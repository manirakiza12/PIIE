<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Keep retired academic workspaces inaccessible, including bookmarked mutation URLs. */
class RetireAcademicGroups
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) return $next($request);

        $action = $request->route()?->getActionName() ?? '';
        $tertiary = academic_education_level((int) $user->school_id) === 'tertiary';
        $destination = null;

        if (str_contains($action, 'ProgrammeCohortController@')) {
            if (! $tertiary) abort(404);
            $destination = 'admin.academic.direct_placement';
        } elseif ($tertiary && str_contains($action, '\\AssignmentController@')) {
            $destination = match ((int) $user->role_id) {
                7 => 'student.my_courses',
                3 => 'teacher.course_offerings.index',
                default => 'admin.course_offerings.index',
            };
        } elseif ($tertiary && in_array($request->route()?->getActionMethod(), [
            'classWiseSections', 'classWiseSubject', 'classWiseStudents',
            'classWiseStudentsInvoice',
        ], true)) {
            abort(410, 'Class targeting is unavailable. Use programme placement and course registrations.');
        } elseif ($tertiary && str_contains($action, 'AdminController@') && in_array($request->route()->getActionMethod(), [
            'classList', 'createClass', 'classCreate', 'editClass', 'classUpdate',
            'editSection', 'sectionUpdate', 'classDelete', 'teacherPermission',
            'teacherPermissionList', 'teacherPermissionUpdate',
            'dailyAttendanceFilter', 'takeAttendance', 'studentListAttendance',
            'attendanceTake', 'dailyAttendanceFilter_csv',
            'routine', 'routineList', 'addRoutine', 'routineAdd', 'routineEditModal', 'routineUpdate', 'routineDelete',
            'marks', 'marksFilter', 'marksPdf', 'gradebook', 'gradebookList', 'subjectWiseMarks',
            'offlineExamList', 'offlineExamExport', 'classWiseOfflineExam', 'createOfflineExam',
            'offlineExamCreate', 'editOfflineExam', 'offlineExamUpdate', 'offlineExamDelete',
            'classWiseSections', 'classWiseSubject', 'classWiseStudents', 'classWiseStudentsInvoice',
        ], true)) {
            $destination = 'admin.course_offerings.index';
        } elseif ($tertiary && str_contains($action, 'TeacherController@') && in_array($request->route()->getActionMethod(), [
            'marks', 'marksFilter', 'offlineExamList', 'offlineExamExport', 'classWiseOfflineExam',
            'routine', 'routineList', 'subjectList', 'createSubject', 'subjectCreate',
            'gradebook', 'gradebookList', 'subjectWiseMarks', 'list_of_syllabus',
            'class_wise_section_for_syllabus', 'syllabus_details', 'show_syllabus_modal',
            'show_syllabus_modal_post', 'syllabusDelete',
            'dailyAttendanceFilter', 'takeAttendance', 'studentListAttendance',
            'attendanceTake', 'dailyAttendanceFilter_csv',
        ], true)) {
            $destination = 'teacher.course_offerings.index';
        }

        if ($destination === null) return $next($request);

        // Legacy endpoints sometimes mutate through GET. Never execute their controllers.
        if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax()) {
            abort(410, 'This workspace has been retired. Use programme placement and course registrations.');
        }

        return redirect()->route($destination);
    }
}
