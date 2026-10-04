<?php

namespace App\Support\CourseRegistration;

use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\Programme;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tenant-scoped administrator roster, derived from the shared CourseOfferingEligibility rule. */
class CourseOfferingRoster
{
    public function __construct(private CourseOfferingEligibility $eligibility) {}

    public function eligible(CourseOffering $offering)
    {
        return $this->review($offering)['eligible'];
    }

    /**
     * Unregistered students with a current placement in, or current membership of a
     * cohort following, a Study Plan linked to this Offering — split by the shared
     * eligibility rule. Ineligible students carry an administrator-readable reason.
     */
    public function review(CourseOffering $offering, ?int $cohortId = null): array
    {
        $empty = ['eligible' => collect(), 'ineligible' => collect()];
        $schoolId = (int) $offering->school_id;
        if ($offering->status !== CourseOffering::STATUS_OPEN
            || ! Schema::hasTable('course_registrations')
            || ! Schema::hasColumn('course_registrations', 'course_offering_id')
            || ! Schema::hasColumn('course_registrations', 'curriculum_membership_id')) return $empty;

        $linkedStudyPlans = DB::table('course_offering_curriculum_memberships')
            ->where('school_id', $schoolId)->where('course_offering_id', $offering->id)
            ->distinct()->pluck('curriculum_id');
        if ($linkedStudyPlans->isEmpty()) return $empty;

        $alreadyRegistered = CourseRegistration::query()->where('school_id', $schoolId)
            ->where('course_offering_id', $offering->id)->pluck('student_id')->map(fn ($id) => (int) $id)->flip();
        $currentMemberships = ! Schema::hasTable('programme_cohort_memberships') ? collect() : DB::table('programme_cohort_memberships as pcm')
            ->join('programme_cohorts as pc', function ($join) use ($schoolId): void {
                $join->on('pc.id', '=', 'pcm.programme_cohort_id')->where('pc.school_id', '=', $schoolId);
            })
            ->where('pcm.school_id', $schoolId)->whereNull('pcm.ended_at')->whereIn('pcm.status', ['active', 'deferred'])
            ->get(['pcm.student_id', 'pcm.programme_cohort_id', 'pc.name as cohort_name', 'pc.curriculum_id'])
            ->keyBy(fn ($row) => (int) $row->student_id);
        $candidateIds = DB::table('student_curriculum_assignments')->where('school_id', $schoolId)
            ->whereNull('ended_at')->whereIn('curriculum_id', $linkedStudyPlans)->pluck('student_id')
            ->merge($currentMemberships->filter(fn ($row) => $linkedStudyPlans->contains($row->curriculum_id))->keys())
            ->map(fn ($id) => (int) $id)->unique();
        if ($cohortId !== null) {
            $candidateIds = $candidateIds->filter(fn ($id) => (int) ($currentMemberships->get($id)?->programme_cohort_id) === $cohortId);
        }
        $students = User::query()->where('school_id', $schoolId)->where('role_id', 7)->whereIn('id', $candidateIds->all())
            ->where(fn ($q) => $q->whereNull('account_status')->orWhere('account_status', '!=', 'disable'))
            ->with(['studentProfile' => fn ($q) => $q->where('school_id', $schoolId)])
            ->orderBy('name')->get()
            ->reject(fn (User $student) => $alreadyRegistered->has((int) $student->id));
        $stageLabels = DB::table('curriculum_stages')->where('school_id', $schoolId)->pluck('label', 'id');

        $reviewed = $students->map(function (User $student) use ($offering, $schoolId, $currentMemberships, $stageLabels): User {
            $result = $this->eligibility->evaluate($offering, (int) $student->id);
            $student->setAttribute('roster_cohort', $currentMemberships->get((int) $student->id)?->cohort_name);
            $stageId = $result->assignment?->entry_curriculum_stage_id;
            $student->setAttribute('roster_stage', $stageId ? $stageLabels->get($stageId) : null);
            $student->setAttribute('roster_eligible', $result->eligible);
            $student->setAttribute('roster_reason', $result->eligible ? null : $result->message);
            if ($result->eligible) {
                $curriculum = $result->curriculum;
                $curriculum->setRelation('programme', Programme::query()->where('school_id', $schoolId)->whereKey($curriculum->programme_id)->first());
                $student->setAttribute('roster_assignment', $result->assignment);
                $student->setAttribute('roster_membership_id', (int) $result->membership->id);
                $student->setAttribute('roster_curriculum', $curriculum);
            }
            return $student;
        });

        return [
            'eligible' => $reviewed->where('roster_eligible', true)->values(),
            'ineligible' => $reviewed->where('roster_eligible', false)->values(),
        ];
    }

    /** Administrator-facing reason why one student cannot be registered, or null when eligible. */
    public function ineligibilityReason(CourseOffering $offering, int $studentId): ?string
    {
        if ($offering->status !== CourseOffering::STATUS_OPEN) {
            return 'Students can be registered only while the Course Offering is open.';
        }
        $result = $this->eligibility->evaluate($offering, $studentId);

        return $result->eligible ? null : $result->message;
    }

    public function registered(CourseOffering $offering)
    {
        $schoolId = (int) $offering->school_id;
        if (! Schema::hasTable('course_registrations')
            || ! Schema::hasColumn('course_registrations', 'course_offering_id')
            || ! Schema::hasColumn('course_registrations', 'curriculum_membership_id')) return collect();
        return CourseRegistration::query()->where('course_registrations.school_id', $schoolId)
            ->where('course_registrations.course_offering_id', $offering->id)
            ->join('users', function ($join) use ($schoolId): void {
                $join->on('users.id', '=', 'course_registrations.student_id')->where('users.school_id', '=', $schoolId);
            })->leftJoin('student_profiles as sp', function ($join) use ($schoolId): void {
                $join->on('sp.user_id', '=', 'users.id')->where('sp.school_id', '=', $schoolId);
            })->leftJoin('curriculum_memberships as m', function ($join) use ($schoolId): void {
                $join->on('m.id', '=', 'course_registrations.curriculum_membership_id')->where('m.school_id', '=', $schoolId);
            })->leftJoin('curricula as c', function ($join) use ($schoolId): void {
                $join->on('c.id', '=', 'm.curriculum_id')->where('c.school_id', '=', $schoolId);
            })->leftJoin('programmes as p', function ($join) use ($schoolId): void {
                $join->on('p.id', '=', 'c.programme_id')->where('p.school_id', '=', $schoolId);
            })->select([
                'course_registrations.*', 'users.name as student_name', 'users.code as registration_number',
                'p.name as programme_name', 'p.code as programme_code', 'c.version as curriculum_version',
                'sp.year_of_study',
            ])->orderBy('users.name')->get();
    }
}
