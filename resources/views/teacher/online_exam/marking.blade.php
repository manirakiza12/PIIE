@extends('teacher.navigation')

@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12 d-flex justify-content-between align-items-center">
    <h4>{{ get_phrase('Marking Queue') }}</h4>
    <a class="export_btn" href="{{ route('teacher.online_exams.index') }}">{{ get_phrase('Back') }}</a>
</div></div></div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<style>
    .online-exam-marking-table { min-width: 1180px; }
    .online-exam-marking-table th, .online-exam-marking-table td { vertical-align: middle; }
    .online-exam-marking-actions { min-width: 280px; }
    .online-exam-marking-actions form { flex-wrap: wrap; }
    .online-exam-summary { background: #f8fafc; }
    .online-exam-summary .badge { font-weight: 500; }
</style>

@if($awaitingDecision->isNotEmpty())
    {{-- QUESTIONS ON A SUBMITTED PAPER THAT NO MARKER HAS DECIDED.

         Separate from the award queue below, which lists answers that have something
         to READ. A blank manual question has nothing to read, so it never appears
         there - but it is still a question a marker must DECIDE, and until it is
         decided the paper cannot be handed over. Without this panel such a question
         had nowhere to go, and the paper stayed stuck: exam 17 submission 12 sat as
         `finalized` with an empty Actions column because a 10-mark short answer had
         no answer row at all.

         The control records an EXPLICIT ZERO and nothing else. A mark above zero for
         an answer that does not exist would be fabricating a result, so the server
         refuses it as well as the form not offering it.

         Rendered as TWO groups over the same rows — recent work and a carried-over
         backlog — because a marker cannot triage a list where last term's paper sits
         above today's. Nothing is merged and nothing is removed: both groups are
         complete and every row still names its submission. --}}
    @foreach([
        ['rows' => $awaitingDecisionRecent, 'testid' => 'awaiting-decision-recent', 'note' => get_phrase('Submitted in the last :days days.', ['days' => \App\Http\Controllers\OnlineExamController::MARKING_BACKLOG_DAYS])],
        ['rows' => $awaitingDecisionBacklog, 'testid' => 'awaiting-decision-backlog', 'note' => get_phrase('Carried over from more than :days days ago. These papers have been waiting for a decision — they are not new work.', ['days' => \App\Http\Controllers\OnlineExamController::MARKING_BACKLOG_DAYS])],
    ] as $group)
        @if($group['rows']->isNotEmpty())
    <div class="eSection-wrap mb-3">
        <div class="title mb-3 pb-0 border-0">
            <h3 class="mb-0" data-testid="{{ $group['testid'] }}">
                {{ get_phrase('Submitted — questions awaiting your decision') }}
                <span class="badge bg-secondary">{{ $group['rows']->count() }}</span>
            </h3>
            <p class="text-muted small mb-0">{{ $group['note'] }}</p>
            <p class="text-muted small mb-0">
                {{ get_phrase('A question a student left blank still needs a decision. Record 0 and it is settled; the decision is stored with your name against it.') }}
            </p>
        </div>
        <div class="table-responsive">
            <table class="table eTable align-middle">
                <caption class="visually-hidden">
                    Submitted questions that still need a marker's decision,
                    including questions the student left blank.
                </caption>
                <thead>
                <tr>
                    <th>{{ get_phrase('Submission') }}</th>
                    <th>{{ get_phrase('Student') }}</th>
                    <th>{{ get_phrase('Exam') }}</th>
                    <th>{{ get_phrase('Question') }}</th>
                    <th>{{ get_phrase('What the student wrote') }}</th>
                    <th class="text-end">{{ get_phrase('Outstanding') }}</th>
                    <th class="text-end">{{ get_phrase('Action') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($group['rows'] as $pendingSubmission)
                    @php
                        $pendingOffering = $pendingSubmission->exam->courseOffering ?? null;
                        $pendingRows = \App\Support\OnlineExams\OnlineExamMarking::manualQuestionsAwaitingDecision($pendingSubmission);
                        $pendingOutstanding = \App\Support\OnlineExams\OnlineExamMarking::undecidedManualMarks($pendingSubmission);
                    @endphp
                    @foreach($pendingRows as $pendingRow)
                        @php
                            $answerOfPending = $pendingRow['answer'];
                            $answered = $pendingRow['answered'];
                        @endphp
                        <tr data-testid="awaiting-decision-row">
                            {{-- The attempt number, so this paper can be named to the
                                 academic office and found exactly. Separate attempts are
                                 never merged, and this is what keeps them distinguishable. --}}
                            <td>
                                <code>#{{ $pendingSubmission->id }}</code>
                                <div class="text-muted small">
                                    {{ get_phrase('Attempt') }} {{ $pendingSubmission->attempt_no }}<br>
                                    {{ optional($pendingSubmission->submitted_at)->format('d M Y') ?? '—' }}
                                </div>
                            </td>
                            <td>{{ optional($pendingSubmission->student)->name ?? '—' }}</td>
                            <td>
                                {{ $pendingSubmission->exam->title ?? '—' }}
                                <div class="text-muted small">
                                    @if($pendingOffering)
                                        {{ $pendingOffering->reference ?? ('Offering #'.$pendingOffering->id) }}
                                    @else
                                        {{ get_phrase('Legacy - no course offering') }}
                                    @endif
                                </div>
                            </td>
                            <td class="piie-prose">{!! $pendingRow['question']->prosePromptOrEmptyLabel() !!}</td>
                            <td>
                                @if($answered)
                                    {!! $answerOfPending->proseAnswer() !!}
                                @else
                                    <span class="text-danger" data-testid="blank-notice">
                                        {{ get_phrase('Not answered — record 0 to settle this question') }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-end">{{ $pendingRow['question']->marks }}</td>
                            <td class="text-end">
                                {{-- A REAL LINK TO THE EXACT SUBMISSION, ALWAYS.
                                     The previous text — "Decide on the submissions page" —
                                     was a dead end: it named no submission and gave a
                                     marker nothing to click, so for a question the
                                     student left blank there was NO way to record the
                                     decision at all. The outcome was a lecturer who
                                     could see 1 outstanding question, had no way to
                                     clear it, and could only press "Submit Marks for
                                     Admin Review" to be met with a 422.
                                     Named routes, no hardcoded host. --}}
                                <a class="eBtn eBtn-sm eBtn-primary"
                                   data-testid="mark-submission-link"
                                   href="{{ route('teacher.online_exams.results', [
                                       'exam' => $pendingSubmission->online_exam_id,
                                       'submission' => $pendingSubmission->id,
                                   ]) }}#submission-{{ $pendingSubmission->id }}">
                                    {{ get_phrase('Mark Submission') }}
                                </a>
                            </td>
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
        @endif
    @endforeach
