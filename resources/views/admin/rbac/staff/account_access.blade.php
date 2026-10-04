@extends('admin.navigation')

{{-- The EXISTING governed account setup screen, now reachable for every staff
     base role. Wording is the platform's canonical account-access vocabulary, so
     the same words appear here, on the staff profile and in the directory. --}}
@section('content')
<div class="rbac">
    @include('admin.rbac._header', [
        'title' => get_phrase('Account Access'),
        'crumb' => $member->name,
        'tab' => 'staff',
    ])

    @if (session('message'))<div class="alert alert-success">{{ session('message') }}</div>@endif
    @if (session('error'))<div class="alert alert-warning">{{ session('error') }}</div>@endif

    @php
        $portalBlocked = $member->account_status === 'disable'
            || \App\Support\Staff\StaffStatus::blocksPortal($member->staff_status);

        $stateLabel = [
            'required' => get_phrase('Setup required'),
            'pending' => get_phrase('Pending setup'),
            'completed' => get_phrase('Completed'),
        ][$setupState];
    @endphp

    <div class="rbac-card">
        <h5>{{ get_phrase('Account Access') }}</h5>
        <p class="rbac-muted">
            @if ($setupState === 'completed')
                {{ get_phrase('Password setup is completed. The staff member can use password recovery on the sign-in page if needed.') }}
            @elseif ($setupState === 'pending')
                {{ get_phrase('Send a secure link so this staff member can choose their own password. No password is sent by email.') }}
            @else
                {{ get_phrase('Send a secure link so this staff member can choose their own password. No password is sent by email.') }}
            @endif
        </p>

        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-4">
                <div class="rbac-muted small">{{ get_phrase('Email') }}</div>
                <div class="text-break">{{ $member->email }}</div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="rbac-muted small">{{ get_phrase('Login account') }}</div>
                <div>{{ $member->account_status === 'disable' ? get_phrase('Disabled') : get_phrase('Enabled') }}</div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="rbac-muted small">{{ get_phrase('Staff status') }}</div>
                <div>{{ get_phrase(ucwords(str_replace('_', ' ', $member->staff_status ?: 'active'))) }}</div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="rbac-muted small">{{ get_phrase('Password setup') }}</div>
                <div>
                    <span class="rbac-badge {{ $setupState === 'completed' ? 'rbac-badge-active' : ($setupState === 'pending' ? 'rbac-badge-base' : 'rbac-badge-legacy') }}">
                        {{ $stateLabel }}
                    </span>
                </div>
            </div>
        </div>

        @if ($portalBlocked)
            <div class="rbac-warning mb-3">
                <i class="bi bi-shield-lock"></i>
                {{ get_phrase('This staff member cannot sign in while their account is disabled or their employment status blocks the portal. A setup link can still be sent, but they will not be able to use it until access is restored.') }}
            </div>
        @endif

        @if ($setupState !== 'completed' && ! $mailConfigured)
            {{-- Read-only notice. It explains why a link would be undeliverable;
                 it never changes the institution's email settings. --}}
            <div class="rbac-warning mb-3">
                <i class="bi bi-envelope-exclamation"></i>
                {{ get_phrase("This institution's email settings are not configured yet, so a setup link cannot be delivered. An administrator must configure them under Settings first.") }}
            </div>
        @endif

        <div class="rbac-actions">
            @if ($setupState !== 'completed')
                {{-- The single governed action. Same controller endpoint, same
                     broker, same mail, same audit entry as every other staff
                     account; nothing here sets or displays a password. --}}
                <form method="POST" action="{{ route('admin.staff.account-access.send', $member->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i>
                        {{ $setupState === 'pending' ? get_phrase('Resend Password Setup Link') : get_phrase('Send Password Setup Link') }}
                    </button>
                </form>
            @else
                {{-- Setup is complete, so there is deliberately no send action.
                     Password recovery on the sign-in page is the existing way in. --}}
                <span class="rbac-muted">
                    <i class="bi bi-check-circle"></i>
                    {{ get_phrase('Password setup is completed. The staff member can use password recovery on the sign-in page if needed.') }}
                </span>
            @endif

            <a class="btn btn-outline-secondary" href="{{ route('admin.staff.profile.show', $member->id) }}">{{ get_phrase('Back to Staff Profile') }}</a>
            <a class="btn btn-outline-secondary" href="{{ route('admin.rbac.staff.show', $member->id) }}">{{ get_phrase('Roles & Permissions') }}</a>
        </div>
    </div>
</div>
@endsection
