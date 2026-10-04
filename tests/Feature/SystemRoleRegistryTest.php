<?php

namespace Tests\Feature;

use App\Support\Roles\SystemRole;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Tests the passive canonical role registry (RBAC Phase 1). These assert
 * the registry's own contents only — they do not touch, and must never
 * change, application authorization. See RbacRoleMiddlewareCharacterizationTest
 * and siblings for what the running app actually does per role_id.
 */
class SystemRoleRegistryTest extends TestCase
{
    public function test_seeded_roles_1_to_7_are_unchanged_and_protected(): void
    {
        $expected = [
            1 => ['super_admin', SystemRole::SUPER_ADMIN],
            2 => ['school_admin', SystemRole::SCHOOL_ADMIN],
            3 => ['teacher', SystemRole::TEACHER],
            4 => ['accountant', SystemRole::ACCOUNTANT],
            5 => ['librarian', SystemRole::LIBRARIAN],
            6 => ['parent', SystemRole::PARENT],
            7 => ['student', SystemRole::STUDENT],
        ];

        foreach ($expected as $roleId => [$key, $constant]) {
            $this->assertSame($roleId, $constant);
            $this->assertSame($key, SystemRole::key($roleId));
            $this->assertSame(SystemRole::STATUS_PROTECTED, SystemRole::status($roleId));
        }
    }

    public function test_8_and_9_are_reserved(): void
    {
        $this->assertSame(8, SystemRole::RESERVED_LEGACY_USER);
        $this->assertSame(9, SystemRole::RESERVED_ALUMNI);
        $this->assertSame([8, 9], SystemRole::idsWithStatus(SystemRole::STATUS_RESERVED));
    }

    public function test_10_remains_warden(): void
    {
        $this->assertSame(10, SystemRole::WARDEN);
        $this->assertSame('warden', SystemRole::key(10));
        $this->assertSame(SystemRole::STATUS_PROTECTED, SystemRole::status(10));
    }

    public function test_11_and_12_are_frozen(): void
    {
        $this->assertSame([11, 12], SystemRole::idsWithStatus(SystemRole::STATUS_FROZEN));
    }

    public function test_specialized_staff_roles_13_to_19(): void
    {
        $expected = [
            13 => [SystemRole::ADMISSIONS_OFFICER, 'admissions_officer', SystemRole::STATUS_PLANNED],
            14 => [SystemRole::DIRECTOR, 'director', SystemRole::STATUS_PLANNED],
            15 => [SystemRole::HR_MANAGER, 'hr_manager', SystemRole::STATUS_ACTIVE],
            16 => [SystemRole::PROCUREMENT_OFFICER, 'procurement_officer', SystemRole::STATUS_PLANNED],
            17 => [SystemRole::STORE_KEEPER, 'store_keeper', SystemRole::STATUS_PLANNED],
            18 => [SystemRole::RECEPTIONIST, 'receptionist', SystemRole::STATUS_PLANNED],
            19 => [SystemRole::EXAMINATIONS_OFFICER, 'examinations_officer', SystemRole::STATUS_PLANNED],
        ];

        foreach ($expected as $roleId => [$constant, $key, $status]) {
            $this->assertSame($roleId, $constant);
            $this->assertSame($key, SystemRole::key($roleId));
            $this->assertSame($status, SystemRole::status($roleId));
            $this->assertSame($roleId, SystemRole::idForKey($key));
        }
    }

    public function test_registry_covers_exactly_1_to_20_and_allocates_nothing_new(): void
    {
        // 20 = Generic Staff (migration 2026_09_27_000001_add_generic_staff_system_role).
        $this->assertSame(range(1, 20), array_keys(SystemRole::all()));
        $this->assertNull(SystemRole::find(0));
        $this->assertNull(SystemRole::find(21));
    }

    public function test_bursar_is_the_accountant_not_a_separate_role(): void
    {
        $this->assertSame(SystemRole::ACCOUNTANT, SystemRole::ALIASES['bursar']['role_id']);
        $this->assertNull(SystemRole::idForKey('bursar'));
    }

    public function test_hod_is_a_teacher_designation_not_a_role(): void
    {
        $this->assertSame(['role_id' => SystemRole::TEACHER, 'designation' => 'HOD'], SystemRole::ALIASES['hod']);
        $this->assertNull(SystemRole::idForKey('hod'));
    }

