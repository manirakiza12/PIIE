<?php
namespace Tests\Feature;

use App\Models\PaymentMethods;
use App\Support\Payments\PesaPalConfiguration;
use App\Support\Payments\PesaPalCredentialStorage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PiieSandbox\PrivateIpnRegistration;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/sandbox/ConnectivitySettings.php';
require_once __DIR__.'/../../scripts/sandbox/PrivateIpnRegistration.php';

final class PesaPalSandboxIpnRegistrationTest extends TestCase
{
    use AdmissionsTestHelper;
    private const ORIGIN = 'https://sandbox.example.test';
    private const GUID = 'aaaaaaaa-883e-440f-a63e-e1105bbfadc3';
    private string $root;
    private PaymentMethods $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        config(['cache.default' => 'array']); Cache::flush(); Http::preventStrayRequests();
        $this->root = sys_get_temp_dir().'/piie-ipn-fixture-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->row = PaymentMethods::create(['school_id' => 1, 'name' => 'pesapal', 'status' => 1, 'mode' => 'test',
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'synthetic-key', 'consumer_secret' => 'synthetic-secret'])]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $file) unlink($file);
        rmdir($this->root);
        parent::tearDown();
    }

    private function entry(array $changes = []): array
    {
        return array_replace(['ipn_id' => self::GUID, 'url' => self::ORIGIN.'/payments/pesapal/ipn',
            'created_date' => '2026-10-09T12:00:00Z', 'notification_type' => 0,
            'ipn_notification_type_description' => 'GET', 'ipn_status' => 1,
            'ipn_status_description' => 'Active', 'error' => null, 'status' => '200'], $changes);
    }

    private function fake($registration, int $status = 200): void
    {
        Http::fake(function ($request) use ($registration, $status) {
            if (str_ends_with($request->url(), '/api/Auth/RequestToken')) {
                return Http::response(['status' => '200', 'error' => null, 'token' => 'synthetic-token',
                    'expiryDate' => now()->addMinutes(5)->format('Y-m-d\TH:i:s\Z')]);
            }
            if ($registration === 'timeout') throw new ConnectionException('synthetic-secret synthetic-token');
            if (is_callable($registration)) return $registration();
            return Http::response($registration, $status);
        });
    }

    private function configuration(): PesaPalConfiguration { return PesaPalConfiguration::forSchool(1); }
    private function operation(): PrivateIpnRegistration { return new PrivateIpnRegistration($this->root); }
    private function register(): string { return $this->operation()->register($this->configuration(), self::ORIGIN, PrivateIpnRegistration::CONFIRMATION); }
    private function refused(callable $operation): void
    {
        try { $operation(); $this->fail('Unsafe operation accepted.'); }
        catch (\RuntimeException $e) {
            $this->assertNull($e->getPrevious());
            foreach (['synthetic-key', 'synthetic-secret', 'synthetic-token', self::GUID, 'signature='] as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    public function test_success_reuses_service_and_preserves_credential_ciphertext_with_encrypted_notification(): void
    {
        $before = json_decode($this->row->payment_keys, true);
        $this->fake($this->entry());
        $message = $this->register();
        $this->assertSame('Verified IPN persisted encrypted; no secrets emitted.', $message);
        Http::assertSentCount(2);
        $this->assertTrue(Http::recorded(fn ($r) => str_ends_with($r->url(), '/api/URLSetup/RegisterIPN')
            && $r->method() === 'POST' && $r->data() === ['url' => self::ORIGIN.'/payments/pesapal/ipn', 'ipn_notification_type' => 'GET'])->count() === 1);
        $stored = $this->row->fresh()->payment_keys;
        $after = json_decode($stored, true);
        $this->assertSame($before['encrypted_credentials'], $after['encrypted_credentials']);
        $this->assertArrayHasKey('encrypted_notification_id', $after);
        $this->assertArrayNotHasKey('notification_id', $after);
        $this->assertFalse(str_contains($stored, self::GUID));
        $this->assertSame(self::GUID, $this->configuration()->notificationId);
        $journal = file_get_contents($this->root.'/ipn-registration.json');
        $this->assertSame('complete', json_decode($journal, true)['state']);
        foreach ([self::GUID, self::ORIGIN, 'synthetic-key', 'synthetic-secret', 'synthetic-token'] as $secret) {
            $this->assertFalse(str_contains($journal, $secret));
        }
        $this->assertSame(1, PaymentMethods::count());
        $this->refused(fn () => $this->register());
        $this->operation()->recover($this->configuration(), self::ORIGIN);
        Http::assertSentCount(2);
    }

    public function test_missing_confirmation_makes_no_requests_and_creates_no_journal(): void
    {
        $this->fake($this->entry());
        $this->refused(fn () => $this->operation()->register($this->configuration(), self::ORIGIN, 'yes'));
        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->root.'/ipn-registration.json');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badResponses')]
    public function test_rejected_timeout_and_ambiguous_results_preserve_state_and_never_retry($changes, int $status): void
    {
        $before = $this->row->fresh()->payment_keys;
        $this->fake($changes === 'timeout' ? 'timeout' : ($changes === 'invalid-json' ? 'invalid-json' : $this->entry($changes)), $status);
        $this->refused(fn () => $this->register());
        $this->assertSame($before, $this->row->fresh()->payment_keys);
        $this->assertSame('uncertain', json_decode(file_get_contents($this->root.'/ipn-registration.json'), true)['state']);
        $this->refused(fn () => $this->register());
        $this->refused(fn () => $this->operation()->recover($this->configuration(), self::ORIGIN));
        Http::assertSentCount($changes === 'timeout' ? 1 : 2);
    }

    public static function badResponses(): array
    {
        return ['rejected' => [['status' => '400', 'error' => ['message' => 'synthetic-secret']], 400],
            'timeout' => ['timeout', 200], 'invalid-json' => ['invalid-json', 200],
            'wrong-url' => [['url' => 'https://other.example.test/payments/pesapal/ipn'], 200],
            'wrong-method' => [['ipn_notification_type_description' => 'POST'], 200],
            'inactive' => [['ipn_status' => 0], 200], 'invalid-id' => [['ipn_id' => 'invalid'], 200],
            'missing-status' => [['status' => null], 200], 'redirect' => [[], 302]];
    }

    public function test_verified_response_survives_database_failure_and_recovers_without_provider_requests(): void
    {
        $this->fake($this->entry());
        DB::statement("CREATE TRIGGER ipn_fixture_failure BEFORE UPDATE ON payment_methods BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $before = $this->row->fresh()->payment_keys;
        $this->refused(fn () => $this->register());
        $this->assertSame('verified', json_decode(file_get_contents($this->root.'/ipn-registration.json'), true)['state']);
        $this->assertSame($before, $this->row->fresh()->payment_keys);
        DB::statement('DROP TRIGGER ipn_fixture_failure');
        $this->operation()->recover($this->configuration(), self::ORIGIN);
        $this->assertSame(self::GUID, $this->configuration()->notificationId);
        Http::assertSentCount(2);
    }

    public function test_recovery_rejects_changed_credentials_origin_and_tampered_journal(): void
    {
        $this->fake($this->entry()); $this->register();
        $this->refused(fn () => $this->operation()->recover($this->configuration(), 'https://other.example.test'));
        $keys = json_decode($this->row->fresh()->payment_keys, true);
        $keys['consumer_key'] = 'synthetic-rotated-key'; $keys['consumer_secret'] = 'synthetic-rotated-secret';
        $this->row->update(['payment_keys' => json_encode($keys)]);
        $this->refused(fn () => $this->operation()->recover($this->configuration(), self::ORIGIN));
        file_put_contents($this->root.'/ipn-registration.json', '{"state":"verified","identity":"invalid","result":"invalid"}');
        $this->refused(fn () => $this->operation()->recover($this->configuration(), self::ORIGIN));
        Http::assertSentCount(2);
    }

    public function test_school_environment_and_phase_guards_precede_provider_requests(): void
    {
        $this->fake($this->entry());
        $database = 'piie_sandbox_0123456789abcdef';
        $manifest = ['database' => $database, 'fixtures' => ['school_id' => 1, 'applications' => ['public' => 1, 'staff_entry' => 2]]];
        $settings = ['origin' => self::ORIGIN, 'registration' => true, 'transactions' => false];
        PrivateIpnRegistration::assertContext($manifest, $settings, $database);
        foreach ([['registration' => false], ['transactions' => true], ['origin' => 'http://127.0.0.1:8002']] as $change) {
            $this->refused(fn () => PrivateIpnRegistration::assertContext($manifest, array_replace($settings, $change), $database));
        }
        $this->refused(fn () => PrivateIpnRegistration::assertContext($manifest, $settings, 'piie_main'));
        $manifest['fixtures']['school_id'] = 2;
        $this->refused(fn () => PrivateIpnRegistration::assertContext($manifest, $settings, $database));
        foreach ([[2, 'sandbox'], [1, 'live']] as [$school, $environment]) {
            $configuration = new PesaPalConfiguration($school, 1, $environment, 'synthetic-key', 'synthetic-secret');
            $this->refused(fn () => $this->operation()->register($configuration, self::ORIGIN, PrivateIpnRegistration::CONFIRMATION));
        }
        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->root.'/ipn-registration.json');
    }

    public function test_interrupted_reservation_or_unwritable_journal_never_allows_provider_retry(): void
    {
        $this->fake($this->entry());
        $this->refused(fn () => (new PrivateIpnRegistration($this->root.'/missing-directory'))
            ->register($this->configuration(), self::ORIGIN, PrivateIpnRegistration::CONFIRMATION));
        file_put_contents($this->root.'/ipn-registration.json', '');
        $this->refused(fn () => $this->register());
        $this->refused(fn () => $this->operation()->recover($this->configuration(), self::ORIGIN));
        Http::assertNothingSent();
    }

    public function test_concurrent_configuration_change_does_not_receive_old_provider_notification(): void
    {
        $before = $this->configuration();
        $this->fake(function () {
            $this->row->update(['payment_keys' => json_encode(['environment' => 'sandbox',
                'consumer_key' => 'synthetic-new-key', 'consumer_secret' => 'synthetic-new-secret'])]);
            return Http::response($this->entry());
        });
        $this->refused(fn () => $this->operation()->register($before, self::ORIGIN, PrivateIpnRegistration::CONFIRMATION));
        $this->assertNull($this->configuration()->notificationId);
        $this->assertSame('verified', json_decode(file_get_contents($this->root.'/ipn-registration.json'), true)['state']);
        $this->refused(fn () => $this->operation()->recover($this->configuration(), self::ORIGIN));
        Http::assertSentCount(2);
    }

    public function test_encrypted_notification_is_bound_to_school_and_rejects_dual_plaintext(): void
    {
        $this->fake($this->entry()); $this->register();
        $stored = $this->row->fresh()->payment_keys;
        $this->refused(fn () => PesaPalCredentialStorage::read($stored, 2));
        $data = json_decode($stored, true); $data['notification_id'] = self::GUID;
        $this->refused(fn () => PesaPalCredentialStorage::read(json_encode($data), 1));
        $data = json_decode($stored, true);
        $data['encrypted_notification_id'] = \Illuminate\Support\Facades\Crypt::encryptString(
            json_encode(['school_id' => 2, 'notification_id' => self::GUID]));
        $this->refused(fn () => PesaPalCredentialStorage::read(json_encode($data), 1));
        Http::assertSentCount(2);
    }

    private function uncertainJournal(): string
    {
        $configuration=$this->configuration();
        $identity=['school_id'=>1,'configuration_id'=>$configuration->configurationId,
            'fingerprint'=>$configuration->cacheKey(),'url'=>self::ORIGIN.'/payments/pesapal/ipn'];
        $body=json_encode(['state'=>'uncertain','identity'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode($identity))]);
        file_put_contents($this->root.'/ipn-registration.json',$body);
        return $body;
    }

    private function normalizedEntry(): array
    {
        return ['ipn_id'=>self::GUID,'url'=>self::ORIGIN.'/payments/pesapal/ipn','created_date'=>'2026-10-09T12:00:00Z',
            'method'=>'GET','active'=>true,'error'=>null,'status'=>200];
    }

    public function test_read_only_reconciliation_uses_existing_persistence_and_preserves_original_journal(): void
    {
        $journal=$this->uncertainJournal(); $before=json_decode($this->row->fresh()->payment_keys,true);
        $message=$this->operation()->reconcile($this->configuration(),self::ORIGIN,[$this->normalizedEntry()]);
        $this->assertStringContainsString('persisted encrypted',$message);
        $this->assertSame($journal,file_get_contents($this->root.'/ipn-registration.json'));
        $this->assertSame(self::GUID,$this->configuration()->notificationId);
        $stored=json_decode($this->row->fresh()->payment_keys,true);
        $this->assertSame($before['encrypted_credentials'],$stored['encrypted_credentials']);
        $this->assertArrayHasKey('encrypted_notification_id',$stored);
        $evidenceFiles=glob($this->root.'/ipn-reconciliation-*.json'); $this->assertCount(1,$evidenceFiles);
        $evidence=file_get_contents($evidenceFiles[0]);
        $this->assertSame('complete',json_decode($evidence,true)['state']);
        $this->assertFalse(str_contains($evidence,self::GUID));
        Http::assertNothingSent();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unclearListings')]
    public function test_ambiguous_or_unverified_listing_never_changes_registration_state(string $case): void
    {
        $journal=$this->uncertainJournal();$before=$this->row->fresh()->payment_keys;$entry=$this->normalizedEntry();
        $entries=[$entry];
        if($case==='none') $entries=[];
        if($case==='multiple') $entries=[$entry,$entry];
        if($case==='unknown-method') $entries[0]['method']=null;
        if($case==='unknown-active') $entries[0]['active']=null;
        if($case==='inactive') $entries[0]['active']=false;
        if($case==='wrong-method') $entries[0]['method']='POST';
        if($case==='invalid-id') $entries[0]['ipn_id']='invalid';
        $message=$this->operation()->reconcile($this->configuration(),self::ORIGIN,$entries);
        $this->assertStringContainsString('state unchanged',$message);
        $this->assertSame($journal,file_get_contents($this->root.'/ipn-registration.json'));
        $this->assertSame($before,$this->row->fresh()->payment_keys);
        $this->assertCount(0,glob($this->root.'/ipn-reconciliation-*.json'));
        Http::assertNothingSent();
    }

    public static function unclearListings(): array
    {
        return array_map(fn($case)=>[$case],['none','multiple','unknown-method','unknown-active','inactive','wrong-method','invalid-id']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('savedListingRecoveryCases')]
    public function test_saved_observed_listing_recovery_keeps_strict_match_and_active_guards(string $case): void
    {
        $journal=$this->uncertainJournal();$before=$this->row->fresh()->payment_keys;
        $raw=$this->entry(['status'=>'1']);unset($raw['error']);
        if($case==='inactive'){$raw['status']='0';$raw['ipn_status']=0;}
        if($case==='wrong-url')$raw['url']='https://other.example.test/payments/pesapal/ipn';
        if($case==='wrong-method'){$raw['ipn_notification_type_description']='POST';$raw['notification_type']=1;}
        $rows=$case==='duplicates'?[$raw,$raw]:[$raw];
        $normalized=(new \App\Support\Payments\PesaPalService($this->configuration()))->normalizeIpnList($rows);
        $message=$this->operation()->reconcile($this->configuration(),self::ORIGIN,$normalized);
        $this->assertSame($journal,file_get_contents($this->root.'/ipn-registration.json'));
        if($case==='active'){
            $this->assertStringContainsString('persisted encrypted',$message);
            $this->assertSame(self::GUID,$this->configuration()->notificationId);
            $this->assertArrayHasKey('encrypted_notification_id',json_decode($this->row->fresh()->payment_keys,true));
        }else{
            $this->assertStringContainsString('state unchanged',$message);
            $this->assertSame($before,$this->row->fresh()->payment_keys);
            $this->assertNull($this->configuration()->notificationId);
        }
        Http::assertNothingSent();
    }

    public static function savedListingRecoveryCases(): array
    {
        return array_map(fn($case)=>[$case],['active','inactive','duplicates','wrong-url','wrong-method']);
    }
}
