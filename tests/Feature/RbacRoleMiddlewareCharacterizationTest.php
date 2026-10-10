<?php

namespace Tests\Feature;

use App\Http\Middleware;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * RBAC Phase 1 — CHARACTERIZATION, not specification.
 *
 * Pins, for every role middleware class, exactly which role_id values pass
 * today and whether account_status / staff_status blocks them — including
 * the inconsistencies found in the Phase 0 audit (AdminMiddleware admitting
 * roles 3/4/5/9–19, RegistrarMiddleware on 9 vs MultiStaffMiddleware's
 * "10 = Registrar" comment, etc.). None of it is corrected here.
 *
 * Uses unsaved User models and calls each middleware directly — no
 * HTTP actions. Approved in-memory subscription fixtures exercise the real
 * subscription guard alongside the existing role rules.
 */
class RbacRoleMiddlewareCharacterizationTest extends TestCase
{
    use \Tests\Feature\Support\ActiveSchoolSubscriptionFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grantActiveFixtureSubscription(1);
    }
    /**
     * middleware class => [role_ids that pass, checks staff_status?, checks account_status?]
     */
    private const MATRIX = [
        Middleware\SuperAdminMiddleware::class   => [[1], false, false],
        Middleware\AdminMiddleware::class        => [[2, 3, 4, 5, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19], true, true],
        Middleware\MultiStaffMiddleware::class   => [[2, 3, 4, 5, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19], true, true],
        Middleware\TeacherMiddleware::class      => [[3], true, true],
        Middleware\AccountantMiddleware::class   => [[4], true, true],
        Middleware\BursarMiddleware::class       => [[4], true, true],
        Middleware\LibrarianMiddleware::class    => [[5], true, true],
        Middleware\ParentMiddleware::class       => [[6], false, true],
        Middleware\StudentMiddleware::class      => [[7], false, true],
        Middleware\WardenMiddleware::class       => [[10], true, true],
        Middleware\RegistrarMiddleware::class    => [[9], false, true],
        Middleware\HodMiddleware::class          => [[11], false, true],
        Middleware\AdmissionsMiddleware::class   => [[12], false, true],
        Middleware\DirectorMiddleware::class     => [[2, 14], false, true],
        Middleware\HrManagerMiddleware::class    => [[2, 15], false, true],
        Middleware\ProcurementMiddleware::class  => [[2, 16], false, true],
        Middleware\StoreKeeperMiddleware::class  => [[2, 17], false, true],
        Middleware\ReceptionistMiddleware::class => [[2, 18], false, true],
        Middleware\ExaminationsMiddleware::class => [[2, 19], false, true],
    ];

    private function passes(string $middlewareClass, User $user): bool
    {
        $user->school_id=1;
        $this->actingAs($user);
        $response = (new $middlewareClass())->handle(Request::create('/probe'), fn () => new Response('reached'));

        return $response->getContent() === 'reached';
    }

    public function test_each_role_middleware_admits_exactly_its_current_role_ids(): void
    {
        foreach (self::MATRIX as $class => [$allowed]) {
            foreach (range(1, 20) as $roleId) {
                $user = User::factory()->make(['role_id' => $roleId, 'account_status' => 'active']);
                $this->assertSame(
                    in_array($roleId, $allowed, true),
                    $this->passes($class, $user),
                    class_basename($class) . " with role {$roleId}"
                );
            }
        }
    }

    public function test_account_status_disable_blocks_every_role_middleware_except_super_admin(): void
    {
        foreach (self::MATRIX as $class => [$allowed, , $checksAccountStatus]) {
            $user = User::factory()->make(['role_id' => $allowed[count($allowed) - 1], 'account_status' => 'disable']);
            $this->assertSame(!$checksAccountStatus, $this->passes($class, $user), class_basename($class));
        }
    }

    public function test_suspended_or_inactive_staff_status_only_blocks_some_middleware(): void
    {
        // KNOWN INCONSISTENCY: only the older portal middleware honours
        // staff_status; the HEI-era role middleware (incl. the active
        // hr_manager) and Parent/Student/SuperAdmin ignore it.
        foreach (['suspended', 'inactive'] as $staffStatus) {
            foreach (self::MATRIX as $class => [$allowed, $checksStaffStatus]) {
                $user = User::factory()->make([
                    'role_id' => $allowed[count($allowed) - 1],
                    'account_status' => 'active',
                    'staff_status' => $staffStatus,
                ]);
                $this->assertSame(!$checksStaffStatus, $this->passes($class, $user), class_basename($class) . " / {$staffStatus}");
            }
        }
    }

    public function test_wrong_role_is_sent_to_its_own_home_and_a_disabled_one_to_its_disabled_page(): void
    {
        $student = User::factory()->make(['role_id' => 7, 'account_status' => 'active']);
        $this->actingAs($student);
        $response = (new Middleware\TeacherMiddleware())->handle(Request::create('/probe'), fn () => new Response('reached'));
        $this->assertSame(route('student.dashboard'), $response->headers->get('Location'));

        $warden = User::factory()->make(['role_id' => 10, 'account_status' => 'disable']);
        $this->actingAs($warden);
        $response = (new Middleware\WardenMiddleware())->handle(Request::create('/probe'), fn () => new Response('reached'));
        $this->assertSame(route('warden.account_disable'), $response->headers->get('Location'));
    }

    /** Route-middleware aliases that at least one registered route actually uses. */
    private function attachedAliases(): array
    {
        $aliases = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->middleware() as $middleware) {
                $aliases[explode(':', (string) $middleware)[0]] = true;
            }
        }

        return array_keys($aliases);
    }

    public function test_active_role_middleware_aliases_are_attached_to_routes(): void
    {
        $attached = $this->attachedAliases();

        foreach (['superAdmin', 'admin', 'admin_permission', 'role_id', 'teacher', 'accountant', 'bursar',
                  'librarian', 'parent', 'student', 'warden', 'hr_manager', 'staff', 'applicant'] as $alias) {
            $this->assertContains($alias, $attached, "{$alias} is load-bearing today");
        }
    }

    public function test_dead_role_middleware_aliases_are_registered_but_attached_to_no_route(): void
    {
        $attached = $this->attachedAliases();
        $registered = app(HttpKernel::class)->getRouteMiddleware();

        foreach (['registrar', 'hod', 'admissions_staff', 'director', 'procurement',
                  'store_keeper', 'receptionist', 'examinations', 'alumni'] as $alias) {
            $this->assertArrayHasKey($alias, $registered, "{$alias} stays registered");
            $this->assertNotContains($alias, $attached, "{$alias} is currently dead");
        }

        // KNOWN LEGACY: the 'alumni' alias points at a class that does not exist.
        $this->assertFalse(class_exists($registered['alumni']));
    }

    /**
     * Phase 0 finding 2 — CLOSED in RBAC Phase 2A. AdminMiddleware still
     * admits non-admin staff roles such as Teacher (3), Accountant (4),
     * Librarian (5) and Warden (10) (unchanged, shared admin/* pages depend
     * on it), but these privileged admin-management routes now also carry
     * the 'school_admin' guard. See PrivilegedAdminRoutesSecurityTest.
     */
    public function test_privileged_admin_management_routes_carry_the_school_admin_guard_on_top_of_admin_middleware(): void
    {
        $expected = [
            'admin.create' => 'school_admin',
            'admin.admin.menu_permission' => 'school_admin:primary',
            'admin.admin.menu_permission_update' => 'school_admin:primary',
            'admin.admin.reset_password' => 'school_admin',
            'admin.settings.permissions.save' => 'school_admin',
            // Phase 2B — staff & administrator account administration.
            'admin.open_modal' => 'school_admin',
            'admin.open_edit_modal' => 'school_admin',
            'admin.update' => 'school_admin',
            'admin.admin.delete' => 'school_admin',
            'admin.admin.resend_activation' => 'school_admin',
            'admin.teacher.create' => 'school_admin:hr',
            'admin.teacher.update' => 'school_admin:hr',
            'admin.teacher.delete' => 'school_admin:hr',
            'admin.teacher.reset_password' => 'school_admin',
            'admin.teacher.resend_activation' => 'school_admin',
            'admin.warden.update' => 'school_admin:hr',
            'admin.warden.reset_password' => 'school_admin',
        ];

        // Setting another user's password no longer accepts GET.
        $this->assertSame(['POST'], Route::getRoutes()->getByName('admin.user_password')->methods());

        foreach ($expected as $routeName => $guard) {
            $middleware = Route::getRoutes()->getByName($routeName)->middleware();
            $this->assertContains('admin', $middleware, $routeName);
            $this->assertContains($guard, $middleware, $routeName);
            $this->assertNotContains('admin_permission', $middleware, $routeName);
        }

        foreach ([3, 4, 5, 10] as $roleId) {
            $user = User::factory()->make(['role_id' => $roleId, 'account_status' => 'active']);
            $this->assertTrue($this->passes(Middleware\AdminMiddleware::class, $user), "role {$roleId} passes AdminMiddleware");
        }
    }
}
