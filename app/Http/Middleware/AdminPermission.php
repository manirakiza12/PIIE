<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class AdminPermission
{
    /**
     * Maps each menu-permission entry to the action routes it also covers.
     * These action routes don't have their own permission checkbox, so a
     * permitted user's menu_permission list only ever contains the parent
     * (index) route name below.
     *
     * @var array<string, array<int, string>>
     */
    private const MODULE_ROUTES = [
        'admin.academic_structure.index' => [
            'admin.academic_structure.show',
            'admin.academic_structure.edit',
            'admin.academic_structure.update',
            'admin.academic_structure.destroy',
            'admin.academic_structure.years.store',
            'admin.academic_structure.periods.store',
            'admin.academic_structure.years.status',
            'admin.academic_structure.periods.status',
            'admin.academic_structure.current',
        ],
        'admin.department_list' => [
            'admin.department.schools_faculties',
            'admin.department.departments',
            'admin.department.departments.create',
            'admin.department.departments.edit',
            'admin.department.show',
            'admin.department.units.create',
            'admin.department.units.store',
            'admin.department.units.show',
            'admin.department.units.edit',
            'admin.department.units.update',
            'admin.department.open_modal',
            'admin.create.department',
            'admin.edit.department',
            'admin.department.update',
            'admin.department.delete',
        ],
        'admin.exam_category' => [
            'admin.exam_category.open_modal',
            'admin.create.exam_category',
            'admin.edit.exam_category',
            'admin.exam_category.update',
            'admin.exam_category.delete',
        ],
        'admin.offline_exam' => [
            'admin.offline_exam.export',
            'admin.offline_exam.open_modal',
            'admin.create.offline_exam',
            'admin.edit.offline_exam',
            'admin.offline_exam.update',
            'admin.offline_exam.delete',
            'admin.class_wise_exam_list',
        ],
        'admin.daily_attendance' => [
            'admin.take_attendance.open_modal',
            'admin.attendance_take',
            'admin.attendance.student',
            'admin.daily_attendance.filter',
            'admin.dailyAttendanceFilter_csv',
        ],
        'admin.routine' => [
            'admin.routine.open_modal',
            'admin.routine.routine_add',
            'admin.routine.routine_list',
            'admin.routine_edit_modal',
            'admin.routine.update',
            'admin.routine.delete',
        ],
        'admin.syllabus' => [
            'admin.syllabus.open_modal',
            'admin.syllabus.syllabus_add',
            'admin.syllabus.syllabus_list',
            'admin.syllabus_edit_modal',
            'admin.syllabus.update',
            'admin.syllabus.delete',
        ],
        'admin.gradebook' => [
            'admin.gradebook.list',
            'admin.gradebook.subject_wise_marks',
            'admin.exam_mark.open_modal',
            'admin.add.exam_mark',
        ],
        'admin.settings.payment' => [
            'admin.settings.payment_post',
        ],
        'admin.marks' => [
            'admin.marks.list',
            'admin.marks.list_pdf',
        ],
        'admin.grade_list' => [
            'admin.grade.open_modal',
            'admin.create.grade',
            'admin.edit.grade',
            'admin.grade.update',
            'admin.grade.delete',
        ],
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        if(empty(Auth()->user()->menu_permission) && Auth()->user() ){
            return $next($request);
        }else{
            $user_permitted_routes = json_decode(Auth()->user()->menu_permission, true);

            $current_route = Route::currentRouteName();

            if ($this->isPermitted($current_route, $user_permitted_routes) && Auth()->user()->menu_permission != 'null') {
                return $next($request);
            }else{
                return redirect()->back();
            }
        }
    }

    private function isPermitted(?string $currentRoute, $permittedRoutes): bool
    {
        if (!is_array($permittedRoutes) || empty($currentRoute)) {
            return false;
        }

        if (in_array($currentRoute, $permittedRoutes, true)) {
            return true;
        }

        if (str_starts_with($currentRoute, 'admin.online_exams.')) {
            return in_array('admin.online_exams', $permittedRoutes, true)
                || in_array('admin.online_exams.index', $permittedRoutes, true);
        }

        if ($currentRoute === 'admin.online_exams') {
            return in_array('admin.online_exams.index', $permittedRoutes, true);
        }

        if ($currentRoute === 'admin.online_exams.index') {
            return in_array('admin.online_exams', $permittedRoutes, true);
        }

        foreach (self::MODULE_ROUTES as $parentRoute => $childRoutes) {
            if (in_array($currentRoute, $childRoutes, true)) {
                return in_array($parentRoute, $permittedRoutes, true);
            }
        }

        return false;
    }
}
