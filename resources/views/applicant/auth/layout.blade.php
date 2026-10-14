@php
    $logoAsset   = asset('assets/uploads/logo/logo.png');
    $institution = get_settings('system_title') ?: 'Prime International Institute of Excellence';
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
@include('shared.typography')
</head>
<body class="ap-shell">

<div class="ap-auth">
    <div class="ap-auth-card">
        <div class="ap-auth-brand">
            <img src="{{ $logoAsset }}" alt="{{ $institution }}" onerror="this.style.display='none'">
            <h1>@yield('heading', get_phrase('Applicant Portal'))</h1>
            <p>@yield('subheading', $institution)</p>
        </div>

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
                <ul class="mb-0 ps-3">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </div>
</div>

<script src="{{ asset('assets/vendors/bootstrap-5.1.3/js/bootstrap.bundle.min.js') }}"></script>
<script>
    "use strict";
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
