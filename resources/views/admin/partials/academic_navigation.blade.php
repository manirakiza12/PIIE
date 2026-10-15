{{-- One direct entry per workflow. Existing route and permission checks stay authoritative. --}}
@php
    $calendarLabel = ucfirst(\App\Models\School::query()->find($user->school_id)?->academic_calendar_pattern ?: ($schoolType === 'k12' ? 'term' : 'semester')).'s';
    $academicLinks = [
        [$showAcademicYears, 'Academic Years', 'admin.academic_structure.index', [], request()->is('admin/academic-structure') && request('section') !== 'semesters', 'bi-calendar-range'],
        [$showAcademicYears, $calendarLabel, 'admin.academic_structure.index', ['section'=>'semesters'], request()->is('admin/academic-structure') && request('section') === 'semesters', 'bi-calendar-week'],
        [$menuAllows('admin.department') && $navCan('admin.department_list'), $canSeeProgrammes ? 'Schools, Faculties & Departments' : 'Departments', 'admin.department_list', [], request()->is('admin/department*'), 'bi-building'],
        [$showLegacySessions, 'Academic Sessions', 'admin.settings.session_manager', [], request()->is('admin/session_manager*'), 'bi-calendar'],
        [$showProgrammes, 'Programmes', 'admin.programmes.index', [], request()->is('admin/programmes*'), 'bi-mortarboard'],
        [$showCourseUnits, academic_term('subjects', $user->school_id), 'admin.subject_list', [], request()->is('admin/subject*','admin/course-unit*'), 'bi-journal-bookmark'],
        [$showStudyPlans, 'Programme Curricula', 'admin.curricula.index', [], request()->is('admin/curricula*'), 'bi-list-check'],
        [$showCohorts, 'Programme Student Groups', 'admin.programme_cohorts.index', [], request()->is('admin/programme-cohorts*'), 'bi-people'],
        [$showOfferings, 'Course Registrations', 'admin.course_offerings.index', [], request()->is('admin/course-offerings*'), 'bi-journal-check'],
        [$showResults, 'Marks & Results', 'admin.gradebook', [], request()->is('admin/gradebook*'), 'bi-clipboard-check'],
        [$menuAllows('admin.settings.school') && $navCan('admin.settings.academic'), 'Grading Scales', 'admin.settings.academic', [], request()->is('admin/settings/academic*'), 'bi-bar-chart'],
        [$showTimetable, 'Timetable', 'admin.routine', [], request()->is('admin/routine*'), 'bi-clock'],
        [$showLecturers, academic_term('teacher_assignment', $user->school_id), 'admin.teacher.permission', [], request()->is('admin/permission*'), 'bi-person-check'],
        [$showOnlineExams, 'Online Exams', 'admin.online_exams.index', [], request()->is('admin/online-exams*'), 'bi-laptop'],
        [$showQuestionBank, 'Question Bank', 'admin.question_bank.index', [], request()->is('admin/question-bank*'), 'bi-question-circle'],
        [$showLiveClasses, 'Live Classes', 'admin.live_classes.index', [], request()->is('admin/live-classes*'), 'bi-camera-video'],
        [$showAttendance, 'Attendance', 'admin.daily_attendance', [], request()->is('admin/attendance*'), 'bi-check2-square'],
        [$showClasses, 'Classes', 'admin.class_list', [], request()->is('admin/class_list','admin/class/*','admin/class_create'), 'bi-collection'],
        [$showAssignments, 'Assignments', 'admin.assignments.index', [], request()->is('admin/assignments*'), 'bi-pencil-square'],
    ];
@endphp
@foreach($academicLinks as [$visible, $label, $route, $parameters, $active, $icon])
    @if($visible)
    <li class="nav-links-li">
        <div class="iocn-link"><a href="{{ route($route, $parameters) }}" class="{{ $active ? 'active' : '' }}" @if($active) aria-current="page" @endif>
            <div class="sidebar_icon"><i class="bi {{ $icon }}"></i></div><span class="link_name">{{ get_phrase($label) }}</span>
        </a></div>
    </li>
    @endif
@endforeach
