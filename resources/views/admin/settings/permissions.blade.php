@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Advanced Permissions') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="{{ route('admin.settings.school') }}">{{ get_phrase('Settings') }}</a></li>
                <li><a href="#">{{ get_phrase('Permissions') }}</a></li>
            </ul>
        </div>
    </div>
</div></div></div>

@include('admin.settings.partials.settings_nav', ['active' => 'permissions'])
{{-- RBAC Phase 3B: kept working as-is (legacy global role_perm matrix); new staff delegation lives in Roles & Permissions. --}}
<div class="alert alert-warning d-flex gap-2 align-items-start" role="note">
    <i class="bi bi-exclamation-triangle"></i>
    <div>
        <strong>{{ get_phrase('Legacy role matrix') }}</strong> —
        {{ get_phrase('these settings are shared by every school on the platform, and only the Online Exams entries still take effect. To give staff additional responsibilities in your school, use') }}
        @if(\Illuminate\Support\Facades\Route::has('admin.rbac.roles.index'))<a href="{{ route('admin.rbac.roles.index') }}" class="fw-semibold">{{ get_phrase('Roles & Permissions') }}</a>.@endif
    </div>
</div>
@if(session('success'))<div class="alert alert-success"><i class="bi bi-check-circle"></i> {{ session('success') }}</div>@endif

<div class="eSection-wrap">
    <div class="p-3 border-bottom">
        <strong>{{ get_phrase('Role-Based Permission Matrix') }}</strong>
        <span class="text-muted ms-2" style="font-size:11px">{{ get_phrase('Check permissions to grant access to each role') }}</span>
    </div>
    <div class="p-4">
    <form action="{{ route('admin.settings.permissions.save') }}" method="POST">
    @csrf
    @foreach($roles as $role)
    @php $roleKey = $role->role_id ?? $role->id; @endphp
    <div class="mb-4 pb-4 border-bottom">
        <div class="d-flex align-items-center gap-2 mb-3">
            <span class="badge bg-primary" style="font-size:12px">{{ $role->name }}</span>
        </div>
        <div class="row g-2">
        @foreach($all_perms as $group => $perms)
            <div class="col-md-4 col-sm-6">
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#6c757d;margin-bottom:6px">{{ $group }}</div>
                @foreach($perms as $perm)
                <label class="d-flex align-items-center gap-2 mb-1" style="cursor:pointer;font-size:12px">
                    <input type="checkbox" name="perms[{{ $roleKey }}][]" value="{{ $perm }}"
                        {{ in_array($perm, $role_perms[$roleKey] ?? []) ? 'checked' : '' }}>
                    {{ get_phrase($perm) }}
                </label>
                @endforeach
            </div>
        @endforeach
        </div>
    </div>
    @endforeach
    @if((int) auth()->user()->role_id === 2)
    <div class="mt-3">
        <button type="submit" class="eBtn eBtn-primary"><i class="bi bi-shield-check"></i> {{ get_phrase('Save Permissions') }}</button>
    </div>
    @endif
    </form>
    </div>
</div>
@endsection
