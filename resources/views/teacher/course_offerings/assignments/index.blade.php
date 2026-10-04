@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    /**
     * The lecturer's Course Offering assignment list.
     *
     * Every column answers a question a lecturer actually asks: what state is
     * this in, when is it due, out of how many marks, how many have handed in,
     * and how many of those have I marked. The counts come from ONE eager-loaded
     * set summarised per row, so the list total and any single assignment's
     * count cannot disagree.
     *
     * States are words as well as colours - "Submitted" and "Late" must not look
     * alike in a list used to chase missing work.
     */
    $unitName = $offering->subject?->name ?? $offering->reference;
@endphp

@include('teacher.course_offerings.assignments._styles')

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.show', $offering->id) }}" class="btn btn-sm btn-outline-secondary mb-2">
        Back to {{ $offering->subject?->code ?? 'Course Offering' }}
    </a>
    <h4 class="mb-1">Assignments &mdash; {{ $unitName }}</h4>
    <p class="text-muted mb-0">
        Set work, choose when it opens and closes, and mark what comes back.
        <span class="d-block small mt-1">
            {{ $offering->academicYear?->label ?? '—' }} &middot; {{ $offering->academicPeriod?->label ?? '—' }}
            &middot; {{ $confirmedStudents }} confirmed {{ Str::plural('student', $confirmedStudents) }}
        </span>
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <strong>Please fix the following:</strong>
        <ul class="mb-0 mt-1">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

@if(!$canManage)
    <div class="alert alert-warning" role="status">
        <strong>You are viewing this read-only.</strong>
        You are not currently allocated to teach this Course Offering, so the authoring actions are not
        available to you.
    </div>
@endif

{{-- ══ How this works ════════════════════════════════════════════════════ --}}
<section class="eSection-wrap mb-3" aria-labelledby="as-how">
    <h5 id="as-how" class="mb-2">How this works</h5>
    <div class="row g-3">
        <div class="col-12 col-md-4">
            <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-1">1 &middot; Write the assignment</div>
                <p class="small text-muted mb-0">
                    Title, instructions in the editor, the marks available, and when it opens and is due.
                    Saving creates a <strong>Draft</strong> that students cannot see.
                </p>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-1">2 &middot; Publish it</div>
                <p class="small text-muted mb-0">
                    Publishing makes it visible to confirmed students. Scheduling it instead releases it
                    at a moment you choose, and it stays hidden until then.
                </p>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-1">3 &middot; Mark and return</div>
                <p class="small text-muted mb-0">
                    Record a mark and feedback whenever you like &mdash; the student sees nothing until you
                    <strong>return</strong> it. Closing stops new submissions and keeps every one of them.
                </p>
            </div>
        </div>
    </div>
</section>

@if($canManage)
    <section class="eSection-wrap mb-4" aria-labelledby="as-create">
        <h5 id="as-create" class="mb-2">Create an assignment</h5>
        <p class="text-muted small">
            A new assignment starts as a Draft. Nothing is visible to students until you publish or schedule it.
        </p>
        <a href="{{ route('teacher.course_offerings.assignments.create', $offering->id) }}" class="btn btn-primary">
            + Create Assignment
        </a>
    </section>
@endif

