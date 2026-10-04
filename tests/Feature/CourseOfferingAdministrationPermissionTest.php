<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Permissions\PermissionService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class CourseOfferingAdministrationPermissionTest extends TestCase
{
    use StaffModuleTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        (require base_path('database/migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
    }

    public function test_offering_permissions_have_view_dependencies_and_routes_are_explicitly_mapped(): void
    {
        $permissions = app(PermissionService::class);
        foreach (['academic.course_offering.view', 'academic.course_offering.manage', 'academic.course_offering.lifecycle', 'academic.course_offering.lecturer.view', 'academic.course_offering.lecturer.manage'] as $key) {
            $this->assertTrue($permissions->exists($key));
        }
        $this->assertEqualsCanonicalizing(['academic.course_offering.manage', 'academic.course_offering.view'], $permissions->withDependencies(['academic.course_offering.manage']));
        $this->assertEqualsCanonicalizing(['academic.course_offering.lifecycle', 'academic.course_offering.view'], $permissions->withDependencies(['academic.course_offering.lifecycle']));
        $expected = [
            'admin.course_offerings.index' => 'academic.course_offering.view',
            'admin.course_offerings.create' => 'academic.course_offering.manage',
            'admin.course_offerings.show' => 'academic.course_offering.view',
            'admin.course_offerings.store' => 'academic.course_offering.manage',
            'admin.course_offerings.update' => 'academic.course_offering.manage',
            'admin.course_offerings.applicability.store' => 'academic.course_offering.manage',
            'admin.course_offerings.applicability.destroy' => 'academic.course_offering.manage',
            'admin.course_offerings.open' => 'academic.course_offering.lifecycle',
            'admin.course_offerings.start' => 'academic.course_offering.lifecycle',
            'admin.course_offerings.complete' => 'academic.course_offering.lifecycle',
            'admin.course_offerings.cancel' => 'academic.course_offering.lifecycle',
            'admin.course_offerings.lecturers.index' => 'academic.course_offering.lecturer.view',
            'admin.course_offerings.lecturers.history' => 'academic.course_offering.lecturer.view',
            'admin.course_offerings.lecturers.create' => 'academic.course_offering.lecturer.manage',
            'admin.course_offerings.lecturers.store' => 'academic.course_offering.lecturer.manage',
            'admin.course_offerings.lecturers.update' => 'academic.course_offering.lecturer.manage',
            'admin.course_offerings.lecturers.activate' => 'academic.course_offering.lecturer.manage',
            'admin.course_offerings.lecturers.end' => 'academic.course_offering.lecturer.manage',
            'admin.course_offerings.lecturers.cancel' => 'academic.course_offering.lecturer.manage',
            'admin.course_offerings.lecturers.replace' => 'academic.course_offering.lecturer.manage',
        ];
        foreach ($expected as $route => $permission) $this->assertSame($permission, $permissions->routePermission($route), $route);
        $this->assertEqualsCanonicalizing(
            ['academic.course_offering.lecturer.manage', 'academic.course_offering.lecturer.view'],
            $permissions->withDependencies(['academic.course_offering.lecturer.manage'])
        );
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with((string)$route->getName(), 'admin.course_offerings.')) continue;
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('admin', $route->gatherMiddleware());
            $this->assertContains('rbac', $route->gatherMiddleware());
            $this->assertNotNull($permissions->routePermission($route->getName()), 'Unmapped route '.$route->getName());
        }
    }

    public function test_view_permission_does_not_authorize_management_or_lifecycle_and_menu_does_not_grant_access(): void
    {
        $school = $this->makeSchool(['title' => 'Offering RBAC', 'status' => 1]);
        $viewer = User::factory()->create(['role_id'=>3,'school_id'=>$school,'account_status'=>'active','menu_permission'=>null]);
        DB::table('user_permissions')->insert(['school_id'=>$school,'user_id'=>$viewer->id,'permission'=>'academic.course_offering.view']);
        $permissions=app(PermissionService::class);
        $this->assertTrue($permissions->allows($viewer,'academic.course_offering.view'));
        $this->assertFalse($permissions->allows($viewer,'academic.course_offering.manage'));
        $this->assertFalse($permissions->allows($viewer,'academic.course_offering.lifecycle'));

        $ungranted=User::factory()->create(['role_id'=>3,'school_id'=>$school,'account_status'=>'active','menu_permission'=>json_encode(['admin.course_offerings.index'])]);
        $this->assertFalse($permissions->allows($ungranted,'academic.course_offering.view'));
        $this->assertTrue($permissions->allows(User::factory()->create(['role_id'=>2,'school_id'=>$school,'account_status'=>'active']),'academic.course_offering.lifecycle'));
    }

    public function test_lecturer_view_does_not_grant_management_but_school_admin_bypass_is_unchanged(): void
    {
        $school = $this->makeSchool(['title' => 'Lecturer RBAC', 'status' => 1]);
        $viewer = User::factory()->create(['role_id' => 3, 'school_id' => $school, 'account_status' => 'active']);
        DB::table('user_permissions')->insert(['school_id' => $school, 'user_id' => $viewer->id, 'permission' => 'academic.course_offering.lecturer.view']);
        $permissions = app(PermissionService::class);
        $this->assertTrue($permissions->allows($viewer, 'academic.course_offering.lecturer.view'));
        $this->assertFalse($permissions->allows($viewer, 'academic.course_offering.lecturer.manage'));

        $manager = User::factory()->create(['role_id' => 3, 'school_id' => $school, 'account_status' => 'active']);
        DB::table('user_permissions')->insert([
            ['school_id' => $school, 'user_id' => $manager->id, 'permission' => 'academic.course_offering.lecturer.manage'],
            ['school_id' => $school, 'user_id' => $manager->id, 'permission' => 'academic.course_offering.lecturer.view'],
        ]);
        $this->assertTrue($permissions->allows($manager, 'academic.course_offering.lecturer.view'));
        $this->assertTrue($permissions->allows($manager, 'academic.course_offering.lecturer.manage'));
        $this->assertTrue($permissions->allows(User::factory()->create(['role_id' => 2, 'school_id' => $school, 'account_status' => 'active']), 'academic.course_offering.lecturer.manage'));
    }
}
