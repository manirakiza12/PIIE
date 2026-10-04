@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    /**
     * The marking workspace.
     *
     * BUILT FROM CONFIRMED REGISTRATIONS, NOT FROM SUBMISSIONS.
     *
     * A list assembled from submissions quietly omits exactly the students a
     * lecturer most needs to chase, so a student who has not submitted appears
     * here as "Not submitted" rather than not appearing at all.
     *
     * "Graded (not yet released)" is a distinct state from "Returned" on
     * purpose. A lecturer may mark a batch of work over a week and release it on
     * an announced day, and the list must not imply a student has been told
     * something they have not.
     */
    $unitName = $offering->subject?->name ?? $offering->reference;
@endphp

@include('teacher.course_offerings.assignments._styles')

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.assignments.show', [$offering->id, $assignment->id]) }}"
       class="btn btn-sm btn-outline-secondary mb-2">Back to the assignment</a>
    <h4 class="mb-1">Marking &mdash; {{ $assignment->title }}</h4>
    <p class="text-muted mb-0">{{ $unitName }} &middot; out of {{ $assignment->max_marks }} marks</p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

@if($markingList->isEmpty())
    <div class="border rounded p-4 text-center" role="status">
        <h6>Nobody is registered for this Course Offering yet.</h6>
        <p class="text-muted mb-0">A marking list is built from confirmed course registrations, so there is nobody to mark yet.</p>
    </div>
@else
    @php
        $order = ['Missing' => 0, 'Not submitted' => 1, 'Late' => 2, 'Submitted' => 3,
                  'Resubmitted' => 3, 'Graded (not yet released)' => 4, 'Returned' => 5];
        $sorted = $markingList->sortBy(fn ($row) => $order[$row['state']] ?? 9)->values();
    @endphp

    <div class="as-table-scroll">
        <table class="table align-middle" data-testid="as-marking-list">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>State</th>
                    <th>Submitted</th>
                    <th class="text-center">Attempts</th>
                    <th class="text-center">Mark</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            @foreach($sorted as $row)
                @php
                    $chip = Str::lower(str_replace([' ', '(', ')'], ['-', '', ''], $row['state']));
                    $submission = $row['submission'];
                @endphp
                <tr data-testid="as-marking-row" data-state="{{ $row['state'] }}">
                    <td>
                        <span class="fw-semibold">{{ $row['student']?->name ?? 'Unknown student' }}</span>
                        @if($row['student'])
                            <div class="small text-muted">{{ $row['student']->email }}</div>
                        @endif
                    </td>
                    <td data-testid="as-marking-state">
                        <span class="as-chip as-chip-{{ $chip }}">
                            <span class="as-dot as-dot-{{ $chip }}" aria-hidden="true"></span>{{ $row['state'] }}
                        </span>
                        @if($submission?->isLate())
                            <div class="small text-muted">after the deadline</div>
                        @endif
                    </td>
                    <td class="small">
                        @if($submission)
                            {{ $display->submittedAt($assignment, auth()->user(), $submission->submitted_at) }}
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-center">
                        {{ $row['attempt_count'] }}{{ $row['attempt_count'] > 1 ? ' of '.$assignment->attemptsAllowed() : '' }}
                    </td>
                    <td class="text-center">
                        @if($submission?->isGraded())
                            <span class="fw-semibold">{{ rtrim(rtrim(number_format((float) $submission->marks_awarded, 2), '0'), '.') }}</span>
                            <span class="text-muted small">/ {{ $assignment->max_marks }}</span>
                            @if(! $submission->isReleased())
                                <div class="small text-muted">not returned</div>
                            @endif
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-end">
                        @if($submission)
                            <a class="btn btn-sm btn-outline-primary"
                               href="{{ route('teacher.course_offerings.assignments.submissions.show', [$offering->id, $assignment->id, $submission->id]) }}">
                                {{ $submission->isGraded() ? 'Review' : 'Mark' }}
                            </a>
                        @else
                            <span class="small text-muted">Nothing to mark</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <p class="small text-muted mt-2">
        Recording a mark does not show it to the student. Use <strong>Return feedback</strong> on a submission
        when you are ready for them to see it — and the mark is always bounded by the {{ $assignment->max_marks }}
        marks available.
    </p>
@endif

{{-- Closes the content section opened at the top of this file.
     Without it the output buffer stays open for the rest of the request,
     which a single page load hides and a test suite reports. --}}
@endsection
