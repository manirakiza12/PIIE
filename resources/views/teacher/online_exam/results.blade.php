@extends('teacher.navigation')

@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h4>{{ get_phrase('Results') }}: {{ $exam->title }}</h4>
    <a class="export_btn" href="{{ route('teacher.online_exams.attempts', $exam->id) }}">{{ get_phrase('Back to Attempts') }}</a>
</div></div></div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="alert alert-info">{{ get_phrase('Submit Marking for Review sends completed marking to an administrator. Only an administrator can publish the official result.') }}</div>

{{--
    ═══════════════════════════════════════════════════════════════════════════
    WHY THE MARKING SCREEN IS BUILT THIS WAY
    ═══════════════════════════════════════════════════════════════════════════

    The reported failure was a broken horizontal layout on exam 21, submission 15:
    student information and submitted answers cut off, the table overflowing, and
    the marking inputs squeezed into the far-right Actions column.

    The cause was structural, not cosmetic. ONE table carried both jobs — the
    per-student summary AND the student's written answers — with a hard
    `min-width: 1250px` and `white-space: nowrap` on every cell. A written answer
    cannot live inside that, so it was truncated and pushed sideways, and the mark
    input sat in the one column narrow enough to break.

    So the two jobs are now separate, and neither depends on the other fitting:

      1. THE SUMMARY — nine short columns, no fixed width, no nowrap. It is a list
         of students and it reads at every width down to a phone.
      2. THE MARKING SECTION — full width, below the summary, one card per question
         on the paper. A card is a stacked block, so it reflows rather than scrolls.

    Deliberately NOT used: `overflow: hidden`, or any fixed pixel width. Those hide
    content instead of fitting it, which is what made the original page look
    tidy and read as a rendering fault. Every rule below only ever allows content
    to wrap, shrink or stack.
--}}
<style>
    /* ── The summary ─────────────────────────────────────────────────────────
       No min-width and no nowrap: the table is allowed to be exactly as wide as
       its content needs, which on a narrow screen means it stacks rather than
       pushing the page sideways. */
    .piie-results-summary { width: 100%; table-layout: auto; }
    .piie-results-summary th,
    .piie-results-summary td { white-space: normal; vertical-align: middle; }
    .piie-results-summary th { font-size: .8rem; }
    .piie-results-summary td { font-size: .875rem; }

    /* The one column that genuinely needs room: the student's name and attempt. */
    .piie-results-summary .piie-col-student { min-width: 9rem; }

    /* Numerals line up and never push a column wider than its figures. */
    .piie-results-summary .piie-col-num { white-space: nowrap; font-variant-numeric: tabular-nums; }

    /* ── The marking section ───────────────────────────────────────────────── */
    .piie-marking { margin-top: 1.5rem; }
    .piie-marking-header {
        display: flex; flex-wrap: wrap; gap: .75rem;
        align-items: center; justify-content: space-between;
        margin-bottom: 1rem;
    }

    /* ONE CARD PER QUESTION. A block, not a row, so it reflows at any width. */
    .piie-mark-card {
        border: 1px solid #e3e6ea;
        border-radius: .5rem;
        background: #fff;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    .piie-mark-card.is-undecided { border-left: 4px solid #ffc107; }
    .piie-mark-card.is-decided   { border-left: 4px solid #28a745; }
    .piie-mark-card.is-blank     { border-left: 4px solid #dc3545; }

    .piie-mark-card-head {
        display: flex; flex-wrap: wrap; gap: .5rem;
        align-items: baseline; margin-bottom: .625rem;
    }
    .piie-mark-qno { font-weight: 700; white-space: nowrap; }

    /* The question and the student's answer get the same prose treatment as the
       rest of PIIE, and BOTH wrap. This is the pair that was being cut off. */
    .piie-mark-question,
    .piie-mark-answer { overflow-wrap: anywhere; word-break: break-word; }
    .piie-mark-question { margin-bottom: .75rem; }
    .piie-mark-answer {
        background: #f8f9fa; border: 1px solid #e9ecef; border-radius: .375rem;
        padding: .625rem .75rem; margin-bottom: .75rem;
    }

    /* Long stored values (a raw payload for diagnosis) must not widen the page. */
    .piie-mark-dump { display: block; max-width: 100%; white-space: pre-wrap; overflow-wrap: anywhere; }

    /* The controls reflow as one group at wide widths, and stack below 576px. */
    .piie-mark-controls { display: flex; flex-wrap: wrap; gap: .625rem; align-items: flex-end; }
    .piie-mark-field { flex: 1 1 8rem; min-width: 0; }
    .piie-mark-field-feedback { flex: 2 1 16rem; min-width: 0; }
    .piie-mark-actions { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
    .piie-mark-actions .eForm-control { min-width: 6rem; }

    @media (max-width: 575.98px) {
        .piie-mark-field, .piie-mark-field-feedback { flex: 1 1 100%; }
    }
</style>

{{-- ═══ 1. THE SUMMARY ═══════════════════════════════════════════════════════ --}}
<div class="eSection-wrap">
    <h6 class="mb-3">{{ get_phrase('Submissions') }}</h6>

    <div class="table-responsive">
        <table class="table eTable piie-results-summary">
            <thead><tr>
                <th>{{ get_phrase('Student') }}</th>
                <th>{{ get_phrase('Submitted') }}</th>
                <th>{{ get_phrase('Automatic') }}</th>
                <th>{{ get_phrase('Manual') }}</th>
                <th>{{ get_phrase('Total') }}</th>
                <th>{{ get_phrase('Maximum') }}</th>
                <th>{{ get_phrase('Pending Decisions') }}</th>
                <th>{{ get_phrase('Status') }}</th>
                <th>{{ get_phrase('Action') }}</th>
            </tr></thead>
            <tbody>
            @forelse($submissions as $i => $submission)
                @php
                    $totalMarks = $submission->result_total_marks;
                    $summary = \App\Support\OnlineExams\OnlineExamMarking::summary($submission);
                    $score = (float) ($summary['score'] ?? $submission->effective_score ?? 0);

                    /**
                     * THE COMPLETION SIGNAL IS THE QUESTION SET, NOT `summary()['pending']`.
                     *
                     * `summary()` counts only manual questions that HAVE a response and
                     * are not yet marked, so a blank manual question was invisible to it
                     * and a paper containing one could report "marking complete" the
                     * moment a student submitted. `manualQuestionsAwaitingDecision()`
                     * includes blanks, because a blank is something a MARKER must decide
                     * - the honest decision being an explicit recorded zero.
                     */
                    $undecidedRows = \App\Support\OnlineExams\OnlineExamMarking::manualQuestionsAwaitingDecision($submission);
                    $undecidedCount = count($undecidedRows);
                    $undecidedMarks = (float) \App\Support\OnlineExams\OnlineExamMarking::undecidedManualMarks($submission);
                    $blankCount = count(array_filter($undecidedRows, static fn ($r) => $r['answered'] === false));

                    $isFinalized = $submission->isFinalized();
                    $isPublished = $submission->isResultVisible();
                    $reviewState = $submission->result_review_state ?: 'not_ready';

                    /**
                     * THE ANOMALOUS STATE, DETECTED RATHER THAN ASSUMED IMPOSSIBLE.
                     *
                     * `finalized` + a review state other than `pending_review` is what the
                     * old student-submit path wrote: marking declared complete without
                     * ever reaching staff. It is unrepairable without help, so it is
                     * labelled and offered a repair rather than left rendering as an
                     * unexplained dead end.
                     */
                    $isOrphanFinalized = $isFinalized
                        && ! $isPublished
                        && $reviewState !== 'pending_review';

                    $markingLabel = match (true) {
                        $isOrphanFinalized => 'Marking never handed over',
                        $isPublished => 'Result published',
                        $reviewState === 'pending_review' => 'Marked — awaiting Admin review',
                        $reviewState === 'returned_for_correction' => 'Returned by Admin for correction',
                        $undecidedCount > 0 => 'Awaiting marking',
                        default => 'Marking complete',
                    };

                    $finalizationLabel = match (true) {
                        $isPublished => 'Released to student',
                        $isOrphanFinalized => 'Needs repair',
                        $reviewState === 'pending_review' => 'With Admin',
                        default => 'With lecturer',
                    };

                    $isSelected = $selectedSubmission && (int) $selectedSubmission->id === (int) $submission->id;
                @endphp
                <tr id="submission-{{ $submission->id }}" @if($isSelected) class="table-active" data-testid="selected-submission-row" @endif>
                    {{-- The student is a LINK, so selecting a paper is one click and the
                         marking section below re-renders for them. --}}
                    <td class="piie-col-student">
                        <a href="{{ route('teacher.online_exams.results', [$exam->id, 'submission' => $submission->id]) }}#piie-marking"
                           data-testid="inspect-submission-{{ $submission->id }}">
                            {{ optional($submission->student)->name ?? '—' }}
                        </a>
                        <div class="text-muted small">{{ get_phrase('Attempt') }} {{ $submission->attempt_no }}</div>
                    </td>
                    <td class="piie-col-num">{{ optional($submission->submitted_at)->format('d M Y H:i') ?? '—' }}</td>
                    <td class="piie-col-num">{{ number_format($summary['objective_score'], 2) }}</td>
                    <td class="piie-col-num">{{ number_format($summary['manual_score'], 2) }}</td>
                    <td class="piie-col-num">
                        {{ number_format($score, 2) }}
                        @if($totalMarks > 0)
                            <small class="text-muted d-block">{{ round(($score / $totalMarks) * 100, 2) }}%</small>
                        @endif
                    </td>
                    <td class="piie-col-num">{{ number_format($totalMarks, 2) }}</td>
                    {{-- Marks no marker has decided, and how many of those are BLANK.
                         Blank is called out separately because "0.00 / 20.00" with no
                         action is indistinguishable from a student who wrote nothing,
                         unless the page says so. --}}
                    <td>
                        @if($undecidedCount > 0)
                            <span class="badge bg-warning text-dark" data-testid="undecided-count">{{ $undecidedCount }}</span>
                            <div class="text-muted small">{{ number_format($undecidedMarks, 2) }} {{ get_phrase('marks') }}</div>
                            @if($blankCount > 0)
                                <div class="text-muted small">{{ $blankCount }} {{ get_phrase('not answered') }}</div>
                            @endif
                        @else
                            <span class="text-muted">0</span>
                        @endif
                    </td>
                    <td data-testid="marking-status">
                        {{ get_phrase($markingLabel) }}
                        <div class="text-muted small">{{ get_phrase($finalizationLabel) }}</div>
                        <div class="text-muted small">
                            {{ $isPublished ? get_phrase('Released') : get_phrase('Not Released') }}
                        </div>
                    </td>
                    <td>
                        {{--
                             ONE ACTION PER STATE, ALWAYS. There is deliberately no branch
                             that renders nothing: an unexplained empty Actions column was
                             the reported defect, and it is only reachable by having a
                             state with no action in it.

                             The marking work itself has MOVED OUT of this cell into the
                             full-width section below. A number input does not belong in
                             a summary column, and its presence there is what made this
                             table overflow.
                        --}}
                        @if($isPublished)
                            <a class="eBtn eBtn-sm eBtn-success" data-testid="action-view-published"
                               href="{{ route('teacher.online_exams.results', [$exam->id, 'submission' => $submission->id]) }}#piie-marking">
                                {{ get_phrase('View Marked Paper') }}
                            </a>

                        @elseif($isOrphanFinalized)
                            {{-- THE ANOMALY. Named, explained, and escalated.
                                 The lecturer is NOT offered the repair: reopening is an
                                 administrator action, because no lecturer route may undo a
                                 state transition. Saying so, rather than showing a button
                                 that is not theirs, is what stops this looking like a dead
                                 end. --}}
                            <span class="badge bg-danger" data-testid="action-escalate">
                                {{ get_phrase('Escalate to administrator') }}
                            </span>

                        @elseif($reviewState === 'pending_review')
                            <span class="badge bg-warning text-dark" data-testid="action-awaiting-admin">{{ get_phrase('Awaiting Admin Review') }}</span>

                        @elseif($reviewState === 'returned_for_correction')
                            <a class="eBtn eBtn-sm eBtn-danger" data-testid="action-review-correction"
                               href="{{ route('teacher.online_exams.results', [$exam->id, 'submission' => $submission->id]) }}#piie-marking">
                                {{ get_phrase('Review and Correct Marks') }}
                            </a>

                        @elseif($undecidedCount > 0)
                            <a class="eBtn eBtn-sm eBtn-primary" data-testid="action-mark-now"
                               href="{{ route('teacher.online_exams.results', [$exam->id, 'submission' => $submission->id]) }}#piie-marking">
                                {{ trans_choice(
                                    '{1} Mark :count outstanding question|[2,*] Mark :count outstanding questions',
                                    $undecidedCount,
                                    ['count' => $undecidedCount]
                                ) }}
                            </a>

                        @else
                            @can('grade', $submission)
                                <form method="POST" action="{{ route('teacher.online_exams.results.finalize', $submission->id) }}" class="d-inline">
                                    @csrf
                                    <button class="eBtn eBtn-sm eBtn-primary" type="submit" data-testid="action-submit-for-review"
                                            title="{{ get_phrase('Hand this completed marking to an administrator. The student sees nothing until an administrator publishes the result.') }}">
                                        {{ get_phrase('Submit Marks for Admin Review') }}
                                    </button>
                                </form>
                            @else
                                <span class="text-muted small">{{ get_phrase('You may not submit this result.') }}</span>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted">{{ get_phrase('No results found') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $submissions->links() }}
</div>

{{-- ═══ 2. THE MARKING SECTION ══════════════════════════════════════════════ --}}
@if($selectedSubmission)
<div class="eSection-wrap piie-marking" id="piie-marking">
    <div class="piie-marking-header">
        <div>
            <h6 class="mb-1">{{ get_phrase('Marking') }}: {{ optional($selectedSubmission->student)->name ?? '—' }}</h6>
            <div class="text-muted small">
                {{ get_phrase('Attempt') }} {{ $selectedSubmission->attempt_no }}
                &middot;
                {{ get_phrase('Submitted') }} {{ optional($selectedSubmission->submitted_at)->format('d M Y H:i') ?? '—' }}
                &middot;
                {{ get_phrase('Status') }}: {{ get_phrase($selectedSubmission->isResultVisible() ? 'Result published' : 'Open for marking') }}
            </div>
        </div>

        <div class="piie-mark-actions">
            @if($markingOpen && count($selectedUndecided) === 0)
                {{-- HANDOVER. Offered only when every decision is made, so it cannot
                     be pressed into a refusal. Only an administrator publishes after
                     this - a lecturer can hand over, never release. --}}
                @can('grade', $selectedSubmission)
                    <form method="POST" action="{{ route('teacher.online_exams.results.finalize', $selectedSubmission->id) }}">
                        @csrf
                        <button class="eBtn eBtn-primary" type="submit" data-testid="marking-submit-for-review"
                                title="{{ get_phrase('Hand this completed marking to an administrator. The student sees nothing until an administrator publishes the result.') }}">
                            {{ get_phrase('Submit Marks for Admin Review') }}
                        </button>
                    </form>
                @else
                    <span class="text-muted small">{{ get_phrase('You may not submit this result.') }}</span>
                @endcan
            @endif
        </div>
    </div>

    @if(! $markingOpen)
        {{-- WHY THE CONTROLS ARE ABSENT, rather than absent and unexplained. --}}
        <div class="alert alert-secondary" data-testid="marking-closed-notice">
            @if($selectedSubmission->isResultVisible())
                {{ get_phrase('This result has been published to the student, so its marking is closed and can no longer be changed. The submitted answers remain visible below.') }}
            @elseif($selectedSubmission->isFinalized())
                {{ get_phrase('This result has been handed to an administrator and its marking is closed. The submitted answers remain visible below.') }}
            @else
                {{ get_phrase('This attempt is not open for marking. The submitted answers remain visible below.') }}
            @endif
        </div>
    @elseif(count($selectedUndecided) > 0)
        <div class="alert alert-warning" data-testid="outstanding-explanation">
            {{ trans_choice(
                '{1} :count question still requires a marking decision. Complete marking before submitting for administrative review.|[2,*] :count questions still require a marking decision. Complete marking before submitting for administrative review.',
                count($selectedUndecided),
                ['count' => count($selectedUndecided)]
            ) }}
        </div>
    @endif

    @forelse($markingCards as $index => $card)
        @php
            $question = $card['question'];
            $answerRow = $card['answer'];
            $isAutomatic = $card['automatic'];
            $hasResponse = $card['has_response'];
            $isDecided = $card['decided'];

            /**
             * THE CARD'S STATE, DECIDED ONCE AND SHOWN IN THREE PLACES.
             *
             * A marker must be able to tell, without counting columns, whether a
             * question is done. Three states, because they mean different things:
             *
             *   automatic  — the engine decided; there is nothing for a marker to do.
             *   decided    — a marker recorded a mark against it.
             *   undecided  — a marker's decision is still owed. That includes a
             *                question the student LEFT BLANK, which is a decision
             *                (an explicit zero), never an automatic pass.
             */
            $cardState = match (true) {
                $isAutomatic => 'automatic',
                $isDecided => 'decided',
                default => 'undecided',
            };

            // A row that exists but stored nothing is EVIDENCE of an autosave
            // failure, and must not be rendered as an ordinary blank answer.
            $storedEmpty = $answerRow !== null && ! $hasResponse;

            $canMarkThis = $markingOpen && ! $isAutomatic;
        @endphp

        <div class="piie-mark-card is-{{ $cardState }} @if(! $hasResponse && ! $isAutomatic) is-blank @endif"
             data-testid="marking-card"
             data-question-id="{{ $question->id }}">

            <div class="piie-mark-card-head">
                <span class="piie-mark-qno">{{ get_phrase('Question') }} {{ $index + 1 }}</span>
                <span class="badge bg-secondary" data-testid="marking-card-type">{{ strtoupper(str_replace('_', ' ', $question->type)) }}</span>
                <span class="badge bg-light text-dark border" data-testid="marking-card-max">
                    {{ $question->marks }} {{ get_phrase('mark(s)') }}
                </span>

                {{-- THE MARKING STATE, STATED PLAINLY. --}}
                <span class="ms-auto">
                    @if($isAutomatic)
                        <span class="badge bg-info" data-testid="mark-state">{{ get_phrase('Marked automatically') }}</span>
                    @elseif($isDecided)
                        <span class="badge bg-success" data-testid="mark-state">
                            {{ get_phrase('Saved') }} &middot; {{ number_format((float) $answerRow->awarded_marks, 2) }}
                        </span>
                    @else
                        <span class="badge bg-warning text-dark" data-testid="mark-state">{{ get_phrase('Unmarked') }}</span>
                    @endif
                </span>
            </div>

            {{-- THE QUESTION, IN FULL. `prosePromptOrEmptyLabel()` never returns an
                 empty string, so a card can never be anonymous. --}}
            <div class="piie-prose piie-mark-question" data-testid="marking-card-question">
                {!! $question->prosePromptOrEmptyLabel() !!}
            </div>

            {{-- THE STUDENT'S EXACT SAVED ANSWER. --}}
            <div class="piie-prose piie-mark-answer" data-testid="marking-card-answer">
                @if($answerRow === null)
                    <span class="text-danger" data-testid="no-answer-notice">
                        {{ get_phrase('No answer was submitted for this question.') }}
                    </span>
                @elseif($hasResponse)
                    {!! $answerRow->answer_text ? $answerRow->proseAnswer() : e($answerRow->selected_option) !!}
                @else
                    {{-- A ROW THAT EXISTS BUT STORED NOTHING.

                         TWO THINGS ARE TRUE AT ONCE here, and a marker needs both.

                         First, the plain fact: no answer was submitted, which is why the
                         only mark available is an explicit zero. Second, the diagnosis:
                         the student DID open and save this question, so an empty stored
                         value is an autosave failure rather than a blank paper.

                         Rendering only the second would let a marker record a zero for
                         what looks like an untouched question and hide a real defect;
                         rendering only the first would blame a student for the engine's
                         failure. So both are stated, and the raw bytes are shown because
                         they are what an administrator needs to diagnose it. --}}
                    <span class="text-danger d-block" data-testid="stored-empty-answer">
                        {{ get_phrase('No answer was submitted for this question.') }}
                    </span>
                    <span class="d-block text-danger small mt-1">
                        {{ get_phrase('Nothing was stored for this answer, although the student opened and saved this question. This indicates an autosave failure rather than a blank submission.') }}
                    </span>
                    <span class="d-block text-muted small mt-1">
                        {{ get_phrase('Stored value') }}:
                        <code class="piie-mark-dump">{{ var_export($answerRow->answer_text, true) }}</code>
                    </span>
                @endif
            </div>

            @if($storedEmpty && $answerRow->awarded_marks !== null)
                <div class="small text-warning mb-2" data-testid="mark-against-empty-answer">
                    {{ trans_choice(
                        '{1} :count mark has already been awarded against this empty answer. Correct it, or return the result for correction.',
                        '[2,*] :count marks have already been awarded against this empty answer. Correct them, or return the result for correction.',
                        ['count' => (int) $answerRow->awarded_marks]
                    ) }}
                </div>
            @endif

            @if($canMarkThis)
                @if($hasResponse)
                    <form method="POST"
                          action="{{ route('teacher.online_exams.submissions.record_decision', [
                              'submission' => $selectedSubmission->id,
                              'question' => $question->id,
                          ]) }}"
                          data-testid="decision-form"
                          data-marking-form="1">
                        @csrf
                        <div class="piie-mark-controls">
                            <div class="piie-mark-field">
                                <label class="form-label mb-1" for="awarded-{{ $question->id }}">
                                    {{ get_phrase('Awarded marks') }}
                                    <span class="text-muted">(0–{{ $question->marks }})</span>
                                </label>
                                <input class="form-control eForm-control" id="awarded-{{ $question->id }}"
                                       type="number" step="0.01" min="0" max="{{ $question->marks }}"
                                       name="awarded_marks"
                                       value="{{ old('awarded_marks', $answerRow->awarded_marks) }}"
                                       data-testid="awarded-marks">
                            </div>

                            <div class="piie-mark-field-feedback">
                                <label class="form-label mb-1" for="feedback-{{ $question->id }}">
                                    {{ get_phrase('Feedback') }} <span class="text-muted">({{ get_phrase('optional') }})</span>
                                </label>
                                <input class="form-control eForm-control" id="feedback-{{ $question->id }}"
                                       type="text" maxlength="2000" name="teacher_comment"
                                       value="{{ old('teacher_comment', $answerRow->teacher_comment) }}"
                                       placeholder="{{ get_phrase('Optional note for the student') }}"
                                       data-testid="marking-feedback">
                            </div>

                            <div>
                                <button class="eBtn eBtn-primary" type="submit" data-testid="save-mark">
                                    {{ get_phrase('Save Mark') }}
                                </button>
                            </div>
                        </div>
                    </form>
                @else
                    {{-- NO ANSWER WAS SUBMITTED. The only mark that can be honestly
                         recorded is zero, so the control says so and offers nothing
                         else. The fact that nothing was submitted is preserved on the
                         record; a zero here is a DECISION about a blank, not an answer.

                         THE HIDDEN ZERO IS LOAD-BEARING. Without a marking input this
                         button POSTed only the CSRF token, the controller requires
                         `awarded_marks`, and the control could never work. Laravel's
                         `required` accepts numeric zero, so this is a valid decision
                         rather than a missing one. --}}
                    <form method="POST"
                          action="{{ route('teacher.online_exams.submissions.record_decision', [
                              'submission' => $selectedSubmission->id,
                              'question' => $question->id,
                          ]) }}"
                          data-testid="decision-form"
                          data-marking-form="1">
                        @csrf
                        <input type="hidden" name="awarded_marks" value="0">
                        <input type="text" name="teacher_comment" class="form-control eForm-control mb-2"
                               maxlength="2000" placeholder="{{ get_phrase('Optional note for the student') }}"
                               value="{{ old('teacher_comment', $answerRow?->teacher_comment) }}">
                        <button class="eBtn eBtn-warning" type="submit" data-testid="record-zero"
                                title="{{ get_phrase('Records a marking decision of 0 marks, attributed to you. No marks can be awarded because no answer was submitted.') }}">
                            {{ get_phrase('Record 0 — No Answer Submitted') }}
                        </button>
                    </form>
                @endif
            @elseif(! $isAutomatic && ! $isDecided)
                <div class="small text-muted" data-testid="mark-unavailable">
                    {{ get_phrase('This question still needs a marking decision, but marking is closed for this attempt.') }}
                </div>
            @endif

            @if($answerRow?->teacher_comment && $canMarkThis)
                <div class="small text-muted mt-2" data-testid="existing-feedback">
                    {{ get_phrase('Saved feedback') }}: {{ $answerRow->teacher_comment }}
                </div>
            @endif
        </div>
    @empty
        <div class="alert alert-secondary">{{ get_phrase('No results found') }}</div>
    @endforelse
</div>
@endif
@endsection