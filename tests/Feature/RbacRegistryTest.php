<?php

namespace Tests\Feature;

use App\Policies\LiveClassPolicy;
use App\Support\Permissions\OnlineExamPermissionService;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Permissions\PermissionService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * RBAC Phase 3A — app/Support/Permissions/registry.php is the one authoritative registry.
 * These checks keep it consistent with the real application.
 */
class RbacRegistryTest extends TestCase
{
    private function registry(): array
    {
        return PermissionRegistry::permissions();
    }

    public function test_the_registry_ships_with_the_code_not_the_unversioned_config_directory(): void
    {
        // config/*.php is git-ignored in this repository; a registry that went missing on
        // deploy would deny every staff member, so it must live in app/.
        $this->assertFileExists(app_path('Support/Permissions/registry.php'));
        $this->assertFileDoesNotExist(config_path('permissions.php'));
        $this->assertNotEmpty(PermissionRegistry::permissions());
    }

    public function test_every_permission_is_a_well_formed_key_of_a_declared_module(): void
    {
        $modules = PermissionRegistry::modules();
        foreach ($this->registry() as $key => $definition) {
            // module.action, optionally module.resource.action; Course Allocation lecturer permissions use the explicit academic.course_offering.lecturer.action namespace.
            $this->assertTrue(
                preg_match('/^[a-z_]+(\.[a-z_]+){1,2}$/', $key) === 1
                    || preg_match('/^academic\.course_offering\.lecturer\.(view|manage)$/', $key) === 1,
                "Malformed permission key: {$key}"
            );
            $this->assertArrayHasKey($definition['module'], $modules, $key);
            $this->assertNotEmpty($definition['label'], $key);
            $this->assertNotEmpty($definition['description'], $key);
            $this->assertIsBool($definition['sensitive'], $key);
            $this->assertIsBool($definition['delegable'], $key);
        }
    }

    public function test_high_risk_capabilities_are_sensitive_and_rbac_administration_is_never_delegable(): void
    {
        foreach (['finance.settings', 'finance.payments', 'finance.payroll', 'students.delete', 'staff.delete', 'admissions.payments',
                  'online_exams.publish', 'online_exams.results', 'cms.manage', 'settings.school', 'audit.view', 'academic.sessions',
                  'roles.view', 'roles.manage', 'permissions.assign', 'users.assign_roles', 'admins.manage'] as $key) {
            $this->assertTrue($this->registry()[$key]['sensitive'], "{$key} should be sensitive");
        }
        foreach (['roles.view', 'roles.manage', 'permissions.assign', 'users.assign_roles', 'admins.manage', 'subscription.manage'] as $key) {
            $this->assertFalse($this->registry()[$key]['delegable'], "{$key} must not be delegable");
        }
        // Ordinary finance work does not imply gateway settings.
        $this->assertNotContains('finance.settings', PermissionRegistry::navSections()['fees']);
        $this->assertNotContains('finance.settings', PermissionRegistry::navSections()['payments']);
    }

    public function test_the_route_map_only_uses_registered_permissions_and_real_admin_routes(): void
    {
        $adminRoutes = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('admin', $route->gatherMiddleware(), true) && $route->getName()) {
                $adminRoutes[] = $route->getName();
                $this->assertContains('rbac', $route->gatherMiddleware(), $route->getName() . ' is missing the rbac middleware');
            }
        }

        foreach (PermissionRegistry::routes() as $pattern => $key) {
            $this->assertArrayHasKey($key, $this->registry(), $pattern);
            $this->assertNotEmpty(array_filter($adminRoutes, fn ($name) => Str::is($pattern, $name)), "{$pattern} matches no admin route");
        }
    }

    public function test_sensitive_routes_are_mapped_to_their_permissions(): void
    {
        $service = app(PermissionService::class);
        $this->assertSame('finance.settings', $service->routePermission('admin.settings.payment_post'));
        $this->assertSame('finance.settings', $service->routePermission('admin.settings.payment'));
        $this->assertSame('finance.invoices', $service->routePermission('admin.fee_manager.update'));
        $this->assertSame('finance.view', $service->routePermission('admin.fee_manager.list'));
        $this->assertSame('admissions.payments', $service->routePermission('admin.hei_admissions.payment.record'));
        $this->assertSame('admissions.view', $service->routePermission('admin.hei_admissions.index'));
        $this->assertSame('cms.manage', $service->routePermission('admin.website.page.delete'));
        $this->assertSame('audit.view', $service->routePermission('admin.audit_log.index'));
        $this->assertSame('roles.manage', $service->routePermission('admin.settings.permissions.save'));
        // Shared helpers and modules with their own authorization stay unmapped.
        foreach (['admin.dashboard', 'admin.class_wise_sections', 'admin.online_exams.index', 'admin.live_classes.index', 'admin.profile', 'admin.teacher', 'admin.admin'] as $name) {
            $this->assertNull($service->routePermission($name), "{$name} should stay unmapped");
        }
    }

    public function test_compatibility_maps_only_use_registered_permissions(): void
    {
        foreach (PermissionRegistry::navSections() as $section => $keys) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $this->registry(), "nav section {$section}");
            }
        }
        foreach (PermissionRegistry::portalGrants() as $role => $keys) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $this->registry(), "portal role {$role}");
            }
        }
    }

    public function test_online_exam_permissions_are_bridged_to_the_exam_module_keys(): void
    {
        $bridge = PermissionRegistry::onlineExamKeys();
        foreach ($bridge as $rbacKey => $examKey) {
            $this->assertArrayHasKey($rbacKey, $this->registry());
            $this->assertContains($examKey, OnlineExamPermissionService::KEYS);
        }
        // Every staff exam capability is represented (sitting exams is a student capability, not delegable).
        $this->assertEqualsCanonicalizing(array_diff(OnlineExamPermissionService::KEYS, ['sit_online_exams']), array_values($bridge));
    }

    public function test_live_class_base_rules_are_the_policy_constants(): void
    {
        $this->assertSame([1, 2, 3, 10, 12, 14], LiveClassPolicy::CREATE_ROLES);
        $this->assertSame([1, 2, 10, 12, 14], LiveClassPolicy::MANAGE_ALL_ROLES);
        $this->assertSame([1, 2, 14], LiveClassPolicy::PLATFORM_ROLES);
    }

    public function test_course_registration_permissions_are_declared_and_manage_confirm_depend_on_view(): void
    {
        foreach (['academic.course_registration.view', 'academic.course_registration.manage', 'academic.course_registration.confirm'] as $key) {
            $this->assertArrayHasKey($key, $this->registry());
        }
        $dependencies = PermissionRegistry::requires();
        $this->assertContains('academic.course_registration.view', $dependencies['academic.course_registration.manage']);
        $this->assertContains('academic.course_registration.view', $dependencies['academic.course_registration.confirm']);
    }
}
