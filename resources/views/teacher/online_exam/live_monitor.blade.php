@extends('teacher.navigation')

@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12 d-flex justify-content-between align-items-center flex-wrap gr-15">
    <h4>{{ get_phrase('Live Monitor') }}</h4>
    <a class="export_btn" href="{{ route('teacher.online_exams.index') }}">{{ get_phrase('Back') }}</a>
</div></div></div>

<div class="eSection-wrap mb-3">
    <h6 class="mb-3">{{ get_phrase('Ongoing Exams') }}</h6>
    <div class="table-responsive">
        <table class="table eTable">
            <thead>
            <tr>
                <th>{{ get_phrase('Title') }}</th>
                <th>{{ get_phrase('Subject') }}</th>
                @if(academic_education_level((int) auth()->user()->school_id) !== 'tertiary')
                <th>{{ academic_term('class', auth()->user()->school_id) }}</th>
                @endif
                <th>{{ get_phrase('Ends') }}</th>
                <th>{{ get_phrase('Students Sitting Now') }}</th>
                <th>{{ get_phrase('Actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($ongoingExams as $exam)
                <tr>
                    <td>{{ $exam->title }}</td>
                    <td>{{ optional($exam->subject)->name ?? '—' }}</td>
                @if(academic_education_level((int) auth()->user()->school_id) !== 'tertiary')
                    <td>{{ optional($exam->classRoom)->name ?? '—' }}</td>
                @endif
                    <td>{{ optional($exam->end_datetime)->format('d M Y H:i') ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $exam->in_progress_count > 0 ? 'bg-success' : 'bg-secondary' }}">{{ $exam->in_progress_count }}</span>
                    </td>
                    <td class="d-flex gap-1 flex-wrap">
                        <a class="eBtn eBtn-sm eBtn-primary" href="{{ route('teacher.online_exams.attempts', $exam->id) }}">{{ get_phrase('View Attempts') }}</a>
                        @if($canReviewProctoring)
                            <span class="text-muted small align-self-center">{{ get_phrase('Open an attempt above to proctor it') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ academic_education_level((int) auth()->user()->school_id) === 'tertiary' ? 5 : 6 }}" class="text-center text-muted">{{ get_phrase('No exams are ongoing right now') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="eSection-wrap">
    <h6 class="mb-3">{{ get_phrase('Upcoming Exams') }}</h6>
    <div class="table-responsive">
        <table class="table eTable">
            <thead>
            <tr>
                <th>{{ get_phrase('Title') }}</th>
                <th>{{ get_phrase('Subject') }}</th>
                @if(academic_education_level((int) auth()->user()->school_id) !== 'tertiary')
                <th>{{ academic_term('class', auth()->user()->school_id) }}</th>
                @endif
                <th>{{ get_phrase('Starts') }}</th>
                <th>{{ get_phrase('Starts In') }}</th>
                <th>{{ get_phrase('Actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($upcomingExams as $exam)
                <tr>
                    <td>{{ $exam->title }}</td>
                    <td>{{ optional($exam->subject)->name ?? '—' }}</td>
                @if(academic_education_level((int) auth()->user()->school_id) !== 'tertiary')
                    <td>{{ optional($exam->classRoom)->name ?? '—' }}</td>
                @endif
                    <td>{{ optional($exam->start_datetime)->format('d M Y H:i') ?? '—' }}</td>
                    <td>{{ $exam->start_datetime ? $exam->start_datetime->diffForHumans(null, true) : '—' }}</td>
                    <td>
                        <a class="eBtn eBtn-sm eBtn-secondary" href="{{ route('teacher.online_exams.show', $exam->id) }}">{{ get_phrase('View') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ academic_education_level((int) auth()->user()->school_id) === 'tertiary' ? 5 : 6 }}" class="text-center text-muted">{{ get_phrase('No upcoming exams scheduled') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
