<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\Programme;
use App\Models\User;
use Illuminate\Http\Request;

/** Independent entry pages reuse the authoritative delivery controllers. */
class AcademicDeliveryWorkspaceController extends Controller
{
    public function registrations(Request $request)
    {
        if (! $request->filled('allocation_id')) {
            return app(ProgrammeRegistrationController::class)->index($request, app(\App\Support\CourseRegistration\CourseOfferingRoster::class));
        }
        return $this->workspace($request, 'Course Registrations', 'admin.course_registrations.index', 'registrations');
    }

    public function lecturers(Request $request)
    {
        return $this->workspace($request, 'Lecturer Assignments', 'admin.lecturer_assignments.index', 'lecturers');
    }

    private function workspace(Request $request, string $title, string $route, string $module)
    {
        $schoolId = (int) $request->user()->school_id;
        $selectedLecturer = null;
        $programmes = collect();
        if ($module === 'lecturers') {
            $request->validate(['lecturer_id' => 'nullable|integer|min:1', 'programme_id' => 'nullable|integer|min:1']);
            $programmes = Programme::where('school_id', $schoolId)->orderBy('name')->get(['id', 'name', 'code']);
            if ($request->filled('programme_id')) {
                abort_unless($programmes->contains('id', $request->integer('programme_id')), 404);
            }
            if ($request->filled('lecturer_id')) {
                abort_unless($request->user()->hasPermission('academic.course_offering.lecturer.manage'), 403);
                $selectedLecturer = User::where('school_id', $schoolId)->where('role_id', 3)->findOrFail($request->integer('lecturer_id'));
                $title = 'Assign Lecturer to Another Programme';
            }
        }
        if ($request->filled('allocation_id')) {
            $offering = CourseOffering::where('school_id', $schoolId)->findOrFail($request->integer('allocation_id'));
            $request->attributes->set('academic_standalone_module', $module);
            return $module === 'lecturers'
                ? app(CourseOfferingLecturerController::class)->index($request, (int) $offering->id)
                : ($request->query('view', 'eligible') === 'eligible'
                    ? app(CourseOfferingController::class)->eligibleStudents($request, (int) $offering->id)
                    : app(CourseOfferingController::class)->registeredStudents($request, (int) $offering->id));
        }
        $allocations = CourseOffering::where('school_id', $schoolId)
            ->with(['subject', 'academicYear', 'academicPeriod'])
            ->when($selectedLecturer, fn ($q) => $q->whereIn('status', ['draft', 'open', 'in_progress']))
            ->when($module === 'lecturers' && $request->filled('programme_id'), fn ($q) => $q->whereExists(function ($links) use ($request, $schoolId) {
                $links->selectRaw('1')->from('course_offering_curriculum_memberships as links')
                    ->join('curricula', 'curricula.id', '=', 'links.curriculum_id')
                    ->whereColumn('links.course_offering_id', 'course_offerings.id')
                    ->where('links.school_id', $schoolId)->where('curricula.school_id', $schoolId)
                    ->where('curricula.programme_id', $request->integer('programme_id'));
            }))
            ->when($request->filled('search'), fn ($q) => $q->where('reference', 'like', '%'.trim((string) $request->query('search')).'%'))
            ->orderByDesc('created_at')->paginate(25)->withQueryString();
        $assignedAllocationIds = $selectedLecturer
            ? CourseOfferingLecturerAllocation::where('school_id', $schoolId)->where('user_id', $selectedLecturer->id)
                ->whereIn('course_offering_id', $allocations->pluck('id'))->whereIn('status', ['planned', 'active'])
                ->pluck('course_offering_id')->map(fn ($id) => (int) $id)->all()
            : [];
        return view('admin.academic.delivery_workspace', compact('title', 'route', 'module', 'allocations', 'selectedLecturer', 'programmes', 'assignedAllocationIds'));
    }
}