    public function test_registrar_has_no_role_id(): void
    {
        $this->assertContains('registrar', SystemRole::UNALLOCATED);
        $this->assertNull(SystemRole::idForKey('registrar'));

        foreach ([9, 10, 11, 12] as $roleId) {
            $this->assertNotSame('registrar', SystemRole::key($roleId));
        }
    }

    public function test_every_role_has_a_known_status(): void
    {
        $statuses = [SystemRole::STATUS_PROTECTED, SystemRole::STATUS_ACTIVE, SystemRole::STATUS_PLANNED,
                     SystemRole::STATUS_RESERVED, SystemRole::STATUS_FROZEN];

        foreach (SystemRole::all() as $roleId => $role) {
            $this->assertContains($role['status'], $statuses, "role {$roleId}");
        }
    }

    /**
     * Phase 1 guard: the registry is passive. Wiring it into middleware,
     * policies, controllers, routes, views or seeders is a later phase and
     * must update this test deliberately.
     *
     * RBAC Phase 3B (deliberate update): the Roles & Permissions screens read
     * it for DISPLAY and VALIDATION only — base-role names, the legacy badge,
     * and refusing custom role names that impersonate a protected base role.
     * It still decides no access, redirect or middleware; any other file that
     * starts referencing it must be added here on purpose.
     *
     * Generic Staff (deliberate update): role_id 20 is installed by migration
     * and routed via SystemRole::GENERIC_STAFF in login/reset redirects, the
     * staff/admin middleware, staff provisioning and account access. The
     * lecturer allocation service reads SystemRole::TEACHER for eligibility,
     * and the lecturer workspace reads SystemRole::TEACHER to confirm the
     * signed-in user really is a lecturer before any Offering is resolved.
     */
    public function test_registry_is_not_referenced_by_runtime_code_yet(): void
    {
        $finder = (new Finder())->files()->name('*.php')
            ->in([base_path('app'), base_path('routes'), base_path('resources/views'), base_path('database')])
            ->notPath('Support/Roles')
            ->contains('SystemRole');

        $phase3bDisplayOnly = [
            'Http/Controllers/Admin/RolePermissionController.php',   // base-role name + filter labels
            'Support/Permissions/PermissionAssignmentService.php',   // reserved custom-role names
            'Support/Permissions/PermissionService.php',             // explain(): "<base role> base role" label
            'admin/rbac/roles/show.blade.php',                        // base-role name column
            'admin/rbac/staff/index.blade.php',                       // base-role name column
        ];

        $genericStaffWiring = [
            'Http/Controllers/Admin/GenericStaffAccountAccessController.php',
            'Http/Controllers/Admin/OtherStaffController.php',
            // Staff Directory record management: identity/display only — which
            // base role a person is, the Lecturer academic block, and refusing
            // to act as a platform Super Admin. Every authorization decision
            // there comes from PermissionService and StaffRecordService.
            'Http/Controllers/Admin/StaffProfileController.php',
            'Http/Controllers/Auth/LoginController.php',
            'Http/Controllers/Auth/ResetPasswordController.php',
            'Http/Middleware/AdminMiddleware.php',
            'Http/Middleware/GenericStaffMiddleware.php',
            'Http/Middleware/RedirectIfAuthenticated.php',
            'Support/CourseOffering/CourseOfferingLecturerAllocationService.php',
            'Support/CourseOffering/LecturerCourseOfferingAccess.php',   // SystemRole::TEACHER: lecturer identity
            'Support/CourseOffering/SystemTesterAccess.php',   // SystemRole::TEACHER: testing access requires Lecturer identity
            'Support/Staff/StaffProvisioningService.php',
            'admin/navigation.blade.php',
            'migrations/2026_09_27_000001_add_generic_staff_system_role.php',
        ];
        $phase3bDisplayOnly = array_merge($phase3bDisplayOnly, $genericStaffWiring);

        $found = array_map(fn ($file) => str_replace('\\', '/', $file->getRelativePathname()), iterator_to_array($finder, false));
        sort($found);
        sort($phase3bDisplayOnly);

        $this->assertSame($phase3bDisplayOnly, $found);
    }
}
