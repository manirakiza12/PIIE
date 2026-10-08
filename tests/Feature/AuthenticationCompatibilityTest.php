<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/** Current Laravel 9 authentication contracts; no authentication redesign. */
class AuthenticationCompatibilityTest extends TestCase
{
    use StaffModuleTestHelper;

    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        $this->school = $this->makeSchool(['status' => 1]);
        DB::table('global_settings')->insert(['key' => 'primary_school_id', 'value' => (string) $this->school]);
        Notification::fake();
        Mail::fake();
        foreach (self::roles() as [$role, , $middleware]) {
            Route::middleware(['web', 'auth', $middleware])->get('/l1-auth-role/'.$role, fn (Request $request) => response()->json([
                'id' => $request->user()->id,
                'school_id' => $request->user()->school_id,
                'role_id' => $request->user()->role_id,
            ]));
        }
    }

    public static function roles(): array
    {
        return [
            'admin' => [2, 'admin.dashboard', 'school_admin', 'admin.account_disableview'],
            'lecturer' => [3, 'teacher.dashboard', 'teacher', 'teacher.account_disable'],
            'student' => [7, 'student.dashboard', 'student', 'student.account_disable'],
            'parent' => [6, 'parent.dashboard', 'parent', 'parent.account_disable'],
        ];
    }

    private function user(int $role, array $overrides = []): User
    {
        return User::factory()->create($overrides + [
            'role_id' => $role, 'school_id' => $this->school, 'status' => 1,
            'account_status' => 'active', 'password' => Hash::make('l1-secret-password'),
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_valid_login_retains_role_redirect_identity_and_school(int $role, string $home): void
    {
        $user = $this->user($role);
        $foreignSchool = $this->makeSchool(['status' => 1]);
        $this->post('/login', [
            'email' => $user->email, 'password' => 'l1-secret-password',
            'school_id' => $foreignSchool, 'role_id' => 2,
        ])->assertRedirect(route($home));
        $this->assertAuthenticatedAs($user);
        $this->getJson('/l1-auth-role/'.$role)->assertOk()->assertJson([
            'id' => $user->id, 'school_id' => $this->school, 'role_id' => $role,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_invalid_credentials_do_not_authenticate(int $role): void
    {
        $user = $this->user($role);
        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])
            ->assertRedirect(route('login'))->assertSessionHas('error');
        $this->assertGuest();
        $this->getJson('/l1-auth-role/'.$role)->assertUnauthorized();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_logout_revokes_session_access_to_the_role_surface(int $role): void
    {
        $user = $this->user($role);
        $this->post('/login', ['email' => $user->email, 'password' => 'l1-secret-password']);
        $this->assertAuthenticatedAs($user);
        $this->postJson('/logout')->assertStatus(204);
        $this->assertGuest();
        $this->getJson('/l1-auth-role/'.$role)->assertUnauthorized();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_disabled_legacy_account_authenticates_but_role_middleware_denies_access(int $role, string $home, string $middleware, string $disabled): void
    {
        // Existing RbacLoginRedirectCharacterizationTest pins this split:
        // login accepts these legacy roles; authorization denies their portal.
        $user = $this->user($role, ['account_status' => 'disable']);
        $this->post('/login', ['email' => $user->email, 'password' => 'l1-secret-password'])->assertRedirect(route($home));
        $this->assertAuthenticatedAs($user);
        $this->getJson('/l1-auth-role/'.$role)->assertRedirect(route($disabled));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_password_reset_notification_and_valid_token_restore_only_the_owner(int $role): void
    {
        $user = $this->user($role);
        $foreign = $this->user($role, ['school_id' => $this->makeSchool(['status' => 1])]);
        $foreignHash = $foreign->password;
        $token = null;
        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;
            return true;
        });
        $this->assertNotEmpty($token);
        $this->post(route('password.update'), [
            'email' => $user->email, 'token' => $token,
            'password' => 'l1-new-password', 'password_confirmation' => 'l1-new-password',
            'user_id' => $foreign->id, 'school_id' => $foreign->school_id,
        ])->assertRedirect('/');
        $this->assertTrue(Hash::check('l1-new-password', $user->fresh()->password));
        $this->assertSame($foreignHash, $foreign->fresh()->password);
        $this->assertFalse(Password::broker('users')->tokenExists($user->fresh(), $token));
        $this->assertAuthenticatedAs($user->fresh());
    }

    public static function rejectedResetCases(): array
    {
        $cases = [];
        foreach (self::roles() as $label => [$role]) {
            foreach (['incorrect', 'expired', 'foreign-owner'] as $reason) {
                $cases[$label.' '.$reason] = [$role, $reason];
            }
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedResetCases')]
    public function test_rejected_password_reset_leaves_credentials_and_authentication_unchanged(int $role, string $reason): void
    {
        $user = $this->user($role);
        $hash = $user->password;
        $token = Password::broker('users')->createToken($user);
        if ($reason === 'incorrect') {
            $token = 'not-the-issued-token';
        } elseif ($reason === 'expired') {
            DB::table('password_resets')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
        } else {
            $foreign = $this->user($role, ['school_id' => $this->makeSchool(['status' => 1])]);
            $token = Password::broker('users')->createToken($foreign);
        }
        $this->post(route('password.update'), [
            'email' => $user->email, 'token' => $token,
            'password' => 'l1-new-password', 'password_confirmation' => 'l1-new-password',
        ])->assertSessionHasErrors('email');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertGuest();
    }

    public function test_wrong_role_cannot_enter_another_authenticated_portal(): void
    {
        $student = $this->user(7);
        $this->post('/login', ['email' => $student->email, 'password' => 'l1-secret-password']);
        foreach ([2, 3, 6] as $otherRole) {
            $this->getJson('/l1-auth-role/'.$otherRole)->assertRedirect(route('student.dashboard'));
        }
        $this->assertAuthenticatedAs($student);
    }

    public function test_applicant_login_logout_and_guard_separation(): void
    {
        $applicant = $this->makeApplicant($this->school);
        $this->post(route('applicant.login.submit'), ['email' => $applicant->email, 'password' => 'secret-password'])
            ->assertRedirect(route('applicant.dashboard'));
        $this->assertAuthenticatedAs($applicant, 'applicant');
        $this->assertGuest('web');
        $this->assertNotNull($applicant->fresh()->last_login_at);
        $this->get(route('applicant.dashboard'))->assertOk();
        $this->getJson('/l1-auth-role/7')->assertUnauthorized();
        $this->post(route('applicant.logout'))->assertRedirect(route('applicant.login'));
        $this->assertGuest('applicant');
        $this->get(route('applicant.dashboard'))->assertRedirect(route('applicant.login'));
    }

    public static function rejectedApplicantLogins(): array
    {
        return ['wrong password' => ['password'], 'inactive' => ['inactive'], 'foreign school' => ['foreign'], 'web account' => ['web']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedApplicantLogins')]
    public function test_applicant_login_rejects_invalid_inactive_foreign_or_web_credentials(string $reason): void
    {
        $applicant = $this->makeApplicant($reason === 'foreign' ? $this->makeSchool(['status' => 1]) : $this->school,
            $reason === 'inactive' ? ['is_active' => 0] : []);
        $email = $applicant->email;
        $password = $reason === 'password' ? 'incorrect' : 'secret-password';
        if ($reason === 'web') {
            $user = $this->user(2);
            $email = $user->email;
            $password = 'l1-secret-password';
        }
        $this->from(route('applicant.login'))->post(route('applicant.login.submit'), [
            'email' => $email, 'password' => $password, 'school_id' => $applicant->school_id,
        ])->assertRedirect(route('applicant.login'))->assertSessionHas('error');
        $this->assertGuest('applicant');
        $this->assertGuest('web');
        $this->assertNull($applicant->fresh()->last_login_at);
    }

    public function test_existing_applicant_session_is_rejected_after_public_school_changes(): void
    {
        $applicant = $this->makeApplicant($this->school);
        $this->post(route('applicant.login.submit'), ['email' => $applicant->email, 'password' => 'secret-password']);
        $otherSchool = $this->makeSchool(['status' => 1]);
        DB::table('global_settings')->where('key', 'primary_school_id')->update(['value' => (string) $otherSchool]);
        $this->get(route('applicant.dashboard', ['school_id' => $this->school]))->assertRedirect(route('applicant.login'));
        $this->assertGuest('applicant');
    }

    public function test_existing_applicant_session_is_rejected_after_deactivation(): void
    {
        $applicant = $this->makeApplicant($this->school);
        $this->post(route('applicant.login.submit'), ['email' => $applicant->email, 'password' => 'secret-password']);
        $applicant->update(['is_active' => 0]);
        // Force rehydration so this represents the next real HTTP request.
        $this->app['auth']->forgetGuards();
        $this->get(route('applicant.dashboard'))->assertRedirect(route('applicant.login'));
        $this->assertGuest('applicant');
    }

    public static function applicantResetCases(): array
    {
        return ['valid' => ['valid'], 'wrong token' => ['incorrect'], 'expired' => ['expired'], 'foreign school' => ['foreign']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('applicantResetCases')]
    public function test_applicant_reset_preserves_current_expiry_and_school_boundary(string $reason): void
    {
        $applicant = $this->makeApplicant($reason === 'foreign' ? $this->makeSchool(['status' => 1]) : $this->school);
        $hash = $applicant->password;
        $token = 'l1-applicant-reset-fixture';
        DB::table('applicant_password_resets')->insert([
            'email' => $applicant->email, 'token' => Hash::make($token),
            'created_at' => $reason === 'expired' ? now()->subMinutes(61) : now(),
        ]);
        $response = $this->from(route('applicant.password.request'))->post(route('applicant.password.update'), [
            'email' => $applicant->email, 'token' => $reason === 'incorrect' ? 'wrong-token' : $token,
            'password' => 'l1-new-password', 'password_confirmation' => 'l1-new-password',
            'school_id' => $applicant->school_id,
        ]);
        if ($reason === 'valid') {
            $response->assertRedirect(route('applicant.login'));
            $this->assertTrue(Hash::check('l1-new-password', $applicant->fresh()->password));
            $this->assertSame(0, DB::table('applicant_password_resets')->where('email', $applicant->email)->count());
        } else {
            $response->assertRedirect(route('applicant.password.request'))->assertSessionHas('error');
            $this->assertSame($hash, $applicant->fresh()->password);
        }
        $this->assertGuest('web');
        $this->assertGuest('applicant');
    }
}
