@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    $courseUnitLabel = $terms['course_unit'] ?? 'Course Unit';
    $programmeLabel = $terms['programme'] ?? 'Programme';
    $statusLabels = ['registered' => 'Registered', 'confirmed' => 'Confirmed', 'dropped' => 'Dropped'];
    $statusBadges = ['registered' => 'warning text-dark', 'confirmed' => 'success', 'dropped' => 'secondary'];
@endphp

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.show', $offering->id) }}" class="btn btn-sm btn-outline-secondary mb-2">Back to Course Offering</a>
    <h4 class="mb-1">Students &mdash; {{ $offering->subject?->code }} {{ $offering->subject?->name }}</h4>
    <p class="text-muted mb-0">{{ $offering->academicYear?->label }} &middot; {{ $offering->academicPeriod?->label }}</p>
</div>

@if($withheld)
    <section class="eSection-wrap">
        <div class="border rounded p-4 text-center" role="status">
            <h6>No confirmed students are currently registered for this Course Offering.</h6>
            <p class="text-muted mb-0">{{ $withheld }}</p>
        </div>
    </section>
@elseif($roster->isEmpty())
    <section class="eSection-wrap">
        <div class="border rounded p-4 text-center" role="status">
            <h6>No confirmed students are currently registered for this Course Offering.</h6>
            <p class="text-muted mb-0">Students appear here once the academic office has confirmed their registration.</p>
        </div>
    </section>
@else
    <section class="eSection-wrap">
        <p class="text-muted small">Confirmed registrations for this {{ $courseUnitLabel }} only. This roster is read-only: registration, confirmation and academic placement are handled by the academic office.</p>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th scope="col">Student Number</th>
                        <th scope="col">Student Name</th>
                        <th scope="col">{{ $programmeLabel }}</th>
                        <th scope="col">Year of Study</th>
                        <th scope="col">Registration Status</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($roster as $registration)
                    <tr>
                        <td>{{ $registration->registration_number ?: '—' }}</td>
                        <td>{{ $registration->student_name }}</td>
                        <td>{{ $registration->programme_code ?: $registration->programme_name ?: '—' }}</td>
                        <td>{{ $registration->year_of_study ?: '—' }}</td>
                        <td><span class="badge bg-{{ $statusBadges[$registration->status] ?? 'secondary' }}">{{ $statusLabels[$registration->status] ?? ucfirst($registration->status) }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection
