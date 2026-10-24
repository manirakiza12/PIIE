<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Support\AcademicContextManager;
use App\Support\Academic\AcademicRecordDeletionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function update(Request $request, string $kind, int $recordId, ?\App\Support\CourseOffering\CourseOfferingService $offerings = null)
    {
        \App\Support\Academic\AcademicDate::normalize($request);
        DB::transaction(function () use ($request, $kind, $recordId, $offerings) {
            $school = \App\Models\School::whereKey($this->schoolId)->lockForUpdate()->firstOrFail();
            $record = $this->calendarRecord($kind, $recordId, true);
            $rules = [
                'label' => ['required', 'string', 'max:100'],
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'status' => ['prohibited'],
                'reason' => ['nullable', 'string', 'max:500'],
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
            $wasCurrentPeriod = false;
            $membershipPlacementUpdates = collect();
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
                if ($record->isDirty(['type', 'sequence']) && trim((string) ($data['reason'] ?? '')) === '') {
                    throw ValidationException::withMessages(['reason' => 'Explain why the semester or term identity is being changed.']);
                }
                $wasCurrentPeriod = $record->isDirty('academic_year_id') && (int) $school->current_academic_period_id === $record->id;
                if ($record->isDirty('academic_year_id') && $record->courseOfferings()->exists()) {
                    throw ValidationException::withMessages(['academic_year_id' => 'Move or remove the linked Course Allocations first. Their generated references and registrations must remain tied to the semester identity. Other semester details can still be edited in every status.']);
                }
                if ($record->isDirty(['type', 'sequence']) && $record->courseOfferings()->exists()) {
                    $offeringIds = $record->courseOfferings()->pluck('course_offerings.id');
                    $hasCurriculumLinks = Schema::hasTable('course_offering_curriculum_memberships')
                        && DB::table('course_offering_curriculum_memberships')->where('school_id', $this->schoolId)->whereIn('course_offering_id', $offeringIds)->exists();
                    if ($hasCurriculumLinks) {
                        $membershipPlacementUpdates = DB::table('course_offering_curriculum_memberships')->where('school_id', $this->schoolId)
                            ->whereIn('course_offering_id', $offeringIds)->pluck('curriculum_membership_id')->unique()->values();
                        $conflictingReuse = DB::table('course_offering_curriculum_memberships as link')
                            ->join('course_offerings as offering', function ($join) { $join->on('offering.id', '=', 'link.course_offering_id')->on('offering.school_id', '=', 'link.school_id'); })
                            ->join('academic_periods as period', function ($join) { $join->on('period.id', '=', 'offering.academic_period_id')->on('period.school_id', '=', 'offering.school_id')->on('period.academic_year_id', '=', 'offering.academic_year_id'); })
                            ->where('link.school_id', $this->schoolId)->whereIn('link.curriculum_membership_id', $membershipPlacementUpdates)
                            ->whereNotIn('link.course_offering_id', $offeringIds)
                            ->where(fn ($query) => $query->where('period.type', '<>', $data['type'])->orWhere('period.sequence', '<>', $data['sequence']))
                            ->exists();
                        if ($conflictingReuse) {
                            throw ValidationException::withMessages(['sequence' => 'At least one linked Curriculum Course Unit is also used in another period. Its placement cannot be changed without making that allocation inconsistent.']);
                        }
                    }
                }
            }
            $refreshReferenceIds = collect();
            if ($kind === 'years' && $record->isDirty('start_date')) {
                $refreshReferenceIds = DB::table('course_offerings')->where('school_id', $this->schoolId)->where('academic_year_id', $record->id)->pluck('id');
            } elseif ($kind === 'periods' && $record->isDirty(['type', 'sequence'])) {
                $refreshReferenceIds = DB::table('course_offerings')->where('school_id', $this->schoolId)->where('academic_period_id', $record->id)->pluck('id');
            }
            if ($wasCurrentPeriod) DB::table('schools')->where('id', $this->schoolId)->update(['current_academic_period_id' => null]);
            $record->save();
            if ($membershipPlacementUpdates->isNotEmpty()) {
                DB::table('curriculum_memberships')->where('school_id', $this->schoolId)->whereIn('id', $membershipPlacementUpdates)->update([
                    'period_type' => $record->type, 'period_sequence' => $record->sequence, 'updated_at' => now(),
                ]);
            }
            if ($refreshReferenceIds->isNotEmpty()) {
                $offerings ??= app(\App\Support\CourseOffering\CourseOfferingService::class);
                foreach ($refreshReferenceIds as $offeringId) {
                    $offerings->updateAllocation($this->schoolId, (int) $offeringId, [], (int) $request->user()->id);
                }
            }
            if ($wasCurrentPeriod) DB::table('schools')->where('id', $this->schoolId)->update([
                'current_academic_year_id' => $record->academic_year_id,
                'current_academic_period_id' => $record->id,
            ]);
            if ($record->wasChanged()) $this->recordAcademicEdit($request, $kind, $recordId, $data['reason'] ?? null);
        });
        return redirect()->route('admin.academic_structure.show', [$kind, $recordId])->with('success', 'Academic record updated.');
    }

    public function destroy(Request $request, string $kind, int $recordId, AcademicRecordDeletionService $deletion)
    {
        abort_unless(Auth::user()?->hasPermission('academic.structure.manage'), 403);
        abort_unless(in_array($kind, ['years', 'periods'], true), 404);
        $data = $request->validate(['confirm_deletion' => ['required', 'string', 'max:150']]);
        try {
            if ($kind === 'years') $deletion->deleteYear($this->schoolId, $recordId, (int) $request->user()->id, $data['confirm_deletion']);
            else $deletion->deletePeriod($this->schoolId, $recordId, (int) $request->user()->id, $data['confirm_deletion']);
        } catch (QueryException $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'delete' => 'The academic record could not be deleted because a linked record or audit write failed. No changes were saved. Contact an administrator to check the linked records, then retry.',
            ]);
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

    private function recordAcademicEdit(Request $request, string $kind, int $recordId, ?string $reason = null): void
    {
        $actorId = (int) ($request->user()?->id ?? 0);
        $actor = Schema::hasTable('users') ? \App\Models\User::find($actorId) : null;
        \App\Models\AuditLog::create([
            'school_id' => $this->schoolId, 'user_id' => $actorId, 'user_name' => $actor?->name,
            'role_id' => $actor?->role_id, 'role_name' => \App\Models\AuditLog::roleName($actor?->role_id),
            'action' => 'ACADEMIC_RECORD_UPDATED', 'event_type' => 'DATA', 'module' => 'Academic Setup',
            'description' => 'Academic record updated.', 'record_type' => $kind === 'years' ? AcademicYear::class : AcademicPeriod::class,
            'record_id' => $recordId, 'new_values' => $reason ? ['reason' => trim($reason)] : null, 'created_at' => now(),
        ]);
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
