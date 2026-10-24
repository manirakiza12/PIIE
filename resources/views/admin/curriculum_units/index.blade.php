@extends('admin.navigation')
@section('content')
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
@if(!$isDraft && !auth()->user()->hasPermission('academic.curriculum.manage'))<div class="alert alert-info">This curriculum is read-only for your account.</div>@endif
@include('admin.curriculum_units.placements')
@endisset
@endsection
