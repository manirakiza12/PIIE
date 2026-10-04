<?php

namespace App\Support\CourseOffering;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\User;
use App\Support\Permissions\PermissionService;
use App\Support\Roles\SystemRole;
use App\Support\Staff\StaffStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authorised system testing: the ONE place that may relax a pre-start DATE gate.
 *
 * This is an ADDITIONAL capability, never a role. The lecturer keeps their real
 * identity and authority: a Lecturer stays a Lecturer and users.role_id is never
 * read as anything but that.
 *
 * What it actually relaxes
 * -----------------------
 * Exactly one condition, and only in this situation: an allocation that is
 * genuinely ACTIVE for this exact Course Offering, on an Offering that is
 * IN_PROGRESS and carries durable evidence of the governed
 * COURSE_OFFERING_EARLY_START workflow, is treated as in force from today even
 * though today is before its starts_on.
 *
 * What it never relaxes
 * ---------------------
 * Authentication, tenant isolation, Lecturer identity, Offering lifecycle,
 * allocation existence, allocation status (planned/ended/cancelled stay
 * refused), and IDOR protection are all still required and are re-checked here.
 * There is no "tester => allow" branch anywhere in this class.
 *
 * No allocation date is ever written. starts_on, ends_on, the Academic Period
 * and the Course Offering are all left exactly as the institution set them; the
 * lecturer's contractual appointment keeps its original meaning.
 */
class SystemTesterAccess
{
    public const PERMISSION = 'system.testing.prestart_lecturer';

    /**
     * The audit action written by the governed early-start workflow. Its presence
     * on an Offering is the durable evidence that the Offering was deliberately
     * started early, rather than a flag we could drift out of step.
     */
    public const EARLY_START_AUDIT = 'COURSE_OFFERING_EARLY_START';

    /**
     * Whether this lecturer may exercise pre-start testing access on this exact
     * allocation right now.
     *
     * Returns false unless every condition holds. Note what is NOT here: no
     * check for an Academic Period start date, and no write of any kind. The
     * caller is responsible for applying the result ONLY to a date comparison.
     */
    public function allowsPreStartFor(User $lecturer, object $allocation, ?CourseOffering $offering = null): bool
    {
        if (! $this->granted($lecturer)) {
            return false;
        }
        if (! $this->isLecturer($lecturer)) {
            return false;
        }
        if (empty($lecturer->school_id)) {
            return false;
        }
        if (! $this->isActive($lecturer)) {
            return false;
        }

        // Tenant: the allocation and the lecturer must share one school.
        $allocationSchool = (int) ($allocation->school_id ?? 0);
        if ($allocationSchool === 0 || $allocationSchool !== (int) $lecturer->school_id) {
            return false;
        }

        // Allocation status is never relaxed: only a live ACTIVE appointment.
        if (($allocation->status ?? null) !== CourseOfferingLecturerAllocation::STATUS_ACTIVE) {
            return false;
        }
        if ($allocation->user_id === null || (int) $allocation->user_id !== (int) $lecturer->id) {
            return false;
        }

        $offering ??= $this->offeringFor($lecturer, $allocation);
        if (! $offering) {
            return false;
        }
        if ((int) $offering->school_id !== (int) $lecturer->school_id) {
            return false;
        }
        if ($offering->status !== CourseOffering::STATUS_IN_PROGRESS) {
            return false;
        }
        if (! $this->wasGovernedEarlyStart($offering)) {
            return false;
        }

        return true;
    }

    /**
     * Whether the allocation is not yet in force purely because today precedes
     * starts_on. If any OTHER condition is failing, this returns false so the
     * caller never treats an unrelated problem as a pre-start situation.
     */
    public function onlyPreStartDateBlocks(User $lecturer, object $allocation, ?string $date = null, ?CourseOffering $offering = null): bool
    {
        $date ??= Carbon::today()->toDateString();
        $startsOn = (string) ($allocation->starts_on ?? '');
        if ($startsOn === '' || $startsOn <= $date) {
            return false; // already in force, or no meaningful start date
        }
        $endsOn = $allocation->ends_on === null ? null : (string) $allocation->ends_on;
        if ($endsOn !== null && $endsOn < $date) {
            return false; // the stint has already finished
        }

        return $this->allowsPreStartFor($lecturer, $allocation, $offering);
    }

    /**
     * The Offering this allocation belongs to, scoped to the lecturer's tenant.
     * Returns null when it does not exist in that tenant, so a guessed
     * course_offering_id cannot reveal or cross into another institution.
     */
    public function offeringFor(User $lecturer, object $allocation): ?CourseOffering
    {
        if (empty($lecturer->school_id) || ! isset($allocation->course_offering_id)) {
            return null;
        }

        return CourseOffering::where('school_id', (int) $lecturer->school_id)
            ->whereKey((int) $allocation->course_offering_id)
            ->first();
    }

