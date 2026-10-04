<!DOCTYPE html>
<html lang="en">

<head>
    <!-- New -->
    <title>{{ get_phrase('Student') . ' | ' . get_settings('system_title') }}</title>
    <!-- all the meta tags -->
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="" name="description" />
    <meta content="" name="author" />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- all the css files -->
    <link rel="shortcut icon" href="{{ asset('assets/uploads/logo/' . get_settings('favicon')) }}" />
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css') }}" />

    <!--Custom css-->
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/swiper-bundle.min.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/main.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/custom.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/style.css') }}" />
    <!-- Datepicker css -->
    <link rel="stylesheet" href="{{ asset('assets/css/daterangepicker.css') }}" />
    <!-- Select2 css -->
    <link rel="stylesheet" href="{{ asset('assets/css/select2.min.css') }}" />

    <link rel="stylesheet" href="{{ asset('assets/vendors/bootstrap-icons-1.8.1/bootstrap-icons.css') }}" />

    <!-- The responsive course-card grid on My Courses. Loaded here, next to the
         other student stylesheets, so the card rules are cached with the rest of
         the shell instead of arriving after first paint. Scoped entirely to .sc-*
         classes, so nothing else on the student area is affected. -->
    <link rel="stylesheet" type="text/css" href="{{ asset('css/student-courses.css') }}" />

    <!--Toaster css-->
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/toastr.min.css') }}" />

    <!-- Calender css -->
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/calender/main.css') }}" />

    <!--Main Jquery-->
    <script src="{{ asset('assets/vendors/jquery/jquery-3.6.0.min.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('assets/css/online-exams.css') }}?v=20260920-3">
</head>

