@extends('student.navigation')

@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Exam Submitted') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="{{ route('student.online_exam.list') }}">{{ get_phrase('Exams') }}</a></li>
                <li><a href="#">{{ get_phrase('Submission status') }}</a></li>
            </ul>
        </div>
    </div>
</div></div></div>

<div class="row justify-content-center"><div class="col-lg-8"><div class="eSection-wrap">
    <div class="alert alert-success" data-testid="result-status-{{ $statusMessage['key'] }}">
        <h5>{{ get_phrase('Exam submitted successfully.') }}</h5>
        {{--
            STATE-AWARE, AND DECIDED IN THE CONTROLLER.

            This used to branch on `status === 'finalized'` and say "Your result is
            finalized and awaiting publication." Exam 17 submission 12 was displayed
            that way while its marking had never been performed and never handed to
            anyone — a false claim about a result no human had reached. The wording now
            comes from the real review state via `studentResultStatusMessage()`.

            It deliberately reveals nothing: no score, no mark, no feedback, and no hint
            that the record needed administrative repair. A student in that state is
            told only that the result is being processed, which is both true and the
            only thing they can act on.
        --}}
        <p class="mb-0">{{ $statusMessage['message'] }}</p>
    </div>
    <p class="text-muted">{{ $submission->exam->title }}</p>
    <a href="{{ route('student.online_exam.list') }}" class="eBtn eBtn-primary">{{ get_phrase('Back to Exams') }}</a>
</div></div></div>
@endsection