{{-- ══ The assignments ═══════════════════════════════════════════════════ --}}
<section class="eSection-wrap" aria-labelledby="as-list">
    <h5 id="as-list" class="mb-3">Your assignments</h5>

    @if($assignments->isEmpty())
        <div class="border rounded p-4 text-center" role="status">
            <h6>No assignments yet.</h6>
            <p class="text-muted mb-0">
                @if($canManage)
                    Create your first assignment above. It will be a Draft until you publish it.
                @else
                    The lecturer for this Course Offering has not set any assignments yet.
                @endif
            </p>
        </div>
    @else
        <div class="as-table-scroll">
            <table class="table align-middle" data-testid="as-list">
                <thead>
                    <tr>
                        <th>Assignment</th>
                        <th>Module / Role</th>
                        <th>State</th>
                        <th>Due</th>
                        <th class="text-center">Marks</th>
                        <th class="text-center">Submitted</th>
                        <th class="text-center">Marked</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($assignments as $assignment)
                    @php $row = $summary[$assignment->id]; @endphp
                    <tr data-testid="as-row" data-assignment-id="{{ $assignment->id }}">
                        <td>
                            <a class="fw-semibold" href="{{ route('teacher.course_offerings.assignments.show', [$offering->id, $assignment->id]) }}">
                                {{ $assignment->title }}
                            </a>
                            <div class="small text-muted">
                                {{ \App\Models\Assignment::SUBMISSION_TYPE_LABELS[$assignment->submission_type] ?? $assignment->submission_type }}
                                &middot; {{ $assignment->attemptsAllowed() === 1 ? 'one attempt' : $assignment->attemptsAllowed().' attempts' }}
                                @if($assignment->released_at?->isFuture())
                                    &middot; opens {{ $assignment->released_at->format('j M Y, H:i') }}
                                @endif
                            </div>
                        </td>
                        {{-- WHICH MODULE, and whether it GATES that module.
                             A lecturer must be able to tell a blocking task from
                             supplementary practice without opening each one, so this
                             is a column rather than something buried in the edit
                             form. The words are the lecturer's, never a bare role
                             value and a colour. --}}
                        <td data-testid="as-module-role">
                            @if($assignment->isModuleTask() && $assignment->module)
                                <div class="small fw-semibold">
                                    {{ $assignment->module->sequence ? $assignment->module->sequence.'. ' : '' }}{{ $assignment->module->title }}
                                </div>
                            @else
                                <div class="small text-muted">Not attached to a module</div>
                            @endif

                            @if($assignment->isRequiredForModule())
                                <span class="as-chip as-chip-late" data-testid="as-role">
                                    <span class="as-dot as-dot-late" aria-hidden="true"></span>
                                    Required for the module
                                </span>
                            @elseif($assignment->isModuleTask())
                                <span class="as-chip as-chip-muted" data-testid="as-role">
                                    <span class="as-dot as-dot-muted" aria-hidden="true"></span>
                                    Supplementary
                                </span>
                            @endif
                        </td>
                        <td data-testid="as-state">
                            <span class="as-chip as-chip-{{ Str::lower(str_replace(' ', '-', $row['label'])) }}">
                                <span class="as-dot as-dot-{{ Str::lower(str_replace(' ', '-', $row['label'])) }}" aria-hidden="true"></span>
                                {{ $row['label'] }}
                            </span>
                        </td>
                        <td class="small">
                            @if($assignment->due_date)
                                {{ \App\Support\Assignments\AssignmentDisplay::make($assignment, auth()->user())->due($assignment, auth()->user()) }}
                            @else
                                <span class="text-muted">Not set</span>
                            @endif
                        </td>
                        <td class="text-center">{{ $assignment->max_marks }}</td>
                        <td class="text-center" data-testid="as-submitted-count">
                            {{ $row['submitted'] }}<span class="text-muted small">/{{ $confirmedStudents }}</span>
                        </td>
                        <td class="text-center" data-testid="as-graded-count">
                            {{ $row['graded'] }}
                            @if($row['released'] > 0)
                                <span class="small text-muted">({{ $row['released'] }} returned)</span>
                            @elseif($row['graded'] > 0)
                                <span class="small text-muted">(not returned)</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1 flex-wrap justify-content-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="{{ route('teacher.course_offerings.assignments.show', [$offering->id, $assignment->id]) }}">Open</a>
                                <a class="btn btn-sm btn-outline-primary"
                                   href="{{ route('teacher.course_offerings.assignments.submissions', [$offering->id, $assignment->id]) }}">
                                    Marking{{ $row['submitted'] > 0 ? ' ('.$row['submitted'].')' : '' }}
                                </a>
                                @if($canManage)
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('teacher.course_offerings.assignments.edit', [$offering->id, $assignment->id]) }}">Edit</a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

{{-- Closes the content section opened at the top of this file.
     Without it the output buffer stays open for the rest of the request,
     which a single page load hides and a test suite reports. --}}
@endsection