@endif

@if($readyForHandover->isNotEmpty())
    {{-- MARKING IS DONE; THE SUBMISSION STILL HAS TO GO SOMEWHERE.
         Deliberately a separate block from the award table below. The handover used
         to live inside that table's summary row, so awarding the final mark made the
         row stop matching the queue's default filter and the control disappeared -
         leaving a lecturer who had finished the whole paper with an empty queue and
         no way to submit it. Marking and handing over are different acts; they now
         have different surfaces.

         This hands the completed marking to an administrator. It does NOT release
         anything to the student: only `publishResult()`, which refuses anyone who is
         not an administrator, can do that. --}}
    <div class="eSection-wrap mb-3">
        <div class="title mb-3 pb-0 border-0">
            <h3 class="mb-0" data-testid="ready-for-handover">
                {{ get_phrase('Marking complete — ready to submit for review') }}
            </h3>
        </div>
        <div class="table-responsive">
            <table class="table eTable align-middle">
                <caption class="visually-hidden">
                    Completed submissions that need handing to an administrator for
                    result review.
                </caption>
                <thead>
                <tr>
                    <th>{{ get_phrase('Student') }}</th>
                    <th>{{ get_phrase('Exam') }}</th>
                    <th>{{ get_phrase('Course Offering / Course Unit') }}</th>
                    <th>{{ get_phrase('Submitted') }}</th>
                    <th class="text-end">{{ get_phrase('Total') }}</th>
                    <th class="text-end">{{ get_phrase('Actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($readyForHandover as $handOver)
                    @php
                        $handOverSummary = \App\Support\OnlineExams\OnlineExamMarking::summary($handOver);
                        $handOverOffering = $handOver->exam->courseOffering ?? null;
                    @endphp
                    <tr>
                        <td>{{ optional($handOver->student)->name ?? '—' }}</td>
                        <td>{{ $handOver->exam->title ?? '—' }}</td>
                        <td>
                            @if($handOverOffering)
                                <div>{{ optional($handOver->exam->subject)->name ?? '—' }}</div>
                                <div class="text-muted small">{{ $handOverOffering->reference ?? ('Offering #'.$handOverOffering->id) }}</div>
                            @else
                                <div class="text-muted small">{{ optional($handOver->exam->classRoom)->name ?? get_phrase('Legacy - no course offering') }}</div>
                            @endif
                        </td>
                        <td>{{ optional($handOver->submitted_at)->format('d M Y H:i') ?? '—' }}</td>
                        <td class="text-end">
                            {{ number_format((float) $handOverSummary['score'], 2) }} / {{ number_format($handOver->result_total_marks, 2) }}
                        </td>
                        <td class="text-end">
                            @can('grade', $handOver)
                                <form method="POST" action="{{ route('teacher.online_exams.results.finalize', $handOver->id) }}" class="d-inline">
                                    @csrf
                                    <button class="eBtn eBtn-sm eBtn-primary" type="submit" title="{{ get_phrase('Hand this completed marking to an administrator. The student sees nothing until an administrator publishes the result.') }}">{{ get_phrase('Submit Marks for Admin Review') }}</button>
                                </form>
                            @else
                                <span class="text-muted small">{{ get_phrase('You may not submit this result.') }}</span>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="eSection-wrap mb-3">
    <form method="GET" action="{{ route('teacher.online_exams.marking') }}" class="d-flex gap-2">
        <select class="form-select eForm-select" name="status" style="max-width:220px;">
            <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>{{ get_phrase('Pending') }}</option>
            <option value="marked" {{ $status === 'marked' ? 'selected' : '' }}>{{ get_phrase('Marked') }}</option>
        </select>
        <button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Filter') }}</button>
    </form>
</div>

<div class="eSection-wrap">
    <div class="table-responsive online-exam-marking-wrap">
        <table class="table eTable online-exam-marking-table">
            <thead>
            <tr>
                <th>#</th><th>{{ get_phrase('Student') }}</th><th>{{ get_phrase('Exam') }}</th>
                <th>{{ get_phrase('Course Offering / Course Unit') }}</th>
                <th>{{ get_phrase('Submitted') }}</th>
                <th>{{ get_phrase('Question') }}</th><th>{{ get_phrase('Answer') }}</th><th>{{ get_phrase('Max') }}</th><th>{{ get_phrase('Awarded') }}</th><th>{{ get_phrase('Comment') }}</th><th>{{ get_phrase('Status') }}</th><th>{{ get_phrase('Action') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($answers as $index => $answer)
                @php
                    $submission = $answer->submission;
                    $summary = \App\Support\OnlineExams\OnlineExamMarking::summary($submission);
                    $pending = (int) $summary['pending'];
                    $readyToFinalize = $pending === 0 && in_array($submission->status, ['submitted', 'timed_out', 'pending_manual_marking'], true);
                    $markingComplete = $pending === 0 && in_array($submission->status, ['submitted', 'timed_out', 'finalized', 'result_published'], true);
                    $markingLabel = $pending > 0 ? 'Awaiting Marking' : ($submission->isFinalized() ? ($submission->isResultVisible() ? 'Result published' : 'Submitted for Admin Review') : ($markingComplete ? 'Marking Complete' : 'Ready to submit'));
                    $finalizationLabel = $submission->isFinalized() ? 'Submitted for Admin Review' : 'With Lecturer';
                    $releaseLabel = $submission->isResultVisible() ? 'Released' : 'Not Released';
                    // Which course this answer belongs to, for a paper that lives in
                    // a Course Offering. A legacy paper has none and says so, rather
                    // than showing a blank cell that reads as "unattached".
                    $offeringExam = $submission->exam->courseOffering ?? null;
                @endphp
                <tr>
                    <td>{{ $answers->firstItem() + $index }}</td>
                    <td>{{ optional($answer->submission->student)->name ?? '—' }}</td>
                    <td>{{ $answer->submission->exam->title ?? '—' }}</td>
                    {{-- The academic context a marker needs to judge fairly: a Course
                         Offering paper names its course, a legacy paper names its
                         class. Never blank, because blank reads as unattached. --}}
                    <td>
                        @if($offeringExam)
                            <div>{{ optional($submission->exam->subject)->name ?? '-' }}</div>
                            <div class="text-muted small">{{ $offeringExam->reference ?? ('Offering #'.$offeringExam->id) }}</div>
                        @else
                            <div class="text-muted small">{{ optional($submission->exam->classRoom)->name ?? get_phrase('Legacy - no course offering') }}</div>
                        @endif
                    </td>
                    <td>{{ optional($submission->submitted_at)->format('d M Y H:i') ?? '-' }}</td>
                    <td class="piie-prose">{!! $answer->question?->prosePromptOrEmptyLabel() !!}</td>
                    {{-- The marker's view of a written answer.

                         A student's working is now rich text - a line of
                         differentiation, a boxed final value - so an escaped render
                         would show the tags as literal characters and the marker
                         would be marking an unreadable transcript.

                         The OPTION LETTER is a different matter and stays escaped:
                         it is a single machine value chosen from a fixed set, and
                         there is no reason for it to be anything but text. Routing
                         one cell through two different renderers is deliberate, not
                         an oversight.

                         `proseAnswer()` filters on READ, not on write, because
                         `answer_text` is the words the student typed and is stored
                         untouched - see OnlineExamAnswer. --}}
                    <td class="piie-prose">{!! $answer->answer_text ? $answer->proseAnswer() : e($answer->selected_option ?: '-') !!}</td>
                    <td>{{ $answer->question->marks ?? 0 }}</td>
                    <td>{{ is_null($answer->awarded_marks) ? '—' : $answer->awarded_marks }}</td>
                    <td>{{ $answer->teacher_comment ?: '—' }}</td>
                    <td>{{ \App\Support\OnlineExams\OnlineExamMarking::isManuallyMarked($answer) ? 'Marked' : 'Pending' }}</td>
                    <td class="online-exam-marking-actions">
                        <form method="POST" action="{{ route('teacher.online_exams.answers.mark', $answer->id) }}" class="d-flex gap-1">
                            @csrf
                            <input type="hidden" name="answer_id" value="{{ $answer->id }}">
                            <input class="form-control eForm-control" type="number" step="0.01" min="0" max="{{ $answer->question->marks ?? 0 }}" name="awarded_marks" value="{{ old('awarded_marks', $answer->awarded_marks) }}" style="width:90px;">
                            <input class="form-control eForm-control" type="text" name="teacher_comment" value="{{ old('teacher_comment', $answer->teacher_comment) }}" placeholder="{{ get_phrase('Comment') }}">
                            <button class="eBtn eBtn-sm eBtn-primary" type="submit">{{ get_phrase('Save') }}</button>
                        </form>
                    </td>
                </tr>
                <tr class="online-exam-summary">
                    <td colspan="12">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <strong>{{ $submission->student->name ?? '—' }} · {{ $submission->exam->title ?? '—' }}</strong>
                            <span class="badge bg-light text-dark">{{ get_phrase('Automatic') }}: {{ number_format($summary['objective_score'], 2) }}</span>
                            <span class="badge bg-light text-dark">{{ get_phrase('Manual') }}: {{ number_format($summary['manual_score'], 2) }}</span>
                            <span class="badge bg-primary">{{ get_phrase('Total') }}: {{ number_format($summary['score'], 2) }} / {{ number_format($submission->result_total_marks, 2) }}</span>
                            <span class="badge {{ $pending ? 'bg-warning text-dark' : 'bg-success' }}">{{ get_phrase('Pending') }}: {{ $pending }}</span>
                            <span class="badge bg-secondary">{{ get_phrase('Marking') }}: {{ $markingLabel }}</span>
                            <span class="badge bg-secondary">{{ get_phrase('With') }}: {{ $finalizationLabel }}</span>
                            <span class="badge bg-secondary">{{ get_phrase('Release') }}: {{ $releaseLabel }}</span>
                            @if($readyToFinalize)
                                @can('grade', $submission)
                                    <form method="POST" action="{{ route('teacher.online_exams.results.finalize', $submission->id) }}" class="ms-auto">
                                        @csrf
                                        <button class="eBtn eBtn-sm eBtn-primary" type="submit" title="{{ get_phrase('Hand this completed marking to an administrator. The student sees nothing until an administrator publishes the result.') }}">{{ get_phrase('Submit Marks for Admin Review') }}</button>
                                    </form>
                                @endcan
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="12" class="text-center text-muted">{{ get_phrase('No answers in queue') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $answers->links() }}
</div>
@endsection
