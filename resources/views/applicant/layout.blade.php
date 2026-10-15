@php
    $applicant = auth('applicant')->user();
    $logoAsset = asset('assets/uploads/logo/logo.png');
    $institution = institution_name();
    $supportPhone = get_settings('system_phone') ?: get_settings('phone');
    $supportEmail = get_settings('system_email') ?: get_settings('smtp_user');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', get_phrase('Applicant Portal')) — {{ $institution }}</title>
    <link rel="shortcut icon" href="{{ $logoAsset }}">

    <link rel="stylesheet" href="{{ asset('assets/vendors/bootstrap-5.1.3/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/vendors/bootstrap-icons-1.8.1/bootstrap-icons.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/applicant-portal.css') }}">
    @stack('styles')
    @include('shared.workspace_assets')
</head>
<body class="ap-shell">

<aside class="ap-sidebar" id="apSidebar">
    <div class="ap-brand">
        <img src="{{ $logoAsset }}" alt="{{ $institution }}" onerror="this.style.display='none'">
        <span class="ap-brand-text d-none d-lg-inline">{{ get_phrase('Applicant Portal') }}</span>
    </div>

    <div class="ap-nav-label">{{ get_phrase('Applicant Portal Navigation') }}</div>

    <nav>
        <a href="{{ route('applicant.dashboard') }}" class="ap-nav-item {{ request()->routeIs('applicant.dashboard') ? 'active' : '' }}">
            <i class="bi bi-house-door"></i> {{ get_phrase('Dashboard') }}
        </a>
        <a href="{{ route('applicant.application') }}" class="ap-nav-item {{ request()->routeIs('applicant.application*') ? 'active' : '' }}">
            <i class="bi bi-file-earmark-text"></i> {{ get_phrase('My Application') }}
        </a>
        <a href="{{ route('applicant.track') }}" class="ap-nav-item {{ request()->routeIs('applicant.track') ? 'active' : '' }}">
            <i class="bi bi-check2-square"></i> {{ get_phrase('Track My Application') }}
        </a>
        <a href="{{ route('applicant.documents') }}" class="ap-nav-item {{ request()->routeIs('applicant.documents*') ? 'active' : '' }}">
            <i class="bi bi-folder2"></i> {{ get_phrase('Documents') }}
        </a>
        @isset($admission)
            @if(\App\Support\Admissions\ApplicationFee::isRequired($admission))
                <a href="{{ route('applicant.payment') }}" class="ap-nav-item {{ request()->routeIs('applicant.payment*') ? 'active' : '' }}">
                    <i class="bi bi-credit-card"></i> {{ get_phrase('Application Fee') }}
                </a>
            @endif
        @endisset
        <a href="{{ route('applicant.profile') }}" class="ap-nav-item {{ request()->routeIs('applicant.profile') ? 'active' : '' }}">
            <i class="bi bi-person-gear"></i> {{ get_phrase('Account Settings') }}
        </a>
    </nav>

    <div class="ap-help">
        <div class="ap-help-title">{{ get_phrase('Need help?') }}</div>
        @if($supportPhone)
            <a href="tel:{{ $supportPhone }}"><i class="bi bi-telephone"></i> {{ $supportPhone }}</a>
        @endif
        @if($supportEmail)
            <a href="mailto:{{ $supportEmail }}"><i class="bi bi-envelope"></i> {{ $supportEmail }}</a>
        @endif
        <a href="{{ url('/') }}"><i class="bi bi-globe2"></i> {{ get_phrase('Visit our website') }}</a>
    </div>

    <form action="{{ route('applicant.logout') }}" method="POST">
        @csrf
        <button type="submit" class="ap-logout"><i class="bi bi-box-arrow-right"></i> {{ get_phrase('Logout') }}</button>
    </form>
</aside>

<div class="ap-backdrop d-none" id="apBackdrop"></div>

<div class="ap-main">
    <header class="ap-topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="ap-mobile-toggle" id="apToggle" type="button" aria-label="{{ get_phrase('Toggle navigation') }}">
                <i class="bi bi-list"></i>
            </button>
            <div>
                <h1>@yield('title', get_phrase('Dashboard'))</h1>
                @hasSection('subtitle')
                    <p class="ap-subtitle">@yield('subtitle')</p>
                @endif
            </div>
        </div>

        <div class="ap-user">
            <div>
                <div class="ap-user-name">{{ $applicant->full_name }}</div>
                <div class="ap-user-role">{{ get_phrase('Applicant') }}</div>
            </div>
            <div class="ap-avatar">{{ $applicant->initials }}</div>
        </div>
    </header>

    <main class="ap-content">
        @if(session('success'))
            <div class="alert alert-success d-flex align-items-start gap-2">
                <i class="bi bi-check-circle-fill mt-1"></i><div>{{ session('success') }}</div>
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill mt-1"></i><div>{{ session('error') }}</div>
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                <strong>{{ get_phrase('Please check the following') }}:</strong>
                <ul class="mb-0 mt-2">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</div>

<script src="{{ asset('assets/vendors/bootstrap-5.1.3/js/bootstrap.bundle.min.js') }}"></script>
<script>
    (function () {
        var sidebar  = document.getElementById('apSidebar');
        var toggle   = document.getElementById('apToggle');
        var backdrop = document.getElementById('apBackdrop');

        function close() {
            sidebar.classList.remove('open');
            backdrop.classList.add('d-none');
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                sidebar.classList.toggle('open');
                backdrop.classList.toggle('d-none', !sidebar.classList.contains('open'));
            });
        }

        if (backdrop) backdrop.addEventListener('click', close);
    })();

    function togglePasswordVisibility(inputId, btn) {
        var input = document.getElementById(inputId);
        var icon = btn.querySelector('i');
        var showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        icon.classList.toggle('bi-eye', showing);
        icon.classList.toggle('bi-eye-slash', !showing);
        btn.setAttribute('aria-label', showing ? '{{ get_phrase('Show password') }}' : '{{ get_phrase('Hide password') }}');
    }
</script>
@stack('scripts')
</body>
</html>
