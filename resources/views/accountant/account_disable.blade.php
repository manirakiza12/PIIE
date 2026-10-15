<!DOCTYPE html>
<html lang="en">
<head>
	<!-- New -->
  <title>{{ get_phrase('Accountant').' | '.institution_name() }}</title>
    <!-- all the meta tags -->
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta content="" name="description" />
    <meta content="" name="author" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    
    <!-- all the css files -->
    <link rel="shortcut icon" href="{{ asset('assets/uploads/logo/'.get_settings('favicon')) }}" />
    <!-- Bootstrap CSS -->
    <link
      rel="stylesheet"
      type="text/css"
      href="{{ asset('assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css') }}"
    />

    <!--Custom css-->
    <link
      rel="stylesheet"
      type="text/css"
      href="{{ asset('assets/css/swiper-bundle.min.css') }}"
    />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/main.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/custom.css') }}" />
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/style.css') }}" />
    <!-- Datepicker css -->
    <link rel="stylesheet" href="{{ asset('assets/css/daterangepicker.css') }}" />
    <!-- Select2 css -->
    <link rel="stylesheet" href="{{ asset('assets/css/select2.min.css') }}" />

    <link
      rel="stylesheet"
      type="text/css"
      href="{{ asset('assets/vendors/bootstrap-icons-1.8.1/bootstrap-icons.css') }}"
    />

    <!--Toaster css-->
    <link 
      rel="stylesheet" 
      type="text/css" 
      href="{{ asset('assets/css/toastr.min.css') }}"
    />

    <!-- Calender css -->
    <link
      rel="stylesheet"
      type="text/css"
      href="{{ asset('assets/calender/main.css') }}"
    />

     <!--Main Jquery-->
     <script src="{{ asset('assets/vendors/jquery/jquery-3.6.0.min.js') }}"></script>
    @include('shared.workspace_assets')
