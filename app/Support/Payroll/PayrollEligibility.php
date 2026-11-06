<?php

namespace App\Support\Payroll;

use App\Models\User;
use App\Support\Roles\SystemRole;
use App\Support\Staff\StaffStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who payroll can cover. Single source of truth for the payroll module so
 * the routes, controller and service can never drift apart again — the
 * original implementation hardcoded a role_id list that (as written) also
 * matched Parents (6), Students (7) and Reserved accounts (8), producing
 * payslips for people who are not employees.
 *
 * Eligibility mirrors the Staff Module's own boundaries exactly:
 *   - the admin-staff admission list of AdminMiddleware (roles 2–19 minus
 *     the non-staff 6/7/8), which is how the rest of the staff system
 *     decides "this account is a school employee";
 *   - plus the Generic Staff "Staff" role (20), which is the neutral
 *     employee identity of the RBAC phase;
 *   - never Super Admin (1) — platform-level, not employed by any school;
 *   - never Parents (6), Students (7), Reserved legacy (8).
 *   Reserved Alumni (9) stays included only because AdminMiddleware itself
 *   admits it to the staff side (documented as frozen-conflict compat);
 *   it is excluded by the status filters in practice.
 *
 * On top of the role list, an employee counts only while their account and
 * Staff Module status say they are still employed: account_status must not
 * be 'disable' and staff_status must not be suspended/inactive/terminated
 * (StaffStatus::blocksPortal — the same rule that blocks their portal).
 */
final class PayrollEligibility
{
    /** Non-staff accounts that must never receive a payslip. */
    public const NEVER_STAFF_ROLES = [SystemRole::SUPER_ADMIN, SystemRole::PARENT, SystemRole::STUDENT, SystemRole::RESERVED_LEGACY_USER];

    /** Role ids that can hold an employment record (see class docblock). */
    public const STAFF_ROLES = [
        SystemRole::SCHOOL_ADMIN, SystemRole::TEACHER, SystemRole::ACCOUNTANT,
        SystemRole::LIBRARIAN, SystemRole::RESERVED_ALUMNI, SystemRole::WARDEN,
        SystemRole::FROZEN_CONFLICT_11, SystemRole::FROZEN_CONFLICT_12,
        SystemRole::ADMISSIONS_OFFICER, SystemRole::DIRECTOR, SystemRole::HR_MANAGER,
        SystemRole::PROCUREMENT_OFFICER, SystemRole::STORE_KEEPER,
        SystemRole::RECEPTIONIST, SystemRole::EXAMINATIONS_OFFICER,
        SystemRole::GENERIC_STAFF,
    ];

    public static function baseQuery(int $schoolId): Builder
    {
        return User::query()
            ->whereIn('role_id', self::STAFF_ROLES)
            ->where('school_id', $schoolId)
            ->where(function (Builder $q) {
                $q->whereNull('staff_status')->orWhereNotIn('staff_status', StaffStatus::BLOCKED);
            })
            ->where(function (Builder $q) {
                $q->whereNull('account_status')->orWhere('account_status', '!=', 'disable');
            });
    }

    /** The employees a payroll run covers: everyone employed, salary-filterable. */
    public static function activeStaff(int $schoolId, ?int $departmentId = null): Builder
    {
        return self::baseQuery($schoolId)
            ->when($departmentId, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->orderBy('name');
    }

    /** Is this user currently employable (dashboard counters use this too)? */
    public static function isEligible(User $user): bool
    {
        return in_array((int) $user->role_id, self::STAFF_ROLES, true)
            && (int) $user->school_id !== 0
            && !StaffStatus::blocksPortal($user->staff_status)
            && $user->account_status !== 'disable';
    }

    /** Teaching vs non-teaching split for the dashboard counters. */
    public static function isTeaching(User $user): bool
    {
        return (int) $user->role_id === SystemRole::TEACHER;
    }
}
