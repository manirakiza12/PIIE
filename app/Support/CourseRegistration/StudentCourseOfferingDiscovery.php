<?php

namespace App\Support\CourseRegistration;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\CourseOffering;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\StudentCurriculumAssignment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\AcademicContext;
use App\Support\Curriculum\StudentCurriculumAssignmentService;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Tenant-scoped student view model for assignment-governed Offering registration. */
class StudentCourseOfferingDiscovery
{
    public function __construct(
        private AcademicContext $academicContext,
        private StudentCurriculumAssignmentService $assignments,
        private StudentRegistrationConfirmationEligibility $confirmationEligibility,
        private CourseOfferingEligibility $eligibility,
    ) {
    }

    public function discover(User $student): array
    {
        $schoolId = (int) $student->school_id;
        $school = School::query()->whereKey($schoolId)->first();
        if (! $school || (int) $student->role_id !== 7 || (string) $student->school_id !== (string) $schoolId) {
            return $this->empty('configuration', 'Your academic registration context could not be verified. Contact the academic office.');
        }
        $registrations = $this->registrations($schoolId, (int) $student->id);
        $financeEligible = $this->confirmationEligibility->allows($student);

        // Attached HERE, on the one exit path every early return shares, so a card
        // can name its lecturer even when the academic record needs review. Doing it
        // in the main path only would leave the lecturer blank on exactly the cards
        // a student most needs a name on.
        //
        // The SAME `activeLecturers()` definition the available Offerings already use
        // — active allocation, started, not ended — so a card never names a lecturer
        // who has already left the Offering.
        $registrationLecturers = $this->activeLecturers(
            $schoolId,
            $registrations->pluck('offering_id')->map(fn ($id) => (int) $id)->unique()->values()->all()
        );
        $registrations->each(function ($registration) use ($registrationLecturers): void {
            $team = $registrationLecturers->get((int) $registration->offering_id, collect());
            $registration->teaching_team = $team;
            $registration->primary_lecturer = $team->firstWhere('role', 'primary_lecturer')?->name;
        });

        $year = $this->academicContext->currentYear($school);
        if (! $year || (int) $year->school_id !== $schoolId) {
            return $this->empty('no_year', 'Your current Academic Year has not been configured. Contact the academic office.', null, null, $registrations, $financeEligible);
        }
        $period = $this->academicContext->currentPeriod($school);
        if (! $period || (int) $period->school_id !== $schoolId || (int) $period->academic_year_id !== (int) $year->id) {
            return $this->empty('no_period', 'Your current academic period has not been configured. Contact the academic office.', $year, null, $registrations, $financeEligible);
        }

        try {
            $assignment = $this->assignments->assignmentForAcademicYear($schoolId, (int) $student->id, (int) $year->id);
        } catch (DomainException) {
            return $this->empty('integrity_review', 'Your academic registration record needs review. Contact the academic office.', $year, $period, $registrations, $financeEligible);
        }
        if (! $assignment) {
            return $this->empty('assignment_missing', 'Your academic programme assignment is not available for this year. Contact the academic office.', $year, $period, $registrations, $financeEligible);
        }

        $profile = StudentProfile::query()->where('school_id', $schoolId)->where('user_id', $student->id)->first();
        if ($profile?->programme_id !== null && (int) $profile->programme_id !== (int) $assignment->programme_id) {
            return $this->empty('programme_mismatch', 'Your student record needs an academic update before you can register.', $year, $period, $registrations, $financeEligible);
        }

        $curriculum = Curriculum::query()->where('school_id', $schoolId)
            ->where('programme_id', $assignment->programme_id)->whereKey($assignment->curriculum_id)->first();
        if (! $curriculum || $curriculum->status !== 'approved') {
            return $this->empty('curriculum_unavailable', 'Your academic programme assignment needs review. Contact the academic office.', $year, $period, $registrations, $financeEligible);
        }

        $placement = $this->eligibility->placement($schoolId, (int) $student->id, (int) $year->id);
        if (! $placement->eligible) {
            return $this->empty('placement_incomplete', $this->studentPlacementMessage($placement->code, $year->label), $year, $period, $registrations, $financeEligible);
        }

        $candidateRows = DB::table('course_offering_curriculum_memberships as x')
            ->join('course_offerings as o', function ($join) use ($schoolId): void {
                $join->on('o.id', '=', 'x.course_offering_id')->on('o.school_id', '=', 'x.school_id');
            })
            ->join('curriculum_memberships as m', function ($join) use ($schoolId): void {
                $join->on('m.id', '=', 'x.curriculum_membership_id')->on('m.school_id', '=', 'x.school_id');
            })
            ->join('subjects as s', function ($join) use ($schoolId): void {
                $join->on('s.id', '=', 'o.subject_id')->on('s.school_id', '=', 'o.school_id');
            })
            ->join('academic_years as ay', function ($join) use ($schoolId): void {
                $join->on('ay.id', '=', 'o.academic_year_id')->on('ay.school_id', '=', 'o.school_id');
            })
            ->join('academic_periods as ap', function ($join) use ($schoolId): void {
                $join->on('ap.id', '=', 'o.academic_period_id')->on('ap.school_id', '=', 'o.school_id');
            })
            ->where('x.school_id', $schoolId)->where('x.curriculum_id', $curriculum->id)
            ->where('o.school_id', $schoolId)->where('o.status', 'open')
            ->where('o.academic_year_id', $year->id)->where('o.academic_period_id', $period->id)
            ->where('ap.academic_year_id', $year->id)
            ->where('m.curriculum_id', $curriculum->id)->whereColumn('m.subject_id', 'o.subject_id')
            ->whereColumn('x.subject_id', 'o.subject_id')->where('ay.id', $year->id)
            ->whereNotExists(function ($query) use ($schoolId, $student): void {
                $query->selectRaw('1')->from('course_registrations as cr')
                    ->whereColumn('cr.course_offering_id', 'o.id')
                    ->where('cr.school_id', $schoolId)->where('cr.student_id', $student->id);
            })
            ->select([
                'o.id as offering_id', 'o.subject_id', 'o.reference', 'o.status as offering_status',
                'o.academic_year_id', 'o.academic_period_id', 'x.curriculum_membership_id',
                's.code as subject_code', 's.name as subject_name',
                'm.credits as membership_credits', 'm.classification as membership_classification',
                'ay.label as academic_year_label', 'ap.label as academic_period_label', 'ap.type as academic_period_type',
            ])->orderBy('s.name')->orderBy('o.reference');

        // Candidate rows are narrowed by the shared eligibility rule (Study Plan
        // stage, period and cohort), which also resolves the single membership.
        $rows = $candidateRows->get();
        $candidateOfferings = CourseOffering::query()->where('school_id', $schoolId)
            ->whereIn('id', $rows->pluck('offering_id')->unique()->all())->get()->keyBy('id');
        $ambiguousOfferingIds = collect();
        $offerings = $rows->groupBy('offering_id')->map(function ($items, $offeringId) use ($candidateOfferings, $student, $ambiguousOfferingIds) {
            $offering = $candidateOfferings->get((int) $offeringId);
            $result = $offering ? $this->eligibility->evaluate($offering, (int) $student->id) : null;
            if ($result?->code === 'ambiguous_study_plan') {
                $ambiguousOfferingIds->push((int) $offeringId);
            }
            return $result?->eligible ? $items->firstWhere('curriculum_membership_id', $result->membership->id) : null;
        })->filter()->values();

        $lecturerNames = $this->activeLecturers($schoolId, $offerings->pluck('offering_id')->all());
        $offerings->each(function ($offering) use ($lecturerNames): void {
            $offering->teaching_team = $lecturerNames->get((int) $offering->offering_id, collect());
            $offering->primary_lecturer = $offering->teaching_team->firstWhere('role', 'primary_lecturer')?->name;
        });

        return [
            'state' => $ambiguousOfferingIds->isNotEmpty() ? 'integrity_review' : ($offerings->isEmpty() ? 'no_offerings' : 'ready'),
            'message' => $ambiguousOfferingIds->isNotEmpty()
                ? 'Some Course Offering records need academic review. Other valid Offerings remain available.'
                : ($offerings->isEmpty() ? 'No Course Offering is currently available for your programme in this period.' : null),
            'year' => $year,
            'period' => $period,
            'offerings' => $offerings,
            'registrations' => $registrations,
            'pending' => $registrations->where('status', 'registered')->values(),
            'confirmed' => $registrations->where('status', 'confirmed')->values(),
            'history' => $registrations->where('status', 'dropped')->values(),
            'finance_eligible' => $financeEligible,
            'assignment_id' => (int) $assignment->id,
        ];
    }

