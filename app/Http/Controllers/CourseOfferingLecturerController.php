<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use App\Support\CourseOffering\CourseOfferingLecturerAllocationConflict;
use App\Support\CourseOffering\CourseOfferingLecturerAllocationService;
use App\Support\Permissions\PermissionService;
use App\Support\TenantConfiguration;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use DomainException;
use Throwable;

class CourseOfferingLecturerController extends Controller
{
    public function __construct(
        private CourseOfferingLecturerAllocationService $allocations,
        private PermissionService $permissions,
        private TenantConfiguration $tenantConfiguration
    ) {
    }

    public function index(Request $request, int $offering)
    {
        $schoolId = (int) $request->user()->school_id;
        $context = $this->offeringContext($schoolId, $offering);
        $allocations = CourseOfferingLecturerAllocation::query()
            ->where('school_id', $schoolId)
            ->where('course_offering_id', $offering)
            ->with($this->lecturerRelations($schoolId))
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        $canManage = $this->permissions->allows($request->user(), 'academic.course_offering.lecturer.manage');
        $candidates = $canManage
            ? $this->allocations->eligibleLecturersForOffering($schoolId, $offering)
            : collect();
        $history = $this->allocationHistory($schoolId, $allocations);

        return view('admin.course_offerings.lecturers.index', $context + [
            'allocations' => $allocations,
            'currentAllocations' => $allocations->where('status', CourseOfferingLecturerAllocation::STATUS_ACTIVE)->values(),
            'plannedAllocations' => $allocations->where('status', CourseOfferingLecturerAllocation::STATUS_PLANNED)->values(),
            'historicalAllocations' => $allocations->whereIn('status', [CourseOfferingLecturerAllocation::STATUS_ENDED, CourseOfferingLecturerAllocation::STATUS_CANCELLED])->values(),
            'candidates' => $candidates,
            'roleLabels' => $this->roleLabels(),
            'canManage' => $canManage,
            'canMutateOffering' => $this->offeringCanBeMutated($context['offering']),
            'canActivate' => in_array($context['offering']->status, [CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true),
            'history' => $history,
            'hasPrimary' => $allocations->contains(fn ($allocation) => $allocation->role === CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER
                && $allocation->status === CourseOfferingLecturerAllocation::STATUS_ACTIVE),
        ]);
    }

    public function history(Request $request, int $offering)
    {
        $schoolId = (int) $request->user()->school_id;
        $context = $this->offeringContext($schoolId, $offering);
        $allocations = CourseOfferingLecturerAllocation::query()
            ->where('school_id', $schoolId)
            ->where('course_offering_id', $offering)
            ->with($this->lecturerRelations($schoolId))
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        return view('admin.course_offerings.lecturers.history', $context + [
            'history' => $this->allocationHistory($schoolId, $allocations),
            'roleLabels' => $this->roleLabels(),
        ]);
    }

    public function create(Request $request, int $offering)
    {
        $schoolId = (int) $request->user()->school_id;
        $context = $this->offeringContext($schoolId, $offering);
        $canManage = $this->permissions->allows($request->user(), 'academic.course_offering.lecturer.manage');

        return view('admin.course_offerings.lecturers.create', $context + [
            'candidates' => $canManage ? $this->allocations->eligibleLecturersForOffering($schoolId, $offering) : collect(),
            'roleLabels' => $this->roleLabels(),
            'canManage' => $canManage,
            'canMutateOffering' => $this->offeringCanBeMutated($context['offering']),
        ]);
    }

    public function store(Request $request, int $offering)
    {
        $schoolId = (int) $request->user()->school_id;
        $this->offeringContext($schoolId, $offering);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', 'string', 'max:32'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $this->allocations->createPlanned($schoolId, $offering, (int) $data['user_id'], $data['role'], $data['starts_on'], $data['ends_on'] ?? null);
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['allocation' => $this->allocationMessage($exception, $schoolId)]);
        }

