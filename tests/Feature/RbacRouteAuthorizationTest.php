<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\LiveClass;
use App\Models\OnlineExam;
use App\Models\User;
use App\Policies\LiveClassPolicy;
use App\Support\Permissions\OnlineExamPermissionService;
use App\Support\Permissions\PermissionAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/**
 * RBAC Phase 3A — the authorization matrix, exercised through real backend
 * routes (direct URL, not menus): base roles keep today's access, delegated
 * permissions open exactly their module, everything else is refused with 403,
 * and no permission ever reaches another school's records (404).
 *
 * "Store keeper" (role 17) is used as the no-permission base role: its base
 * role only covers assets/inventory, so every other module depends on grants.
 */
class RbacRouteAuthorizationTest extends TestCase
{
    use StaffModuleTestHelper;

    private int $schoolA;
    private int $schoolB;
    private PermissionAssignmentService $assign;
    private User $adminA;

    /** Representative module entry points (all RBAC-mapped admin routes). */
    private const MODULE_ROUTES = [
        'finance' => 'admin.fee_manager.list',
        'admissions' => 'admin.hei_admissions.index',
        'library' => 'admin.book.book_list',
        'hostel' => 'admin.hostel.hostel_list',
        'hr' => 'admin.designation_list',
        'cms' => 'admin.website.index',
        'audit' => 'admin.audit_log.index',
        'payment_settings' => 'admin.settings.payment',
        'rbac' => 'admin.settings.permissions',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        (require base_path('database/migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        foreach ([
            'database/migrations/2026_06_24_000001_create_website_management_tables.php',
            'database/migrations/2026_06_27_000002_ensure_website_management_schema_integrity.php',
            'database/migrations/2026_06_27_000003_add_page_header_and_navigation_fields_to_website_pages.php',
            'database/migrations/2026_09_23_000002_add_school_id_to_website_tables.php',
        ] as $path) {
            (require base_path($path))->up();
        }
        if (!Schema::hasTable('hostels')) {
            Schema::create('hostels', function (Blueprint $t) {
                $t->id();
                foreach (['school_id', 'name', 'type', 'address', 'warden_id', 'fee'] as $c) $t->text($c)->nullable();
                $t->timestamps();
            });
        }

        $this->schoolA = $this->makeSchool(['title' => 'School A', 'status' => 1]);
        $this->schoolB = $this->makeSchool(['title' => 'School B', 'status' => 1]);
        $this->assign = app(PermissionAssignmentService::class);
        $this->adminA = $this->user(2);
    }

    private function user(int $role, ?int $school = null, array $extra = []): User
    {
        return User::factory()->create($extra + ['role_id' => $role, 'school_id' => $school ?? $this->schoolA, 'account_status' => 'active']);
    }

    private function grant(User $user, array $permissions): User
    {
        $this->actingAs($this->adminA);
        foreach ($permissions as $permission) {
            $this->assign->grant($this->adminA, $user, $permission);
        }

        return $user->fresh();
    }

    private function foreignBook(): int
    {
        return (int) DB::table('books')->insertGetId(['name' => 'B book', 'author' => 'x', 'copies' => 1, 'school_id' => $this->schoolB, 'session_id' => 1, 'timestamp' => time()]);
    }

    private function foreignInvoice(int $studentId): int
    {
        return (int) DB::table('student_fee_managers')->insertGetId(['title' => 'B fee', 'total_amount' => 10, 'class_id' => 1, 'student_id' => $studentId,
            'payment_method' => 'offline', 'paid_amount' => 0, 'status' => 'unpaid', 'school_id' => $this->schoolB]);
    }

    private function routeStatusFixture(User $user, string $routeName, array $params = []): int
    {
        return $this->actingAs($user)->get(route($routeName, $params))->getStatusCode();
    }

    private function assertAllowed(User $user, string $module): void
    {
        $this->assertNotSame(403, $this->routeStatusFixture($user, self::MODULE_ROUTES[$module]), "role {$user->role_id} should reach {$module}");
    }

    private function assertDenied(User $user, string $module): void
    {
        $this->assertSame(403, $this->routeStatusFixture($user, self::MODULE_ROUTES[$module]), "role {$user->role_id} must not reach {$module}");
    }

    // ── Base roles ───────────────────────────────────────────────────────────

    public function test_super_admin_keeps_platform_access_and_is_still_kept_out_of_the_school_portal(): void
    {
        $super = $this->user(1, null, ['school_id' => null]);

        $this->assertTrue($super->hasPermission('finance.settings'));
        $this->assertTrue($super->hasPermission('roles.manage'));
        $response = $this->actingAs($super)->get(route('admin.fee_manager.list'));
        $this->assertTrue($response->isRedirect(), 'AdminMiddleware still redirects the Super Admin, as before');
    }

