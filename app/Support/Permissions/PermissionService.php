<?php

namespace App\Support\Permissions;

use App\Models\User;
use App\Policies\LiveClassPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * RBAC Phase 3A — the one place that answers "may this user do X?".
 *
 * It answers WHAT a user may do, never WHICH SCHOOL's data: every controller
 * keeps resolving records within the user's own school (Phases 2A–2I), and a
 * permission never widens that. Grants themselves are tenant-bound — a grant
 * only counts in the school it was made in.
 *
 * Resolution (grants-only, no deny rules):
 *   unknown key / disabled or suspended account      → denied
 *   Super Admin (1), School Admin (2)                → allowed (explicit bypass)
 *   Parent (6), Student (7), reserved role 8         → denied (their portals use ownership rules)
 *   Online Exams keys  → OnlineExamPermissionService (authoritative; it consults grants too)
 *   Live Classes keys  → LiveClassPolicy role rules, or a grant
 *   everything else    → base-role compatibility (registry.php: nav_sections via
 *                        get_role_nav_permissions() + portal_grants), or a grant
 *
 * No caching: permission changes take effect on the next check.
 *
 * Legacy layers (kept, never used for new grants — RBAC Phase 3B):
 *  - global_settings.role_perm_{id}: platform-wide per-role list; still read by
 *    OnlineExamPermissionService for the exam keys, edited only on the legacy
 *    Settings → Permissions page;
 *  - users.menu_permission: per-user RESTRICTION list (AdminPermission middleware,
 *    navigation, LiveClassPolicy) — new access is granted through user_permissions /
 *    staff_roles only.
 */
class PermissionService
{
    public const SUPER_ADMIN = 1;
    public const SCHOOL_ADMIN = 2;

    /**
     * The lecturer role. This is the same value TeacherMiddleware admits, so the
     * two can never drift apart.
     *
     * Named here because a bare 3 was already mis-guessed as 6 (= Parent) in the
     * Google Meet path, which both locked every real lecturer out and would have
     * let a parent write to a lecturer's own Google calendar.
     */
    public const TEACHER = 3;

    /** Roles that are never staff: Parent, Student, and the reserved legacy "user" role. */
    public const NON_STAFF_ROLES = [6, 7, 8];

