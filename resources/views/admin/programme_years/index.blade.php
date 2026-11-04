@extends('admin.navigation')
@section('page_styles')
<link rel="stylesheet" href="{{ asset('assets/css/curriculum-workspace.css') }}">
<style>
    .programme-years-compact .table.eTable { margin-bottom: 0; }
    .programme-years-compact .table.eTable thead th { padding: 10px 12px; font-size: 13px; white-space: nowrap; }
    .programme-years-compact .table.eTable tbody td { padding: 8px 12px; vertical-align: middle; font-size: 14px; }
    .programme-years-compact .table.eTable tbody tr { height: auto; }
    .programme-years-compact .adminTable-action { margin: 0; }
    .programme-years-compact .adminTable-action .table-action-btn-2 { padding: 4px 12px; font-size: 13px; }
    .programme-years-filter .form-label { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
    .programme-years-filter .form-control, .programme-years-filter .form-select { height: 38px; font-size: 14px; }
</style>
@endsection
@section('content')
<div class="curriculum-workspace programme-years-compact">
@php
    $isOwner = \App\Support\Curriculum\CurriculumOwnerAccess::allows(auth()->user(), (int) auth()->user()->school_id);
    $editableCurricula = $isOwner ? $curricula : $curricula->where('status', 'draft');
@endphp
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>{{ get_phrase('Programme Years') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="#">{{ get_phrase('Home') }}</a></li>
                <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                <li aria-current="page">{{ get_phrase('Programme Years') }}</li>
            </ul>
            <p class="text-muted mb-0">{{ get_phrase('Define study-year levels within a Programme Curriculum, such as Year 1 or Foundation Year. Academic Years are dated calendar records; Semester or Term is a separate period on each Course Unit placement.') }}</p>
        </div>
    </div>
</div>
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}@if(session('programme_year_next_course_units')) <a class="alert-link ms-2" href="{{ route('admin.curriculum_units.index', ['curriculum_id' => session('programme_year_next_course_units')]) }}#course-units">Next: Add Course Units</a>@endif</div>@endif
@if(session('curriculum_reopened'))<div class="alert alert-info">Your owner change reopened the curriculum as Draft. Approve it again when all corrections are complete.</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="eSection-wrap mb-3 programme-years-filter">
    <form method="GET" class="row g-2 align-items-end" action="{{ route('admin.programme_years.index') }}">
        <div class="col-12 col-md-9">
            <label for="filter-curriculum" class="form-label">{{ get_phrase('Programme Curriculum') }}</label>
            <select id="filter-curriculum" name="curriculum_id" class="form-select">
                <option value="">{{ get_phrase('All curricula') }}</option>
                @foreach($curricula as $curriculum)
                    <option value="{{ $curriculum->id }}" @selected($selected?->id === $curriculum->id)>{{ $curriculum->programme?->name }} — {{ $curriculum->version }} ({{ ucfirst($curriculum->status) }})</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-3 d-flex align-items-end">
            <button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Filter') }}</button>
        </div>
    </form>
</section>
@if(auth()->user()->hasPermission('academic.curriculum.manage'))
<section class="eSection-wrap mb-3">
    <h5>{{ get_phrase('Create Programme Year') }}</h5>
    @if($editableCurricula->isEmpty())<p class="text-muted mb-0">{{ get_phrase('Create a draft in the Programme Curriculum module before adding its years.') }}</p>@else
    <form method="POST" action="{{ route('admin.programme_years.store') }}" class="row g-3">@csrf
        <div class="col-12 col-md-6">
            <label for="new-curriculum" class="form-label">{{ get_phrase('Programme Curriculum') }}</label>
            <select id="new-curriculum" name="curriculum_id" class="form-select" required>
                <option value="">{{ get_phrase('Choose curriculum') }}</option>
                @foreach($editableCurricula as $curriculum)
                    <option value="{{ $curriculum->id }}" @selected(old('curriculum_id', $selected?->id) == $curriculum->id)>{{ $curriculum->programme?->name }} — {{ $curriculum->version }} ({{ ucfirst($curriculum->status) }})</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label for="new-label" class="form-label">{{ get_phrase('Programme Year name') }}</label>
            <input id="new-label" name="label" class="form-control @error('label') is-invalid @enderror" value="{{ old('label') }}" maxlength="100" required placeholder="Year 1" aria-describedby="new-label-help">
            @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <small id="new-label-help" class="form-text">{{ get_phrase('Examples: Year 1, Year 2, Foundation Year. Set Semester or Term on Course Unit placement instead.') }}</small>
        </div>
        <div class="col-12 col-md-3">
            <label for="new-sequence" class="form-label">{{ get_phrase('Order in curriculum') }}</label>
            <input id="new-sequence" name="sequence" type="number" class="form-control @error('sequence') is-invalid @enderror" min="1" max="65535" value="{{ old('sequence', 1) }}" required>
            @error('sequence')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <small class="form-text">{{ get_phrase('Each position can be used once in this curriculum.') }}</small>
        </div>
        @if($isOwner)
        <div class="col-12">
            <label for="create-reason" class="form-label">{{ get_phrase('Reason for owner change (required for approved or retired curricula)') }}</label>
            <input id="create-reason" name="reason" class="form-control @error('reason') is-invalid @enderror" maxlength="500" value="{{ old('reason') }}">
            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <small>{{ get_phrase('Changing an approved or retired curriculum reopens it as Draft. Approve it again when corrections are complete.') }}</small>
        </div>
        @endif
        <div class="col-12"><button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Create Programme Year') }}</button></div>
    </form>
    @endif
</section>
@endif
<section class="eSection-wrap">
    <h5>{{ get_phrase('Programme Years') }}</h5>
    <div class="table-responsive table-responsive-actions">
        <table class="table eTable align-middle">
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">{{ get_phrase('Programme Year') }}</th>
                    <th scope="col">{{ get_phrase('Programme Curriculum') }}</th>
                    <th scope="col">{{ get_phrase('Status') }}</th>
                    <th scope="col">{{ get_phrase('Course Units') }}</th>
                    <th scope="col">{{ get_phrase('Student Records') }}</th>
                    <th scope="col" class="text-end">{{ get_phrase('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($years as $index => $year)
                @php($containsPeriodLabel = preg_match('/\b(?:semester|term)\s*(?:no\.?\s*)?[1-9][0-9]*\b/i', $year->label) === 1)
                <tr>
                    <td class="text-nowrap">{{ $years->firstItem() + $index }}</td>
                    <td>
                        <span class="fw-semibold text-nowrap">{{ $year->sequence }}. {{ $year->label }}</span>
                        @if($containsPeriodLabel)<span class="badge bg-warning text-dark ms-1" title="{{ get_phrase('This label includes a Semester or Term. Programme Year is the study-year level; the period belongs to Course Unit placement.') }}">!</span>@endif
                        @if($year->archived_at)<span class="badge bg-secondary ms-1">{{ get_phrase('Archived') }}</span>@endif
                    </td>
                    <td class="text-truncate" style="max-width: 220px;" title="{{ $year->curriculum->programme?->name }} — {{ $year->curriculum->version }}">
                        {{ $year->curriculum->programme?->name }} — {{ $year->curriculum->version }}
                        <div class="small text-muted">{{ ucfirst($year->curriculum->status) }}</div>
                    </td>
                    <td>
                        @if($year->archived_at)
                            <span class="badge bg-secondary">{{ get_phrase('Archived') }}</span>
                        @else
                            <span class="badge bg-{{ $year->curriculum->status === 'approved' ? 'success' : ($year->curriculum->status === 'retired' ? 'secondary' : 'warning text-dark') }}">{{ get_phrase(ucfirst($year->curriculum->status)) }}</span>
                        @endif
                    </td>
                    <td class="text-center">{{ $year->memberships_count }}</td>
                    <td class="text-center">{{ $year->student_assignments_count }}</td>
                    <td class="text-end">
                        <div class="adminTable-action">
                            <button type="button" class="eBtn eBtn-black dropdown-toggle table-action-btn-2" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" aria-label="{{ get_phrase('Actions for') }} {{ $year->label }}">{{ get_phrase('Actions') }}</button>
                            <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action">
                                <li><a class="dropdown-item" href="{{ route('admin.programme_years.show', $year->id) }}">{{ get_phrase('View Year') }}</a></li>
                                @if(!$year->archived_at && ($year->curriculum->status === 'draft' || $isOwner) && auth()->user()->hasPermission('academic.curriculum.manage'))
                                    <li><a class="dropdown-item" href="javascript:;" data-bs-toggle="collapse" data-bs-target="#edit-year-{{ $year->id }}">{{ get_phrase('Edit Year') }}</a></li>
                                @endif
                                @if(!$year->archived_at)
                                    <li><a class="dropdown-item text-danger" href="javascript:;" data-bs-toggle="collapse" data-bs-target="#delete-year-{{ $year->id }}">{{ get_phrase('Delete or archive Programme Year') }}</a></li>
                                @endif
                            </ul>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center py-4">{{ get_phrase('No Programme Years found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $years->links() }}
    @foreach($years as $year)
        @if(!$year->archived_at && ($year->curriculum->status === 'draft' || $isOwner) && auth()->user()->hasPermission('academic.curriculum.manage'))
        <div class="collapse mt-3" id="edit-year-{{ $year->id }}">
            <div class="eSection-wrap">
                <h6>{{ get_phrase('Edit Programme Year') }} — {{ $year->label }}</h6>
                <form method="POST" action="{{ route('admin.programme_years.update', $year->id) }}" class="row g-2">@csrf @method('PUT')
                    <div class="col-md-7">
                        <label class="form-label" for="label-{{ $year->id }}">{{ get_phrase('Programme Year name') }}</label>
                        <input id="label-{{ $year->id }}" name="label" class="form-control @error('label') is-invalid @enderror" value="{{ $year->label }}" maxlength="100" required>
                        @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small class="form-text">{{ get_phrase('Use a study-year name; set Semester or Term on its Course Unit placement.') }}</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="order-{{ $year->id }}">{{ get_phrase('Order in curriculum') }}</label>
                        <input id="order-{{ $year->id }}" name="sequence" type="number" class="form-control @error('sequence') is-invalid @enderror" min="1" max="65535" value="{{ $year->sequence }}" required>
                        @error('sequence')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @if($year->curriculum->status !== 'draft')
                    <div class="col-12">
                        <label class="form-label" for="reason-{{ $year->id }}">{{ get_phrase('Reason for owner correction') }}</label>
                        <input id="reason-{{ $year->id }}" name="reason" class="form-control @error('reason') is-invalid @enderror" maxlength="500" required>
                        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <small>{{ get_phrase('Saving reopens this curriculum as Draft. Approve it again when corrections are complete.') }}</small>
                    </div>
                    @endif
                    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary">{{ get_phrase('Save') }}</button></div>
                </form>
            </div>
        </div>
        @endif
        @if(!$year->archived_at)
        @php($confirmationPhrase = $year->label . ' — ' . ($year->curriculum->programme?->name ?? 'Programme') . ' — ' . $year->curriculum->version)
        <div class="collapse mt-3" id="delete-year-{{ $year->id }}">
            <div class="eSection-wrap border border-danger-subtle">
                <p class="mb-2">{{ get_phrase('Impact for') }} <strong>{{ $year->label }}</strong> {{ get_phrase('in') }} <strong>{{ $year->curriculum->programme?->name }} — {{ $year->curriculum->version }}</strong>:</p>
                <ul class="small mb-3">
                    <li>{{ $year->deletion_impact['course_unit_placements'] }} Course Unit placements; {{ $year->deletion_impact['draft_offering_links_to_remove'] }} draft Allocation links will be removed.</li>
                    <li>{{ $year->deletion_impact['prerequisite_links'] }} prerequisite links will be removed if no historical records require archiving.</li>
                    <li>{{ $year->deletion_impact['non_draft_offering_links_to_preserve'] }} non-draft Allocation links, {{ $year->deletion_impact['course_registrations'] }} course registrations, and {{ $year->deletion_impact['student_placement_history'] }} student placement/history records will be preserved.</li>
                </ul>
                <p class="small">{{ get_phrase('If historical records reference this year, PIIE archives it and hides its placements from new selections. Otherwise, it deletes the year, its placements, prerequisite links, and draft Allocation links. Shared Course Unit definitions and student, result, finance, and other history are preserved.') }}</p>
                <form method="POST" action="{{ route('admin.programme_years.destroy', $year->id) }}">@csrf @method('DELETE')
                    <label class="form-label" for="confirm-year-{{ $year->id }}">{{ get_phrase('Type') }} <code>{{ $confirmationPhrase }}</code> {{ get_phrase('to confirm') }}</label>
                    <input id="confirm-year-{{ $year->id }}" name="confirmation" class="form-control mb-2" required autocomplete="off">
                    @if($year->curriculum->status !== 'draft')
                        <label class="form-label" for="delete-reason-{{ $year->id }}">{{ get_phrase('Reason for owner change') }}</label>
                        <input id="delete-reason-{{ $year->id }}" name="reason" class="form-control mb-2" maxlength="500" required>
                        <small class="d-block mb-2">{{ get_phrase('This reopens the curriculum as Draft. Approve it again when corrections are complete.') }}</small>
                    @endif
                    <button class="btn btn-outline-danger btn-sm">{{ get_phrase('Confirm delete/archive') }}</button>
                </form>
            </div>
        </div>
        @endif
    @endforeach
</section>
</div>
@endsection