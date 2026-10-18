<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Support\AcademicContextManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AcademicStructureController extends Controller
{
    private int $schoolId;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(Auth::user() && Auth::user()->school_id, 403);
            $this->schoolId = (int) Auth::user()->school_id;
            return $next($request);
        });
    }

    public function index()
    {
        $school = \App\Models\School::query()->findOrFail($this->schoolId);
        $years = AcademicYear::query()->where('school_id', $this->schoolId)->with('periods')->orderByDesc('start_date')->get();
        return view('admin.academic_structure.index', compact('school', 'years'));
    }

    public function storeYear(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['prohibited'],
        ]);
        AcademicYear::query()->create(['school_id' => $this->schoolId, 'status' => 'planned'] + $data);
        return redirect()->route('admin.academic_structure.index')->with('success', 'Academic year created.');
    }

    public function storePeriod(Request $request)
    {
        $year = AcademicYear::query()->where('school_id', $this->schoolId)->findOrFail($request->input('academic_year_id'));
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(AcademicPeriod::TYPES)],
            'label' => ['required', 'string', 'max:100'],
            'sequence' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date', 'after_or_equal:'.$year->start_date->toDateString(), 'before_or_equal:'.$year->end_date->toDateString()],
            'end_date' => ['required', 'date', 'after_or_equal:start_date', 'before_or_equal:'.$year->end_date->toDateString()],
            'status' => ['prohibited'],
            'make_current' => ['prohibited'],
        ]);
        $pattern = \App\Models\School::query()->whereKey($this->schoolId)->value('academic_calendar_pattern');
        if (! AcademicPeriod::matchesCalendarPattern($data['type'], $pattern)) {
            throw ValidationException::withMessages(['type' => 'The period type must match this school’s configured calendar pattern.']);
        }

        AcademicPeriod::query()->create(['school_id' => $this->schoolId, 'status' => 'planned'] + $data);
        return redirect()->route('admin.academic_structure.index', ['section' => 'semesters'])->with('success', 'Academic period created.');
    }

    public function setCurrent(Request $request, AcademicContextManager $manager)
    {
        $data = $request->validate([
            'current_academic_year_id' => ['nullable', 'integer'],
            'current_academic_period_id' => ['nullable', 'integer'],
        ]);
        $manager->setCurrent($this->schoolId, $data['current_academic_year_id'] ?? null, $data['current_academic_period_id'] ?? null);
        return redirect()->route('admin.academic_structure.index', $request->input('section') === 'semesters' ? ['section' => 'semesters'] : [])->with('success', 'Current academic context updated.');
    }

    public function transitionYear(Request $request, int $yearId, AcademicContextManager $manager)
    {
        $data = $this->validatedTransition($request);
        $manager->transitionYear($this->schoolId, $yearId, $data['status']);
        return redirect()->route('admin.academic_structure.index')->with('success', 'Academic year status updated.');
    }

    public function transitionPeriod(Request $request, int $periodId, AcademicContextManager $manager)
    {
        $data = $this->validatedTransition($request);
        $manager->transitionPeriod($this->schoolId, $periodId, $data['status']);
        return redirect()->route('admin.academic_structure.index', ['section' => 'semesters'])->with('success', 'Academic period status updated.');
    }

    private function validatedTransition(Request $request): array
    {
        $rules = ['status' => ['required', Rule::in(AcademicYear::STATUSES)]];
        if ($request->input('status') === 'cancelled') {
            $rules['confirm_cancellation'] = ['required', 'accepted'];
        }

        return $request->validate($rules);
    }
}
