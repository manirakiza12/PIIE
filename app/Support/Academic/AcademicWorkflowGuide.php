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
            ['programmes','Programmes','admin.programmes.index','admin/programmes*','Create qualifications, such as a degree or diploma. Student Groups are managed here after curriculum setup.','Create the relevant academic division first.'],
            ['years','Academic Years','admin.academic_structure.index','admin/academic-structure','Set the dated institutional calendar, such as 2026/2027.','Use approved institutional start and end dates.'],
            ['semesters','Semesters / Terms','admin.academic_structure.index','', 'Divide an Academic Year into dated teaching periods.','Create the Academic Year first.'],
            ['grading','Grading Scales','admin.settings.academic','admin/settings/academic*','Define how marks become grades and grade points.','Set the approved grading rules before recording and publishing results.'],
            ['curricula','Programme Curricula','admin.curricula.index','admin/curricula*','Define each programme version, its Years of Study, course-unit placement, credits and prerequisites.','Create a Programme. Create its draft curriculum and Years / Stages, add Course Units, then review and approve. Choose Applies From Academic Year before approval.'],
            ['units','Course Units','admin.subject_list','admin/subject*|admin/course-unit*','Maintain reusable course-unit names, codes and academic rules. Optionally add a unit to a draft curriculum in the same form.','For curriculum placement, first create the Programme Curriculum and its Years / Stages. A catalogue-only unit can be saved independently.'],
            ['groups','Programme Student Groups','admin.programme_cohorts.index','admin/programme-cohorts*','Under Programmes, group an intake of students who follow the same curriculum and manage their academic placement.','Create the Programme, Admissions Intake, starting Academic Year and approved Curriculum before activation. Place each student in the correct Year / Stage.'],
            ['teaching-groups','Teaching Groups','admin.class_list','admin/class_list|admin/class/*|admin/class_create','Organise teaching groups and subgroups used by attendance and existing examination eligibility.','Set up the relevant student and teaching-group records. Use this when the attendance or examination workflow requires teaching groups.'],
            ['offerings','Course Registrations','admin.course_offerings.index','admin/course-offerings*','Offer a Course Unit in a dated Academic Year and Semester, assign its teaching team, and register and confirm students.','Create the Course Unit, Academic Year and Semester. Link approved curriculum placements; students need matching academic placement to register.'],
            ['lecturers','Lecturer Assignments','admin.teacher.permission','admin/permission*','Control lecturers? marks and attendance access across teaching groups or programmes.','Create lecturers and the relevant Programme or Teaching Group. For a specific course unit and semester, assign its lecturer under Course Registrations ? View ? Teaching Team.'],
            ['timetable','Timetable','admin.routine','admin/routine*','Schedule lessons and teaching sessions.','Set up the relevant teaching groups, course units and lecturers first.'],
            ['live','Live Classes','admin.live_classes.index','admin/live-classes*','Schedule and deliver online teaching sessions.','For course-offering sessions, assign the teaching team and confirm student registrations first.'],
            ['attendance','Attendance','admin.daily_attendance','admin/attendance*','Record student participation for the relevant group or teaching session.','Set up the teaching group or course offering and its students first.'],
            ['assignments','Assignments','admin.assignments.index','admin/assignments*','Set coursework and review student submissions.','Set up the course unit, teaching group and lecturer access first.'],
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
            if ($key === 'assignments') continue; // Higher-education coursework belongs to its Course Offering.
            $parameters = $key === 'semesters' ? ['section'=>'semesters'] : [];
            if ($paths && $request->is(...explode('|',$paths))) {
                $current = compact('key','label','purpose','before');
                if ($key === 'years' && $request->query('section') === 'semesters') {
                    $current = ['key'=>'semesters','label'=>'Semesters / Terms','purpose'=>'Divide an Academic Year into dated teaching periods.','before'=>'Create the Academic Year first.'];
                }
            }
            $required = $permissions->routePermission($route);
            $aliases = [$route];
            if ($key === 'divisions') $aliases[] = 'admin.department';
            if ($key === 'live') $aliases[] = 'admin.live_classes';
            if ($key === 'attendance') $aliases[] = 'admin.student_attendance';
            if ($required && $permissions->allows($user,$required) && (!$legacy || array_intersect($aliases,$legacy))) {
                $steps[] = compact('key','label','route','parameters','purpose');
            }
        }
        return $current ? ['current'=>$current,'steps'=>$steps] : null;
    }
}
