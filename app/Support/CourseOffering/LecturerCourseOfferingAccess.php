<?php

namespace App\Support\CourseOffering;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\CourseRegistration;
use App\Models\Subject;
use App\Models\User;
use App\Support\CourseRegistration\CourseOfferingRoster;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The single authority for what a Lecturer may see and do inside an HEI Course
 * Offering.
 *
 * The only accepted chain is:
 *
 *   authenticated Lecturer -> Lecturer Allocation -> Course Offering -> tenant
 *
 * Nothing else grants access. In particular a shared Course Unit, a Programme
 * membership, a Programme Cohort membership or a legacy teacher/class assignment
 * never expose a Course Offering, because none of them is an academic delivery
 * appointment and none of them carries the offering's own lifecycle.
 *
 * Allocation lifecycle is reused verbatim from
 * CourseOfferingLecturerAllocationService rather than restated here:
 *
 *   planned   an agreed appointment that is not yet in force
 *   active    in force now, inside its own effective date window
 *   ended     a historical appointment; it keeps its record but grants no
 *             current teaching action
 *   cancelled withdrawn; it grants nothing at all
 *
 * Offering lifecycle is reused from CourseOfferingService via CourseOffering's
 * own status constants, and the operational gate matches
 * LiveClassAccessService::offeringAllowsOperations() so Live Classes, this
 * workspace and the admin screens can never disagree about whether an Offering
 * is being taught.
 */
class LecturerCourseOfferingAccess
{
    public function __construct(private SystemTesterAccess $testerAccess) {}

    /**
     * Human labels for the allocation roles. Reused verbatim by the admin
     * Teaching Team page so a lecturer and an administrator always see the
     * same words for the same appointment.
     */
    public const ROLE_LABELS = [
        CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER => 'Primary Lecturer',
        CourseOfferingLecturerAllocation::ROLE_CO_LECTURER => 'Co-Lecturer',
        CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT => 'Teaching Assistant',
        CourseOfferingLecturerAllocation::ROLE_LAB_INSTRUCTOR => 'Lab Instructor',
        CourseOfferingLecturerAllocation::ROLE_GUEST_LECTURER => 'Guest Lecturer',
    ];

    /**
     * Allocations a lecturer may still reach, keyed by offering id. Cancelled
     * allocations are excluded outright; ended ones are included so a completed
     * Course Offering still shows its teaching record.
     */
    public function reachableOfferings(User $lecturer, ?string $date = null): SupportCollection
    {
        if (! $this->allocationsAvailable() || ! $this->isLecturer($lecturer)) {
            return collect();
        }
        $date ??= Carbon::today()->toDateString();

        return DB::table('course_offering_lecturer_allocations')
            ->where('school_id', $lecturer->school_id)
            ->where('user_id', $lecturer->id)
            ->whereIn('status', [
                CourseOfferingLecturerAllocation::STATUS_PLANNED,
                CourseOfferingLecturerAllocation::STATUS_ACTIVE,
                CourseOfferingLecturerAllocation::STATUS_ENDED,
            ])
            ->orderBy('course_offering_id')
            ->orderBy('id')
            ->get(['id', 'school_id', 'course_offering_id', 'user_id', 'role', 'starts_on', 'ends_on', 'status'])
            ->keyBy('course_offering_id');
    }

