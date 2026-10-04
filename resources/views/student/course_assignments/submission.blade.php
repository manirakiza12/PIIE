@extends('student.navigation')
@section('content')
@php
    /**
     * One of the student's own attempts.
     *
     * SCOPED TO THE OWNER AT THE ROUTE, NOT HERE.
     * `GradingService::resolveSubmissionForStudent()` matches on the
     * authenticated student's id inside the resolved assignment, so another
     * student's submission is a 404 and cannot be read by substituting an id.
     * Nothing on this page decides that; by the time it renders, the row is
     * already known to be this student's.
     *
     * A RESULT IS SHOWN ONLY WHEN IT HAS BEEN RETURNED.
     *
     * A mark the lecturer has recorded but not yet returned is invisible, because
     * the release is the deliberate act that publishes it. This is the single
     * place that gate is applied to display.
     */
    $chip = $submission->isReleased()
        ? ($submission->isLate() ? 'returned' : 'returned')
        : ($submission->isLate() ? 'late' : 'progress');
@endphp

@include('teacher.course_offerings.assignments._styles')

<div class="mainSection-title">
    <a href="{{ route('student.courses.assignments.show', [$offering->id, $assignment->id]) }}"
       class="btn btn-sm btn-outline-secondary mb-2">Back to the assignment</a>
    <p class="small text-muted mb-1">{{ $unitName }} &middot; attempt {{ $submission->attempt_no }}</p>
    <h4 class="mb-1">{{ $assignment->title }}</h4>
    <p class="text-muted mb-0">
        <span class="as-chip as-chip-{{ $chip }}" data-testid="as-attempt-state">
            <span class="as-dot as-dot-{{ $chip }}" aria-hidden="true"></span>
            {{ $submission->isReleased() ? 'Returned' : ($submission->isLate() ? 'Submitted (late)' : 'Submitted') }}
        </span>
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif

<div class="row g-4">
    <div class="col-12 col-lg-7">
        <article class="border rounded p-3" data-testid="as-my-work">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <h6 class="mb-0">What you handed in</h6>
                <span class="small text-muted">
                    {{ $display->submittedAt($assignment, auth()->user(), $submission->submitted_at) }}
                </span>
            </div>

            @if($submission->hasFile())
                <div class="border rounded p-3 mb-3 d-flex justify-content-between align-items-center gap-2">
                    <div style="min-width:0">
                        <div class="fw-semibold text-truncate">{{ $submission->displayName() }}</div>
                        <div class="small text-muted">
                            Your uploaded file
                            @if($submission->sizeLabel()) &middot; {{ $submission->sizeLabel() }} @endif
                        </div>
                    </div>
                    <a class="btn btn-sm btn-outline-primary flex-shrink-0"
                       href="{{ route('student.courses.assignments.submissions.file', [$offering->id, $assignment->id, $submission->id]) }}">
                        Open
                    </a>
                </div>
            @endif

            @if($submission->writtenResponse() !== null)
                <div class="as-surface border rounded p-3" data-testid="as-my-text">
                    {!! nl2br(e($submission->writtenResponse())) !!}
                </div>
            @endif

            @if(! $submission->hasFile() && ! $submission->writtenResponse() !== null)
                <p class="text-muted mb-0">This attempt recorded no work.</p>
            @endif
        </article>
    </div>

    <div class="col-12 col-lg-5">
        @if($submission->isReleased())
            <div class="as-result border rounded p-3" data-testid="as-my-result">
                <h6 class="mb-2">Your result</h6>
                <div class="as-mark" data-testid="as-my-mark">
                    {{ rtrim(rtrim(number_format((float) $submission->marks_awarded, 2), '0'), '.') }}
                    <span class="fs-6 fw-normal text-muted">/ {{ $assignment->max_marks }}</span>
                    @if($submission->percentOfMaximum() !== null)
                        <span class="fs-6 fw-normal text-muted">({{ $submission->percentOfMaximum() }}%)</span>
                    @endif
                </div>
                @if($submission->feedback)
                    <hr>
                    <h6 class="small">Feedback from your lecturer</h6>
                    <p class="mb-0" data-testid="as-my-feedback">{{ $submission->feedback }}</p>
                @else
                    <p class="small text-muted mb-0">No written feedback was left on this attempt.</p>
                @endif
            </div>
        @else
            <div class="border rounded p-3" data-testid="as-my-awaiting">
                <h6 class="mb-2">Not marked yet</h6>
                <p class="small text-muted mb-0">
                    Your work has been received and your lecturer has not returned a result yet. You will get
                    a notification when they do, and it will appear here.
                </p>
            </div>
        @endif

        <div class="border rounded p-3 mt-3">
            <h6 class="mb-2">What happens next</h6>
            <ul class="small text-muted mb-0">
                <li>Your lecturer marks the work and may leave written feedback.</li>
                <li>Nothing is shown here until they return it, so nothing you see is a half-decided result.</li>
                <li>If the assignment allows more than one attempt, you can submit again and both attempts are kept.</li>
            </ul>
        </div>
    </div>
</div>

{{-- Closes the content section opened at the top of this file.
     Without it the output buffer stays open for the rest of the request,
     which a single page load hides and a test suite reports. --}}
@endsection
