@extends('admin.navigation')
@section('page_styles')<link rel="stylesheet" href="{{ asset('assets/css/curriculum-workspace.css') }}">
<style>
    .programme-year-view .table.eTable { margin-bottom: 0; }
    .programme-year-view .table.eTable thead th { padding: 10px 12px; font-size: 13px; white-space: nowrap; }
    .programme-year-view .table.eTable tbody td { padding: 8px 12px; vertical-align: middle; font-size: 14px; }
</style>
@endsection
@section('content')
<div class="curriculum-workspace programme-year-view">
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>{{ $year->label }} @if($year->archived_at)<span class="badge bg-secondary">{{ get_phrase('Archived') }}</span>@endif</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="#">{{ get_phrase('Home') }}</a></li>
                <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                <li><a href="{{ route('admin.programme_years.index', ['curriculum_id' => $year->curriculum_id]) }}">{{ get_phrase('Programme Years') }}</a></li>
                <li aria-current="page">{{ get_phrase('View') }}</li>
            </ul>
            <p class="text-muted mb-0">{{ $year->curriculum->programme?->name }} · {{ get_phrase('Curriculum') }} {{ $year->curriculum->version }} · {{ ucfirst($year->curriculum->status) }}</p>
        </div>
        <div class="export-btn-area">
            <a href="{{ route('admin.programme_years.index', ['curriculum_id' => $year->curriculum_id]) }}" class="eBtn eBtn-dark">{{ get_phrase('All Programme Years') }}</a>
        </div>
    </div>
</div>
<section class="eSection-wrap mb-3">
    <h5>{{ get_phrase('Programme Year Overview') }}</h5>
    <div class="row g-2">
        <div class="col-md-4"><strong>{{ get_phrase('Programme Year') }}:</strong> {{ $year->label }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Curriculum') }}:</strong> {{ $year->curriculum->programme?->name }} — {{ $year->curriculum->version }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Curriculum status') }}:</strong> {{ ucfirst($year->curriculum->status) }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Order in curriculum') }}:</strong> {{ $year->sequence }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Created') }}:</strong> {{ $year->created_at?->format('Y-m-d') ?? '—' }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Updated') }}:</strong> {{ $year->updated_at?->format('Y-m-d') ?? '—' }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Course Unit placements') }}:</strong> {{ $year->memberships->count() }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Student placement/history records') }}:</strong> {{ $year->student_assignments_count }}</div>
    </div>
</section>
<section class="eSection-wrap mb-3">
    <h5>{{ get_phrase('Curriculum Information') }}</h5>
    <div class="row g-2">
        <div class="col-md-4"><strong>{{ get_phrase('Programme') }}:</strong> {{ $year->curriculum->programme?->name }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Curriculum code') }}:</strong> {{ $year->curriculum->version }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Approval status') }}:</strong> {{ ucfirst($year->curriculum->status) }}</div>
    </div>
    <a class="btn btn-sm btn-outline-secondary mt-2" href="{{ route('admin.curricula.show', $year->curriculum_id) }}">{{ get_phrase('View Curriculum') }}</a>
</section>
<section class="eSection-wrap mb-3">
    <h5>{{ get_phrase('Course Units in this Year') }}</h5>
    <div class="table-responsive"><table class="table eTable align-middle"><thead><tr><th>#</th><th>{{ get_phrase('Course Unit Code') }}</th><th>{{ get_phrase('Course Unit Name') }}</th><th>{{ get_phrase('Credit Units') }}</th><th>{{ get_phrase('Semester/Term') }}</th><th>{{ get_phrase('Classification') }}</th></tr></thead><tbody>
    @forelse($year->memberships as $i => $membership)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td class="text-nowrap">{{ $membership->subject?->code ?: '—' }}</td>
            <td>{{ $membership->subject?->name ?: '—' }}</td>
            <td>{{ $membership->credits }}</td>
            <td>{{ ucfirst($membership->period_type ?? '') }} {{ $membership->period_sequence }}</td>
            <td>{{ ucfirst($membership->classification) }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="text-center text-muted">{{ get_phrase('No course units assigned to this year.') }}</td></tr>
    @endforelse
    </tbody></table></div>
</section>
<section class="eSection-wrap mb-3">
    <h5>{{ get_phrase('Prerequisites') }}</h5>
    @php($edgeCount = 0)
    @foreach($year->memberships as $membership)
        @foreach($membership->prerequisites as $required)
            @php($edgeCount++)
            <div class="d-flex justify-content-between border rounded p-2 mb-1">
                <span>{{ $membership->subject?->code ?: '—' }} — {{ $membership->subject?->name }} <span class="text-muted">{{ get_phrase('requires') }}</span> {{ $required->subject?->code ?: '—' }} — {{ $required->subject?->name }}</span>
            </div>
        @endforeach
    @endforeach
    @if(!$edgeCount)<p class="text-muted">{{ get_phrase('No prerequisites defined.') }}</p>@endif
</section>
@if(($year->deletion_impact['draft_offering_links_to_remove'] ?? 0) || ($year->deletion_impact['non_draft_offering_links_to_preserve'] ?? 0) || ($year->deletion_impact['course_registrations'] ?? 0))
<section class="eSection-wrap mb-3">
    <h5>{{ get_phrase('Allocation and Registration') }}</h5>
    <div class="row g-2">
        <div class="col-md-4"><strong>{{ get_phrase('Draft allocation links') }}:</strong> {{ $year->deletion_impact['draft_offering_links_to_remove'] ?? 0 }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Non-draft allocation links') }}:</strong> {{ $year->deletion_impact['non_draft_offering_links_to_preserve'] ?? 0 }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Course registrations') }}:</strong> {{ $year->deletion_impact['course_registrations'] ?? 0 }}</div>
        <div class="col-md-4"><strong>{{ get_phrase('Prerequisite links') }}:</strong> {{ $year->deletion_impact['prerequisite_links'] ?? 0 }}</div>
    </div>
</section>
@endif
@if($year->student_assignments_count)
<section class="eSection-wrap mb-3">
    <h5>{{ get_phrase('Student Placement and History') }}</h5>
    <p class="text-muted mb-0">{{ $year->student_assignments_count }} {{ get_phrase('student placement/history record(s) reference this Programme Year.') }}</p>
</section>
@endif
</div>
@endsection