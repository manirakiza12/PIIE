@extends('admin.navigation')

@section('content')

{{--
    THE ADMINISTRATOR'S RESULTS SCREEN FOR ONE EXAMINATION.

    ── WHY PUBLICATION STATES ITS BLOCKERS HERE ────────────────────────────
    Exam 17 submission 12 returned a bare `422 Unprocessable Content` when an
    administrator pressed "Approve & Publish Result". The cause was a correct rule —
    the paper's `after_exam_end` window had not opened — but nothing on this screen
    said so, so a scheduling policy presented as a broken endpoint.

    `OnlineExamPublication::blockers()` is the single definition both this screen and
    the publish action ask, so the two cannot disagree: the button is offered only
    when publication will actually succeed, and when it is not, the reason appears
    here in the same words the redirect would have used.

    NOTE ON BLADE: this file was previously assembled by repeated in-place edits and
    accumulated `@endif@if` sequences. Blade's directive pattern requires a
    non-word-boundary before `@`, so `@endif@if(...)` silently fails to compile the
    second directive and leaves a stray `endif`. Every directive is therefore on its
    own line or separated by whitespace.
--}}

<div class="mainSection-title"><div class="row"><div class="col-12 d-flex justify-content-between align-items-center">
    <h4>{{ get_phrase('Results') }}: {{ $exam->title }}</h4>
    <a class="export_btn" href="{{ route('admin.online_exams.index') }}">{{ get_phrase('Back to Exams') }}</a>
</div></div></div>

