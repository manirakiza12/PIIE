<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\TestCase;

/**
 * Stability repair H4 — a Google Meet / Zoom API that cannot be reached (network
 * down, DNS, TLS certificate verification failure — e.g. a PHP runtime without a
 * CA bundle) must not crash Live Classes with HTTP 500. The request comes back
 * as a normal validation error on meeting_url, the entered data is kept, nothing
 * is saved, and the failure is logged server-side without secrets.
 */
class LiveClassProviderFailureTest extends TestCase
{
    use LiveClassTestHelper;

    private int $schoolId;
    private $admin;
    private int $classId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLiveClassTestSchema();
        $this->schoolId = $this->makeSchool();
        $this->admin = $this->makeStaffUser($this->schoolId, 2);
        $this->classId = $this->makeClass($this->schoolId);

        Config::set('services.google_meet.client_id', 'test-client-id');
        Config::set('services.google_meet.client_secret', 'test-client-secret');
        Config::set('services.google_meet.refresh_token', 'test-refresh-token');
        Config::set('services.google_meet.calendar_id', 'primary');
        Config::set('services.zoom.account_id', 'test-account');
        Config::set('services.zoom.client_id', 'test-zoom-client');
        Config::set('services.zoom.client_secret', 'test-zoom-secret');
    }

    private function schedule(string $platform)
    {
        return $this->actingAs($this->admin)->from(route('admin.live_classes.create'))->post(route('admin.live_classes.store'), [
            'title' => 'Resilience Class',
            'class_id' => $this->classId,
            'platform' => $platform,
            'start_date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'timezone' => 'UTC',
        ]);
    }

    public static function providers(): array
    {
        return ['google meet' => ['google_meet', 'Google Meet'], 'zoom' => ['zoom', 'Zoom']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_an_unreachable_provider_is_a_validation_error_not_a_500(string $platform, string $label): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate'));
        Log::spy();

        $response = $this->schedule($platform);

        $response->assertRedirect(route('admin.live_classes.create'));
        $response->assertSessionHasErrors('meeting_url');
        $message = session('errors')->first('meeting_url');
        $this->assertStringContainsString($label, $message);
        $this->assertStringContainsString('could not be reached', $message);
        foreach (['cURL', 'SSL', 'certificate', 'test-client-secret', 'test-zoom-secret', 'refresh'] as $internal) {
            $this->assertStringNotContainsString($internal, $message);
        }
        $response->assertSessionHasInput('title', 'Resilience Class');
        $this->assertSame(0, DB::table('live_classes')->count(), 'nothing saved');

        Log::shouldHaveReceived('warning')->withArgs(function ($text, $context) use ($label) {
            $flat = $text . json_encode($context);

            return str_contains($text, $label) && !str_contains($flat, 'test-client-secret') && !str_contains($flat, 'test-zoom-secret')
                && !str_contains($flat, 'test-refresh-token') && ($context['exception'] ?? null) === ConnectionException::class;
        })->once();
    }

    /** The exact failure seen in the log: cURL error 60 surfaces as a raw Guzzle RequestException. */
    public function test_a_tls_certificate_failure_is_handled_the_same_way(): void
    {
        Http::fake(fn () => throw new \GuzzleHttp\Exception\RequestException(
            'cURL error 60: SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)',
            new \GuzzleHttp\Psr7\Request('POST', 'https://oauth2.googleapis.com/token')
        ));

        $response = $this->schedule('google_meet');

        $response->assertSessionHasErrors('meeting_url');
        $this->assertStringContainsString('could not be reached', session('errors')->first('meeting_url'));
        $this->assertSame(0, DB::table('live_classes')->count());
    }

    // A real cURL TLS failure, which is what the missing CA bundle produced.
    //
    // The existing test above throws a hand-built RequestException, which carries NO
    // handler context - so it exercises the generic transport branch, not the TLS
    // one. This test supplies the handler context that a genuine cURL error 60
    // always carries, and pins the two behaviours that were missing: the failure is
    // classified in the log, and the lecturer is told it is a configuration fault
    // rather than being sent into a retry loop against a provider that was never
    // contacted.
    public function test_laravel_connection_wrapper_preserves_tls_classification_without_secret_logging(): void
    {
        $raw = new \GuzzleHttp\Exception\RequestException('fixture-url-secret',
            new \GuzzleHttp\Psr7\Request('POST', 'https://oauth2.googleapis.com/token?client_secret=fixture-url-secret', ['Authorization' => 'Bearer fixture-bearer-secret']),
            null, null, ['errno' => 60, 'error' => 'SSL certificate problem: unable to get local issuer certificate']);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('fixture-message-secret', 0, $raw));
        Log::spy();
        $this->schedule('google_meet')->assertSessionHasErrors('meeting_url');
        $this->assertStringContainsString('configuration fault', session('errors')->first('meeting_url'));
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $line, array $context): bool {
            $logged = $line.json_encode($context);
            return ($context['failure_kind'] ?? '') === 'tls_trust_failure' && ($context['curl_errno'] ?? 0) === 60
                && !str_contains($logged, 'fixture-url-secret') && !str_contains($logged, 'fixture-bearer-secret')
                && !str_contains($logged, 'fixture-message-secret');
        });
        $this->assertSame(0, DB::table('live_classes')->count());
    }

    public function test_nested_http_wrappers_preserve_network_error_context(): void
    {
        $raw = new \GuzzleHttp\Exception\RequestException('unused fixture message', new \GuzzleHttp\Psr7\Request('POST', 'https://example.test'),
            null, null, ['errno' => 28, 'error' => 'Connection timed out']);
        $wrapped = new \Illuminate\Http\Client\ConnectionException('unused outer message', 0,
            new \Illuminate\Http\Client\ConnectionException('unused inner message', 0, $raw));
        $method = new \ReflectionMethod(\App\Http\Controllers\LiveClassController::class, 'describeTransportFailure');
        $method->setAccessible(true);
        $result = $method->invoke(app(\App\Http\Controllers\LiveClassController::class), $wrapped);
        $this->assertSame('network_unreachable', $result['kind']);
        $this->assertSame(28, $result['errno']);
        $this->assertSame('Connection timed out', $result['message']);
    }

    public function test_a_real_tls_trust_failure_is_classified_and_not_reported_as_a_provider_outage(): void
    {
        Http::fake(fn () => throw new \GuzzleHttp\Exception\RequestException(
            'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
            new \GuzzleHttp\Psr7\Request('POST', 'https://oauth2.googleapis.com/token'),
            null,
            null,
            // Guzzle 7 takes the handler context as the FIFTH argument; passing it
            // fourth binds to ?Throwable $previous and raises a TypeError instead,
            // which would fail the test for the wrong reason entirely.
            ['errno' => 60, 'error' => 'SSL certificate problem: unable to get local issuer certificate']
        ));
        Log::spy();

        $response = $this->schedule('google_meet');

        $response->assertSessionHasErrors('meeting_url');
        $message = session('errors')->first('meeting_url');

        // Told it is a configuration fault: retrying cannot help.
        $this->assertStringContainsString('configuration fault', $message);
        // NOT the "provider is down, try again shortly" wording, which sent the
        // investigation to Google instead of to this host's php.ini.
        $this->assertStringNotContainsString('could not be reached', $message);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $line, array $context): bool {
                return str_contains($line, 'tls_trust_failure')
                    && ($context['failure_kind'] ?? null) === 'tls_trust_failure'
                    && ($context['curl_errno'] ?? null) === 60
                    && str_contains((string) ($context['hint'] ?? ''), 'curl.cainfo');
            });

        $this->assertSame(0, DB::table('live_classes')->count());
    }

    public function test_a_successful_google_meet_call_still_creates_the_class(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token'], 200),
            'www.googleapis.com/calendar/v3/*' => Http::response(['hangoutLink' => 'https://meet.google.com/ok-room'], 200),
        ]);

        $this->schedule('google_meet')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('https://meet.google.com/ok-room', DB::table('live_classes')->value('meeting_url'));
    }

    /** Configured provider that answers without a meeting link — every documented failure shape. */
    public static function providerResponses(): array
    {
        $token = ['access_token' => 'fake-access-token'];

        return [
            'expired / invalid grant (400)' => ['google_meet', ['oauth2.googleapis.com/*' => [['error' => 'invalid_grant'], 400]]],
            'invalid client (401)' => ['google_meet', ['oauth2.googleapis.com/*' => [['error' => 'invalid_client'], 401]]],
            'forbidden (403)' => ['google_meet', ['oauth2.googleapis.com/*' => [$token, 200], 'www.googleapis.com/*' => [['error' => 'forbidden'], 403]]],
            'calendar not found (404)' => ['google_meet', ['oauth2.googleapis.com/*' => [$token, 200], 'www.googleapis.com/*' => [['error' => 'notFound'], 404]]],
            'rate limited (429)' => ['google_meet', ['oauth2.googleapis.com/*' => [['error' => 'rate'], 429]]],
            'provider error (500)' => ['google_meet', ['oauth2.googleapis.com/*' => [$token, 200], 'www.googleapis.com/*' => [['error' => 'backend'], 500]]],
            'unavailable (503)' => ['google_meet', ['oauth2.googleapis.com/*' => [[], 503]]],
            'malformed token response' => ['google_meet', ['oauth2.googleapis.com/*' => [['unexpected' => true], 200]]],
            'malformed event response' => ['google_meet', ['oauth2.googleapis.com/*' => [$token, 200], 'www.googleapis.com/*' => [['id' => 'evt'], 200]]],
            'zoom unauthorized (401)' => ['zoom', ['zoom.us/*' => [['reason' => 'Invalid client_id'], 401]]],
            'zoom rate limited (429)' => ['zoom', ['zoom.us/*' => [['code' => 429], 429]]],
            'zoom unavailable (503)' => ['zoom', ['zoom.us/*' => [[], 503]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providerResponses')]
    public function test_a_configured_provider_that_fails_gives_a_controlled_message(string $platform, array $responses): void
    {
        Http::fake(array_map(fn ($r) => Http::response($r[0], $r[1]), $responses) + ['*' => Http::response([], 500)]);
        Log::spy();

        $response = $this->schedule($platform);

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasErrors('meeting_url');
        $message = session('errors')->first('meeting_url');
        $this->assertStringContainsString('did not create a meeting link', $message);
        $this->assertStringNotContainsString('not configured', $message);
        foreach (['fake-access-token', 'test-client-secret', 'test-zoom-secret', 'invalid_grant', 'invalid_client'] as $secret) {
            $this->assertStringNotContainsString($secret, $message);
        }
        $this->assertSame(0, DB::table('live_classes')->count(), 'no incomplete class saved');
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_connection_timeout_is_handled(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20000 milliseconds'));

        $this->schedule('google_meet')->assertSessionHasErrors('meeting_url');
        $this->assertStringContainsString('could not be reached', session('errors')->first('meeting_url'));
        $this->assertSame(0, DB::table('live_classes')->count());
    }

    public function test_an_unconfigured_provider_keeps_its_setup_message(): void
    {
        Config::set('services.google_meet.refresh_token', '');
        Http::fake();

        $this->schedule('google_meet')->assertSessionHasErrors('meeting_url');
        $this->assertStringContainsString('not configured', session('errors')->first('meeting_url'));
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('live_classes')->count());
    }

    public function test_provider_calls_have_bounded_timeouts(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/LiveClassController.php'));
        $this->assertSame(4, substr_count($source, '->connectTimeout(10)->timeout(20)'), 'every provider call is time-bounded');
        $this->assertStringNotContainsString("'verify' => false", $source);
        $this->assertStringNotContainsString('withoutVerifying', $source);
    }
}
