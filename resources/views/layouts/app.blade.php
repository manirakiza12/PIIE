@php
    use App\Models\User;
    use App\Support\Permissions\OnlineExamPermissionService;

    $user = Auth()->user();
    $isPrimarySchool = is_primary_school($user->school_id);
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
        
    $logoAsset = $whiteLogoSetting !== ''
        ? asset('assets/uploads/logo/' . $whiteLogoSetting)
        : ($darkLogoSetting !== ''
            ? asset('assets/uploads/logo/' . $darkLogoSetting)
            : asset('assets/uploads/logo/logo1.png'));
            
    $menu_permission =
        empty($user->menu_permission) || $user->menu_permission == 'null'
            ? []
            : json_decode($user->menu_permission, true);

    $roleNavPerms = function_exists('get_role_nav_permissions')
        ? get_role_nav_permissions((int) $user->role_id)
        : ['all'];

    $canSee = function(string $section) use ($menu_permission, $roleNavPerms): bool {
        if (!empty($menu_permission)) {
            return in_array($section, $menu_permission);
        }
        return in_array('all', $roleNavPerms) || in_array($section, $roleNavPerms);
    };

    $isAdminOrSuper = in_array($user->role_id, [1, 2]);
    if (!$isAdminOrSuper && empty($menu_permission) && !in_array('all', $roleNavPerms)) {
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
        $menu_permission[] = 'admin.dashboard';
    }

    $onlineExamPermissionService = app(\App\Support\Permissions\OnlineExamPermissionService::class);
    $canViewOnlineExamsNav = $onlineExamPermissionService->hasAny($user, [
        'view_online_exams',
        'create_online_exams',
        'view_exam_attempts',
        'view_exam_results',
    ]);
    $canManageQuestionBankNav = $onlineExamPermissionService->has($user, 'manage_exam_questions');
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <title>{{ get_phrase('Admin') . ' | ' . get_settings('system_title') }}</title>
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

    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    {{--
        Per-page stylesheets. The admin chrome loads none of the public design
        system's CSS, so an admin screen that must render a PUBLIC component
        faithfully — the programme catalogue preview does, using the real card
        partial — has to pull those sheets in itself. Left as an empty stack so
        the overwhelming majority of admin pages pay nothing for it.
    --}}
    @stack('public_design_styles')

    <script src="{{ asset('assets/vendors/jquery/jquery-3.6.0.min.js') }}"></script>

    <style>
        /* Sidebar theme + layout widths live in assets/css/admin-sidebar.css
           (shared with admin/navigation.blade.php). Only this page's own
           content-wrapper spacing stays here. */

        .home-content {
            background-color: #f6f6f9;
            min-height: 100vh;
            padding: 0 !important;
        }

        .home-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            height: 70px;
            background-color: #fff;
            box-shadow: 0 10px 50px rgba(0, 0, 0, 0.06);
            padding: 0 20px;
        }

        .main_content {
            padding: 20px 25px !important;
            overflow: visible !important;
        }

        .container-fluid {
            padding: 0 10px !important;
            overflow: visible !important;
        }

        /* ============================================ */
        /* HEADER NOTIFICATIONS                         */
        /* ============================================ */

        .header_notification {
            gap: 7px;
            display: flex;
            align-items: center;
        }

        .header_notification .notification_icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #d1eeff;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .header_notification .notification_icon i {
            color: #00a3ff;
            font-size: 15px;
            font-weight: 700;
        }

        .header_notification p {
            font-size: 13px;
            font-weight: 400;
            color: #6e6f78;
            margin: 0;
        }

        /* ============================================ */
        /* RESPONSIVE                                  */
        /* ============================================ */

        @media (max-width: 768px) {
            .main_content {
                padding: 15px !important;
            }
            .home-header {
                height: 60px;
                padding: 0 12px;
            }
            .header_notification p {
                display: none;
            }
        }
    </style>

</head>

