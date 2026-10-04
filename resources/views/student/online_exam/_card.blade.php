@php
    $examStart = $exam->scheduledStartAt();
    $examEnd = $exam->scheduledEndAt();
    $examNow = \Carbon\Carbon::now($exam->scheduleTimezone());
    $latestSubmission = $exam->submission;
    $summary = $latestSubmission ? \App\Support\OnlineExams\OnlineExamMarking::summary($latestSubmission) : null;
@endphp
<div class="col-md-6 col-xl-4">
    <div class="card h-100 online-exam-card">
        <div class="card-body d-flex flex-column">
            <h6 class="mb-1">{{ $exam->title }}</h6>
            <div class="text-muted small mb-2">{{ optional($exam->subject)->name }}</div>
            <div class="d-flex gap-2 flex-wrap mb-2"><span class="badge bg-info">{{ $exam->duration_minutes }} min</span><span class="badge bg-secondary">Pass: {{ $exam->pass_mark }} marks</span></div>
            @if($examStart)<div class="small text-muted">{{ get_phrase('Starts') }}: {{ $examStart->format('d M Y H:i') }}</div>@endif
            @if($examEnd)<div class="small text-muted">{{ get_phrase('Closes') }}: {{ $examEnd->format('d M Y H:i') }}</div>@endif
            @if($exam->instructions)<p class="small mt-2">{{ $exam->plainInstructions(100) }}</p>@endif
            <div class="mt-auto">
            @if($latestSubmission && $latestSubmission->status === 'in_progress')
                <div class="alert alert-warning py-2 small mb-2">In Progress</div><a href="{{ route('student.online_exam.take',$exam->id) }}" class="eBtn eBtn-sm eBtn-warning w-100">Resume Exam</a>
            @elseif($latestSubmission)
                @if($latestSubmission->isResultVisible())
                    <div class="alert alert-success py-2 small mb-2">Result Available</div><a href="{{ route('student.online_exam.result',$latestSubmission->id) }}" class="eBtn eBtn-sm eBtn-primary w-100">View Result</a>
                @elseif($summary && (int) $summary['pending'] > 0)
                    <div class="alert alert-info py-2 small mb-2">Submitted - Awaiting Marking</div>
                @elseif($latestSubmission->status === 'submitted' || $latestSubmission->status === 'timed_out')
                    <div class="alert alert-info py-2 small mb-2">Marking Complete - Awaiting Finalization</div>
                @elseif($latestSubmission->status === 'finalized')
                    <div class="alert alert-info py-2 small mb-2">Result Awaiting Release</div>
                @else
                    <div class="alert alert-secondary py-2 small mb-2">Submitted</div>
                @endif
                @if($exam->attempts_used < $exam->max_attempts && (!$examEnd || $examNow->lt($examEnd)))<a href="{{ route('student.online_exam.instructions',$exam->id) }}" class="eBtn eBtn-sm eBtn-dark w-100 mt-2">Attempt Again</a>@endif
            @elseif($examStart && $examNow->lt($examStart))
                <button class="eBtn eBtn-sm eBtn-secondary w-100" disabled>Not Started Yet</button>
            @elseif($examEnd && $examNow->gte($examEnd))
                <button class="eBtn eBtn-sm eBtn-secondary w-100" disabled>Exam Has Ended</button>
            @else
                <a href="{{ route('student.online_exam.instructions',$exam->id) }}" class="eBtn eBtn-sm eBtn-primary w-100">Start Exam</a>
            @endif
            </div>
        </div>
    </div>
</div>
