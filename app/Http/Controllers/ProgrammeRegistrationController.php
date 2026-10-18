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
        $schoolId = (int) $request->user()->school_id;
        $programmes = DB::table('programmes')->where('school_id', $schoolId)->orderBy('name')->get(['id', 'name', 'code']);
        $programme = $programmes->firstWhere('id', $request->integer('programme_id'));
        $curricula = Curriculum::where('school_id', $schoolId)->where('programme_id', $programme?->id ?? 0)
            ->where('status', 'approved')->orderByDesc('id')->get();
        $curriculum = $request->filled('curriculum_id') ? $curricula->firstWhere('id', $request->integer('curriculum_id'))
            : ($curricula->count() === 1 ? $curricula->first() : null);
        $stages = DB::table('curriculum_stages')->where('school_id', $schoolId)->where('curriculum_id', $curriculum?->id ?? 0)->orderBy('sequence')->get();
        $stage = $stages->firstWhere('id', $request->integer('stage_id'));
        $years = DB::table('academic_years')->where('school_id', $schoolId)->orderByDesc('start_date')->get();
        $year = $request->filled('academic_year_id') ? $years->firstWhere('id', $request->integer('academic_year_id')) : $years->firstWhere('status', 'active');
        $periods = DB::table('academic_periods')->where('school_id', $schoolId)->where('academic_year_id', $year?->id ?? 0)->orderBy('sequence')->get();
        $period = $periods->firstWhere('id', $request->integer('period_id'));
        $context = compact('schoolId', 'programmes', 'programme', 'curricula', 'curriculum', 'stages', 'stage', 'years', 'year', 'periods', 'period');
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

    private function students(array $c)
    {
        return User::where('school_id', $c['schoolId'])->where('role_id', 7)
            ->where(function ($q) use ($c) {
                $q->whereExists(fn ($p) => $p->selectRaw('1')->from('student_profiles')->whereColumn('user_id', 'users.id')
                    ->where('school_id', $c['schoolId'])->where('programme_id', $c['programme']->id))
                    ->orWhereExists(fn ($p) => $p->selectRaw('1')->from('student_curriculum_assignments')->whereColumn('student_id', 'users.id')
                        ->where('school_id', $c['schoolId'])->where('programme_id', $c['programme']->id)->whereNull('ended_at'));
            })->orderBy('name');
    }

    public function index(Request $request, CourseOfferingRoster $roster)
    {
        $c = $this->context($request);
        $offerings = $c['ready'] ? $this->offerings($c) : collect();
        $subjects = DB::table('subjects')->where('school_id', $c['schoolId'])->whereIn('id', $offerings->pluck('subject_id'))->get()->keyBy('id');
        $students = $c['ready'] ? $this->students($c)->paginate(30)->withQueryString() : null;
        $registrations = DB::table('course_registrations')->where('school_id', $c['schoolId'])
            ->whereIn('course_offering_id', $offerings->pluck('id'))->whereIn('student_id', $students?->pluck('id') ?? [])->get()->groupBy('student_id');
        $placements = DB::table('student_curriculum_assignments')->where('school_id', $c['schoolId'])
            ->whereIn('student_id', $students?->pluck('id') ?? [])->whereNull('ended_at')->get()->groupBy('student_id');
        $cells = [];
        foreach ($students ?? [] as $student) {
            foreach ($offerings as $offering) {
                $registration = $registrations->get($student->id, collect())->firstWhere('course_offering_id', $offering->id);
                $reason = $registration ? null : $roster->ineligibilityReason($offering, (int) $student->id);
                $cells[$student->id][$offering->id] = ['registration' => $registration, 'reason' => $reason, 'ready' => ! $registration && $reason === null];
            }
        }
        return view('admin.academic.programme_registration', $c + compact('offerings', 'subjects', 'students', 'placements', 'cells'));
    }

    public function register(Request $request, CourseOfferingRegistrationBatch $batch)
    {
        $data = $this->selection($request);
        $c = $this->context($request);
        abort_unless($c['ready'], 422, 'Choose a programme, year of study and academic period.');
        $studentIds = $this->students($c)->whereIn('id', $data['student_ids'])->pluck('id');
        $offerings = $this->offerings($c)->whereIn('id', $data['offering_ids']);
        abort_unless($studentIds->count() === count(array_unique($data['student_ids'])) && $offerings->count() === count(array_unique($data['offering_ids'])), 422);
        $outcomes = [];
        foreach ($offerings as $offering) {
            $summary = $batch->register($offering, $studentIds, (int) $request->user()->id);
            $outcomes[] = ['course' => $offering->subject?->name ?? $offering->reference, 'summary' => $summary];
        }
        return redirect()->route('admin.course_registrations.index', $this->filters($c))->with('programme_registration_results', $outcomes);
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
        $offerings = $this->offerings($c)->whereIn('id', $data['offering_ids']);
        abort_unless($studentIds->count() === count(array_unique($data['student_ids'])) && $offerings->count() === count(array_unique($data['offering_ids'])), 422);
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
        return $request->validate([
            'student_ids' => 'required|array|max:30', 'student_ids.*' => 'required|integer|min:1',
            'offering_ids' => 'required|array|max:50', 'offering_ids.*' => 'required|integer|min:1',
        ], ['student_ids.required' => 'Select at least one student.', 'offering_ids.required' => 'Choose at least one course.']);
    }

    private function filters(array $c): array
    {
        $filters = ['programme_id' => $c['programme']->id, 'curriculum_id' => $c['curriculum']->id, 'stage_id' => $c['stage']->id,
            'academic_year_id' => $c['year']->id, 'period_id' => $c['period']->id];
        if ($c['page'] > 1) $filters['page'] = $c['page'];
        return $filters;
    }
}