<body>

    <div class="sidebar" id="sidebar">
        <div class="logo-details">
            <div class="img_wrapper">
                <img height="45px" class=""
                    src="{{ $logoAsset }}" alt="{{ get_settings('navbar_title') ?: 'PIIE' }}" />
            </div>
            <span class="logo_name">{{ get_settings('navbar_title') ?: 'HEMS Portal' }}</span>
        </div>
        <div class="closeIcon">
            <span>
                <img src="{{ asset('assets/images/close.svg') }}">
            </span>
        </div>
        <ul class="nav-links">
            
            <!-- ============================================ -->
            <!-- DASHBOARD                                   -->
            <!-- ============================================ -->
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

            <!-- ============================================ -->
            <!-- STUDENTS                                    -->
            <!-- ============================================ -->
            @if(empty($user->menu_permission) || in_array('admin.student', $menu_permission) || in_array('admin.parent', $menu_permission))
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

            <!-- ============================================ -->
            <!-- STAFF                                       -->
            <!-- ============================================ -->
            @if(empty($user->menu_permission) || in_array('admin.admin', $menu_permission) || in_array('admin.teacher', $menu_permission) || in_array('admin.accountant', $menu_permission) || in_array('admin.librarian', $menu_permission) || in_array('admin.warden', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/admin*') || request()->is('admin/teacher*') || request()->is('admin/accountant*') || request()->is('admin/librarian*') || request()->is('admin/warden*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="#">
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
                    @if(empty($user->menu_permission) || in_array('admin.admin', $menu_permission))
                    <li><a class="{{ request()->is('admin/admin*') ? 'active' : '' }}" href="{{ route('admin.admin') }}"><span>{{ get_phrase('Admin') }}</span></a></li>
                    @endif
                    @if(empty($user->menu_permission) || in_array('admin.teacher', $menu_permission))
                    <li><a class="{{ request()->is('admin/teacher*') ? 'active' : '' }}" href="{{ route('admin.teacher') }}"><span>{{ get_phrase('Teacher') }}</span></a></li>
                    @endif
                    @if(empty($user->menu_permission) || in_array('admin.accountant', $menu_permission))
                    <li><a class="{{ request()->is('admin/accountant*') ? 'active' : '' }}" href="{{ route('admin.accountant') }}"><span>{{ get_phrase('Accountant') }}</span></a></li>
                    @endif
                    @if(empty($user->menu_permission) || in_array('admin.librarian', $menu_permission))
                    <li><a class="{{ request()->is('admin/librarian*') ? 'active' : '' }}" href="{{ route('admin.librarian') }}"><span>{{ get_phrase('Librarian') }}</span></a></li>
                    @endif
                    @if(empty($user->menu_permission) || in_array('admin.warden', $menu_permission))
                    <li><a class="{{ request()->is('admin/warden*') ? 'active' : '' }}" href="{{ route('admin.warden') }}"><span>{{ get_phrase('Warden') }}</span></a></li>
                    @endif
                    @if(empty($user->menu_permission) || in_array('admin.permission', $menu_permission))
                    <li><a class="{{ request()->is('admin/permission*') ? 'active' : '' }}" href="{{ route('admin.teacher.permission') }}"><span>{{ get_phrase('Teacher Permission') }}</span></a></li>
                    @endif
                </ul>
            </li>
            @endif

            <!-- ============================================ -->
            <!-- PROGRAMMES (higher_ed/mixed only)             -->
            <!-- ============================================ -->
            @if($canSeeProgrammes)
            <li class="nav-links-li {{ request()->is('admin/programmes*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.programmes.index') }}" class="{{ request()->is('admin/programmes*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Programmes') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- ============================================ -->
            <!-- COURSES                                     -->
            <!-- ============================================ -->
            @if(empty($user->menu_permission) || in_array('admin.subject_list', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/subject*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.subject_list') }}" class="{{ request()->is('admin/subject*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Courses') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- ============================================ -->
            <!-- ACADEMIC SECTION HEADER                      -->
            <!-- ============================================ -->
            <li class="nav-section-header"><i class="fas fa-graduation-cap"></i> ACADEMIC</li>

            <!-- Classes (K-12 class/section structure; hidden for higher_ed schools) -->
            @if($canSeeClasses && (empty($user->menu_permission) || in_array('admin.class_list', $menu_permission)))
            <li class="nav-links-li {{ request()->is('admin/class_list') || request()->is('admin/class/*') || request()->is('admin/class_create') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.class_list') }}" class="{{ request()->is('admin/class_list') || request()->is('admin/class/*') || request()->is('admin/class_create') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                                <polyline points="2 17 12 22 22 17"></polyline>
                                <polyline points="2 12 12 17 22 12"></polyline>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Classes') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Attendance -->
            @if(empty($user->menu_permission) || in_array('admin.daily_attendance', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/attendance*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.daily_attendance') }}" class="{{ request()->is('admin/attendance*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Attendance') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Online Exams -->
            @if((empty($user->menu_permission) || in_array('admin.online_exams', $menu_permission) || in_array('admin.online_exams.index', $menu_permission)) && $canViewOnlineExamsNav)
            <li class="nav-links-li {{ request()->is('admin/online-exams*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.online_exams.index') }}" class="{{ request()->is('admin/online-exams*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Online Exams') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Timetable -->
            @if(empty($user->menu_permission) || in_array('admin.routine', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/routine*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.routine') }}" class="{{ request()->is('admin/routine*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Timetable') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Assignments -->
            @if(empty($user->menu_permission) || in_array('admin.assignments', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/assignments*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.assignments.index') }}" class="{{ request()->is('admin/assignments*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="8" y1="13" x2="16" y2="13"></line>
                                <line x1="8" y1="17" x2="12" y2="17"></line>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Assignments') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Live Classes -->
            @if(empty($user->menu_permission) || in_array('admin.live_classes', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/live-classes*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.live_classes.index') }}" class="{{ request()->is('admin/live-classes*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="23 7 16 12 23 17 23 7"></polygon>
                                <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Live Classes') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Marks & Results -->
            @if(empty($user->menu_permission) || in_array('admin.gradebook', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/gradebook*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.gradebook') }}" class="{{ request()->is('admin/gradebook*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                                <path d="M2 17l10 5 10-5"></path>
                                <path d="M2 12l10 5 10-5"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Marks & Results') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Question Bank -->
            @if((empty($user->menu_permission) || in_array('admin.question_bank', $menu_permission) || in_array('admin.question_bank.index', $menu_permission)) && $canManageQuestionBankNav)
            <li class="nav-links-li {{ request()->is('admin/question-bank*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.question_bank.index') }}" class="{{ request()->is('admin/question-bank*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Question Bank') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- ============================================ -->
            <!-- FINANCE SECTION HEADER                       -->
            <!-- ============================================ -->
            <li class="nav-section-header"><i class="fas fa-coins"></i> FINANCE</li>

            <!-- Finance -->
            @if(empty($user->menu_permission) || in_array('admin.fee_manager.list', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/fee_manager*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.fee_manager.list') }}" class="{{ request()->is('admin/fee_manager*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="1" x2="12" y2="23"></line>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Finance') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Fee Structures -->
            @if(empty($user->menu_permission) || in_array('admin.fee_structures', $menu_permission))
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

            <!-- Payments -->
            @if(empty($user->menu_permission) || in_array('admin.offline_payment_pending', $menu_permission))
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
                        <span class="link_name">{{ get_phrase('Payments') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Expenses -->
            @if(empty($user->menu_permission) || in_array('admin.expense.list', $menu_permission))
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
            @if(empty($user->menu_permission) || in_array('admin.payroll', $menu_permission))
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

            @if($isPrimarySchool)
            <!-- ============================================ -->
            <!-- ADMISSIONS SECTION HEADER                    -->
            <!-- Admissions/Intake Sessions/Agents only apply  -->
            <!-- to the one school the public Apply Now portal -->
            <!-- currently belongs to (primary_school_id).     -->
            <!-- ============================================ -->
            <li class="nav-section-header"><i class="fas fa-file-alt"></i> ADMISSIONS</li>

            <!-- Admissions -->
            @if(empty($user->menu_permission) || in_array('admin.hei_admissions', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/hei-admissions*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.hei_admissions.index') }}" class="{{ request()->is('admin/hei-admissions*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                                <path d="M2 17l10 5 10-5"></path>
                                <path d="M2 12l10 5 10-5"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Admissions') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Intake Sessions -->
            @if(empty($user->menu_permission) || in_array('admin.intake_sessions', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/intake-sessions*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.intake_sessions.index') }}" class="{{ request()->is('admin/intake-sessions*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Intake Sessions') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Document Requirements — gated on the same permission as the
                 Admissions queue: it is configuration for that one module, not
                 a separately assignable area. -->
            @if(empty($user->menu_permission) || in_array('admin.hei_admissions', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/admissions-documents*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.admissions_documents.index') }}" class="{{ request()->is('admin/admissions-documents*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="9" y1="15" x2="15" y2="15"></line>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Document Requirements') }}</span>
                    </a>
                </div>
            </li>
            @endif

            <!-- Agents -->
            @if(empty($user->menu_permission) || in_array('admin.admissions_agents', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/admissions-agents*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.admissions_agents.index') }}" class="{{ request()->is('admin/admissions-agents*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Agents') }}</span>
                    </a>
                </div>
            </li>
            @endif
            @endif

            <!-- ============================================ -->
            <!-- RESOURCES SECTION HEADER                     -->
            <!-- ============================================ -->
            <li class="nav-section-header"><i class="fas fa-book"></i> RESOURCES</li>

            <!-- Library -->
            @if(empty($user->menu_permission) || in_array('admin.book.book_list', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/book*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.book.book_list') }}" class="{{ request()->is('admin/book*') ? 'active' : '' }}">
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
            @if(empty($user->menu_permission) || in_array('admin.assets', $menu_permission))
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
            @if(empty($user->menu_permission) || in_array('admin.asset_categories', $menu_permission))
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
            @if(empty($user->menu_permission) || in_array('admin.procurement', $menu_permission))
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
            <li class="nav-section-header"><i class="fas fa-bullhorn"></i> COMMUNICATION</li>

            <!-- Notices -->
            @if(empty($user->menu_permission) || in_array('admin.noticeboard.list', $menu_permission))
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
            @if(empty($user->menu_permission) || in_array('admin.events.list', $menu_permission))
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
            @if(empty($user->menu_permission) || in_array('admin.academic_calendar', $menu_permission))
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
            <!-- PROFILE & LOGOUT                             -->
            <!-- ============================================ -->
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

            <!-- Settings -->
            @if(empty($user->menu_permission) || in_array('admin.settings.school', $menu_permission))
            <li class="nav-links-li {{ request()->is('admin/settings*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('admin.settings.school') }}" class="{{ request()->is('admin/settings*') ? 'active' : '' }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Settings') }}</span>
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
    <!-- HOME SECTION                                 -->
    <!-- ============================================ -->
    <section class="home-section">
        <div class="home-content">
            <div class="home-header">
                <div class="row w-100 justify-content-between align-items-center">
                    <div class="col-auto">
                        <div class="sidebar_menu_icon">
                            <div class="menuList">
                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="12" viewBox="0 0 15 12">
                                    <path id="Union_5" data-name="Union 5" d="M-2188.5,52.5v-2h15v2Zm0-5v-2h15v2Zm0-5v-2h15v2Z" transform="translate(2188.5 -40.5)" fill="#6e6f78"/>
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
                                    <img class="" src="{{ asset('assets/uploads/school_logo/' .DB::table('schools')->where('id', auth()->user()->school_id)->value('school_logo')) }}" width="30px" height="30px" style="border-radius: 50%;">
                                @else
                                    <img class="" src="{{ asset('assets') }}/images/id_logo.png" width="30px" height="30px">
                                @endif
                            </div>
                            <p>{{ DB::table('schools')->where('id', auth()->user()->school_id)->value('title') }}</p>
                        </div>
                    </div>

                    <div class="col-auto d-flex">
                        <!-- Language Dropdown -->
                        @php
                            $all_languages = get_all_language();
                            $usersinfo = DB::table('users')->where('id', auth()->user()->id)->first();
                            $userlanguage = $usersinfo->language;
                        @endphp

                        <div class="adminTable-action" style="margin-right: 20px; margin-top: 14px;">
                            <button type="button" class="eBtn eBtn-black dropdown-toggle table-action-btn-2" data-bs-toggle="dropdown" aria-expanded="false" style="width: 91px; height: 29px; padding: 0;">
                                <svg width="24" height="24" viewBox="0 0 24 24" focusable="false" class="ep0rzf NMm5M" style="width: 17px">
                                    <path d="M12.87 15.07l-2.54-2.51.03-.03A17.52 17.52 0 0 0 14.07 6H17V4h-7V2H8v2H1v1.99h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z"/>
                                </svg>
                                @if(!empty($userlanguage))
                                    <span style="font-size: 10px;">{{ ucwords($userlanguage) }}</span>
                                @else
                                    <span style="font-size: 10px;">{{ ucwords(get_settings('language')) }}</span>
                                @endif
                            </button>
                            <ul style="min-width: 0;" class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action">
                                <form method="post" id="languageForm" action="{{ route('admin.language') }}">
                                    @csrf
                                    @foreach ($all_languages as $all_language)
                                        <li>
                                            <a class="dropdown-item language-item" href="javascript:;" data-language-name="{{ $all_language->name }}">{{ ucwords($all_language->name) }}</a>
                                        </li>
                                    @endforeach
                                    <input type="hidden" name="language" id="selectedLanguageName">
                                </form>
                            </ul>
                        </div>

                        <!-- User Profile -->
                        <div class="header-menu">
                            <ul>
                                <li class="user-profile">
                                    <div class="btn-group">
                                        <button class="btn btn-secondary dropdown-toggle" type="button" id="defaultDropdown" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                                            <div class="">
                                                <img src="{{ get_user_image(auth()->user()->id) }}" height="42px" />
                                            </div>
                                            <div class="px-2 text-start">
                                                <span class="user-name">{{ auth()->user()->name }}</span>
                                                <span class="user-title">{{ get_phrase('Admin') }}</span>
                                            </div>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end eDropdown-menu" aria-labelledby="defaultDropdown">
                                            <li class="user-profile user-profile-inner">
                                                <button class="btn w-100 d-flex align-items-center" type="button">
                                                    <div class="">
                                                        <img class="radious-5px" src="{{ get_user_image(auth()->user()->id) }}" height="42px" />
                                                    </div>
                                                    <div class="px-2 text-start">
                                                        <span class="user-name">{{ auth()->user()->name }}</span>
                                                        <span class="user-title">{{ get_phrase('Admin') }}</span>
                                                    </div>
                                                </button>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="{{ route('admin.profile') }}">
                                                    <span>
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="13.275" height="14.944" viewBox="0 0 13.275 14.944">
                                                            <g id="user_icon" data-name="user icon" transform="translate(-1368.531 -147.15)">
                                                                <g id="Ellipse_1" data-name="Ellipse 1" transform="translate(1370.609 147.15)" fill="none" stroke="#181c32" stroke-width="2">
                                                                    <ellipse cx="4.576" cy="4.435" rx="4.576" ry="4.435" stroke="none"/>
                                                                    <ellipse cx="4.576" cy="4.435" rx="3.576" ry="3.435" fill="none"/>
                                                                </g>
                                                                <path id="Path_41" data-name="Path 41" d="M1485.186,311.087a5.818,5.818,0,0,1,5.856-4.283,5.534,5.534,0,0,1,5.466,4.283" transform="translate(-115.686 -149.241)" fill="none" stroke="#181c32" stroke-width="2"/>
                                                            </g>
                                                        </svg>
                                                    </span>
                                                    {{ get_phrase('My Account') }}
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="{{ route('admin.password', ['edit']) }}">
                                                    <span>
                                                        <svg id="Layer_1" width="13.275" height="14.944" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" data-name="Layer 1">
                                                            <path d="m6.5 16a1.5 1.5 0 1 1 -1.5 1.5 1.5 1.5 0 0 1 1.5-1.5zm3 7.861a7.939 7.939 0 0 0 6.065-5.261 7.8 7.8 0 0 0 .32-3.85l.681-.689a1.5 1.5 0 0 0 .434-1.061v-2h.5a2.5 2.5 0 0 0 2.5-2.5v-.5h1.251a2.512 2.512 0 0 0 2.307-1.52 5.323 5.323 0 0 0 .416-2.635 4.317 4.317 0 0 0 -4.345-3.845 5.467 5.467 0 0 0 -3.891 1.612l-6.5 6.5a7.776 7.776 0 0 0 -3.84.326 8 8 0 0 0 2.627 15.562 8.131 8.131 0 0 0 1.475-.139zm-.185-12.661a1.5 1.5 0 0 0 1.463-.385l7.081-7.08a2.487 2.487 0 0 1 1.77-.735 1.342 1.342 0 0 1 1.36 1.149 2.2 2.2 0 0 1 -.08.851h-1.409a2.5 2.5 0 0 0 -2.5 2.5v.5h-.5a2.5 2.5 0 0 0 -2.5 2.5v1.884l-.822.831a1.5 1.5 0 0 0 -.378 1.459 4.923 4.923 0 0 1 -.074 2.955 5 5 0 1 1 -6.36-6.352 4.9 4.9 0 0 1 1.592-.268 5.053 5.053 0 0 1 1.357.191z"/>
                                                        </svg>
                                                    </span>
                                                    {{ get_phrase('Change Password') }}
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="{{ route('admin.settings.school') }}">
                                                    <span>
                                                        <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" id="Capa_1" x="0px" y="0px" viewBox="0 0 512 512" style="enable-background:new 0 0 512 512;" xml:space="preserve" width="13.275" height="14.944">
                                                            <g>
                                                                <path d="M256,162.923c-51.405,0-93.077,41.672-93.077,93.077s41.672,93.077,93.077,93.077s93.077-41.672,93.077-93.077   C349.019,204.619,307.381,162.981,256,162.923z M256,285.077c-16.059,0-29.077-13.018-29.077-29.077s13.018-29.077,29.077-29.077   c16.059,0,29.077,13.018,29.077,29.077l0,0C285.066,272.054,272.054,285.066,256,285.077z"/>
                                                                <path d="M469.333,256c-0.032-32.689-7.633-64.927-22.208-94.187l10.965-7.616c14.496-10.104,18.058-30.046,7.957-44.544l0,0   c-10.104-14.496-30.046-18.058-44.544-7.957l-10.987,7.637c-32.574-34.38-75.691-56.904-122.517-64V32c0-17.673-14.327-32-32-32   l0,0c-17.673,0-32,14.327-32,32v13.333c-46.826,7.096-89.944,29.62-122.517,64l-10.987-7.637   c-14.498-10.101-34.44-6.538-44.544,7.957l0,0c-10.101,14.498-6.538,34.44,7.957,44.544l10.965,7.616   c-29.61,59.3-29.61,129.073,0,188.373l-10.965,7.616c-14.496,10.104-18.058,30.046-7.957,44.544l0,0   c10.104,14.496,30.046,18.058,44.544,7.957l10.987-7.637c32.574,34.38,75.691,56.904,122.517,64V480c0,17.673,14.327,32,32,32l0,0   c17.673,0,32-14.327,32-32v-13.333c46.826-7.096,89.944-29.62,122.517-64l10.987,7.637c14.498,10.101,34.44,6.538,44.544-7.957l0,0   c10.101-14.498,6.538-34.44-7.957-44.544l-10.965-7.616C461.7,320.927,469.301,288.689,469.333,256z M256,405.333   c-82.475,0-149.333-66.859-149.333-149.333S173.525,106.667,256,106.667S405.333,173.525,405.333,256   C405.228,338.431,338.431,405.228,256,405.333z"/>
                                                            </g>
                                                        </svg>
                                                    </span>
                                                    {{ get_phrase('Settings') }}
                                                </a>
                                            </li>
                                            <li>
                                                <a class="btn eLogut_btn" href="{{ route('logout') }}" onclick="event.preventDefault();document.getElementById('logout-form').submit();">
                                                    <span>
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14.046" height="12.29" viewBox="0 0 14.046 12.29">
                                                            <path id="Logout" d="M4.389,42.535H2.634a.878.878,0,0,1-.878-.878V34.634a.878.878,0,0,1,.878-.878H4.389a.878.878,0,0,0,0-1.756H2.634A2.634,2.634,0,0,0,0,34.634v7.023A2.634,2.634,0,0,0,2.634,44.29H4.389a.878.878,0,1,0,0-1.756Zm9.4-5.009-3.512-3.512a.878.878,0,0,0-1.241,1.241l2.015,2.012H5.267a.878.878,0,0,0,0,1.756H11.05L9.037,41.036a.878.878,0,1,0,1.241,1.241l3.512-3.512A.879.879,0,0,0,13.788,37.525Z" transform="translate(0 -32)" fill="#fff"/>
                                                        </svg>
                                                    </span>
                                                    {{ get_phrase('Log out') }}
                                                </a>
                                                <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
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

            <!-- ============================================ -->
            <!-- MAIN CONTENT                               -->
            <!-- ============================================ -->
            <div class="main_content">
                {{-- A page that declares @section('hide_page_toolbar') has no
                     printable/exportable result, so the toolbar is skipped. --}}
                @unless (View::hasSection('hide_page_toolbar'))
                    @include('shared.page_toolbar')
                @endunless
                <div id="page-print-area">
                    @yield('content')
                </div>
                <!-- Footer -->
                <div class="copyright-text">
                    <?php $active_session = DB::table('sessions')->where('id', get_settings('running_session'))->value('session_title'); ?>
                    <p>{{ $active_session }} &copy; <span><a class="text-info" target="_blank" href="{{ get_settings('footer_link') }}">{{ get_settings('footer_text') }}</a></span></p>
                </div>
            </div>
        </div>
        @include('modal')
    </section>

    <!-- ============================================ -->
    <!-- SCRIPTS                                      -->
    <!-- ============================================ -->
    @include('external_plugin')
    @include('jquery-form')

    <script src="{{ asset('assets/vendors/bootstrap-5.1.3/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/js/swiper-bundle.min.js') }}"></script>
    <script src="{{ asset('assets/js/moment.min.js') }}"></script>
    <script src="{{ asset('assets/js/daterangepicker.min.js') }}"></script>
    <script src="{{ asset('assets/js/select2.min.js') }}"></script>
    <script src="{{ asset('assets/js/script.js') }}"></script>
    <script src="{{ asset('assets/calender/main.js') }}"></script>
    <script src="{{ asset('assets/calender/locales-all.js') }}"></script>
    <script src="{{ asset('assets/js/Sortable.min.js') }}"></script>
    <script src="{{ asset('assets/js/jquery-sortable.js') }}"></script>
    {{-- Summernote is deliberately NOT loaded here.

         It used to be, and that was the cause of the exam 21 question-creation
         failure. `x-academic-editor` loads the bundle itself, inside `@once`, at
         the point the editor is needed — so it is already executed and already
         mounted the editable regions by the time this layout's script block runs.

         Loading it a second time here re-registers `$.fn.summernote` with a FRESH
         copy that has no knowledge of the editables the first copy mounted. Every
         later `$(area).summernote('code')` then dispatches into that fresh copy,
         which finds nothing and answers with an empty string — while the lecturer
         is looking at their text in the editor the first copy built. That empty
         read is what the browser serialised as the question, and the server was
         right to refuse it.

         The stylesheet above is still loaded here: a duplicate stylesheet is inert,
         and keeping it means any view expecting the vendor styling still has it.

         Every editor in the application is built by `x-academic-editor`, so nothing
         calls the vendor directly any more. --}}
    <script src="{{ asset('assets/js/toastr.min.js') }}"></script>
    <script src="{{ asset('assets/js/pdfmake.min.js') }}"></script>
    <script src="{{ asset('assets/js/html2pdf.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/js/html2canvas.min.js') }}"></script>

    <script>
        // Sidebar collapse/off-canvas toggling is handled globally by
        // assets/js/script.js (.menuList / .closeIcon / .nav-links-li),
        // shared with the rest of the app.

        // Language selection
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

        // Toastr notifications
        "use strict";
        @if(Session::has('message'))
            toastr.options = { "closeButton": true, "progressBar": true }
            toastr.success("{{ session('message') }}");
        @endif
        @if(Session::has('error'))
            toastr.options = { "closeButton": true, "progressBar": true }
            toastr.error("{{ session('error') }}");
        @endif
        @if(Session::has('info'))
            toastr.options = { "closeButton": true, "progressBar": true }
            toastr.info("{{ session('info') }}");
        @endif
        @if(Session::has('warning'))
            toastr.options = { "closeButton": true, "progressBar": true }
            toastr.warning("{{ session('warning') }}");
        @endif

        // Date range picker
        jQuery(document).ready(function() {
            $('input[name="datetimes"]').daterangepicker({
                timePicker: true,
                startDate: moment().startOf('day').subtract(30, 'day'),
                endDate: moment().startOf('day'),
                locale: { format: 'M/DD/YYYY ' }
            });
        });
    </script>

</body>
</html>