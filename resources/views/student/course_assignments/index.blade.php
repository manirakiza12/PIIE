@extends('student.navigation')
@section('content')
@php
    /**
     * The student's assignment list for one course.
     *
     * Each row carries a FACTUAL state, not a colour: Upcoming, Open, In progress,
     * Submitted, Returned, or Overdue/Missing where that is actually true.
     *
     * Two distinctions this page is careful about:
     *
     *   - "In progress — not yet submitted" is NOT the same as "Submitted".
     *     Work a student has prepared but not handed in has not been submitted,
     *     and saying otherwise would be a claim about their work that is untrue.
     *   - "Overdue — work prepared but not submitted" is not "Missing". A
     *     student who wrote something and missed the deadline deserves to be
     *     told the difference.
     */
@endphp

@include('teacher.course_offerings.assignments._styles')

<div class="mainSection-title">
    <a href="{{ route('student.my_courses') }}" class="btn btn-sm btn-outline-secondary mb-2">Back to My Courses</a>
    <h4 class="mb-1">Assignments &mdash; {{ $unitName }}</h4>
    <p class="text-muted mb-0">
        {{ $offering->academicYear?->label ?? '—' }} &middot; {{ $offering->academicPeriod?->label ?? '—' }}
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif

@if($rows->isEmpty())
    <div class="border rounded p-4 text-center" role="status">
        <h6>No assignments have been set yet.</h6>
        <p class="text-muted mb-0">
            Your lecturer has not released any assignments for this course. You do not need to do anything
            &mdash; they will appear here when they are set.
        </p>
    </div>
@else
    <p class="small text-muted">
        Opening an assignment does NOT submit it. You hand work in deliberately, and each attempt you use is
        recorded.
    </p>

    @foreach($rows as $row)
        @php
            $assignment = $row['assignment'];
            $state = $row['state'];
            $chip = Str::lower(str_replace([' ', '(', ')', '—'], ['-', '', '', ''], $state));
            $show = $display->for($assignment, auth()->user());
        @endphp
        <article class="border rounded p-3 mb-3" data-testid="as-student-row" data-state="{{ $state }}">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div style="min-width:0">
                    <h6 class="mb-1">
                        <a href="{{ route('student.courses.assignments.show', [$offering->id, $assignment->id]) }}">
                            {{ $assignment->title }}
                        </a>
                    </h6>
                    <p class="small text-muted mb-0">
                        <span class="as-chip as-chip-{{ $chip }}" data-testid="as-student-state">
                            <span class="as-dot as-dot-{{ $chip }}" aria-hidden="true"></span>{{ $state }}
                        </span>
                    </p>
                </div>
                <div class="text-sm text-end">
                    <div class="small text-muted">Due</div>
                    <div class="small fw-semibold">{{ $show->due($assignment, auth()->user()) }}</div>
                </div>
            </div>

            <p class="small text-muted mt-2 mb-2">
                {{ $assignment->max_marks }} marks
                &middot; {{ \App\Models\Assignment::SUBMISSION_TYPE_LABELS[$assignment->submission_type] ?? $assignment->submission_type }}
                &middot;
                @if($assignment->attemptsAllowed() === 1)
                    one attempt
                @else
                    {{ $row['attempts_used'] }} of {{ $row['attempts_allowed'] }} attempts used
                @endif
                @if($assignment->closes_at)
                    &middot; final closing {{ $show->closes($assignment, auth()->user()) }}
                @endif
            </p>

            @if($row['hasDraft'])
                <p class="small mb-2 p-2 rounded" style="background:#fff3cd" data-testid="as-draft-notice">
                    <strong>You have prepared work that is not submitted.</strong>
                    Only you can see it, and it does not count as a hand-in.
                </p>
            @endif

            <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-sm btn-outline-primary"
                   href="{{ route('student.courses.assignments.show', [$offering->id, $assignment->id]) }}">
                    {{ $row['submission'] ? 'Open' : 'Start' }}
                </a>
                @if($row['submission'])
                    <a class="btn btn-sm btn-outline-secondary"
                       href="{{ route('student.courses.assignments.submissions.show', [$offering->id, $assignment->id, $row['submission']->id]) }}">
                        My attempt {{ $row['submission']->attempt_no }}
                    </a>
                @endif
            </div>
        </article>
    @endforeach
@endif

{{-- Closes the content section opened at the top of this file.
     Without it the output buffer stays open for the rest of the request,
     which a single page load hides and a test suite reports. --}}
@endsection
