<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use App\Models\StaffProfile;
use App\Models\StaffQualification;
use App\Support\Permissions\PermissionAssignmentService;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Permissions\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * Staff entry points: the dashboard uses the canonical admin sidebar
 * (admin.navigation, no longer the stale layouts.app copy), the Staff menu
 * links Staff Directory / Add Staff / Roles & Permissions, and Add Staff is
 * only a launcher into the existing per-role create workflows — same guards,
 * same forms, same POST handlers.
 */
class StaffEntryPointTest extends TestCase
{
    use StaffModuleTestHelper;

    private const HR_MANAGER = 15;

    /** The existing create forms and the handlers they post to. */
    private const WORKFLOWS = [
        'admin' => ['form' => 'admin.open_modal', 'post' => 'admin.create', 'role' => 2],
        'teacher' => ['form' => 'admin.teacher.open_modal', 'post' => 'admin.teacher.create', 'role' => 3],
        'accountant' => ['form' => 'admin.accountant.open_modal', 'post' => 'admin.accountant.create', 'role' => 4],
        'librarian' => ['form' => 'admin.librarian.open_modal', 'post' => 'admin.librarian.create', 'role' => 5],
        'warden' => ['form' => 'admin.warden.create_form', 'post' => 'admin.warden.create', 'role' => 10],
    ];

    /**
     * The Create Staff form's own type key for each launcher key. The launcher
     * calls the teaching role "lecturer" in every tenant; only the displayed
     * label is tenant-specific.
     */
    private const CREATE_FORM_TYPE = [
        'admin' => 'admin',
        'teacher' => 'lecturer',
        'accountant' => 'accountant',
        'librarian' => 'librarian',
        'warden' => 'warden',
    ];

    private int $school;
    private int $otherSchool;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('school_type')->default('k12');
        });
        $this->withoutMiddleware(VerifyCsrfToken::class);
        (require base_path('database/migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        (require base_path('database/migrations/2026_09_23_000004_add_is_active_to_staff_roles.php'))->up();
        (require base_path('database/migrations/2026_09_24_000001_create_staff_professional_records_tables.php'))->up();
        Mail::fake();

        $this->school = $this->makeSchool(['title' => 'School A', 'status' => 1]);
        $this->otherSchool = $this->makeSchool(['title' => 'School B', 'status' => 1]);
        $this->admin = $this->user(2, ['school_role' => 1]);
    }

    private function user(int $role, array $extra = []): User
    {
        return User::factory()->create($extra + ['role_id' => $role, 'school_id' => $this->school, 'account_status' => 'active']);
    }

    /** The Staff submenu's link labels, in order, from a rendered admin page. */
    private function staffMenu(string $html): array
    {
        $start = strpos($html, '<span class="link_name">Staff</span>');
        $this->assertNotFalse($start, 'Staff menu rendered');
        $menu = substr($html, $start, strpos($html, '</ul>', $start) - $start);
        preg_match_all('/<li><a [^>]*><span>([^<]+)<\/span><\/a><\/li>/', $menu, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    /** Every top-level sidebar label (link_name), in order. */
    private function sidebar(string $html): array
    {
        preg_match_all('/class="link_name">([^<]+)</', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    // ── Dashboard uses the canonical sidebar ────────────────────────────────

    public function test_dashboard_and_other_admin_pages_render_the_same_canonical_sidebar(): void
    {
        $dashboard = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk();
        $other = $this->actingAs($this->admin)->get(route('admin.teacher'))->assertOk();

        $this->assertSame($this->sidebar($other->getContent()), $this->sidebar($dashboard->getContent()));
        $this->assertContains('Roles & Permissions', $this->sidebar($dashboard->getContent()));
        $dashboard->assertSee(route('admin.rbac.roles.index'), false);

        // Dashboard content and its Font Awesome icons are still there.
        $dashboard->assertSee('Total Students', false)->assertSee('font-awesome/6.5.0', false)->assertSee('fas fa-users', false);
        $this->assertStringNotContainsString("@extends('layouts.app')", file_get_contents(resource_path('views/admin/dashboard.blade.php')));
    }

    public function test_school_admin_staff_menu_has_the_new_entries_then_every_existing_one(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertSame(
            ['Staff Directory', 'Add Staff', 'Roles & Permissions'],
            $this->staffMenu($html)
        );
        foreach (['admin.rbac.staff.index', 'admin.staff.add', 'admin.rbac.roles.index', 'admin.teacher.permission'] as $route) {
            $this->assertStringContainsString('href="' . route($route) . '"', $html, $route);
        }
        foreach (['admin.admin', 'admin.teacher', 'admin.accountant', 'admin.librarian', 'admin.warden', 'admin.teacher.permission', 'admin.designation_list'] as $legacyRoute) {
            $this->assertNotNull(app('router')->getRoutes()->getByName($legacyRoute), $legacyRoute . ' route remains registered');
        }
    }

    // ── Add Staff launcher ──────────────────────────────────────────────────

    public function test_school_admin_launcher_offers_specialised_workflows_and_add_other_staff(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.staff.add'))->assertOk()->getContent();

        preg_match_all('/data-staff-type="([a-z]+)"/', $html, $types);
        $this->assertSame(array_merge(array_keys(self::WORKFLOWS), ['other']), $types[1]);
        foreach (array_keys(self::WORKFLOWS) as $key) {
            // Opens the full-page Create Staff form for that type. The narrow
            // drawer is no longer used for creation; the per-role create routes
            // themselves are untouched (see the test below).
            $this->assertStringContainsString(route('admin.staff.create', self::CREATE_FORM_TYPE[$key]), $html);
        }
        $this->assertStringContainsString('Add institutional staff whose main responsibility is not listed above.', $html);
        $this->assertStringContainsString('+ Add Other Staff', $html);
        $this->assertStringContainsString(route('admin.staff.other.create'), $html);
        $this->assertStringContainsString(route('admin.rbac.staff.index'), $html);
        $this->assertStringContainsString('Add Staff Member', $html);
        $this->assertStringContainsString("Select the staff member&#039;s main responsibility.", $html);
        $this->assertStringContainsString('Create Teacher', $html);
        $this->assertStringContainsString('Examinations Officer, Head of Department, or Programme Coordinator', $html);
    }

    public function test_other_staff_form_marks_backend_required_fields_and_identifies_optional_fields(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.staff.other.create'))->assertOk()
            ->assertSee('* Required fields');

        foreach ([
            'First Name *', 'Last Name *', 'Email *', 'Phone *', 'Gender *',
            'NIN / Identity number *', 'Designation / Job Title *', 'Employment Type *', 'Staff Status *',
        ] as $label) {
            $html->assertSee($label);
        }

        foreach (['Date of birth (Optional)', 'Address (Optional)', 'Department (Optional)'] as $label) {
            $html->assertSee($label);
            $this->assertStringNotContainsString($label . ' *', $html->getContent());
        }
    }

    public function test_higher_education_launcher_uses_tenant_lecturer_terminology_without_changing_teacher_workflow(): void
    {
        DB::table('schools')->where('id', $this->school)->update(['school_type' => 'higher_ed']);

        $html = $this->actingAs($this->admin)->get(route('admin.staff.add'))->assertOk()->getContent();

        $this->assertStringContainsString('data-staff-type="teacher"', $html);
        $this->assertStringContainsString('Lecturer', $html);
        $this->assertStringContainsString(route('admin.staff.create', 'lecturer'), $html);
        $this->assertStringNotContainsString('Create Teacher', $html);
    }

    public function test_each_launched_form_is_the_existing_form_posting_to_the_existing_handler(): void
    {
        foreach (self::WORKFLOWS as $key => $workflow) {
            $form = $this->actingAs($this->admin)->get(route($workflow['form']), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
            $this->assertStringContainsString('action="' . route($workflow['post']) . '"', $form, $key);
        }

        // The existing handler still creates the base role it always did.
        $this->actingAs($this->admin)->post(route('admin.teacher.create'), [
            'first_name' => 'Benjamin', 'last_name' => 'Okoth', 'email' => 'benjamin@a.test', 'gender' => 'Male', 'blood_group' => 'a+',
            'birthday' => '1990-01-01', 'phone' => '0700', 'address' => 'Nairobi', 'password_mode' => 'manual', 'password' => 'secret-pass',
        ])->assertRedirect();
        $benjamin = User::where('email', 'benjamin@a.test')->first();
        $this->assertNotNull($benjamin);
        $this->assertSame(3, (int) $benjamin->role_id);
        $this->assertSame($this->school, (int) $benjamin->school_id);

        // …and the new staff member appears in the Staff Directory, ready for Manage Access.
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk()->assertSee('Benjamin Okoth');
        $this->actingAs($this->admin)->get(route('admin.rbac.staff.show', $benjamin->id))->assertOk()->assertSee('Effective access');
    }

    public function test_hr_manager_gets_the_four_hr_workflows_but_never_admin_creation(): void
    {
        $hr = $this->user(self::HR_MANAGER);

        $html = $this->actingAs($hr)->get(route('admin.staff.add'))->assertOk()->getContent();
        preg_match_all('/data-staff-type="([a-z]+)"/', $html, $types);
        $this->assertSame(['teacher', 'accountant', 'librarian', 'warden'], $types[1]);
        $this->assertStringNotContainsString(route('admin.open_modal'), $html);

        // The existing guards still decide: HR may open the teacher form, never the admin form.
        $this->actingAs($hr)->get(route('admin.teacher.open_modal'), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertNotSame(200, $this->actingAs($hr)->get(route('admin.open_modal'))->getStatusCode());
        $this->assertNotSame(200, $this->actingAs($hr)->post(route('admin.create'), ['email' => 'x@a.test'])->getStatusCode());
        $this->assertFalse(User::where('email', 'x@a.test')->exists());

        // HR is not an RBAC administrator: no Staff Directory / Roles & Permissions.
        $this->assertSame(403, $this->actingAs($hr)->get(route('admin.rbac.staff.index'))->getStatusCode());
    }

    public function test_other_staff_and_delegates_cannot_reach_the_launcher_or_see_add_staff(): void
    {
        $delegate = $this->user(17);
        $this->actingAs($this->admin);
        // Even a delegated staff.create grant does not open the School Admin / HR creation workflows.
        app(PermissionAssignmentService::class)->grantMany($this->admin, $delegate, ['staff.view', 'staff.create', 'staff.edit']);

        foreach ([3, 4, 5, 10, 19] as $role) {
            $this->assertLauncherDenied($this->user($role));
        }
        $this->assertLauncherDenied($delegate->fresh());
        $this->assertLauncherDenied($this->user(7));
        $this->assertLauncherDenied($this->user(6));
        $this->assertLauncherDenied($this->user(2, ['account_status' => 'disable']));

        // After the approved sidebar consolidation, this grant does not create
        // any available Staff submenu destination: management entry points are
        // gated by users.assign_roles / roles.view, and Add Staff stays guarded.
        $html = $this->actingAs($delegate->fresh())->get(route('admin.teacher'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<span class="link_name">Staff</span>', $html);
        $this->assertStringNotContainsString(route('admin.rbac.roles.index'), $html);
    }

    public function test_other_staff_http_creation_reuses_profile_and_qualification_storage(): void
    {
        $department = $this->makeDepartment($this->school, 'Academic Registrar');
        $designation = $this->makeDesignation($this->school, 'Registrar');
        $this->actingAs($this->admin)->get(route('admin.staff.add'))->assertOk()->assertSee('Other Staff');
        $this->get(route('admin.staff.other.create'))->assertOk()
            ->assertSee('Designation / Job Title')->assertSee('Manage Designations')
            ->assertSee('Employment Type *')->assertSee('NIN / Identity number *');
        $otherForm = $this->get(route('admin.staff.other.create'))->getContent();
        $this->assertSame(1, substr_count($otherForm, 'name="employment_type"'), 'the form has exactly one employment type control');

        $response = $this->post(route('admin.staff.other.store'), [
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane.doe@school.test', 'phone' => '0700111222',
            'gender' => 'Female', 'birthday' => '1992-04-10', 'address' => 'Campus Road',
            'department_id' => $department, 'designation_id' => $designation, 'employment_type' => 'Full Time',
            'staff_status' => 'active', 'nin' => 'CM90012345ABCD',
            'qualifications' => [['qualification_level' => 'Bachelor', 'qualification_name' => 'BA Administration', 'institution' => 'PIIE University']],
        ]);

        $user = User::where('email', 'jane.doe@school.test')->firstOrFail();
        $response->assertRedirect(route('admin.rbac.staff.show', $user->id))
            ->assertSessionHas('message', 'Other staff member created. Next step: manage access.');
        $this->get(route('admin.rbac.staff.show', $user->id))->assertOk()->assertSee('Effective access')->assertSee('Other Staff');
        $this->assertStringNotContainsString('name="role_id"', $otherForm);
        $this->assertSame(1, User::where('email', 'jane.doe@school.test')->count());
        $this->assertSame(20, (int) $user->role_id);
        $this->assertSame($this->school, (int) $user->school_id);
        $this->assertNotEmpty($user->code);
        $this->assertSame($department, (int) $user->department_id);
        $this->assertSame($designation, (int) $user->designation_id);
        $this->assertSame('Full Time', $user->employment_type);
        $this->assertSame('active', $user->staff_status);
        $this->assertSame(1, StaffProfile::where('user_id', $user->id)->count());
        $profile = StaffProfile::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('CM90012345ABCD', app(\App\Support\Staff\StaffRecordService::class)->revealNin($this->admin, $user));
        $this->assertNotSame('CM90012345ABCD', $profile->nin_encrypted);
        $this->assertSame(1, StaffQualification::where('user_id', $user->id)->count());
        $qualification = StaffQualification::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Bachelor', $qualification->qualification_level);
        $this->assertSame('BA Administration', $qualification->qualification_name);
        $this->assertSame('PIIE University', $qualification->institution);
        $this->assertSame([], app(PermissionService::class)->basePermissions($user));
        $this->assertSame([], app(PermissionService::class)->grantedPermissions($user));
        $this->assertSame(1, DB::table('staff_profiles')->where('user_id', $user->id)->count(), 'one existing professional profile, no parallel profile record');
    }

    public function test_other_staff_qualification_is_optional_when_all_fields_are_blank(): void
    {
        $designation = $this->makeDesignation($this->school, 'Registrar');
        $this->actingAs($this->admin)->post(route('admin.staff.other.store'), [
            'first_name' => 'Una', 'last_name' => 'Qualified', 'email' => 'una.qualified@school.test', 'phone' => '0700111223',
            'gender' => 'Female', 'employment_type' => 'Full Time', 'staff_status' => 'active', 'nin' => 'CM90012345EMPTY',
            'designation_id' => $designation,
            'qualifications' => [['qualification_level' => '', 'qualification_name' => '', 'institution' => '']],
        ])->assertRedirect(route('admin.rbac.staff.show', User::where('email', 'una.qualified@school.test')->value('id')));

        $user = User::where('email', 'una.qualified@school.test')->firstOrFail();
        $this->assertSame(0, StaffQualification::where('user_id', $user->id)->count());
    }

    public function test_partial_other_staff_qualification_has_human_errors_and_preserves_values(): void
    {
        $department = $this->makeDepartment($this->school, 'Administration');
        $designation = $this->makeDesignation($this->school, 'Registrar');
        $this->actingAs($this->admin)->get(route('admin.staff.other.create'))->assertOk();
        $response = $this->actingAs($this->admin)->post(route('admin.staff.other.store'), [
            'first_name' => 'Pia', 'last_name' => 'Partial', 'email' => 'pia.partial@school.test', 'phone' => '0700111224',
            'gender' => 'Female', 'birthday' => '1991-02-03', 'address' => 'Admin block',
            'department_id' => $department, 'employment_type' => 'Part Time', 'staff_status' => 'active', 'nin' => 'CM90012345PART',
            'designation_id' => $designation,
            'qualifications' => [['qualification_level' => "Bachelor's Degree", 'qualification_name' => '', 'institution' => 'Makerere University']],
        ]);

        $response->assertRedirect(route('admin.staff.other.create'))
            ->assertSessionHasErrors(['qualifications.0.qualification_name']);
        $errors = session('errors');
        $this->assertSame(['Enter the qualification/award.'], $errors->get('qualifications.0.qualification_name'));
        $this->assertStringNotContainsString('qualifications.0.', implode(' ', $errors->all()));
        $this->assertFalse(User::where('email', 'pia.partial@school.test')->exists());

        $form = $this->actingAs($this->admin)->get(route('admin.staff.other.create'))->assertOk()
            ->assertSee('name="first_name" value="Pia"', false)
            ->assertSee('name="last_name" value="Partial"', false)
            ->assertSee('name="email" value="pia.partial@school.test"', false)
            ->assertSee('name="phone" value="0700111224"', false)
            ->assertSee('name="birthday" value="1991-02-03"', false)
            ->assertSee('Admin block</textarea>', false)
            ->assertSee('<option selected>Female</option>', false)
            ->assertSee('name="department_id"><option value="">Select department</option><option value="' . $department . '" selected>', false)
            ->assertSee('name="designation_id" required><option value="">Select designation</option><option value="' . $designation . '" selected>', false)
            ->assertSee('<option selected>Part Time</option>', false)
            ->assertSee('<option value="active" selected>Active</option>', false)
            ->assertSee("value=\"Bachelor&#039;s Degree\"", false)
            ->assertSee('value="Makerere University"', false)
            ->assertSee('Enter the qualification/award.');
        $form->assertSee('name="qualifications[0][qualification_name]" value=""', false);
    }

    public function test_other_staff_rejects_cross_tenant_department_and_designation(): void
    {
        $foreignDepartment = $this->makeDepartment($this->otherSchool, 'Foreign Department');
        $foreignDesignation = $this->makeDesignation($this->otherSchool, 'Foreign Job');
        $payload = [
            'first_name' => 'Test', 'last_name' => 'User', 'email' => 'cross.tenant@school.test', 'phone' => '0700',
            'gender' => 'Other', 'employment_type' => 'Full Time', 'staff_status' => 'active', 'nin' => 'CM90012345XYZ',
        ];
        $this->actingAs($this->admin)->post(route('admin.staff.other.store'), $payload + ['department_id' => $foreignDepartment, 'designation_id' => $this->makeDesignation($this->school)])->assertStatus(422);
        $this->post(route('admin.staff.other.store'), $payload + ['department_id' => $this->makeDepartment($this->school), 'designation_id' => $foreignDesignation])->assertStatus(422);
        $this->assertFalse(User::where('email', 'cross.tenant@school.test')->exists());
    }

    public function test_generic_staff_authenticates_and_blocked_employment_statuses_cannot_enter_workspace(): void
    {
        $staff = app(\App\Support\Staff\StaffProvisioningService::class)->provisionProfessional(
            $this->admin, 20,
            ['first_name' => 'Ari', 'last_name' => 'Staff', 'email' => 'ari.staff@school.test', 'phone' => '0700111222',
                'gender' => 'Other', 'birthday' => '1990-01-01', 'address' => '', 'password_mode' => 'manual', 'password' => 'StaffPass123'],
            ['nin' => 'CM90012345LOGIN']
        );

        $this->post('/login', ['email' => $staff->email, 'password' => 'StaffPass123'])
            ->assertRedirect(route('staff.dashboard'));
        $this->get(route('staff.dashboard'))->assertOk()
            ->assertSee('Staff Workspace')->assertSee('Welcome, Ari Staff')
            ->assertSee('user-title">Staff', false)
            ->assertSee('Your available work')
            ->assertSee('No additional application access has been assigned. Contact an administrator to request access.')
            ->assertDontSee('href="' . route('admin.hei_admissions.index') . '"', false)
            ->assertDontSee('href="' . route('admin.fee_manager.list') . '"', false)
            ->assertDontSee('href="' . route('admin.librarian') . '"', false)
            ->assertDontSee('href="' . route('admin.warden') . '"', false);
        $this->assertSame(1, substr_count($this->get(route('staff.dashboard'))->getContent(), '<!DOCTYPE html>'));

        foreach (['suspended', 'inactive', 'terminated'] as $status) {
            Auth::logout();
            DB::table('users')->where('id', $staff->id)->update(['staff_status' => $status]);
            $this->post('/login', ['email' => $staff->email, 'password' => 'StaffPass123'])->assertRedirect(route('login'));
            $this->assertFalse(Auth::check(), "{$status} account must be logged out after credential validation");
            $this->actingAs($staff->fresh())->get(route('staff.dashboard'))->assertRedirect(route('login'));
        }
    }

    public function test_generic_staff_real_http_grant_and_revoke_enforces_only_assigned_admissions_work(): void
    {
        $staff = $this->user(20);
        $this->actingAs($this->admin)->post(route('admin.rbac.staff.permissions.grant', $staff->id), [
            'permissions' => ['admissions.view'],
        ])->assertRedirect(route('admin.rbac.staff.show', $staff->id));

        $this->actingAs($staff->fresh())->get(route('staff.dashboard'))->assertOk()->assertSee('View applications');
        $this->get(route('admin.hei_admissions.index'))->assertOk();
        $this->assertNotSame(200, $this->get(route('admin.fee_manager.list'))->getStatusCode(), 'Finance remains denied');
        $this->assertNotSame(200, $this->get(route('admin.rbac.roles.index'))->getStatusCode(), 'RBAC administration remains denied');

        $this->actingAs($this->admin)->delete(route('admin.rbac.staff.permissions.revoke', [$staff->id, 'admissions.view']))
            ->assertRedirect(route('admin.rbac.staff.show', $staff->id));
        $this->actingAs($staff->fresh())->get(route('staff.dashboard'))->assertOk()->assertDontSee('View applications');
        $this->assertNotSame(200, $this->get(route('admin.hei_admissions.index'))->getStatusCode());
    }

    public function test_generic_staff_has_a_safe_zero_privilege_landing_and_mapped_grants_revoke_cleanly(): void
    {
        $staff = $this->user(20);
        $permissions = app(PermissionService::class);

        $this->assertSame([], $permissions->basePermissions($staff));
        $this->assertFalse($permissions->allows($staff, 'finance.view'));
        $this->assertFalse($permissions->allows($staff, 'academic.gradebook'));
        $this->assertFalse($permissions->allows($staff, 'academic.course_offering.lecturer.manage'));
        $this->assertFalse($permissions->allows($staff, 'library.view'));
        $this->assertFalse($permissions->allows($staff, 'hostel.view'));
        $this->assertFalse($permissions->allows($staff, 'admissions.view'));
        $this->actingAs($staff)->get(route('staff.dashboard'))->assertOk()->assertSee('No additional application access has been assigned.');
        $this->assertNotSame(200, $this->actingAs($staff)->get(route('admin.dashboard'))->getStatusCode());
        $this->assertNotSame(200, $this->actingAs($staff)->get(route('admin.rbac.roles.index'))->getStatusCode());
        foreach (['teacher.dashboard', 'accountant.dashboard', 'librarian.dashboard', 'warden.dashboard'] as $route) {
            if (app('router')->getRoutes()->getByName($route)) {
                $this->assertNotSame(200, $this->actingAs($staff)->get(route($route))->getStatusCode(), $route);
            }
        }

        app(PermissionAssignmentService::class)->grantMany($this->admin, $staff, ['admissions.view']);
        $this->assertTrue($permissions->allows($staff->fresh(), 'admissions.view'));
        $this->actingAs($staff->fresh())->get(route('staff.dashboard'))->assertOk()->assertSee('View applications');

        app(PermissionAssignmentService::class)->revoke($this->admin, $staff->fresh(), 'admissions.view');
        $this->assertFalse($permissions->allows($staff->fresh(), 'admissions.view'));
        $this->actingAs($staff->fresh())->get(route('staff.dashboard'))->assertOk()->assertDontSee('View applications');

        $otherTenantStaff = $this->user(20, ['school_id' => $this->otherSchool]);
        try {
            app(PermissionAssignmentService::class)->grantMany($this->admin, $otherTenantStaff, ['admissions.view']);
            $this->fail('Cross-tenant permission assignment should be refused.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->assertFalse($permissions->allows($otherTenantStaff->fresh(), 'admissions.view'));
        }

        $blocked = $this->user(20, ['staff_status' => 'suspended']);
        $this->assertNotSame(200, $this->actingAs($blocked)->get(route('staff.dashboard'))->getStatusCode());
    }

    private function assertLauncherDenied(User $user): void
    {
        $response = $this->actingAs($user)->get(route('admin.staff.add'));
        $this->assertNotSame(200, $response->getStatusCode(), "role {$user->role_id} must not open Add Staff");
        $this->assertStringNotContainsString('data-staff-type', (string) $response->getContent());
    }

    // ── Staff parent menu follows its children ──────────────────────────────

    /** Staff submenu label => href, or null when the Staff parent is not rendered. */
    private function staffLinks(User $user, string $page = 'admin.teacher'): ?array
    {
        // Rendered on an admin page the user can open, so a missing menu is really a hidden menu.
        $response = $this->actingAs($user)->get(route($page));
        $this->assertSame(200, $response->getStatusCode(), "role {$user->role_id} must be able to open {$page}");
        $html = $response->getContent();
        $this->assertStringContainsString('<span class="link_name">Dashboard</span>', $html, 'admin sidebar rendered');
        $start = strpos($html, '<span class="link_name">Staff</span>');
        if ($start === false) {
            return null;
        }
        $menu = substr($html, $start, strpos($html, '</ul>', $start) - $start);
        preg_match_all('/<li><a [^>]*href="([^"]+)"[^>]*><span>([^<]+)<\/span><\/a><\/li>/', $menu, $m);

        return array_combine(array_map('html_entity_decode', $m[2]), $m[1]);
    }

    /** Every Staff child shown to $user really opens for $user (menu never promises what the backend refuses). */
    private function assertStaffLinksAreLegitimate(User $user, array $links): void
    {
        $this->assertNotEmpty($links, "role {$user->role_id}: a rendered Staff menu is never empty");
        foreach ($links as $label => $href) {
            $this->assertSame(200, $this->actingAs($user)->get($href)->getStatusCode(), "role {$user->role_id}: '{$label}' is shown, so it must open");
        }
    }

    private function bootAssetsTable(): void
    {
        foreach (['asset_categories', 'assets'] as $table) {
            \Illuminate\Support\Facades\Schema::create($table, function ($t) use ($table) {
                $t->id();
                $t->unsignedBigInteger('school_id');
                $t->string('name');
                if ($table === 'assets') {
                    $t->unsignedBigInteger('category_id')->nullable();
                    $t->string('condition')->default('good');
                }
                $t->timestamps();
            });
        }
    }

    public function test_hr_manager_sees_staff_menu_with_add_staff_but_no_admin_creation_or_rbac_entries(): void
    {
        $this->bootAssetsTable();
        $hr = $this->user(self::HR_MANAGER);
        $links = $this->staffLinks($hr);

        $this->assertNotNull($links, 'HR Manager sees the Staff menu');
        $this->assertSame(['Add Staff' => route('admin.staff.add')], $links);
        $this->assertArrayNotHasKey('Staff Directory', $links);
        $this->assertArrayNotHasKey('Roles & Permissions', $links);
        $this->assertStaffLinksAreLegitimate($hr, $links);

        $this->actingAs($hr)->get(route('admin.staff.add'))->assertOk()->assertDontSee('data-staff-type="admin"', false);
    }

    public function test_hr_manager_with_a_restrictive_legacy_menu_list_still_gets_add_staff(): void
    {
        // Previously the whole Staff section was hidden by the legacy keys even though Add Staff works.
        $this->bootAssetsTable();
        foreach (['2022_07_04_150637_create_leavelists_table', '2026_07_02_130005_fill_leavelists_columns', '2026_07_02_130006_create_leave_types_table'] as $migration) {
            (require base_path("database/migrations/{$migration}.php"))->up();
        }
        $hr = $this->user(self::HR_MANAGER, ['menu_permission' => json_encode(['admin.leave', 'admin.leave_types'])]);

        $links = $this->staffLinks($hr, 'admin.leave.index');
        $this->assertSame(['Add Staff' => route('admin.staff.add')], $links);
        $this->assertStaffLinksAreLegitimate($hr, $links);
    }

    public function test_school_admin_staff_links_are_all_legitimate(): void
    {
        $this->bootAssetsTable();
        $links = $this->staffLinks($this->admin);

        $this->assertSame(['Staff Directory', 'Add Staff', 'Roles & Permissions'], array_keys($links));
        $this->assertStaffLinksAreLegitimate($this->admin, $links);
    }

    public function test_teacher_and_accountant_see_only_legitimate_staff_links_and_no_privileged_entries(): void
    {
        $this->bootAssetsTable();
        foreach ([3, 4] as $role) {
            $user = $this->user($role);
            $links = $this->staffLinks($user);
            if ($links === null) {
                continue;   // hidden entirely is fine
            }
            foreach (['Add Staff', 'Staff Directory', 'Roles & Permissions', 'Teacher Permission'] as $privileged) {
                $this->assertArrayNotHasKey($privileged, $links, "role {$role}");
            }
            $this->assertStaffLinksAreLegitimate($user, $links);
        }
    }

    public function test_staff_parent_is_hidden_when_no_child_is_available(): void
    {
        $this->bootAssetsTable();
        // Legacy per-user list without any Staff key, and no staff creation rights: nothing to show.
        foreach ([16, 17] as $role) {
            $user = $this->user($role, ['menu_permission' => json_encode(['admin.assets', 'admin.asset_categories'])]);
            $this->assertNull($this->staffLinks($user, 'admin.assets.index'), "role {$role}");
        }
    }

    public function test_delegated_rbac_access_drives_staff_children(): void
    {
        $this->bootAssetsTable();
        $keeper = $this->user(17);
        $this->assertArrayNotHasKey('Teacher Permission', $this->staffLinks($keeper) ?? []);

        $this->actingAs($this->admin);
        app(PermissionAssignmentService::class)->grant($this->admin, $keeper, 'staff.teacher_assignments');
        $links = $this->staffLinks($keeper->fresh());

        $this->assertArrayNotHasKey('Teacher Permission', $links ?? []);
        $this->assertArrayNotHasKey('Add Staff', $links ?? [], 'a grant never opens the School Admin / HR creation workflows');
        if ($links !== null) {
            $this->assertStaffLinksAreLegitimate($keeper->fresh(), $links);
        }

        // Direct URLs unchanged: still refused for the delegate.
        $this->assertNotSame(200, $this->actingAs($keeper->fresh())->get(route('admin.staff.add'))->getStatusCode());
        $this->assertSame(403, $this->actingAs($keeper->fresh())->get(route('admin.rbac.staff.index'))->getStatusCode());
    }

    // ── Staff Directory = RBAC Staff Access (no second list) ────────────────

    public function test_staff_directory_is_the_rbac_staff_access_screen_and_stays_tenant_scoped(): void
    {
        $departmentId = $this->makeDepartment($this->school, 'Registry');
        $designationId = $this->makeDesignation($this->school, 'Registrar');
        $anna = $this->user(3, ['name' => 'Anna Teacher', 'employment_type' => 'Full Time']);
        DB::table('users')->where('id', $anna->id)->update(['department_id' => $departmentId, 'designation_id' => $designationId]);
        User::factory()->create(['role_id' => 3, 'school_id' => $this->otherSchool, 'name' => 'Zed Foreign', 'account_status' => 'active']);
        $this->user(7, ['name' => 'Stu Student']);

        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->getContent();
        $this->assertStringContainsString('href="' . route('admin.rbac.staff.index') . '"><span>Staff Directory', $html);

        $this->actingAs($this->admin)->get(route('admin.rbac.staff.index'))->assertOk()
            ->assertSee('Anna Teacher')->assertDontSee('Zed Foreign')->assertDontSee('Stu Student')
            ->assertSee('Designation / Job Title')->assertSee('Registry')->assertSee('Registrar');
        $this->get(route('admin.rbac.staff.index', ['department_id' => $departmentId, 'designation_id' => $designationId, 'employment_type' => 'Full Time']))
            ->assertOk()->assertSee('Anna Teacher')->assertDontSee('Zed Foreign');
    }

    public function test_the_registry_route_map_is_unchanged_for_the_launcher(): void
    {
        // The launcher is guarded by the existing school_admin:hr middleware (EnforceRoutePermission defers
        // to it), so it needs no — and gets no — new RBAC permission or mapping.
        $this->assertArrayNotHasKey('admin.staff.add', PermissionRegistry::routes());
        $this->assertSame(0, DB::table('staff_roles')->count());
    }
}
