@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Online Exams / CBT') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="#">{{ get_phrase('Examination') }}</a></li><li><a href="#">{{ get_phrase('Online Exams') }}</a></li></ul>
        </div>
        <div class="export-btn-area d-flex gap-2">
            @if(academic_education_level(auth()->user()->school_id) !== 'tertiary')<a href="{{ route('admin.question_bank.index') }}" class="export_btn bg-secondary">{{ get_phrase('Question Bank') }}</a>@endif
            <a href="javascript:;" class="export_btn" onclick="rightModal('{{ route('admin.online_exams.open_modal') }}', '{{ get_phrase('Create Exam') }}')">{{ get_phrase('Create Exam') }}</a>
        </div>
    </div>
</div></div></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><strong>{{ get_phrase('Exam cannot be published yet.') }}</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="alert alert-info">{{ get_phrase('Administrators create and publish exam structures, schedules, and release policies. Teachers manage assigned questions and marking when permitted.') }}</div>
<div class="alert alert-secondary">
    <strong>{{ get_phrase('Exam eligibility setup') }}</strong>:
    @if(academic_education_level(auth()->user()->school_id) === 'tertiary')
    {{ get_phrase('Assign the teaching team and confirm student course registrations before publishing course-based exams.') }}
    @else
    {{ get_phrase('Create classes, assign teachers, and place students into their class before publishing class-targeted exams.') }}
    <a class="ms-2" href="{{ route('admin.class_list') }}">{{ get_phrase('Manage Classes') }}</a>
    <a class="ms-2" href="{{ route('admin.teacher.permission') }}">{{ get_phrase('Assign').' '.academic_term('teachers', auth()->user()->school_id) }}</a>
    <a class="ms-2" href="{{ route('admin.student') }}">{{ get_phrase('Assign Students') }}</a>
    @endif
</div>
<div class="row"><div class="col-12"><div class="eSection-wrap">
    <div class="table-responsive online-exam-table-wrap">
        <table class="table eTable">
            <thead><tr><th>#</th><th>{{ get_phrase('Title') }}</th><th>{{ academic_term('subject', auth()->user()->school_id) }}</th><th>{{ get_phrase('Duration') }}</th><th>{{ get_phrase('Start') }}</th><th>{{ get_phrase('Workflow') }}</th><th>{{ get_phrase('Lifecycle') }}</th><th>{{ get_phrase('Submissions') }}</th><th>{{ get_phrase('Marking / Release') }}</th><th>{{ get_phrase('Actions') }}</th></tr></thead>
            <tbody>
            @forelse($exams as $i => $exam)
            @php
                $workflowLabel = ['draft' => 'Draft', 'pending_review' => 'Awaiting Admin Review', 'published' => 'Published', 'cancelled' => 'Cancelled'][$exam->workflow_state ?? 'draft'] ?? 'Draft';
                $lifecycleLabel = ['active' => 'Active', 'ended' => 'Ended', 'published' => 'Scheduled', 'cancelled' => 'Cancelled'][$exam->lifecycle_status] ?? $workflowLabel;
                $pendingMarking = 0;
                $finalized = 0;
                $released = 0;
                foreach ($exam->submissions as $adminSubmission) {
                    $adminSummary = \App\Support\OnlineExams\OnlineExamMarking::summary($adminSubmission);
                    $pendingMarking += (int) $adminSummary['pending'];
                    $finalized += in_array($adminSubmission->status, ['finalized', 'result_published'], true) ? 1 : 0;
                    $released += $adminSubmission->isResultPublished() ? 1 : 0;
                }
                $hasSubmissions = $exam->submissions_count > 0;
                $hasPending = $pendingMarking > 0;
            @endphp
            <tr>
                <td>{{ $exams->firstItem() + $i }}</td>
                <td><strong>{{ $exam->title }}</strong></td>
                <td>{{ optional($exam->subject)->name ?? '—' }}</td>
                <td>{{ $exam->duration_minutes }} min</td>
                <td>{{ $exam->start_datetime ? $exam->start_datetime->format('d M Y H:i') : '—' }}</td>
                <td><span class="badge {{ $exam->is_published ? 'bg-success' : 'bg-secondary' }}">{{ $workflowLabel }}</span></td>
                <td><span class="badge bg-info">{{ $lifecycleLabel }}</span></td>
                <td>
                    <strong>{{ $exam->submissions_count }}</strong>
                    @if($hasSubmissions)<div class="small text-muted">{{ $finalized }}/{{ $exam->submissions_count }} finalized</div>@endif
                </td>
                <td>
                    @if($hasPending)
                        <span class="badge bg-warning text-dark">{{ get_phrase('Manual marking required') }}</span>
                        <div class="small text-muted">{{ $pendingMarking }} {{ get_phrase('pending answer(s)') }}</div>
                    @elseif($hasSubmissions && $released === $exam->submissions_count)
                        <span class="badge bg-success">{{ get_phrase('Results released') }}</span>
                    @elseif($hasSubmissions && $finalized === $exam->submissions_count)
                        <span class="badge bg-secondary">{{ get_phrase('Finalized / not released') }}</span>
                    @elseif($hasSubmissions)
                        <span class="badge bg-info">{{ get_phrase('Awaiting finalization') }}</span>
                    @else
                        <span class="text-muted">{{ get_phrase('No submissions yet') }}</span>
                    @endif
                </td>
                <td>
                    <a href="{{ route('admin.online_exams.questions', $exam->id) }}" class="eBtn eBtn-sm eBtn-primary"><i class="bi bi-list-ol"></i> {{ get_phrase('Questions') }}</a>
                    <a href="{{ route('admin.online_exams.submissions', $exam->id) }}" class="eBtn eBtn-sm eBtn-warning"><i class="bi bi-people"></i> {{ get_phrase('Attempts') }}</a>
                    @if($hasSubmissions)<a href="{{ route('admin.online_exams.results', $exam->id) }}" class="eBtn eBtn-sm eBtn-info"><i class="bi bi-check2-square"></i> {{ get_phrase('Results & Marking') }}</a>@endif
                    @if(!$exam->is_published)
                        <form method="POST" action="{{ route('admin.online_exams.publish', $exam->id) }}" class="d-inline" onsubmit="return confirm('Publish?')">@csrf<button class="eBtn eBtn-sm eBtn-primary" type="submit"><i class="bi bi-send"></i> {{ get_phrase('Publish Exam') }}</button></form>
                    @endif
                    <a href="javascript:;" class="eBtn eBtn-sm eBtn-primary" onclick="rightModal('{{ route('admin.online_exams.open_modal', ['id'=>$exam->id]) }}', '{{ get_phrase('Edit Exam') }}')"><i class="bi bi-pencil"></i> {{ get_phrase('Edit') }}</a>
                    <form method="POST" action="{{ route('admin.online_exams.destroy', $exam->id) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="eBtn eBtn-sm eBtn-danger" type="submit"><i class="bi bi-trash"></i> {{ get_phrase('Delete') }}</button></form>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center text-muted py-4">{{ get_phrase('No exams created') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $exams->links() }}
</div></div></div>
@endsection
