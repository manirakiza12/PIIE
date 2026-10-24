<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceRoutePermission;
use App\Models\User;
use App\Support\Permissions\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class CurriculumPermissionTest extends TestCase
{
    use StaffModuleTestHelper;

    private int $schoolA;
    private int $schoolB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        (require base_path('database/migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        $this->schoolA = $this->makeSchool(['title' => 'Curriculum A', 'status' => 1]);
        $this->schoolB = $this->makeSchool(['title' => 'Curriculum B', 'status' => 1]);
    }

    public function test_curriculum_permissions_are_registered_and_actions_only_require_view(): void
    {
        $permissions = app(PermissionService::class);
        foreach (['academic.curriculum.view', 'academic.curriculum.manage', 'academic.curriculum.approve'] as $key) {
            $this->assertTrue($permissions->exists($key));
        }
        $this->assertEqualsCanonicalizing(['academic.curriculum.manage', 'academic.curriculum.view'], $permissions->withDependencies(['academic.curriculum.manage']));
        $this->assertEqualsCanonicalizing(['academic.curriculum.approve', 'academic.curriculum.view'], $permissions->withDependencies(['academic.curriculum.approve']));
        $this->assertFalse($permissions->exists('academic.curriculum.retire'));

        $expected = [
            'admin.curricula.index' => 'academic.curriculum.view',
            'admin.curricula.show' => 'academic.curriculum.view',
            'admin.curricula.subjects.search' => 'academic.curriculum.view',
            'admin.curricula.store' => 'academic.curriculum.manage',
            'admin.curricula.destroy' => 'academic.curriculum.manage',
            'admin.curricula.memberships.update' => 'academic.curriculum.manage',
            'admin.curricula.successor' => 'academic.curriculum.manage',
            'admin.curricula.approve' => 'academic.curriculum.approve',
            'admin.curricula.retire' => 'academic.curriculum.approve',
            'admin.curricula.owner_status' => 'academic.curriculum.approve',
        ];
        foreach ($expected as $route => $permission) {
            $this->assertSame($permission, $permissions->routePermission($route), $route);
        }

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! str_starts_with($name, 'admin.curricula.')) {
                continue;
            }
            $this->assertNotNull($permissions->routePermission($name), "Unmapped Curriculum route: {$name}");
            $this->assertContains('auth', $route->gatherMiddleware(), "Curriculum route missing auth middleware: {$name}");
            $this->assertContains('admin', $route->gatherMiddleware(), "Curriculum route missing admin middleware: {$name}");
            $this->assertContains('rbac', $route->gatherMiddleware(), "Curriculum route missing RBAC middleware: {$name}");
        }
    }

    public function test_empty_legacy_menu_does_not_bypass_route_rbac_and_admin_bypass_remains(): void
    {
        $middleware = app(EnforceRoutePermission::class);
        $route = new Route(['GET'], '/curricula-test', fn () => response('reached'));
        $route->name('admin.curricula.index');
        $request = Request::create('/curricula-test');
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $this->user(3, $this->schoolA, ['menu_permission' => null]));

        try {
            $middleware->handle($request, fn () => response('reached'));
            $this->fail('Un-granted staff bypassed Curriculum view authorization.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $request->setUserResolver(fn () => $this->user(2, $this->schoolA));
        $this->assertSame('reached', $middleware->handle($request, fn () => response('reached'))->getContent());
    }

    public function test_view_manage_and_approve_are_independently_grantable_and_tenant_local(): void
    {
        $viewOnly = $this->user(3, $this->schoolA, ['menu_permission' => null]);
        $manageOnly = $this->user(3, $this->schoolA, ['menu_permission' => null]);
        $approveOnly = $this->user(11, $this->schoolA, ['menu_permission' => null]);
        $tenantBStaff = $this->user(3, $this->schoolB, ['menu_permission' => null]);

        DB::table('user_permissions')->insert(['school_id' => $this->schoolA, 'user_id' => $viewOnly->id, 'permission' => 'academic.curriculum.view']);
        DB::table('user_permissions')->insert([
            ['school_id' => $this->schoolA, 'user_id' => $manageOnly->id, 'permission' => 'academic.curriculum.manage'],
            ['school_id' => $this->schoolA, 'user_id' => $manageOnly->id, 'permission' => 'academic.curriculum.view'],
            ['school_id' => $this->schoolA, 'user_id' => $approveOnly->id, 'permission' => 'academic.curriculum.approve'],
            ['school_id' => $this->schoolA, 'user_id' => $approveOnly->id, 'permission' => 'academic.curriculum.view'],
        ]);

        $permissions = app(PermissionService::class);
        $this->assertTrue($permissions->allows($viewOnly, 'academic.curriculum.view'));
        $this->assertFalse($permissions->allows($viewOnly, 'academic.curriculum.manage'));
        $this->assertFalse($permissions->allows($viewOnly, 'academic.curriculum.approve'));

        $this->assertTrue($permissions->allows($manageOnly, 'academic.curriculum.manage'));
        $this->assertTrue($permissions->allows($manageOnly, 'academic.curriculum.view'));
        $this->assertFalse($permissions->allows($manageOnly, 'academic.curriculum.approve'));

        $this->assertTrue($permissions->allows($approveOnly, 'academic.curriculum.approve'));
        $this->assertTrue($permissions->allows($approveOnly, 'academic.curriculum.view'));
        $this->assertFalse($permissions->allows($approveOnly, 'academic.curriculum.manage'));
        $this->assertFalse($permissions->allows($tenantBStaff, 'academic.curriculum.view'));

        $middleware = app(\App\Http\Middleware\EnforceRoutePermission::class);
        $authorize = function (string $routeName, User $user) use ($middleware) {
            $route = new Route(['GET', 'POST'], '/curriculum-permission-test', fn () => response('reached'));
            $route->name($routeName);
            $request = Request::create('/curriculum-permission-test', 'POST');
            $request->setRouteResolver(fn () => $route);
            $request->setUserResolver(fn () => $user);
            try {
                return $middleware->handle($request, fn () => response('reached'))->getContent();
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                return $exception->getStatusCode();
            }
        };
        $this->assertSame(403, $authorize('admin.curricula.store', $viewOnly));
        $this->assertSame('reached', $authorize('admin.curricula.store', $manageOnly));
        $this->assertSame(403, $authorize('admin.curricula.destroy', $viewOnly));
        $this->assertSame('reached', $authorize('admin.curricula.destroy', $manageOnly));
        $this->assertSame(403, $authorize('admin.curricula.approve', $manageOnly));
        $this->assertSame('reached', $authorize('admin.curricula.approve', $approveOnly));
    }

    private function user(int $role, int $school, array $extra = []): User
    {
        return User::factory()->create($extra + ['role_id' => $role, 'school_id' => $school, 'account_status' => 'active']);
    }
}
