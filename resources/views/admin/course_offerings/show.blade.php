@extends('admin.navigation')
@section('content')
@php
    $editable = $offering->status === 'draft';
@endphp
<div class="mainSection-title"><div class="d-flex justify-content-between align-items-start flex-wrap gap-3"><div><h4>{{ $offering->reference ?: 'Course Offering' }}</h4></div><div class="d-flex flex-wrap gap-2">@if($canCreateLiveClass)<a class="btn btn-primary" href="{{ route('admin.course_offerings.live_classes.create', $offering->id) }}">{{ get_phrase('Schedule Live Class') }}</a>@endif<a class="btn btn-outline-secondary" href="{{ route('admin.course_offerings.index') }}">{{ get_phrase('All Offerings') }}</a></div></div></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="alert alert-info" role="status"><strong>{{ $stateHeadline }}</strong> {{ $nextStep }}</div>
<section class="eSection-wrap mb-3" aria-label="Course Offering overview">
    <div class="row g-3 align-items-stretch">
        <div class="col-12 col-lg-6">
            <div class="text-muted small text-uppercase">{{ $courseUnitLabel }}</div>
            <div class="fs-5">{{ $offering->subject->code ? $offering->subject->code.' — ' : '' }}{{ $offering->subject->name }}</div>
            <div class="text-muted mt-2"><strong>Offering Reference:</strong> {{ $offering->reference ?: '—' }}</div>
            <div class="text-muted small text-uppercase mt-2">Academic Delivery</div>
            <div class="text-muted"><strong>Academic Year:</strong> {{ $offering->academicYear->label }} · <strong>{{ $periodLabel }}:</strong> {{ $offering->academicPeriod->label }}</div>
            <div class="text-muted"><strong>Study Plan Applicability:</strong> @if($links->isEmpty())<span class="text-muted fst-italic">No Study Plan linked yet</span>@else{{ $studyPlanVersions->count() === 1 ? 'Study Plan '.$studyPlanVersions->first() : $studyPlanVersions->count().' Study Plans linked' }} · <strong>Stage / Year of Study:</strong> {{ $studyPlanStages->isEmpty() ? 'Not recorded' : $studyPlanStages->implode(', ') }}@endif</div>
            <div class="mt-2"><span class="badge bg-primary">{{ \App\Support\CourseOffering\CourseOfferingService::statusLabel($offering->status) }}</span></div>
        </div>
        <div class="col-6 col-lg-3"><h6>Registered Students</h6><div class="fs-3">{{ $canViewRegistrations ? $registeredStudentCount : '—' }}</div>@if($canViewRegistrations)<a href="{{ route('admin.course_offerings.registrations', $offering->id) }}">Open registered students</a>@else<span class="text-muted small">Permission required</span>@endif</div>
        <div class="col-6 col-lg-3"><h6>Eligible Students</h6><div class="fs-3">{{ $canViewRegistrations ? $eligibleStudents->count() : '—' }}</div>@if($canViewRegistrations)<a href="{{ route('admin.course_offerings.eligible_students', $offering->id) }}">Review eligible students</a>@else<span class="text-muted small">Permission required</span>@endif</div>
    </div>
    @if($canViewRegistrations)
        <div class="row g-2 mt-1" aria-label="Student summary">
            @foreach(['eligible' => $eligibleStudents->count(), 'registered' => $registeredStudents->where('status','registered')->count(), 'confirmed' => $registeredStudents->where('status','confirmed')->count(), 'dropped' => $registeredStudents->where('status','dropped')->count()] as $label => $count)
                <div class="col-6 col-md-3"><div class="border rounded p-2 text-center h-100"><div class="fs-5 fw-semibold">{{ $count }}</div><div class="small text-muted">{{ ucfirst($label) }}</div></div></div>
            @endforeach
        </div>
    @endif
    @if($canViewRegistrations && $registeredStudents->isEmpty())<div class="alert alert-info mt-3 mb-0" role="status"><strong>No students are registered for this Course Offering yet.</strong> Review eligible students to begin Course Registration. <a href="{{ route('admin.course_offerings.eligible_students', $offering->id) }}" class="alert-link">Review eligible students</a>.</div>@endif
    <hr><div class="d-flex flex-wrap gap-2">@if(auth()->user()->hasPermission('academic.course_offering.lecturer.view'))<a class="btn btn-outline-primary" href="{{ route('admin.course_offerings.lecturers.index', $offering->id) }}">Teaching Team</a>@endif @if($canViewRegistrations)<a class="btn btn-outline-primary" href="{{ route('admin.course_offerings.eligible_students', $offering->id) }}">Eligible Students</a><a class="btn btn-outline-primary" href="{{ route('admin.course_offerings.registrations', $offering->id) }}">Registered Students</a>@endif</div>