    /** Durable evidence that the governed early-start workflow was used here. */
    public function wasGovernedEarlyStart(CourseOffering $offering): bool
    {
        if (! $this->auditAvailable()) {
            return false;
        }

        return DB::table('audit_logs')
            ->where('school_id', (int) $offering->school_id)
            ->where('action', self::EARLY_START_AUDIT)
            ->where('record_type', CourseOffering::class)
            ->where('record_id', (int) $offering->id)
            ->exists();
    }

    /**
     * Whether an authorised pre-start tester may record an Attendance Session
     * dated before the Academic Period start.
     *
     * This delegates to the SAME allowsPreStartFor() gate the workspace and Live
     * Classes use, so there is exactly one definition of "authorised pre-start
     * tester": the permission, the Lecturer identity, the tenant, the exact
     * ACTIVE non-ended allocation, the IN_PROGRESS lifecycle and the durable
     * governed early-start evidence.
     *
     * On top of that it only ever relaxes ONE thing, the period START bound:
     *   - the session date must actually be before the period start, otherwise
     *     the normal rule already allows it and this is not a pre-start case;
     *   - the session date may never be in the FUTURE, so testing cannot be used
     *     to pre-record teaching that has not happened yet.
     *
     * The period end bound, duplicate detection, Live Class ownership, the
     * confirmed roster and every other Attendance rule are untouched.
     */
    public function allowsPreStartAttendanceDate(
        User $lecturer,
        CourseOffering $offering,
        string $sessionDate,
        ?string $periodStart = null,
        ?string $date = null
    ): bool {
        $date ??= Carbon::today()->toDateString();
        $sessionDay = Carbon::parse($sessionDate)->startOfDay()->toDateString();

        $periodStart ??= DB::table('academic_periods')
            ->where('school_id', $offering->school_id)
            ->where('academic_year_id', $offering->academic_year_id)
            ->where('id', $offering->academic_period_id)
            ->value('start_date');
        if ($periodStart === null) {
            return false;
        }

        $periodStartDay = Carbon::parse($periodStart)->startOfDay()->toDateString();

        // Not a pre-start case: the normal period rule already covers it.
        if ($sessionDay >= $periodStartDay) {
            return false;
        }
        // Never allow recording teaching that has not happened yet.
        if ($sessionDay > $date) {
            return false;
        }

        $allocation = $this->allocationFor($lecturer, $offering);
        if ($allocation === null) {
            return false;
        }
        // The allocation must still be pre-start, so this is exactly the
        // situation the workspace and Live Classes already recognise.
        if ((string) ($allocation->starts_on ?? '') <= $date) {
            return false;
        }

        return $this->allowsPreStartFor($lecturer, $allocation, $offering);
    }

    /** This lecturer's allocation for one Offering, scoped to their tenant. */
    private function allocationFor(User $lecturer, CourseOffering $offering): ?object
    {
        if (empty($lecturer->school_id)) {
            return null;
        }

        return DB::table('course_offering_lecturer_allocations')
            ->where('school_id', (int) $lecturer->school_id)
            ->where('course_offering_id', (int) $offering->id)
            ->where('user_id', (int) $lecturer->id)
            ->orderBy('id')
            ->first();
    }

    /** Read-only explanation for the UI, so the notice can be honest about why. */
    public function explanation(?string $date = null): string
    {
        $date ??= Carbon::today()->toDateString();

        return 'Testing access active — pre-start access has been granted for authorised system testing. '
            .'This Course Offering was deliberately started early, and your lecturer allocation takes effect from '
            .$date.' even though its agreed start date is later. Your allocation dates and the Academic Period are unchanged.';
    }

    /** Does this user hold the permission, through any existing grant mechanism? */
    private function granted(User $user): bool
    {
        return app(PermissionService::class)->allows($user, self::PERMISSION);
    }

    private function isLecturer(User $user): bool
    {
        return (int) $user->role_id === SystemRole::TEACHER;
    }

    private function isActive(User $user): bool
    {
        if ($user->account_status === 'disable') {
            return false;
        }
        if (method_exists($user, 'isStaffPortalBlocked') && $user->isStaffPortalBlocked()) {
            return false;
        }
        if (in_array((string) $user->staff_status, [StaffStatus::SUSPENDED, StaffStatus::INACTIVE, StaffStatus::TERMINATED], true)) {
            return false;
        }

        return true;
    }

    private function auditAvailable(): bool
    {
        return Schema::hasTable('audit_logs') && Schema::hasColumn('audit_logs', 'action');
    }
}
