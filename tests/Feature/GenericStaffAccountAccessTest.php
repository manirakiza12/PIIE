<?php

namespace Tests\Feature;

use App\Mail\GenericStaffPasswordSetupMail;
use App\Models\User;
use App\Support\Permissions\PermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Mail\MailManager;
use Mockery;
use Symfony\Component\Mime\Exception\LogicException as MimeLogicException;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class GenericStaffAccountAccessTest extends TestCase
{
    use StaffModuleTestHelper;

    private int $school;
    private int $otherSchool;
    private User $admin;
    private User $staff;
    private string $temporaryPassword = 'TemporaryOnly-456!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('school_type')->default('k12');
        });
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        (require base_path('database/migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        (require base_path('database/migrations/2026_09_23_000004_add_is_active_to_staff_roles.php'))->up();
        (require base_path('database/migrations/2026_09_24_000001_create_staff_professional_records_tables.php'))->up();
        (require base_path('database/migrations/2014_10_12_100000_create_password_resets_table.php'))->up();

        $this->school = $this->makeSchool(['title' => 'School A', 'status' => 1]);
        $this->otherSchool = $this->makeSchool(['title' => 'School B', 'status' => 1]);
        $this->admin = $this->makeAdminUser($this->school);
        $this->staff = $this->makeGenericStaff($this->school, 'registrar.test@school.test');
        Mail::fake();
    }

    private function makeGenericStaff(int $schoolId, string $email, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'name' => 'Test Registrar',
            'first_name' => 'Test',
            'last_name' => 'Registrar',
            'email' => $email,
            'role_id' => 20,
            'school_id' => $schoolId,
            'staff_status' => 'active',
            'account_status' => 'active',
            'force_password_change' => true,
            'password' => Hash::make($this->temporaryPassword),
        ]);
    }

    private function setupUrlAndToken(): array
    {
        $url = null;
        Mail::assertSent(GenericStaffPasswordSetupMail::class, function (GenericStaffPasswordSetupMail $mail) use (&$url): bool {
            if (!$mail->hasTo($this->staff->email)) {
                return false;
            }
            $url = $mail->setupUrl;

            return true;
        });

        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));

        return [$url, $segments ? rawurldecode((string) end($segments)) : null];
    }

    private function issueSetupLink(): string
    {
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.account-access', $this->staff->id))->assertOk();
        $this->post(route('admin.rbac.staff.account-access.send', $this->staff->id))
            ->assertRedirect(route('admin.rbac.staff.account-access', $this->staff->id))
            ->assertSessionHas('message');

        [, $token] = $this->setupUrlAndToken();
        $this->assertNotEmpty($token);
        Auth::logout();

        return $token;
    }

    public function test_school_admin_has_separate_account_access_ui(): void
    {
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk()
            ->assertSee('Account Access')
            ->assertSee(route('admin.staff.account-access.show', $this->staff->id), false);

        $page = $this->get(route('admin.rbac.staff.account-access', $this->staff->id))->assertOk()
            ->assertSee('Account Access')
            ->assertSee($this->staff->email)
            ->assertSee('Setup required')
            ->assertSee('Send Password Setup Link')
            ->assertDontSee($this->temporaryPassword)
            ->assertDontSee($this->staff->password);

        $page->assertDontSee('Manage access');
    }

    /**
     * The workflow was originally reachable for Other Staff (role 20) only, and
     * the resolver refused every other base role with a 404. It now serves every
     * staff member of the administrator's own school, so tenant isolation and
     * authorization are asserted here instead of the old role restriction.
     */
    public function test_only_same_school_school_administrator_can_issue_links(): void
    {
        $tokenCount = DB::table('password_resets')->count();

        $teacher = User::factory()->create([
            'role_id' => 3, 'school_id' => $this->school, 'account_status' => 'active', 'staff_status' => 'active',
        ]);
        $this->actingAs($teacher)->post(route('admin.rbac.staff.account-access.send', $this->staff->id))->assertForbidden();
        $this->assertSame($tokenCount, DB::table('password_resets')->count());

        Auth::logout();
        $foreignStaff = $this->makeGenericStaff($this->otherSchool, 'foreign.registrar@school.test');
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.account-access', $foreignStaff->id))->assertNotFound();
        $this->post(route('admin.rbac.staff.account-access.send', $foreignStaff->id))->assertNotFound();
        // ... and the same for the HR-scoped entry point.
        $this->get(route('admin.staff.account-access.show', $foreignStaff->id))->assertNotFound();
        $this->post(route('admin.staff.account-access.send', $foreignStaff->id))->assertNotFound();
        // A student is never a staff record.
        $student = User::factory()->create([
            'role_id' => 7, 'school_id' => $this->school, 'account_status' => 'active',
        ]);
        $this->get(route('admin.staff.account-access.show', $student->id))->assertNotFound();
        $this->assertSame($tokenCount, DB::table('password_resets')->count());

        // A same-school Lecturer is now reachable, which is the point of the fix.
        $this->get(route('admin.staff.account-access.show', $teacher->id))->assertOk()
            ->assertSee('Account Access');
        $this->assertSame($tokenCount, DB::table('password_resets')->count(), 'viewing issues nothing');

        Auth::logout();
        $this->actingAs($this->staff)->post(route('admin.rbac.staff.account-access.send', $this->staff->id))->assertRedirect();
        $this->assertSame($tokenCount, DB::table('password_resets')->count());
    }

    public function test_setup_email_contains_only_expiring_password_link_not_any_password_or_hash(): void
    {
        $this->issueSetupLink();
        [$url, $token] = $this->setupUrlAndToken();
        $mail = Mail::sent(GenericStaffPasswordSetupMail::class)->first();
        $body = view('email.genericStaffPasswordSetup', [
            'staffName' => $mail->staffName,
            'setupUrl' => $mail->setupUrl,
        ])->render();

        $this->assertStringContainsString(route('password.reset', ['token' => $token, 'email' => $this->staff->email]), $url);
        $this->assertStringContainsString('Choose your password', $body);
        $this->assertStringNotContainsString($this->temporaryPassword, $body);
        $this->assertStringNotContainsString($this->staff->password, $body);
        $this->assertTrue(Password::broker('users')->tokenExists($this->staff, $token));

        $tokenRow = DB::table('password_resets')->where('email', $this->staff->email)->first();
        $this->assertNotSame($token, $tokenRow->token, 'The stored reset token is hashed by Laravel.');

        $adminPage = $this->actingAs($this->admin)->get(route('admin.rbac.staff.account-access', $this->staff->id))->assertOk()->getContent();
        $this->assertStringNotContainsString($token, $adminPage);
        $this->assertStringNotContainsString($this->staff->password, $adminPage);
    }

    public function test_password_confirmation_is_required_and_human_validation_keeps_token_usable(): void
    {
        $token = $this->issueSetupLink();
        $response = $this->post(route('password.update'), [
            'token' => $token,
            'email' => $this->staff->email,
            'password' => 'A-New-Secure-Password-987!',
            'password_confirmation' => 'A-different-password-987!',
        ])->assertSessionHasErrors('password');

        $messages = implode(' ', session('errors')->all());
        $this->assertStringContainsString('confirmation', strtolower($messages));
        $this->assertStringNotContainsString('password_confirmation', $messages);
        $this->assertTrue(Password::broker('users')->tokenExists($this->staff, $token));
        $this->assertTrue(Hash::check($this->temporaryPassword, $this->staff->fresh()->password));

        $setupPage = $this->get(route('password.reset', ['token' => $token, 'email' => $this->staff->email]))->assertOk()
            ->assertSee('Set up your staff password')
            ->assertSee('New password *')
            ->assertSee('Confirm new password *')
            ->assertDontSee($this->temporaryPassword)
            ->assertDontSee($this->staff->password);
        $this->assertStringNotContainsString('password_resets', $setupPage->getContent());
    }

    public function test_successful_setup_hashes_password_consumes_token_logs_in_and_routes_generic_staff(): void
    {
        $this->assignRegistrarRoleWithThreeAdmissionsPermissions();
        $token = $this->issueSetupLink();
        $newPassword = 'Registrar-Setup-Password-987!';

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $this->staff->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('staff.dashboard'));

        $this->assertAuthenticatedAs($this->staff->fresh());
        $staff = $this->staff->fresh();
        $this->assertFalse((bool) $staff->force_password_change);
        $this->assertTrue(Hash::check($newPassword, $staff->password));
        $this->assertNotSame($newPassword, $staff->password);
        $this->assertSame(0, DB::table('password_resets')->where('email', $staff->email)->count());
        $this->assertFalse(Password::broker('users')->tokenExists($staff, $token));

        $dashboard = $this->get(route('staff.dashboard'))->assertOk()->assertSee('Staff Workspace');
        $dashboard->assertSee('View applications');
        $effective = app(PermissionService::class)->effectivePermissions($staff);
        $this->assertEqualsCanonicalizing(['admissions.decide', 'admissions.review', 'admissions.view'], $effective);
        $this->assertTrue(app(PermissionService::class)->allows($staff, 'admissions.view'));
        $this->assertFalse(app(PermissionService::class)->allows($staff, 'finance.view'));
        $this->assertNotSame(200, $this->get(route('admin.fee_manager.list'))->getStatusCode());
        $this->assertNotSame(200, $this->get(route('admin.rbac.roles.index'))->getStatusCode());

        Auth::logout();
        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $staff->email,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertSessionHasErrors('email');

        $this->post('/login', ['email' => $staff->email, 'password' => $newPassword])
            ->assertRedirect(route('staff.dashboard'));
        $this->assertAuthenticatedAs($staff);
        Auth::logout();
        $page = $this->actingAs($this->admin)->withSession(['setup_link_sent' => true])
            ->get(route('admin.rbac.staff.account-access', $staff->id))->assertOk()
            ->assertViewHas('setupState', 'completed')->assertSee('Completed')
            ->assertDontSee('Setup required')->assertDontSee('Resend Password Setup Link')
            ->assertDontSee('Send Password Setup Link')->assertDontSee($newPassword)
            ->assertDontSee($token)->assertDontSee($staff->password);
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        Mail::fake();
        $this->post(route('admin.rbac.staff.account-access.send', $staff->id))->assertRedirect();
        Mail::assertNothingOutgoing();
        $this->assertSame(0, DB::table('password_resets')->where('email', $staff->email)->count());
    }

    public function test_initial_and_pending_states_follow_server_state_across_page_loads(): void
    {
        $url = route('admin.rbac.staff.account-access', $this->staff->id);
        $this->actingAs($this->admin)->withSession(['setup_link_sent' => true])->get($url)
            ->assertOk()->assertViewHas('setupState', 'required')->assertSee('Enabled')
            ->assertSee('Setup required')->assertSee('Send Password Setup Link')
            ->assertDontSee('Resend Password Setup Link');
        $token = $this->issueSetupLink();
        $this->flushSession();
        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertViewHas('setupState', 'pending')->assertSee('Pending setup')
            ->assertSee('Resend Password Setup Link')->assertDontSee($token)
            ->assertDontSee($this->temporaryPassword)->assertDontSee($this->staff->password);
        $this->get($url)->assertViewHas('setupState', 'pending');
        $this->assertSame(60, config('auth.passwords.users.expire'));
        DB::table('password_resets')->where('email', $this->staff->email)
            ->update(['created_at' => now()->subMinutes(61)]);
        $this->get($url)->assertViewHas('setupState', 'required')->assertSee('Setup required');
        $this->assertFalse(Password::broker('users')->tokenExists($this->staff, $token));
    }

    public function test_zero_permission_generic_staff_can_reach_workspace_but_not_privileged_routes(): void
    {
        $staff = $this->makeGenericStaff($this->school, 'zero.permission@school.test', [
            'force_password_change' => false,
            'password' => Hash::make('ZeroPermission-Pass-987!'),
        ]);

        $this->post('/login', ['email' => $staff->email, 'password' => 'ZeroPermission-Pass-987!'])
            ->assertRedirect(route('staff.dashboard'));
        $this->get(route('staff.dashboard'))->assertOk()
            ->assertSee('No additional application access has been assigned.')
            ->assertDontSee('View applications');
        $this->assertNotSame(200, $this->get(route('admin.hei_admissions.index'))->getStatusCode());
        $this->assertNotSame(200, $this->get(route('admin.fee_manager.list'))->getStatusCode());
    }

    public function test_pending_generic_staff_cannot_use_temporary_password_or_loop_at_workspace(): void
    {
        $this->post('/login', ['email' => $this->staff->email, 'password' => $this->temporaryPassword])
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
        $this->assertGuest();

        $this->actingAs($this->staff)->get(route('staff.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_expired_or_invalid_setup_token_is_rejected_without_changing_password(): void
    {
        $token = $this->issueSetupLink();
        DB::table('password_resets')->where('email', $this->staff->email)->update(['created_at' => now()->subHours(2)]);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $this->staff->email,
            'password' => 'Expired-Setup-Password-987!',
            'password_confirmation' => 'Expired-Setup-Password-987!',
        ])->assertSessionHasErrors('email');

        $messages = implode(' ', session('errors')->all());
        $this->assertStringContainsString('password reset token', strtolower($messages));
        $this->assertStringNotContainsString('token repository', strtolower($messages));
        $this->assertTrue(Hash::check($this->temporaryPassword, $this->staff->fresh()->password));
        $this->assertTrue((bool) $this->staff->fresh()->force_password_change);
    }

    public function test_failed_smtp_delivery_shows_safe_message_and_removes_unusable_token(): void
    {
        Mail::shouldReceive('to')->once()->with($this->staff->email)
            ->andThrow(new MimeLogicException('An email must have a "From" or a "Sender" header.'));
        Log::shouldReceive('error')->once()->with('Mail delivery failed; the completed action was kept', Mockery::on(function (array $context): bool {
            $serialized = json_encode($context);

            return !str_contains($serialized, $this->temporaryPassword)
                && !str_contains($serialized, $this->staff->password)
                && !str_contains($serialized, 'An email must have a');
        }));

        $this->actingAs($this->admin)->get(route('admin.rbac.staff.account-access', $this->staff->id))->assertOk();
        $response = $this->post(route('admin.rbac.staff.account-access.send', $this->staff->id))
            ->assertRedirect(route('admin.rbac.staff.account-access', $this->staff->id))
            ->assertSessionHas('error');

        $message = (string) session('error');
        $this->assertStringContainsString("couldn't send the password setup email", strtolower($message));
        $this->assertStringNotContainsString('An email must have', $message);
        $this->assertStringNotContainsString($this->temporaryPassword, $message);
        $this->assertSame(0, DB::table('password_resets')->where('email', $this->staff->email)->count());
        $this->assertTrue(Hash::check($this->temporaryPassword, $this->staff->fresh()->password));
        $this->assertTrue((bool) $this->staff->fresh()->force_password_change);
        $this->assertStringNotContainsString($this->temporaryPassword, $response->getContent());
    }

    public function test_missing_sender_configuration_is_caught_by_real_mailer_without_network_delivery(): void
    {
        $this->assignRegistrarRoleWithThreeAdmissionsPermissions();
        DB::table('user_permissions')->insert([
            'school_id' => $this->school,
            'user_id' => $this->staff->id,
            'permission' => 'admissions.view',
            'granted_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rolesBefore = DB::table('user_staff_roles')->where('user_id', $this->staff->id)->pluck('staff_role_id')->all();
        $permissionsBefore = DB::table('user_permissions')->where('user_id', $this->staff->id)->pluck('permission')->all();
        $passwordBefore = $this->staff->password;
        $forceSetupBefore = (bool) $this->staff->force_password_change;

        // Reconstitute Laravel's real mail manager after setUp's Mail::fake().
        // Symfony validates the message before opening the SMTP transport, so
        // this reproduces the logged missing-From LogicException without network IO.
        $this->app->forgetInstance('mail.manager');
        Mail::swap(new MailManager($this->app));
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 2525,
            'mail.from.address' => '',
            'mail.from.name' => '',
        ]);

        $this->assertFalse(\App\Support\Mail\SafeMail::send(
            $this->staff->email,
            new GenericStaffPasswordSetupMail($this->staff->name, 'https://localhost/setup/opaque'),
            'generic-staff-password-setup'
        ));

        $this->assertSame($passwordBefore, $this->staff->fresh()->password);
        $this->assertSame($forceSetupBefore, (bool) $this->staff->fresh()->force_password_change);
        $this->assertSame(20, (int) $this->staff->fresh()->role_id);
        $this->assertSame('active', $this->staff->fresh()->account_status);
        $this->assertSame($rolesBefore, DB::table('user_staff_roles')->where('user_id', $this->staff->id)->pluck('staff_role_id')->all());
        $this->assertSame($permissionsBefore, DB::table('user_permissions')->where('user_id', $this->staff->id)->pluck('permission')->all());
    }

    public function test_setup_link_can_be_resent_after_broker_throttle_window(): void
    {
        $firstToken = $this->issueSetupLink();
        DB::table('password_resets')->where('email', $this->staff->email)->update(['created_at' => now()->subMinutes(2)]);
        Mail::fake();

        $this->actingAs($this->admin)->post(route('admin.rbac.staff.account-access.send', $this->staff->id))
            ->assertRedirect(route('admin.rbac.staff.account-access', $this->staff->id))
            ->assertSessionHas('message');
        [$url, $secondToken] = $this->setupUrlAndToken();

        $this->assertNotSame($firstToken, $secondToken);
        $this->assertStringContainsString($secondToken, $url);
        $this->assertFalse(Password::broker('users')->tokenExists($this->staff, $firstToken));
        $this->assertTrue(Password::broker('users')->tokenExists($this->staff, $secondToken));
    }

    private function assignRegistrarRoleWithThreeAdmissionsPermissions(): void
    {
        $roleId = DB::table('staff_roles')->insertGetId([
            'school_id' => $this->school,
            'name' => 'Registrar',
            'description' => 'Admissions registrar access',
            'is_active' => true,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['admissions.view', 'admissions.review', 'admissions.decide'] as $permission) {
            DB::table('staff_role_permissions')->insert([
                'staff_role_id' => $roleId,
                'permission' => $permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('user_staff_roles')->insert([
            'school_id' => $this->school,
            'user_id' => $this->staff->id,
            'staff_role_id' => $roleId,
            'assigned_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
