<?php
namespace PiieSandbox;

use App\Support\Payments\PesaPalConfiguration;
use App\Support\Payments\PesaPalIpnRegistration;
use Illuminate\Support\Facades\Crypt;

/** Caller holds connectivity.lock; journal never clears an uncertain provider attempt. */
final class PrivateIpnRegistration
{
    public const CONFIRMATION = 'REGISTER SYNTHETIC SANDBOX IPN';

    public function __construct(private readonly string $root, private readonly PesaPalIpnRegistration $registration = new PesaPalIpnRegistration) {}

    public static function assertContext(array $manifest, array $settings, string $database, bool $recover = false): void
    {
        ConnectivitySettings::validate($settings);
        if (!preg_match('/\Apiie_sandbox_[a-f0-9]{16}\z/', $database) || ($manifest['database'] ?? null) !== $database
            || ($manifest['fixtures']['school_id'] ?? null) !== 1
            || ($manifest['fixtures']['applications'] ?? null) !== ['public' => 1, 'staff_entry' => 2]
            || !str_starts_with($settings['origin'], 'https://') || $settings['origin'] !== rtrim($settings['origin'], '/')
            || (!$recover && (!$settings['registration'] || $settings['transactions']))) {
            throw new \RuntimeException('Isolated registration context refused.');
        }
    }

    private function identity(PesaPalConfiguration $configuration, string $origin): array
    {
        if ($configuration->schoolId !== 1 || $configuration->environment !== 'sandbox') throw new \RuntimeException('Sandbox school required.');
        ConnectivitySettings::validate(['origin' => $origin, 'registration' => false, 'transactions' => false]);
        if (!str_starts_with($origin, 'https://') || $origin !== rtrim($origin, '/')) throw new \RuntimeException('Exact HTTPS origin required.');
        return ['school_id' => 1, 'configuration_id' => $configuration->configurationId,
            'fingerprint' => $configuration->cacheKey(), 'url' => $origin.'/payments/pesapal/ipn'];
    }

    public function register(PesaPalConfiguration $configuration, string $origin, string $confirmation): string
    {
        if (!hash_equals(self::CONFIRMATION, $confirmation)) throw new \RuntimeException('Explicit confirmation required.');
        $identity = $this->identity($configuration, $origin);
        if ($configuration->notificationId !== null) throw new \RuntimeException('Existing IPN preserved; registration refused.');
        $file = $this->root.'/ipn-registration.json';
        // Exclusive reservation BEFORE authentication or registration. A crash is uncertain.
        $handle = @fopen($file, 'x');
        if (!$handle) throw new \RuntimeException('Existing registration journal; do not retry.');
        try {
            $body = json_encode(['state' => 'uncertain', 'identity' => Crypt::encryptString(json_encode($identity, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR);
            if (fwrite($handle, $body) !== strlen($body) || !fflush($handle) || !fsync($handle)) throw new \RuntimeException('Journal durability refused.');
        } finally { fclose($handle); }
        try {
            $entry = $this->registration->register($configuration, $identity['url']);
            // Record verified result BEFORE database persistence. Encrypted at rest.
            $this->save(['state' => 'verified', 'identity' => Crypt::encryptString(json_encode($identity, JSON_THROW_ON_ERROR)),
                'result' => Crypt::encryptString(json_encode($entry, JSON_THROW_ON_ERROR))]);
        } catch (\Throwable $e) {
            // Service intentionally sanitizes rejection/timeout detail. All unverified outcomes stay uncertain.
            throw new \RuntimeException('Provider outcome unverified; journal retained; do not retry.');
        }
        return $this->recover($configuration, $origin);
    }

    public function recover(PesaPalConfiguration $configuration, string $origin): string
    {
        $identity = $this->identity($configuration, $origin);
        try {
            $journal = json_decode(file_get_contents($this->root.'/ipn-registration.json'), true, 512, JSON_THROW_ON_ERROR);
            if (!in_array($journal['state'] ?? null, ['verified', 'complete'], true)
                || json_decode(Crypt::decryptString($journal['identity']), true, 512, JSON_THROW_ON_ERROR) !== $identity) {
                throw new \RuntimeException();
            }
            $entry = json_decode(Crypt::decryptString($journal['result']), true, 512, JSON_THROW_ON_ERROR);
            $this->registration->persist($configuration, $entry, $identity['url'], true);
            $journal['state'] = 'complete'; $this->save($journal);
            return 'Verified IPN persisted encrypted; no secrets emitted.';
        } catch (\Throwable $e) {
            throw new \RuntimeException('Local recovery refused; preserve journal and configuration; no provider retry.');
        }
    }

    public function reconcile(PesaPalConfiguration $configuration, string $origin, array $entries): string
    {
        $identity = $this->identity($configuration, $origin);
        $original = file_get_contents($this->root.'/ipn-registration.json');
        $journal = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
        if (($journal['state'] ?? null) !== 'uncertain'
            || json_decode(Crypt::decryptString($journal['identity']), true, 512, JSON_THROW_ON_ERROR) !== $identity
            || $configuration->notificationId !== null) throw new \RuntimeException('Reconciliation identity refused.');
        $matches = array_values(array_filter($entries, fn ($entry) => ($entry['url'] ?? null) === $identity['url']));
        if (!$matches) return 'No exact IPN URL match; registration state unchanged.';
        if (count($matches) !== 1) return 'Multiple exact IPN URL matches; registration state unchanged.';
        $entry = $matches[0];
        if (($entry['method'] ?? null) !== 'GET' || ($entry['active'] ?? null) !== true
            || ($entry['status'] ?? null) !== 200 || !is_string($entry['ipn_id'] ?? null)
            || !\App\Support\Payments\PesaPalService::isGuid($entry['ipn_id'])) {
            return 'Exact IPN URL found, but GET/active registration not verified; registration state unchanged.';
        }
        // Keep the original uncertain journal byte-for-byte; write separate encrypted recovery evidence.
        $evidence = ['state' => 'verified', 'source' => 'GetIpnList', 'original_journal_sha256' => hash('sha256', $original),
            'identity' => Crypt::encryptString(json_encode($identity, JSON_THROW_ON_ERROR)),
            'result' => Crypt::encryptString(json_encode($entry, JSON_THROW_ON_ERROR))];
        $file = $this->root.'/ipn-reconciliation-'.bin2hex(random_bytes(8)).'.json';
        if (file_put_contents($file, json_encode($evidence, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new \RuntimeException('Evidence write refused.');
        if (!hash_equals(hash('sha256', $original), hash_file('sha256', $this->root.'/ipn-registration.json'))) throw new \RuntimeException('Original journal changed.');
        $this->registration->persist($configuration, $entry, $identity['url'], true);
        $evidence['state'] = 'complete';
        if (file_put_contents($file, json_encode($evidence, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new \RuntimeException('Evidence completion refused.');
        return 'Unique active GET IPN verified and notification ID persisted encrypted; original journal preserved.';
    }

    private function save(array $journal): void
    {
        $temp = $this->root.'/ipn-registration.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = @fopen($temp, 'x');
        if (!$handle) throw new \RuntimeException('Journal write refused.');
        try {
            $body = json_encode($journal, JSON_THROW_ON_ERROR);
            if (fwrite($handle, $body) !== strlen($body) || !fflush($handle) || !fsync($handle)) throw new \RuntimeException('Journal durability refused.');
        } finally { fclose($handle); }
        if (!rename($temp, $this->root.'/ipn-registration.json')) throw new \RuntimeException('Journal replacement refused.');
    }
}
