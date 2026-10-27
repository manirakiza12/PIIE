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
    public function __construct(
        private CourseOfferingEligibility $eligibility,
        private StudentRegistrationConfirmationEligibility $financialEligibility,
        private CourseRegistrationFeeWaiverService $feeWaivers,
    ) {}

    public function eligible(CourseOffering $offering)
    {
        return $this->review($offering)['eligible'];
    }

    /**
     * Unregistered students with a current placement in a Study Plan linked to this Allocation — split by the shared
     * eligibility rule. Ineligible students carry an administrator-readable reason.
     */
    public function review(CourseOffering $offering, bool $includeClosed = false): array
    {
        $empty = ['eligible' => collect(), 'ineligible' => collect()];
        $schoolId = (int) $offering->school_id;
        if ((! $includeClosed && $offering->status !== CourseOffering::STATUS_OPEN)
            || ! Schema::hasTable('course_registrations')
            || ! Schema::hasColumn('course_registrations', 'course_offering_id')
            || ! Schema::hasColumn('course_registrations', 'curriculum_membership_id')) return $empty;

        $linkedStudyPlans = DB::table('course_offering_curriculum_memberships')
            ->where('school_id', $schoolId)->where('course_offering_id', $offering->id)
            ->distinct()->pluck('curriculum_id');
        if ($linkedStudyPlans->isEmpty()) return $empty;

        $alreadyRegistered = CourseRegistration::query()->where('school_id', $schoolId)
            ->where('course_offering_id', $offering->id)->pluck('student_id')->map(fn ($id) => (int) $id)->flip();
        $candidateIds = DB::table('student_curriculum_assignments')->where('school_id', $schoolId)
            ->whereNull('ended_at')->whereIn('curriculum_id', $linkedStudyPlans)->pluck('student_id')
            ->map(fn ($id) => (int) $id)->unique();
        $students = User::query()->where('school_id', $schoolId)->where('role_id', 7)->whereIn('id', $candidateIds->all())
            ->where(fn ($q) => $q->whereNull('account_status')->orWhere('account_status', '!=', 'disable'))
            ->with(['studentProfile' => fn ($q) => $q->where('school_id', $schoolId)])
            ->orderBy('name')->get()
            ->reject(fn (User $student) => $alreadyRegistered->has((int) $student->id));
        $stageLabels = DB::table('curriculum_stages')->where('school_id', $schoolId)->pluck('label', 'id');

        $reviewed = $students->map(function (User $student) use ($offering, $schoolId, $stageLabels): User {
            $result = $this->eligibility->evaluate($offering, (int) $student->id);
            $financeSummary = $this->financialEligibility->summary($student);
            $financeEligible = $financeSummary['eligible'];
            $activeWaiver = $this->feeWaivers->activeFor($student);
            $stageId = $result->assignment?->entry_curriculum_stage_id;
            $student->setAttribute('roster_stage', $stageId ? $stageLabels->get($stageId) : null);
            $student->setAttribute('roster_fee_waived', (bool) $activeWaiver);
            $student->setAttribute('roster_can_waive_fees', $result->eligible && ! $financeEligible
                && $this->feeWaivers->mayWaive($student, $offering, $result->assignment));
            $student->setAttribute('roster_eligible', $result->eligible && $financeEligible);
            $student->setAttribute('roster_reason', ! $result->eligible
                ? $result->message
                : ($financeEligible ? null : $this->financialEligibility->blocker($student, $financeSummary)));
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
            return 'This Course Allocation is not open for new registrations. Next step: the academic office can review or reopen it if appropriate.';
        }
        $result = $this->eligibility->evaluate($offering, $studentId);
        if (! $result->eligible) return $result->message;
        $student = User::query()->where('school_id', $offering->school_id)->where('role_id', 7)->find($studentId);
        if (! $student) return 'This student account could not be found in this institution.';
        $finance = $this->financialEligibility->summary($student);
        return $finance['eligible'] ? null : $this->financialEligibility->blocker($student, $finance);
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
