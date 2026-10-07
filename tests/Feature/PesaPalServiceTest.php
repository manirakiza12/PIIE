<?php

namespace Tests\Feature;

use App\Models\PaymentMethods;
use App\Support\Payments\PesaPalConfiguration;
use App\Support\Payments\PesaPalException;
use App\Support\Payments\PesaPalService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class PesaPalServiceTest extends TestCase
{
    use AdmissionsTestHelper;

    private const GUID = '7e6b62d9-883e-440f-a63e-e1105bbfadc3';
    private const URL = 'https://example.test/payments/pesapal/ipn';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        config(['cache.default' => 'array']);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-07T12:00:00Z'));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function configuration(int $school = 1, string $environment = 'sandbox', string $secret = 'fixture-secret'): PesaPalConfiguration
    {
        return new PesaPalConfiguration($school, $school, $environment, 'fixture-key', $secret, self::GUID);
    }

    private function service(): PesaPalService { return new PesaPalService($this->configuration()); }
    private function token(array $overrides = []): array
    {
        return array_merge(['token' => 'fixture-bearer', 'expiryDate' => now()->addMinutes(5)->format('Y-m-d\TH:i:s\Z'),
            'error' => null, 'status' => '200', 'message' => 'Request processed successfully'], $overrides);
    }
    private function ipn(): array
    {
        return ['ipn_id' => self::GUID, 'url' => self::URL, 'created_date' => '2026-10-07T12:00:00Z',
            'notification_type' => 0, 'ipn_notification_type_description' => 'GET', 'ipn_status' => 1,
            'ipn_status_description' => 'Active', 'error' => null, 'status' => '200'];
    }
    private function order(): array
    {
        return ['id' => 'APP-1', 'currency' => 'UGX', 'amount' => '50000.00', 'description' => 'Application fee',
            'callback_url' => 'https://example.test/payments/pesapal/callback', 'notification_id' => self::GUID,
            'billing_address' => ['email_address' => 'applicant@example.test', 'first_name' => 'Fixture']];
    }
    private function submitted(): array
    {
        return ['order_tracking_id' => self::GUID, 'merchant_reference' => 'APP-1',
            'redirect_url' => 'https://cybqa.pesapal.com/pesapaliframe/PesapalIframe3/Index/?OrderTrackingId=' . self::GUID,
            'status' => '200', 'error' => null];
    }
    private function status(): array
    {
        return ['merchant_reference' => 'APP-1', 'amount' => 50000, 'currency' => 'UGX', 'status_code' => 1,
            'payment_status_description' => 'Completed', 'payment_method' => 'Visa', 'confirmation_code' => 'opaque-confirmation',
            'status' => '200', 'error' => ['error_type' => null, 'code' => null, 'message' => null, 'call_back_url' => null]];
    }
    private function fakeEndpoint(string $path, $response, int $httpStatus = 200): void
    {
        Http::fake(['*/api/Auth/RequestToken' => Http::response($this->token()),
            '*' . $path . '*' => Http::response($response, $httpStatus)]);
    }
    private function reject(callable $operation): void
    {
        try { $operation(); $this->fail('Unsafe response was accepted'); }
        catch (PesaPalException $e) {
            $this->assertSame('PesaPal request could not be completed safely.', $e->getMessage());
            $this->assertNull($e->getPrevious());
            foreach (['fixture-key', 'fixture-secret', 'fixture-bearer'] as $secret) { $this->assertStringNotContainsString($secret, $e->getMessage()); }
        }
    }

    /** @dataProvider environments */
    public function test_authentication_endpoint_headers_and_payload(string $environment, string $base): void
    {
        Http::fake(['*' => Http::response($this->token())]);
        $token = (new PesaPalService($this->configuration(1, $environment)))->authenticate();
        $this->assertSame('fixture-bearer', $token->bearer());
        $this->assertSame(now()->timestamp + 270, $token->expiresAt);
        Http::assertSent(fn ($r) => $r->url() === $base . '/api/Auth/RequestToken' && $r->method() === 'POST'
            && $r->data() === ['consumer_key' => 'fixture-key', 'consumer_secret' => 'fixture-secret']
            && $r->hasHeader('Accept', 'application/json') && $r->hasHeader('Content-Type', 'application/json')
            && ! $r->hasHeader('Authorization'));
    }
    public static function environments(): array
    {
        return [['sandbox', PesaPalService::SANDBOX_URL], ['live', PesaPalService::LIVE_URL]];
    }

    /** @dataProvider badTokens */
    public function test_malformed_expired_and_provider_error_tokens_fail_closed(array $changes): void
    {
        Http::fake(['*' => Http::response($this->token($changes))]);
        $this->reject(fn () => $this->service()->authenticate());
        $this->assertNull(Cache::get($this->configuration()->cacheKey()));
    }
    public static function badTokens(): array
    {
        return [
            'missing token' => [['token' => null]], 'empty token' => [['token' => '']],
            'header injection' => [['token' => "fixture-bearer\r\nX: bad"]], 'array token' => [['token' => []]],
            'expired' => [['expiryDate' => '2026-10-07T11:59:59Z']], 'missing expiry' => [['expiryDate' => null]],
            'invalid expiry' => [['expiryDate' => 'tomorrow']], 'invalid calendar expiry' => [['expiryDate' => '2026-99-99T12:05:00Z']],
            'normalized invalid date' => [['expiryDate' => '2026-02-30T12:05:00Z']],
            'unsafe near expiry' => [['expiryDate' => '2026-10-07T12:00:10Z']],
            'provider error' => [['error' => ['message' => 'fixture-key fixture-secret fixture-bearer']]],
            'provider status' => [['status' => '500']],
        ];
    }

    /** @dataProvider httpFailures */
    public function test_http_errors_and_malformed_json_are_sanitized($body, int $status): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $this->reject(fn () => $this->service()->authenticate());
    }
    public static function httpFailures(): array
    {
        return [['fixture-secret fixture-bearer', 401], ['fixture-secret', 500], ['fixture-secret', 302],
            ['not json', 200], ['null', 200], ['"fixture-bearer"', 200]];
    }

    public function test_connection_timeout_exception_has_no_secret_or_previous_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('fixture-key fixture-secret fixture-bearer timeout'));
        $this->reject(fn () => $this->service()->authenticate());
    }

    public function test_cache_reuse_expiry_isolation_rotation_and_encryption(): void
    {
        Http::fake(fn () => Http::response($this->token()));
        $service = $this->service();
        $service->authenticate(); $service->authenticate();
        Http::assertSentCount(1);
        $cached = Cache::get($this->configuration()->cacheKey());
        $this->assertIsString($cached);
        $this->assertStringNotContainsString('fixture-bearer', $cached);
        (new PesaPalService($this->configuration(2)))->authenticate();
        (new PesaPalService($this->configuration(1, 'live')))->authenticate();
        (new PesaPalService($this->configuration(1, 'sandbox', 'rotated-secret')))->authenticate();
        Http::assertSentCount(4);
        $this->travel(271)->seconds();
        $service->authenticate();
        Http::assertSentCount(5);
    }

    public function test_expiry_is_capped_and_corrupt_cache_is_not_trusted(): void
    {
        Cache::put($this->configuration()->cacheKey(), 'fixture-bearer-plaintext', 500);
        Http::fake(['*' => Http::response($this->token(['expiryDate' => '2026-10-08T12:00:00Z']))]);
        $token = $this->service()->authenticate();
        $this->assertSame(now()->timestamp + 270, $token->expiresAt);
        Http::assertSentCount(1);
    }

    public function test_per_school_configuration_is_read_only_and_existing_notification_is_preserved(): void
    {
        $row = PaymentMethods::create(['school_id' => 1, 'name' => 'pesapal', 'status' => 1, 'mode' => 'test',
            'payment_keys' => json_encode(['consumer_key' => 'fixture-key', 'consumer_secret' => 'fixture-secret',
                'environment' => 'sandbox', 'notification_id' => '84740ab4-3cd9-47da-8a4f-dd1db53494b5'])]);
        $before = DB::table('payment_methods')->get()->toJson();
        $this->fakeEndpoint('/api/URLSetup/RegisterIPN', $this->ipn());
        $result = PesaPalService::forSchool(1)->registerIpn(self::URL, 'GET');
        $this->assertSame(self::GUID, $result['ipn_id']);
        $this->assertSame('84740ab4-3cd9-47da-8a4f-dd1db53494b5', PesaPalConfiguration::forSchool(1)->notificationId);
        $this->assertSame($before, DB::table('payment_methods')->get()->toJson());
        $this->reject(fn () => PesaPalService::forSchool(2));
        $row->update(['payment_keys' => '{invalid']);
        $this->reject(fn () => PesaPalService::forSchool(1));
    }

    /** @dataProvider invalidEnvironments */
    public function test_invalid_environment_configuration_is_rejected(string $environment): void
    {
        $this->reject(fn () => new PesaPalService($this->configuration(1, $environment)));
        Http::assertNothingSent();
    }
    public static function invalidEnvironments(): array { return [['test'], ['production'], ['LIVE'], ['https://attacker.test']]; }

    public function test_registration_and_listing_use_bearer_auth_and_normalize_entries(): void
    {
        $ipn = $this->ipn();
        $listEntry = array_intersect_key($ipn, array_flip(['ipn_id', 'url', 'created_date', 'status', 'error']));
        Http::fake(['*/Auth/RequestToken' => Http::response($this->token()),
            '*/URLSetup/RegisterIPN' => Http::response($ipn), '*/URLSetup/GetIpnList' => Http::response([$listEntry])]);
        $registered = $this->service()->registerIpn(self::URL, 'GET');
        $this->assertTrue($registered['active']);
        $list = $this->service()->getIpnList();
        $this->assertSame(self::GUID, $list[0]['ipn_id']);
        $this->assertNull($list[0]['method']);
        Http::assertSent(fn ($r) => $r->url() === PesaPalService::SANDBOX_URL . '/api/URLSetup/RegisterIPN'
            && $r->method() === 'POST' && $r->data() === ['url' => self::URL, 'ipn_notification_type' => 'GET']
            && $r->hasHeader('Authorization', 'Bearer fixture-bearer'));
        Http::assertSent(fn ($r) => $r->url() === PesaPalService::SANDBOX_URL . '/api/URLSetup/GetIpnList'
            && $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer fixture-bearer'));
    }

    /** @dataProvider badIpns */
    public function test_registration_rejects_malformed_or_failed_response(array $changes): void
    {
        $this->fakeEndpoint('/api/URLSetup/RegisterIPN', array_merge($this->ipn(), $changes));
        $this->reject(fn () => $this->service()->registerIpn(self::URL, 'GET'));
    }
    public static function badIpns(): array
    {
        return [[['ipn_id' => null]], [['url' => 'https://other.test']], [['ipn_notification_type_description' => 'POST']],
            [['ipn_status' => 0]], [['created_date' => null]], [['notification_type' => null]],
            [['error' => ['message' => 'fixture-secret']]], [['status' => '500']]];
    }

    /** @dataProvider badLists */
    public function test_listing_fails_closed($data): void
    {
        $this->fakeEndpoint('/api/URLSetup/GetIpnList', $data);
        $this->reject(fn () => $this->service()->getIpnList());
    }
    public static function badLists(): array { return [[['error' => ['message' => 'fixture-secret'], 'status' => '500']], [[[]]], [['invalid']], ['bad json'], ['{}']]; }

    public function test_empty_ipn_list_and_invalid_registration_inputs(): void
    {
        $this->fakeEndpoint('/api/URLSetup/GetIpnList', []);
        $this->assertSame([], $this->service()->getIpnList());
        $this->reject(fn () => $this->service()->registerIpn('http://example.test', 'GET'));
        $this->reject(fn () => $this->service()->registerIpn(self::URL, 'PUT'));
    }

    public function test_order_is_transmitted_and_no_payment_rows_are_written(): void
    {
        DB::table('application_payments')->insert(['school_id' => 1, 'admission_id' => 1, 'method' => 'pesapal',
            'status' => 'pending', 'amount' => '50000.00', 'reference' => 'APP-1']);
        $before = DB::table('application_payments')->get()->toJson();
        $this->fakeEndpoint('/api/Transactions/SubmitOrderRequest', $this->submitted());
        $result = $this->service()->submitOrder($this->order());
        $this->assertSame(self::GUID, $result->orderTrackingId);
        $this->assertSame('APP-1', $result->merchantReference);
        $this->assertSame($this->submitted()['redirect_url'], $result->redirectUrl);
        Http::assertSent(fn ($r) => $r->url() === PesaPalService::SANDBOX_URL . '/api/Transactions/SubmitOrderRequest'
            && $r->method() === 'POST' && $r->data() === $this->order() && $r->hasHeader('Authorization', 'Bearer fixture-bearer'));
        $this->fakeEndpoint('/api/Transactions/GetTransactionStatus', $this->status());
        $this->service()->getTransactionStatus(self::GUID);
        $this->assertSame($before, DB::table('application_payments')->get()->toJson());
    }

    /** @dataProvider badOrders */
    public function test_order_response_validation(array $changes): void
    {
        $this->fakeEndpoint('/api/Transactions/SubmitOrderRequest', array_merge($this->submitted(), $changes));
        $this->reject(fn () => $this->service()->submitOrder($this->order()));
    }
    public static function badOrders(): array
    {
        return [[['order_tracking_id' => null]], [['order_tracking_id' => 'not-guid']], [['merchant_reference' => null]],
            [['merchant_reference' => 'wrong']], [['redirect_url' => null]], [['redirect_url' => 'javascript:alert(1)']],
            [['redirect_url' => 'https://cybqa.pesapal.com.attacker.test/pay']], [['redirect_url' => 'https://attacker@cybqa.pesapal.com/pay']],
            [['redirect_url' => 'https://pay.pesapal.com/pay']], [['redirect_url' => 'http://cybqa.pesapal.com/pay']],
            [['redirect_url' => 'https://cybqa.pesapal.com:444/pay']], [['redirect_url' => "https://cybqa.pesapal.com/\r\n"]],
            [['error' => ['message' => 'fixture-bearer']]], [['status' => '500']]];
    }

    public function test_invalid_order_inputs_fail_before_any_request_and_live_redirect_is_allowed(): void
    {
        foreach (['id' => 'bad/reference', 'amount' => -1, 'currency' => 'ugx', 'notification_id' => 'bad',
            'callback_url' => 'http://example.test', 'billing_address' => [], 'consumer_secret' => 'fixture-secret'] as $field => $value) {
            $this->reject(fn () => $this->service()->submitOrder(array_merge($this->order(), [$field => $value])));
        }
        Http::assertNothingSent();
        $response = $this->submitted(); $response['redirect_url'] = 'https://pay.pesapal.com/pesapaliframe/PesapalIframe3/Index/';
        $this->fakeEndpoint('/api/Transactions/SubmitOrderRequest', $response);
        $result = (new PesaPalService($this->configuration(1, 'live')))->submitOrder($this->order());
        $this->assertSame($response['redirect_url'], $result->redirectUrl);
    }

    /** @dataProvider statuses */
    public function test_status_is_normalized_without_settlement(int $code, string $description, string $classification): void
    {
        $response = array_merge($this->status(), ['status_code' => $code, 'payment_status_description' => $description]);
        $this->fakeEndpoint('/api/Transactions/GetTransactionStatus', $response);
        $result = $this->service()->getTransactionStatus(strtoupper(self::GUID));
        $this->assertSame(self::GUID, $result->orderTrackingId);
        $this->assertSame('APP-1', $result->merchantReference);
        $this->assertSame('50000.00', $result->amount);
        $this->assertSame('UGX', $result->currency);
        $this->assertSame($code, $result->statusCode);
        $this->assertSame(strtoupper($description), $result->paymentStatusDescription);
        $this->assertSame($classification, $result->classification());
        $this->assertSame('Visa', $result->paymentMethod);
        $this->assertSame('opaque-confirmation', $result->confirmationCode);
        Http::assertSent(fn ($r) => $r->method() === 'GET'
            && $r->url() === PesaPalService::SANDBOX_URL . '/api/Transactions/GetTransactionStatus?orderTrackingId=' . self::GUID
            && $r->hasHeader('Authorization', 'Bearer fixture-bearer'));
        $this->assertSame(0, DB::table('application_payments')->count());
    }
    public static function statuses(): array
    {
        return [[1, 'Completed', 'COMPLETED'], [0, 'Invalid', 'INVALID'], [2, 'Failed', 'FAILED'],
            [3, 'Reversed', 'REVERSED'], [0, 'Pending', 'UNKNOWN'], [99, 'Future status', 'UNKNOWN'], [2, 'Completed', 'UNKNOWN']];
    }

    /** @dataProvider badStatuses */
    public function test_status_rejects_missing_or_malformed_evidence(array $changes): void
    {
        $this->fakeEndpoint('/api/Transactions/GetTransactionStatus', array_merge($this->status(), $changes));
        $this->reject(fn () => $this->service()->getTransactionStatus(self::GUID));
    }
    public static function badStatuses(): array
    {
        return [[['amount' => '50,000']], [['amount' => '1.001']], [['amount' => null]], [['amount' => -1]],
            [['currency' => null]], [['currency' => 'not-currency']], [['merchant_reference' => null]],
            [['payment_status_description' => null]], [['status_code' => []]], [['payment_method' => []]],
            [['order_tracking_id' => '84740ab4-3cd9-47da-8a4f-dd1db53494b5']],
            [['error' => ['message' => 'fixture-secret fixture-bearer']]]];
    }

    public function test_authenticated_http_and_connection_failures_are_sanitized(): void
    {
        $this->fakeEndpoint('/api/Transactions/GetTransactionStatus', 'fixture-secret fixture-bearer', 503);
        $this->reject(fn () => $this->service()->getTransactionStatus(self::GUID));
        Http::fake(['*/Auth/RequestToken' => Http::response($this->token()),
            '*/Transactions/GetTransactionStatus*' => fn () => throw new ConnectionException('fixture-secret fixture-bearer')]);
        $this->reject(fn () => $this->service()->getTransactionStatus(self::GUID));
    }

    public function test_bearer_tokens_are_used_only_for_their_school_and_environment(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/Auth/RequestToken')) {
                $live = str_starts_with($request->url(), PesaPalService::LIVE_URL);
                return Http::response($this->token(['token' => 'token-' . $request['consumer_key'] . ($live ? '-live' : '-sandbox')]));
            }
            return Http::response($this->status());
        });
        foreach ([[1, 'sandbox'], [2, 'sandbox'], [1, 'live'], [1, 'sandbox']] as [$school, $environment]) {
            $config = new PesaPalConfiguration($school, $school, $environment, 'school-' . $school, 'fixture-secret');
            (new PesaPalService($config))->getTransactionStatus(self::GUID);
        }
        Http::assertSentCount(7); // Three auth calls, four status queries.
        $queries = Http::recorded(fn ($r) => str_contains($r->url(), '/GetTransactionStatus'));
        $this->assertSame(['Bearer token-school-1-sandbox', 'Bearer token-school-2-sandbox',
            'Bearer token-school-1-live', 'Bearer token-school-1-sandbox'],
            $queries->map(fn ($pair) => $pair[0]->header('Authorization')[0])->values()->all());
    }

    public function test_short_expiry_fractional_utc_date_and_transport_options(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame(15, $options['timeout']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);
            return Http::response($this->token(['expiryDate' => '2026-10-07T12:02:00.5177702Z']));
        });
        $this->assertSame(now()->timestamp + 90, $this->service()->authenticate()->expiresAt);
    }

    public function test_database_cache_does_not_persist_or_read_bearer_tokens(): void
    {
        config(['cache.stores.pesapal_database_test' => ['driver' => 'database', 'table' => 'not_created', 'connection' => 'sqlite'],
            'cache.default' => 'pesapal_database_test']);
        Http::fake(['*' => Http::response($this->token())]);
        DB::enableQueryLog();
        $this->service()->authenticate(); $this->service()->authenticate();
        $this->assertSame([], DB::getQueryLog());
        Http::assertSentCount(2);
    }

    public function test_configuration_rejects_arbitrary_base_url_duplicate_and_inactive_rows(): void
    {
        $keys = ['consumer_key' => 'fixture-key', 'consumer_secret' => 'fixture-secret', 'environment' => 'sandbox'];
        $row = PaymentMethods::create(['school_id' => 1, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode($keys + ['base_url' => 'https://attacker.test'])]);
        $this->reject(fn () => PesaPalService::forSchool(1));
        $row->update(['payment_keys' => json_encode($keys), 'status' => 0]);
        $this->reject(fn () => PesaPalService::forSchool(1));
        $row->update(['status' => 1]);
        PaymentMethods::create(['school_id' => 1, 'name' => 'pesapal', 'status' => 1, 'payment_keys' => json_encode($keys)]);
        $this->reject(fn () => PesaPalService::forSchool(1));
        Http::assertNothingSent();
    }

    public function test_debug_views_redact_configuration_and_token(): void
    {
        Http::fake(['*' => Http::response($this->token())]);
        $debug = print_r($this->configuration(), true) . print_r($this->service()->authenticate(), true);
        foreach (['fixture-key', 'fixture-secret', 'fixture-bearer'] as $secret) {
            $this->assertStringNotContainsString($secret, $debug);
        }
    }
}