</section>
@if(auth()->user()->hasPermission('academic.course_offering.lecturer.view'))
<section class="eSection-wrap mb-3"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h5 class="mb-0">Teaching Team</h5><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.course_offerings.lecturers.index', $offering->id) }}">{{ auth()->user()->hasPermission('academic.course_offering.lecturer.manage') ? 'Manage Lecturer Allocations' : 'View Teaching Team' }}</a></div>
    @php $currentTeam = $activeTeachingTeam; $plannedTeam = $plannedTeachingTeam ?? collect(); @endphp
    @if($currentTeam->isEmpty() && $plannedTeam->isEmpty())
        <p class="text-muted mt-3 mb-0">No lecturers have been assigned yet.</p>
    @else
        <div class="table-responsive mt-2"><table class="table mb-0"><thead><tr><th>Lecturer</th><th>Role</th><th>State</th><th>Effective dates</th></tr></thead><tbody>
        {{-- Current team: the allocation's own status decides this, exactly as
             the Teaching Team page decides it. Ended and cancelled allocations
             are never listed here; they stay visible on the Teaching Team page
             as history. --}}
        @foreach($currentTeam as $allocation)
            <tr><td>{{ $allocation->lecturer?->name ?: 'Former lecturer' }}</td><td>{{ $roleLabels[$allocation->role] ?? str_replace('_',' ',$allocation->role) }}</td>
            <td><span class="badge bg-{{ $allocation->starts_on && $allocation->starts_on->gt(now()->startOfDay()) ? 'info' : 'success' }}">{{ $allocation->starts_on && $allocation->starts_on->gt(now()->startOfDay()) ? 'Active — starts '.$allocation->starts_on->format('j M Y') : 'Active' }}</span></td>
            <td>{{ $allocation->starts_on?->format('Y-m-d') }} – {{ $allocation->ends_on?->format('Y-m-d') ?: 'No end date' }}</td></tr>
        @endforeach
        {{-- Planned allocations are shown so the state is honest, but clearly
             marked as not yet part of the teaching team. --}}
        @foreach($plannedTeam as $allocation)
            <tr><td>{{ $allocation->lecturer?->name ?: 'Former lecturer' }}</td><td>{{ $roleLabels[$allocation->role] ?? str_replace('_',' ',$allocation->role) }}</td>
            <td><span class="badge bg-warning text-dark">Planned — not yet teaching</span></td>
            <td>{{ $allocation->starts_on?->format('Y-m-d') }} – {{ $allocation->ends_on?->format('Y-m-d') ?: 'No end date' }}</td></tr>
        @endforeach
        </tbody></table></div>
    @endif
</section>
@endif
<section class="eSection-wrap mb-3" id="live-classes" aria-labelledby="live-classes-heading">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div><h5 id="live-classes-heading">{{ get_phrase('Live Classes') }}</h5></div>
    </div>
    @if($liveClasses->isEmpty())
        <div class="border rounded p-4 text-center" role="status"><h6>{{ get_phrase('No live classes scheduled') }}</h6><p class="text-muted mb-0">{{ get_phrase('No live classes have been scheduled for this course offering yet.') }}</p></div>
    @else
        @foreach($liveClassGroups as $groupLabel => $sessions)
            @if($sessions->isNotEmpty())
                <section class="mb-3" aria-label="{{ get_phrase($groupLabel) }}">
                    <h6>{{ get_phrase($groupLabel) }} <span class="text-muted">({{ $sessions->count() }})</span></h6>
                    @foreach($sessions as $liveClass)
                        @include('admin.live_class._offering_session', ['liveClass' => $liveClass])
                    @endforeach
                </section>
            @endif
        @endforeach
    @endif