</head>
<body>

	<div class="sidebar">
		<div class="logo-details mt-4 mb-3">
      <div class="img_wrapper">
          <img height="40px" class="" src="{{ institution_logo_url() }}" alt="" />
      </div>
      <span class="logo_name">{{ institution_name() }}</span>
    </div>
		<div class="closeIcon">
      <span>
        <img src="{{ asset('assets/images/close.svg') }}">
      </span>
    </div>
		<ul class="nav-links">
			<!-- sidebar title -->
      <li class="nav-links-li {{ request()->is('accountant/dashboard') ? 'showMenu':'' }}">
        <div class="iocn-link">
          <a href="{{ route('accountant.dashboard') }}">
            <div class="sidebar_icon">
              <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" id="Capa_1" x="0px" y="0px" viewBox="0 0 512 512" style="enable-background:new 0 0 512 512;" xml:space="preserve" width="48" height="48">
              <g>
                <path d="M117.333,234.667C52.532,234.667,0,182.135,0,117.333S52.532,0,117.333,0s117.333,52.532,117.333,117.333   C234.596,182.106,182.106,234.596,117.333,234.667z M117.333,64C87.878,64,64,87.878,64,117.333s23.878,53.333,53.333,53.333   s53.333-23.878,53.333-53.333S146.789,64,117.333,64z"/>
                <path d="M394.667,234.667c-64.801,0-117.333-52.532-117.333-117.333S329.865,0,394.667,0S512,52.532,512,117.333   C511.929,182.106,459.439,234.596,394.667,234.667z M394.667,64c-29.455,0-53.333,23.878-53.333,53.333   s23.878,53.333,53.333,53.333S448,146.789,448,117.333S424.122,64,394.667,64z"/>
                <path d="M117.333,512C52.532,512,0,459.468,0,394.667s52.532-117.333,117.333-117.333s117.333,52.532,117.333,117.333   C234.596,459.439,182.106,511.929,117.333,512z M117.333,341.333C87.878,341.333,64,365.211,64,394.667S87.878,448,117.333,448   s53.333-23.878,53.333-53.333S146.789,341.333,117.333,341.333z"/>
                <path d="M394.667,512c-64.801,0-117.333-52.532-117.333-117.333s52.532-117.333,117.333-117.333S512,329.865,512,394.667   C511.929,459.439,459.439,511.929,394.667,512z M394.667,341.333c-29.455,0-53.333,23.878-53.333,53.333S365.211,448,394.667,448   S448,424.122,448,394.667S424.122,341.333,394.667,341.333z"/>
              </g>
              </svg>

            </div>
            <span class="link_name">{{ get_phrase('Dashboard') }}</span>
          </a>
        </div>
      </li>
			<!-- Sidebar menu -->
	</div>

	<section class="home-section">
      <div class="home-content">
        <div class="home-header">
          <div class="row w-100 justify-content-between align-items-center">
            <div class="col-auto">
              <div class="sidebar_menu_icon">
                <div class="menuList">
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="15"
                    height="12"
                    viewBox="0 0 15 12"
                  >
                    <path
                      id="Union_5"
                      data-name="Union 5"
                      d="M-2188.5,52.5v-2h15v2Zm0-5v-2h15v2Zm0-5v-2h15v2Z"
                      transform="translate(2188.5 -40.5)"
                      fill="#6e6f78"
                    />
                  </svg>
                </div>
              </div>
            </div>

            <div class="col-auto d-xl-block d-none">
              <div class="header_notification d-flex align-items-center">
                <div class="notification_icon">
                  <svg style="width: 14px; fill: #00a3ff;" xmlns="http://www.w3.org/2000/svg" id="Layer_1" data-name="Layer 1" viewBox="0 0 24 24" width="48px" height="48px"><path d="M19,6H14.321A3.95,3.95,0,0,0,13,5.388V5l3.53-1.652a1,1,0,0,0,0-1.7l-3.2-1.5A1.637,1.637,0,0,0,11,1.636V5.388A3.937,3.937,0,0,0,9.68,6H5a5.006,5.006,0,0,0-5,5V21a3,3,0,0,0,3,3H21a3,3,0,0,0,3-3V11A5.006,5.006,0,0,0,19,6Zm2.816,4H19.657a3.017,3.017,0,0,1-2.121-.879L16.414,8H19A3,3,0,0,1,21.816,10ZM5,8H7.586L6.464,9.121A3.017,3.017,0,0,1,4.343,10H2.184A3,3,0,0,1,5,8Zm8,14H11V19a1,1,0,0,1,2,0Zm8,0H15V19a3,3,0,0,0-6,0v3H3a1,1,0,0,1-1-1V12H4.343a4.968,4.968,0,0,0,3.535-1.465l2.708-2.707a2,2,0,0,1,2.828,0l2.708,2.707A4.968,4.968,0,0,0,19.657,12H22v9A1,1,0,0,1,21,22ZM7,15a1,1,0,0,1-1,1H5a1,1,0,0,1,0-2H6A1,1,0,0,1,7,15Zm0,4a1,1,0,0,1-1,1H5a1,1,0,0,1,0-2H6A1,1,0,0,1,7,19Zm13-4a1,1,0,0,1-1,1H18a1,1,0,0,1,0-2h1A1,1,0,0,1,20,15Zm0,4a1,1,0,0,1-1,1H18a1,1,0,0,1,0-2h1A1,1,0,0,1,20,19Zm-6-7a2,2,0,1,1-2-2A2,2,0,0,1,14,12Z"></path></svg>
                </div>
                <p>
                  {{ DB::table('schools')->where('id', auth()->user()->school_id)->value('title') }}
                </p>
              </div>
            </div>
            
            <div class="col-auto d-flex">
              @php
                $all_languages = get_all_language();
                $usersinfo = DB::table('users')->where('id', auth()->user()->id)->first();

                $userlanguage = $usersinfo->language;
                
              @endphp
              <div class="adminTable-action" style="margin-right: 20px; margin-top: 14px;">
                <button
                  type="button"
                  class="eBtn eBtn-black dropdown-toggle table-action-btn-2"
                  data-bs-toggle="dropdown"
                  aria-expanded="false"
                  style="width: 91px; height: 29px; padding: 0;"
                >
                   <svg width="24" height="24" viewBox="0 0 24 24" focusable="false" class="ep0rzf NMm5M" style="width: 17px"><path d="M12.87 15.07l-2.54-2.51.03-.03A17.52 17.52 0 0 0 14.07 6H17V4h-7V2H8v2H1v1.99h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04zM18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12zm-2.62 7l1.62-4.33L19.12 17h-3.24z"></path></svg>
                   
                    @if(!empty($userlanguage))
                   <span style="font-size: 10px;">{{ucwords($userlanguage)}}</span>
                   @else
                   <span style="font-size: 10px;">{{ucwords(get_settings('language'))}}</span>
                   @endif
                </button>
                
                <ul style="min-width: 0;" class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action">
                  <form method="post" id="languageForm" action="{{ route('accountant.language') }}">
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
              <div class="header-menu">
                <ul>
                  <li class="user-profile">
                    <div class="btn-group">
                      <button
                        class="btn btn-secondary dropdown-toggle"
                        type="button"
                        id="defaultDropdown"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="true"
                        aria-expanded="false"
                      >
                        <div class="">
                          <img src="{{ get_user_image(auth()->user()->id) }}" height="42px" />
                        </div>
                        <div class="px-2 text-start">
                          <span class="user-name">{{ auth()->user()->name }}</span>
                          <span class="user-title">{{ get_phrase('accountant') }}</span>
                        </div>
                      </button>
                      <ul
                        class="dropdown-menu dropdown-menu-end eDropdown-menu"
                        aria-labelledby="defaultDropdown"
                      >
                        <li class="user-profile user-profile-inner">
                          <button
                            class="btn w-100 d-flex align-items-center"
                            type="button"
                          >
                            <div class="">
                              <img
                                class="radious-5px"
                                src="{{ get_user_image(auth()->user()->id) }}"
                                height="42px"
                              />
                            </div>
                            <div class="px-2 text-start">
                              <span class="user-name">{{ auth()->user()->name }}</span>
                              <span class="user-title">{{ get_phrase('accountant') }}</span>
                            </div>
                          </button>
                        </li>
                        <!-- Logout Button -->
                        <li>
                            <a class="btn eLogut_btn" href="{{ route('logout') }}" onclick="event.preventDefault();document.getElementById('logout-form').submit();">
                                <span>
                                  <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    width="14.046"
                                    height="12.29"
                                    viewBox="0 0 14.046 12.29"
                                  >
                                    <path
                                      id="Logout"
                                      d="M4.389,42.535H2.634a.878.878,0,0,1-.878-.878V34.634a.878.878,0,0,1,.878-.878H4.389a.878.878,0,0,0,0-1.756H2.634A2.634,2.634,0,0,0,0,34.634v7.023A2.634,2.634,0,0,0,2.634,44.29H4.389a.878.878,0,1,0,0-1.756Zm9.4-5.009-3.512-3.512a.878.878,0,0,0-1.241,1.241l2.015,2.012H5.267a.878.878,0,0,0,0,1.756H11.05L9.037,41.036a.878.878,0,1,0,1.241,1.241l3.512-3.512A.879.879,0,0,0,13.788,37.525Z"
                                      transform="translate(0 -32)"
                                      fill="#fff"
                                    />
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
        <div class="main_content">
            <div class="row">
                <div class="col-12">
                    <div class="eSection-wrap pb-3">
                        <div class="title">
                            <h3 class="mb-2">{{get_phrase(' Important Notice: Your Account Has Been Disabled')}}</h3>
                            <p>Dear {{ auth()->user()->name }},</p>
                            <p>{{get_phrase('We regret to inform you that your account with')}} {{ DB::table('schools')->where('id', auth()->user()->school_id)->value('title') }}{{get_phrase(' has been disabled effective immediately')}}.</p>
                        </div>
                        
                        
                        <h3 class="mb-2" style="font-size: 15px; color:#181c32;">{{get_phrase('What this means for you')}}:</h3>
                        <ul>
                            <p class="column-title">{{get_phrase('You will no longer be able to access your account.')}}</p>
                            <p class="column-title">{{get_phrase('All services and features associated with your account are now inaccessible.')}}</p>
                            <p class="column-title ">{{get_phrase('Any active subscriptions or services have been terminated.')}}</p>
                        </ul>
                        
                    </div>
                </div>
            </div>
            
            @yield('content')
            <!-- Start Footer -->
            <div class="copyright-text">
              <?php $active_session = DB::table('sessions')->where('id',  get_settings('running_session'))->value('session_title'); ?>
                <p>{{ $active_session }} &copy; <span><a class="text-info" target="_blank" href="{{ get_settings('footer_link') }}">{{ get_settings('footer_text') }}</a></span></p>
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


</body>
</html>
