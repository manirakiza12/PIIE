@extends('admin.navigation')

@section('content')
@php
    $workflowLabel = ['draft' => 'Draft', 'pending_review' => 'Awaiting Admin Review', 'published' => 'Published', 'cancelled' => 'Cancelled'][$exam->workflow_state ?? 'draft'] ?? 'Draft';
    $lifecycleLabel = ['published' => 'Scheduled', 'active' => 'Active', 'ended' => 'Ended', 'cancelled' => 'Cancelled'][$exam->lifecycle_status] ?? $workflowLabel;
@endphp
<div class="mainSection-title"><div class="row"><div class="col-12 d-flex justify-content-between align-items-center">
    <h4>{{ $exam->title }}</h4>
    <a class="export_btn" href="{{ route('admin.online_exams.questions', $exam->id) }}">{{ get_phrase('Manage questions') }}</a>
</div></div></div>
@if(!empty($readinessErrors))
<div class="alert alert-warning"><strong>{{ get_phrase('Readiness issues') }}:</strong><ul class="mb-0">@foreach($readinessErrors as $issue)<li>{{ $issue }}</li>@endforeach</ul></div>
@endif
<div class="eSection-wrap"><div class="row">
    <div class="col-md-6"><p><strong>{{ academic_term('subject', auth()->user()->school_id) }}:</strong> {{ optional($exam->subject)->name ?? '—' }}</p><p><strong>{{ get_phrase('Workflow') }}:</strong> <span class="badge bg-secondary">{{ $workflowLabel }}</span></p><p><strong>{{ get_phrase('Lifecycle') }}:</strong> <span class="badge bg-info">{{ $lifecycleLabel }}</span></p><p><strong>{{ get_phrase('Schedule') }}:</strong> {{ optional($exam->start_datetime)->format('d M Y H:i') }} – {{ optional($exam->end_datetime)->format('d M Y H:i') }}</p></div>
    <div class="col-md-6"><p><strong>{{ get_phrase('Duration') }}:</strong> {{ $exam->duration_mins }} {{ get_phrase('minutes') }}</p><p><strong>{{ get_phrase('Total marks / pass mark') }}:</strong> {{ $exam->total_marks }} / {{ $exam->pass_mark }}</p><p><strong>{{ get_phrase('Questions') }}:</strong> {{ $exam->questions->count() }}</p></div>
</div><hr><h6>{{ get_phrase('Instructions') }}</h6><div class="piie-prose">{!! $exam->proseInstructions() ?: '—' !!}</div></div>
@endsection