    public function registry(): array
    {
        return PermissionRegistry::permissions();
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->registry());
    }

    public function definition(string $key): ?array
    {
        return $this->registry()[$key] ?? null;
    }

    public function isSensitive(string $key): bool
    {
        return (bool) ($this->definition($key)['sensitive'] ?? true);
    }

    public function isDelegable(string $key): bool
    {
        return (bool) ($this->definition($key)['delegable'] ?? false);
    }

    public function allows(?User $user, string $key): bool
    {
        if (!$user || !$this->exists($key) || !$this->isActive($user)) {
            return false;
        }

        $role = (int) $user->role_id;

        if ($role === self::SUPER_ADMIN || $role === self::SCHOOL_ADMIN) {
            return true;
        }

        if (!$this->isStaffRole($role)) {
            return false;
        }

        $examKey = PermissionRegistry::onlineExamKeys()[$key] ?? null;
        if ($examKey !== null) {
            return app(OnlineExamPermissionService::class)->has($user, $examKey);
        }

        if (str_starts_with($key, 'live_classes.') && $this->liveClassBaseAllows($role, $key)) {
            return true;
        }

        if (str_starts_with($key, 'course_content.') && $this->courseContentBaseAllows($role, $key)) {
            return true;
        }

        if (str_starts_with($key, 'course_assignments.') && $this->courseAssignmentBaseAllows($role, $key)) {
            return true;
        }

        return in_array($key, $this->basePermissions($user), true) || $this->hasGrant($user, $key);
    }

    public function allowsAny(?User $user, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->allows($user, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Direct or custom-role grant only (no bypass, no compatibility layer).
     * Used by modules that keep their own authorization and treat a grant as
     * one more way in (OnlineExamPermissionService, LiveClassPolicy).
     */
    public function hasGrant(?User $user, string $key): bool
    {
        if (!$user || !$this->exists($key) || !$this->isActive($user) || !$this->isStaffRole((int) $user->role_id)) {
            return false;
        }

        return in_array($key, $this->grantedPermissions($user), true);
    }

    /** What the user's base role can do today (backward-compatibility layer). */
    public function basePermissions(User $user): array
    {
        $role = (int) $user->role_id;

        if ($role === self::SUPER_ADMIN || $role === self::SCHOOL_ADMIN) {
            return array_keys($this->registry());
        }

        if (!$this->isStaffRole($role)) {
            return [];
        }

        $sections = function_exists('get_role_nav_permissions') ? get_role_nav_permissions($role) : [];
        $map = PermissionRegistry::navSections();
        $permissions = PermissionRegistry::portalGrants()[$role] ?? [];

        foreach ($sections as $section) {
            $permissions = array_merge($permissions, $map[$section] ?? []);
        }

        return array_values(array_unique(array_filter($permissions, fn ($key) => $this->exists($key))));
    }

    /** Direct + custom-role grants recorded for the user in their own school. */
    public function grantedPermissions(User $user): array
    {
        if (empty($user->school_id) || !$this->isStaffRole((int) $user->role_id) || !$this->grantTablesExist()) {
            return [];
        }

        $school = (int) $user->school_id;

        $direct = DB::table('user_permissions')
            ->where('user_id', $user->id)
            ->where('school_id', $school)
            ->pluck('permission')
            ->all();

        $viaRoles = DB::table('user_staff_roles')
            ->join('staff_roles', 'staff_roles.id', '=', 'user_staff_roles.staff_role_id')
            ->join('staff_role_permissions', 'staff_role_permissions.staff_role_id', '=', 'staff_roles.id')
            ->where('user_staff_roles.user_id', $user->id)
            ->where('user_staff_roles.school_id', $school)
            ->where('staff_roles.school_id', $school)
            ->when($this->staffRolesHaveStatus(), fn ($q) => $q->where('staff_roles.is_active', true))
            ->pluck('staff_role_permissions.permission')
            ->all();

        return array_values(array_unique(array_filter(array_merge($direct, $viaRoles), fn ($key) => $this->exists($key))));
    }

    /** Everything the user may do (for the Phase 3B UI and diagnostics). */
    public function effectivePermissions(User $user): array
    {
        return array_values(array_filter(array_keys($this->registry()), fn ($key) => $this->allows($user, $key)));
    }

    /** $keys plus everything they require (Phase 3B: an action always brings its module's view). */
    public function withDependencies(array $keys): array
    {
        $requires = PermissionRegistry::requires();
        $result = [];
        $queue = array_values($keys);
        while ($queue) {
            $key = array_shift($queue);
            if (isset($result[$key])) {
                continue;
            }
            $result[$key] = true;
            foreach ($requires[$key] ?? [] as $required) {
                $queue[] = $required;
            }
        }

        return array_keys($result);
    }

    /** Permissions that require $key (revoking $key must revoke these too). */
    public function dependentsOf(string $key): array
    {
        return array_keys(array_filter(PermissionRegistry::requires(), fn ($required) => in_array($key, $required, true)));
    }

    /**
     * Allowed by the base role alone (administrator bypass, compatibility map,
     * Live Class / Online Exam legacy rules) — i.e. without any delegated grant.
     */
    public function baseAllows(User $user, string $key): bool
    {
        if (!$this->exists($key) || !$this->isActive($user)) {
            return false;
        }

        $role = (int) $user->role_id;
        if ($role === self::SUPER_ADMIN || $role === self::SCHOOL_ADMIN) {
            return true;
        }
        if (!$this->isStaffRole($role)) {
            return false;
        }

        $examKey = PermissionRegistry::onlineExamKeys()[$key] ?? null;
        if ($examKey !== null) {
            return app(OnlineExamPermissionService::class)->hasBase($user, $examKey);
        }

        if (str_starts_with($key, 'live_classes.')) {
            return $this->liveClassBaseAllows($role, $key);
        }

        return in_array($key, $this->basePermissions($user), true);
    }

    /**
     * Read-only explanation of a user's effective access (Phase 3B "Effective
     * Access"): every permission they hold, with where it comes from.
     *
     * @return array<string, array<int, array{type: string, label: string}>>  key => sources
     */
    public function explain(User $user): array
    {
        $role = (int) $user->role_id;
        $roleName = \App\Support\Roles\SystemRole::name($role) ?? ('Role ' . $role);
        $direct = [];
        $viaRoles = [];

        if (!empty($user->school_id) && $this->isStaffRole($role) && $this->grantTablesExist()) {
            $school = (int) $user->school_id;
            $direct = DB::table('user_permissions')->where('user_id', $user->id)->where('school_id', $school)->pluck('permission')->all();
            $rows = DB::table('user_staff_roles')
                ->join('staff_roles', 'staff_roles.id', '=', 'user_staff_roles.staff_role_id')
                ->join('staff_role_permissions', 'staff_role_permissions.staff_role_id', '=', 'staff_roles.id')
                ->where('user_staff_roles.user_id', $user->id)
                ->where('user_staff_roles.school_id', $school)
                ->where('staff_roles.school_id', $school)
                ->when($this->staffRolesHaveStatus(), fn ($q) => $q->where('staff_roles.is_active', true))
                ->get(['staff_roles.name', 'staff_role_permissions.permission']);
            foreach ($rows as $row) {
                $viaRoles[$row->permission][] = $row->name;
            }
        }

        $explained = [];
        foreach (array_keys($this->registry()) as $key) {
            if (!$this->allows($user, $key)) {
                continue;
            }

            $sources = [];
            if ($role === self::SUPER_ADMIN || $role === self::SCHOOL_ADMIN) {
                $sources[] = ['type' => 'admin', 'label' => $roleName . ' (full access)'];
            } elseif ($this->baseAllows($user, $key)) {
                $sources[] = ['type' => 'base', 'label' => $roleName . ' base role'];
            }
            foreach (array_unique($viaRoles[$key] ?? []) as $name) {
                $sources[] = ['type' => 'role', 'label' => $name];
            }
            if (in_array($key, $direct, true)) {
                $sources[] = ['type' => 'direct', 'label' => 'Direct permission'];
            }

            $explained[$key] = $sources;
        }

        return $explained;
    }

    /** The permission an admin-portal route requires, or null if the route is not RBAC-mapped. */
    public function routePermission(?string $routeName): ?string
    {
        if (!$routeName) {
            return null;
        }

        foreach (PermissionRegistry::routes() as $pattern => $key) {
            if ($key !== null && Str::is($pattern, $routeName)) {
                return $key;
            }
        }

        return null;
    }

    public function isStaffRole(int $roleId): bool
    {
        return $roleId > 0 && !in_array($roleId, self::NON_STAFF_ROLES, true);
    }

    public function isActive(User $user): bool
    {
        return $user->account_status !== 'disable'
            && !(method_exists($user, 'isStaffPortalBlocked') && $user->isStaffPortalBlocked());
    }

    /**
     * Capability only, for the same reason liveClassBaseAllows() exists: a
     * lecturer who could already schedule Live Classes must not be stranded
     * behind a permission grant nobody has been asked to make. The ALLOCATION
     * check lives in CourseContentAccess and is not bypassed by this.
     *
     * The role lists are reused from LiveClassPolicy rather than restated, so
     * "who is a teaching role" is answered in exactly one place.
     */
    /**
     * Capability only, for the same reason courseContentBaseAllows() exists: a
     * lecturer who could already teach a Course Offering must not be stranded
     * behind a permission grant nobody has been asked to make. The ALLOCATION
     * check lives in AssignmentAccess and is not bypassed by this.
     *
     * The role lists are reused from LiveClassPolicy so that "who is a teaching
     * role" has exactly one definition across Live Classes, Course Content and
     * Course Assignments.
     */
    private function courseAssignmentBaseAllows(int $role, string $key): bool
    {
        return match ($key) {
            'course_assignments.view' => in_array($role, LiveClassPolicy::STAFF_ROLES, true),
            'course_assignments.manage' => in_array($role, LiveClassPolicy::CREATE_ROLES, true),
            default => false,
        };
    }

    private function courseContentBaseAllows(int $role, string $key): bool
    {
        return match ($key) {
            'course_content.view' => in_array($role, LiveClassPolicy::STAFF_ROLES, true),
            'course_content.manage' => in_array($role, LiveClassPolicy::CREATE_ROLES, true),
            default => false,
        };
    }

    private function liveClassBaseAllows(int $role, string $key): bool
    {
        return match ($key) {
            'live_classes.view' => in_array($role, LiveClassPolicy::STAFF_ROLES, true),
            'live_classes.create' => in_array($role, LiveClassPolicy::CREATE_ROLES, true),
            'live_classes.manage_all' => in_array($role, LiveClassPolicy::MANAGE_ALL_ROLES, true),
            'live_classes.platforms' => in_array($role, LiveClassPolicy::PLATFORM_ROLES, true),
            default => false,
        };
    }

    /** Phase 3B adds staff_roles.is_active; before that migration every role counts as active. */
    private function staffRolesHaveStatus(): bool
    {
        return Schema::hasColumn('staff_roles', 'is_active');
    }

    /** Before the RBAC migration has run (e.g. piie_main today) there are simply no grants. */
    private function grantTablesExist(): bool
    {
        return Schema::hasTable('user_permissions') && Schema::hasTable('user_staff_roles')
            && Schema::hasTable('staff_roles') && Schema::hasTable('staff_role_permissions');
    }
}
