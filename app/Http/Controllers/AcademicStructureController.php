<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Support\AcademicContextManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
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
        \App\Support\Academic\AcademicDate::normalize($request);
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
        \App\Support\Academic\AcademicDate::normalize($request);
        $year = AcademicYear::query()->where('school_id', $this->schoolId)->findOrFail($request->input('academic_year_id'));
        $data = $request->validate([
            'academic_year_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(AcademicPeriod::TYPES)],
            'label' => ['required', 'string', 'max:100'],
            'sequence' => ['required', 'integer', 'min:1', Rule::unique('academic_periods', 'sequence')->where('academic_year_id', $year->id)],
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

    public function record(Request $request, string $kind, int $recordId)
    {
        $record = $this->calendarRecord($kind, $recordId);
        $school = \App\Models\School::findOrFail($this->schoolId);
        $years = AcademicYear::where('school_id', $this->schoolId)->orderByDesc('start_date')->get();
        $editing = $request->routeIs('admin.academic_structure.edit');
        return view('admin.academic_structure.record', compact('record', 'kind', 'school', 'years', 'editing'));
    }

    public function update(Request $request, string $kind, int $recordId)
    {
        \App\Support\Academic\AcademicDate::normalize($request);
        DB::transaction(function () use ($request, $kind, $recordId) {
            $school = \App\Models\School::whereKey($this->schoolId)->lockForUpdate()->firstOrFail();
            $record = $this->calendarRecord($kind, $recordId, true);
            $rules = [
                'label' => ['required', 'string', 'max:100'],
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'status' => ['prohibited'],
            ];
            if ($kind === 'periods') {
                $rules += [
                    'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $this->schoolId)],
                    'type' => ['required', Rule::in(AcademicPeriod::TYPES)],
                    'sequence' => ['required', 'integer', 'min:1', Rule::unique('academic_periods', 'sequence')->where('academic_year_id', $request->input('academic_year_id'))->ignore($record->id)],
                ];
            }
            $data = $request->validate($rules);
            $record->fill($data);
            if ($kind === 'years' && $record->periods()->where(function ($query) use ($data) {
                $query->whereDate('start_date', '<', $data['start_date'])->orWhereDate('end_date', '>', $data['end_date']);
            })->exists()) {
                throw ValidationException::withMessages(['start_date' => 'The year dates must include all existing semesters or terms.']);
            }
            if ($kind === 'periods') {
                $year = AcademicYear::where('school_id', $this->schoolId)->findOrFail($data['academic_year_id']);
                if ($record->start_date->lt($year->start_date) || $record->end_date->gt($year->end_date)) {
                    throw ValidationException::withMessages(['start_date' => 'Dates must fall within the selected Academic Year.']);
                }
                if (! AcademicPeriod::matchesCalendarPattern($data['type'], $school->academic_calendar_pattern)) {
                    throw ValidationException::withMessages(['type' => 'Choose the period type configured for this school.']);
                }
                if ($record->isDirty('academic_year_id') && (int) $school->current_academic_period_id === $record->id) {
                    throw ValidationException::withMessages(['academic_year_id' => 'Clear the current semester selection before moving it to another year.']);
                }
            }
            $structuralFields = $kind === 'years' ? ['start_date', 'end_date'] : ['academic_year_id', 'type', 'sequence', 'start_date', 'end_date'];
            if ($record->isDirty($structuralFields) && $record->courseOfferings()->exists()) {
                throw ValidationException::withMessages(['start_date' => 'This record has Course Registrations. You can edit its label; its calendar details must be preserved.']);
            }
            $record->save();
        });
        return redirect()->route('admin.academic_structure.show', [$kind, $recordId])->with('success', 'Academic record updated.');
    }

    public function destroy(Request $request, string $kind, int $recordId)
    {
        $request->validate(['confirm_deletion' => ['required', 'accepted']]);
        try {
            DB::transaction(function () use ($kind, $recordId) {
                $school = \App\Models\School::whereKey($this->schoolId)->lockForUpdate()->firstOrFail();
                $record = $this->calendarRecord($kind, $recordId, true);
                $current = $kind === 'years' ? $school->current_academic_year_id : $school->current_academic_period_id;
                $relationships = $kind === 'years'
                    ? ['periods', 'legacySessions', 'curricula', 'entryProgrammeCohorts', 'studentEntryAssignments', 'studentEffectiveAssignments', 'courseOfferings']
                    : ['courseOfferings'];
                if ((int) $current === $record->id) {
                    throw ValidationException::withMessages(['delete' => 'Clear or replace the current selection before deleting this record.']);
                }
                foreach ($relationships as $relationship) {
                    if ($record->{$relationship}()->exists()) {
                        throw ValidationException::withMessages(['delete' => 'This record is used by other academic records. Remove those links first, or use Cancel or Complete to preserve its history.']);
                    }
                }
                $record->delete();
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), ['23000', '23503'], true)) throw $exception;
            throw ValidationException::withMessages(['delete' => 'This record is linked to other records and cannot be deleted.']);
        }
        return redirect()->route('admin.academic_structure.index', $kind === 'periods' ? ['section' => 'semesters'] : [])->with('success', 'Academic record deleted.');
    }

    private function calendarRecord(string $kind, int $recordId, bool $lock = false)
    {
        abort_unless(in_array($kind, ['years', 'periods'], true), 404);
        $query = ($kind === 'years' ? AcademicYear::query() : AcademicPeriod::query())->where('school_id', $this->schoolId);
        if ($lock) $query->lockForUpdate();
        return $query->findOrFail($recordId);
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
