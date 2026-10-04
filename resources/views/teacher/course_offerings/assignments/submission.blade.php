@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    /**
     * One student's work, ready to mark.
     *
     * RECORDING AND RETURNING ARE SEPARATE BUTTONS
     *
     * "Save mark" stores the decision; "Return to student" is what makes it
     * visible. They are adjacent so the second is never forgotten once the first
     * has been used, but they are genuinely different acts and the page says so.
     *
     * EVERY ATTEMPT IS SHOWN, so a resubmitted student can be compared with what
     * they sent first rather than only the latest.
     */
    $unitName = $offering->subject?->name ?? $offering->reference;
@endphp

@include('teacher.course_offerings.assignments._styles')

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.assignments.submissions', [$offering->id, $assignment->id]) }}"
       class="btn btn-sm btn-outline-secondary mb-2">Back to marking</a>
    <h4 class="mb-1">{{ $submission->student?->name ?? 'Student' }}</h4>
    <p class="text-muted mb-0">
        {{ $assignment->title }} &middot; {{ $unitName }} &middot;
        attempt {{ $submission->attempt_no }} of {{ $assignment->attemptsAllowed() }}
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-7">
        {{-- ══ The student's work ═══════════════════════════════════════ --}}
        <article class="border rounded p-3" data-testid="as-submitted-work">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <h6 class="mb-0">Submitted work</h6>
                <span class="small text-muted">
                    {{ $display->submittedAt($assignment, auth()->user(), $submission->submitted_at) }}
                    @if($submission->isLate())
                        <span class="as-chip as-chip-late ms-1">
                            <span class="as-dot as-dot-late" aria-hidden="true"></span>Late
                        </span>
                    @endif
                </span>
            </div>

            @php
                $evidence = $submission->items;
                $written = $submission->writtenResponse();
            @endphp

            {{-- The written response, from the canonical rich-text field. --}}
            @if($written !== null)
                <div class="as-surface border rounded p-3 mb-3" data-testid="as-submitted-text">
                    <h6 class="small text-uppercase text-muted">Written response</h6>
                    {{-- Already sanitised on the way in by the shared allowlist, so
                         this is reading filtered content. --}}
                    {!! $written !!}
                </div>
            @endif

            {{-- Every piece of evidence, each openable. A marker told to expect a
                 photograph has to be able to OPEN the photograph. --}}
            @if($evidence->isNotEmpty())
                <h6 class="small text-uppercase text-muted mt-3">Attached evidence</h6>
                <ul class="list-group list-group-flush mb-3" data-testid="as-evidence-list">
                    @foreach($evidence as $item)
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-start gap-2">
                            <div style="min-width:0">
                                <div class="fw-semibold text-truncate">{{ $item->displayName() }}</div>
                                <div class="small text-muted">
                                    {{ $item->kindLabel() }}
                                    @if($item->sizeLabel()) &middot; {{ $item->sizeLabel() }} @endif
                                    @if($item->mime_type) &middot; {{ $item->mime_type }} @endif
                                </div>
                                @if($item->note)
                                    <div class="small"><em>{{ $item->note }}</em></div>
                                @endif
                            </div>
                            @if($item->hasFile())
                                {{-- The authorised route. The stored path is generated and
                                     outside the web root, so this is the only way to
                                     read it, and it re-checks the lecturer's own
                                     allocation on this exact Offering. --}}
                                <a class="btn btn-sm btn-outline-primary flex-shrink-0"
                                   href="{{ route('teacher.course_offerings.assignments.submissions.evidence', [$offering->id, $assignment->id, $submission->id, $item->id]) }}">Open</a>
                            @elseif($item->hasUsableLink())
                                <a class="btn btn-sm btn-outline-primary flex-shrink-0"
                                   href="{{ $item->url }}" target="_blank" rel="noopener noreferrer">Open</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($written === null && $evidence->isEmpty())
                <p class="text-muted mb-0">This attempt recorded no work.</p>
            @endif
        </article>

        @if($attempts->count() > 1)
            <section class="border rounded p-3 mt-3" aria-labelledby="as-history">
                <h6 id="as-history" class="mb-2">Every attempt</h6>
                <ul class="list-group list-group-flush">
                    @foreach($attempts as $attempt)
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                            <span class="small">
                                Attempt {{ $attempt->attempt_no }}
                                &middot; {{ $display->submittedAt($assignment, auth()->user(), $attempt->submitted_at) }}
                                @if($attempt->isLate()) <span class="text-muted">(late)</span> @endif
                                @if($attempt->items->isNotEmpty())
                                    &middot; {{ $attempt->items->count() }} {{ Str::plural('item', $attempt->items->count()) }}
                                @endif
                                @if($attempt->writtenResponse() !== null) &middot; written response @endif
                            </span>
                            <span class="small">
                                @if($attempt->isGraded())
                                    {{ rtrim(rtrim(number_format((float) $attempt->marks_awarded, 2), '0'), '.') }} / {{ $assignment->max_marks }}
                                    @if($attempt->isReleased()) <span class="text-muted">(returned)</span> @endif
                                @else
                                    <span class="text-muted">not marked</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
                <p class="small text-muted mt-2 mb-0">
                    Earlier attempts are kept rather than overwritten, so a resubmission never destroys the
                    work that was there before.
                </p>
            </section>
        @endif
    </div>

    <div class="col-12 col-lg-5">
        {{-- ══ Grading ══════════════════════════════════════════════════ --}}
        <div class="border rounded p-3">
            <h6 class="mb-2">Mark this attempt</h6>
            <p class="small text-muted">
                Out of {{ $assignment->max_marks }} marks. A mark above that, or below zero, is refused
                rather than adjusted — a stored mark of {{ $assignment->max_marks + 10 }} against a maximum of
                {{ $assignment->max_marks }} would quietly corrupt every total built from it.
            </p>

            @if($questionBased)
                @include('teacher.course_offerings.assignments._question_marking', [
                    'questions' => $questions,
                    'answers' => $questionAnswers,
                ])
            @else
                {{-- ── A GENERIC assignment: one mark for the whole piece of work ──
                     The original form, untouched. --}}
                <form method="POST"
                      action="{{ route('teacher.course_offerings.assignments.submissions.grade', [$offering->id, $assignment->id, $submission->id]) }}">
                    @csrf
                    <div class="mb-3">
                        <label for="as-marks-awarded" class="form-label">Marks awarded</label>
                        <input type="number" step="0.01" min="0" max="{{ $assignment->max_marks }}"
                               class="form-control @error('marks_awarded') is-invalid @enderror"
                               id="as-marks-awarded" name="marks_awarded"
                               value="{{ old('marks_awarded', $submission->marks_awarded) }}">
                        @error('marks_awarded')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    {{-- The SAME editor as the question-based feedback, on purpose.
                         A student whose lecturer marked a question-based assignment
                         receives formatted feedback, and a student on a generic one
                         would otherwise receive a wall of unformatted prose - for no
                         reason either of them could discover.

                         The generic path is not going away: a single-submission
                         assignment is a legitimate thing to set, and the
                         `submission_kinds` rules that decide it must keep working.
                         So the field is brought up to the same standard rather than
                         left as the last plain textarea in the marking journey. --}}
                    <x-academic-editor
                        name="feedback"
                        id="as-feedback"
                        label="Feedback for the student"
                        :value="old('feedback', $submission->feedback)"
                        testid="as-feedback"
                        :height="260"
                        :rows="6"
                        placeholder="What went well, and what to do differently. Use the toolbar for worked steps, symbols and equations."
                        help="The student sees this only once you return it."
                    />
                    <button type="submit" class="btn btn-primary w-100" data-testid="as-save-mark">Save mark</button>
                </form>
            @endif

            <div class="border-top pt-3 mt-3">
                @if($submission->isReleased())
                    <div class="alert alert-success py-2" role="status" data-testid="as-released-note">
                        <strong>Returned to the student.</strong>
                        They can see this mark and your feedback.
                    </div>
                    <form method="POST"
                          action="{{ route('teacher.course_offerings.assignments.submissions.unrelease', [$offering->id, $assignment->id, $submission->id]) }}"
                          onsubmit="return confirm('Hide this result from the student again? The mark is kept.')">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Withdraw the result</button>
                    </form>
                @else
                    <p class="small text-muted" data-testid="as-unreleased-note">
                        The student cannot see the mark or the feedback yet. Saving above records your decision
                        without publishing it.
                    </p>
                    <form method="POST"
                          action="{{ route('teacher.course_offerings.assignments.submissions.release', [$offering->id, $assignment->id, $submission->id]) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary w-100" data-testid="as-return-feedback">
                            Return feedback to the student
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="border rounded p-3 mt-3">
            <h6 class="mb-2">What was asked</h6>
            <div class="small text-muted mb-2">
                {{ \App\Models\Assignment::SUBMISSION_TYPE_LABELS[$assignment->submission_type] ?? $assignment->submission_type }}
                &middot; {{ $assignment->attemptsAllowed() === 1 ? 'one attempt' : $assignment->attemptsAllowed().' attempts' }}
                &middot; due {{ $display->due($assignment, auth()->user()) }}
            </div>
            <div class="as-surface small mb-2">
                {!! $assignment->proseInstructions() !!}
            </div>

            {{-- A paper of four questions is worth listing, so a marker can see the
                 split at a glance. A generic assignment has none, and the panel above
                 is the whole of "what was asked". --}}
            @if($questionBased && $questions->isNotEmpty())
                <h6 class="small text-uppercase text-muted mt-3 mb-2">The questions</h6>
                <ol class="small mb-0" data-testid="as-mark-paper">
                    @foreach($questions as $question)
                        <li class="mb-1">
                            {{ $question->heading ?: 'Question '.($loop->iteration) }}
                            <span class="text-muted">&middot; {{ $question->marksLabelWithUnit() }}</span>
                        </li>
                    @endforeach
                </ol>
                <p class="small text-muted mt-2 mb-0">
                    {{ $questions->count() }} questions totalling
                    {{ $assignment->max_marks }} marks. The assignment total is the
                    sum of the question marks, so it cannot contradict them.
                </p>
            @endif
        </div>
    </div>
</div>

{{-- Closes the content section opened at the top of this file.
     Without it the output buffer stays open for the rest of the request,
     which a single page load hides and a test suite reports. --}}
@endsection

{{-- ── The running total ───────────────────────────────────────────────
     Display only. The figure is recalculated by the server from the marks
     that are actually STORED when the form is saved, so this can never be the
     number that is recorded - it exists so a lecturer can see the total move
     as they work, not to be submitted.

     DEFERRED, and the form works without it: with no script the panel shows
     the total of the marks already recorded, which is still true. --}}
@if($questionBased && $questions->isNotEmpty())
    <script src="{{ asset('js/assignment-marks.js') }}" defer></script>
@endif
