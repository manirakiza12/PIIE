<?php
namespace App\Support\Academic;

use App\Models\User;
use App\Support\Permissions\PermissionService;
use Illuminate\Http\Request;

/** Presentation only: the existing services remain authoritative for prerequisites. */
final class AcademicWorkflowGuide
{
    public function steps(): array
    {
        return [
            ['divisions','Schools, Faculties & Departments','admin.department_list','admin/department*','Create the academic divisions that own programmes and course units.','Start with the official institutional structure.'],
            ['programmes','Programmes','admin.programmes.index','admin/programmes*','Create qualifications, such as a degree or diploma. Use Student Course Registration to place and register students together.','Create the relevant academic division first.'],
            ['years','Academic Years','admin.academic_structure.index','admin/academic-structure','Set the dated institutional calendar, such as 2026/2027.','Use approved institutional start and end dates.'],
            ['semesters','Semesters / Terms','admin.academic_structure.index','', 'Divide an Academic Year into dated teaching periods.','Create the Academic Year first.'],
            ['grading','Grading Scales','admin.settings.academic','admin/settings/academic*','Define how marks become grades and grade points.','Set the approved grading rules before recording and publishing results.'],
            ['curricula','Programme Curriculum','admin.curricula.index','admin/curricula*','Define each programme version, its Years of Study, course-unit placement, credits and prerequisites.','Create a Programme. Create its draft curriculum and Programme Years, add Course Units, then review and approve. Choose Applies From Academic Year before approval.'],
            ['programme-years','Programme Years','admin.programme_years.index','admin/programme-years*','Create and maintain the years within each curriculum. These years can be reused across dated Academic Years.','Create a Programme Curriculum first. Staff edit draft years; owners can correct approved or retired years with an audit reason.'],
            ['units','Course Units','admin.subject_list','admin/subject*|admin/course-unit*|admin/curriculum-course-units','Maintain reusable course-unit names, codes and academic rules. Optionally add a unit to a draft curriculum in the same form.','For curriculum placement, first create the Programme Curriculum and its Programme Years. A catalogue-only unit can be saved independently.'],
            ['groups','Student Placement','admin.academic.placements.index','admin/academic/direct-placement|admin/academic/student-placements*','Create, view and correct unused student placements in an approved curriculum and Programme Year. Matching course allocations review these students under Not Registered.','Create the student account and approve the curriculum first. Registration is a separate action after placement.'],
            ['offerings','Course Allocation','admin.course_offerings.index','admin/course-offerings*','Allocate a course unit to an Academic Year and Semester, then manage its lecturers in the same workspace.','Create the Course Unit, Academic Year and Semester. Link the matching approved curriculum placement.'],
            ['lecturers','Lecturer Assignments','admin.lecturer_assignments.index','admin/lecturer-assignments*','Assign lecturers and manage their roles and teaching dates for a Course Allocation.','Open the Course Allocation and manage its teaching team there.'],
            ['registrations','Student Course Registration','admin.course_registrations.index','admin/course-registrations*','Choose a programme and teaching period, then register and confirm students together on one page.','Students need a programme on their profile, an approved curriculum and allocated courses. Missing placements can be saved as part of registration.'],
            ['timetable','Timetable','admin.routine','admin/routine*','Schedule lessons and teaching sessions.','Set up the course units, lecturers and student registrations first.'],
            ['live','Live Classes','admin.live_classes.index','admin/live-classes*','Schedule and deliver online teaching sessions.','For course-offering sessions, assign the teaching team and confirm student registrations first.'],
            ['attendance','Attendance','admin.daily_attendance','admin/attendance*','Record student participation for the relevant teaching session.','Set up the course allocation and confirm its student registrations first.'],
            ['assignments','Assignments','admin.assignments.index','admin/assignments*','Set coursework and review student submissions.','Set up the course unit, course registration and teaching team first.'],
            ['questions','Question Bank','admin.question_bank.index','admin/question-bank*','Prepare reusable examination questions.','Create the relevant course units before adding their questions.'],
            ['exams','Online Exams','admin.online_exams.index','admin/online-exams*','Create, publish and administer online examinations.','Set up course units, questions and the student eligibility required by the chosen examination workflow.'],
            ['marks','Marks & Results','admin.gradebook','admin/gradebook*','Enter, review and publish academic marks and results.','Set up the grading scale, course units, eligible students and lecturer access before processing marks.'],
        ];
    }