</section>
<section class="eSection-wrap mb-3" id="offering-details"><h5>Offering Details</h5>@if($editable && auth()->user()->hasPermission('academic.course_offering.manage'))
<form method="POST" action="{{ route('admin.course_offerings.update',$offering->id) }}" class="row g-2">@csrf @method('PUT')<div class="col-md-4"><label class="form-label">Course Unit</label><select class="form-select" name="subject_id">@foreach($subjects as $subject)<option value="{{ $subject->id }}" @selected($offering->subject_id==$subject->id)>{{ $subject->code ? $subject->code.' — ' : '' }}{{ $subject->name }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">Academic Year</label><select class="form-select" name="academic_year_id" id="edit-year">@foreach($years as $year)<option value="{{ $year->id }}" @selected($offering->academic_year_id==$year->id)>{{ $year->label }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">Academic Period</label><select class="form-select" name="academic_period_id" id="edit-period">@foreach($periods as $period)<option data-year="{{ $period->academic_year_id }}" value="{{ $period->id }}" @selected($offering->academic_period_id==$period->id)>{{ $period->label }}</option>@endforeach</select></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary">Save</button></div><div class="col-12"><div class="form-text">Offering Reference: <strong>{{ $offering->reference ?: '—' }}</strong> — generated automatically and cannot be edited.</div></div></form>
@else<dl class="row mb-0"><dt class="col-sm-3">Offering reference</dt><dd class="col-sm-9">{{ $offering->reference ?: '—' }}</dd></dl>@endif</section>
<section class="eSection-wrap mb-3" id="applicability"><h5>Study Plan Applicability</h5><p class="text-muted">A Study Plan linked here determines which students may register for this Course Offering; teaching staff are managed separately.</p><div class="table-responsive"><table class="table"><thead><tr><th>Programme</th><th>Study Plan</th><th>Stage / Year of Study</th><th>{{ $periodLabel }}</th><th></th></tr></thead><tbody>@forelse($links as $link)<tr><td>{{ $link->programme_code }} — {{ $link->programme_name }}</td><td>Study Plan {{ $link->version }}</td><td>{{ $link->stage_label ?: 'Not recorded' }}</td><td>{{ ucfirst($link->period_type) }} {{ $link->period_sequence }}</td><td>@if($editable && auth()->user()->hasPermission('academic.course_offering.manage'))<form method="POST" action="{{ route('admin.course_offerings.applicability.destroy',[$offering->id,$link->curriculum_membership_id]) }}" onsubmit="return confirm('Remove this Study Plan from this Course Offering?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove</button></form>@endif</td></tr>@empty<tr><td colspan="5" class="text-muted">No Study Plan has been linked yet. Add the applicable Study Plan before opening this Course Offering.</td></tr>@endforelse</tbody></table></div>
@if($editable && auth()->user()->hasPermission('academic.course_offering.manage'))@if($candidates->isEmpty())<p class="text-muted mb-0">No compatible approved Study Plan entries are available to link yet.</p>@else<form method="POST" action="{{ route('admin.course_offerings.applicability.store',$offering->id) }}" class="row g-2">@csrf<div class="col-md-9"><label class="form-label">Add a compatible Study Plan</label><select class="form-select" name="curriculum_membership_id" required><option value="">Choose a Study Plan entry</option>@foreach($candidates as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->programme_code }} — Study Plan {{ $candidate->version }} — {{ $candidate->stage_label ?: 'Placement' }} · {{ ucfirst($candidate->period_type) }} {{ $candidate->period_sequence }}</option>@endforeach</select>@if($candidates->count()===100)<small class="text-muted">Showing first 100 candidates; narrow selection in the Programme Study Plan before adding.</small>@endif</div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary">Link Study Plan</button></div></form>@endif @endif</section>
<section class="eSection-wrap mb-3" id="lifecycle"><h5>Lifecycle</h5><p class="text-muted">Draft → Open → In progress → Completed. Open, in-progress and draft Offerings may be cancelled. Open freezes identity and applicability.</p>
<p class="text-muted"><strong>Open</strong> means preparation and registration: an assigned lecturer may prepare academic delivery. <strong>In progress</strong> means active teaching and delivery.</p>
@if($editable && auth()->user()->hasPermission('academic.course_offering.lifecycle'))<form method="POST" action="{{ route('admin.course_offerings.open',$offering->id) }}" class="d-inline" onsubmit="return confirm('Open this Offering? Identity and applicability will be frozen.')">@csrf<button class="btn btn-success">Open Offering</button></form>@endif
@if($offering->status==='open' && auth()->user()->hasPermission('academic.course_offering.lifecycle'))
    @if($canStartEarly)
    {{-- Governed early start: an audited, reasoned exception to date
         governance. It moves ONLY this Offering's status. The Academic Period,
         Study Plan, Programme Cohort, Academic Placement, registrations and
         lecturer allocation dates are all left exactly as they are. --}}
    <div class="alert alert-warning mb-3" role="status">
        <strong>{{ $periodLabel }} has not begun yet.</strong>
        Teaching normally cannot start before the Academic Period begins. An authorised administrator may start this Course Offering early for a documented reason; the Academic Period dates are not changed and the reason is recorded in the audit trail.
    </div>
    <form method="POST" action="{{ route('admin.course_offerings.start_early',$offering->id) }}" class="mt-3" onsubmit="return confirm('Start this Course Offering before {{ $periodLabel }} begins? The Academic Period dates will not be changed, and this reason is recorded in the audit trail.')">
        @csrf
        <label class="form-label" for="early_start_reason">Reason for starting before {{ $periodLabel }}</label>
        <input id="early_start_reason" name="reason" class="form-control mb-2" required maxlength="1000" placeholder="e.g. Pre-semester end-to-end academic delivery testing.">
        <button class="btn btn-warning">Start Course Offering Early</button>
    </form>
    @elseif(! $academicPeriodHasBegun)
    <div class="alert alert-secondary mb-3" role="status">
        This Course Offering cannot be started yet: {{ $periodLabel }} has not begun, and an early start is not available to you.
        @if($earlyStartBlockers)<ul class="mb-0 mt-1">@foreach($earlyStartBlockers as $earlyBlocker)<li>{{ $earlyBlocker }}</li>@endforeach</ul>@endif
    </div>
    @else
    <form method="POST" action="{{ route('admin.course_offerings.start',$offering->id) }}" class="d-inline" onsubmit="return confirm('Start this Course Offering? This marks teaching as under way.')">@csrf<button class="btn btn-primary">Start Course Offering</button></form>
    @endif