    public function assignmentMembershipForOffering(User $student, int $offeringId): ?int
    {
        $view = $this->discover($student);
        if (! in_array($view['state'], ['ready', 'no_offerings', 'integrity_review'], true)) {
            return null;
        }
        $offering = $view['offerings']->firstWhere('offering_id', $offeringId);
        return $offering ? (int) $offering->curriculum_membership_id : null;
    }

    private function studentPlacementMessage(string $code, string $yearLabel): string
    {
        return match ($code) {
            'progression_not_recorded' => "Your Year of Study for {$yearLabel} has not been recorded yet. Contact the academic office.",
            'cohort_not_current', 'cohort_mismatch' => 'Your Programme Cohort record needs review before you can register. Contact the academic office.',
            'placement_ended' => 'Your academic placement is no longer current. Contact the academic office.',
            default => 'Your academic placement is not complete yet. Contact the academic office.',
        };
    }

    private function activeLecturers(int $schoolId, array $offeringIds)
    {
        if ($offeringIds === []) return collect();

        return DB::table('course_offering_lecturer_allocations as a')
            ->join('users as u', function ($join) use ($schoolId): void {
                $join->on('u.id', '=', 'a.user_id')->where('u.school_id', '=', $schoolId);
            })
            ->where('a.school_id', $schoolId)->whereIn('a.course_offering_id', $offeringIds)
            ->where('a.status', 'active')->where('a.starts_on', '<=', now()->toDateString())
            ->where(fn ($query) => $query->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', now()->toDateString()))
            ->select('a.course_offering_id', 'a.role', 'u.name')
            ->orderByRaw("CASE WHEN a.role = 'primary_lecturer' THEN 0 ELSE 1 END")
            ->orderBy('u.name')->get()->groupBy('course_offering_id');
    }

    private function registrations(int $schoolId, int $studentId)
    {
        return DB::table('course_registrations as cr')
            ->join('course_offerings as o', function ($join) use ($schoolId): void {
                $join->on('o.id', '=', 'cr.course_offering_id')->where('o.school_id', '=', $schoolId);
            })
            ->join('subjects as s', function ($join) use ($schoolId): void {
                $join->on('s.id', '=', 'cr.subject_id')->where('s.school_id', '=', $schoolId);
            })
            ->join('academic_years as ay', function ($join) use ($schoolId): void {
                $join->on('ay.id', '=', 'o.academic_year_id')->where('ay.school_id', '=', $schoolId);
            })
            ->join('academic_periods as ap', function ($join) use ($schoolId): void {
                $join->on('ap.id', '=', 'o.academic_period_id')->where('ap.school_id', '=', $schoolId);
            })
            ->leftJoin('curriculum_memberships as m', function ($join) use ($schoolId): void {
                $join->on('m.id', '=', 'cr.curriculum_membership_id')->where('m.school_id', '=', $schoolId);
            })
            ->where('cr.school_id', $schoolId)->where('cr.student_id', $studentId)->whereNotNull('cr.course_offering_id')
            ->select([
                'cr.id', 'cr.status', 'cr.registered_credits', 'cr.registered_classification', 'cr.created_at as registered_at',
                'cr.course_offering_id as offering_id', 'o.reference', 'o.status as offering_status',
                's.code as subject_code', 's.name as subject_name', 'ay.label as academic_year_label',
                'ap.label as academic_period_label', 'ap.type as academic_period_type',
                'm.credits as current_membership_credits', 'm.classification as current_membership_classification',
            ])->orderByDesc('cr.created_at')->orderByDesc('cr.id')->get();
    }

    private function empty(string $state, string $message, ?AcademicYear $year = null, ?AcademicPeriod $period = null, $registrations = null, bool $financeEligible = false): array
    {
        $registrations ??= collect();
        return [
            'state' => $state, 'message' => $message, 'year' => $year, 'period' => $period,
            'offerings' => collect(), 'registrations' => $registrations,
            'pending' => $registrations->where('status', 'registered')->values(),
            'confirmed' => $registrations->where('status', 'confirmed')->values(),
            'history' => $registrations->where('status', 'dropped')->values(), 'finance_eligible' => $financeEligible,
            'assignment_id' => null,
        ];
    }
}
