<?php

namespace App\Support\Payments;

use Carbon\CarbonImmutable;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/** API 3.0 transport only. No settlement, redirects or payment/config writes. */
final class PesaPalService
{
    public const SANDBOX_URL = 'https://cybqa.pesapal.com/pesapalv3';
    public const LIVE_URL = 'https://pay.pesapal.com/v3';

    public function __construct(private readonly PesaPalConfiguration $configuration) {}

    public static function forSchool(int $schoolId): self
    {
        return new self(PesaPalConfiguration::forSchool($schoolId));
    }

    public static function isGuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/i', $value) === 1;
    }

    public function authenticate(): PesaPalToken
    {
        // All tokens are encrypted at rest. A database cache is deliberately
        // bypassed: bearer tokens must not be persisted in database fields.
        try {
            $cache = Cache::store();
            $cacheable = ! ($cache->getStore() instanceof DatabaseStore);
            $key = $this->configuration->cacheKey();
            $cached = $cacheable ? $cache->get($key) : null;
            if (is_string($cached)) {
                try {
                    $entry = json_decode(Crypt::decryptString($cached), true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($entry) && is_string($entry['token'] ?? null) && $this->validToken($entry['token'])
                        && is_int($entry['expires_at'] ?? null) && $entry['expires_at'] > now()->timestamp
                        && $entry['expires_at'] <= now()->timestamp + 300) {
                        return new PesaPalToken($entry['token'], $entry['expires_at']);
                    }
                } catch (\Exception $e) { /* Invalid cache entry: request a fresh token. */ }
                $cache->forget($key);
            }
            $started = now()->timestamp;
            $data = $this->request('POST', '/api/Auth/RequestToken', $this->configuration->credentials());
            $this->envelope($data);
            if (! is_string($data['token'] ?? null) || ! $this->validToken($data['token'])
                || ! is_string($data['expiryDate'] ?? null)
                || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?Z?\z/', $data['expiryDate'])) {
                throw new PesaPalException();
            }
            $expiry = $this->utcDate($data['expiryDate']);
            $expiresAt = min($expiry->timestamp, $started + 300) - 30;
            if ($expiresAt <= now()->timestamp) { throw new PesaPalException(); }
            $token = new PesaPalToken($data['token'], $expiresAt);
            if ($cacheable) {
                $cache->put($key, Crypt::encryptString(json_encode(['token' => $token->bearer(), 'expires_at' => $expiresAt])),
                    $expiresAt - now()->timestamp);
            }
            return $token;
        } catch (\Exception $e) { throw new PesaPalException(); }
    }

    /** Explicit administrative operation; never writes notification_id. */
    public function registerIpn(string $url, string $method): array
    {
        $this->httpsUrl($url);
        if (! in_array($method, ['GET', 'POST'], true)) { throw new PesaPalException(); }
        $data = $this->authorized('POST', '/api/URLSetup/RegisterIPN', ['url' => $url, 'ipn_notification_type' => $method]);
        $entry = $this->ipnEntry($data, true);
        if ($entry['url'] !== $url || $entry['method'] !== $method || $entry['active'] !== true) { throw new PesaPalException(); }
        return $entry;
    }

    public function getIpnList(): array
    {
        $data = $this->authorized('GET', '/api/URLSetup/GetIpnList');
        if (! array_is_list($data)) { throw new PesaPalException(); }
        return array_map(fn ($entry) => is_array($entry) ? $this->ipnEntry($entry, false) : throw new PesaPalException(), $data);
    }

    public function submitOrder(array $order): PesaPalOrder
    {
        // Caller supplies all commercial/tenant decisions. Do not accept extra
        // fields that could accidentally carry credentials into provider payloads.
        $allowed = ['id', 'currency', 'amount', 'description', 'callback_url', 'cancellation_url',
            'redirect_mode', 'notification_id', 'branch', 'billing_address'];
        if (array_diff(array_keys($order), $allowed) || ! $this->reference($order['id'] ?? null)
            || ! is_string($order['currency'] ?? null) || ! preg_match('/\A[A-Z]{3}\z/', $order['currency'])
            || ($minor = DecimalAmount::minorUnits($order['amount'] ?? null)) === null || $minor <= 0
            || ! is_string($order['description'] ?? null) || trim($order['description']) === '' || mb_strlen($order['description']) > 100
            || ! is_string($order['notification_id'] ?? null) || ! self::isGuid($order['notification_id'])
            || ! is_array($order['billing_address'] ?? null)) { throw new PesaPalException(); }
        $this->httpsUrl($order['callback_url'] ?? null);
        if (isset($order['cancellation_url'])) { $this->httpsUrl($order['cancellation_url']); }
        if (isset($order['redirect_mode']) && ! in_array($order['redirect_mode'], ['', 'TOP_WINDOW', 'PARENT_WINDOW'], true)) { throw new PesaPalException(); }
        $billing = $order['billing_address'];
        $billingFields = ['email_address', 'phone_number', 'country_code', 'first_name', 'middle_name', 'last_name',
            'line_1', 'line_2', 'city', 'state', 'postal_code', 'zip_code'];
        if (array_diff(array_keys($billing), $billingFields)
            || (! $this->nonempty($billing['email_address'] ?? null) && ! $this->nonempty($billing['phone_number'] ?? null))) { throw new PesaPalException(); }
        foreach ($billing as $value) { if (! is_string($value) && ! is_int($value)) { throw new PesaPalException(); } }
        if (isset($order['branch']) && ! is_string($order['branch'])) { throw new PesaPalException(); }
        $data = $this->authorized('POST', '/api/Transactions/SubmitOrderRequest', $order);
        $this->envelope($data);
        if (! is_string($data['order_tracking_id'] ?? null) || ! self::isGuid($data['order_tracking_id'])
            || ($data['merchant_reference'] ?? null) !== $order['id']) { throw new PesaPalException(); }
        $this->httpsUrl($data['redirect_url'] ?? null, $this->configuration->environment === 'live' ? 'pay.pesapal.com' : 'cybqa.pesapal.com');
        return new PesaPalOrder(strtolower($data['order_tracking_id']), $data['merchant_reference'], $data['redirect_url'], 200);
    }

    public function getTransactionStatus(string $orderTrackingId): PesaPalTransactionStatus
    {
        if (! self::isGuid($orderTrackingId)) { throw new PesaPalException(); }
        $id = strtolower($orderTrackingId);
        $data = $this->authorized('GET', '/api/Transactions/GetTransactionStatus', ['orderTrackingId' => $id]);
        $this->envelope($data);
        if (! $this->reference($data['merchant_reference'] ?? null)
            || ($minor = DecimalAmount::minorUnits($data['amount'] ?? null)) === null || $minor < 0
            || ! is_string($data['currency'] ?? null) || ! preg_match('/\A[A-Z]{3}\z/', $data['currency'])
            || ! $this->nonempty($data['payment_status_description'] ?? null)
            || ! (is_int($data['status_code'] ?? null) || (is_string($data['status_code'] ?? null) && preg_match('/\A\d{1,3}\z/', $data['status_code'])))
            || (isset($data['order_tracking_id']) && (! is_string($data['order_tracking_id']) || strtolower($data['order_tracking_id']) !== $id))) {
            throw new PesaPalException();
        }
        foreach (['payment_method', 'confirmation_code'] as $field) {
            if (isset($data[$field]) && ! is_string($data[$field])) { throw new PesaPalException(); }
        }
        return new PesaPalTransactionStatus($id, $data['merchant_reference'], DecimalAmount::decimal($minor),
            $data['currency'], (int) $data['status_code'], strtoupper(trim($data['payment_status_description'])),
            $data['payment_method'] ?? null, $data['confirmation_code'] ?? null, 200);
    }

    private function authorized(string $method, string $path, array $data = []): array
    {
        return $this->request($method, $path, $data, $this->authenticate()->bearer());
    }

    private function request(string $method, string $path, array $data, ?string $token = null): array
    {
        try {
            $base = $this->configuration->environment === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
            $request = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false]);
            if ($token !== null) { $request = $request->withToken($token); }
            $response = $method === 'GET' ? $request->get($base . $path, $data) : $request->post($base . $path, $data);
            if (! $response->successful()) { throw new PesaPalException(); }
            // Associative JSON decoding collapses {} and [] into the same
            // PHP value. The list endpoint must have an actual JSON array root.
            if ($path === '/api/URLSetup/GetIpnList' && ! str_starts_with(ltrim($response->body()), '[')) { throw new PesaPalException(); }
            $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) { throw new PesaPalException(); }
            return $decoded;
        } catch (\Exception $e) { throw new PesaPalException(); }
    }

    private function envelope(array $data): void
    {
        if (! in_array($data['status'] ?? null, [200, '200'], true) || ! array_key_exists('error', $data)) { throw new PesaPalException(); }
        $error = $data['error'];
        if ($error !== null && (! is_array($error) || array_filter($error, fn ($value) => $value !== null && $value !== ''))) { throw new PesaPalException(); }
    }

    private function ipnEntry(array $data, bool $registration): array
    {
        $this->envelope($data);
        if (! is_string($data['ipn_id'] ?? null) || ! self::isGuid($data['ipn_id']) || ! $this->nonempty($data['created_date'] ?? null)) { throw new PesaPalException(); }
        $this->utcDate($data['created_date']);
        $this->httpsUrl($data['url'] ?? null);
        $method = $data['ipn_notification_type_description'] ?? null;
        $status = $data['ipn_status'] ?? null;
        if (($registration || $method !== null) && ! in_array($method, ['GET', 'POST'], true)) { throw new PesaPalException(); }
        if (($registration || $status !== null) && ! in_array($status, [0, 1], true)) { throw new PesaPalException(); }
        if ($registration && (! in_array($data['notification_type'] ?? null, [0, 1], true)
            || ! $this->nonempty($data['ipn_status_description'] ?? $data['ipn_status_decription'] ?? null))) { throw new PesaPalException(); }
        return ['ipn_id' => strtolower($data['ipn_id']), 'url' => $data['url'], 'created_date' => $data['created_date'],
            'method' => $method, 'active' => $status === null ? null : $status === 1, 'error' => null, 'status' => 200];
    }

    private function httpsUrl($url, ?string $host = null): void
    {
        if (! is_string($url) || preg_match('/[\x00-\x20\\\\]/', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) { throw new PesaPalException(); }
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ($host !== null && strtolower($parts['host']) !== $host)) { throw new PesaPalException(); }
    }

    private function validToken(string $value): bool { return trim($value) !== '' && ! preg_match('/[\x00-\x20\x7f]/', $value); }
    private function nonempty($value): bool { return is_string($value) && trim($value) !== ''; }
    private function reference($value): bool { return is_string($value) && preg_match('/\A[A-Za-z0-9._:-]{1,50}\z/', $value) === 1; }

    private function utcDate(string $value): CarbonImmutable
    {
        if (! preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,7})?Z?\z/', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59) { throw new PesaPalException(); }
        // .NET examples use seven fractional digits; PHP supports six. This
        // truncation never increases validity; cache expiry uses whole seconds.
        $value = preg_replace('/(\.\d{6})\d(?=Z?$)/', '$1', $value);
        try { return CarbonImmutable::parse($value, 'UTC'); }
        catch (\Exception $e) { throw new PesaPalException(); }
    }
}
