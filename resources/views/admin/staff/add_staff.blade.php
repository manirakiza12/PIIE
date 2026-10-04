@extends('admin.navigation')

@section('content')
{{-- Staff entry launcher into the existing staff creation workflows; nothing is created here. --}}
<style>
    .staff-launcher .sl-intro { color: #475467; font-size: .9rem; margin: 0; }
    .staff-launcher .sl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
    .staff-launcher .sl-card { display: flex; flex-direction: column; gap: .75rem; background: #fff; border: 1px solid #e4e7ec; border-radius: 10px; padding: 1.25rem; }
    .staff-launcher .sl-card:hover { border-color: #b2ccff; box-shadow: 0 4px 16px rgba(16, 24, 40, .06); }
    .staff-launcher .sl-head { display: flex; align-items: center; gap: .75rem; }
    .staff-launcher .sl-icon { width: 42px; height: 42px; border-radius: 10px; background: #eff4ff; color: #1d4ed8; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0; }
    .staff-launcher .sl-title { font-size: 1rem; font-weight: 600; color: #101828; margin: 0; }
    .staff-launcher .sl-desc { color: #475467; font-size: .85rem; margin: 0; flex-grow: 1; }
    .staff-launcher .sl-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
    .staff-launcher .sl-actions .btn { flex: 1 1 auto; }
    .staff-launcher .sl-note { background: #f9fafb; border: 1px solid #e4e7ec; border-radius: 10px; padding: 1rem 1.25rem; color: #344054; font-size: .875rem; }
</style>

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Add Staff Member') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="{{ route('admin.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                        <li><a href="#">{{ get_phrase('Staff') }}</a></li>
                        <li><a href="#">{{ get_phrase('Add Staff Member') }}</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="staff-launcher">
    <div class="eSection-wrap mb-3">
        <p class="sl-intro">{{ get_phrase("Select the staff member's main responsibility. You can assign additional responsibilities and permissions after creating their profile.") }}</p>
    </div>

    <div class="sl-grid mb-3">
        @foreach ($types as $key => $type)
            <div class="sl-card" data-staff-type="{{ $key }}">
                <div class="sl-head">
                    <span class="sl-icon" aria-hidden="true"><i class="bi {{ $type['icon'] }}"></i></span>
                    <h5 class="sl-title">{{ get_phrase($type['label']) }}</h5>
                </div>
                <p class="sl-desc">{{ get_phrase($type['description']) }}</p>
                <div class="sl-actions">
                    @if ($key === 'other')
                        <a class="btn btn-primary" href="{{ route($type['form']) }}"><i class="bi bi-plus-lg"></i> {{ get_phrase('+ Add Other Staff') }}</a>
                        <a href="{{ route($type['list']) }}" class="btn btn-outline-secondary">{{ get_phrase('Staff Directory') }}</a>
                    @else
                    <a class="btn btn-primary" href="{{ route('admin.staff.create', $key === 'teacher' ? 'lecturer' : $key) }}"><i class="bi bi-plus-lg"></i> {{ get_phrase('Create ' . $type['label']) }}</a>
                    <a href="{{ route($type['list']) }}" class="btn btn-outline-secondary">{{ get_phrase('View list') }}</a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="sl-note">
        <i class="bi bi-info-circle"></i>
        {{ get_phrase('After creating the profile, use') }}
        @permission('roles.view')
            <a href="{{ route('admin.rbac.staff.index') }}" class="fw-semibold">{{ get_phrase('Staff Directory') }} → {{ get_phrase('Manage Access') }}</a>
        @else
            {{ get_phrase('Staff Directory → Manage Access') }}
        @endpermission
        {{ get_phrase('to assign additional responsibilities such as Examinations Officer, Head of Department, or Programme Coordinator.') }}
    </div>
</div>
@endsection