    public function forRequest(User $user, Request $request): ?array
    {
        $paths = explode('|', implode('|', array_filter(array_column($this->steps(), 3))));
        if (!$request->is(...$paths)) return null;
        if (academic_education_level($user->school_id) !== 'tertiary') return null;
        $permissions = app(PermissionService::class);
        $legacy = json_decode((string)$user->menu_permission, true) ?: [];
        $steps = []; $current = null;
        foreach ($this->steps() as [$key,$label,$route,$paths,$purpose,$before]) {
            if ($key === 'assignments') continue; // Higher-education coursework belongs to its Course Allocation.
            $parameters = $key === 'semesters' ? ['section'=>'semesters'] : [];
            if ($paths && $request->is(...explode('|',$paths))) {
                $current = compact('key','label','purpose','before');
                if ($key === 'years' && $request->query('section') === 'semesters') {
                    $current = ['key'=>'semesters','label'=>'Semesters / Terms','purpose'=>'Divide an Academic Year into dated teaching periods.','before'=>'Create the Academic Year first.'];
                }
            }
            if ($key === 'units' && !$permissions->allows($user, 'academic.structure') && $permissions->allows($user, 'academic.curriculum.view')) {
                $route = 'admin.curriculum_units.index';
            }
            $required = $permissions->routePermission($route);
            $aliases = [$route];
            if ($key === 'groups') $aliases = [...$aliases, 'admin.academic.direct_placement', 'admin.programme_cohorts.index'];
            if ($key === 'programme-years') $aliases[] = 'admin.curricula.index';
            if ($key === 'units' && $route === 'admin.curriculum_units.index') $aliases = [...$aliases, 'admin.subject_list', 'admin.curricula.index'];
            if ($key === 'lecturers') $aliases = [...$aliases, 'admin.teacher.permission', 'admin.course_offerings.index'];
            if ($key === 'registrations') $aliases[] = 'admin.course_offerings.index';
            if ($key === 'divisions') $aliases[] = 'admin.department';
            if ($key === 'live') $aliases[] = 'admin.live_classes';
            if ($key === 'attendance') $aliases[] = 'admin.student_attendance';
            if (!in_array($key, ['groups', 'lecturers'], true) && $required && $permissions->allows($user,$required) && (!$legacy || array_intersect($aliases,$legacy))) {
                $steps[] = compact('key','label','route','parameters','purpose');
            }
        }
        if ($request->routeIs('admin.course_offerings.lecturers.*')) {
            $current = ['key'=>'lecturers', 'label'=>'Lecturer Assignments', 'purpose'=>'Assign lecturers and maintain their teaching roles and dates.', 'before'=>'Create lecturer accounts and the Course Allocation first.'];
        } elseif ($request->routeIs('admin.course_offerings.registrations*', 'admin.course_offerings.eligible_students')) {
            $current = ['key'=>'registrations', 'label'=>'Student Course Registration', 'purpose'=>'Set missing years of study, register students and confirm their courses in Student Course Registration.', 'before'=>'Select the programme, year of study and teaching period. Existing placements are preserved.'];
        }
        if (($current['key'] ?? null) === 'groups') {
            $current = ['key'=>'registrations', 'label'=>'Student Course Registration', 'purpose'=>'Review placement history or correct a student placement.', 'before'=>'For normal registration, set missing years of study in Student Course Registration.'];
        } elseif (($current['key'] ?? null) === 'lecturers') {
            $current = ['key'=>'offerings', 'label'=>'Course Allocation', 'purpose'=>'Manage the lecturers for this Course Allocation.', 'before'=>'Choose the allocation and assign its teaching team.'];
        }
        return $current ? ['current'=>$current,'steps'=>$steps] : null;
    }
}
