@extends('admin.navigation')
@section('page_styles')
<link rel="stylesheet" href="{{ asset('assets/css/curriculum-workspace.css') }}">
@endsection
@section('content')
<div class="curriculum-workspace programme-years-workspace">
@php
    $isOwner = \App\Support\Curriculum\CurriculumOwnerAccess::allows(auth()->user(), (int) auth()->user()->school_id);
    $editableCurricula = $isOwner ? $curricula : $curricula->where('status', 'draft');
    // Presentation only: saved versions and deletion references stay unchanged.
    $compactText = static function ($text, $fallback, $limit = 32) {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        return $text !== '' && mb_strlen($text) <= $limit && count(explode(' ', $text)) <= 5
            ? $text : \Illuminate\Support\Str::limit($fallback, $limit);
    };
    $curriculumLabels = $curricula->mapWithKeys(static function ($curriculum) use ($compactText) {
        $programme = $curriculum->programme;
        $programmeLabel = $compactText($programme?->name, $programme?->code ?: 'Programme #'.$curriculum->programme_id);
        $versionLabel = $compactText($curriculum->version, 'Curriculum #'.$curriculum->id, 24);
        return [$curriculum->id => $programmeLabel.' / '.$versionLabel];
    });
@endphp
<div class="mainSection-title"><h4>{{ get_phrase('Programme Years') }}</h4><p class="text-muted">Define study-year levels within a Programme Curriculum, such as Year 1 or Foundation Year. Academic Years are dated calendar records; Semester or Term is a separate period on each Course Unit placement.</p></div>
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}@if(session('programme_year_next_course_units')) <a class="alert-link ms-2" href="{{ route('admin.curriculum_units.index', ['curriculum_id' => session('programme_year_next_course_units')]) }}#course-units">Next: Add Course Units</a>@endif</div>@endif
@if(session('curriculum_reopened'))<div class="alert alert-info">Your owner change reopened the curriculum as Draft. Approve it again when all corrections are complete.</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="eSection-wrap mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-9"><label class="form-label" for="filter-curriculum">Programme Curriculum</label><select id="filter-curriculum" name="curriculum_id" class="form-select"><option value="">All curricula</option>@foreach($curricula as $curriculum)<option value="{{ $curriculum->id }}" @selected($selected?->id === $curriculum->id)>{{ $curriculumLabels[$curriculum->id] }} ({{ ucfirst($curriculum->status) }})</option>@endforeach</select></div>
        <div class="col-md-3"><button class="btn btn-primary">Filter</button></div>
    </form>
</section>
@if(auth()->user()->hasPermission('academic.curriculum.manage'))
<section class="eSection-wrap mb-3"><h5>Create Programme Year</h5>
    @if($editableCurricula->isEmpty())<p class="text-muted mb-0">Create a draft in the Programme Curriculum module before adding its years.</p>@else
    <form method="POST" action="{{ route('admin.programme_years.store') }}" class="row g-3">@csrf
        <div class="col-lg-6"><label class="form-label" for="new-curriculum">Programme Curriculum</label><select id="new-curriculum" name="curriculum_id" class="form-select" required><option value="">Choose curriculum</option>@foreach($editableCurricula as $curriculum)<option value="{{ $curriculum->id }}" @selected(old('curriculum_id', $selected?->id) == $curriculum->id)>{{ $curriculumLabels[$curriculum->id] }} ({{ ucfirst($curriculum->status) }})</option>@endforeach</select></div>
        <div class="col-lg-3"><label class="form-label" for="new-label">Programme Year name</label><input id="new-label" name="label" class="form-control @error('label') is-invalid @enderror" value="{{ old('label') }}" maxlength="100" required placeholder="Year 1" aria-describedby="new-label-help">@error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror<small id="new-label-help" class="form-text">Examples: Year 1, Year 2, Foundation Year. Set Semester or Term on Course Unit placement instead.</small></div>
        <div class="col-lg-3"><label class="form-label" for="new-sequence">Order in curriculum</label><input id="new-sequence" name="sequence" type="number" class="form-control @error('sequence') is-invalid @enderror" min="1" max="65535" value="{{ old('sequence', 1) }}" required>@error('sequence')<div class="invalid-feedback">{{ $message }}</div>@enderror<small class="form-text">Each position can be used once in this curriculum.</small></div>
        @if($isOwner)<div class="col-12"><label for="create-reason" class="form-label">Reason for owner change (required for approved or retired curricula)</label><input id="create-reason" name="reason" class="form-control @error('reason') is-invalid @enderror" maxlength="500" value="{{ old('reason') }}">@error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror<small>Changing an approved or retired curriculum reopens it as Draft. Approve it again when corrections are complete.</small></div>@endif
        <div class="col-12"><button class="btn btn-primary">Create Year</button></div>
    </form>@endif
