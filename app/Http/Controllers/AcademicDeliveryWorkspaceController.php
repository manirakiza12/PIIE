<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use Illuminate\Http\Request;

/** Independent entry pages reuse the authoritative delivery controllers. */
class AcademicDeliveryWorkspaceController extends Controller
{
    public function registrations(Request $request)
    {
        return $this->workspace($request, 'Course Registrations', 'admin.course_registrations.index', 'registrations');
    }

    public function lecturers(Request $request)
    {
        return $this->workspace($request, 'Lecturer Assignments', 'admin.lecturer_assignments.index', 'lecturers');
    }

    private function workspace(Request $request, string $title, string $route, string $module)
    {
        $schoolId = (int) $request->user()->school_id;
        if ($request->filled('allocation_id')) {
            $offering = CourseOffering::where('school_id', $schoolId)->findOrFail($request->integer('allocation_id'));
            $request->attributes->set('academic_standalone_module', $module);
            return $module === 'lecturers'
                ? app(CourseOfferingLecturerController::class)->index($request, (int) $offering->id)
                : ($request->query('view') === 'eligible'
                    ? app(CourseOfferingController::class)->eligibleStudents($request, (int) $offering->id)
                    : app(CourseOfferingController::class)->registeredStudents($request, (int) $offering->id));
        }
        $allocations = CourseOffering::where('school_id', $schoolId)
            ->with(['subject', 'academicYear', 'academicPeriod'])
            ->when($request->filled('search'), fn ($q) => $q->where('reference', 'like', '%'.trim((string) $request->query('search')).'%'))
            ->orderByDesc('created_at')->paginate(25)->withQueryString();
        return view('admin.academic.delivery_workspace', compact('title', 'route', 'module', 'allocations'));
    }
}
