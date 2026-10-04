@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    /**
     * One assignment as the lecturer sees it, plus the lifecycle actions.
     *
     * The lifecycle buttons are rendered from AssignmentLifecycle::TRANSITIONS, so
     * the screen can only ever offer a move the state machine actually permits.
     * A "Publish" button on a closed assignment would be a promise PIIE cannot
     * keep, because closed is terminal on purpose.
     */
    $unitName = $offering->subject?->name ?? $offering->reference;
    $label = \App\Support\Assignments\AssignmentLifecycle::lecturerLabel($assignment);
    $chip = Str::lower(str_replace(' ', '-', $label));
    $canManage = $access = app(\App\Support\Assignments\AssignmentAccess::class)
        ->canLecturerManage(auth()->user(), $offering);
@endphp

@include('teacher.course_offerings.assignments._styles')

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.assignments.index', $offering->id) }}" class="btn btn-sm btn-outline-secondary mb-2">
        Back to Assignments
    </a>
    <h4 class="mb-1">{{ $assignment->title }}</h4>
    <p class="text-muted mb-0">
        {{ $unitName }} &middot;
        <span class="as-chip as-chip-{{ $chip }}" data-testid="as-detail-state">
            <span class="as-dot as-dot-{{ $chip }}" aria-hidden="true"></span>{{ $label }}
        </span>
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" role="status">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-8">
        {{-- WHICH MODULE this task belongs to, and whether it GATES that module.
             Stated here in the lecturer's own words, because the difference between
             supplementary practice and a requirement is the single most consequential
             thing on this page. --}}
        <div class="border rounded p-3 mb-3" data-testid="as-task-context">
            <h6 class="small text-uppercase text-muted">Where this task belongs</h6>
            @if($module)
                <p class="mb-2">
                    <span class="fw-semibold">
                        {{ $module->sequence ? $module->sequence.'. ' : '' }}{{ $module->title }}
                    </span>
                </p>
            @else
                <p class="mb-2 text-muted">Not attached to a module.</p>
            @endif

            @if($assignment->isRequiredForModule())
                <p class="mb-2">
                    <span class="as-chip as-chip-late">
                        <span class="as-dot as-dot-late" aria-hidden="true"></span>
                        Required for this module
                    </span>
                </p>
                <p class="small text-muted mb-0">
                    Counts as done: {{ $assignment->completionRuleLabel() }}. The module is
                    not fully complete until this is satisfied.
                </p>
            @elseif($assignment->isModuleTask())
                <p class="mb-0">
                    <span class="as-chip as-chip-muted">
                        <span class="as-dot as-dot-muted" aria-hidden="true"></span>
                        Supplementary
                    </span>
                    <span class="small text-muted d-block mt-1">
                        This task does not gate the module. No student is ever blocked by it.
                    </span>
                </p>
            @endif
        </div>
        <article class="as-surface border rounded p-3 p-md-4">
            <dl class="row small mb-0">
                <dt class="col-5 col-sm-4 text-muted">Opens</dt>
                <dd class="col-7 col-sm-8">{{ $display->released($assignment, auth()->user()) }}</dd>

                <dt class="col-5 col-sm-4 text-muted">Due</dt>
                <dd class="col-7 col-sm-8" data-testid="as-detail-due">{{ $display->due($assignment, auth()->user()) }}</dd>

                @if($assignment->closes_at)
                    <dt class="col-5 col-sm-4 text-muted">Final closing</dt>
                    <dd class="col-7 col-sm-8">{{ $display->closes($assignment, auth()->user()) }}</dd>
                @endif

                <dt class="col-5 col-sm-4 text-muted">Total marks</dt>
                <dd class="col-7 col-sm-8">{{ $assignment->max_marks }}</dd>

                <dt class="col-5 col-sm-4 text-muted">Submission</dt>
                <dd class="col-7 col-sm-8">{{ \App\Models\Assignment::SUBMISSION_TYPE_LABELS[$assignment->submission_type] ?? $assignment->submission_type }}</dd>

                <dt class="col-5 col-sm-4 text-muted">Attempts</dt>
                <dd class="col-7 col-sm-8">
                    {{ $assignment->attemptsAllowed() === 1 ? 'One attempt' : $assignment->attemptsAllowed().' attempts' }}
                </dd>

                <dt class="col-5 col-sm-4 text-muted">After the due date</dt>
                <dd class="col-7 col-sm-8">
                    {{ $assignment->allowsLateSubmissions() ? 'Accepted and recorded as late' : 'New submissions refused' }}
                </dd>
            </dl>

            @if($display->zoneNote($assignment, auth()->user()))
                <p class="small text-muted mt-2 mb-0">{{ $display->zoneNote($assignment, auth()->user()) }}</p>
            @endif
            @if($display->institutionDue($assignment))
                @php $inst = $display->institutionDue($assignment); @endphp
                <p class="small text-muted mb-0">
                    Institution time ({{ $inst['label'] }}): {{ $inst['date'] }} at {{ $inst['time'] }}
                </p>
            @endif

            @if($assignment->learning_objectives)
                <hr>
                <h6>What this assesses</h6>
                <ul class="mb-0">
                    @foreach(preg_split('/\r\n|\r|\n|;/', $assignment->learning_objectives) as $line)
                        @php $line = trim($line); @endphp
                        @if($line !== '') <li>{{ ltrim($line, '•- ') }}</li> @endif
                    @endforeach
                </ul>
            @endif

            <hr>
            {{-- Read through `proseInstructions()`, which filters on the way OUT.
                 The comment that used to stand here said this was "already sanitised on the
                 way in by a model mutator". That was half true and the mechanism was wrong:
                 there is no model mutator on `Assignment` - the filtering happens in
                 `AssignmentService` when the assignment is saved. The CONCLUSION, that
                 `{!! !!}` is acceptable here, happened to be right, which is the worst kind
                 of wrong: it looked justified, so the next reader would either go looking
                 for a filter that does not exist, or trust the storage path to stay safe
                 by itself. The guarantee is now visible at the point of use. --}}
            <div class="as-body piie-prose">
            {!! $assignment->proseInstructions() !!}
            </div>
        </article>

        {{-- The other tasks on this module, so a lecturer managing a module can see
             the whole set and which of them actually gate it. Shown only for a
             module task: a task with no module has no siblings by definition. --}}
        @if($module && $moduleTasks->isNotEmpty())
            <section class="border rounded p-3 mt-3" aria-labelledby="as-siblings">
                <h6 id="as-siblings" class="mb-2">Other tasks on this module</h6>
                <ul class="list-unstyled mb-0" data-testid="as-sibling-tasks">
                    @foreach($moduleTasks as $sibling)
                        @continue($sibling->id === $assignment->id)
                        <li class="d-flex justify-content-between align-items-center gap-2 py-1 border-bottom">
                            <span style="min-width:0">
                                {{ $sibling->title }}
                                <span class="small text-muted">
                                    &middot; {{ $sibling->max_marks }} marks
                                </span>
                            </span>
                            @if($sibling->isRequiredForModule())
                                <span class="as-chip as-chip-late">
                                    <span class="as-dot as-dot-late" aria-hidden="true"></span>Required
                                </span>
                            @else
                                <span class="as-chip as-chip-muted">
                                    <span class="as-dot as-dot-muted" aria-hidden="true"></span>Supplementary
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
        @if($assignment->resources->isNotEmpty())
            <section class="border rounded p-3 mt-3" aria-labelledby="as-res">
                <h6 id="as-res" class="mb-2">Resources</h6>
                <ul class="list-unstyled mb-0">
                    @foreach($assignment->resources as $resource)
                        <li class="d-flex justify-content-between align-items-center gap-2 py-1">
                            <span style="min-width:0">
                                {{ $resource->displayName() }}
                                <span class="small text-muted">
                                    &middot; {{ $resource->isFile() ? 'File' : 'Link' }}
                                    @if($resource->sizeLabel()) &middot; {{ $resource->sizeLabel() }} @endif
                                </span>
                            </span>
                            @if($resource->isFile())
                                <a class="btn btn-sm btn-outline-primary"
                                   href="{{ route('teacher.course_offerings.assignments.resources.file', $resource->id) }}">Open</a>
                            @elseif($resource->hasUsableLink())
                                <a class="btn btn-sm btn-outline-primary" href="{{ $resource->link_url }}"
                                   target="_blank" rel="noopener noreferrer">Open</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    <div class="col-12 col-lg-4">
        @if($canManage)
            <div class="border rounded p-3 mb-3">
                <h6 class="mb-2">Lifecycle</h6>
                <p class="small text-muted">
                    Only the moves the lifecycle allows are offered. Closed is final on purpose: reopening would
                    silently change what "closed" told a student, so publish a new assignment instead.
                </p>
                <div class="d-grid gap-2">
                    @foreach(\App\Support\Assignments\AssignmentLifecycle::TRANSITIONS[$assignment->status] ?? [] as $to)
                        <form method="POST"
                              action="{{ route('teacher.course_offerings.assignments.state', [$offering->id, $assignment->id, $to]) }}"
                              onsubmit="return confirm('{{ \App\Support\Assignments\AssignmentLifecycle::labelFor($assignment) === 'Draft' && $to === 'published' ? 'Publish this assignment? Students will be able to see it.' : 'Change this assignment to '.ucfirst($to).'?' }}')">
                            @csrf
                            <button type="submit" class="btn btn-{{ $to === 'published' ? 'primary' : 'outline-secondary' }} w-100">
                                @if($to === 'published') Publish now
                                @elseif($to === 'scheduled') Schedule for its release time
                                @elseif($to === 'closed') Close — stop new submissions
                                @else Return to draft
                                @endif
                            </button>
                        </form>
                    @endforeach
                </div>

                @if($assignment->status === 'draft')
                    <form method="POST" class="mt-3 pt-3 border-top"
                          action="{{ route('teacher.course_offerings.assignments.destroy', [$offering->id, $assignment->id]) }}"
                          onsubmit="return confirm('Delete this draft? This cannot be undone.')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">Delete this draft</button>
                    </form>
                @endif
            </div>
        @endif

        <div class="border rounded p-3 mb-3">
            <h6 class="mb-2">Submissions</h6>
            @php
                $real = $markingList->filter(fn ($row) => $row['submission'] !== null);
                $missing = $markingList->count() - $real->count();
                $graded = $real->filter(fn ($row) => $row['submission'] && $row['submission']->isGraded());
                $returned = $real->filter(fn ($row) => $row['submission'] && $row['submission']->isReleased());
            @endphp
            <dl class="row small mb-0">
                <dt class="col-7 text-muted">Registered students</dt>
                <dd class="col-5 text-end">{{ $markingList->count() }}</dd>
                <dt class="col-7 text-muted">Submitted</dt>
                <dd class="col-5 text-end" data-testid="as-detail-submitted">{{ $real->count() }}</dd>
                <dt class="col-7 text-muted">Not submitted</dt>
                <dd class="col-5 text-end">{{ $missing }}</dd>
                <dt class="col-7 text-muted">Marked</dt>
                <dd class="col-5 text-end">{{ $graded->count() }}</dd>
                <dt class="col-7 text-muted">Returned to student</dt>
                <dd class="col-5 text-end" data-testid="as-detail-returned">{{ $returned->count() }}</dd>
            </dl>
            <a href="{{ route('teacher.course_offerings.assignments.submissions', [$offering->id, $assignment->id]) }}"
               class="btn btn-sm btn-outline-primary w-100 mt-3">Open the marking list</a>
        </div>

        <div class="d-grid gap-2">
            <a class="btn btn-outline-secondary btn-sm"
               href="{{ route('teacher.course_offerings.assignments.preview', [$offering->id, $assignment->id]) }}">
                Preview as a student
            </a>
            @if($canManage)
                {{-- ── THE QUESTION BUILDER ──────────────────────────────────────
                     A FIRST-CLASS destination, not a tab hidden behind Edit, because
                     questions are not a field of the assignment: they are the
                     assignment. The label states which model this assignment is
                     currently using, so a lecturer can see at a glance whether
                     students get one evidence form or an ordered paper - and
                     adding a question is the act that switches it. --}}
                <a class="btn btn-outline-secondary btn-sm"
                   href="{{ route('teacher.course_offerings.assignments.questions', [$offering->id, $assignment->id]) }}"
                   data-testid="as-open-questions">
                    @if($assignment->isQuestionBased())
                        Questions ({{ $assignment->questionCount() }})
                    @else
                        Add questions
                    @endif
                </a>
                <a class="btn btn-outline-secondary btn-sm"
                   href="{{ route('teacher.course_offerings.assignments.edit', [$offering->id, $assignment->id]) }}">Edit</a>
            @endif
        </div>
    </div>
</div>

<style>
    .as-body table { display: block; width: 100%; overflow-x: auto; border-collapse: collapse; }
    .as-body th, .as-body td { border: 1px solid var(--as-line, #d7dee5); padding: .5em .7em; text-align: left; }
    .as-body th { background: #f4f6f8; font-weight: 600; }
</style>

{{-- Closes the content section opened at the top of this file.
     Without it the output buffer stays open for the rest of the request,
     which a single page load hides and a test suite reports. --}}
@endsection
