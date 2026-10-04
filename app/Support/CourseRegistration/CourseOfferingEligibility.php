<?php

namespace App\Support\CourseRegistration;

use App\Models\CourseOffering;
use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use App\Models\StudentCurriculumAssignment;
use App\Support\Curriculum\StudentCurriculumAssignmentService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The single academic eligibility rule for Course Offering registration.
 *
 * Governed academic placement (StudentCurriculumAssignment) is authoritative:
 * Programme, Study Plan, the explicitly recorded Study Plan Stage and the
 * Academic Year that stage was recorded for. The Programme Cohort membership
 * that produced the placement must still be current and agree with it; it is
 * a consistency boundary, never a substitute for placement.
 *
 * Progression is not inferred: a placement's stage governs only its entry
 * Academic Year. Later years need a recorded progression (not yet modelled),
 * so they are refused. Retakes, carry-forward, exemptions and credit
 * recognition likewise need their own governed records before they relax
 * this rule.
 *
 * Used by the admin roster and registration, student discovery and
 * self-registration, and registration confirmation (which gates Live Class
 * access downstream).
 */
class CourseOfferingEligibility
{
    public const PLACEMENT_CODES = [
        'student_inactive', 'placement_missing', 'placement_review', 'placement_ended',
        'study_plan_unavailable', 'programme_mismatch', 'placement_incomplete',
        'cohort_not_current', 'cohort_mismatch', 'progression_not_recorded',
    ];

    public function __construct(private StudentCurriculumAssignmentService $assignments)
    {
    }

    /** Student-level checks for one Academic Year, independent of any Offering. */
    public function placement(int $schoolId, int $studentId, int $academicYearId, bool $lock = false, bool $allowRetiredStudyPlan = false): CourseOfferingEligibilityResult
    {
        $student = DB::table('users')->where('school_id', $schoolId)->where('id', $studentId)->first(['id', 'role_id', 'account_status']);
        if (! $student || (int) $student->role_id !== 7 || $student->account_status === 'disable') {
            return CourseOfferingEligibilityResult::denied('student_inactive', 'This student account is not active in this institution.');
        }
        $year = DB::table('academic_years')->where('school_id', $schoolId)->where('id', $academicYearId)->first(['id', 'label']);
        if (! $year) {
            return CourseOfferingEligibilityResult::denied('placement_review', 'The Academic Year of this Course Offering could not be found.');
        }

        try {
            $assignment = $this->assignments->assignmentForAcademicYear($schoolId, $studentId, $academicYearId, $lock);
        } catch (DomainException) {
            return CourseOfferingEligibilityResult::denied('placement_review', 'The student\'s academic placement needs review by the academic office.');
        }
        if (! $assignment) {
            return CourseOfferingEligibilityResult::denied('placement_missing', "The student has no academic placement for {$year->label}.");
        }
        if ($assignment->ended_at !== null) {
            return CourseOfferingEligibilityResult::denied('placement_ended', 'The student\'s academic placement for this Academic Year is no longer current.', $assignment);
        }

        $curriculum = Curriculum::query()->where('school_id', $schoolId)->whereKey($assignment->curriculum_id)->first();
        $allowedStatuses = $allowRetiredStudyPlan ? ['approved', 'retired'] : ['approved'];
        if (! $curriculum || (int) $curriculum->programme_id !== (int) $assignment->programme_id
            || ! in_array($curriculum->status, $allowedStatuses, true)
            || ! DB::table('programmes')->where('school_id', $schoolId)->where('id', $assignment->programme_id)->exists()) {
            return CourseOfferingEligibilityResult::denied('study_plan_unavailable', 'The student\'s Programme Study Plan is not approved for registration.', $assignment);
        }

        $profileProgrammeId = DB::table('student_profiles')->where('school_id', $schoolId)->where('user_id', $studentId)->value('programme_id');
        if ($profileProgrammeId !== null && (int) $profileProgrammeId !== (int) $assignment->programme_id) {
            return CourseOfferingEligibilityResult::denied('programme_mismatch', 'The Programme on the student\'s profile does not match their academic placement.', $assignment);
        }

        $stage = $assignment->entry_curriculum_stage_id === null ? null : DB::table('curriculum_stages')
            ->where('school_id', $schoolId)->where('curriculum_id', $assignment->curriculum_id)
            ->where('id', $assignment->entry_curriculum_stage_id)->first(['id', 'label']);
        if (! $stage) {
            return CourseOfferingEligibilityResult::denied('placement_incomplete', 'Academic placement is incomplete: no Study Plan stage has been recorded for this student.', $assignment);
        }

        $cohortResult = $this->cohortConsistency($schoolId, $studentId, $assignment);
        if ($cohortResult) {
            return $cohortResult;
        }

        if ((int) $assignment->entry_academic_year_id !== $academicYearId) {
            $placedYear = DB::table('academic_years')->where('school_id', $schoolId)->where('id', $assignment->entry_academic_year_id)->value('label');
            return CourseOfferingEligibilityResult::denied('progression_not_recorded', "The student was placed in {$stage->label} for {$placedYear}; their Year of Study for {$year->label} has not been recorded yet.", $assignment);
        }

        return CourseOfferingEligibilityResult::eligible($assignment, $curriculum);
    }

