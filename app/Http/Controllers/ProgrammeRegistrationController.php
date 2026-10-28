<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Models\Curriculum;
use App\Models\User;
use App\Support\CourseRegistration\CourseOfferingRegistrationBatch;
use App\Support\CourseRegistration\CourseOfferingRoster;
use App\Support\Curriculum\StudentCurriculumAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProgrammeRegistrationController extends Controller
{
    private function context(Request $request): array
    {
        $request->validate(['student_search' => 'nullable|string|max:100', 'student_id' => 'nullable|integer|min:1']);
        $schoolId = (int) $request->user()->school_id;
        $school = DB::table('schools')->where('id', $schoolId)->first();
        $studentSearch = trim((string) $request->input('student_search', ''));
        $selectedStudent = $request->filled('student_id')
            ? User::where('school_id', $schoolId)->where('role_id', 7)->findOrFail($request->integer('student_id'))
            : null;
        $selectedPlacement = $selectedStudent
            ? DB::table('student_curriculum_assignments')->where('school_id', $schoolId)->where('student_id', $selectedStudent->id)->whereNull('ended_at')->first()
            : null;
        $selectedProfile = $selectedStudent
            ? DB::table('student_profiles')->where('school_id', $schoolId)->where('user_id', $selectedStudent->id)->first()
            : null;
        $programmes = DB::table('programmes')->where('school_id', $schoolId)->orderBy('name')->get(['id', 'name', 'code']);
        $programmeId = $request->filled('programme_id') ? $request->integer('programme_id')
            : ($selectedPlacement->programme_id ?? $selectedProfile->programme_id ?? null);
        $programme = $programmes->firstWhere('id', $programmeId);
        $curricula = Curriculum::where('school_id', $schoolId)->where('programme_id', $programme?->id ?? 0)
            ->where('status', 'approved')->orderByDesc('id')->get();
        $curriculum = $request->filled('curriculum_id') ? $curricula->firstWhere('id', $request->integer('curriculum_id'))
            : ($curricula->firstWhere('id', $selectedPlacement->curriculum_id ?? null) ?? ($curricula->count() === 1 ? $curricula->first() : null));
        $stages = DB::table('curriculum_stages')->where('school_id', $schoolId)->where('curriculum_id', $curriculum?->id ?? 0)->orderBy('sequence')->get();
        $stage = $request->filled('stage_id') ? $stages->firstWhere('id', $request->integer('stage_id'))
            : ($stages->firstWhere('id', $selectedPlacement->entry_curriculum_stage_id ?? null)
                ?? ($stages->firstWhere('sequence', $selectedProfile->year_of_study ?? null) ?? ($stages->count() === 1 ? $stages->first() : null)));
        $years = DB::table('academic_years')->where('school_id', $schoolId)->orderByDesc('start_date')->get();
        $activeYears = $years->where('status', 'active');
        $year = $request->filled('academic_year_id') ? $years->firstWhere('id', $request->integer('academic_year_id'))
            : ($activeYears->firstWhere('id', $school->current_academic_year_id ?? null) ?? $activeYears->first());
        $periods = DB::table('academic_periods')->where('school_id', $schoolId)->where('academic_year_id', $year?->id ?? 0)->orderBy('sequence')->get();
        $activePeriods = $periods->where('status', 'active');
        $period = $request->filled('period_id') ? $periods->firstWhere('id', $request->integer('period_id'))
            : ($activePeriods->firstWhere('id', $school->current_academic_period_id ?? null)
                ?? ($activePeriods->count() === 1 ? $activePeriods->first() : null));
        $context = compact('schoolId', 'programmes', 'programme', 'curricula', 'curriculum', 'stages', 'stage', 'years', 'year', 'periods', 'period', 'studentSearch', 'selectedStudent');
        $context['ready'] = $programme && $curriculum && $stage && $year && $period;
        $context['page'] = max(1, $request->integer('page', 1));
        return $context;
    }

    private function offerings(array $c)
    {
        return CourseOffering::where('school_id', $c['schoolId'])->where('academic_year_id', $c['year']->id)
            ->where('academic_period_id', $c['period']->id)->whereIn('id', function ($q) use ($c) {
                $q->select('x.course_offering_id')->from('course_offering_curriculum_memberships as x')
                    ->join('curriculum_memberships as m', 'm.id', '=', 'x.curriculum_membership_id')
                    ->where('x.school_id', $c['schoolId'])->where('m.school_id', $c['schoolId'])
                    ->where('x.curriculum_id', $c['curriculum']->id)->where('m.curriculum_id', $c['curriculum']->id)
                    ->where('m.curriculum_stage_id', $c['stage']->id)
                    ->where('m.period_type', $c['period']->type)->where('m.period_sequence', $c['period']->sequence)
                    ->whereColumn('m.subject_id', 'x.subject_id');
            })->orderBy('reference')->get();
    }

    /** Every curriculum unit in the selected Programme Year and semester, whether allocated yet or not. */
    private function curriculumUnits(array $c)
    {
        return DB::table('curriculum_memberships as m')
            ->join('subjects as s', function ($join) use ($c): void {
                $join->on('s.id', '=', 'm.subject_id')->where('s.school_id', '=', $c['schoolId']);
            })
            ->where('m.school_id', $c['schoolId'])->where('m.curriculum_id', $c['curriculum']->id)
            ->where('m.curriculum_stage_id', $c['stage']->id)
            ->where('m.period_type', $c['period']->type)->where('m.period_sequence', $c['period']->sequence)
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('curriculum_memberships', 'archived_at'), fn ($query) => $query->whereNull('m.archived_at'))
            ->orderBy('m.sequence')->orderBy('s.code')->get([
                'm.id as membership_id', 'm.subject_id', 'm.classification', 'm.credits', 's.code', 's.name',
            ]);
    }

    private function students(array $c)
    {
        return User::where('school_id', $c['schoolId'])->where('role_id', 7)
            ->where(function ($q) use ($c) {
                $q->whereExists(fn ($p) => $p->selectRaw('1')->from('student_profiles')->whereColumn('user_id', 'users.id')
                    ->where('school_id', $c['schoolId'])->where('programme_id', $c['programme']->id))
                    ->orWhereExists(fn ($p) => $p->selectRaw('1')->from('student_curriculum_assignments')->whereColumn('student_id', 'users.id')
                        ->where('school_id', $c['schoolId'])->where('programme_id', $c['programme']->id)->whereNull('ended_at'));
            })->when($c['selectedStudent'], fn ($q) => $q->whereKey($c['selectedStudent']->id))
            ->when($c['studentSearch'] !== '', fn ($q) => $q->where(fn ($search) => $search
                ->where('name', 'like', '%'.$c['studentSearch'].'%')->orWhere('code', 'like', '%'.$c['studentSearch'].'%')))
            ->orderBy('name');
    }

    public function index(Request $request, CourseOfferingRoster $roster)
    {
        $c = $this->context($request);
        $offerings = $c['ready'] ? $this->offerings($c) : collect();
        $curriculumUnits = $c['ready'] ? $this->curriculumUnits($c) : collect();
        $unitAllocations = $c['ready'] ? DB::table('course_offering_curriculum_memberships')
            ->where('school_id', $c['schoolId'])->where('curriculum_id', $c['curriculum']->id)
            ->whereIn('course_offering_id', $offerings->pluck('id'))
            ->whereIn('curriculum_membership_id', $curriculumUnits->pluck('membership_id'))
            ->get(['curriculum_membership_id', 'course_offering_id'])->groupBy('curriculum_membership_id') : collect();
        $offeringsById = $offerings->keyBy('id');
        $unitAllocations = $unitAllocations->map(fn ($links) => $links->map(fn ($link) => $offeringsById->get($link->course_offering_id))->filter()->values());
        $subjects = DB::table('subjects')->where('school_id', $c['schoolId'])->whereIn('id', $offerings->pluck('subject_id'))->get()->keyBy('id');
        $students = $c['ready'] ? $this->students($c)->paginate(30)->withQueryString() : null;
        $registrations = $students?->isNotEmpty() ? DB::table('course_registrations')->where('school_id', $c['schoolId'])
            ->whereIn('course_offering_id', $offerings->pluck('id'))->whereIn('student_id', $students->pluck('id'))->get()->groupBy('student_id') : collect();
        $placements = $students?->isNotEmpty() ? DB::table('student_curriculum_assignments')->where('school_id', $c['schoolId'])
            ->whereIn('student_id', $students->pluck('id'))->whereNull('ended_at')->get()->groupBy('student_id') : collect();
        $cells = [];
        foreach ($students ?? [] as $student) {
            foreach ($curriculumUnits as $unit) {
                $allocations = $unitAllocations->get($unit->membership_id, collect());
                $offering = $allocations->count() === 1 ? $allocations->first() : null;
                $registration = $offering
                    ? $registrations->get($student->id, collect())->firstWhere('course_offering_id', $offering->id)
                    : null;
                $reason = match (true) {
                    $allocations->isEmpty() => 'This unit has no offering yet. Required offerings are created automatically when the semester is opened; elective choices follow the existing process.',
                    $allocations->count() > 1 => 'More than one Course Allocation is linked to this unit for the selected period. Next step: ask the academic office to review the allocations.',
                    $registration !== null => null,
                    default => $roster->ineligibilityReason($offering, (int) $student->id, true),
                };
                $cells[$student->id][$unit->membership_id] = [
                    'offering' => $offering, 'allocations' => $allocations, 'registration' => $registration,
                    'reason' => $reason, 'ready' => $offering && ! $registration && $reason === null,
                ];
            }
        }
        return view('admin.academic.programme_registration', $c + compact('offerings', 'curriculumUnits', 'unitAllocations', 'subjects', 'students', 'placements', 'cells'));
    }

    public function register(Request $request, CourseOfferingRegistrationBatch $batch, StudentCurriculumAssignmentService $assignments)
    {
        $data = $this->selection($request);
        if ($request->boolean('confirm_now')) {
            abort_unless($request->user()->hasPermission('academic.course_registration.confirm'), 403);
        }
        $c = $this->context($request);
        abort_unless($c['ready'], 422, 'Choose a programme, year of study and academic period.');
        $studentIds = $this->students($c)->whereIn('id', $data['student_ids'])->pluck('id');
        $offerings = $this->offerings($c);
        $offerings = $request->boolean('register_all_eligible')
            ? $offerings->where('status', 'open')->values()
            : $offerings->whereIn('id', $data['offering_ids'])->values();
        abort_unless($studentIds->count() === count(array_unique($data['student_ids']))
            && ($request->boolean('register_all_eligible') || $offerings->count() === count(array_unique($data['offering_ids']))), 422);
        $placementResults = [];
        if ($request->boolean('place_missing')) {
            abort_unless($request->user()->hasPermission('academic.programme_cohort.membership'), 403);
            $unplaced = $this->students($c)->whereIn('id', $studentIds)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('student_curriculum_assignments')
                    ->whereColumn('student_id', 'users.id')->where('school_id', $c['schoolId'])->whereNull('ended_at'))->get();
            foreach ($unplaced as $student) {
                try {
                    $assignments->assignDirectPlacement($c['schoolId'], (int) $student->id, (int) $c['programme']->id,
                        (int) $c['curriculum']->id, (int) $c['stage']->id, (int) $c['year']->id, (int) $request->user()->id);
                    $placementResults[] = ['student' => $student->name, 'message' => 'Year of study saved.'];
                } catch (\DomainException $exception) {
                    $studentIds = $studentIds->reject(fn ($id) => (int) $id === (int) $student->id);
                    $placementResults[] = ['student' => $student->name, 'message' => 'Not registered: '.$exception->getMessage()];
                }
            }
        }
        $outcomes = [];
        foreach ($offerings as $offering) {
            $summary = $batch->register($offering, $studentIds, (int) $request->user()->id);
            if ($request->boolean('confirm_now')) {
                $ids = DB::table('course_registrations')->where('school_id', $c['schoolId'])
                    ->where('course_offering_id', $offering->id)->whereIn('student_id', $studentIds)
                    ->where('status', 'registered')->pluck('id');
                $confirmation = $batch->confirm($offering, $ids, (int) $request->user()->id);
                $summary['confirmed'] = $confirmation['confirmed'];
                $summary['skipped'] += $confirmation['skipped'];
                $summary['details'] = array_merge(
                    array_values(array_filter($summary['details'], fn ($detail) => $detail['result'] !== CourseOfferingRegistrationBatch::ALREADY)),
                    $confirmation['details']
                );
            }
            $outcomes[] = ['course' => $offering->subject?->name ?? $offering->reference, 'summary' => $summary];
        }
        return redirect()->route('admin.course_registrations.index', $this->filters($c))
            ->with('programme_registration_results', $outcomes)->with('programme_placement_results', $placementResults);
    }

    public function place(Request $request, StudentCurriculumAssignmentService $assignments)
    {
        $request->validate(['student_id' => 'required|integer|min:1']);
        $c = $this->context($request);
        abort_unless($c['ready'], 422);
        $student = $this->students($c)->findOrFail($request->integer('student_id'));
        try {
            $assignments->assignDirectPlacement($c['schoolId'], (int) $student->id, (int) $c['programme']->id,
                (int) $c['curriculum']->id, (int) $c['stage']->id, (int) $c['year']->id, (int) $request->user()->id);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['placement' => $exception->getMessage()]);
        }
        return redirect()->route('admin.course_registrations.index', $this->filters($c))->with('success', 'Year of study saved for '.$student->name.'. You can now review and register their courses below.');
    }

    public function confirm(Request $request, CourseOfferingRegistrationBatch $batch)
    {
        $data = $this->selection($request);
        $c = $this->context($request);
        abort_unless($c['ready'], 422);
        $studentIds = $this->students($c)->whereIn('id', $data['student_ids'])->pluck('id');
        $offerings = $this->offerings($c);
        $offerings = $request->boolean('register_all_eligible')
            ? $offerings->where('status', 'open')->values()
            : $offerings->whereIn('id', $data['offering_ids'])->values();
        abort_unless($studentIds->count() === count(array_unique($data['student_ids']))
            && ($request->boolean('register_all_eligible') || $offerings->count() === count(array_unique($data['offering_ids']))), 422);
        $outcomes = [];
        foreach ($offerings as $offering) {
            $ids = DB::table('course_registrations')->where('school_id', $c['schoolId'])->where('course_offering_id', $offering->id)
                ->whereIn('student_id', $studentIds)->where('status', 'registered')->pluck('id');
            $outcomes[] = ['course' => $offering->subject?->name ?? $offering->reference, 'summary' => $batch->confirm($offering, $ids, (int) $request->user()->id)];
        }
        return redirect()->route('admin.course_registrations.index', $this->filters($c))->with('programme_registration_results', $outcomes);
    }

    private function selection(Request $request): array
    {
        $data = $request->validate([
            'student_ids' => 'required|array|max:30', 'student_ids.*' => 'required|integer|min:1',
            'offering_ids' => 'nullable|array|max:50', 'offering_ids.*' => 'required|integer|min:1',
            'register_all_eligible' => 'nullable|boolean',
            'place_missing' => 'nullable|boolean', 'confirm_now' => 'nullable|boolean',
        ], ['student_ids.required' => 'Select at least one student.']);
        if (! $request->boolean('register_all_eligible') && empty($data['offering_ids'])) {
            throw ValidationException::withMessages(['offering_ids' => 'Choose at least one Course Allocation, or select all eligible allocated units.']);
        }
        $data['offering_ids'] = $data['offering_ids'] ?? [];
        return $data;
    }

    private function filters(array $c): array
    {
        $filters = ['programme_id' => $c['programme']->id, 'curriculum_id' => $c['curriculum']->id, 'stage_id' => $c['stage']->id,
            'academic_year_id' => $c['year']->id, 'period_id' => $c['period']->id];
        if ($c['selectedStudent']) $filters['student_id'] = $c['selectedStudent']->id;
        if ($c['page'] > 1) $filters['page'] = $c['page'];
        if ($c['studentSearch'] !== '') $filters['student_search'] = $c['studentSearch'];
        return $filters;
    }
}