@if (session('success'))
    <div class="alert alert-success" data-testid="admin-flash-success">{{ session('success') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-danger" data-testid="admin-flash-errors">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<style>
    .online-exam-admin-results-table { min-width: 1400px; }
    .online-exam-admin-results-table th,
    .online-exam-admin-results-table td { vertical-align: middle; white-space: nowrap; }
    .online-exam-admin-actions { min-width: 340px; white-space: normal !important; }
</style>

<div class="eSection-wrap">
    <div class="table-responsive online-exam-results-wrap">
        <table class="table eTable online-exam-admin-results-table">
            <thead>
            <tr>
                <th>#</th>
                <th>{{ get_phrase('Student') }}</th>
                <th>{{ get_phrase('Submitted') }}</th>
                <th>{{ get_phrase('Automatic') }}</th>
                <th>{{ get_phrase('Manual') }}</th>
                <th>{{ get_phrase('Total') }}</th>
                <th>{{ get_phrase('Maximum') }}</th>
                <th>{{ get_phrase('Awaiting Decision') }}</th>
                <th>{{ get_phrase('Marking Status') }}</th>
                <th>{{ get_phrase('Review') }}</th>
                <th>{{ get_phrase('Release') }}</th>
                <th>{{ get_phrase('Actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($submissions as $i => $submission)
                @php
                    $summary = \App\Support\OnlineExams\OnlineExamMarking::summary($submission);

                    /**
                     * "AWAITING DECISION" INCLUDES ANSWERLESS QUESTIONS.
                     *
                     * `summary()['pending']` counts only a manual question that HAS a
                     * response and is not yet marked, so it reported zero for a paper
                     * whose written question had been left empty — which is how exam 19
                     * submission 13 claimed to be fully marked when no human had judged
                     * it. This uses the same question-level definition the marking
                     * queue and the publication guard use, so all three agree.
                     */
                    $undecidedRows = \App\Support\OnlineExams\OnlineExamMarking::manualQuestionsAwaitingDecision($submission);
                    $undecidedCount = count($undecidedRows);
                    $undecidedMarks = \App\Support\OnlineExams\OnlineExamMarking::undecidedManualMarks($submission);
                    $blankCount = count(array_filter($undecidedRows, static fn ($row) => $row['answered'] === false));

                    $finalized = $submission->isFinalized();
                    $published = $submission->isResultVisible();
                    $reviewState = $submission->result_review_state ?: ($finalized ? 'pending_review' : 'not_ready');

                    /**
                     * `finalized` with a review state other than `pending_review` is
                     * only producible by the old student-submit path: marking declared
                     * complete and never handed to anybody. Such a row sits in no queue
                     * and admits no lecturer action, so it is labelled and offered to an
                     * administrator for repair.
                     */
                    $isOrphanFinalized = $finalized && ! $published && $reviewState !== 'pending_review';

                    $markingComplete = $undecidedCount === 0;

                    $markingLabel = $isOrphanFinalized
                        ? 'Closed without handover'
                        : ($undecidedCount > 0
                            ? 'Awaiting Marking'
                            : ($reviewState === 'published'
                                ? 'Result published'
                                : ($reviewState === 'pending_review'
                                    ? 'Pending Admin Review'
                                    : ($reviewState === 'returned_for_correction'
                                        ? 'Returned for Correction'
                                        : 'Marking Complete'))));

                    /**
                     * ONE DEFINITION, ASKED ONCE. The button is offered only when
                     * publication will actually succeed; otherwise every blocker is
                     * listed, so an administrator never presses a button that can only
                     * fail.
                     */
                    $publicationBlockers = \App\Support\OnlineExams\OnlineExamPublication::blockers($submission);
                    $canPublish = $publicationBlockers === [];

                    $readyToFinalize = $markingComplete
                        && in_array($submission->status, ['submitted', 'timed_out', 'pending_manual_marking'], true);
                @endphp

                <tr id="submission-{{ $submission->id }}"
                    class="{{ ($highlightSubmissionId ?? 0) === (int) $submission->id ? 'table-warning' : '' }}">
                    <td>{{ $submissions->firstItem() + $i }}</td>
                    <td>{{ optional($submission->student)->name ?? '—' }}</td>
                    <td>{{ optional($submission->submitted_at)->format('d M Y H:i') ?? '—' }}</td>
                    <td>{{ number_format($summary['objective_score'], 2) }}</td>
                    <td>{{ number_format($summary['manual_score'], 2) }}</td>
                    <td>{{ number_format($summary['score'], 2) }}</td>
                    <td>{{ number_format($submission->result_total_marks, 2) }}</td>

                    <td>
                        @if ($undecidedCount > 0)
                            <span class="badge bg-warning text-dark">{{ $undecidedCount }}</span>
                            <div class="text-muted small" data-testid="undecided-marks">
                                {{ number_format($undecidedMarks, 2) }} {{ get_phrase('marks') }}
                                @if ($blankCount > 0)
                                    &middot; {{ $blankCount }} {{ get_phrase('not answered') }}
                                @endif
                            </div>
                        @else
                            <span class="text-muted">0</span>
                        @endif
                    </td>

                    <td>{{ get_phrase($markingLabel) }}</td>
                    {{-- Never the raw workflow token. `not_ready` is an internal state, not something an
             administrator can act on; see OnlineExamSubmission::$resultReviewStateLabel. --}}
    <td>{{ $submission->result_review_state_label }}</td>
                    <td>
                        {{ $published ? get_phrase('Released') : get_phrase('Not Released') }}
                        @if ($published && $submission->published_by)
                            <div class="text-muted small">
                                {{ get_phrase('Released by') }} #{{ $submission->published_by }}
                                @if ($submission->published_at)
                                    &middot; {{ $submission->published_at->format('d M Y H:i') }}
                                @endif
                            </div>
                        @endif
                    </td>

                    <td class="online-exam-admin-actions">
                        <a class="eBtn eBtn-sm eBtn-info"
                           href="{{ route('admin.online_exams.proctoring.review', ['id' => $exam->id, 'submission' => $submission->id]) }}">
                            {{ get_phrase('Review') }}
                        </a>

                        @can('grade', $submission)
                            @if ($readyToFinalize)
                                <form method="POST" action="{{ route('admin.online_exams.submissions.finalize', $submission->id) }}" class="d-inline">
                                    @csrf
                                    <button class="eBtn eBtn-sm eBtn-primary" type="submit">{{ get_phrase('Finalize Result') }}</button>
                                </form>
                            @endif

                            {{-- THE ANOMALY: repaired by an administrator only. No
                                 lecturer route may reopen a state transition. --}}
                            @if ($isOrphanFinalized)
                                <form method="POST" action="{{ route('admin.online_exams.submissions.return_to_marking', $submission->id) }}" class="d-inline" data-testid="action-reopen-marking">
                                    @csrf
                                    <input type="hidden" name="reason" value="{{ get_phrase('Closed by the student without handover; reopened for marking.') }}">
                                    <button class="eBtn eBtn-sm eBtn-danger" type="submit"
                                            title="{{ get_phrase('This attempt was closed without ever being handed over, so no marker judged it. Reopening returns every undecided question to the marking queue. It awards nothing and invents no answer.') }}">
                                        {{ get_phrase('Reopen for Marking') }}
                                    </button>
                                </form>
                            @endif

                            @if ($finalized && $reviewState === 'pending_review')
                                <form method="POST" action="{{ route('admin.online_exams.submissions.return', $submission->id) }}" class="d-inline">
                                    @csrf
                                    <button class="eBtn eBtn-sm eBtn-warning" type="submit">{{ get_phrase('Return for Correction') }}</button>
                                </form>

                                @if ($canPublish)
                                    <form method="POST" action="{{ route('admin.online_exams.submissions.publish_result', $submission->id) }}" class="d-inline" data-testid="publish-result">
                                        @csrf
                                        <button class="eBtn eBtn-sm eBtn-primary" type="submit">{{ get_phrase('Approve & Publish Result') }}</button>
                                    </form>
                                @else
                                    {{-- THE REASON IS STATED BEFORE ANYONE CLICKS.

                                         These are the same blockers the publish action
                                         enforces, from the same single definition, so
                                         this screen cannot offer something the action
                                         would refuse. --}}
                                    <div class="text-warning small mb-1" data-testid="publication-blocked">{{ get_phrase('Cannot publish yet:') }}</div>
                                    <ul class="text-warning small mb-2 ps-3" style="list-style:disc;">
                                        @foreach ($publicationBlockers as $blocker)
                                            <li>{{ $blocker['message'] }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            @endif

                            @if ($published)
                                <span class="badge bg-success">{{ get_phrase('Released') }}</span>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="text-center text-muted py-4">{{ get_phrase('No results found') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $submissions->links() }}
</div>

@endsection