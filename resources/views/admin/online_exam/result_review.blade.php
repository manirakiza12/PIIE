@extends('admin.navigation')

@section('title', 'Online Exam Results Awaiting Review')

@section('content')
{{-- RESULTS AWAITING AN ADMINISTRATOR'S DECISION.

     One list, every exam in the institution, read from the same persisted state
     the per-exam screen acts on: `status = finalized` together with
     `result_review_state = pending_review`.

     Both halves of that test are load-bearing. `finalized` on its own also matches
     results an administrator has already released, so the queue would never empty;
     `pending_review` on its own also matches marking a lecturer has not finished,
     so it would ask for a decision that cannot be made yet.

     This page CREATES NO NEW WAY TO PUBLISH. Every row links to the existing
     per-exam results screen, and the only two actions remain `publishResult()` and
     `returnResultForCorrection()` - both of which refuse anyone who is not an
     administrator, whatever route they arrive by. The lecturer's action is to hand
     marking over, which is a different screen with a different word on the button.
--}}
<div class="mainSection-title">
    <div class="row">
        <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h4>{{ get_phrase('Results Awaiting Review') }}</h4>
            <a class="export_btn" href="{{ route('admin.online_exams.index') }}">{{ get_phrase('All Online Exams') }}</a>
        </div>
    </div>
</div>

<div class="eSection-wrap mb-3">
    <div class="row g-2">
        @foreach([
            'pending_review' => ['Awaiting decision', $counts['pending_review'], 'bg-warning'],
            'returned' => ['Returned for correction', $counts['returned_for_correction'], 'bg-danger'],
            'published' => ['Published', $counts['result_published'], 'bg-success'],
        ] as $key => [$label, $count, $class])
            <div class="col-md-4">
                <a class="card text-decoration-none h-100" href="{{ route('admin.online_exams.result_review_queue', ['status' => $key]) }}">
                    <div class="card-body py-3">
                        <div class="text-muted small">{{ get_phrase($label) }}</div>
                        <div class="fs-4 fw-semibold">{{ $count }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<div class="eSection-wrap">
    <div class="table-responsive">
        <table class="table eTable" data-testid="result-review-queue">
            <caption class="visually-hidden">
                Completed submissions awaiting an administrator's decision, with the
                student, the exam, the course, the marker, the score and the outcome.
            </caption>
            <thead>
            <tr>
                <th>{{ get_phrase('Student') }}</th>
                <th>{{ get_phrase('Exam') }}</th>
                <th>{{ get_phrase('Course Offering / Course Unit') }}</th>
                <th>{{ get_phrase('Submitted') }}</th>
                <th class="text-end">{{ get_phrase('Objective') }}</th>
                <th class="text-end">{{ get_phrase('Manual') }}</th>
                <th class="text-end">{{ get_phrase('Total') }}</th>
                <th class="text-end">{{ get_phrase('Percentage') }}</th>
                <th>{{ get_phrase('Outcome') }}</th>
                <th class="text-end">{{ get_phrase('Actions') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse($submissions as $submission)
                @php
                    $offering = $submission->exam->courseOffering ?? null;
                    $percentage = $submission->result_total_marks > 0
                        ? round(($submission->score / $submission->result_total_marks) * 100, 1)
                        : null;
                @endphp
                <tr id="review-submission-{{ $submission->id }}">
                    <td>{{ optional($submission->student)->name ?? '—' }}</td>
                    <td>{{ $submission->exam->title ?? '—' }}</td>
                    {{-- The course is part of the decision, so it is never blank: an
                         Offering paper names its course, a legacy paper names its class. --}}
                    <td>
                        @if($offering)
                            <div>{{ optional($submission->exam->subject)->name ?? '—' }}</div>
                            <div class="text-muted small">{{ $offering->reference ?? ('Offering #'.$offering->id) }}</div>
                        @else
                            <div class="text-muted small">{{ optional($submission->exam->classRoom)->name ?? get_phrase('Legacy - no course offering') }}</div>
                        @endif
                    </td>
                    <td>{{ optional($submission->submitted_at)->format('d M Y H:i') ?? '—' }}</td>
                    <td class="text-end">{{ number_format((float) $submission->objective_score, 2) }}</td>
                    <td class="text-end">{{ number_format((float) $submission->manual_score, 2) }}</td>
                    <td class="text-end">
                        {{ number_format((float) $submission->score, 2) }} / {{ number_format($submission->result_total_marks, 2) }}
                    </td>
                    <td class="text-end">{{ $percentage !== null ? number_format($percentage, 1).'%' : '—' }}</td>
                    <td>
                        @if($submission->isResultPublished())
                            <span class="badge bg-success">{{ get_phrase('Published') }}</span>
                        @elseif($submission->result_review_state === 'returned_for_correction')
                            <span class="badge bg-danger">{{ get_phrase('Returned for correction') }}</span>
                        @else
                            <span class="badge bg-warning">{{ get_phrase('Awaiting decision') }}</span>
                        @endif
                        @if($submission->passed !== null)
                            <span class="badge {{ $submission->passed ? 'bg-success' : 'bg-secondary' }} ms-1">
                                {{ $submission->passed ? get_phrase('Pass') : get_phrase('Fail') }}
                            </span>
                        @endif
                    </td>
                    <td class="text-end">
                        <a class="eBtn eBtn-sm eBtn-primary"
                           href="{{ route('admin.online_exams.results', ['id' => $submission->online_exam_id, 'submission' => $submission->id]) . '#submission-'.$submission->id }}">
                            {{ get_phrase('Open') }}
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="text-center text-muted py-4">
                        {{ $status === 'pending_review'
                            ? get_phrase('Nothing is waiting for a decision. Completed lecturer marking appears here.')
                            : get_phrase('Nothing in this state.') }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $submissions->links() }}
</div>
@endsection