</section>@endif
<section class="eSection-wrap"><h5 id="programme-years-heading">Programme Years</h5>
    <div class="programme-years-list overflow-auto" role="region" aria-labelledby="programme-years-heading" tabindex="0">
    <div class="d-inline-flex flex-column" style="min-width: 100%;">
    @forelse($years as $year)
    @php($containsPeriodLabel = preg_match('/\b(?:semester|term)\s*(?:no\.?\s*)?[1-9][0-9]*\b/i', $year->label) === 1)
    @php($canManageYear = !$year->archived_at && (($year->curriculum->status ?? '') === 'draft' || $isOwner) && auth()->user()->hasPermission('academic.curriculum.manage'))
    <article class="programme-year-record mb-2" aria-labelledby="year-title-{{ $year->id }}">
        <div class="list-group-item d-flex flex-nowrap align-items-center justify-content-between gap-4 py-2 px-3 border rounded bg-white text-nowrap">
            <div class="d-flex flex-nowrap align-items-center gap-3 flex-shrink-0">
                <strong id="year-title-{{ $year->id }}" class="text-dark" title="{{ $year->label }}" aria-label="{{ $year->label }}">{{ $year->sequence }}. {{ $compactText($year->label, 'Year '.$year->sequence) }}</strong>
                <span class="text-muted small" aria-hidden="true">|</span>
                <span class="text-secondary small font-monospace bg-light px-2 py-1 rounded" title="{{ ($year->curriculum->programme?->name ?? 'Programme').' / ' . ($year->curriculum->version ?? '') }}" aria-label="{{ ($year->curriculum->programme?->name ?? 'Programme').' / ' . ($year->curriculum->version ?? '') }}">{{ \Illuminate\Support\Str::limit($curriculumLabels[$year->curriculum_id], 36) }}</span>
                <span class="badge {{ $year->archived_at ? 'bg-secondary' : (($year->curriculum->status ?? '') === 'approved' ? 'bg-success' : 'bg-warning text-dark') }}" tabindex="0" title="{{ $year->archived_at ? 'Retained for history; unavailable for new placements.' : ($canManageYear ? 'This Programme Year can be managed.' : 'Read-only; use a successor curriculum for structural changes.') }}">{{ $year->archived_at ? 'Archived' : ucfirst($year->curriculum->status ?? '') }}</span>
            </div>
            <div class="d-flex flex-nowrap align-items-center gap-4 flex-shrink-0">
                <div class="text-end text-muted small me-2">
                    <span class="me-3">{{ $year->memberships_count }} Course Unit placement(s)</span>
                    <span>{{ $year->student_assignments_count }} student placement/history record(s)</span>
                </div>
                <div class="d-flex flex-nowrap align-items-center gap-2">
                    <a class="btn btn-outline-secondary btn-sm py-1 px-2 text-nowrap" href="{{ route('admin.programme_years.show', $year->id) }}" aria-label="View {{ $year->label }}">View Year</a>
                    @if($canManageYear)
                    <button type="button" class="btn btn-outline-primary btn-sm py-1 px-2 text-nowrap" data-bs-toggle="collapse" data-bs-target="#edit-year-{{ $year->id }}" aria-controls="edit-year-{{ $year->id }}" aria-expanded="false" aria-label="Edit Year: {{ $year->label }}">Edit</button>
                    <button type="button" class="btn btn-outline-danger btn-sm py-1 px-2 text-nowrap" data-bs-toggle="collapse" data-bs-target="#delete-year-{{ $year->id }}" aria-controls="delete-year-{{ $year->id }}" aria-expanded="false" aria-label="Delete or archive Programme Year: {{ $year->label }}">Archive</button>
                    @endif
                @if($containsPeriodLabel)<span class="badge bg-warning text-dark" tabindex="0" title="Review this saved label: it includes a Semester or Term. Programme Year is the study-year level; the period belongs to Course Unit placement." aria-label="Review this saved label">!<span class="visually-hidden"> Review this saved label: it includes a Semester or Term. Programme Year is the study-year level; the period belongs to Course Unit placement.</span></span>@endif
                </div>
            </div>
        </div>
        @if($canManageYear)
        <div class="collapse" id="edit-year-{{ $year->id }}"><h6 class="mt-3">Edit Year</h6><form method="POST" action="{{ route('admin.programme_years.update', $year->id) }}" class="row g-2 mt-1">@csrf @method('PUT')<div class="col-md-7"><label class="form-label" for="label-{{ $year->id }}">Programme Year name</label><input id="label-{{ $year->id }}" name="label" class="form-control @error('label') is-invalid @enderror" value="{{ $year->label }}" maxlength="100" required>@error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror<small class="form-text">Use a study-year name; set Semester or Term on its Course Unit placement.</small></div><div class="col-md-3"><label class="form-label" for="order-{{ $year->id }}">Order in curriculum</label><input id="order-{{ $year->id }}" name="sequence" type="number" class="form-control @error('sequence') is-invalid @enderror" min="1" max="65535" value="{{ $year->sequence }}" required>@error('sequence')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        @if($year->curriculum->status !== 'draft')<div class="col-12"><label class="form-label" for="reason-{{ $year->id }}">Reason for owner correction</label><input id="reason-{{ $year->id }}" name="reason" class="form-control @error('reason') is-invalid @enderror" maxlength="500" required>@error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror<small>Saving reopens this curriculum as Draft. Approve it again when corrections are complete.</small></div>@endif
        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary">Save</button></div></form></div>
        @if(!$year->archived_at)
        @php($confirmationPhrase = $year->label . ' — ' . ($year->curriculum->programme?->name ?? 'Programme') . ' — ' . $year->curriculum->version)
        <div class="collapse" id="delete-year-{{ $year->id }}"><h6 class="text-danger mt-3">Delete or archive Programme Year</h6>
        <div class="border border-danger-subtle rounded p-3 mt-2"><p class="mb-2">Impact for <strong>{{ $year->label }}</strong> in <strong>{{ $year->curriculum->programme?->name }} — {{ $year->curriculum->version }}</strong>:</p>
            <ul class="small mb-3"><li>{{ $year->deletion_impact['course_unit_placements'] }} Course Unit placements; {{ $year->deletion_impact['draft_offering_links_to_remove'] }} draft Allocation links will be removed.</li><li>{{ $year->deletion_impact['prerequisite_links'] }} prerequisite links will be removed if no historical records require archiving.</li><li>{{ $year->deletion_impact['non_draft_offering_links_to_preserve'] }} non-draft Allocation links, {{ $year->deletion_impact['course_registrations'] }} course registrations, and {{ $year->deletion_impact['student_placement_history'] }} student placement/history records will be preserved.</li></ul>
            <p class="small">If historical records reference this year, PIIE archives it and hides its placements from new selections. Otherwise, it deletes the year, its placements, prerequisite links, and draft Allocation links. Shared Course Unit definitions and student, result, finance, and other history are preserved.</p>
            <form method="POST" action="{{ route('admin.programme_years.destroy', $year->id) }}">@csrf @method('DELETE')
            <label class="form-label" for="confirm-year-{{ $year->id }}">Type <code>{{ $confirmationPhrase }}</code> to confirm</label><input id="confirm-year-{{ $year->id }}" name="confirmation" class="form-control mb-2" required autocomplete="off">
            @if($year->curriculum->status !== 'draft')<label class="form-label" for="delete-reason-{{ $year->id }}">Reason for owner change</label><input id="delete-reason-{{ $year->id }}" name="reason" class="form-control mb-2" maxlength="500" required><small class="d-block mb-2">This reopens the curriculum as Draft. Approve it again when corrections are complete.</small>@endif
            <button class="btn btn-outline-danger btn-sm">Confirm delete/archive</button></form></div></div>
        @endif
        @elseif(!$year->archived_at)<p class="visually-hidden">Approved and retired curriculum years are read-only. Use a successor curriculum for structural changes.</p>@else<p class="visually-hidden">Archived years and their placements are retained for historical records and cannot be selected for new placements.</p>@endif
    </article>
    @empty<p class="text-muted">No Programme Years found.</p>@endforelse
    </div>
    </div>
    {{ $years->links() }}
</section>
</div>
@endsection