        return redirect()->route('admin.course_offerings.lecturers.index', $offering)->with('success', 'Lecturer assigned as a planned allocation.');
    }

    public function update(Request $request, int $offering, int $allocation)
    {
        $schoolId = (int) $request->user()->school_id;
        $this->allocationForOffering($schoolId, $offering, $allocation);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', 'string', 'max:32'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $this->allocations->updatePlanned($schoolId, $allocation, [
                'user_id' => (int) $data['user_id'],
                'role' => $data['role'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
            ]);
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['allocation' => $this->allocationMessage($exception, $schoolId)]);
        }

        return redirect()->route('admin.course_offerings.lecturers.index', $offering)->with('success', 'Planned lecturer allocation updated.');
    }

    public function activate(Request $request, int $offering, int $allocation)
    {
        $schoolId = (int) $request->user()->school_id;
        $this->allocationForOffering($schoolId, $offering, $allocation);

        try {
            $this->allocations->activate($schoolId, $allocation);
        } catch (Throwable $exception) {
            return back()->withErrors(['allocation' => $this->allocationMessage($exception, $schoolId)]);
        }

        return redirect()->route('admin.course_offerings.lecturers.index', $offering)->with('success', 'Lecturer allocation activated.');
    }

    public function end(Request $request, int $offering, int $allocation)
    {
        $schoolId = (int) $request->user()->school_id;
        $this->allocationForOffering($schoolId, $offering, $allocation);
        $data = $request->validate(['ends_on' => ['required', 'date_format:Y-m-d']]);

        try {
            $this->allocations->end($schoolId, $allocation, $data['ends_on']);
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['allocation' => $this->allocationMessage($exception, $schoolId)]);
        }

        return redirect()->route('admin.course_offerings.lecturers.index', $offering)->with('success', 'Allocation ended and retained as teaching history.');
    }

    public function cancel(Request $request, int $offering, int $allocation)
    {
        $schoolId = (int) $request->user()->school_id;
        $this->allocationForOffering($schoolId, $offering, $allocation);
        $data = $request->validate(['reason' => ['required', 'string', 'min:1', 'max:1000']]);

        try {
            $this->allocations->cancel($schoolId, $allocation, trim($data['reason']));
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['allocation' => $this->allocationMessage($exception, $schoolId)]);
        }

        return redirect()->route('admin.course_offerings.lecturers.index', $offering)->with('success', 'Allocation cancelled. Its effective dates and history were preserved.');
    }

    public function replace(Request $request, int $offering, int $allocation)
    {
        $schoolId = (int) $request->user()->school_id;
        $this->allocationForOffering($schoolId, $offering, $allocation);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'role' => ['required', 'string', 'max:32'],
            'old_ends_on' => ['required', 'date_format:Y-m-d'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        try {
            $this->allocations->replace(
                $schoolId,
                $allocation,
                (int) $data['user_id'],
                $data['role'],
                $data['old_ends_on'],
                $data['starts_on'],
                $data['ends_on'] ?? null
            );
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['allocation' => $this->allocationMessage($exception, $schoolId)]);
        }

        return redirect()->route('admin.course_offerings.lecturers.index', $offering)->with('success', 'Lecturer replaced. Both allocations remain in teaching history.');
    }

    private function offeringContext(int $schoolId, int $offeringId): array
    {
        $school = School::findOrFail($schoolId);
        if (($school->school_type ?: 'k12') === 'k12') {
            abort(404);
        }
        $offering = CourseOffering::where('school_id', $schoolId)->whereKey($offeringId)->firstOrFail();
        $offering->setRelation('subject', Subject::where('school_id', $schoolId)->whereKey($offering->subject_id)->firstOrFail());
        $offering->setRelation('academicYear', AcademicYear::where('school_id', $schoolId)->whereKey($offering->academic_year_id)->firstOrFail());
        $period = AcademicPeriod::where('school_id', $schoolId)
            ->where('academic_year_id', $offering->academic_year_id)
            ->whereKey($offering->academic_period_id)
            ->firstOrFail();
        $offering->setRelation('academicPeriod', $period);
        $terminology = $this->tenantConfiguration->terminology($school);

        return [
            'offering' => $offering,
            'period' => $period,
            'terminology' => $terminology,
            'courseUnitLabel' => $terminology['course_unit'] ?? 'Course Unit',
            'lecturerLabel' => $terminology['teacher'] ?? 'Lecturer',
            'periodLabel' => $terminology['academic_period'] ?? 'Academic Period',
        ];
    }

    private function allocationForOffering(int $schoolId, int $offeringId, int $allocationId): CourseOfferingLecturerAllocation
    {
        $this->offeringContext($schoolId, $offeringId);

        return CourseOfferingLecturerAllocation::where('school_id', $schoolId)
            ->where('course_offering_id', $offeringId)
            ->whereKey($allocationId)
            ->firstOrFail();
    }

    private function lecturerRelations(int $schoolId): array
    {
        $relations = [
            'lecturer.department' => fn ($query) => $query->where('school_id', $schoolId),
            'lecturer.designationRecord' => fn ($query) => $query->where('school_id', $schoolId),
        ];

        if (Schema::hasTable('staff_profiles')) {
            $relations['lecturer.staffProfile'] = fn ($query) => $query->where('school_id', $schoolId);
        }

        return $relations;
    }

    private function allocationHistory(int $schoolId, Collection $allocations): array
    {
        if ($allocations->isEmpty()) {
            return [];
        }

        $events = AuditLog::query()
            ->where('school_id', $schoolId)
            ->where('record_type', CourseOfferingLecturerAllocation::class)
            ->whereIn('record_id', $allocations->pluck('id'))
            ->orderByDesc('id')
            ->get();

        $userIds = $events->flatMap(function (AuditLog $event): array {
            $old = $event->old_values ?? [];
            $new = $event->new_values ?? [];

            return array_filter([
                $old['user_id'] ?? null,
                $new['user_id'] ?? null,
                $new['previous_user_id'] ?? null,
                $new['replacement_user_id'] ?? null,
            ]);
        })->unique()->values();
        $names = $userIds->isEmpty()
            ? collect()
            : User::where('school_id', $schoolId)->whereIn('id', $userIds)->pluck('name', 'id');

        return $events->map(function (AuditLog $event) use ($names): array {
            $old = $event->old_values ?? [];
            $new = $event->new_values ?? [];
            $action = $event->action;
            $userId = $new['replacement_user_id'] ?? $new['user_id'] ?? $old['user_id'] ?? null;
            $lecturer = $userId ? ($names->get($userId) ?: 'Former lecturer') : 'Lecturer not recorded';
            $actor = $event->user_name ?: ($event->user_id ? 'Unknown user' : 'System');

            return [
                'event' => $this->historyEventLabel($action),
                'lecturer' => $action === 'COURSE_OFFERING_LECTURER_REPLACED'
                    ? (($names->get($new['previous_user_id'] ?? null) ?: 'Former lecturer').' → '.$lecturer)
                    : $lecturer,
                'role' => $this->roleLabels()[$new['replacement_role'] ?? $new['role'] ?? $old['role'] ?? ''] ?? '—',
                'starts_on' => $new['replacement_starts_on'] ?? $new['starts_on'] ?? $old['starts_on'] ?? null,
                'ends_on' => $new['replacement_ends_on'] ?? $new['ends_on'] ?? $new['previous_ends_on'] ?? $old['ends_on'] ?? null,
                'actor' => $actor,
                'created_at' => $event->created_at,
                'reason' => $new['reason'] ?? null,
            ];
        })->all();
    }

    private function historyEventLabel(string $action): string
    {
        return match ($action) {
            'COURSE_OFFERING_LECTURER_ALLOCATION_CREATED' => 'Lecturer assigned',
            'COURSE_OFFERING_LECTURER_ALLOCATION_UPDATED' => 'Planned allocation updated',
            'COURSE_OFFERING_LECTURER_ALLOCATION_ACTIVATED' => 'Allocation activated',
            'COURSE_OFFERING_LECTURER_ALLOCATION_ENDED' => 'Allocation ended',
            'COURSE_OFFERING_LECTURER_ALLOCATION_CANCELLED' => 'Allocation cancelled',
            'COURSE_OFFERING_LECTURER_REPLACED' => 'Lecturer replaced',
            default => 'Allocation updated',
        };
    }

    private function roleLabels(): array
    {
        return [
            CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER => 'Primary Lecturer',
            CourseOfferingLecturerAllocation::ROLE_CO_LECTURER => 'Co-Lecturer',
            CourseOfferingLecturerAllocation::ROLE_TEACHING_ASSISTANT => 'Teaching Assistant',
            CourseOfferingLecturerAllocation::ROLE_LAB_INSTRUCTOR => 'Lab Instructor',
            CourseOfferingLecturerAllocation::ROLE_GUEST_LECTURER => 'Guest Lecturer',
        ];
    }

    private function offeringCanBeMutated(CourseOffering $offering): bool
    {
        return in_array($offering->status, [CourseOffering::STATUS_DRAFT, CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true);
    }

    /**
     * Domain failures are already written for lecturers and administrators. Anything
     * else (a database or configuration failure) is reported server-side and reduced
     * to a safe message, so no SQL or exception detail ever reaches the UI.
     */
    private function allocationMessage(Throwable $exception, int $schoolId): string
    {
        if (! $exception instanceof DomainException) {
            report($exception);

            return 'Something went wrong while updating the teaching team. Please try again, or contact support if this continues.';
        }

        return $this->domainMessage($exception, $schoolId);
    }

    private function domainMessage(DomainException $exception, int $schoolId): string
    {
        if (! $exception instanceof CourseOfferingLecturerAllocationConflict) {
            return $exception->getMessage();
        }

        $conflict = $exception->conflictingAllocation;
        $name = User::where('school_id', $schoolId)->whereKey($conflict->user_id)->value('name') ?: 'Another lecturer';
        $role = $this->roleLabels()[$conflict->role] ?? 'teaching';
        $dates = $conflict->starts_on->format('Y-m-d').' – '.($conflict->ends_on?->format('Y-m-d') ?? 'no end date');

        if ($exception->primaryConflict) {
            $next = $conflict->status === CourseOfferingLecturerAllocation::STATUS_ACTIVE
                ? 'Use Replace Primary Lecturer to preserve the existing history.'
                : 'Cancel the conflicting planned Primary Lecturer allocation first.';

            return "Primary Lecturer conflict: {$name} has an overlapping {$role} allocation ({$dates}). {$next}";
        }

        return "Lecturer conflict: {$name} already has an overlapping {$role} allocation ({$dates}). Choose another lecturer or resolve that allocation first.";
    }
}
