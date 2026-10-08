<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class CorsCompatibilityTest extends TestCase
{
    use StaffModuleTestHelper;

    private const ORIGIN = 'https://portal.example.test';
    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        (require base_path('database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php'))->up();
        (require base_path('database/migrations/2026_10_08_000001_add_expires_at_to_personal_access_tokens.php'))->up();
        $this->school = $this->makeSchool(['status' => 1]);
        // Keep browser-session classification separate from bearer-token CORS.
        config(['sanctum.stateful' => []]);
        Route::middleware('api')->get('/api/l1-cors-public', fn () => response()->json(['ok' => true]));
        // Exercise the real web, locale and applicant middleware with CORS enabled
        // on this test-only path. The applicant dashboard itself is unchanged.
        Route::middleware(['web', 'applicant'])->get('/api/l1-cors-applicant', fn () => response()->json([
            'school_id' => (int) auth('applicant')->user()->school_id,
            'locale' => app()->getLocale(),
        ]));
    }

    private function restrictOrigins(): void
    {
        config(['cors.allowed_origins' => [self::ORIGIN, 'https://second.example.test']]);
    }

    public function test_default_cors_configuration_and_middleware_order_are_preserved(): void
    {
        $this->assertSame(require base_path('config/cors.php'), config('cors'));
        $kernel = $this->app->make(Kernel::class);
        $this->assertTrue($kernel->hasMiddleware(\Illuminate\Http\Middleware\HandleCors::class));
        $this->assertFalse($kernel->hasMiddleware(\Fruitcake\Cors\HandleCors::class));
        $property = new \ReflectionProperty($kernel, 'middleware');
        $property->setAccessible(true);
        $stack = $property->getValue($kernel);
        $position = array_search(\Illuminate\Http\Middleware\HandleCors::class, $stack, true);
        $this->assertSame(\App\Http\Middleware\TrustProxies::class, $stack[$position - 1]);
        $this->assertSame(\App\Http\Middleware\PreventRequestsDuringMaintenance::class, $stack[$position + 1]);
    }

    public static function legacyParityCases(): array
    {
        $allowlist = ['cors.allowed_origins' => [self::ORIGIN, 'https://second.example.test']];
        return [
            'wildcard actual' => ['GET', self::ORIGIN, [], '/api/l1-cors-public'],
            'wildcard no origin' => ['GET', null, [], '/api/l1-cors-public'],
            'allowlist allowed' => ['GET', self::ORIGIN, $allowlist, '/api/l1-cors-public'],
            'allowlist denied' => ['GET', 'https://untrusted.example.test', $allowlist, '/api/l1-cors-public'],
            'single origin denied browser' => ['GET', 'https://untrusted.example.test', ['cors.allowed_origins' => [self::ORIGIN]], '/api/l1-cors-public'],
            'preflight allowed' => ['OPTIONS', self::ORIGIN, $allowlist, '/api/l1-cors-public'],
            'preflight denied' => ['OPTIONS', 'https://untrusted.example.test', $allowlist, '/api/l1-cors-public'],
            'credentialed actual' => ['GET', self::ORIGIN, $allowlist + ['cors.supports_credentials' => true, 'cors.exposed_headers' => ['X-School']], '/api/l1-cors-public'],
            'excluded path' => ['GET', self::ORIGIN, [], '/l1-not-cors'],
        ];
    }

    /** @dataProvider legacyParityCases */
    public function test_builtin_middleware_preserves_legacy_cors_responses(string $method, ?string $origin, array $settings, string $path): void
    {
        config($settings);
        $server = $origin === null ? [] : ['HTTP_ORIGIN' => $origin];
        if ($method === 'OPTIONS') {
            $server['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = 'POST';
            $server['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] = 'Authorization, Content-Type';
        }
        $responses = [];
        foreach ([\Fruitcake\Cors\HandleCors::class, \Illuminate\Http\Middleware\HandleCors::class] as $middleware) {
            $request = Request::create('http://localhost'.$path, $method, [], [], [], $server);
            $response = $this->app->make($middleware)->handle($request, fn () => response('l1-next', 200));
            $headers = [];
            foreach (['Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods',
                'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers', 'Access-Control-Max-Age', 'Vary'] as $header) {
                $headers[$header] = $response->headers->get($header);
            }
            $responses[] = [$response->getStatusCode(), $response->getContent(), $headers];
        }
        $this->assertSame($responses[0], $responses[1]);
    }

    public function test_default_allowed_request_uses_wildcard_without_credentials(): void
    {
        $response = $this->withHeader('Origin', self::ORIGIN)->getJson('/api/l1-cors-public');
        $response->assertOk()->assertJson(['ok' => true])->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    public function test_request_without_origin_preserves_the_static_wildcard_header(): void
    {
        $response = $this->getJson('/api/l1-cors-public')->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    public function test_preflight_preserves_configured_methods_headers_and_max_age(): void
    {
        $this->restrictOrigins();
        config([
            'cors.allowed_methods' => ['GET', 'POST'],
            'cors.allowed_headers' => ['Authorization', 'Content-Type'],
            'cors.max_age' => 600,
        ]);
        $response = $this->withHeaders([
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type',
        ])->options('/api/profile_update');
        $response->assertStatus(204)->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)
            ->assertHeader('Access-Control-Max-Age', '600');
        $this->assertStringContainsString('POST', $response->headers->get('Access-Control-Allow-Methods'));
        $headers = strtolower($response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('authorization', $headers);
        $this->assertStringContainsString('content-type', $headers);
        $this->assertStringContainsString('Access-Control-Request-Method', $response->headers->get('Vary'));
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_explicit_credentials_and_exposed_header_configuration_is_honoured(): void
    {
        $this->restrictOrigins();
        config(['cors.supports_credentials' => true, 'cors.exposed_headers' => ['X-School']]);
        $response = $this->withHeader('Origin', self::ORIGIN)->getJson('/api/l1-cors-public');
        $response->assertOk()->assertHeader('Access-Control-Allow-Origin', self::ORIGIN)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertSame('x-school', strtolower($response->headers->get('Access-Control-Expose-Headers')));
    }

    public function test_disallowed_origin_receives_no_permission_header_on_actual_request(): void
    {
        $this->restrictOrigins();
        $response = $this->withHeader('Origin', 'https://untrusted.example.test')->getJson('/api/l1-cors-public');
        // CORS denies browser access through headers, not an application HTTP 403.
        $response->assertOk();
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    public function test_disallowed_preflight_receives_no_origin_permission(): void
    {
        $this->restrictOrigins();
        $response = $this->withHeaders([
            'Origin' => 'https://untrusted.example.test',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/profile_update')->assertStatus(204);
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_allowed_origin_and_successful_preflight_do_not_authenticate_an_api_request(): void
    {
        $this->restrictOrigins();
        $this->withHeaders(['Origin' => self::ORIGIN, 'Access-Control-Request-Method' => 'GET'])
            ->options('/api/user')->assertStatus(204);
        $this->withHeaders(['Origin' => self::ORIGIN, 'Access-Control-Request-Method' => ''])
            ->getJson('/api/user')->assertUnauthorized()->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);
        $this->withHeader('Authorization', 'Bearer 999|invalid')->getJson('/api/user')->assertUnauthorized();
    }

    public function test_bearer_identity_and_tenant_cannot_be_overridden_by_cors_or_query_parameters(): void
    {
        $otherSchool = $this->makeSchool(['status' => 1]);
        $student = User::factory()->create(['role_id' => 7, 'school_id' => $this->school, 'status' => 1]);
        $foreign = User::factory()->create(['role_id' => 7, 'school_id' => $otherSchool, 'status' => 1]);
        $token = $student->createToken('l1-cors')->plainTextToken;
        $this->withHeaders(['Origin' => self::ORIGIN, 'Authorization' => 'Bearer '.$token])
            ->getJson('/api/user?user_id='.$foreign->id.'&school_id='.$otherSchool)
            ->assertOk()->assertJson(['id' => $student->id, 'school_id' => $this->school]);
    }

    public function test_cors_does_not_bypass_applicant_tenant_resolution_or_locale_middleware(): void
    {
        DB::table('global_settings')->insert(['key' => 'primary_school_id', 'value' => (string) $this->school]);
        DB::table('global_settings')->insert(['key' => 'language', 'value' => 'french']);
        $applicant = $this->makeApplicant($this->school);
        $this->actingAs($applicant, 'applicant')->withHeader('Origin', self::ORIGIN)
            ->getJson('/api/l1-cors-applicant')->assertOk()->assertJson(['school_id' => $this->school, 'locale' => 'fr']);
        $foreign = $this->makeApplicant($this->makeSchool(['status' => 1]));
        $this->actingAs($foreign, 'applicant')->getJson('/api/l1-cors-applicant?school_id='.$this->school)
            ->assertRedirect(route('applicant.login'));
        $this->assertGuest('applicant');
    }
}
