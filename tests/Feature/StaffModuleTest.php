<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\TeacherMiddleware;
use App\Mail\NewUserEmail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class StaffModuleTest extends TestCase
{
    use StaffModuleTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'email'      => 'new.teacher@example.com',
            'gender'     => 'Male',
            'blood_group'=> 'o+',
            'birthday'   => '01/01/1990',
            'phone'      => '0700000001',
            'address'    => '1 Test Street',
            'first_name' => 'Ada',
            'last_name'  => 'Lovelace',
        ], $overrides);
    }

    // ── Staff data capture ──────────────────────────────────────────────────

    public function test_staff_creation_captures_all_new_fields_with_auto_generated_staff_number(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->enableSmtpSettings();
        $admin = $this->makeAdminUser($schoolId);
        $departmentId = $this->makeDepartment($schoolId, 'ICT');
        $designationId = $this->makeDesignation($schoolId, 'Lecturer');

        $this->actingAs($admin)->post(route('admin.teacher.create'), $this->basePayload([
            'department_id'   => $departmentId,
            'designation_id'  => $designationId,
            'employment_type' => 'Full Time',
        ]));

        $teacher = User::where('email', 'new.teacher@example.com')->firstOrFail();

        $this->assertSame('Ada', $teacher->first_name);
        $this->assertSame('Lovelace', $teacher->last_name);
        $this->assertSame('Ada Lovelace', $teacher->name, 'name must stay in sync with first+last, for the hundreds of existing ->name reads.');
        $this->assertSame($departmentId, $teacher->department_id);
        $this->assertSame($designationId, $teacher->designation_id);
        $this->assertSame('Full Time', $teacher->employment_type);
        $this->assertSame('active', $teacher->staff_status);
        $this->assertNotEmpty($teacher->code, 'Staff Number must be auto-generated.');
        $this->assertSame(now()->format('Y/m').'/TRS/001/', $teacher->code);
        $this->assertSame($schoolId, $teacher->school_id);
    }

    public function test_staff_number_is_unique_across_many_creations(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        for ($i = 0; $i < 15; $i++) {
            $this->actingAs($admin)->post(route('admin.teacher.create'), $this->basePayload([
                'email' => "teacher{$i}@example.com",
            ]));
        }

        $codes = User::where('role_id', 3)->pluck('code');
        $this->assertCount(15, $codes);
        $this->assertCount(15, $codes->unique(), 'Every generated staff number must be unique.');
    }

    public function test_staff_number_is_read_only_and_unaffected_by_update(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $this->actingAs($admin)->post(route('admin.teacher.create'), $this->basePayload());
        $teacher = User::where('email', 'new.teacher@example.com')->firstOrFail();
        $originalCode = $teacher->code;

        $this->actingAs($admin)->post(route('admin.teacher.update', $teacher->id), $this->basePayload([
            'email' => 'new.teacher@example.com',
            'code'  => 'STF-HACKED-0000-0000', // even if a malicious client injects it
        ]));

        $teacher->refresh();
        $this->assertSame($originalCode, $teacher->code, 'Staff Number must never change via the update form.');
    }

    // ── Password: auto-generate vs administrator-chosen ─────────────────────

    public function test_auto_generated_password_forces_change_and_emails_temporary_password(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->enableSmtpSettings();
        $admin = $this->makeAdminUser($schoolId);

        $this->actingAs($admin)->post(route('admin.teacher.create'), $this->basePayload([
            'password_mode' => 'auto',
        ]));

        $teacher = User::where('email', 'new.teacher@example.com')->firstOrFail();
        $this->assertTrue((bool) $teacher->force_password_change);

        Mail::assertSent(NewUserEmail::class, function ($mail) use ($teacher) {
            return $mail->hasTo($teacher->email) && !empty($mail->data['password']);
        });
    }

    public function test_administrator_chosen_password_does_not_force_change_and_is_hashed(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $this->actingAs($admin)->post(route('admin.teacher.create'), $this->basePayload([
            'password_mode' => 'manual',
            'password'      => 'MyChosenSecurePass1',
        ]));

        $teacher = User::where('email', 'new.teacher@example.com')->firstOrFail();
        $this->assertFalse((bool) $teacher->force_password_change);
        $this->assertNotSame('MyChosenSecurePass1', $teacher->password, 'Password must never be stored in plaintext.');
        $this->assertTrue(Hash::check('MyChosenSecurePass1', $teacher->password));
    }

    // ── View / Edit / Delete / Profile ───────────────────────────────────────

    public function test_admin_can_view_edit_and_delete_their_own_schools_teacher(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);
        $this->actingAs($admin)->post(route('admin.teacher.create'), $this->basePayload());
        $teacher = User::where('email', 'new.teacher@example.com')->firstOrFail();

        $profile = $this->actingAs($admin)->get(route('admin.teacher.teacher_profile', $teacher->id));
        $profile->assertStatus(200);
        $profile->assertSee('Ada Lovelace');

        $edit = $this->actingAs($admin)->get(route('admin.teacher_edit_modal', $teacher->id), ['X-Requested-With' => 'XMLHttpRequest']);
        $edit->assertStatus(200);

        $this->actingAs($admin)->post(route('admin.teacher.update', $teacher->id), $this->basePayload([
            'email'      => 'new.teacher@example.com',
            'first_name' => 'Grace',
            'last_name'  => 'Hopper',
            'staff_status' => 'suspended',
        ]));
        $teacher->refresh();
        $this->assertSame('Grace Hopper', $teacher->name);
        $this->assertSame('suspended', $teacher->staff_status);

        $this->actingAs($admin)->get(route('admin.teacher.delete', $teacher->id));
        $this->assertNull(User::find($teacher->id));
    }

    // ── Reset Password / Resend Activation ───────────────────────────────────

    public function test_reset_password_issues_new_hashed_password_emails_it_and_logs_without_plaintext(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->enableSmtpSettings();
        $admin = $this->makeAdminUser($schoolId);
        $this->actingAs($admin)->post(route('admin.teacher.create'), $this->basePayload());
        $teacher = User::where('email', 'new.teacher@example.com')->firstOrFail();
        $originalHash = $teacher->password;

        $this->actingAs($admin)->get(route('admin.teacher.reset_password', $teacher->id));

        $teacher->refresh();
        $this->assertNotSame($originalHash, $teacher->password);

        $sentPassword = null;
        Mail::assertSent(NewUserEmail::class, function ($mail) use (&$sentPassword) {
            $sentPassword = $mail->data['password'];
            return true;
        });
        $this->assertTrue(Hash::check($sentPassword, $teacher->password));

        $log = AuditLog::where('description', 'like', '%Reset portal password%')->firstOrFail();
        $this->assertStringNotContainsString($sentPassword, $log->description);
        $this->assertStringNotContainsString($sentPassword, json_encode($log->old_values));
        $this->assertStringNotContainsString($sentPassword, json_encode($log->new_values));
        $this->assertStringNotContainsString($originalHash, json_encode($log->old_values));
    }

    public function test_resend_activation_forces_password_change_and_emails_new_temporary_password(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $this->enableSmtpSettings();
        $admin = $this->makeAdminUser($schoolId);
        $this->actingAs($admin)->post(route('admin.teacher.create'), $this->basePayload([
            'password_mode' => 'manual', 'password' => 'InitialPass1',
        ]));
        $teacher = User::where('email', 'new.teacher@example.com')->firstOrFail();
        $this->assertFalse((bool) $teacher->force_password_change);

        $this->actingAs($admin)->get(route('admin.teacher.resend_activation', $teacher->id));

        $teacher->refresh();
        $this->assertTrue((bool) $teacher->force_password_change);
        Mail::assertSent(NewUserEmail::class, 2); // initial create + resend
    }

    // ── Export / Print ────────────────────────────────────────────────────

    public function test_profile_pdf_export_is_a_genuine_pdf_and_school_scoped(): void
    {
        Mail::fake();
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();
        $adminA = $this->makeAdminUser($schoolA);
        $this->actingAs($adminA)->post(route('admin.teacher.create'), $this->basePayload());
        $teacherA = User::where('email', 'new.teacher@example.com')->firstOrFail();

        $response = $this->actingAs($adminA)->get(route('admin.teacher.profile_pdf', $teacherA->id));
        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));

        $teacherB = User::factory()->create(['role_id' => 3, 'school_id' => $schoolB, 'account_status' => 'active']);
        $this->actingAs($adminA)->get(route('admin.teacher.profile_pdf', $teacherB->id))->assertStatus(404);
    }

    public function test_excel_export_is_genuine_xlsx_and_only_contains_own_school_staff(): void
    {
        Mail::fake();
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();
        $adminA = $this->makeAdminUser($schoolA);
        $this->actingAs($adminA)->post(route('admin.teacher.create'), $this->basePayload(['email' => 'teacher.a@example.com']));
        User::factory()->create(['role_id' => 3, 'school_id' => $schoolB, 'email' => 'teacher.b@example.com', 'account_status' => 'active']);

        $response = $this->actingAs($adminA)->get(route('admin.teacher.export_excel'));
        $response->assertStatus(200);

        $content = $response->streamedContent();
        $this->assertSame('PK', substr($content, 0, 2), 'Export must be a genuine .xlsx (ZIP), not a renamed CSV.');

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $content);
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet()->toArray();
        unlink($tmp);

        $emails = array_column($rows, 2);
        $this->assertContains('teacher.a@example.com', $emails);
        $this->assertNotContains('teacher.b@example.com', $emails);
    }

    // ── Audit logging ────────────────────────────────────────────────────────

    public function test_create_update_delete_are_audit_logged_without_any_password_material(): void
    {
        Mail::fake();
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $this->actingAs($admin)->post(route('admin.teacher.create'), $this->basePayload([
            'password_mode' => 'manual', 'password' => 'SuperSecretPass1',
        ]));
        $teacher = User::where('email', 'new.teacher@example.com')->firstOrFail();
        $hash = $teacher->password;

        $this->actingAs($admin)->post(route('admin.teacher.update', $teacher->id), $this->basePayload([
            'email' => 'new.teacher@example.com', 'first_name' => 'Updated',
        ]));

        $this->actingAs($admin)->get(route('admin.teacher.delete', $teacher->id));

        $logs = AuditLog::where('record_type', User::class)->where('record_id', $teacher->id)->get();
        $this->assertTrue($logs->pluck('action')->contains('CREATE'));
        $this->assertTrue($logs->pluck('action')->contains('UPDATE'));
        $this->assertTrue($logs->pluck('action')->contains('DELETE'));

        foreach ($logs as $log) {
            $dump = json_encode($log->toArray());
            $this->assertStringNotContainsString('SuperSecretPass1', $dump);
            $this->assertStringNotContainsString($hash, $dump);
        }
    }

    // ── Staff status: active / suspended / inactive portal access ───────────

    public function test_suspended_staff_status_blocks_teacher_portal_access(): void
    {
        $schoolId = $this->makeSchool();
        $teacher = User::factory()->create([
            'role_id' => 3, 'school_id' => $schoolId, 'account_status' => 'active', 'staff_status' => 'suspended',
        ]);
        $this->actingAs($teacher);

        $route = (new \Illuminate\Routing\Route('GET', 'teacher/dashboard', []))->name('teacher.dashboard');
        $request = Request::create('/teacher/dashboard', 'GET');
        $request->setRouteResolver(fn () => $route);

        $response = (new TeacherMiddleware())->handle($request, fn ($req) => new \Illuminate\Http\Response('reached'));

        $this->assertNotSame('reached', $response->getContent(), 'A suspended staff member must not reach the portal.');
    }

    public function test_inactive_staff_status_blocks_admin_portal_access(): void
    {
        $schoolId = $this->makeSchool();
        $admin = User::factory()->create([
            'role_id' => 2, 'school_id' => $schoolId, 'account_status' => 'active', 'staff_status' => 'inactive',
        ]);
        $this->actingAs($admin);

        $route = (new \Illuminate\Routing\Route('GET', 'admin/dashboard', []))->name('admin.dashboard');
        $request = Request::create('/admin/dashboard', 'GET');
        $request->setRouteResolver(fn () => $route);

        $response = (new AdminMiddleware())->handle($request, fn ($req) => new \Illuminate\Http\Response('reached'));

        $this->assertNotSame('reached', $response->getContent(), 'An inactive staff member must not reach the portal.');
    }

    public function test_active_staff_status_allows_teacher_portal_access(): void
    {
        $schoolId = $this->makeSchool();
        $teacher = User::factory()->create([
            'role_id' => 3, 'school_id' => $schoolId, 'account_status' => 'active', 'staff_status' => 'active',
        ]);
        $this->actingAs($teacher);

        $route = (new \Illuminate\Routing\Route('GET', 'teacher/dashboard', []))->name('teacher.dashboard');
        $request = Request::create('/teacher/dashboard', 'GET');
        $request->setRouteResolver(fn () => $route);

        $response = (new TeacherMiddleware())->handle($request, fn ($req) => new \Illuminate\Http\Response('reached'));

        $this->assertSame('reached', $response->getContent());
    }

    public function test_null_staff_status_does_not_block_legacy_accounts(): void
    {
        $schoolId = $this->makeSchool();
        $teacher = User::factory()->create([
            'role_id' => 3, 'school_id' => $schoolId, 'account_status' => 'active', 'staff_status' => null,
        ]);
        $this->actingAs($teacher);

        $route = (new \Illuminate\Routing\Route('GET', 'teacher/dashboard', []))->name('teacher.dashboard');
        $request = Request::create('/teacher/dashboard', 'GET');
        $request->setRouteResolver(fn () => $route);

        $response = (new TeacherMiddleware())->handle($request, fn ($req) => new \Illuminate\Http\Response('reached'));

        $this->assertSame('reached', $response->getContent(), 'Pre-existing staff with no staff_status set must not be locked out.');
    }

    // ── Cross-school tenant isolation, every staff action, every role ──────

    public function test_cross_school_access_is_denied_for_every_staff_action_across_every_role(): void
    {
        $roles = [
            'teacher' => [
                'role_id' => 3, 'edit' => 'admin.teacher_edit_modal', 'update' => 'admin.teacher.update',
                'delete' => 'admin.teacher.delete', 'profile' => 'admin.teacher.teacher_profile',
                'reset' => 'admin.teacher.reset_password', 'resend' => 'admin.teacher.resend_activation',
                'pdf' => 'admin.teacher.profile_pdf',
            ],
            'accountant' => [
                'role_id' => 4, 'edit' => 'admin.accountant_edit_modal', 'update' => 'admin.accountant.update',
                'delete' => 'admin.accountant.delete', 'profile' => 'admin.accountant.accountant_profile',
                'reset' => 'admin.accountant.reset_password', 'resend' => 'admin.accountant.resend_activation',
                'pdf' => 'admin.accountant.profile_pdf',
            ],
            'librarian' => [
                'role_id' => 5, 'edit' => 'admin.librarian_edit_modal', 'update' => 'admin.librarian.update',
                'delete' => 'admin.librarian.delete', 'profile' => 'admin.librarian.librarian_profile',
                'reset' => 'admin.librarian.reset_password', 'resend' => 'admin.librarian.resend_activation',
                'pdf' => 'admin.librarian.profile_pdf',
            ],
            'warden' => [
                'role_id' => 10, 'edit' => 'admin.warden_edit_modal', 'update' => 'admin.warden.update',
                'delete' => 'admin.warden.delete', 'profile' => 'admin.warden.warden_profile',
                'reset' => 'admin.warden.reset_password', 'resend' => 'admin.warden.resend_activation',
                'pdf' => 'admin.warden.profile_pdf',
            ],
            'admin' => [
                'role_id' => 2, 'edit' => 'admin.open_edit_modal', 'update' => 'admin.update',
                'delete' => 'admin.admin.delete', 'profile' => 'admin.admin.admin_profile',
                'reset' => 'admin.admin.reset_password', 'resend' => 'admin.admin.resend_activation',
                'pdf' => 'admin.admin.profile_pdf',
            ],
        ];

        $schoolA = $this->makeSchool(['title' => 'School A']);
        $schoolB = $this->makeSchool(['title' => 'School B']);
        $adminA = $this->makeAdminUser($schoolA);

        foreach ($roles as $roleName => $r) {
            $staffInB = User::factory()->create([
                'role_id' => $r['role_id'], 'school_id' => $schoolB, 'account_status' => 'active',
                'email' => "{$roleName}.b@example.com", 'name' => 'Confidential ' . $roleName,
            ]);
            $originalEmail = $staffInB->email;

            $this->actingAs($adminA)->get(route($r['edit'], $staffInB->id))
                ->assertStatus(404, "{$roleName}: edit modal must 404 across schools");

            $this->actingAs($adminA)->get(route($r['profile'], $staffInB->id))
                ->assertStatus(404, "{$roleName}: profile must 404 across schools");

            $this->actingAs($adminA)->get(route($r['reset'], $staffInB->id))
                ->assertStatus(404, "{$roleName}: reset password must 404 across schools");

            $this->actingAs($adminA)->get(route($r['resend'], $staffInB->id))
                ->assertStatus(404, "{$roleName}: resend activation must 404 across schools");

            $this->actingAs($adminA)->get(route($r['pdf'], $staffInB->id))
                ->assertStatus(404, "{$roleName}: profile PDF must 404 across schools");

            $this->actingAs($adminA)->post(route($r['update'], $staffInB->id), [
                'email' => 'hacked@example.com', 'gender' => 'Male', 'blood_group' => 'o+',
                'birthday' => '01/01/1990', 'phone' => '0', 'address' => 'x',
                'first_name' => 'Hacked', 'last_name' => 'Name',
            ])->assertStatus(404, "{$roleName}: update must 404 across schools");

            $staffInB->refresh();
            $this->assertSame($originalEmail, $staffInB->email, "{$roleName}: update must not have modified the other school's record");

            $this->actingAs($adminA)->get(route($r['delete'], $staffInB->id))
                ->assertStatus(404, "{$roleName}: delete must 404 across schools");

            $this->assertNotNull(User::find($staffInB->id), "{$roleName}: record must still exist after a denied delete attempt");
        }
    }

    public function test_admin_can_still_manage_their_own_schools_staff_of_every_role(): void
    {
        $schoolId = $this->makeSchool();
        $admin = $this->makeAdminUser($schoolId);

        $roles = [3 => 'teacher', 4 => 'accountant', 5 => 'librarian', 10 => 'warden', 2 => 'admin'];

        foreach ($roles as $roleId => $roleName) {
            $staff = User::factory()->create(['role_id' => $roleId, 'school_id' => $schoolId, 'account_status' => 'active']);
            $delete = $roleName === 'admin' ? 'admin.admin.delete' : "admin.{$roleName}.delete";
            $response = $this->actingAs($admin)->get(route($delete, $staff->id));
            $response->assertStatus(302); // redirect on success, not 404
            $this->assertNull(User::find($staff->id), "{$roleName}: admin must still be able to delete their own school's staff");
        }
    }
}
