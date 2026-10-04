@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    $courseUnitLabel = $terms['course_unit'] ?? 'Course Unit';
    $periodLabel = $terms['academic_period'] ?? 'Academic Period';
@endphp

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.show', $offering->id) }}" class="btn btn-sm btn-outline-secondary mb-2">Back to Course Offering</a>
    <h4 class="mb-1">Attendance &mdash; {{ $offering->subject?->code }} {{ $offering->subject?->name }}</h4>
    <p class="text-muted mb-0">{{ $offering->academicYear?->label }} &middot; {{ $offering->academicPeriod?->label }} &middot; {{ $offering->my_role_label }}</p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@if(!$canTeach)
    <div class="alert alert-warning" role="status">
        <strong>Attendance is read-only.</strong>
        @if($offering->status === 'completed')
            This Course Offering is completed; its attendance history remains available for reference.
        @else
            Recording attendance requires a current teaching allocation while the Course Offering is in progress.
        @endif
    </div>
@endif

<section class="eSection-wrap mb-3" aria-label="Course Offering summary">
    <div class="row g-3 align-items-stretch">
        <div class="col-12 col-lg-7">
            <dl class="row mb-0">
                <dt class="col-5 col-sm-4">{{ $courseUnitLabel }}</dt>
                <dd class="col-7 col-sm-8">{{ $offering->subject?->code ? $offering->subject->code.' — ' : '' }}{{ $offering->subject?->name }}</dd>
                <dt class="col-5 col-sm-4">Academic Year</dt>
                <dd class="col-7 col-sm-8">{{ $offering->academicYear?->label }}</dd>
                <dt class="col-5 col-sm-4">{{ $periodLabel }}</dt>
                <dd class="col-7 col-sm-8">{{ $offering->academicPeriod?->label }}</dd>
                <dt class="col-5 col-sm-4">Stage / Year of Study</dt>
                <dd class="col-7 col-sm-8">{{ $offering->my_stages->isNotEmpty() ? $offering->my_stages->implode(', ') : 'Not recorded' }}</dd>
                <dt class="col-5 col-sm-4">Offering Status</dt>
                <dd class="col-7 col-sm-8">{{ ucfirst(str_replace('_', ' ', $offering->status)) }}</dd>
            </dl>
        </div>
        <div class="col-6 col-lg-2">
            <h6>Sessions Held</h6>
            <div class="fs-3">{{ $sessions->count() }}</div>
        </div>
        <div class="col-6 col-lg-3 d-flex align-items-end">
            @if($canTeach)
                <a class="btn btn-primary" href="{{ route('teacher.course_offerings.attendance.create', $offering->id) }}">Create Attendance Session</a>
            @else
                <span class="small text-muted">No new sessions can be created.</span>
            @endif
        </div>
    </div>
</section>

<section class="eSection-wrap mb-3" aria-label="Attendance sessions">
    <h5 class="mb-3">Attendance Sessions</h5>
    @if($sessions->isEmpty())
        <div class="border rounded p-4 text-center" role="status">
            <h6>No Attendance Sessions have been created yet.</h6>
            <p class="text-muted mb-0">Create a session to record the teaching that took place for this {{ $courseUnitLabel }}.</p>
        </div>
    @else
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">Time</th>
                        <th scope="col">Type</th>
                        <th scope="col">Topic</th>
                        <th scope="col">Status</th>
                        <th scope="col">Marked / Total</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($sessions as $session)
                    <tr>
                        <td class="text-nowrap">{{ $session->session_date?->format('Y-m-d') }}</td>
                        <td class="text-nowrap">{{ $session->timeRange() ?? 'All day' }}</td>
                        <td>{{ $session->typeLabel() }}</td>
                        <td>{{ $session->topic ?: '—' }}</td>
                        <td><span class="badge bg-{{ $session->status === 'draft' ? 'warning text-dark' : ($session->status === 'finalised' ? 'success' : 'secondary') }}">{{ $session->statusLabel() }}</span></td>
                        <td>{{ $session->marked_count }} / {{ $offering->my_confirmed_students }} &middot; {{ $session->attended_count }} attended</td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('teacher.course_offerings.attendance.show', [$offering->id, $session->id]) }}">View</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

@if($history->isNotEmpty())
<section class="eSection-wrap" aria-label="Attendance history by student">
    <h5 class="mb-3">Attendance History</h5>
    <p class="text-muted small">Factual counts only. No attendance threshold or eligibility rule is applied.</p>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th scope="col">Student</th>
                    <th scope="col">Sessions</th>
                    <th scope="col">Present</th>
                    <th scope="col">Late</th>
                    <th scope="col">Absent</th>
                    <th scope="col">Excused</th>
                    <th scope="col">Attendance</th>
                </tr>
            </thead>
            <tbody>
            @foreach($history as $row)
                <tr>
                    <td>{{ $row->student_name }}{{ $row->student_number ? ' ('.$row->student_number.')' : '' }}</td>
                    <td>{{ $row->total }}</td>
                    <td>{{ $row->present_count }}</td>
                    <td>{{ $row->late_count }}</td>
                    <td>{{ $row->absent_count }}</td>
                    <td>{{ $row->excused_count }}</td>
                    <td>{{ $row->total > 0 ? round(((int) $row->present_count + (int) $row->late_count) / $row->total * 100).'%' : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</section>
@endif
@endsection
