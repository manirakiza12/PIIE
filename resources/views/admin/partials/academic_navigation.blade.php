{{-- One direct entry per workflow. Existing route and permission checks stay authoritative. --}}
@php
    $calendarLabel = ucfirst(\App\Models\School::query()->find($user->school_id)?->academic_calendar_pattern ?: ($schoolType === 'k12' ? 'term' : 'semester')).'s';
    $academicLinks = [
        [$canSeeProgrammes && $menuAllows('admin.department') && $navCan('admin.department_list'), 'Schools / Faculties', 'admin.department.schools_faculties', [], request()->routeIs('admin.department.schools_faculties', 'admin.department.units.*'), 'bi-building'],
        [$menuAllows('admin.department') && $navCan('admin.department_list'), 'Departments', 'admin.department.departments', [], request()->routeIs('admin.department.departments', 'admin.department.departments.*', 'admin.department.show'), 'bi-diagram-3'],
        [$showProgrammes, 'Programmes', 'admin.programmes.index', [], request()->is('admin/programmes*','admin/programme-cohorts*'), 'bi-mortarboard'],
        [$showAcademicYears, 'Academic Years', 'admin.academic_structure.index', [], (request()->is('admin/academic-structure') && request('section') !== 'semesters') || request()->is('admin/academic-structure/years/*'), 'bi-calendar-range'],
        [$showAcademicYears, $calendarLabel, 'admin.academic_structure.index', ['section'=>'semesters'], (request()->is('admin/academic-structure') && request('section') === 'semesters') || request()->is('admin/academic-structure/periods/*'), 'bi-calendar-week'],
        [$showLegacySessions, 'Academic Sessions', 'admin.settings.session_manager', [], request()->is('admin/session_manager*'), 'bi-calendar'],
        [$menuAllows('admin.settings.school') && $navCan('admin.settings.academic'), 'Grading Scales', 'admin.settings.academic', [], request()->is('admin/settings/academic*'), 'bi-bar-chart'],
        [$showStudyPlans, 'Programme Curriculum', 'admin.curricula.index', [], request()->is('admin/curricula*'), 'bi-list-check'],
        [$canSeeProgrammes && $menuAllows('admin.programme_years.index', 'admin.curricula.index') && $navCan('admin.programme_years.index'), 'Programme Years', 'admin.programme_years.index', [], request()->is('admin/programme-years*'), 'bi-list-ol'],
        [$showCourseUnits, academic_term('subjects', $user->school_id), 'admin.subject_list', [], request()->is('admin/subject*','admin/course-unit*'), 'bi-journal-bookmark'],
        [!$showCourseUnits && $canSeeProgrammes && $menuAllows('admin.curriculum_units.index', 'admin.curricula.index', 'admin.subject_list') && $navCan('admin.curriculum_units.index'), 'Course Units', 'admin.curriculum_units.index', [], request()->is('admin/curriculum-course-units'), 'bi-journal-bookmark'],
        [$showClasses && academic_education_level($user->school_id) !== 'tertiary', academic_term('classes', $user->school_id), 'admin.class_list', [], request()->is('admin/class_list','admin/class/*','admin/class_create'), 'bi-collection'],
        [academic_education_level($user->school_id) === 'tertiary' && $user->hasPermission('academic.programme_cohort.membership'), 'Place Student in Programme', 'admin.academic.direct_placement', [], request()->is('admin/academic/direct-placement'), 'bi-person-plus'],
        [$showOfferings, 'Course Allocation', 'admin.course_offerings.index', [], request()->routeIs('admin.course_offerings.index', 'admin.course_offerings.show', 'admin.course_offerings.create'), 'bi-journal-check'],
        [academic_education_level($user->school_id) === 'tertiary' && $showStandaloneLecturers, 'Lecturer Assignments', 'admin.lecturer_assignments.index', [], request()->routeIs('admin.lecturer_assignments.*', 'admin.course_offerings.lecturers.*'), 'bi-person-check'],
        [academic_education_level($user->school_id) !== 'tertiary' && $showLecturers, academic_term('teacher_assignment', $user->school_id), 'admin.teacher.permission', [], request()->is('admin/permission*'), 'bi-person-check'],
        [$showCourseRegistrations, 'Course Registrations', 'admin.course_registrations.index', [], request()->routeIs('admin.course_registrations.*', 'admin.course_offerings.registrations*', 'admin.course_offerings.eligible_students'), 'bi-person-check-fill'],
        [$showTimetable, 'Timetable', 'admin.routine', [], request()->is('admin/routine*'), 'bi-clock'],
        [$showLiveClasses, 'Live Classes', 'admin.live_classes.index', [], request()->is('admin/live-classes*'), 'bi-camera-video'],
        [$showAttendance, 'Attendance', 'admin.daily_attendance', [], request()->is('admin/attendance*'), 'bi-check2-square'],
        [$showAssignments, 'Assignments', 'admin.assignments.index', [], request()->is('admin/assignments*'), 'bi-pencil-square'],
        [$showQuestionBank, 'Question Bank', 'admin.question_bank.index', [], request()->is('admin/question-bank*'), 'bi-question-circle'],
        [$showOnlineExams, 'Online Exams', 'admin.online_exams.index', [], request()->is('admin/online-exams*'), 'bi-laptop'],
        [$showResults, 'Marks & Results', 'admin.gradebook', [], request()->is('admin/gradebook*'), 'bi-clipboard-check'],
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
