@extends('admin.navigation')
@section('content')
@php
    $editable = $offering->status === 'draft';
@endphp
<div class="mainSection-title"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h4>Course Allocation · {{ $offering->reference }}</h4><a class="btn btn-outline-secondary" href="{{ route('admin.course_offerings.index') }}">All Allocations</a></div></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="alert alert-info" role="status"><strong>{{ $stateHeadline }}</strong> {{ $nextStep }}</div>
<section class="eSection-wrap mb-3" aria-label="Course Allocation overview">
    <h5>{{ $offering->subject->code ? $offering->subject->code.' — ' : '' }}{{ $offering->subject->name }}</h5>
    <div class="row g-3"><div class="col-md-4"><strong>Allocation Reference:</strong> {{ $offering->reference }}</div><div class="col-md-4"><strong>Academic Year:</strong> {{ $offering->academicYear->label }}</div><div class="col-md-4"><strong>{{ $periodLabel }}:</strong> {{ $offering->academicPeriod->label }}</div></div>
    <p class="mt-3 mb-0"><span class="badge bg-primary">{{ \App\Support\CourseOffering\CourseOfferingService::statusLabel($offering->status) }}</span></p>
</section>
<section class="eSection-wrap mb-3" id="offering-details"><h5>Allocation Details</h5>@if($editable && auth()->user()->hasPermission('academic.course_offering.manage'))
<form method="POST" action="{{ route('admin.course_offerings.update',$offering->id) }}" class="row g-2">@csrf @method('PUT')<div class="col-md-4"><label class="form-label">Course Unit</label><select class="form-select" name="subject_id">@foreach($subjects as $subject)<option value="{{ $subject->id }}" @selected($offering->subject_id==$subject->id)>{{ $subject->code ? $subject->code.' — ' : '' }}{{ $subject->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">Academic Year</label><select class="form-select" name="academic_year_id" id="edit-year">@foreach($years as $year)<option value="{{ $year->id }}" @selected($offering->academic_year_id==$year->id)>{{ $year->label }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">Academic Period</label><select class="form-select" name="academic_period_id" id="edit-period">@foreach($periods as $period)<option data-year="{{ $period->academic_year_id }}" value="{{ $period->id }}" @selected($offering->academic_period_id==$period->id)>{{ $period->label }}</option>@endforeach</select></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary">Save</button></div><div class="col-12"><div class="form-text">Allocation Reference: <strong>{{ $offering->reference ?: '—' }}</strong> — generated automatically and cannot be edited.</div></div></form>
@else<dl class="row mb-0"><dt class="col-sm-3">Allocation reference</dt><dd class="col-sm-9">{{ $offering->reference ?: '—' }}</dd></dl>@endif</section>
@if(auth()->user()->hasPermission('academic.course_offering.lecturer.view'))
<section class="eSection-wrap mb-3" id="teaching-team"><div class="d-flex flex-wrap justify-content-between gap-2"><h5>Teaching team</h5><a class="btn btn-outline-primary" href="{{ route('admin.course_offerings.lecturers.index', $offering->id) }}">Manage lecturers</a></div>
@if($activeTeachingTeam->isEmpty() && $plannedTeachingTeam->isEmpty())<p class="text-muted mb-0">No lecturers assigned to this allocation.</p>@else<ul class="mb-0">@foreach($activeTeachingTeam->concat($plannedTeachingTeam) as $allocation)<li>{{ $allocation->lecturer?->name ?? 'Lecturer unavailable' }} ? {{ $roleLabels[$allocation->role] ?? $allocation->role }} ({{ ucfirst($allocation->status) }})</li>@endforeach</ul>@endif
@if(auth()->user()->hasPermission('academic.course_offering.lecturer.manage') && !in_array($offering->status, ['completed', 'cancelled'], true))<a class="btn btn-primary mt-3" href="{{ route('admin.course_offerings.lecturers.create', $offering->id) }}">Assign Lecturer</a>@endif
</section>
@endif
<section class="eSection-wrap mb-3" id="applicability"><h5>Curriculum Applicability</h5><p class="text-muted">A Curriculum linked here determines which students may register for this Course Allocation; manage its teaching staff in the Teaching team section above.</p><div class="table-responsive"><table class="table"><thead><tr><th>Programme</th><th>Curriculum</th><th>Programme Year</th><th>{{ $periodLabel }}</th><th></th></tr></thead><tbody>@forelse($links as $link)<tr><td>{{ $link->programme_code }} — {{ $link->programme_name }}</td><td>Curriculum {{ $link->version }}</td><td>{{ $link->stage_label ?: 'Not recorded' }}</td><td>{{ ucfirst($link->period_type) }} {{ $link->period_sequence }}</td><td>@if($editable && auth()->user()->hasPermission('academic.course_offering.manage'))<form method="POST" action="{{ route('admin.course_offerings.applicability.destroy',[$offering->id,$link->curriculum_membership_id]) }}" onsubmit="return confirm('Remove this Curriculum from this Course Allocation?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove</button></form>@endif</td></tr>@empty<tr><td colspan="5" class="text-muted">No Curriculum has been linked yet. Add the applicable Curriculum before opening this Course Allocation.</td></tr>@endforelse</tbody></table></div>
@if($editable && auth()->user()->hasPermission('academic.course_offering.manage'))@if($candidates->isEmpty())<p class="text-muted mb-0">No compatible approved Curriculum entries are available to link yet.</p>@else<form method="POST" action="{{ route('admin.course_offerings.applicability.store',$offering->id) }}" class="row g-2">@csrf<div class="col-md-9"><label class="form-label">Add a compatible Curriculum</label><select class="form-select" name="curriculum_membership_id" required><option value="">Choose a Curriculum entry</option>@foreach($candidates as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->programme_code }} — Curriculum {{ $candidate->version }} — {{ $candidate->stage_label ?: 'Placement' }} · {{ ucfirst($candidate->period_type) }} {{ $candidate->period_sequence }}</option>@endforeach</select>@if($candidates->count()===100)<small class="text-muted">Showing first 100 candidates; narrow selection in the Programme Curriculum before adding.</small>@endif</div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary">Link Curriculum</button></div></form>@endif @endif</section>
<section class="eSection-wrap mb-3" id="lifecycle"><h5>Lifecycle</h5><p class="text-muted">Draft → Open → In progress → Completed. Open, in-progress and draft Allocations may be cancelled. Open freezes identity and applicability.</p>
<p class="text-muted"><strong>Open</strong> means preparation and registration: an assigned lecturer may prepare academic delivery. <strong>In progress</strong> means active teaching and delivery.</p>
@if($editable && auth()->user()->hasPermission('academic.course_offering.lifecycle'))<form method="POST" action="{{ route('admin.course_offerings.open',$offering->id) }}" class="d-inline" onsubmit="return confirm('Open this Allocation? Identity and applicability will be frozen.')">@csrf<button class="btn btn-success">Open Allocation</button></form>@endif
@if($offering->status==='open' && auth()->user()->hasPermission('academic.course_offering.lifecycle'))
    @if($canStartEarly)
    {{-- Governed early start: an audited, reasoned exception to date
         governance. It moves ONLY this Allocation's status. The Academic Period,
         Curriculum, Programme Student Group, Academic Placement, registrations and
         lecturer allocation dates are all left exactly as they are. --}}
    <div class="alert alert-warning mb-3" role="status">
        <strong>{{ $periodLabel }} has not begun yet.</strong>
        Teaching normally cannot start before the Academic Period begins. An authorised administrator may start this Course Allocation early for a documented reason; the Academic Period dates are not changed and the reason is recorded in the audit trail.
    </div>
    <form method="POST" action="{{ route('admin.course_offerings.start_early',$offering->id) }}" class="mt-3" onsubmit="return confirm('Start this Course Allocation before {{ $periodLabel }} begins? The Academic Period dates will not be changed, and this reason is recorded in the audit trail.')">
        @csrf
        <label class="form-label" for="early_start_reason">Reason for starting before {{ $periodLabel }}</label>
        <input id="early_start_reason" name="reason" class="form-control mb-2" required maxlength="1000" placeholder="e.g. Pre-semester end-to-end academic delivery testing.">
        <button class="btn btn-warning">Start Course Allocation Early</button>
    </form>
    @elseif(! $academicPeriodHasBegun)
    <div class="alert alert-secondary mb-3" role="status">
        This Course Allocation cannot be started yet: {{ $periodLabel }} has not begun, and an early start is not available to you.
        @if($earlyStartBlockers)<ul class="mb-0 mt-1">@foreach($earlyStartBlockers as $earlyBlocker)<li>{{ $earlyBlocker }}</li>@endforeach</ul>@endif
    </div>
    @else
    <form method="POST" action="{{ route('admin.course_offerings.start',$offering->id) }}" class="d-inline" onsubmit="return confirm('Start this Course Allocation? This marks teaching as under way.')">@csrf<button class="btn btn-primary">Start Course Allocation</button></form>
    @endif
@elseif($offering->status==='open' && ! $academicPeriodHasBegun)
<div class="alert alert-secondary mb-3" role="status">
    {{ $periodLabel }} has not begun yet, so teaching cannot start. Open means preparation and registration: the assigned lecturer may still prepare academic delivery.
</div>
@endif
@if($offering->status==='in_progress' && auth()->user()->hasPermission('academic.course_offering.lifecycle'))<form method="POST" action="{{ route('admin.course_offerings.complete',$offering->id) }}" class="d-inline" onsubmit="return confirm('Complete this Course Allocation?')">@csrf<button class="btn btn-dark">Complete Course Allocation</button></form>@endif
@if(in_array($offering->status,['draft','open','in_progress'],true) && auth()->user()->hasPermission('academic.course_offering.lifecycle'))<form method="POST" action="{{ route('admin.course_offerings.cancel',$offering->id) }}" class="mt-3" onsubmit="return confirm('Cancel this Allocation? This is terminal and preserves history.')">@csrf<label class="form-label" for="reason">Cancellation reason</label><textarea id="reason" name="reason" class="form-control mb-2" required maxlength="1000"></textarea><button class="btn btn-outline-danger">Cancel Allocation</button></form>@endif
@if($completionBlockers)
<div class="alert alert-warning mb-3" role="status">
    <strong>This Course Allocation cannot be completed yet.</strong>
    <ul class="mb-0 mt-1">@foreach($completionBlockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
</div>
@endif
@if(in_array($offering->status,['completed','cancelled'],true))<p class="mb-0">Terminal historical record. This {{ \App\Support\CourseOffering\CourseOfferingService::statusLabel($offering->status) }} Course Allocation is read-only; its teaching, registration and audit history is retained.@if($offering->status==='cancelled') <strong>Cancellation reason:</strong> {{ $cancellationReason ?: 'Not recorded.' }}@endif</p>@endif
</section>
<section class="eSection-wrap"><h5>Audit / History</h5><div class="table-responsive"><table class="table"><thead><tr><th>When</th><th>Action</th><th>Description</th></tr></thead><tbody>@forelse($audit as $event)<tr><td>{{ $event->created_at }}</td><td>{{ $event->action }}</td><td>{{ $event->description }}</td></tr>@empty<tr><td colspan="3" class="text-muted">No audit entries available.</td></tr>@endforelse</tbody></table></div></section>
@if($editable && auth()->user()->hasPermission('academic.course_offering.manage'))
<section class="eSection-wrap mb-3"><h5>Delete draft</h5><p class="text-muted">Only drafts with no teaching, lecturer or registration history can be deleted. Cancel an allocation already in use.</p><form method="POST" action="{{ route('admin.course_offerings.destroy', $offering->id) }}" onsubmit="return confirm('Delete this unused draft Course Allocation?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete Draft</button></form></section>
@endif
@endsection