    /**
     * My Course Offerings, with the academic context and the student count the
     * list shows. Every row is derived from an allocation, so an unallocated
     * offering can never appear.
     */
    public function offerings(User $lecturer, array $filters = [], ?string $date = null): Collection
    {
        $allocations = $this->reachableOfferings($lecturer, $date);
        if ($allocations->isEmpty()) {
            return new Collection();
        }
        $date ??= Carbon::today()->toDateString();

        $offerings = CourseOffering::query()
            ->where('school_id', $lecturer->school_id)
            ->whereIn('id', $allocations->keys()->all())
            ->when($filters['academic_year_id'] ?? null, fn ($query, $id) => $query->where('academic_year_id', (int) $id))
            ->when($filters['academic_period_id'] ?? null, fn ($query, $id) => $query->where('academic_period_id', (int) $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('academic_year_id')
            ->orderBy('academic_period_id')
            ->orderBy('id')
            ->get();

        return $this->decorate($lecturer, $this->withAcademicContext($lecturer, $offerings), $allocations, $date);
    }

    /**
     * Resolve the offering a lecturer explicitly asked for.
     *
     * A lecturer who was never allocated to it, or who belongs to another
     * institution, gets the same not-found response as a missing record, so a
     * changed URL id reveals nothing. Returns null rather than throwing so the
     * caller decides how to render the boundary.
     */
    public function resolveForLecturer(User $lecturer, int $offeringId, ?string $date = null): ?CourseOffering
    {
        $allocations = $this->reachableOfferings($lecturer, $date);
        $allocation = $allocations->get($offeringId);
        if (! $allocation) {
            return null;
        }

        $offerings = CourseOffering::query()
            ->where('school_id', $lecturer->school_id)
            ->whereKey($offeringId)
            ->get();

        return $this->decorate($lecturer, $this->withAcademicContext($lecturer, $offerings), $allocations, $date)->first();
    }

    /** Attach this lecturer's allocation, Study Plan context and student count. */
    private function decorate(User $lecturer, Collection $offerings, SupportCollection $allocations, ?string $date): Collection
    {
        $date ??= Carbon::today()->toDateString();
        $context = $this->academicContext($lecturer, $offerings->pluck('id')->all());
        $counts = $this->confirmedCounts($lecturer, $offerings->pluck('id')->all());

        return $offerings->map(function (CourseOffering $offering) use ($lecturer, $allocations, $context, $counts, $date): CourseOffering {
            $allocation = $allocations->get($offering->id);
            $offering->setAttribute('my_allocation_id', $allocation->id);
            $offering->setAttribute('my_role', $allocation->role);
            $offering->setAttribute('my_role_label', self::ROLE_LABELS[$allocation->role] ?? 'Lecturer');
            $offering->setAttribute('my_allocation_status', $allocation->status);
            $offering->setAttribute('my_allocation_starts_on', $allocation->starts_on);
            $offering->setAttribute('my_allocation_ends_on', $allocation->ends_on);
            $offering->setAttribute('my_allocation_is_current', $this->allocationIsCurrent($lecturer, $allocation, $date));
            $offering->setAttribute('my_allocation_is_testing_access', $this->testerAccess->onlyPreStartDateBlocks($lecturer, $allocation, $date, $offering));
            $offering->setAttribute('my_study_plans', $context['versions'][$offering->id] ?? collect());
            $offering->setAttribute('my_stages', $context['stages'][$offering->id] ?? collect());
            $offering->setAttribute('my_programmes', $context['programmes'][$offering->id] ?? collect());
            $offering->setAttribute('my_confirmed_students', $counts[$offering->id] ?? 0);

            return $offering;
        })->values();
    }

    /**
     * Current teaching means: the allocation is in force today AND the Offering is
     * in a state that is actually being taught. This is deliberately identical to
     * the rule Live Class management already applies, so a lecturer can never
     * reach a teaching action here that Live Classes would refuse there.
     */
    public function teachingActionsAllowed(CourseOffering $offering, ?string $date = null): bool
    {
        $date ??= Carbon::today()->toDateString();
        $allocation = $this->allocationFromOffering($offering, $date);

        return $allocation !== null
            && $this->allocationIsCurrent(null, $allocation, $date)
            && $this->offeringAllowsOperations($offering);
    }

    /**
     * The teaching roster. Course Offering scoped and tenant scoped: confirmed
     * registrations for this Offering only. A Programme, a Cohort or the wider
     * institution never widens it, and the lecturer is read-only here.
     */
    public function teachingRoster(CourseOffering $offering)
    {
        $registrations = app(CourseOfferingRoster::class)->registered($offering);

        return $registrations->where('status', CourseRegistration::STATUS_CONFIRMED)->values();
    }

    /** The allocation record carried on an offering resolved by this service. */
    public function allocationFromOffering(CourseOffering $offering, ?string $date = null): ?object
    {
        $allocationId = $offering->getAttribute('my_allocation_id');
        if (! $allocationId) {
            return null;
        }
        $allocation = DB::table('course_offering_lecturer_allocations')
            ->where('school_id', $offering->school_id)
            ->where('id', $allocationId)
            ->first();
        if (! $allocation) {
            return null;
        }

        return $this->allocationIsCurrent(null, $allocation, $date) ? $allocation : null;
    }

    /** Reused from LiveClassAccessService so workspace and Live Classes agree. */
    public function offeringAllowsOperations(CourseOffering $offering): bool
    {
        return in_array($offering->status, [CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true);
    }

    /**
     * Completed Offerings keep a readable teaching record; cancelled and draft
     * Offerings never expose a roster.
     */
    public function rosterAvailable(CourseOffering $offering): bool
    {
        return $this->teachingActionsAllowed($offering)
            || $offering->status === CourseOffering::STATUS_COMPLETED;
    }

    public static function roleLabel(?string $role): string
    {
        return self::ROLE_LABELS[(string) $role] ?? 'Lecturer';
    }

    /** Allocation filters available on the My Course Offerings page. */
    public function filterOptions(User $lecturer): array
    {
        $allocations = $this->reachableOfferings($lecturer);
        if ($allocations->isEmpty() || ! Schema::hasTable('academic_years')) {
            return ['years' => collect(), 'periods' => collect(), 'statuses' => collect()];
        }

        $years = DB::table('academic_years')->where('school_id', $lecturer->school_id)
            ->whereIn('id', DB::table('course_offerings')->where('school_id', $lecturer->school_id)
                ->whereIn('id', $allocations->keys()->all())->distinct()->pluck('academic_year_id'))
            ->orderByDesc('start_date')->pluck('label', 'id');

        return [
            'years' => $years,
            'periods' => DB::table('academic_periods')->where('school_id', $lecturer->school_id)
                ->whereIn('academic_year_id', $years->keys()->all() ?: [0])
                ->orderBy('sequence')->pluck('label', 'id'),
            'statuses' => collect(CourseOffering::STATUSES),
        ];
    }

    /**
     * Is this lecturer currently TEACHING this allocation, on this Offering?
     *
     * A READ-ONLY CLASSIFICATION, not a gate.
     *
     * ── WHY IT IS EXPOSED AT ALL ────────────────────────────────────────────
     *
     * Because "who is allocated to this course" and "who may teach it right now"
     * are two different questions, and a header answering only the second says
     * "nobody" about a course whose lecturer starts tomorrow.
     *
     * A Course Home has to show the first without weakening the second. The way to
     * do that without a second rule is to REACH the existing one - so this delegates
     * to `allocationIsCurrent()` unchanged, and that private method remains the gate
     * `teachingActionsAllowed()` calls. Nothing about who may teach has changed, and
     * no date on any allocation is ever written.
     *
     * A caller that wants to DECIDE anything must not use this. It exists so a page
     * can label a name honestly: teaching now, starting on a date, or finished.
     */
    public function isAllocationCurrent(object $allocation, ?string $date = null): bool
    {
        return $this->allocationIsCurrent(null, $allocation, $date);
    }

    private function allocationIsCurrent(?User $lecturer, object $allocation, ?string $date = null): bool
    {
        $date ??= Carbon::today()->toDateString();
        if ($allocation->status !== CourseOfferingLecturerAllocation::STATUS_ACTIVE) {
            return false;
        }
        $startsOn = (string) $allocation->starts_on;
        // The ONLY condition an authorised System Tester may relax is this one:
        // today being before the allocation's agreed start date, on an Offering
        // that was deliberately early-started. Allocation status, tenant,
        // identity, Offering lifecycle and the end date are all still enforced,
        // and no date on the allocation is ever written. See SystemTesterAccess.
        if ($startsOn !== '' && $startsOn > $date) {
            // Resolved only in this exceptional branch, so the normal path is
            // unchanged and costs no extra query.
            $lecturer ??= $this->lecturerForAllocation($allocation);
            if (! $lecturer || ! $this->testerAccess->onlyPreStartDateBlocks($lecturer, $allocation, $date)) {
                return false;
            }
        }
        $endsOn = $allocation->ends_on === null ? null : (string) $allocation->ends_on;

        return $endsOn === null || $endsOn >= $date;
    }

    /** The allocated lecturer, resolved in the allocation's own tenant. */
    private function lecturerForAllocation(object $allocation): ?User
    {
        if (empty($allocation->user_id) || empty($allocation->school_id)) {
            return null;
        }

        return User::where('school_id', (int) $allocation->school_id)->whereKey((int) $allocation->user_id)->first();
    }

    private function isLecturer(User $user): bool
    {
        return $user->school_id !== null
            && $user->account_status !== 'disable'
            && (int) $user->role_id === \App\Support\Roles\SystemRole::TEACHER;
    }

    private function allocationsAvailable(): bool
    {
        return Schema::hasTable('course_offering_lecturer_allocations')
            && Schema::hasColumn('course_offering_lecturer_allocations', 'course_offering_id');
    }

    /**
     * Academic context for a set of offerings, set as real relations.
     *
     * CourseOffering's belongsTo relations carry `where('school_id', $this->school_id)`,
     * which Laravel evaluates on an attribute-less instance during eager loading
     * and therefore resolves to `school_id IS NULL`. These relations are loaded
     * explicitly instead, which is both correct and free of an N+1 query.
     */
    private function withAcademicContext(User $lecturer, Collection $offerings): Collection
    {
        if ($offerings->isEmpty()) {
            return $offerings;
        }
        $schoolId = (int) $lecturer->school_id;
        $ids = $offerings->pluck('id')->all();

        $subjects = Subject::query()->where('school_id', $schoolId)
            ->whereIn('id', $offerings->pluck('subject_id')->unique()->all())->get()->keyBy('id');
        $years = AcademicYear::query()->where('school_id', $schoolId)
            ->whereIn('id', $offerings->pluck('academic_year_id')->unique()->all())->get()->keyBy('id');
        $periods = AcademicPeriod::query()->where('school_id', $schoolId)
            ->whereIn('id', $offerings->pluck('academic_period_id')->unique()->all())->get()->keyBy('id');

        $context = $this->academicContext($lecturer, $ids);
        $counts = $this->confirmedCounts($lecturer, $ids);

        return $offerings->map(function (CourseOffering $offering) use ($context, $counts, $subjects, $years, $periods): CourseOffering {
            $offering->setRelation('subject', $subjects->get($offering->subject_id));
            $offering->setRelation('academicYear', $years->get($offering->academic_year_id));
            $offering->setRelation('academicPeriod', $periods->get($offering->academic_period_id));

            return $offering;
        });
    }

    /** Study Plan versions, Study Plan stages and Programmes per offering. */
    private function academicContext(User $lecturer, array $offeringIds): array
    {
        $empty = ['versions' => [], 'stages' => [], 'programmes' => []];
        if ($offeringIds === []
            || ! Schema::hasTable('course_offering_curriculum_memberships')
            || ! Schema::hasTable('curriculum_memberships')) {
            return $empty;
        }

        $rows = DB::table('course_offering_curriculum_memberships as link')
            ->join('curriculum_memberships as membership', function ($join) use ($lecturer): void {
                $join->on('membership.id', '=', 'link.curriculum_membership_id')
                    ->where('membership.school_id', '=', $lecturer->school_id);
            })
            ->join('curricula as curriculum', function ($join) use ($lecturer): void {
                $join->on('curriculum.id', '=', 'membership.curriculum_id')
                    ->where('curriculum.school_id', '=', $lecturer->school_id);
            })
            ->leftJoin('curriculum_stages as stage', function ($join) use ($lecturer): void {
                $join->on('stage.id', '=', 'membership.curriculum_stage_id')
                    ->where('stage.school_id', '=', $lecturer->school_id);
            })
            ->leftJoin('programmes as programme', function ($join) use ($lecturer): void {
                $join->on('programme.id', '=', 'curriculum.programme_id')
                    ->where('programme.school_id', '=', $lecturer->school_id);
            })
            ->where('link.school_id', $lecturer->school_id)
            ->whereIn('link.course_offering_id', $offeringIds)
            ->get(['link.course_offering_id', 'curriculum.version', 'stage.label as stage_label', 'programme.name as programme_name']);

        return [
            'versions' => $rows->groupBy('course_offering_id')->map(fn ($group) => $group->pluck('version')->filter()->unique()->values()),
            'stages' => $rows->groupBy('course_offering_id')->map(fn ($group) => $group->pluck('stage_label')->filter()->unique()->values()),
            'programmes' => $rows->groupBy('course_offering_id')->map(fn ($group) => $group->pluck('programme_name')->filter()->unique()->values()),
        ];
    }

    /** Confirmed registration counts, tenant scoped, one query for the list. */
    private function confirmedCounts(User $lecturer, array $offeringIds): array
    {
        if ($offeringIds === []
            || ! Schema::hasTable('course_registrations')
            || ! Schema::hasColumn('course_registrations', 'course_offering_id')) {
            return [];
        }

        return DB::table('course_registrations')
            ->where('school_id', $lecturer->school_id)
            ->whereIn('course_offering_id', $offeringIds)
            ->where('status', CourseRegistration::STATUS_CONFIRMED)
            ->groupBy('course_offering_id')
            ->pluck(DB::raw('count(*)'), 'course_offering_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
