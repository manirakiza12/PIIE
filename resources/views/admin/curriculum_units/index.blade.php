@extends('admin.navigation')
@section('page_styles')
<link rel="stylesheet" href="{{ asset('assets/css/curriculum-workspace.css') }}">
<style>
    .course-units-compact .table.eTable { margin-bottom: 0; }
    .course-units-compact .table.eTable thead th { padding: 10px 12px; font-size: 13px; white-space: nowrap; }
    .course-units-compact .table.eTable tbody td { padding: 8px 12px; vertical-align: middle; font-size: 14px; }
    .course-units-compact .table.eTable tbody tr { height: auto; }
    .course-units-compact .adminTable-action { margin: 0; }
    .course-units-compact .adminTable-action .table-action-btn-2 { padding: 4px 12px; font-size: 13px; }
    .course-units-filter .form-label { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
    .course-units-filter .form-control, .course-units-filter .form-select { height: 38px; font-size: 14px; }
    .curriculum-placements-compact .membership-row { padding: 8px 12px; }
    .curriculum-placements-compact .membership-row .adminTable-action { margin: 0; }
</style>
@endsection
@section('content')
<div class="curriculum-workspace course-units-compact curriculum-placements-compact">
<div class="mainSection-title"><h4>Course Units</h4><p class="text-muted">Manage course-unit placements, credits and prerequisites for a Programme Curriculum. Catalogue editing requires separate permission.</p></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="eSection-wrap mb-3"><form method="GET" class="row g-2 align-items-end"><div class="col-md-9"><label class="form-label" for="unit-curriculum">Programme Curriculum</label><select id="unit-curriculum" name="curriculum_id" class="form-select" required><option value="">Choose curriculum</option>@foreach($curricula as $plan)<option value="{{ $plan->id }}" @selected(request('curriculum_id') == $plan->id)>{{ $plan->programme?->name }} — {{ $plan->version }} ({{ ucfirst($plan->status) }})</option>@endforeach</select></div><div class="col-md-3"><button class="btn btn-primary">View Course Units</button></div></form></section>
@isset($curriculum)
@php
    $isDraft = $curriculum->status === 'draft';
    $courseUnitLabel = 'Course Unit';
    $periodLabel = $calendarPattern === 'semester' ? 'Semester' : 'Term';
@endphp
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}@if(session('curriculum_next_step') === 'placement') <a class="alert-link ms-2" href="#subject-search-form">Add Another Course Unit</a><span class="mx-1">·</span><a class="alert-link" href="{{ route('admin.curricula.show', $curriculum->id) }}#review">Review Curriculum</a>@endif</div>@endif
@if(!$isDraft && !auth()->user()->hasPermission('academic.curriculum.manage'))<div class="alert alert-info">This curriculum is read-only for your account.</div>@endif
@include('admin.curriculum_units.placements')
@endisset
</div>
@endsection
