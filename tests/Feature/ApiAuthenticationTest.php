<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Pre-RBAC cleanup A — the student mobile API (routes/api.php, Sanctum
 * bearer tokens). /login is the only public endpoint; every other endpoint
 * requires a token.
 *
 * The protected group used to be declared as ['middleware', ['auth:sanctum']]
 * (a list, not a 'middleware' key), so no auth middleware ran and anonymous
 * calls reached the controller. Logout also never revoked the token.
 */
class ApiAuthenticationTest extends TestCase
{
    use StaffModuleTestHelper;

    private const PROTECTED = [
        '/api/user_details', '/api/routine', '/api/attendance', '/api/subjects', '/api/syllabus_list',
        '/api/teacher_list', '/api/book_list', '/api/book_issue_list', '/api/exam_list', '/api/marks',
        '/api/profile_update', '/api/fee_list', '/api/logout', '/api/account_delete', '/api/change_profile_photo',
    ];

    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        (require base_path('database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php'))->up();
        (require base_path('database/migrations/2026_10_08_000001_add_expires_at_to_personal_access_tokens.php'))->up();
        $this->school = $this->makeSchool(['title' => 'API School', 'status' => 1]);
    }

    private function student(array $overrides = []): User
    {
        return User::factory()->create($overrides + [
            'role_id' => 7, 'school_id' => $this->school, 'status' => 1,
            'email' => 'api.student.' . uniqid() . '@example.com', 'password' => bcrypt('secret-pass'),
        ]);
    }

    public function test_every_protected_endpoint_rejects_anonymous_requests(): void
    {
        $victim = $this->student();

        foreach (self::PROTECTED as $uri) {
            $this->postJson($uri, ['name' => 'Anon', 'email' => 'anon@example.com'])->assertStatus(401);
        }
        $this->getJson('/api/user')->assertStatus(401);

        $this->assertEquals(1, DB::table('users')->where('id', $victim->id)->value('status'), 'anonymous call changed an account');
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer 999|not-a-real-token')->postJson('/api/user_details')->assertStatus(401);
    }

    public function test_login_stays_public_and_issues_a_token_that_authenticates(): void
    {
        $student = $this->student(['email' => 'api.login@example.com']);

        $login = $this->postJson('/api/login', ['email' => 'api.login@example.com', 'password' => 'secret-pass']);
        $login->assertStatus(201);
        $token = $login->json('token');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/user')->assertOk()->assertJson(['id' => $student->id]);
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/user_details')->assertSuccessful();
    }

    public function test_login_rejects_bad_credentials_and_non_students(): void
    {
        $this->student(['email' => 'api.bad@example.com']);
        $this->postJson('/api/login', ['email' => 'api.bad@example.com', 'password' => 'wrong'])->assertStatus(401);

        User::factory()->create(['role_id' => 3, 'school_id' => $this->school, 'status' => 1, 'email' => 'api.teacher@example.com', 'password' => bcrypt('secret-pass')]);
        $this->postJson('/api/login', ['email' => 'api.teacher@example.com', 'password' => 'secret-pass'])->assertStatus(400);
    }

    public function test_logout_revokes_the_token(): void
    {
        $student = $this->student();
        $token = $student->createToken('auth-token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout')->assertStatus(201);

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $student->id)->count(), 'token still stored after logout');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/user_details')->assertStatus(401);
    }

    public function test_current_token_creation_leaves_additive_expires_at_null(): void
    {
        $student = $this->student();
        $issued = $student->createToken('l1-baseline', ['profile:read']);
        [$id, $secret] = explode('|', $issued->plainTextToken, 2);
        $row = DB::table('personal_access_tokens')->where('id', $id)->first();
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'expires_at'));
        $this->assertNull(DB::table('personal_access_tokens')->where('id', $id)->value('expires_at'));
        $this->assertSame(hash('sha256', $secret), $row->token);
        $this->assertSame($student->id, (int) $row->tokenable_id);
        $this->assertSame(User::class, $row->tokenable_type);
        $this->assertSame(['profile:read'], json_decode($row->abilities, true));
        $this->assertArrayNotHasKey('token', $issued->accessToken->toArray());
    }

    public function test_historical_token_with_null_expiry_authenticates_its_owner(): void
    {
        config(['sanctum.expiration' => null]);
        $student = $this->student();
        $secret = 'l1-historical-fixture-secret';
        $id = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => User::class, 'tokenable_id' => $student->id,
            'name' => 'historical', 'token' => hash('sha256', $secret),
            'abilities' => json_encode(['*']), 'last_used_at' => null,
            'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ]);
        $this->withHeader('Authorization', 'Bearer '.$id.'|'.$secret)->getJson('/api/user')
            ->assertOk()->assertJson(['id' => $student->id, 'school_id' => $this->school]);
        $this->assertNotNull(DB::table('personal_access_tokens')->where('id', $id)->value('last_used_at'));
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'expires_at'));
        $this->assertNull(DB::table('personal_access_tokens')->where('id', $id)->value('expires_at'));
    }

    public function test_global_token_expiration_still_rejects_old_tokens(): void
    {
        config(['sanctum.expiration' => 60]);
        $issued = $this->student()->createToken('expired');
        $issued->accessToken->forceFill(['created_at' => now()->subMinutes(61)])->save();
        $this->withHeader('Authorization', 'Bearer '.$issued->plainTextToken)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_token_id_from_another_school_cannot_be_combined_with_a_valid_secret(): void
    {
        $owner = $this->student();
        $foreign = $this->student(['school_id' => $this->makeSchool(['status' => 1])]);
        $issued = $owner->createToken('owner');
        $foreignIssued = $foreign->createToken('foreign');
        [, $secret] = explode('|', $issued->plainTextToken, 2);
        $this->withHeader('Authorization', 'Bearer '.$foreignIssued->accessToken->id.'|'.$secret)
            ->getJson('/api/user')->assertUnauthorized();
        $this->assertNull($foreignIssued->accessToken->fresh()->last_used_at);
    }

    public function test_authenticated_api_mutation_cannot_target_a_foreign_school_account(): void
    {
        $owner = $this->student();
        $foreign = $this->student(['school_id' => $this->makeSchool(['status' => 1])]);
        $before = $foreign->fresh()->getAttributes();
        $token = $owner->createToken('account-owner')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/account_delete', [
            'id' => $foreign->id, 'user_id' => $foreign->id, 'school_id' => $foreign->school_id,
        ])->assertStatus(201);
        $this->assertSame(0, (int) $owner->fresh()->status);
        $this->assertSame($before, $foreign->fresh()->getAttributes());
    }

    public function test_stateful_origin_uses_the_existing_session_and_cookie_policy(): void
    {
        config(['sanctum.stateful' => ['portal.example.test']]);
        Route::middleware(['api', 'auth:sanctum'])->get('/api/l1-stateful', fn (Request $request) => response()->json([
            'id' => $request->user()->id,
            'school_id' => $request->user()->school_id,
            'stateful' => $request->attributes->get('sanctum', false),
            'has_session' => $request->hasSession(),
        ]));
        $student = $this->student();
        $this->actingAs($student, 'web')->withHeader('Origin', 'https://portal.example.test')
            ->getJson('/api/l1-stateful')->assertOk()->assertJson([
                'id' => $student->id, 'school_id' => $this->school, 'stateful' => true, 'has_session' => true,
            ]);
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend(Request::create('/api/user', 'GET', [], [], [], [
            'HTTP_ORIGIN' => 'https://portal.example.test',
        ])));
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend(Request::create('/api/user', 'GET', [], [], [], [
            'HTTP_ORIGIN' => 'https://portal.example.test.attacker.test',
        ])));
    }

    public function test_stateful_guest_is_not_authenticated_by_origin_alone(): void
    {
        config(['sanctum.stateful' => ['portal.example.test']]);
        $this->withHeader('Origin', 'https://portal.example.test')->getJson('/api/user')->assertUnauthorized();
    }

    public function test_stateful_csrf_is_enforced_when_the_test_bypass_is_disabled(): void
    {
        config(['sanctum.stateful' => ['portal.example.test']]);
        // Laravel normally skips CSRF in PHPUnit. Disable only that test bypass,
        // preserving the application's real exclusions and token comparison.
        $this->app->bind(\App\Http\Middleware\VerifyCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends \App\Http\Middleware\VerifyCsrfToken {
            protected function runningUnitTests() { return false; }
        });
        $student = $this->student();
        $this->withHeader('Origin', 'https://portal.example.test')->postJson('/api/login', [
            'email' => $student->email, 'password' => 'secret-pass',
        ])->assertStatus(419);
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->withSession(['_token' => 'l1-csrf-fixture'])->withHeader('X-CSRF-TOKEN', 'l1-csrf-fixture')
            ->postJson('/api/login', ['email' => $student->email, 'password' => 'secret-pass'])->assertStatus(201);
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }
}