    /**
     * Full rule for one student and one Offering. When $membershipId is given it
     * must equal the placement-resolved Study Plan membership.
     */
    public function evaluate(CourseOffering $offering, int $studentId, ?int $membershipId = null, bool $lock = false, bool $allowRetiredStudyPlan = false): CourseOfferingEligibilityResult
    {
        $schoolId = (int) $offering->school_id;
        $placement = $this->placement($schoolId, $studentId, (int) $offering->academic_year_id, $lock, $allowRetiredStudyPlan);
        if (! $placement->eligible) {
            return $placement;
        }
        $assignment = $placement->assignment;

        $period = DB::table('academic_periods')->where('school_id', $schoolId)
            ->where('academic_year_id', $offering->academic_year_id)->where('id', $offering->academic_period_id)
            ->first(['type', 'sequence', 'label']);
        if (! $period || ! DB::table('subjects')->where('school_id', $schoolId)->where('id', $offering->subject_id)->exists()) {
            return CourseOfferingEligibilityResult::denied('offering_invalid', 'This Course Offering needs academic review before students can register.', $assignment);
        }

        $linked = DB::table('course_offering_curriculum_memberships as x')
            ->join('curriculum_memberships as m', function ($join) use ($schoolId): void {
                $join->on('m.id', '=', 'x.curriculum_membership_id')->where('m.school_id', '=', $schoolId);
            })
            ->leftJoin('curriculum_stages as st', function ($join) use ($schoolId): void {
                $join->on('st.id', '=', 'm.curriculum_stage_id')->where('st.school_id', '=', $schoolId);
            })
            ->where('x.school_id', $schoolId)->where('x.course_offering_id', $offering->id)
            ->where('x.curriculum_id', $assignment->curriculum_id)->where('m.curriculum_id', $assignment->curriculum_id)
            ->where('x.subject_id', $offering->subject_id)->where('m.subject_id', $offering->subject_id)
            ->get(['m.id', 'm.curriculum_stage_id', 'm.period_type', 'm.period_sequence', 'st.label as stage_label']);
        if ($linked->isEmpty()) {
            return CourseOfferingEligibilityResult::denied('not_in_study_plan', 'This Course Unit is not part of the student\'s current Study Plan for this Course Offering.', $assignment);
        }

        $inPeriod = $linked->filter(fn ($m) => $m->period_type === $period->type && (int) $m->period_sequence === (int) $period->sequence);
        if ($inPeriod->isEmpty()) {
            return CourseOfferingEligibilityResult::denied('period_mismatch', "This Course Unit is not scheduled for {$period->label} in the student's Study Plan.", $assignment);
        }

        $inStage = $inPeriod->filter(fn ($m) => (int) $m->curriculum_stage_id === (int) $assignment->entry_curriculum_stage_id);
        if ($inStage->isEmpty()) {
            $unitStage = $inPeriod->pluck('stage_label')->filter()->unique()->implode(' / ') ?: 'another stage';
            $studentStage = DB::table('curriculum_stages')->where('school_id', $schoolId)->where('id', $assignment->entry_curriculum_stage_id)->value('label');
            return CourseOfferingEligibilityResult::denied('stage_mismatch', "Not eligible — this Course Unit belongs to {$unitStage} of the student's Study Plan; the student is placed in {$studentStage}.", $assignment);
        }
        if ($inStage->count() !== 1) {
            return CourseOfferingEligibilityResult::denied('ambiguous_study_plan', 'This Course Unit appears more than once in the student\'s Study Plan stage; the academic office must review the Study Plan.', $assignment);
        }

        $resolvedId = (int) $inStage->first()->id;
        if ($membershipId !== null && $membershipId !== $resolvedId) {
            return CourseOfferingEligibilityResult::denied('membership_conflict', 'The selected Study Plan entry no longer matches the student\'s academic placement. Refresh the page and try again.', $assignment);
        }

        $membership = CurriculumMembership::query()->where('school_id', $schoolId)
            ->where('curriculum_id', $assignment->curriculum_id)->whereKey($resolvedId)->first();

        return CourseOfferingEligibilityResult::eligible($assignment, $placement->curriculum, $membership);
    }

    private function cohortConsistency(int $schoolId, int $studentId, StudentCurriculumAssignment $assignment): ?CourseOfferingEligibilityResult
    {
        $membership = $assignment->programme_cohort_membership_id === null ? null : DB::table('programme_cohort_memberships')
            ->where('school_id', $schoolId)->where('student_id', $studentId)
            ->where('id', $assignment->programme_cohort_membership_id)->first();
        if (! $membership) {
            return CourseOfferingEligibilityResult::denied('placement_incomplete', 'Academic placement is incomplete: the student\'s placement is not linked to a Programme Cohort.', $assignment);
        }
        if ($membership->ended_at !== null || $membership->status !== 'active') {
            return CourseOfferingEligibilityResult::denied('cohort_not_current', 'The student\'s Programme Cohort membership is not currently active (it may be deferred, transferred, withdrawn or completed).', $assignment);
        }
        $cohort = DB::table('programme_cohorts')->where('school_id', $schoolId)->where('id', $membership->programme_cohort_id)->first();
        if (! $cohort || $cohort->status !== 'active'
            || (int) $cohort->programme_id !== (int) $assignment->programme_id
            || (int) $cohort->curriculum_id !== (int) $assignment->curriculum_id
            || (int) $cohort->entry_academic_year_id !== (int) $assignment->entry_academic_year_id) {
            return CourseOfferingEligibilityResult::denied('cohort_mismatch', 'The student\'s Programme Cohort does not match the current academic placement.', $assignment);
        }

        return null;
    }
}
