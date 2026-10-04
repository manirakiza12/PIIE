@extends('student.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Exam Result') }}: {{ $exam->title }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="{{ route('student.online_exam.list') }}">{{ get_phrase('Exams') }}</a></li><li><a href="#">{{ get_phrase('Result') }}</a></li></ul>
        </div>
    </div>
</div></div></div>
<div class="row justify-content-center online-exam-result-page"><div class="col-lg-8"><div class="eSection-wrap text-center">
    <div class="mb-3">
        <h5>{{ $exam->title }}</h5>
        <div class="text-muted">{{ get_phrase('Course') }}: {{ optional($exam->subject)->name ?? '—' }}</div>
        <div class="text-muted">{{ get_phrase('Student') }}: {{ auth()->user()->name }}</div>
        <div class="text-muted">{{ get_phrase('Submitted') }}: {{ optional($submission->submitted_at)->format('d M Y H:i') ?? '—' }}</div>
    </div>
    <div class="my-4">
        @if($submission->passed)
            <div class="display-1 text-success"><i class="bi bi-trophy-fill"></i></div>
            <h3 class="text-success mt-2">{{ get_phrase('Congratulations! You Passed!') }}</h3>
        @else
            <div class="display-1 text-danger"><i class="bi bi-x-circle-fill"></i></div>
            <h3 class="text-danger mt-2">{{ get_phrase('Sorry, You Did Not Pass') }}</h3>
        @endif
    </div>
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-4"><div class="card"><div class="card-body"><h6 class="text-muted">{{ get_phrase('Score') }}</h6><h4>{{ $submission->score }}/{{ $submission->result_total_marks }}</h4></div></div></div>
        <div class="col-12 col-sm-4"><div class="card"><div class="card-body"><h6 class="text-muted">{{ get_phrase('Percentage') }}</h6><h4>{{ $submission->result_total_marks > 0 ? round(($submission->score/$submission->result_total_marks)*100,1) : 0 }}%</h4></div></div></div>
        <div class="col-12 col-sm-4"><div class="card"><div class="card-body"><h6 class="text-muted">{{ get_phrase('Pass Mark') }}</h6><h4>{{ $exam->pass_mark }} {{ get_phrase('marks') }}</h4></div></div></div>
    </div>
    <div class="row g-3 mb-4 text-start">
        <div class="col-md-6"><div class="card"><div class="card-body"><strong>{{ get_phrase('Automatic Marks') }}</strong><div>{{ number_format((float) ($submission->objective_score ?? 0), 2) }}</div></div></div></div>
        <div class="col-md-6"><div class="card"><div class="card-body"><strong>{{ get_phrase('Manual Marks') }}</strong><div>{{ number_format((float) ($submission->manual_score ?? 0), 2) }}</div></div></div></div>
    </div>
    {{--
        THE MARKING BREAKDOWN — only ever rendered on a PUBLISHED result.

        The controller returns the withheld page before this one is reached, and it loads
        the answer rows only after `authorize('viewResult')`, so there is no route by
        which an unpublished paper shows a student's own answers, a marker's comment, or
        a per-question score.

        It exists because a total with no breakdown is not reviewable by the one person
        who cannot appeal it: a student told "19 of 20" with no way to see which question
        lost the mark cannot learn anything from it, and the feedback a lecturer wrote is
        collected and never read.
    --}}
    @if(isset($answerRows) && $questions->isNotEmpty())
        <div class="text-start mt-4">
            <h5 class="mb-3">{{ get_phrase('Marking breakdown') }}</h5>
            @foreach($questions as $index => $question)
                @php($row = $answerRows->get($question->id))
                <div class="card mb-2">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="flex-grow-1">
                                <strong class="small">Q{{ $index + 1 }}</strong>
                                <div class="small piie-prose">
                                    {!! $question->prosePromptOrEmptyLabel() !!}
                                </div>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <span class="badge bg-primary">
                                    {{ is_null(optional($row)->awarded_marks) ? '—' : number_format((float) $row->awarded_marks, 2) }}
                                    / {{ number_format((float) $question->marks, 2) }}
                                </span>
                            </div>
                        </div>

                        @if($row && ($row->answer_text || $row->selected_option || $row->answer_payload))
                            <div class="small mt-2">
                                <span class="text-muted">{{ get_phrase('Your answer') }}:</span>
                                @if($row->answer_text)
                                    <div class="piie-prose border-start ps-2 mt-1">{!! $row->proseAnswer() !!}</div>
                                @else
                                    <div class="mt-1">{{ $row->selected_option }}</div>
                                @endif
                            </div>
                        @else
                            <div class="small text-muted mt-2" data-testid="result-blank-answer">
                                {{ get_phrase('No answer was recorded for this question.') }}
                            </div>
                        @endif

                        @if($row && $row->teacher_comment)
                            <div class="small mt-2">
                                <span class="text-muted">{{ get_phrase('Feedback') }}:</span>
                                {{ $row->teacher_comment }}
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <a href="{{ route('student.online_exam.list') }}" class="eBtn eBtn-primary mt-4">{{ get_phrase('Back to Exams') }}</a>
</div></div></div>
@endsection
