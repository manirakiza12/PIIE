{{-- Each entry points to an existing academic workflow and keeps its existing access checks. --}}
@php
    $schoolCalendarPattern = \App\Models\School::query()->find($user->school_id)?->academic_calendar_pattern ?: ($schoolType === 'k12' ? 'term' : 'semester');
    $calendarLabel = ucfirst($schoolCalendarPattern).'s';
    $canSeeAcademicWorkspace = $rbacPermissions->allowsAny($user, [
        'academic.structure', 'academic.structure.manage', 'academic.departments', 'academic.programmes',
        'academic.curriculum.view', 'academic.course_offering.view', 'academic.course_registration.view',
        'academic.routine', 'academic.attendance', 'academic.gradebook', 'academic.assignments',
        'live_classes.view', 'online_exams.view',
    ]);
    $workspaceMenuRoutes = ['admin.department_list', 'admin.programmes.index', 'admin.academic_structure.index', 'admin.settings.academic', 'admin.curricula.index', 'admin.programme_years.index', 'admin.subject_list', 'admin.curriculum_units.index', 'admin.course_offerings.index', 'admin.lecturer_assignments.index', 'admin.course_registrations.index', 'admin.routine', 'admin.daily_attendance', 'admin.live_classes.index', 'admin.assignments.index', 'admin.question_bank.index', 'admin.online_exams', 'admin.online_exams.index', 'admin.gradebook', 'admin.reports.index'];
    $canSeeAcademicWorkspace = $canSeeAcademicWorkspace && (empty($user->menu_permission) || $user->menu_permission === 'null' || (bool) array_intersect($workspaceMenuRoutes, $menu_permission));
    $academicLinks = [
        [$canSeeAcademicWorkspace, 'Academic Dashboard', 'admin.academic.workspace', [], request()->routeIs('admin.academic.workspace') && request()->query('mode') !== 'prepare', 'bi-grid-1x2'],
        [$canSeeProgrammes && $menuAllows('admin.department') && $navCan('admin.department_list'), 'Schools / Faculties', 'admin.department.schools_faculties', [], request()->routeIs('admin.department.schools_faculties', 'admin.department.units.*'), 'bi-building'],
        [$menuAllows('admin.department') && $navCan('admin.department_list'), 'Departments', 'admin.department.departments', [], request()->routeIs('admin.department.departments', 'admin.department.departments.*', 'admin.department.show'), 'bi-diagram-3'],
        [$showProgrammes, 'Programmes', 'admin.programmes.index', [], request()->is('admin/programmes*','admin/programme-cohorts*'), 'bi-mortarboard'],
        [$showAcademicYears, 'Academic Years', 'admin.academic_structure.index', [], (request()->is('admin/academic-structure') && request('section') !== 'semesters') || request()->is('admin/academic-structure/years/*'), 'bi-calendar-range'],
        [$showAcademicYears, $calendarLabel, 'admin.academic_structure.index', ['section'=>'semesters'], (request()->is('admin/academic-structure') && request('section') === 'semesters') || request()->is('admin/academic-structure/periods/*'), 'bi-calendar-week'],
        [$showLegacySessions, 'Class-based Sessions', 'admin.settings.session_manager', [], request()->is('admin/session_manager*'), 'bi-calendar'],
        [$menuAllows('admin.settings.school') && $navCan('admin.settings.academic'), 'Grading Scales', 'admin.settings.academic', [], request()->is('admin/settings/academic*'), 'bi-bar-chart'],
        [$user->hasPermission('academic.curriculum_assignment.view'), 'Curriculum Assignment', 'admin.curriculum_assignment.index', [], request()->routeIs('admin.curriculum_assignment.*'), 'bi-person-check'],
        [$showStudyPlans, 'Programme Curriculum', 'admin.curricula.index', [], request()->is('admin/curricula*'), 'bi-list-check'],
        [$canSeeProgrammes && $menuAllows('admin.programme_years.index', 'admin.curricula.index') && $navCan('admin.programme_years.index'), 'Programme Years', 'admin.programme_years.index', [], request()->is('admin/programme-years*'), 'bi-list-ol'],
        [$showCourseUnits, academic_term('subjects', $user->school_id), 'admin.subject_list', [], request()->is('admin/subject*','admin/course-unit*'), 'bi-journal-bookmark'],
        [!$showCourseUnits && $canSeeProgrammes && $menuAllows('admin.curriculum_units.index', 'admin.curricula.index', 'admin.subject_list') && $navCan('admin.curriculum_units.index'), 'Course Units', 'admin.curriculum_units.index', [], request()->is('admin/curriculum-course-units'), 'bi-journal-bookmark'],
        [$showClasses && academic_education_level($user->school_id) !== 'tertiary', academic_term('classes', $user->school_id), 'admin.class_list', [], request()->is('admin/class_list','admin/class/*','admin/class_create'), 'bi-collection'],
        [!$showCourseRegistrations && academic_education_level($user->school_id) === 'tertiary' && $user->hasPermission('academic.programme_cohort.membership'), 'Student Placement', 'admin.academic.placements.index', [], request()->is('admin/academic/direct-placement', 'admin/academic/student-placements*'), 'bi-person-plus'],
        [$showOfferings, 'Course Allocation', 'admin.course_offerings.index', [], request()->is('admin/course-offerings*', 'admin/lecturer-assignments*') && !request()->routeIs('admin.course_offerings.registrations*', 'admin.course_offerings.eligible_students'), 'bi-journal-check'],
        [!$showOfferings && academic_education_level($user->school_id) === 'tertiary' && $showStandaloneLecturers, 'Lecturer Assignments', 'admin.lecturer_assignments.index', [], request()->routeIs('admin.lecturer_assignments.*', 'admin.course_offerings.lecturers.*'), 'bi-person-check'],
        [academic_education_level($user->school_id) !== 'tertiary' && $showLecturers, academic_term('teacher_assignment', $user->school_id), 'admin.teacher.permission', [], request()->is('admin/permission*'), 'bi-person-check'],
        [$showCourseRegistrations, 'Student Course Registration', 'admin.course_registrations.index', [], request()->routeIs('admin.course_registrations.*', 'admin.course_offerings.registrations*', 'admin.course_offerings.eligible_students') || request()->is('admin/academic/direct-placement', 'admin/academic/student-placements*'), 'bi-person-check-fill'],
        [$showTimetable, 'Timetable', 'admin.routine', [], request()->is('admin/routine*'), 'bi-clock'],
        [$showLiveClasses, 'Live Classes', 'admin.live_classes.index', [], request()->is('admin/live-classes*'), 'bi-camera-video'],
        [$showAttendance, 'Attendance', 'admin.daily_attendance', [], request()->is('admin/attendance*'), 'bi-check2-square'],
        [$showAssignments, 'Class Assignments', 'admin.assignments.index', [], request()->is('admin/assignments*'), 'bi-pencil-square'],
        [$showQuestionBank, 'Question Bank', 'admin.question_bank.index', [], request()->is('admin/question-bank*'), 'bi-question-circle'],
        [$showOnlineExams, 'Online Exams', 'admin.online_exams.index', [], request()->is('admin/online-exams*'), 'bi-laptop'],
        [$showResults, 'Marks & Results', 'admin.gradebook', [], request()->is('admin/gradebook*'), 'bi-clipboard-check'],
        [$navCan('admin.reports.index') && $menuAllows('admin.reports'), 'Academic Reports', 'admin.reports.index', [], request()->is('admin/reports*'), 'bi-file-earmark-bar-graph'],
    ];
    $setupRoutes = ['admin.department.schools_faculties', 'admin.department.departments', 'admin.programmes.index', 'admin.academic_structure.index', 'admin.settings.session_manager', 'admin.settings.academic', 'admin.curricula.index', 'admin.programme_years.index', 'admin.subject_list', 'admin.curriculum_units.index'];
    $groups = [
        'Academic setup' => array_values(array_filter($academicLinks, fn ($link) => $link[0] && in_array($link[2], $setupRoutes, true))),
        'Teaching & learning' => array_values(array_filter($academicLinks, fn ($link) => $link[0] && in_array($link[2], ['admin.course_offerings.index','admin.lecturer_assignments.index','admin.teacher.permission','admin.course_registrations.index','admin.academic.placements.index','admin.routine','admin.live_classes.index','admin.daily_attendance','admin.assignments.index','admin.class_list']) && !in_array($link[2], $setupRoutes, true))),
        'Exams & assessments' => array_values(array_filter($academicLinks, fn ($link) => $link[0] && in_array($link[2], ['admin.question_bank.index','admin.online_exams.index','admin.gradebook']))),
        'Academic reports' => array_values(array_filter($academicLinks, fn ($link) => $link[0] && $link[2] === 'admin.reports.index')),
    ];
    $workspaceLinks = array_values(array_filter($academicLinks, fn ($link) => $link[0] && $link[2] === 'admin.academic.workspace'));
@endphp
@foreach(['' => $workspaceLinks, ...$groups] as $group => $links)
    @if($links)
        @if($group === 'Academic setup')
            <li class="academic-setup-menu"><details @if(collect($links)->contains(fn ($link) => $link[4])) open @endif><summary class="p-3 text-muted">{{ get_phrase($group) }}</summary><ul class="ps-0">
        @elseif($group !== '')
            <li class="academic-workflow-menu"><details @if(collect($links)->contains(fn ($link) => $link[4])) open @endif><summary class="p-3 text-muted">{{ get_phrase($group) }}</summary><ul class="ps-0">
        @endif
        @foreach($links as [$visible, $label, $route, $parameters, $active, $icon])
            @if($visible)
            <li class="nav-links-li"><div class="iocn-link"><a href="{{ route($route, $parameters) }}" class="{{ $active ? 'active' : '' }}" @if($active) aria-current="page" @endif>
                <div class="sidebar_icon"><i class="bi {{ $icon }}"></i></div><span class="link_name">{{ get_phrase($label) }}</span>
            </a></div></li>
            @endif
        @endforeach
        @if($group !== '')</ul></details></li>@endif
    @endif
@endforeach
