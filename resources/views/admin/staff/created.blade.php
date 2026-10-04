@extends('admin.navigation')
@section('content')
@php
    $initials = strtoupper(mb_substr((string) $created->first_name, 0, 1).mb_substr((string) $created->last_name, 0, 1));
@endphp

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Staff Account Created') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="{{ route('admin.staff.add') }}">{{ get_phrase('Add Staff') }}</a></li>
                        <li><span>{{ get_phrase('Created') }}</span></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="eSection-wrap mb-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <span class="sc-avatar" aria-hidden="true">{{ $initials }}</span>
        <div class="flex-grow-1">
            <h5 class="mb-1">{{ trim($created->title.' '.$created->first_name.' '.$created->last_name) }}</h5>
            <p class="text-muted mb-0">{{ $label }} &middot; {{ $created->email }}</p>
        </div>
        <span class="badge bg-success-subtle text-success-emphasis px-3 py-2">{{ get_phrase('Profile created') }}</span>
    </div>

    <hr>

    <div class="row g-3">
        <div class="col-12 col-md-4">
            <div class="sc-fact">
                <span class="sc-fact-label">{{ get_phrase('Staff Number') }}</span>
                <strong class="sc-fact-value">{{ $created->code ?: '—' }}</strong>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="sc-fact">
                <span class="sc-fact-label">{{ get_phrase('Account Access') }}</span>
                <strong class="sc-fact-value">{{ get_phrase('Setup Required') }}</strong>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="sc-fact">
                <span class="sc-fact-label">{{ get_phrase('Employment Status') }}</span>
                <strong class="sc-fact-value">{{ ucwords(str_replace('_', ' ', (string) $created->staff_status)) }}</strong>
            </div>
        </div>
    </div>
</div>

<div class="eSection-wrap mb-3">
    <h5 class="mb-2">{{ get_phrase('Next Step: Set Up Portal Access') }}</h5>
    <p class="text-muted">
        {{ get_phrase('This staff member has no password yet. Send a secure setup link so they choose their own password. Nothing is emailed until you choose to send it.') }}
    </p>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="{{ route('admin.rbac.staff.account-access', $created->id) }}">
            <i class="bi bi-shield-lock"></i> {{ get_phrase('Send Setup Link / Manage Access') }}
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('admin.rbac.staff.show', $created->id) }}">
            <i class="bi bi-person-vcard"></i> {{ get_phrase('View Staff Profile') }}
        </a>
        <a class="btn btn-light" href="{{ route('admin.rbac.staff.index') }}">
            {{ get_phrase('Return to Staff Directory') }}
        </a>
    </div>
</div>

@if($profile && ($profile->title || $profile->emergency_contact_name))
    <div class="eSection-wrap mb-3">
        <h5 class="mb-2">{{ get_phrase('Recorded on this staff record') }}</h5>
        <dl class="row mb-0 sc-summary">
            @if($profile->title)
                <dt class="col-6 col-sm-4">{{ get_phrase('Title') }}</dt><dd class="col-6 col-sm-8">{{ \App\Support\Staff\StaffTitle::base($profile->title) }}</dd>
            @endif
            @if($profile->years_teaching_experience !== null)
                <dt class="col-6 col-sm-4">{{ get_phrase('Years of Experience') }}</dt><dd class="col-6 col-sm-8">{{ $profile->years_teaching_experience }}</dd>
            @endif
            @if($profile->emergency_contact_name)
                <dt class="col-6 col-sm-4">{{ get_phrase('Next of Kin') }}</dt>
                <dd class="col-6 col-sm-8">{{ $profile->emergency_contact_name }} ({{ \App\Support\Staff\StaffNextOfKin::base($profile->emergency_contact_relationship) }})</dd>
            @endif
        </dl>
    </div>
@endif

<style>
    .sc-avatar { width: 52px; height: 52px; border-radius: 12px; background: #eff4ff; color: #1d4ed8; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.1rem; flex-shrink: 0; }
    .sc-fact { background: #f9fafb; border: 1px solid #e4e7ec; border-radius: 10px; padding: .875rem 1rem; height: 100%; }
    .sc-fact-label { display: block; font-size: .78rem; color: #475467; text-transform: uppercase; letter-spacing: .03em; margin-bottom: .25rem; }
    .sc-fact-value { font-size: 1.05rem; color: #101828; }
    .sc-summary dt { color: #475467; font-weight: 500; }
</style>
@endsection