    public function test_school_admin_keeps_every_module_but_not_another_schools_records(): void
    {
        foreach (array_keys(self::MODULE_ROUTES) as $module) {
            $this->assertAllowed($this->adminA, $module);
        }

        $foreignBook = $this->foreignBook();
        $foreignHostel = DB::table('hostels')->insertGetId(['name' => 'B hostel', 'school_id' => $this->schoolB]);
        $this->assertSame(404, $this->routeStatusFixture($this->adminA, 'admin.edit.book', ['id' => $foreignBook]));
        $this->assertSame(404, $this->routeStatusFixture($this->adminA, 'admin.hostel.edit_hostel', ['id' => $foreignHostel]));
    }

    public function test_teacher_keeps_teaching_access_and_gains_no_unrelated_administration(): void
    {
        $teacher = $this->user(3);

        foreach (['admin.daily_attendance', 'admin.gradebook', 'admin.assignments.index', 'admin.noticeboard.list', 'admin.student'] as $route) {
            $this->assertNotSame(403, $this->routeStatusFixture($teacher, $route), "teacher should still reach {$route}");
        }
        foreach (array_keys(self::MODULE_ROUTES) as $module) {
            $this->assertDenied($teacher, $module);
        }
    }

    public function test_staff_without_permissions_are_denied_every_delegated_module(): void
    {
        $storeKeeper = $this->user(17);

        foreach (array_keys(self::MODULE_ROUTES) as $module) {
            $this->assertDenied($storeKeeper, $module);
        }
        // AJAX and exports are protected the same way, not only pages.
        $this->assertSame(403, $this->actingAs($storeKeeper)->getJson(route('admin.fee_manager.list'))->getStatusCode());
        $this->assertSame(403, $this->routeStatusFixture($storeKeeper, 'admin.fee_manager.export', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'selected_class' => 'all', 'selected_status' => 'all']));
        // …while their own base-role module stays open.
        $this->assertNotSame(403, $this->routeStatusFixture($storeKeeper, 'admin.assets.index'));
    }

    // ── Delegates ────────────────────────────────────────────────────────────

    public function test_teacher_with_an_exam_permission_gets_that_exam_action_but_not_finance(): void
    {
        $teacher = $this->user(3);
        $exams = app(OnlineExamPermissionService::class);
        $this->assertFalse($exams->has($teacher, 'publish_online_exams'), 'teachers cannot publish by default');

        $teacher = $this->grant($teacher, ['online_exams.publish']);

        $this->assertTrue($exams->has($teacher, 'publish_online_exams'));
        $this->assertTrue($teacher->hasPermission('online_exams.publish'));
        $this->assertDenied($teacher, 'finance');
        // Permission never crosses schools: the exam policy still checks the school first.
        $foreignExam = (new OnlineExam())->forceFill(['school_id' => $this->schoolB, 'created_by' => $teacher->id]);
        $this->assertFalse(Gate::forUser($teacher)->allows('view', $foreignExam));
    }

    public function test_teacher_with_a_live_class_permission_gets_that_action_but_not_admissions(): void
    {
        $teacher = $this->user(3);
        $otherTeachersClass = (new LiveClass())->forceFill(['school_id' => $this->schoolA, 'teacher_id' => 999, 'created_by' => 999]);
        $foreignClass = (new LiveClass())->forceFill(['school_id' => $this->schoolB, 'teacher_id' => 999, 'created_by' => 999]);
        $policy = new LiveClassPolicy();
        $this->assertFalse($policy->update($teacher, $otherTeachersClass), 'teachers only manage their own classes by default');

        $teacher = $this->grant($teacher, ['live_classes.manage_all']);

        $this->assertTrue($policy->update($teacher, $otherTeachersClass));
        $this->assertFalse($policy->update($teacher, $foreignClass), 'never another school');
        $this->assertDenied($teacher, 'admissions');
    }

    public function test_finance_delegate_gets_finance_but_not_hr_exams_settings_or_another_schools_invoices(): void
    {
        $delegate = $this->grant($this->user(17), ['finance.view', 'finance.invoices']);

        $this->assertAllowed($delegate, 'finance');
        $this->assertDenied($delegate, 'hr');
        $this->assertDenied($delegate, 'payment_settings');
        $this->assertFalse(app(OnlineExamPermissionService::class)->has($delegate, 'view_online_exams'));
        $this->assertFalse($delegate->hasPermission('online_exams.view'));

        $foreignInvoice = $this->foreignInvoice($this->user(7, $this->schoolB)->id);
        $this->assertSame(404, $this->routeStatusFixture($delegate, 'admin.edit.fee_manager', ['id' => $foreignInvoice]));
    }

    public function test_admissions_delegate_reviews_applications_but_not_payments_or_settings_or_another_school(): void
    {
        $delegate = $this->grant($this->user(17), ['admissions.view', 'admissions.review']);

        $this->assertAllowed($delegate, 'admissions');
        $this->assertDenied($delegate, 'payment_settings');
        $own = $this->makeAdmission($this->schoolA);
        $this->assertSame(403, $this->actingAs($delegate)->post(route('admin.hei_admissions.payment.record', $own), ['amount' => 1])->getStatusCode(), 'reviewing does not include application payments');

        $foreign = $this->makeAdmission($this->schoolB);
        $this->assertContains($this->routeStatusFixture($delegate, 'admin.hei_admissions.review', ['id' => $foreign]), [403, 404], 'another school\'s application');
    }

    public function test_library_delegate_gets_the_library_but_not_finance(): void
    {
        $delegate = $this->grant($this->user(17), ['library.view', 'library.manage_books']);

        $this->assertAllowed($delegate, 'library');
        $this->assertDenied($delegate, 'finance');
        $foreignBook = $this->foreignBook();
        $this->assertSame(404, $this->routeStatusFixture($delegate, 'admin.edit.book', ['id' => $foreignBook]));
    }

    public function test_hostel_delegate_gets_the_hostel_but_not_another_schools_hostel(): void
    {
        $delegate = $this->grant($this->user(17), ['hostel.view', 'hostel.manage']);

        $this->assertAllowed($delegate, 'hostel');
        $this->assertDenied($delegate, 'library');
        $foreignHostel = DB::table('hostels')->insertGetId(['name' => 'B hostel', 'school_id' => $this->schoolB]);
        $this->assertSame(404, $this->routeStatusFixture($delegate, 'admin.hostel.edit_hostel', ['id' => $foreignHostel]));
    }

    public function test_hr_manager_gets_hr_but_never_permission_administration(): void
    {
        $hr = $this->user(15);

        $this->assertAllowed($hr, 'hr');
        $this->assertDenied($hr, 'rbac');
        DB::table('global_settings')->insert(['key' => 'role_perm_3', 'value' => json_encode(['view_online_exams'])]);
        $this->actingAs($hr)->post(route('admin.settings.permissions.save'), ['perms' => [3 => ['manage_exam_settings']]]);
        $this->assertSame(json_encode(['view_online_exams']), DB::table('global_settings')->where('key', 'role_perm_3')->value('value'));
        $this->assertFalse($hr->hasPermission('permissions.assign'));
    }

    // ── Composition, revocation, tenant isolation ───────────────────────────

    public function test_combined_permissions_compose_without_leaking_into_other_modules(): void
    {
        $teacher = $this->grant($this->user(3), ['online_exams.view', 'online_exams.mark', 'live_classes.view']);

        $this->assertNotSame(403, $this->routeStatusFixture($teacher, 'admin.daily_attendance'), 'normal teacher functions remain');
        $this->assertTrue($teacher->hasPermission('online_exams.view'));
        $this->assertTrue($teacher->hasPermission('online_exams.mark'));
        $this->assertTrue($teacher->hasPermission('live_classes.view'));
        foreach (['finance', 'admissions', 'hr', 'cms'] as $module) {
            $this->assertDenied($teacher, $module);
        }
    }

    public function test_revoking_a_permission_closes_the_route_again(): void
    {
        $delegate = $this->grant($this->user(17), ['finance.view', 'library.view']);
        $this->assertAllowed($delegate, 'finance');

        $this->assign->revoke($this->adminA, $delegate, 'finance.view');

        $this->assertDenied($delegate->fresh(), 'finance');
        $this->assertAllowed($delegate->fresh(), 'library');
    }

    public function test_powerful_permissions_never_reach_another_schools_records(): void
    {
        $staff = $this->grant($this->user(17), ['students.view', 'students.edit', 'finance.view', 'finance.invoices', 'hostel.view', 'hostel.manage',
            'library.view', 'library.manage_books', 'cms.manage', 'online_exams.view', 'online_exams.edit_all']);

        $foreignStudent = $this->user(7, $this->schoolB);
        $foreignInvoice = $this->foreignInvoice($foreignStudent->id);
        $foreignHostel = DB::table('hostels')->insertGetId(['name' => 'B hostel', 'school_id' => $this->schoolB]);
        $foreignBook = $this->foreignBook();
        $foreignPage = DB::table('website_pages')->insertGetId(['school_id' => $this->schoolB, 'page_key' => 'about', 'slug' => 'about', 'title' => 'B About', 'status' => 1]);

        $this->assertSame(404, $this->routeStatusFixture($staff, 'admin.student_edit_modal', ['id' => $foreignStudent->id]), 'students');
        $this->assertSame(404, $this->routeStatusFixture($staff, 'admin.edit.fee_manager', ['id' => $foreignInvoice]), 'finance');
        $this->assertSame(404, $this->routeStatusFixture($staff, 'admin.hostel.edit_hostel', ['id' => $foreignHostel]), 'hostel');
        $this->assertSame(404, $this->routeStatusFixture($staff, 'admin.edit.book', ['id' => $foreignBook]), 'library');
        $this->assertSame(404, $this->actingAs($staff)->post(route('admin.website.page.update', $foreignPage), ['page_key' => 'about', 'title' => 'HIJACK'])->getStatusCode(), 'cms');
        $this->assertSame('B About', DB::table('website_pages')->where('id', $foreignPage)->value('title'));
        $this->assertFalse(Gate::forUser($staff)->allows('view', (new OnlineExam())->forceFill(['school_id' => $this->schoolB])), 'exams');
    }
}
