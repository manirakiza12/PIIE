@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    $courseUnitLabel = $terms['course_unit'] ?? 'Course Unit';
@endphp

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.attendance.index', $offering->id) }}" class="btn btn-sm btn-outline-secondary mb-2">Back to Attendance</a>
    <h4 class="mb-1">Create Attendance Session</h4>
    <p class="text-muted mb-0">{{ $offering->subject?->code }} {{ $offering->subject?->name }} &middot; {{ $offering->academicYear?->label }} &middot; {{ $offering->academicPeriod?->label }}</p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><strong>This Attendance Session was not created.</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="eSection-wrap">
    <p class="text-muted">A session records one teaching occurrence for this {{ $courseUnitLabel }}. The date must fall within the Course Offering's Academic Period.</p>
    <form method="POST" action="{{ route('teacher.course_offerings.attendance.store', $offering->id) }}" class="row g-3" data-single-submit>
        @csrf
        <div class="col-12 col-md-4">
            <label class="form-label" for="session_date">Date <span class="text-danger">*</span></label>
            <input type="date" id="session_date" name="session_date" class="form-control" value="{{ old('session_date') }}" required>
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label" for="type">Session Type <span class="text-danger">*</span></label>
            <select id="type" name="type" class="form-select" required>
                @foreach($types as $value => $label)<option value="{{ $value }}" @selected(old('type', $value) === $value)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label" for="topic">Topic</label>
            <input type="text" id="topic" name="topic" class="form-control" maxlength="255" value="{{ old('topic') }}" placeholder="Introduction to Information Systems">
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label" for="starts_at">Start Time</label>
            <input type="time" id="starts_at" name="starts_at" class="form-control" value="{{ old('starts_at') }}">
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label" for="ends_at">End Time</label>
            <input type="time" id="ends_at" name="ends_at" class="form-control" value="{{ old('ends_at') }}">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label" for="live_class_id">Related Live Class <span class="text-muted">(optional)</span></label>
            <input type="number" id="live_class_id" name="live_class_id" class="form-control" min="1" value="{{ old('live_class_id') }}">
            <small class="form-text">A Live Class may be linked for reference. Joining it never marks attendance on its own.</small>
        </div>
        <div class="col-12">
            <button class="btn btn-primary">Create Attendance Session</button>
            <a class="btn btn-outline-secondary" href="{{ route('teacher.course_offerings.attendance.index', $offering->id) }}">Cancel</a>
        </div>
    </form>
</section>
@endsection
