@extends('student.navigation')
@section('content')
@php
    /**
     * One assignment, and the submission panel.
     *
     * THE FOUR QUESTIONS, ANSWERED ON THIS PAGE
     *
     *   What do I need to do?   the objectives and the instructions
     *   When is it due?         in THIS student's own clock, from one stored instant
     *   Have I submitted?       draft / submitted / returned, per attempt
     *   What happens next?      attempts left, whether late work is accepted,
     *                           and whether the assignment has closed
     *
     * DRAFT AND SUBMIT ARE VISUALLY SEPARATE, ON PURPOSE
     *
     * "Saved" and "Submitted" are different claims about the same work. The
     * prepared-work zone is grey and dashed and labelled as not submitted; the
     * hand-in button is a separate, explicit act. A student who has typed for
     * twenty minutes must never be left unsure whether that counted.
     *
     * An idempotency key travels with the submit form, so a double-click or a
     * retried request collapses onto one attempt instead of consuming two.
     */
    $chip = Str::lower(str_replace([' ', '(', ')', '—'], ['-', '', '', ''], $state));
@endphp

@include('teacher.course_offerings.assignments._styles')

<div class="mainSection-title">
    <a href="{{ route('student.courses.assignments.index', $offering->id) }}" class="btn btn-sm btn-outline-secondary mb-2">
        Back to Assignments
    </a>
    <p class="small text-muted mb-1" data-testid="as-detail-position">{{ $unitName }}</p>
    <h4 class="mb-1">{{ $assignment->title }}</h4>
    <p class="text-muted mb-0">
        <span class="as-chip as-chip-{{ $chip }}" data-testid="as-student-detail-state">
            <span class="as-dot as-dot-{{ $chip }}" aria-hidden="true"></span>{{ $state }}
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
    <div class="col-12 col-lg-7">
        <article class="as-surface border rounded p-3 p-md-4">
            <dl class="row small mb-0">
                <dt class="col-5 col-sm-4 text-muted">Opens</dt>
                <dd class="col-7 col-sm-8">{{ $display->released($assignment, auth()->user()) }}</dd>

                <dt class="col-5 col-sm-4 text-muted">Due</dt>
                <dd class="col-7 col-sm-8" data-testid="as-due">{{ $display->due($assignment, auth()->user()) }}</dd>

                @if($assignment->closes_at)
                    <dt class="col-5 col-sm-4 text-muted">Final closing</dt>
                    <dd class="col-7 col-sm-8">{{ $display->closes($assignment, auth()->user()) }}</dd>
                @endif

                <dt class="col-5 col-sm-4 text-muted">Marks available</dt>
                <dd class="col-7 col-sm-8">{{ $assignment->max_marks }}</dd>

                <dt class="col-5 col-sm-4 text-muted">What to submit</dt>
                <dd class="col-7 col-sm-8" data-testid="as-evidence-requirement">
                    {{-- Read from the QUESTIONS when there are any, so the summary
                         cannot say "Written response or Document" for a paper that
                         actually asks for a photograph, an explanation and a
                         demonstration. The generic wording is untouched for a
                         generic assignment. --}}
                    @if($questionBased)
                        {{ $questions->count() }} question{{ $questions->count() === 1 ? '' : 's' }}, answered one at a time
                    @else
                        {{ $assignment->evidenceRequirementLabel() }}
                    @endif
                </dd>

                <dt class="col-5 col-sm-4 text-muted">Attempts</dt>
                <dd class="col-7 col-sm-8" data-testid="as-attempts">
                    {{ $attempts_allowed === 1 ? 'One attempt' : 'Up to '.$attempts_allowed.' attempts' }}
                    @if($attempts_allowed > 1)
                        &middot; {{ $attempts_used }} used
                    @endif
                </dd>

                <dt class="col-5 col-sm-4 text-muted">After the due date</dt>
                <dd class="col-7 col-sm-8">
                    {{ $assignment->allowsLateSubmissions() ? 'Accepted, and recorded as late' : 'New submissions are not accepted' }}
                </dd>
            </dl>

            @if($display->zoneNote($assignment, auth()->user()))
                <p class="small text-muted mt-2 mb-0" data-testid="as-zone-note">
                    {{ $display->zoneNote($assignment, auth()->user()) }}
                </p>
            @endif

            @if($assignment->learning_objectives)
                <hr>
                <h6>What you should be able to do</h6>
                <ul class="mb-0">
                    @foreach(preg_split('/\r\n|\r|\n|;/', $assignment->learning_objectives) as $line)
                        @php $line = trim($line); @endphp
                        @if($line !== '' && $line !== '•') <li>{{ ltrim($line, '•- ') }}</li> @endif
                    @endforeach
                </ul>
            @endif

            <hr>
            {{-- Already sanitised on the way in by a model mutator, so this is
                 reading filtered content. The one place here {!! !!} is correct. --}}
            <div class="as-body">
                {!! $assignment->proseInstructions() !!}
            </div>
        </article>

        @if($assignment->resources->isNotEmpty())
            <section class="border rounded p-3 mt-3" aria-labelledby="as-student-res">
                <h6 id="as-student-res" class="mb-2">Resources for this assignment</h6>
                <ul class="list-unstyled mb-0">
                    @foreach($assignment->resources as $resource)
                        <li class="d-flex justify-content-between align-items-center gap-2 py-1">
                            <span style="min-width:0">
                                {{ $resource->displayName() }}
                                <span class="small text-muted">
                                    &middot; {{ $resource->isFile() ? 'Download' : 'Web link' }}
                                    @if($resource->sizeLabel()) &middot; {{ $resource->sizeLabel() }} @endif
                                </span>
                            </span>
                            @if($resource->isFile() && $resource->stored_name)
                                <a class="btn btn-sm btn-outline-primary flex-shrink-0"
                                   href="{{ route('student.courses.assignments.resources.file', $resource->id) }}">Open</a>
                            @elseif(! $resource->isFile() && $resource->hasUsableLink())
                                <a class="btn btn-sm btn-outline-primary flex-shrink-0"
                                   href="{{ $resource->link_url }}" target="_blank" rel="noopener noreferrer">Open</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="small text-muted mt-2 mb-0">
                    Sizes are shown so you can decide what to download on a metered connection.
                </p>
            </section>
        @endif
    </div>

    <div class="col-12 col-lg-5">
        {{-- ══ A returned result, when there is one ══════════════════════════ --}}
        @if($latest && $latest->isReleased())
            <div class="as-result border rounded p-3 mb-3" data-testid="as-result">
                <h6 class="mb-2">Your result</h6>
                <div class="as-mark" data-testid="as-result-mark">
                    {{ rtrim(rtrim(number_format((float) $latest->marks_awarded, 2), '0'), '.') }}
                    <span class="fs-6 fw-normal text-muted">/ {{ $assignment->max_marks }}</span>
                    @if($latest->percentOfMaximum() !== null)
                        <span class="fs-6 fw-normal text-muted">({{ $latest->percentOfMaximum() }}%)</span>
                    @endif
                </div>
                <p class="small text-muted mb-0">
                    Returned {{ $latest->marks_released_at?->format('j M Y, H:i') }}
                    @if($latest->isLate()) &middot; this attempt was late @endif
                </p>
                {{-- ── PER-QUESTION MARKS AND FEEDBACK ────────────────────────
                     A total of 17/20 is not feedback. A student needs to know WHICH
                     question lost the marks, and only a lecturer can say.

                     Rendered ONLY inside the `isReleased()` branch above, so there
                     remains exactly ONE gate on a student seeing a result and it
                     is the existing one. This cannot become a second way to read an
                     unreleased mark. --}}
                @if($questionBased && $latest->questionResponses->isNotEmpty())
                    <hr>
                    <h6 class="small">Marked question by question</h6>
                    <ul class="list-unstyled small mb-0" data-testid="as-result-per-question">
                        @foreach($questions as $question)
                            @php $row = $questionAnswers[$question->id] ?? null; @endphp
                            <li class="py-1 border-bottom">
                                <div class="d-flex justify-content-between gap-2">
                                    <span>Question {{ $loop->iteration }}</span>
                                    <span class="text-nowrap">
                                        @if($row?->marks_awarded !== null)
                                            <strong data-testid="as-question-mark-{{ $question->id }}">{{ $row->marksLabel() }}</strong>
                                            <span class="text-muted">/ {{ $question->marksLabel() }}</span>
                                        @else
                                            <span class="text-muted">not marked</span>
                                        @endif
                                    </span>
                                </div>
                                @if($row?->feedback)
                                    <p class="mb-0 text-muted" data-testid="as-question-feedback-{{ $question->id }}">{{ $row->feedback }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($latest->feedback)
                    <hr>
                    <h6 class="small">Feedback from your lecturer</h6>
                    <p class="mb-0" data-testid="as-result-feedback">{{ $latest->feedback }}</p>
                @endif
            </div>
        @endif

        {{-- ══ The submission panel ═══════════════════════════════════════ --}}
        <div class="as-submit-panel">
            <div class="as-draft-zone p-3">
                <h6 class="mb-1">Your work</h6>
                <p class="as-draft-note mb-2">
                    Saving here does <strong>not</strong> hand your work in, and does not use an attempt.
                    Use <em>Submit</em> below when you are ready.
                </p>

                @if($draft)
                    <p class="small mb-2">
                        <span class="as-chip as-chip-progress" data-testid="as-draft-chip">
                            <span class="as-dot as-dot-progress" aria-hidden="true"></span>Prepared, not submitted
                        </span>
                    </p>
                @endif

                @php
                    // The evidence already attached to the current draft, keyed by
                    // kind, so each input can say what is on file. A browser cannot
                    // re-populate a file input, so anything it silently dropped
                    // would be lost on submit - it has to be stated instead.
                    $attachedByKind = $draft
                        ? $draft?->items ?? collect()->groupBy('kind')
                        : collect();
                @endphp

                <form method="POST" enctype="multipart/form-data"
                      action="{{ route('student.courses.assignments.draft', [$offering->id, $assignment->id]) }}">
                    @csrf
                    @if($questionBased)
                        @include('student.course_assignments._question_fields', [
                            'assignment' => $assignment,
                            'questions' => $questions,
                            'answers' => $questionAnswers,
                            'evidenceByQuestion' => $evidenceByQuestion,
                            'prefix' => 'as-draft-',
                        ])
                    @else
                        @include('student.course_assignments._evidence_fields', [
                            'assignment' => $assignment,
                            'attachedByKind' => $attachedByKind,
                            'prefix' => 'as-draft-',
                            'existingText' => $draft?->writtenResponse() ?? $latest?->writtenResponse(),
                        ])
                    @endif
                    <button type="submit" class="btn btn-outline-secondary btn-sm">Save without submitting</button>
                </form>
            </div>

            <div class="p-3">
                <h6 class="mb-1">Hand it in</h6>

                @if(! $canSubmit)
                    <div class="alert alert-secondary py-2 small mb-0" data-testid="as-cannot-submit">
                        {{ $refusalReason }}
                    </div>
                @else
                    <p class="as-draft-note mb-2">
                        This counts as your hand-in. A mark is only visible after your lecturer returns it.
                    </p>
                    <form method="POST" enctype="multipart/form-data"
                          action="{{ route('student.courses.assignments.submit', [$offering->id, $assignment->id]) }}">
                        @csrf
                        {{-- The idempotency key. The UNIQUE index on this column is what
                             makes a double-click or a retried request collapse onto one
                             attempt instead of consuming two. --}}
                        <input type="hidden" name="idempotency_key"
                               value="{{ $attemptKey }}">
                        @if($questionBased)
                            @include('student.course_assignments._question_fields', [
                                'assignment' => $assignment,
                                'questions' => $questions,
                                'answers' => $questionAnswers,
                                'evidenceByQuestion' => $evidenceByQuestion,
                                'prefix' => 'as-submit-',
                            ])
                        @else
                            @include('student.course_assignments._evidence_fields', [
                                'assignment' => $assignment,
                                'attachedByKind' => $attachedByKind,
                                'prefix' => 'as-submit-',
                                'existingText' => $draft?->writtenResponse() ?? $latest?->writtenResponse(),
                            ])
                        @endif
                        <button type="submit" class="btn btn-success w-100" data-testid="as-submit">
                            Submit my work
                        </button>
                    </form>
                @endif
            </div>
        </div>

        @if($submissions->isNotEmpty())
            <div class="border rounded p-3 mt-3">
                <h6 class="mb-2">Your attempts</h6>
                <ul class="list-group list-group-flush">
                    @foreach($submissions as $attempt)
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                            <span class="small">
                                Attempt {{ $attempt->attempt_no }}
                                @if($attempt->isLate()) <span class="text-muted">(late)</span> @endif
                            </span>
                            @if($attempt->isReleased())
                                <span class="small text-success fw-semibold">
                                    {{ rtrim(rtrim(number_format((float) $attempt->marks_awarded, 2), '0'), '.') }} / {{ $assignment->max_marks }}
                                </span>
                            @elseif($attempt->isGraded())
                                <span class="small text-muted">marked, awaiting return</span>
                            @else
                                <span class="small text-muted">awaiting marking</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="small text-muted mb-0">Earlier attempts are kept, so resubmitting never replaces your history.</p>
            </div>
        @endif
    </div>
</div>

{{-- Closes the content section opened at the top of this file.
     Without it the output buffer stays open for the rest of the request,
     which a single page load hides and a test suite reports. --}}
@endsection

{{-- ── The recorder ──────────────────────────────────────────────────────
     Loaded ONLY on a question-based assignment that actually offers a
     recorder, and it attaches itself only to panels marked `data-as-recorder`.

     The page works with JavaScript switched off: every panel still has its
     file picker, and the picker is the whole answer. This script only ever
     ADDS a way to produce that same file, and it asks for a microphone or a
     camera exclusively from inside a click handler the student pressed.

     `defer` so it never runs before the panels exist, and the panel markup is
     inert without it. --}}
@if($questionBased && $questions->contains(fn ($q) => $q->allowsRecording()))
    <script src="{{ asset('js/assignment-recorder.js') }}" defer></script>
@endif