<body>

    @php
        $studentUser = auth()->user();
        $onlineExamPermissionService = app(\App\Support\Permissions\OnlineExamPermissionService::class);
        $canSeeStudentOnlineExams = $studentUser
            ? $onlineExamPermissionService->hasAny($studentUser, ['sit_online_exams', 'view_exam_results'])
            : false;
    @endphp

    <div class="sidebar">
        <div class="logo-details mt-4 mb-3">
            <div class="img_wrapper">
                <img height="40px" class=""
                    src="{{ asset('assets/uploads/logo/' . get_settings('white_logo')) }}" alt="" />
            </div>
            <span class="logo_name">{{ get_settings('navbar_title') }}</span>
        </div>
        <div class="closeIcon">
            <span>
                <img src="{{ asset('assets/images/close.svg') }}">
            </span>
        </div>
        <ul class="nav-links">
            <!-- sidebar title -->
            <li class="nav-links-li {{ request()->is('student/dashboard') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('student.dashboard') }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                                version="1.1" id="Capa_1" x="0px" y="0px" viewBox="0 0 512 512"
                                style="enable-background:new 0 0 512 512;" xml:space="preserve" width="48"
                                height="48">
                                <g>
                                    <path
                                        d="M117.333,234.667C52.532,234.667,0,182.135,0,117.333S52.532,0,117.333,0s117.333,52.532,117.333,117.333   C234.596,182.106,182.106,234.596,117.333,234.667z M117.333,64C87.878,64,64,87.878,64,117.333s23.878,53.333,53.333,53.333   s53.333-23.878,53.333-53.333S146.789,64,117.333,64z" />
                                    <path
                                        d="M394.667,234.667c-64.801,0-117.333-52.532-117.333-117.333S329.865,0,394.667,0S512,52.532,512,117.333   C511.929,182.106,459.439,234.596,394.667,234.667z M394.667,64c-29.455,0-53.333,23.878-53.333,53.333   s23.878,53.333,53.333,53.333S448,146.789,448,117.333S424.122,64,394.667,64z" />
                                    <path
                                        d="M117.333,512C52.532,512,0,459.468,0,394.667s52.532-117.333,117.333-117.333s117.333,52.532,117.333,117.333   C234.596,459.439,182.106,511.929,117.333,512z M117.333,341.333C87.878,341.333,64,365.211,64,394.667S87.878,448,117.333,448   s53.333-23.878,53.333-53.333S146.789,341.333,117.333,341.333z" />
                                    <path
                                        d="M394.667,512c-64.801,0-117.333-52.532-117.333-117.333s52.532-117.333,117.333-117.333S512,329.865,512,394.667   C511.929,459.439,459.439,511.929,394.667,512z M394.667,341.333c-29.455,0-53.333,23.878-53.333,53.333S365.211,448,394.667,448   S448,424.122,448,394.667S424.122,341.333,394.667,341.333z" />
                                </g>
                            </svg>

                        </div>
                        <span class="link_name">{{ get_phrase('Dashboard') }}</span>
                    </a>
                </div>
            </li>
            <li class="nav-links-li {{ request()->is('student/profile*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('student.profile') }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                                version="1.1" id="Capa_1" x="0px" y="0px" viewBox="0 0 512 512"
                                style="enable-background:new 0 0 512 512;" xml:space="preserve" width="48"
                                height="48">
                                <g>
                                    <path
                                        d="M244.317,299.051c-90.917,8.218-160.183,85.041-158.976,176.32V480c0,17.673,14.327,32,32,32l0,0c17.673,0,32-14.327,32-32   v-5.909c-0.962-56.045,40.398-103.838,96-110.933c58.693-5.82,110.992,37.042,116.812,95.735c0.344,3.47,0.518,6.954,0.521,10.441   V480c0,17.673,14.327,32,32,32l0,0c17.673,0,32-14.327,32-32v-10.667c-0.104-94.363-76.685-170.774-171.047-170.67   C251.854,298.668,248.082,298.797,244.317,299.051z" />
                                    <path
                                        d="M256.008,256c70.692,0,128-57.308,128-128S326.7,0,256.008,0s-128,57.308-128,128   C128.078,198.663,185.345,255.929,256.008,256z M256.008,64c35.346,0,64,28.654,64,64s-28.654,64-64,64s-64-28.654-64-64   S220.662,64,256.008,64z" />
                                </g>
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Personal Information') }}</span>
                    </a>
                </div>
            </li>
            <li class="nav-links-li {{ request()->is('student/id-card*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('student.id_card') }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48"><path d="M20,4H4C2.9,4,2,4.9,2,6v12c0,1.1,0.9,2,2,2h16c1.1,0,2-0.9,2-2V6C22,4.9,21.1,4,20,4z M9,10c1.1,0,2,0.9,2,2s-0.9,2-2,2 s-2-0.9-2-2S7.9,10,9,10z M13,17H5v-0.75C5,14.9,6.79,14,9,14s4,0.9,4,2.25V17z M20,16h-5v-1.5h5V16z M20,13h-5v-1.5h5V13z M20,10 h-5V8.5h5V10z"/></svg>
                        </div>
                        <span class="link_name">{{ get_phrase('My ID Card') }}</span>
                    </a>
                </div>
            </li>
            <li
                class="nav-links-li {{ request()->is('student/attendance*') || request()->is('student/routine*') || request()->is('student/subject*') || request()->is('student/syllabus*') || request()->is('student/transfers*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="#">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" id="Layer_1" data-name="Layer 1"
                                viewBox="0 0 24 24" width="48" height="48">
                                <path
                                    d="M7.5,4.5c.151-5.935,8.85-5.934,9,0-.151,5.935-8.85,5.934-9,0ZM24,15.5v1.793c0,2.659-1.899,4.935-4.516,5.411l-5.763,1.139c-1.142,.207-2.285,.21-3.421,.004l-5.807-1.147c-2.595-.472-4.494-2.748-4.494-5.407v-1.793c-.083-3.331,3.222-6.087,6.483-5.411l3.36,.702c.824,.15,1.564,.527,2.16,1.062,.601-.537,1.351-.916,2.191-1.069l3.282-.688c1.653-.301,3.293,.134,4.548,1.181,1.256,1.048,1.976,2.587,1.976,4.223Zm-13.5-.289c0-.726-.518-1.346-1.231-1.476l-3.36-.702c-.707-.126-1.439,.075-2.01,.548-.571,.477-.898,1.176-.898,1.919v1.793c0,1.209,.863,2.243,2.053,2.46l5.447,1.076v-5.618Zm10.5,.289c0-.744-.327-1.443-.897-1.919-.57-.476-1.318-.674-2.05-.54l-3.282,.687c-.753,.137-1.271,.758-1.271,1.483v5.618l5.425-1.072c1.212-.221,2.075-1.255,2.075-2.464v-1.793Z" />
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Studentship') }}</span>
                    </a>
                    <span class="arrow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="4.743" height="7.773" viewBox="0 0 4.743 7.773">
                            <path id="navigate_before_FILL0_wght600_GRAD0_opsz24"
                                d="M1.466.247,4.5,3.277a.793.793,0,0,1,.189.288.92.92,0,0,1,0,.643A.793.793,0,0,1,4.5,4.5l-3.03,3.03a.828.828,0,0,1-.609.247.828.828,0,0,1-.609-.247.875.875,0,0,1,0-1.219L2.668,3.886.247,1.466A.828.828,0,0,1,0,.856.828.828,0,0,1,.247.247.828.828,0,0,1,.856,0,.828.828,0,0,1,1.466.247Z"
                                fill="#fff" opacity="1" />
                        </svg>
                    </span>
                </div>
                <ul class="sub-menu">
                    <li><a class="{{ request()->is('student/attendance*') ? 'active' : '' }}"
                            href="{{ route('student.daily_attendance') }}"><span>{{ get_phrase('Daily Attendance') }}</span></a>
                    </li>
                    <li><a class="{{ request()->is('student/routine*') ? 'active' : '' }}"
                            href="{{ route('student.routine') }}"><span>{{ get_phrase('Class Routine') }}</span></a>
                    </li>
                    <li><a class="{{ request()->is('student/subject*') ? 'active' : '' }}"
                            href="{{ route('student.subject_list') }}"><span>{{ academic_term('subjects', auth()->user()->school_id) }}</span></a>
                    </li>
                    <li><a class="{{ request()->is('student/syllabus*') ? 'active' : '' }}"
                            href="{{ route('student.syllabus') }}"><span>{{ get_phrase('Syllabus') }}</span></a></li>
                    <li><a class="{{ request()->is('student/transfers*') ? 'active' : '' }}"
                            href="{{ route('student.transfers.index') }}"><span>{{ get_phrase('My Transfers') }}</span></a></li>
                </ul>
            </li>
            <li class="nav-links-li {{ request()->is('student/requests*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('student.requests.index') }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48"><path d="M12,2C6.48,2,2,6.48,2,12s4.48,10,10,10s10-4.48,10-10S17.52,2,12,2z M13,17h-2v-2h2V17z M13,13h-2V7h2V13z"/></svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Student Affairs') }}</span>
                    </a>
                </div>
            </li>
            <li class="nav-links-li {{ request()->is('student/my-courses*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('student.my_courses') }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48"><path d="M4,19V6.2C4,5.08,4.94,4.15,6.11,4.15h11.32c1.06,0,1.93,0.83,1.93,1.85V19c0,0.55-0.47,1-1.05,1H5.05C4.47,20,4,19.55,4,19z M6,17h11.36V7H6V17z M8,9h7v1.5H8V9z M8,12h7v1.5H8V12z"/></svg>
                        </div>
                        <span class="link_name">{{ get_phrase('My Courses') }}</span>
                    </a>
                </div>
            </li>
            <li class="nav-links-li {{ request()->is('student/elections*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('student.elections.index') }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48"><path d="M12,2L1,7l4,2v8l7,3l7-3v-8l2-1v6h2V7L12,2z M12,4.15L18.5,7L12,9.85L5.5,7L12,4.15z M17,15.5l-5,2.15l-5-2.15V10.15l5,2.15l5-2.15V15.5z"/></svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Elections') }}</span>
                    </a>
                </div>
            </li>
            <li
                class="nav-links-li {{ request()->is('student/marks') || request()->is('student/grade') || request()->is('student/exam-results*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="#">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" id="Layer_1" data-name="Layer 1"
                                viewBox="0 0 24 24" width="48" height="48">
                                <path
                                    d="M18,17.5A1.5,1.5,0,0,1,16.5,19h-1a1.5,1.5,0,0,1,0-3h1A1.5,1.5,0,0,1,18,17.5ZM13.092,14H10.908A1.5,1.5,0,0,1,8,13.5V10a4,4,0,0,1,8,0v3.5a1.5,1.5,0,0,1-2.908.5ZM11,10v1h2V10a1,1,0,0,0-2,0Zm-.569,5.947-.925.941a1.5,1.5,0,0,0-2.139,2.095s.163.187.189.211a2.757,2.757,0,0,0,3.9-.007l1.116-1.134a1.5,1.5,0,1,0-2.138-2.106ZM22,7.157V18.5A5.507,5.507,0,0,1,16.5,24h-9A5.507,5.507,0,0,1,2,18.5V5.5A5.507,5.507,0,0,1,7.5,0h7.343a5.464,5.464,0,0,1,3.889,1.611l1.657,1.657A5.464,5.464,0,0,1,22,7.157ZM18.985,7H17a2,2,0,0,1-2-2V3.015C14.947,3.012,7.5,3,7.5,3A2.5,2.5,0,0,0,5,5.5v13A2.5,2.5,0,0,0,7.5,21h9A2.5,2.5,0,0,0,19,18.5S18.988,7.053,18.985,7Z" />
                            </svg>
                        </div>
                        <span class="link_name">
                            {{ get_phrase('Examination') }}
                        </span>
                    </a>
                    <span class="arrow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="4.743" height="7.773"
                            viewBox="0 0 4.743 7.773">
                            <path id="navigate_before_FILL0_wght600_GRAD0_opsz24"
                                d="M1.466.247,4.5,3.277a.793.793,0,0,1,.189.288.92.92,0,0,1,0,.643A.793.793,0,0,1,4.5,4.5l-3.03,3.03a.828.828,0,0,1-.609.247.828.828,0,0,1-.609-.247.875.875,0,0,1,0-1.219L2.668,3.886.247,1.466A.828.828,0,0,1,0,.856.828.828,0,0,1,.247.247.828.828,0,0,1,.856,0,.828.828,0,0,1,1.466.247Z"
                                fill="#fff" opacity="1" />
                        </svg>
                    </span>
                </div>
                <ul class="sub-menu">
                    <li>
                        <a class="{{ request()->is('student/marks') ? 'active' : '' }}"
                            href="{{ route('student.marks') }}"><span>{{ get_phrase('Marks') }}</span></a>
                    </li>
                    <li>
                        <a class="{{ request()->is('student/grade') ? 'active' : '' }}"
                            href="{{ route('student.grade_list') }}"><span>{{ get_phrase('Grades') }}</span></a>
                    </li>
                    @if ($canSeeStudentOnlineExams)
                        <li>
                            <a class="{{ request()->is('student/online-exams*') ? 'active' : '' }}"
                                href="{{ route('student.online_exam.list') }}"><span>{{ get_phrase('Online Exams') }}</span></a>
                        </li>
                    @endif
                    <li>
                        <a class="{{ request()->is('student/exam-results*') ? 'active' : '' }}"
                            href="{{ route('student.exam_results') }}"><span>{{ get_phrase('My Exam Results') }}</span></a>
                    </li>
                </ul>
            </li>
            <li class="nav-links-li {{ request()->is('student/fee_manager*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a class="{{ request()->is('student/fee_manager*') ? 'active' : '' }}"
                        href="{{ route('student.fee_manager.list') }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" id="Layer_1" data-name="Layer 1"
                                viewBox="0 0 24 24" width="48" height="48">
                                <path
                                    d="M16.5,10c-1.972-.034-1.971-2.967,0-3h1c1.972,.034,1.971,2.967,0,3h-1Zm-3.5,4.413c0-1.476-.885-2.783-2.255-3.331l-2.376-.95c-.591-.216-.411-1.15,.218-1.132h1.181c.181,0,.343,.094,.434,.251,.415,.717,1.334,.962,2.05,.547,.717-.415,.962-1.333,.548-2.049-.511-.883-1.381-1.492-2.363-1.684-.399-1.442-2.588-1.375-2.896,.091-3.161,.875-3.414,5.6-.285,6.762l2.376,.95c.591,.216,.411,1.15-.218,1.132h-1.181c-.181,0-.343-.094-.434-.25-.415-.717-1.334-.961-2.05-.547-.717,.415-.962,1.333-.548,2.049,.511,.883,1.381,1.491,2.363,1.683,.399,1.442,2.588,1.375,2.896-.091,1.469-.449,2.54-1.817,2.54-3.431ZM18.5,1H5.5C2.468,1,0,3.467,0,6.5v11c0,3.033,2.468,5.5,5.5,5.5h3c1.972-.034,1.971-2.967,0-3h-3c-1.379,0-2.5-1.122-2.5-2.5V6.5c0-1.378,1.121-2.5,2.5-2.5h13c1.379,0,2.5,1.122,2.5,2.5v2c.034,1.972,2.967,1.971,3,0v-2c0-3.033-2.468-5.5-5.5-5.5Zm-5.205,18.481c-.813,.813-1.269,1.915-1.269,3.064,.044,.422-.21,1.464,.5,1.455,1.446,.094,2.986-.171,4.019-1.269l6.715-6.715c2.194-2.202-.9-5.469-3.157-3.343l-6.808,6.808Z" />
                            </svg>
                        </div>
                        <span class="link_name">
                            {{ get_phrase('Student Fees') }}
                        </span>
                    </a>
                </div>
            </li>
            <li class="nav-links-li {{ request()->is('student/teacher*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a class="{{ request()->is('student/teacher*') ? 'active' : '' }}"
                        href="{{ route('student.teacher') }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" id="Layer_1" data-name="Layer 1"
                                viewBox="0 0 24 24" width="48" height="48">
                                <path
                                    d="M16.5,24a1.5,1.5,0,0,1-1.489-1.335,3.031,3.031,0,0,0-6.018,0,1.5,1.5,0,0,1-2.982-.33,6.031,6.031,0,0,1,11.982,0,1.5,1.5,0,0,1-1.326,1.656A1.557,1.557,0,0,1,16.5,24Zm6.167-9.009a1.5,1.5,0,0,0,1.326-1.656A5.815,5.815,0,0,0,18.5,8a1.5,1.5,0,0,0,0,3,2.835,2.835,0,0,1,2.509,2.665A1.5,1.5,0,0,0,22.5,15,1.557,1.557,0,0,0,22.665,14.991ZM2.991,13.665A2.835,2.835,0,0,1,5.5,11a1.5,1.5,0,0,0,0-3A5.815,5.815,0,0,0,.009,13.335a1.5,1.5,0,0,0,1.326,1.656A1.557,1.557,0,0,0,1.5,15,1.5,1.5,0,0,0,2.991,13.665ZM12.077,16a3.5,3.5,0,1,0-3.5-3.5A3.5,3.5,0,0,0,12.077,16Zm6-9a3.5,3.5,0,1,0-3.5-3.5A3.5,3.5,0,0,0,18.077,7Zm-12,0a3.5,3.5,0,1,0-3.5-3.5A3.5,3.5,0,0,0,6.077,7Z" />
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Teacher') }}</span>
                    </a>
                </div>
            </li>
            <li class="nav-links-li {{ request()->is('student/live-classes*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="{{ route('student.live_classes.index') }}">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" id="Layer_1" data-name="Layer 1"
                                viewBox="0 0 24 24" width="48" height="48">
                                <path
                                    d="M22.925,5.695c-.663-.332-1.443-.262-2.037,.183l-1.944,1.882c-.364-2.683-2.664-4.76-5.444-4.76H5.5C2.467,3,0,5.468,0,8.5v7c0,3.032,2.467,5.5,5.5,5.5H13.5c2.772,0,5.067-2.065,5.441-4.736l1.947,1.846c.346,.258,.753,.391,1.165,.391,.335,0,.672-.087,.98-.265,.63-.364,.967-1.088,.967-1.816V7.434c0-.741-.412-1.408-1.075-1.739Zm-6.925,9.805c0,1.379-1.121,2.5-2.5,2.5H5.5c-1.378,0-2.5-1.121-2.5-2.5v-7c0-1.379,1.122-2.5,2.5-2.5H13.5c1.379,0,2.5,1.121,2.5,2.5v7Z" />
                            </svg>
                        </div>
                        <span class="link_name">{{ get_phrase('Live class') }}</span>
                    </a>
                </div>
            </li>
            @if (addon_status('online_courses') == 1)
                <li class="nav-links-li {{ request()->is('student/addons/courses*') ? 'showMenu' : '' }}">
                    <div class="iocn-link">
                        <a class="{{ request()->is('student/courses*') ? 'active' : '' }}"
                            href="{{ route('student.addons.courses') }}">
                            <div class="sidebar_icon">
                                <svg xmlns="http://www.w3.org/2000/svg" id="Isolation_Mode"
                                    data-name="Isolation Mode" viewBox="0 0 24 24" width="48" height="48">
                                    <path
                                        d="M11,14H5a5.006,5.006,0,0,0-5,5v5H3V19a2,2,0,0,1,2-2h6a2,2,0,0,1,2,2v5h3V19A5.006,5.006,0,0,0,11,14Z" />
                                    <path
                                        d="M8,12A6,6,0,1,0,2,6,6.006,6.006,0,0,0,8,12ZM8,3A3,3,0,1,1,5,6,3,3,0,0,1,8,3Z" />
                                    <polygon
                                        points="21 10 21 7 18 7 18 10 15 10 15 13 18 13 18 16 21 16 21 13 24 13 24 10 21 10" />
                                </svg>
                            </div>
                            <span class="link_name">
                                {{ get_phrase('Online Course') }}
                            </span>
                        </a>
                    </div>
                </li>
            @endif
            @if (addon_status('assignments') == 1)
                <li class="nav-links-li {{ request()->is('student/my-assignments*') ? 'showMenu' : '' }}">
                    <div class="iocn-link">
                        <a href="{{ route('student.assignment_home', ['type' => 'active']) }}">
                            <div class="sidebar_icon">
                                <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                                    version="1.1" id="Layer_1" x="0px" y="0px" viewBox="0 0 512 512"
                                    style="enable-background:new 0 0 512 512;" xml:space="preserve" width="48"
                                    height="48">
                                    <g>
                                        <path
                                            d="m385 227.33c28.359 0 54.99 7.538 78 20.71v-232.21c0-8.284-6.716-15-15-15h-314c-8.284 0-15 6.716-15 15v391.004c0 8.284 6.716 15 15 15h97.902c-24.727-97.03 52.723-195.864 153.098-194.504zm-190.997-177.499h193.994c19.902.793 19.887 29.215 0 30h-193.994c-19.902-.793-19.887-29.215 0-30zm0 60h193.994c19.902.793 19.887 29.215 0 30h-193.994c-19.902-.793-19.887-29.215 0-30zm0 60h193.994c19.902.793 19.887 29.215 0 30h-193.994c-19.902-.793-19.887-29.215 0-30zm-15 75c0-8.284 6.716-15 15-15h47.729c19.902.793 19.887 29.215 0 30h-47.729c-8.284 0-15-6.716-15-15z" />
                                        <path
                                            d="m385 257.33c-70.304 0-127.5 57.196-127.5 127.5 7.004 169.146 248.022 169.097 255-.001 0-70.303-57.196-127.499-127.5-127.499zm58.891 100.745-56.962 72.779c-2.765 3.531-6.964 5.642-11.447 5.75-4.471.113-8.784-1.793-11.714-5.187l-30.616-35.424c-5.417-6.268-4.728-15.74 1.54-21.157 6.271-5.419 15.74-4.727 21.157 1.54l18.693 21.629 45.724-58.421c5.106-6.523 14.532-7.674 21.058-2.567 6.523 5.107 7.672 14.534 2.567 21.058z" />
                                        <path d="m0 137.83h90v184h-90z" />
                                        <path
                                            d="m45 49.83c-24.813 0-45 20.187-45 45v13h90v-13c0-24.813-20.187-45-45-45z" />
                                        <path
                                            d="m31.153 414.599c5.047 12.231 22.648 12.226 27.692 0l26.155-62.769h-80z" />
                                    </g>
                                </svg>
                            </div>
                            <span class="link_name">{{ get_phrase('My assignments') }}</span>
                        </a>
                    </div>
                </li>
            @endif
            <li
                class="nav-links-li {{ request()->is('student/appraisal-list*') || request()->is('student/start-appraisal/*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a class="{{ request()->is('student/appraisal-list*') || request()->is('student/start-appraisal/*') ? 'active' : '' }}"
                        href="{{ route('student.appraisal.appraisalList') }}">
                        <div class="sidebar_icon mt-4">
                            <svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg"
                                xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 106.86 122.88"
                                style="enable-background:new 0 0 106.86 122.88" xml:space="preserve">
                                <style type="text/css">
                                    .st0 {
                                        fill-rule: evenodd;
                                        clip-rule: evenodd;
                                    }
                                </style>
                                <g>
                                    <path class="st0  mt-3"
                                        d="M39.62,64.58c-1.46,0-2.64-1.41-2.64-3.14c0-1.74,1.18-3.14,2.64-3.14h34.89c1.46,0,2.64,1.41,2.64,3.14 c0,1.74-1.18,3.14-2.64,3.14H39.62L39.62,64.58z M46.77,116.58c1.74,0,3.15,1.41,3.15,3.15c0,1.74-1.41,3.15-3.15,3.15H7.33 c-2.02,0-3.85-0.82-5.18-2.15C0.82,119.4,0,117.57,0,115.55V7.33c0-2.02,0.82-3.85,2.15-5.18C3.48,0.82,5.31,0,7.33,0h90.02 c2.02,0,3.85,0.83,5.18,2.15c1.33,1.33,2.15,3.16,2.15,5.18v50.14c0,1.74-1.41,3.15-3.15,3.15c-1.74,0-3.15-1.41-3.15-3.15V7.33 c0-0.28-0.12-0.54-0.31-0.72c-0.19-0.19-0.44-0.31-0.72-0.31H7.33c-0.28,0-0.54,0.12-0.73,0.3C6.42,6.8,6.3,7.05,6.3,7.33v108.21 c0,0.28,0.12,0.54,0.3,0.72c0.19,0.19,0.45,0.31,0.73,0.31H46.77L46.77,116.58z M98.7,74.34c-0.51-0.49-1.1-0.72-1.78-0.71 c-0.68,0.01-1.26,0.27-1.74,0.78l-3.91,4.07l10.97,10.59l3.95-4.11c0.47-0.48,0.67-1.1,0.66-1.78c-0.01-0.67-0.25-1.28-0.73-1.74 L98.7,74.34L98.7,74.34z M78.21,114.01c-1.45,0.46-2.89,0.94-4.33,1.41c-1.45,0.48-2.89,0.97-4.33,1.45 c-3.41,1.12-5.32,1.74-5.72,1.85c-0.39,0.12-0.16-1.48,0.7-4.81l2.71-10.45l0,0l20.55-21.38l10.96,10.55L78.21,114.01L78.21,114.01 z M39.62,86.95c-1.46,0-2.65-1.43-2.65-3.19c0-1.76,1.19-3.19,2.65-3.19h17.19c1.46,0,2.65,1.43,2.65,3.19 c0,1.76-1.19,3.19-2.65,3.19H39.62L39.62,86.95z M39.62,42.26c-1.46,0-2.64-1.41-2.64-3.14c0-1.74,1.18-3.14,2.64-3.14h34.89 c1.46,0,2.64,1.41,2.64,3.14c0,1.74-1.18,3.14-2.64,3.14H39.62L39.62,42.26z M24.48,79.46c2.06,0,3.72,1.67,3.72,3.72 c0,2.06-1.67,3.72-3.72,3.72c-2.06,0-3.72-1.67-3.72-3.72C20.76,81.13,22.43,79.46,24.48,79.46L24.48,79.46z M24.48,57.44 c2.06,0,3.72,1.67,3.72,3.72c0,2.06-1.67,3.72-3.72,3.72c-2.06,0-3.72-1.67-3.72-3.72C20.76,59.11,22.43,57.44,24.48,57.44 L24.48,57.44z M24.48,35.42c2.06,0,3.72,1.67,3.72,3.72c0,2.06-1.67,3.72-3.72,3.72c-2.06,0-3.72-1.67-3.72-3.72 C20.76,37.08,22.43,35.42,24.48,35.42L24.48,35.42z" />
                                </g>
                            </svg>
                        </div>
                        <span class="link_name">
                            {{ get_phrase('Appraisal') }}
                        </span>
                    </a>
                </div>
            </li>
            <li
                class="nav-links-li {{ request()->is('student/book*') || request()->is('student/book_issue*') || request()->is('student/noticeboard*') || request()->is('student/club/list*')|| request()->is('student/club/notices*')  || request()->is('student/events/list*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="#">
                        <div class="sidebar_icon">
                            <svg xmlns="http://www.w3.org/2000/svg" id="Bold" viewBox="0 0 24 24"
                                width="48" height="48">
                                <path
                                    d="M18.5,3h-.642A4,4,0,0,0,14,0H10A4,4,0,0,0,6.142,3H5.5A5.506,5.506,0,0,0,0,8.5v10A5.506,5.506,0,0,0,5.5,24h13A5.507,5.507,0,0,0,24,18.5V8.5A5.507,5.507,0,0,0,18.5,3ZM5.5,6h13A2.5,2.5,0,0,1,21,8.5V11H3V8.5A2.5,2.5,0,0,1,5.5,6Zm13,15H5.5A2.5,2.5,0,0,1,3,18.5V14h7a2,2,0,0,0,2,2h0a2,2,0,0,0,2-2h7v4.5A2.5,2.5,0,0,1,18.5,21Z" />
                            </svg>
                        </div>
                        @php
                            $notice_count = \Illuminate\Support\Facades\Schema::hasTable('noticeboard')
                                ? DB::table('noticeboard')
                                    ->where('school_id', auth()->user()->school_id)
                                    ->whereDate('start_date', '>', now()->toDateString())
                                    ->count()
                                : 0;
                        @endphp
                        @if ($notice_count > 0)
                            <span class="link_name">{{ get_phrase('Back Office') }}</span>
                            <span class="notification"
                                style="background-color: red;
                  border-radius: 50%; width: 18px; height: 18px; margin-top: 5px; margin-left: 5px; line-height: 20px;">
                                <h5
                                    style="font-size: 10px;  color: #fff; margin-top: -7px;display: flex; justify-content: center; ">
                                    {{ $notice_count }}</h5>
                            </span>
                        @else
                            <span class="link_name">{{ get_phrase('Back Office') }}</span>
                        @endif
                    </a>
                    <span class="arrow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="4.743" height="7.773"
                            viewBox="0 0 4.743 7.773">
                            <path id="navigate_before_FILL0_wght600_GRAD0_opsz24"
                                d="M1.466.247,4.5,3.277a.793.793,0,0,1,.189.288.92.92,0,0,1,0,.643A.793.793,0,0,1,4.5,4.5l-3.03,3.03a.828.828,0,0,1-.609.247.828.828,0,0,1-.609-.247.875.875,0,0,1,0-1.219L2.668,3.886.247,1.466A.828.828,0,0,1,0,.856.828.828,0,0,1,.247.247.828.828,0,0,1,.856,0,.828.828,0,0,1,1.466.247Z"
                                fill="#fff" opacity="1" />
                        </svg>
                    </span>
                </div>
                <ul class="sub-menu">
                    <li><a class="{{ request()->is('student/book/list') ? 'active' : '' }}"
                            href="{{ route('student.book.book_list') }}"><span>{{ get_phrase('List Of Books') }}</span></a>
                    </li>
                    <li><a class="{{ request()->is('student/book_issue') ? 'active' : '' }}"
                            href="{{ route('student.book.issued_list') }}"><span>{{ get_phrase('Issued Book') }}</span></a>
                    </li>
                    <li><a class="{{ request()->is('student/noticeboard*') ? 'active' : '' }}"
                            href="{{ route('student.noticeboard.list') }}"><span>{{ get_phrase('Noticeboard') }}</span></a>
                    </li>
                    <li><a class="{{ request()->is('student/events/list*') ? 'active' : '' }}"
                            href="{{ route('student.events.list') }}"><span>{{ get_phrase('Events') }}</span></a>
                    </li>
                    <li><a class="{{ request()->is('student/club/list*') || request()->is('student/club/notices*')  ? 'active' : '' }}"
                            href="{{ route('student.club.list') }}"><span>{{ get_phrase('Club') }}</span></a>
                    </li>
                </ul>
            </li>
            <li
                class="nav-links-li {{ request()->is('student/hostel_*') || request()->is('student/hostel-applications*') || request()->is('student/hostel_fee*') ? 'showMenu' : '' }}">
                <div class="iocn-link">
                    <a href="#">
                        <div class="sidebar_icon mt-4">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M21 20.25H20.25V6C20.25 3.582 18.918 2.25 16.5 2.25H7.5C5.082 2.25 3.75 3.582 3.75 6V20.25H3C2.586 20.25 2.25 20.586 2.25 21C2.25 21.414 2.586 21.75 3 21.75H21C21.414 21.75 21.75 21.414 21.75 21C21.75 20.586 21.414 20.25 21 20.25ZM13.5 20.25H10.5V17.5C10.5 16.911 10.661 16.75 11.25 16.75H12.75C13.339 16.75 13.5 16.911 13.5 17.5V20.25ZM15 20.25V17.5C15 16.091 14.159 15.25 12.75 15.25H11.25C9.841 15.25 9 16.091 9 17.5V20.25H5.25V6C5.25 4.423 5.923 3.75 7.5 3.75H16.5C18.077 3.75 18.75 4.423 18.75 6V20.25H15ZM15.75 13C15.75 13.414 15.414 13.75 15 13.75H14C13.586 13.75 13.25 13.414 13.25 13C13.25 12.586 13.586 12.25 14 12.25H15C15.414 12.25 15.75 12.586 15.75 13ZM10.75 13C10.75 13.414 10.414 13.75 10 13.75H9C8.586 13.75 8.25 13.414 8.25 13C8.25 12.586 8.586 12.25 9 12.25H10C10.414 12.25 10.75 12.586 10.75 13ZM15.75 10C15.75 10.414 15.414 10.75 15 10.75H14C13.586 10.75 13.25 10.414 13.25 10C13.25 9.586 13.586 9.25 14 9.25H15C15.414 9.25 15.75 9.586 15.75 10ZM10.75 10C10.75 10.414 10.414 10.75 10 10.75H9C8.586 10.75 8.25 10.414 8.25 10C8.25 9.586 8.586 9.25 9 9.25H10C10.414 9.25 10.75 9.586 10.75 10ZM15.75 7C15.75 7.414 15.414 7.75 15 7.75H14C13.586 7.75 13.25 7.414 13.25 7C13.25 6.586 13.586 6.25 14 6.25H15C15.414 6.25 15.75 6.586 15.75 7ZM10.75 7C10.75 7.414 10.414 7.75 10 7.75H9C8.586 7.75 8.25 7.414 8.25 7C8.25 6.586 8.586 6.25 9 6.25H10C10.414 6.25 10.75 6.586 10.75 7Z"
                                    fill="#7A7E8E" stroke="#7A7E8E" />
                            </svg>
                        </div>
                        <span class="link_name">
                            {{ get_phrase('Hostel') }}
                        </span>
                    </a>
                    <span class="arrow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="4.743" height="7.773"
                            viewBox="0 0 4.743 7.773">
                            <path id="navigate_before_FILL0_wght600_GRAD0_opsz24"
                                d="M1.466.247,4.5,3.277a.793.793,0,0,1,.189.288.92.92,0,0,1,0,.643A.793.793,0,0,1,4.5,4.5l-3.03,3.03a.828.828,0,0,1-.609.247.828.828,0,0,1-.609-.247.875.875,0,0,1,0-1.219L2.668,3.886.247,1.466A.828.828,0,0,1,0,.856.828.828,0,0,1,.247.247.828.828,0,0,1,.856,0,.828.828,0,0,1,1.466.247Z"
                                fill="#fff" opacity="1" />
                        </svg>
                    </span>
                </div>

                <ul class="sub-menu">
                    @php
                        $hasApplications = \Illuminate\Support\Facades\Schema::hasTable('hostel_applications')
                            && \App\Models\HostelApplication::where('student_id', auth()->user()->id)
                                ->where('school_id', auth()->user()->school_id)
                                ->exists();

                        $hasHostelFees = \Illuminate\Support\Facades\Schema::hasTable('hostel_fees')
                            && \App\Models\HostelFee::where('student_id', auth()->user()->id)
                                ->where('school_id', auth()->user()->school_id)
                                ->exists();
                    @endphp

                    @if (empty($user->menu_permission) || in_array('student.hostel.applications', $menu_permission))
                        <li>
                            <a class="{{ request()->is('student/hostel-applications*') ? 'active' : '' }}"
                                href="{{ route('student.hostel.applications') }}">
                                <span>{{ get_phrase('Application') }}</span>
                            </a>
                        </li>
                    @endif

                    @if (
                        ($hasApplications || $hasHostelFees) &&
                            (empty($user->menu_permission) || in_array('student.hostel.fee_manager', $menu_permission)))
                        <li>
                            <a class="{{ request()->is('student/hostel_fee*') ? 'active' : '' }}"
                                href="{{ route('student.hostel_fee_manager.list') }}">
                                <span>{{ get_phrase('Hostel Fees') }}</span>
                            </a>
                        </li>
                    @endif
                </ul>
            </li>
    </div>

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
                                <svg style="width: 14px; fill: #00a3ff;" xmlns="http://www.w3.org/2000/svg"
                                    id="Layer_1" data-name="Layer 1" viewBox="0 0 24 24" width="48px"
                                    height="48px">
                                    <path
                                        d="M19,6H14.321A3.95,3.95,0,0,0,13,5.388V5l3.53-1.652a1,1,0,0,0,0-1.7l-3.2-1.5A1.637,1.637,0,0,0,11,1.636V5.388A3.937,3.937,0,0,0,9.68,6H5a5.006,5.006,0,0,0-5,5V21a3,3,0,0,0,3,3H21a3,3,0,0,0,3-3V11A5.006,5.006,0,0,0,19,6Zm2.816,4H19.657a3.017,3.017,0,0,1-2.121-.879L16.414,8H19A3,3,0,0,1,21.816,10ZM5,8H7.586L6.464,9.121A3.017,3.017,0,0,1,4.343,10H2.184A3,3,0,0,1,5,8Zm8,14H11V19a1,1,0,0,1,2,0Zm8,0H15V19a3,3,0,0,0-6,0v3H3a1,1,0,0,1-1-1V12H4.343a4.968,4.968,0,0,0,3.535-1.465l2.708-2.707a2,2,0,0,1,2.828,0l2.708,2.707A4.968,4.968,0,0,0,19.657,12H22v9A1,1,0,0,1,21,22ZM7,15a1,1,0,0,1-1,1H5a1,1,0,0,1,0-2H6A1,1,0,0,1,7,15Zm0,4a1,1,0,0,1-1,1H5a1,1,0,0,1,0-2H6A1,1,0,0,1,7,19Zm13-4a1,1,0,0,1-1,1H18a1,1,0,0,1,0-2h1A1,1,0,0,1,20,15Zm0,4a1,1,0,0,1-1,1H18a1,1,0,0,1,0-2h1A1,1,0,0,1,20,19Zm-6-7a2,2,0,1,1-2-2A2,2,0,0,1,14,12Z">
                                    </path>
                                </svg>
                            </div>
                            <p>
                                {{ DB::table('schools')->where('id', auth()->user()->school_id)->value('title') }}
                            </p>
                        </div>
                    </div>

                    <div class="col-auto d-flex">
                        @include('notifications._bell')
                        <div class="message">
                            @php
                                $last_message = DB::table('message_thrades')
                                    ->where(function ($query) {
                                        $query
                                            ->where('reciver_id', auth()->user()->id)
                                            ->orWhere('sender_id', auth()->user()->id);
                                    })
                                    ->orderBy('id', 'desc')
                                    ->first();

                                $countUnreadThreads = DB::table('chats')
                                    ->where('read_status', 0)
                                    ->where('reciver_id', auth()->user()->id)
                                    ->distinct('message_thrade')
                                    ->count('message_thrade');

                            @endphp
                            @if (!empty($last_message))
                                <a href="{{ route('student.message.all_message', ['id' => $last_message->id]) }}"
                                    class="message_ico">
                                    <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true"
                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                        fill="currentColor" viewBox="0 0 24 24">
                                        <path fill-rule="evenodd"
                                            d="M4 3a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h1v2a1 1 0 0 0 1.707.707L9.414 13H15a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H4Z"
                                            clip-rule="evenodd" />
                                        <path fill-rule="evenodd"
                                            d="M8.023 17.215c.033-.03.066-.062.098-.094L10.243 15H15a3 3 0 0 0 3-3V8h2a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1h-1v2a1 1 0 0 1-1.707.707L14.586 18H9a1 1 0 0 1-.977-.785Z"
                                            clip-rule="evenodd" />
                                    </svg>
                                    @if ($countUnreadThreads != 0)
                                        <div class="countUnread">
                                            <span class="countUnreadThreads">{{ $countUnreadThreads }}</span>
                                        </div>
                                    @endif
                                </a>
                            @else
                                <a href="{{ route('student.message.chat_empty') }}" class="message_ico">
                                    <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true"
                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                        fill="currentColor" viewBox="0 0 24 24">
                                        <path fill-rule="evenodd"
                                            d="M4 3a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h1v2a1 1 0 0 0 1.707.707L9.414 13H15a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H4Z"
                                            clip-rule="evenodd" />
                                        <path fill-rule="evenodd"
                                            d="M8.023 17.215c.033-.03.066-.062.098-.094L10.243 15H15a3 3 0 0 0 3-3V8h2a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1h-1v2a1 1 0 0 1-1.707.707L14.586 18H9a1 1 0 0 1-.977-.785Z"
                                            clip-rule="evenodd" />
                                    </svg>
                                </a>
                            @endif
                        </div>
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
                                <form method="post" id="languageForm" action="{{ route('student.language') }}">
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
                                                <span class="user-title">{{ get_phrase('Student') }}</span>
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
                                                        <span class="user-title">{{ get_phrase('Student') }}</span>
                                                    </div>
                                                </button>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="{{ route('student.profile') }}">
                                                    <span>
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="13.275"
                                                            height="14.944" viewBox="0 0 13.275 14.944">
                                                            <g id="user_icon" data-name="user icon"
                                                                transform="translate(-1368.531 -147.15)">
                                                                <g id="Ellipse_1" data-name="Ellipse 1"
                                                                    transform="translate(1370.609 147.15)"
                                                                    fill="none" stroke="#181c32" stroke-width="2">
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
                                                                    fill="none" stroke="#181c32"
                                                                    stroke-width="2" />
                                                            </g>
                                                        </svg>
                                                    </span>
                                                    {{ get_phrase('My Account') }}
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item"
                                                    href="{{ route('student.password', ['edit']) }}">
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
                @yield('content')
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
    <script src="{{ asset('assets/js/custom.js') }}"></script>

    <!-- Calender js -->
    <script src="{{ asset('assets/calender/main.js') }}"></script>
    <script src="{{ asset('assets/calender/locales-all.js') }}"></script>

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

    {{-- Page-specific scripts pushed by student views (for example the
         Online Exams instructions and exam-taking screens). --}}
    @stack('scripts')

</body>

</html>
