@php
    use App\Models\User;
    use App\Support\Permissions\OnlineExamPermissionService;

    $user = Auth()->user();
    // Classes (legacy K-12 class/section structure) don't apply to a
    // purely higher_ed school — those use Programmes/Courses instead.
    // 'k12' and 'mixed' schools (and the 'k12' column default) see it.
    $schoolType = \Illuminate\Support\Facades\DB::table('schools')->where('id', $user->school_id)->value('school_type') ?? 'k12';
    $canSeeClasses = true;
    // Symmetric to $canSeeClasses: Programmes/Courses don't apply to a
    // purely k12 school. 'higher_ed' and 'mixed' schools see them.
    $canSeeProgrammes = $schoolType !== 'k12';
    $faviconSetting = trim((string) get_settings('favicon'));
    $whiteLogoSetting = trim((string) get_settings('white_logo'));
    $darkLogoSetting = trim((string) get_settings('dark_logo'));
    
    $faviconAsset = $faviconSetting !== ''
        ? asset('assets/uploads/logo/' . $faviconSetting)
        : asset('assets/uploads/logo/favicon.png');
        
    // Try white logo first, fallback to dark logo, then default
    $logoAsset = institution_logo_url();
            
    $menu_permission =
        empty($user->menu_permission) || $user->menu_permission == 'null'
            ? []
            : json_decode($user->menu_permission, true);

    // Role-based nav permissions (used when menu_permission is empty)
    $roleNavPerms = function_exists('get_role_nav_permissions')
        ? get_role_nav_permissions((int) $user->role_id)
        : ['all'];

    // canSee: returns true if this nav section should be visible
    $canSee = function(string $section) use ($menu_permission, $roleNavPerms): bool {
        // If menu_permission is set (legacy per-user config), use it
        if (!empty($menu_permission)) {
            return in_array($section, $menu_permission);
        }
        // Otherwise use role-based permissions
        return in_array('all', $roleNavPerms) || in_array($section, $roleNavPerms);
    };

    // Build the effective permission array used by legacy @if checks.
    // When roleNavPerms is 'all', keep $menu_permission empty so existing
    // `empty($user->menu_permission)` checks evaluate to true (show everything).
    // For restricted roles, populate it with allowed keys so existing checks work.
    $isAdminOrSuper = in_array($user->role_id, [1, 2]);
    if (!$isAdminOrSuper && empty($menu_permission) && !in_array('all', $roleNavPerms)) {
        // Map role nav permission keys → legacy menu_permission keys
        $navToMenuKeys = [
            'students'         => ['admin.student', 'admin.parent'],
            'staff'            => ['admin.admin', 'admin.teacher', 'admin.accountant', 'admin.librarian', 'admin.warden'],
            'attendance'       => ['admin.student_attendance', 'admin.teacher_attendance'],
            'admissions'       => ['admin.offline_admission.single'],
            'hei_admissions'   => ['admin.hei_admissions'],
            'intake_sessions'  => ['admin.intake_sessions'],
            'admissions_agents'=> ['admin.admissions_agents'],
            'programmes'       => ['admin.programmes'],
            'enrolment'        => ['admin.enrolment'],
            'online_exams'     => ['admin.online_exams', 'admin.online_exams.index'],
            'exams'            => ['admin.exam', 'admin.exam_category', 'admin.admit_card'],
            'assignments'      => ['admin.assignments'],
            'live_classes'     => ['admin.live_classes'],
            'gradebook'        => ['admin.gradebook'],
            'question_bank'    => ['admin.question_bank', 'admin.question_bank.index'],
            'transcripts'      => ['admin.transcripts'],
            'graduation'       => ['admin.graduation'],
            'fees'             => ['admin.fee', 'admin.fee_structures'],
            'fee_structures'   => ['admin.fee_structures'],
            'payments'         => ['admin.payment'],
            'expenses'         => ['admin.expense'],
            'payroll'          => ['admin.payroll'],
            'salary_structures'=> ['admin.salary_structures'],
            'hostel_fee'       => ['admin.hostel'],
            'library'          => ['admin.library'],
            'assets'           => ['admin.assets'],
            'asset_categories' => ['admin.asset_categories'],
            'procurement'      => ['admin.procurement'],
            'leave'            => ['admin.leave'],
            'leave_types'      => ['admin.leave_types'],
            'appraisal'        => ['admin.appraisal'],
            'departments'      => ['admin.department'],
            'routine'          => ['admin.routine'],
            'academic_calendar'=> ['admin.academic_calendar'],
            'noticeboard'      => ['admin.noticeboard'],
            'chat'             => ['admin.chat'],
            'reports'          => ['admin.reports'],
            'settings'         => ['admin.setting'],
        ];
        foreach ($roleNavPerms as $key) {
            if (isset($navToMenuKeys[$key])) {
                $menu_permission = array_merge($menu_permission, $navToMenuKeys[$key]);
            }
        }
        // Always allow dashboard
        $menu_permission[] = 'admin.dashboard';
    }

    $onlineExamPermissionService = app(OnlineExamPermissionService::class);
    $canViewOnlineExamsNav = $onlineExamPermissionService->hasAny($user, [
        'view_online_exams',
        'create_online_exams',
        'view_exam_attempts',
        'view_exam_results',
    ]);
    $canManageQuestionBankNav = $onlineExamPermissionService->has($user, 'manage_exam_questions');
    // Online Exams still use class/enrollment eligibility in this installation,
    // so administrators must be able to configure those records even when the
    // school is otherwise classified as higher education.
    if ($canViewOnlineExamsNav) {
        $canSeeClasses = true;
    }

    // RBAC Phase 3B: a menu item is shown only if the user can actually open its destination.
    // Routes mapped in the permission registry need that permission (base role, custom role or
    // direct grant); unmapped routes (directory pages, dashboard, Online Exams / Live Classes,
    // which keep their own checks) are unaffected. Hiding a menu is convenience only — the
    // backend ('rbac' middleware, policies, controllers) is what enforces access.
    // users.menu_permission remains the legacy per-user restriction layer read above; new
    // grants never go there (they live in the RBAC tables).
    $rbacPermissions = app(\App\Support\Permissions\PermissionService::class);
    $navCan = function (string $routeName) use ($rbacPermissions, $user): bool {
        $required = $rbacPermissions->routePermission($routeName);

        if ((int) $user->role_id === \App\Support\Roles\SystemRole::GENERIC_STAFF && $required === null) {
            return false;
        }

        return $required === null || $rbacPermissions->allows($user, $required);
    };

    // Legacy menu key check, used by every "may this user see it" flag below.
    // `menu_permission` is the legacy per-user restriction layer; an empty value
    // means "no per-user restriction", which is what admin/super admin have.
    $menuAllows = fn (string ...$keys): bool => empty($user->menu_permission)
        || count(array_intersect($keys, $menu_permission)) > 0;

    /*
    | Two academic modules are K-12 leftovers that a purely higher_ed school has
    | no use for, so they are hidden from its sidebar. Their routes, controllers
    | and data are untouched — this only hides the menu entry.
    |
    |   Assignments       → superseded by Course Offering assignments.
    |                       AssignmentController@index deliberately lists
    |                       Assignment::k12() rows ONLY, so on a programme-based
    |                       school the screen can never show a real assignment.
    |   Academic Sessions → superseded by Academic Years & Periods. The legacy
    |                       `sessions` table is bridged onto AcademicYear by
    |                       AcademicContextManager::mapLegacySession().
    |
    | Keyed on the school type, NOT on $canSeeClasses: that flag is also forced
    | on for higher_ed schools whose staff can reach Online Exams, which use
    | class/enrollment eligibility, and reusing it would have shown both
    | leftovers exactly where they are meant to disappear. 'mixed' schools run
    | class-based and programme-based delivery side by side, so they keep them.
    */
    $canSeeLegacyAcademic = $schoolType !== 'higher_ed';

    // True when any of the given URL patterns is the page being viewed. Used to
    // open the sub-menu that owns the current page, so a grouped nav keeps its
    // parent expanded and its child highlighted.
    $onAny = fn (array $patterns): bool => collect($patterns)->contains(fn ($p) => request()->is($p));

    // ---- Admission (rendered by the self-contained partial) ----
    // ---- Academic: structure ----
    $showAcademicYears = $menuAllows('admin.academic_structure.index') && $navCan('admin.academic_structure.index');
    $showLegacySessions = $canSeeLegacyAcademic && $menuAllows('admin.settings.session_manager') && $navCan('admin.settings.session_manager');
    $showProgrammes = $canSeeProgrammes && $navCan('admin.programmes.index');
    $showCourseUnits = $menuAllows('admin.subject_list') && $navCan('admin.subject_list');
    $showStudyPlans = $canSeeProgrammes && $menuAllows('admin.curricula.index') && $navCan('admin.curricula.index');
    $showCohorts = $canSeeProgrammes && $menuAllows('admin.programme_cohorts.index') && $navCan('admin.programme_cohorts.index');
    // ---- Academic: teaching & delivery ----
    $showOfferings = $canSeeProgrammes && $menuAllows('admin.course_offerings.index') && $rbacPermissions->allows($user, 'academic.course_offering.view');
    $showLecturers = $menuAllows('admin.teacher.permission') && $navCan('admin.teacher.permission');
    $showClasses = $canSeeClasses && $menuAllows('admin.class_list') && $navCan('admin.class_list');
    $showTimetable = $menuAllows('admin.routine') && $navCan('admin.routine');
    $showLiveClasses = $menuAllows('admin.live_classes') && $navCan('admin.live_classes.index');
    $showAttendance = $menuAllows('admin.daily_attendance') && $navCan('admin.daily_attendance');
    $showAssignments = $canSeeLegacyAcademic && $menuAllows('admin.assignments') && $navCan('admin.assignments.index');
    // ---- Academic: assessment ----
    $showOnlineExams = $menuAllows('admin.online_exams', 'admin.online_exams.index') && $canViewOnlineExamsNav && $navCan('admin.online_exams.index');
    $showResults = $menuAllows('admin.gradebook') && $navCan('admin.gradebook');
    $showQuestionBank = $menuAllows('admin.question_bank', 'admin.question_bank.index') && $canManageQuestionBankNav && $navCan('admin.question_bank.index');

    $showAcademicStructureAny = $showAcademicYears || $showLegacySessions || $showProgrammes || $showCourseUnits || $showStudyPlans || $showCohorts;
    $showTeachingAny = $showOfferings || $showLecturers || $showClasses || $showTimetable || $showLiveClasses || $showAttendance || $showAssignments;
    $showAssessmentAny = $showOnlineExams || $showResults || $showQuestionBank;

    // ---- Student affairs ----
    // Student requests (transfers, complaints, appeals) and elections concern
    // students, not staff, so they belong beside Students — never under
    // Human Resources.
    $showStudentRequests = $navCan('admin.student_requests.index');
    $showElections = $navCan('admin.elections.index');
    $showStudentAffairsAny = $showStudentRequests || $showElections;

    // ---- Human resources ----
    $showLeave = $menuAllows('admin.leave') && $navCan('admin.leave.index');
    $showLeaveTypes = $menuAllows('admin.leave_types') && $navCan('admin.leave_types.index');
    // Self-service: every signed-in staff member may file their own leave.
    $showMyLeave = Route::has('staff.leave.index');
    $showHumanResourcesAny = $showLeave || $showLeaveTypes || $showMyLeave;

    // ---- Finance ----
    $showStudentFees = $menuAllows('admin.fee_manager.list') && $navCan('admin.fee_manager.list');
    $showFeeStructures = $menuAllows('admin.fee_structures') && $navCan('admin.fee_structures.index');
    $showFeePayments = $menuAllows('admin.offline_payment_pending') && $navCan('admin.offline_payment_pending');
    $showExpenses = $menuAllows('admin.expense.list') && $navCan('admin.expense.list');
    $showPayroll = $menuAllows('admin.payroll') && $navCan('admin.payroll.index');
    $showFinanceAny = $showStudentFees || $showFeeStructures || $showFeePayments || $showExpenses || $showPayroll;

    // ---- Resources ----
    $showLibrary = $menuAllows('admin.book.book_list') && $navCan('admin.book.book_list');
    $showAssets = $menuAllows('admin.assets') && $navCan('admin.assets.index');
    $showAssetCategories = $menuAllows('admin.asset_categories') && $navCan('admin.asset_categories.index');
    $showProcurement = $menuAllows('admin.procurement') && $navCan('admin.procurement.index');
    $showResourcesAny = $showLibrary || $showAssets || $showAssetCategories || $showProcurement;

    // ---- Communication ----
    $showNotices = $menuAllows('admin.noticeboard.list') && $navCan('admin.noticeboard.list');
    $showEvents = $menuAllows('admin.events.list') && $navCan('admin.events.list');
    $showAcademicCalendar = $menuAllows('admin.academic_calendar') && $navCan('admin.academic_calendar.index');
    $showCommunicationAny = $showNotices || $showEvents || $showAcademicCalendar;

    // ---- System ----
    $showAuditLogs = (int) $user->role_id === 2;
    $showSettings = $menuAllows('admin.settings.school') && $navCan('admin.settings.school');
    $showSystemAny = $showAuditLogs || $showSettings;
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <title>{{ get_phrase('Admin') . ' | ' . institution_name() }}</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="" name="description" />
    <meta content="" name="author" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ $faviconAsset }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/swiper-bundle.min.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/style.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/main.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/custom.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/css/daterangepicker.css') }}" />
    <link rel="stylesheet" href="{{ asset('assets/css/select2.min.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/lightbox.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/summernote-lite.min.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/vendors/bootstrap-icons-1.8.1/bootstrap-icons.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/toastr.min.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/calender/main.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/admin-sidebar.css') }}" />
    <script src="{{ asset('assets/vendors/jquery/jquery-3.6.0.min.js') }}"></script>

    <link rel="stylesheet" href="{{ asset('assets/css/online-exams.css') }}?v=20260920-3">

    {{-- Feature-scoped stylesheets: a page that owns its own presentation
         (e.g. the Create Staff form) links them here instead of inlining a
         <style> block in the markup. Rendered last so it wins on order. --}}
    @yield('page_styles')
    @include('shared.workspace_assets')