@elseif($offering->status==='open' && ! $academicPeriodHasBegun)
<div class="alert alert-secondary mb-3" role="status">
    {{ $periodLabel }} has not begun yet, so teaching cannot start. Open means preparation and registration: the assigned lecturer may still prepare academic delivery.
</div>
@endif
@if($offering->status==='in_progress' && auth()->user()->hasPermission('academic.course_offering.lifecycle'))<form method="POST" action="{{ route('admin.course_offerings.complete',$offering->id) }}" class="d-inline" onsubmit="return confirm('Complete this Course Offering?')">@csrf<button class="btn btn-dark">Complete Course Offering</button></form>@endif
@if(in_array($offering->status,['draft','open','in_progress'],true) && auth()->user()->hasPermission('academic.course_offering.lifecycle'))<form method="POST" action="{{ route('admin.course_offerings.cancel',$offering->id) }}" class="mt-3" onsubmit="return confirm('Cancel this Offering? This is terminal and preserves history.')">@csrf<label class="form-label" for="reason">Cancellation reason</label><textarea id="reason" name="reason" class="form-control mb-2" required maxlength="1000"></textarea><button class="btn btn-outline-danger">Cancel Offering</button></form>@endif
@if($completionBlockers)
<div class="alert alert-warning mb-3" role="status">
    <strong>This Course Offering cannot be completed yet.</strong>
    <ul class="mb-0 mt-1">@foreach($completionBlockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
</div>
@endif
@if(in_array($offering->status,['completed','cancelled'],true))<p class="mb-0">Terminal historical record. This {{ \App\Support\CourseOffering\CourseOfferingService::statusLabel($offering->status) }} Course Offering is read-only; its teaching, registration and audit history is retained.@if($offering->status==='cancelled') <strong>Cancellation reason:</strong> {{ $cancellationReason ?: 'Not recorded.' }}@endif</p>@endif
</section>
<section class="eSection-wrap"><h5>Audit / History</h5><div class="table-responsive"><table class="table"><thead><tr><th>When</th><th>Action</th><th>Description</th></tr></thead><tbody>@forelse($audit as $event)<tr><td>{{ $event->created_at }}</td><td>{{ $event->action }}</td><td>{{ $event->description }}</td></tr>@empty<tr><td colspan="3" class="text-muted">No audit entries available.</td></tr>@endforelse</tbody></table></div></section>
@endsection