</head>

<body>

    <div class="sidebar">
        <div class="logo-details mt-4 mb-3">
            <div class="img_wrapper">
                <img height="45px" class=""
                    src="{{ $logoAsset }}" alt="{{ institution_name() }}" />
            </div>
            <span class="logo_name">{{ institution_name() }}</span>
        </div>
        <div class="closeIcon">
            <span>
                <img src="{{ asset('assets/images/close.svg') }}">
            </span>
        </div>
        <ul class="nav-links">
            
            <!-- ============================================ -->
            <!-- MAIN SECTION - No header needed              -->
            <!-- ============================================ -->

            <!-- Dashboard -->
            <li class="nav-links-li {{ request()->is('admin/dashboard') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.dashboard') }}" class="{{ request()->is('admin/dashboard') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7"></rect>
                                <rect x="14" y="3" width="7" height="7"></rect>
                                <rect x="3" y="14" width="7" height="7"></rect>
                                <rect x="14" y="14" width="7" height="7"></rect>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Dashboard') }}</span>
                    </a>
                </div>
            </li>

            <!-- Students -->
            @if((empty($user->menu_permission) || in_array('admin.student', $menu_permission) || in_array('admin.parent', $menu_permission)) && $navCan('admin.student'))
            <li class="nav-links-li {{ request()->is('admin/student*') || request()->is('admin/parent*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.student') }}" class="{{ request()->is('admin/student*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Students') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Staff -->
            @php
                // The consolidated Staff menu contains only the directory,
                // launcher and access management entry points. Legacy pages
                // remain routable directly under their existing guards.
                // Designation master data is reached contextually from the
                // Staff Directory and the staff forms instead, so this menu
                // stays exactly the three curated entries.
                $staffNav = [
                    'directory'   => $navCan('admin.rbac.staff.index'),
                    'add'         => (bool) \App\Http\Controllers\Admin\StaffLauncherController::creatableTypes($user),
                    'roles'       => $rbacPermissions->allows($user, 'roles.view'),
                ];
            @endphp
            @if(in_array(true, $staffNav, true))
            <li class="nav-links-li {{ request()->is('admin/admin*') || request()->is('admin/teacher*') || request()->is('admin/accountant*') || request()->is('admin/librarian*') || request()->is('admin/warden*') || request()->is('admin/staff*') || request()->is('admin/roles-permissions*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="#" class="has-submenu">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Staff') }}</span>
                    </a>
                    <span class="arrow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 4.743 7.773">
                            <path d="M1.466.247,4.5,3.277a.793.793,0,0,1,.189.288.92.92,0,0,1,0,.643A.793.793,0,0,1,4.5,4.5l-3.03,3.03a.828.828,0,0,1-.609.247.828.828,0,0,1-.609-.247.875.875,0,0,1,0-1.219L2.668,3.886.247,1.466A.828.828,0,0,1,0,.856.828.828,0,0,1,.247.247.828.828,0,0,1,.856,0,.828.828,0,0,1,1.466.247Z" fill="#fff" opacity="1"/>
                        </svg>
                    </span>
                </div>
                <ul class="sub-menu">
                    {{-- Staff Directory, Add Staff and Roles & Permissions are the primary staff entry points. --}}
                    @if($staffNav['directory'])
                    <li><a class="{{ request()->is('admin/roles-permissions/staff*') ? 'active' : '' }}" href="{{ route('admin.rbac.staff.index') }}"><span>{{ get_phrase('Staff Directory') }}</span></a></li>
                    @endif
                    @if($staffNav['add'])
                    <li><a class="{{ request()->is('admin/staff/add*') ? 'active' : '' }}" href="{{ route('admin.staff.add') }}"><span>{{ get_phrase('Add Staff') }}</span></a></li>
                    @endif
                    @if($staffNav['roles'])
                    <li><a class="{{ request()->is('admin/roles-permissions') || request()->is('admin/roles-permissions/roles*') ? 'active' : '' }}" href="{{ route('admin.rbac.roles.index') }}"><span>{{ get_phrase('Roles & Permissions') }}</span></a></li>
                    @endif
                </ul>
            </li>
            @endif

            <!-- ============================================ -->
            <!-- ADMISSIONS                                    -->
            <!-- Rendered by a self-contained partial so both admin layouts share -->
            <!-- one definition of the module. "Application Workflow" and        -->
            <!-- "Admissions" were two blocks describing this one module.        -->
            <!-- ============================================ -->
            @include('admin.admissions.partials.navigation')

            <!-- ============================================ -->
            <!-- ACADEMIC                                      -->
            <!-- Split into what is taught, how it is delivered -->
            <!-- and how it is assessed. Fifteen links in one   -->
            <!-- flat block made the module unusable.            -->
            <!-- ============================================ -->
            @if($showAcademicStructureAny || $showTeachingAny || $showAssessmentAny)
            <li class="nav-section-header">ACADEMIC</li>
            @endif

            {{-- Academic Structure — the catalogue: what exists and who may take it. --}}
            @if($showAcademicStructureAny)
            <li class="nav-links-li {{ $onAny(['admin/academic-structure*', 'admin/session_manager*', 'admin/programmes*', 'admin/subject*', 'admin/curricula*', 'admin/programme-cohorts*']) ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="#" class="has-submenu">
                        <div class="sidebar_icon"><i class="bi bi-diagram-3"></i></div>
                        <span class="link_name">{{ get_phrase('Academic Structure') }}</span>
                    </a>
                    <span class="arrow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 4.743 7.773">
                            <path d="M1.466.247,4.5,3.277a.793.793,0,0,1,.189.288.92.92,0,0,1,0,.643A.793.793,0,0,1,4.5,4.5l-3.03,3.03a.828.828,0,0,1-.609.247.828.828,0,0,1-.609-.247.875.875,0,0,1,0-1.219L2.668,3.886.247,1.466A.828.828,0,0,1,0,.856.828.828,0,0,1,.247.247.828.828,0,0,1,.856,0,.828.828,0,0,1,1.466.247Z" fill="#fff" opacity="1"/>
                        </svg>
                    </span>
                </div>
                <ul class="sub-menu">
                    @if($showAcademicYears)
                    <li><a class="{{ request()->is('admin/academic-structure*') ? 'active' : '' }}" href="{{ route('admin.academic_structure.index') }}"><span>{{ get_phrase('Academic Years & Periods') }}</span></a></li>
                    @endif
                    @if($showLegacySessions)
                    <li><a class="{{ request()->is('admin/session_manager*') ? 'active' : '' }}" href="{{ route('admin.settings.session_manager') }}"><span>{{ get_phrase('Academic Sessions') }}</span></a></li>
                    @endif
                    @if($showProgrammes)
                    <li><a class="{{ request()->is('admin/programmes*') ? 'active' : '' }}" href="{{ route('admin.programmes.index') }}"><span>{{ get_phrase('Programmes') }}</span></a></li>
                    @endif
                    @if($showCourseUnits)
                    <li><a class="{{ request()->is('admin/subject*') ? 'active' : '' }}" href="{{ route('admin.subject_list') }}"><span>{{ academic_term('subjects', $user->school_id) }}</span></a></li>
                    @endif
                    @if($showStudyPlans)
                    <li><a class="{{ request()->is('admin/curricula*') ? 'active' : '' }}" href="{{ route('admin.curricula.index') }}"><span>{{ get_phrase('Study Plans') }}</span></a></li>
                    @endif
                    @if($showCohorts)
                    <li><a class="{{ request()->is('admin/programme-cohorts*') ? 'active' : '' }}" href="{{ route('admin.programme_cohorts.index') }}"><span>{{ get_phrase('Cohorts') }}</span></a></li>
                    @endif
                </ul>
            </li>
            @endif

            {{-- Teaching & Delivery — an offering, who teaches it, when and where. --}}
            @if($showTeachingAny)
            <li class="nav-links-li {{ $onAny(['admin/course-offerings*', 'admin/permission*', 'admin/class_list', 'admin/class/*', 'admin/class_create', 'admin/routine*', 'admin/live-classes*', 'admin/attendance*', 'admin/assignments*']) ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="#" class="has-submenu">
                        <div class="sidebar_icon"><i class="bi bi-mortarboard"></i></div>
                        <span class="link_name">{{ get_phrase('Teaching & Delivery') }}</span>
                    </a>
                    <span class="arrow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 4.743 7.773">
                            <path d="M1.466.247,4.5,3.277a.793.793,0,0,1,.189.288.92.92,0,0,1,0,.643A.793.793,0,0,1,4.5,4.5l-3.03,3.03a.828.828,0,0,1-.609.247.828.828,0,0,1-.609-.247.875.875,0,0,1,0-1.219L2.668,3.886.247,1.466A.828.828,0,0,1,0,.856.828.828,0,0,1,.247.247.828.828,0,0,1,.856,0,.828.828,0,0,1,1.466.247Z" fill="#fff" opacity="1"/>
                        </svg>
                    </span>
                </div>
                <ul class="sub-menu">
                    @if($showOfferings)
                    <li><a class="{{ request()->is('admin/course-offerings*') ? 'active' : '' }}" href="{{ route('admin.course_offerings.index') }}"><span>{{ get_phrase('Course Offerings') }}</span></a></li>
                    @endif
                    @if($showLecturers)
                    <li><a class="{{ request()->is('admin/permission*') ? 'active' : '' }}" href="{{ route('admin.teacher.permission') }}"><span>{{ academic_term('teacher_assignment', $user->school_id) }}</span></a></li>
                    @endif
                    @if($showTimetable)
                    <li><a class="{{ request()->is('admin/routine*') ? 'active' : '' }}" href="{{ route('admin.routine') }}"><span>{{ get_phrase('Timetable') }}</span></a></li>
                    @endif
                    @if($showLiveClasses)
                    <li><a class="{{ request()->is('admin/live-classes*') ? 'active' : '' }}" href="{{ route('admin.live_classes.index') }}"><span>{{ get_phrase('Live Classes') }}</span></a></li>
                    @endif
                    @if($showAttendance)
                    <li><a class="{{ request()->is('admin/attendance*') ? 'active' : '' }}" href="{{ route('admin.daily_attendance') }}"><span>{{ get_phrase('Attendance') }}</span></a></li>
                    @endif
                    @if($showClasses)
                    <li><a class="{{ request()->is('admin/class_list') || request()->is('admin/class/*') || request()->is('admin/class_create') ? 'active' : '' }}" href="{{ route('admin.class_list') }}"><span>{{ get_phrase('Classes') }}</span></a></li>
                    @endif
                    @if($showAssignments)
                    <li><a class="{{ request()->is('admin/assignments*') ? 'active' : '' }}" href="{{ route('admin.assignments.index') }}"><span>{{ get_phrase('Assignments') }}</span></a></li>
                    @endif
                </ul>
            </li>
            @endif

            {{-- Assessment & Results — how learning is evidenced and reported. --}}
            @if($showAssessmentAny)
            <li class="nav-links-li {{ $onAny(['admin/online-exams*', 'admin/gradebook*', 'admin/question-bank*']) ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="#" class="has-submenu">
                        <div class="sidebar_icon"><i class="bi bi-clipboard-check"></i></div>
                        <span class="link_name">{{ get_phrase('Assessment & Results') }}</span>
                    </a>
                    <span class="arrow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 4.743 7.773">
                            <path d="M1.466.247,4.5,3.277a.793.793,0,0,1,.189.288.92.92,0,0,1,0,.643A.793.793,0,0,1,4.5,4.5l-3.03,3.03a.828.828,0,0,1-.609.247.828.828,0,0,1-.609-.247.875.875,0,0,1,0-1.219L2.668,3.886.247,1.466A.828.828,0,0,1,0,.856.828.828,0,0,1,.247.247.828.828,0,0,1,.856,0,.828.828,0,0,1,1.466.247Z" fill="#fff" opacity="1"/>
                        </svg>
                    </span>
                </div>
                <ul class="sub-menu">
                    @if($showOnlineExams)
                    <li><a class="{{ request()->is('admin/online-exams*') ? 'active' : '' }}" href="{{ route('admin.online_exams.index') }}"><span>{{ get_phrase('Online Exams') }}</span></a></li>
                    @endif
                    @if($showResults)
                    <li><a class="{{ request()->is('admin/gradebook*') ? 'active' : '' }}" href="{{ route('admin.gradebook') }}"><span>{{ get_phrase('Marks & Results') }}</span></a></li>
                    @endif
                    @if($showQuestionBank)
                    <li><a class="{{ request()->is('admin/question-bank*') ? 'active' : '' }}" href="{{ route('admin.question_bank.index') }}"><span>{{ get_phrase('Question Bank') }}</span></a></li>
                    @endif
                </ul>
            </li>
            @endif

            <!-- ============================================ -->
            <!-- STUDENT AFFAIRS                                -->
            <!-- Requests and elections concern students, not   -->
            <!-- employees, so they no longer sit in HR.        -->
            <!-- ============================================ -->
            @if($showStudentAffairsAny)
            <li class="nav-section-header">STUDENT AFFAIRS</li>
            @endif

            <!-- Student Affairs Requests -->
            @if($showStudentRequests)
            <li class="nav-links-li {{ request()->is('admin/student-requests*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.student_requests.index') }}" class="{{ request()->is('admin/student-requests*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48"><path d="M12,2C6.48,2,2,6.48,2,12s4.48,10,10,10s10-4.48,10-10S17.52,2,12,2z M13,17h-2v-2h2V17z M13,13h-2V7h2V13z"/></svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Student Affairs Requests') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Elections -->
            @if($showElections)
            <li class="nav-links-li {{ request()->is('admin/elections*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.elections.index') }}" class="{{ request()->is('admin/elections*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48"><path d="M12,2L1,7l4,2v8l7,3l7-3v-8l2-1v6h2V7L12,2z M12,4.15L18.5,7L12,9.85L5.5,7L12,4.15z M17,15.5l-5,2.15l-5-2.15V10.15l5,2.15l5-2.15V15.5z"/></svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Elections') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- ============================================ -->
            <!-- HUMAN RESOURCES                                -->
            <!-- Staff matters only. Programmes, Course Units, -->
            <!-- Student Affairs Requests and Elections used to -->
            <!-- be filed here and no longer are.                -->
            <!-- ============================================ -->
            @if($showHumanResourcesAny)
            <li class="nav-section-header">HUMAN RESOURCES</li>
            @endif

            <!-- Leave Management -->
            @if($showLeave)
            <li class="nav-links-li {{ request()->is('admin/leave') || request()->is('admin/leave/*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.leave.index') }}" class="{{ request()->is('admin/leave') || request()->is('admin/leave/*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                                <path d="M9 16l2 2 4-4"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Leave Management') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Leave Types -->
            @if($showLeaveTypes)
            <li class="nav-links-li {{ request()->is('admin/leave-types*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.leave_types.index') }}" class="{{ request()->is('admin/leave-types*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 11l3 3L22 4"></path>
                                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Leave Types') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- My Leave (self-service) -->
            @if($showMyLeave)
            <li class="nav-links-li {{ request()->is('staff/leave*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('staff.leave.index') }}" class="{{ request()->is('staff/leave*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('My Leave') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- ============================================ -->
            <!-- FINANCE SECTION HEADER                       -->
            <!-- ============================================ -->
            @if($showFinanceAny)<li class="nav-section-header">FINANCE</li>@endif

            <!-- Student Fees (was labelled "Finance", which named the whole section
                 rather than the module: student fee invoices and their balances) -->
            @if($showStudentFees)
            <li class="nav-links-li {{ request()->is('admin/fee_manager*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.fee_manager.list') }}" class="{{ request()->is('admin/fee_manager*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="1" x2="12" y2="23"></line>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Student Fees') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Fee Structures -->
            @if($showFeeStructures)
            <li class="nav-links-li {{ request()->is('admin/fee-structures*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.fee_structures.index') }}" class="{{ request()->is('admin/fee-structures*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Fee Structures') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Fee Payments: offline fee payments awaiting approval. Named to match
                 "Student Fees"/"Fee Structures" instead of the bare "Payments",
                 which did not say whose payments these were. -->
            @if($showFeePayments)
            <li class="nav-links-li {{ request()->is('admin/offline_payment/pending*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.offline_payment_pending') }}" class="{{ request()->is('admin/offline_payment/pending*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                                <path d="M2 17l10 5 10-5"></path>
                                <path d="M2 12l10 5 10-5"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Fee Payments') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Expenses -->
            @if($showExpenses)
            <li class="nav-links-li {{ request()->is('admin/expenses*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.expense.list') }}" class="{{ request()->is('admin/expenses*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                                <path d="M2 17l10 5 10-5"></path>
                                <path d="M2 12l10 5 10-5"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Expenses') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Payroll -->
            @if($showPayroll)
            <li class="nav-links-li {{ request()->is('admin/payroll*') || request()->is('admin/salary-structures*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.payroll.index') }}" class="{{ request()->is('admin/payroll*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                                <path d="M2 17l10 5 10-5"></path>
                                <path d="M2 12l10 5 10-5"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Payroll') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- ============================================ -->
            <!-- RESOURCES SECTION HEADER                     -->
            <!-- ============================================ -->
            @if($showResourcesAny)<li class="nav-section-header">RESOURCES</li>@endif

            <!-- Library -->
            @if($showLibrary)
            <li class="nav-links-li {{ (request()->is('admin/book*') || request()->is('admin/library*')) ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.library.dashboard') }}" class="{{ (request()->is('admin/book*') || request()->is('admin/library*')) ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
                                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Library') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Assets -->
            @if($showAssets)
            <li class="nav-links-li {{ request()->is('admin/assets') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.assets.index') }}" class="{{ request()->is('admin/assets') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Assets') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Asset Categories -->
            @if($showAssetCategories)
            <li class="nav-links-li {{ request()->is('admin/asset-categories*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.asset_categories.index') }}" class="{{ request()->is('admin/asset-categories*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Asset Categories') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Procurement -->
            @if($showProcurement)
            <li class="nav-links-li {{ request()->is('admin/procurement*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.procurement.index') }}" class="{{ request()->is('admin/procurement*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Procurement') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- ============================================ -->
            <!-- COMMUNICATION SECTION HEADER                 -->
            <!-- ============================================ -->
            @if($showCommunicationAny)<li class="nav-section-header">COMMUNICATION</li>@endif

            <!-- Notices -->
            @if($showNotices)
            <li class="nav-links-li {{ request()->is('admin/noticeboard*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.noticeboard.list') }}" class="{{ request()->is('admin/noticeboard*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Notices') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Events -->
            @if($showEvents)
            <li class="nav-links-li {{ request()->is('admin/events*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.events.list') }}" class="{{ request()->is('admin/events*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Events') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Academic Calendar -->
            @if($showAcademicCalendar)
            <li class="nav-links-li {{ request()->is('admin/academic-calendar*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.academic_calendar.index') }}" class="{{ request()->is('admin/academic-calendar*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Academic Calendar') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- ============================================ -->
            <!-- SYSTEM                                        -->
            <!-- Account and institution administration.      -->
            <!-- "Roles & Permissions" is deliberately absent: -->
            <!-- it already lives in the Staff sub-menu above, -->
            <!-- where staff access belongs.                   -->
            <!-- ============================================ -->
            @if($showSystemAny)
            <li class="nav-section-header">SYSTEM</li>
            @endif

            <!-- My Profile -->
            <li class="nav-links-li {{ request()->is('admin/profile*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.profile') }}" class="{{ request()->is('admin/profile*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('My Profile') }}</span>
                    </a>
                </div>
            </li>

            <!-- Audit Logs (School Admin only) -->
            @if($showAuditLogs)
            <li class="nav-links-li {{ request()->is('admin/audit-log*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.audit_log.index') }}" class="{{ request()->is('admin/audit-log*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="9" y1="13" x2="15" y2="13"></line>
                                <line x1="9" y1="17" x2="15" y2="17"></line>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Audit Logs') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- School Settings -->
            @if($showSettings)
            <li class="nav-links-li {{ request()->is('admin/settings*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.settings.school') }}" class="{{ request()->is('admin/settings*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('School Settings') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Logout -->
            <li class="nav-links-li">
                <div class="iocn-link">
                    <a href="{{ route('logout') }}" onclick="event.preventDefault();document.getElementById('logout-form').submit();">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                <polyline points="16 17 21 12 16 7"></polyline>
                                <line x1="21" y1="12" x2="9" y2="12"></line>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Logout') }}</span>
                    </a>
                </div>
                <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                    @csrf
                </form>
            </li>
        </ul>
    </div>

    <!-- ============================================ -->
    <!-- REST OF THE LAYOUT (Home Section, Footer, etc.) -->
    <!-- ============================================ -->
    <section class="home-section">
        <div class="home-content">
            <div class="home-header">
                <div class="row w-100 justify-content-between align-items-center">
                    <div class="col-auto">
                        <div class="sidebar_menu_icon">
                            <div class="menuList">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="12"
                                    viewBox="0 0 15 12">
                                    <path id="Union_5" data-name="Union 5"
                                        d="M-2188.5,52.5v-2h15v2Zm0-5v-2h15v2Zm0-5v-2h15v2Z"
                                        transform="translate(2188.5 -40.5)" fill="#6e6f78" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <div class="col-auto d-xl-block d-none">
                        <div class="header_notification d-flex align-items-center">
                            <div class="notification_icon">
                                @php
                                    $school_data = App\Models\School::where('id', auth()->user()->school_id)->first();
                                @endphp

                                @if (!empty($school_data->school_logo))
                                    <img class=""
                                        src="{{ asset('assets/uploads/school_logo/' .DB::table('schools')->where('id', auth()->user()->school_id)->value('school_logo')) }}"
                                        width="30px" height="30px" style="border-radius: 50%; ">
                                @else
                                    <img class="" src="{{ asset('assets') }}/images/id_logo.png"
                                        width="30px" height="30px">
                                @endif

                            </div>
                            <p>
                                {{ DB::table('schools')->where('id', auth()->user()->school_id)->value('title') }}
                            </p>
                        </div>
                    </div>

                    <div class="col-auto d-flex ">
                        @include('notifications._bell')
                        @include('messaging._header')
                        @php
                            $all_languages = get_all_language();
                            $usersinfo = DB::table('users')
                                ->where('id', auth()->user()->id)
                                ->first();

                            $userlanguage = $usersinfo->language;

                        @endphp
                        <div class="adminTable-action" style="margin-right: 20px; margin-top: 14px;">
                            <button type="button" class="eBtn eBtn-black dropdown-toggle table-action-btn-2"
                                data-bs-toggle="dropdown" aria-expanded="false"
                                style="width: 91px; height: 29px; padding: 0;">
                                <svg width="24" height="24" viewBox="0 0 24 24" focusable="false"
                                    class="ep0rzf NMm5M" style="width: 17px">
                                    <path
                                        d="M12.87 15.07l-2.54-2.51.03-.03A17.52 17.52 0 0 0 14.07 6H17V4h-7V2H8v2H1v1.99h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z">
                                    </path>
                                </svg>

                                @if (!empty($userlanguage))
                                    <span style="font-size: 10px;">{{ ucwords($userlanguage) }}</span>
                                @else
                                    <span style="font-size: 10px;">{{ ucwords(get_settings('language')) }}</span>
                                @endif
                            </button>

                            <ul style="min-width: 0;"
                                class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action">
                                <form method="post" id="languageForm" action="{{ route('admin.language') }}">
                                    @csrf
                                    @foreach ($all_languages as $all_language)
                                        <li>
                                            <a class="dropdown-item language-item" href="javascript:;"
                                                data-language-name="{{ $all_language->name }}">{{ ucwords($all_language->name) }}</a>
                                        </li>
                                    @endforeach
                                    <input type="hidden" name="language" id="selectedLanguageName">
                                </form>
                            </ul>
                        </div>
                        <div class="header-menu">
                            <ul>
                                <li class="user-profile">
                                    <div class="btn-group">
                                        <button class="btn btn-secondary dropdown-toggle" type="button"
                                            id="defaultDropdown" data-bs-toggle="dropdown" data-bs-auto-close="true"
                                            aria-expanded="false">
                                            <div class="">
                                                <img src="{{ get_user_image(auth()->user()->id) }}"
                                                    height="42px" />
                                            </div>
                                            <div class="px-2 text-start">
                                                <span class="user-name">{{ auth()->user()->name }}</span>
                                                <span class="user-title">{{ get_phrase((int) $user->role_id === \App\Support\Roles\SystemRole::GENERIC_STAFF ? 'Staff' : 'Admin') }}</span>
                                            </div>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end eDropdown-menu"
                                            aria-labelledby="defaultDropdown">
                                            <li class="user-profile user-profile-inner">
                                                <button class="btn w-100 d-flex align-items-center" type="button">
                                                    <div class="">
                                                        <img class="radious-5px"
                                                            src="{{ get_user_image(auth()->user()->id) }}"
                                                            height="42px" />
                                                    </div>
                                                    <div class="px-2 text-start">
                                                        <span class="user-name">{{ auth()->user()->name }}</span>
                                                        <span class="user-title">{{ get_phrase((int) $user->role_id === \App\Support\Roles\SystemRole::GENERIC_STAFF ? 'Staff' : 'Admin') }}</span>
                                                    </div>
                                                </button>
                                            </li>

                                            <li>
                                                <a class="dropdown-item" href="{{ route('admin.profile') }}">
                                                    <span>
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="13.275"
                                                            height="14.944" viewBox="0 0 13.275 14.944">
                                                            <g id="user_icon" data-name="user icon"
                                                                transform="translate(-1368.531 -147.15)">
                                                                <g id="Ellipse_1" data-name="Ellipse 1"
                                                                    transform="translate(1370.609 147.15)"
                                                                    fill="none" stroke="#1466AF" stroke-width="2">
                                                                    <ellipse cx="4.576" cy="4.435"
                                                                        rx="4.576" ry="4.435"
                                                                        stroke="none" />
                                                                    <ellipse cx="4.576" cy="4.435"
                                                                        rx="3.576" ry="3.435"
                                                                        fill="none" />
                                                                </g>
                                                                <path id="Path_41" data-name="Path 41"
                                                                    d="M1485.186,311.087a5.818,5.818,0,0,1,5.856-4.283,5.534,5.534,0,0,1,5.466,4.283"
                                                                    transform="translate(-115.686 -149.241)"
                                                                    fill="none" stroke="#1466AF"
                                                                    stroke-width="2" />
                                                            </g>
                                                        </svg>
                                                    </span>
                                                    {{ get_phrase('My Account') }}
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item"
                                                    href="{{ route('admin.password', ['edit']) }}">
                                                    <span>
                                                        <svg id="Layer_1" width="13.275" height="14.944"
                                                            viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                                                            data-name="Layer 1">
                                                            <path
                                                                d="m6.5 16a1.5 1.5 0 1 1 -1.5 1.5 1.5 1.5 0 0 1 1.5-1.5zm3 7.861a7.939 7.939 0 0 0 6.065-5.261 7.8 7.8 0 0 0 .32-3.85l.681-.689a1.5 1.5 0 0 0 .434-1.061v-2h.5a2.5 2.5 0 0 0 2.5-2.5v-.5h1.251a2.512 2.512 0 0 0 2.307-1.52 5.323 5.323 0 0 0 .416-2.635 4.317 4.317 0 0 0 -4.345-3.845 5.467 5.467 0 0 0 -3.891 1.612l-6.5 6.5a7.776 7.776 0 0 0 -3.84.326 8 8 0 0 0 2.627 15.562 8.131 8.131 0 0 0 1.475-.139zm-.185-12.661a1.5 1.5 0 0 0 1.463-.385l7.081-7.08a2.487 2.487 0 0 1 1.77-.735 1.342 1.342 0 0 1 1.36 1.149 2.2 2.2 0 0 1 -.08.851h-1.409a2.5 2.5 0 0 0 -2.5 2.5v.5h-.5a2.5 2.5 0 0 0 -2.5 2.5v1.884l-.822.831a1.5 1.5 0 0 0 -.378 1.459 4.923 4.923 0 0 1 -.074 2.955 5 5 0 1 1 -6.36-6.352 4.9 4.9 0 0 1 1.592-.268 5.053 5.053 0 0 1 1.357.191z" />
                                                        </svg>
                                                    </span>
                                                    {{ get_phrase('Change Password') }}
                                                </a>
                                            </li>

                                            <li>
                                                <hr class="my-0">
                                            </li>

                                            <!-- Logout Button -->
                                            <li>
                                                <a class="btn eLogut_btn" href="{{ route('logout') }}"
                                                    onclick="event.preventDefault();document.getElementById('logout-form').submit();">
                                                    <span>
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14.046"
                                                            height="12.29" viewBox="0 0 14.046 12.29">
                                                            <path id="Logout"
                                                                d="M4.389,42.535H2.634a.878.878,0,0,1-.878-.878V34.634a.878.878,0,0,1,.878-.878H4.389a.878.878,0,0,0,0-1.756H2.634A2.634,2.634,0,0,0,0,34.634v7.023A2.634,2.634,0,0,0,2.634,44.29H4.389a.878.878,0,1,0,0-1.756Zm9.4-5.009-3.512-3.512a.878.878,0,0,0-1.241,1.241l2.015,2.012H5.267a.878.878,0,0,0,0,1.756H11.05L9.037,41.036a.878.878,0,1,0,1.241,1.241l3.512-3.512A.879.879,0,0,0,13.788,37.525Z"
                                                                transform="translate(0 -32)" fill="#fff" />
                                                        </svg>
                                                    </span>
                                                    {{ get_phrase('Log out') }}
                                                </a>
                                                <form id="logout-form" action="{{ route('logout') }}"
                                                    method="POST" class="d-none">
                                                    @csrf
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="main_content">
                {{-- Print / PDF / Excel belong on view, list and report pages.
                     A creation form has nothing worth exporting, so a page that
                     declares @section('hide_page_toolbar') renders without it. --}}
                @unless (View::hasSection('hide_page_toolbar'))
                    @include('shared.page_toolbar')
                @endunless
                <div id="page-print-area">
                    @yield('content')
                </div>
                <!-- Start Footer -->
                <div class="copyright-text">
                    <?php $active_session = DB::table('sessions')->where('id', get_settings('running_session'))->value('session_title'); ?>
                    <p>{{ $active_session }} &copy; <span><a class="text-info" target="_blank"
                                href="{{ get_settings('footer_link') }}">{{ get_settings('footer_text') }}</a></span>
                    </p>
                </div>
                <!-- End Footer -->
            </div>
        </div>
        @include('modal')
    </section>

    @include('external_plugin')
    @include('jquery-form')


    <!--Main Jquery-->
    <!--Bootstrap bundle with popper-->
    <script src="{{ asset('assets/vendors/bootstrap-5.1.3/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/js/swiper-bundle.min.js') }}"></script>
    <!-- Datepicker js -->
    <script src="{{ asset('assets/js/moment.min.js') }}"></script>
    <script src="{{ asset('assets/js/daterangepicker.min.js') }}"></script>
    <!-- Select2 js -->
    <script src="{{ asset('assets/js/select2.min.js') }}"></script>

    <!--Custom Script-->
    <script src="{{ asset('assets/js/script.js') }}"></script>

    <!-- Calender js -->
    <script src="{{ asset('assets/calender/main.js') }}"></script>
    <script src="{{ asset('assets/calender/locales-all.js') }}"></script>

    <!-- Sorting helpers -->
    <script src="{{ asset('assets/js/Sortable.min.js') }}"></script>
    <script src="{{ asset('assets/js/jquery-sortable.js') }}"></script>
    {{-- SummerNote is loaded by `x-academic-editor`, not here. A second execution
         of the bundle re-registers `$.fn.summernote` with a fresh copy that knows
         nothing about the editables already mounted, so later `summernote('code')`
         reads answer empty. See the same note in layouts/app.blade.php. --}}
    <!--Toaster Script-->
    <script src="{{ asset('assets/js/toastr.min.js') }}"></script>

    <!--pdf Script-->
    <script src="{{ asset('assets/js/pdfmake.min.js') }}"></script>
    <script src="{{ asset('assets/js/html2pdf.bundle.min.js') }}"></script>

    <!--html2canvas Script-->
    <script src="{{ asset('assets/js/html2canvas.min.js') }}"></script>
    <script>
        // JavaScript to handle language selection
        document.addEventListener('DOMContentLoaded', function() {
            let languageLinks = document.querySelectorAll('.language-item');

            languageLinks.forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    let languageName = this.getAttribute('data-language-name');
                    document.getElementById('selectedLanguageName').value = languageName;
                    document.getElementById('languageForm').submit();
                });
            });
        });


        "use strict";

        @if (Session::has('message'))
            toastr.options = {
                "closeButton": true,
                "progressBar": true
            }
            toastr.success("{{ session('message') }}");
        @endif

        @if (Session::has('error'))
            toastr.options = {
                "closeButton": true,
                "progressBar": true
            }
            toastr.error("{{ session('error') }}");
        @endif

        @if (Session::has('info'))
            toastr.options = {
                "closeButton": true,
                "progressBar": true
            }
            toastr.info("{{ session('info') }}");
        @endif

        @if (Session::has('warning'))
            toastr.options = {
                "closeButton": true,
                "progressBar": true
            }
            toastr.warning("{{ session('warning') }}");
        @endif
    </script>

    <script>
        "use strict";

        jQuery(document).ready(function() {
            $('input[name="datetimes"]').daterangepicker({
                timePicker: true,
                startDate: moment().startOf('day').subtract(30, 'day'),
                endDate: moment().startOf('day'),
                locale: {
                    format: 'M/DD/YYYY '
                }

            });
        });
    </script>

</body>

</html>