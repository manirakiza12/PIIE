@extends('admin.navigation')

@section('page_styles')<link rel="stylesheet" href="{{ asset('assets/css/curriculum-workspace.css') }}">@endsection
@section('content')
<div class="curriculum-workspace">
@php
    $courseUnitLabel = app(\App\Support\TenantConfiguration::class)->terminology($curriculum->school)['course_unit'] ?? 'Course Unit';
    $periodLabel = $calendarPattern === 'semester' ? 'Semester' : 'Term';
    $isDraft = $curriculum->status === 'draft';
    $isOwner = \App\Support\Curriculum\CurriculumOwnerAccess::allows(auth()->user(), (int) $curriculum->school_id);
    $canCreateAllocation = auth()->user()->hasPermission('academic.course_offering.manage');
    $canViewAllocations = auth()->user()->hasPermission('academic.course_offering.view');
    $stepState = [$detailsReady, $stageCount > 0, $unitsReady, $curriculum->status === 'approved'];
    $yearsUrl = route('admin.programme_years.index', ['curriculum_id' => $curriculum->id]);
    $unitsUrl = route('admin.curriculum_units.index', ['curriculum_id' => $curriculum->id]);
    $allocationUrl = route('admin.course_offerings.create', ['curriculum_id' => $curriculum->id]);
    $firstBlocker = strtolower($reviewBlockers[0] ?? '');
    $blockerTarget = str_contains($firstBlocker, 'academic year') || str_contains($firstBlocker, 'version') ? '#manage-curriculum'
        : ((str_contains($firstBlocker, 'course unit') || str_contains($firstBlocker, 'programme year') || str_contains($firstBlocker, 'credit') || str_contains($firstBlocker, 'period') || str_contains($firstBlocker, 'prerequisite')) ? $unitsUrl.'#course-units' : '#review');
    $blockerAction = $blockerTarget === '#manage-curriculum' ? 'Review curriculum details' : ($blockerTarget === '#review' ? 'Review blockers' : 'Fix Course Unit placements');
@endphp
<style>
    .curriculum-hero { background:linear-gradient(125deg,#f4f8ff,#fff 70%); border:1px solid #dce6f3; border-radius:14px; padding:1.5rem; }
    .curriculum-progress { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.75rem; }
    .curriculum-step { border:1px solid #dfe5ed; border-radius:10px; padding:.75rem; background:#fff; color:#64748b; }
    .curriculum-step.is-done { border-color:#a7d7bd; color:#146c43; background:#f2fbf6; }
    .curriculum-step.is-current { border:2px solid #2864c5; color:#174ea6; background:#f3f7ff; }
    .curriculum-summary { min-height:108px; border:1px solid #e4e9f0; border-radius:12px; padding:1rem; background:#fff; }
    .curriculum-summary strong { display:block; font-size:1.65rem; line-height:1.2; color:#1e293b; }
    .curriculum-table th { white-space:nowrap; }
    @media(max-width:650px) { .curriculum-progress { grid-template-columns:1fr 1fr; } .curriculum-hero { padding:1rem; } }
    @media print { .curriculum-no-print { display:none!important; } }
</style>
<header class="mainSection-title" id="overview">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <div class="text-uppercase small text-muted mb-1">Programme Curriculum · Version {{ $curriculum->version }}</div>
            <h3 class="mb-2">{{ $curriculum->programme->code }} — {{ $curriculum->programme->name }}</h3>
            <div class="d-flex align-items-center flex-wrap gap-2"><span>Effective Academic Year: <strong>{{ $curriculum->effectiveAcademicYear->label ?? 'Not set' }}</strong></span>
                <span class="badge {{ $curriculum->status === 'approved' ? 'bg-success' : ($curriculum->status === 'retired' ? 'bg-secondary' : 'bg-warning text-dark') }}">{{ ucfirst($curriculum->status) }}</span>
            </div>
        </div>
        <nav class="curriculum-no-print d-flex flex-wrap gap-2" aria-label="Curriculum navigation">
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.curricula.index', ['programme_id' => $curriculum->programme_id]) }}">All versions</a>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">Print / PDF</button>
        </nav>
    </div>
</header>
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}
    @if(session('curriculum_next_step') === 'years') <a class="alert-link ms-2" href="{{ $yearsUrl }}">Next: Add Programme Years</a>
    @elseif(session('curriculum_next_step') === 'placement') <a class="alert-link ms-2" href="{{ $unitsUrl }}#course-units">Add Another Course Unit</a><span class="mx-1">·</span><a class="alert-link" href="#review">Review Curriculum</a>
    @endif
</div>@endif
@if(session('curriculum_reopened'))<div class="alert alert-info" role="status">Curriculum reopened for editing. The change reason and before/after structure are recorded.</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><strong>Please correct the following:</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

<section class="curriculum-hero mb-3" aria-labelledby="setup-heading">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
        <div><h4 id="setup-heading" class="mb-1">Curriculum setup</h4><p class="text-muted mb-0">Progress reflects saved structure and current approval checks.</p></div>
        @if($nextStep === 'years')
            @if($canManage)<a class="btn btn-primary curriculum-no-print" href="{{ $yearsUrl }}">Add Programme Years</a>@else<button class="btn btn-primary" type="button" disabled aria-describedby="next-step-permission">Add Programme Years</button>@endif
        @elseif($nextStep === 'units')
            @if($canManage)<a class="btn btn-primary curriculum-no-print" href="{{ $unitsUrl }}">Add Course Units</a>@else<button class="btn btn-primary" type="button" disabled aria-describedby="next-step-permission">Add Course Units</button>@endif
        @elseif($nextStep === 'review')
            @if($canApprove)<a class="btn btn-primary curriculum-no-print" href="#review">Review &amp; Approve</a>@else<button class="btn btn-primary" type="button" disabled aria-describedby="next-step-permission">Ready for approval</button>@endif
        @elseif($nextStep === 'allocation')
            @if($canCreateAllocation)<a class="btn btn-primary curriculum-no-print" href="{{ $allocationUrl }}">Create Course Allocation</a>@else<button class="btn btn-primary" type="button" disabled aria-describedby="next-step-permission">Create Course Allocation</button>@endif
        @else
            @if($canManage)<a class="btn btn-outline-primary curriculum-no-print" href="{{ $blockerTarget }}">{{ $blockerAction }}</a>@else<button class="btn btn-outline-primary" type="button" disabled aria-describedby="next-step-permission">{{ $blockerAction }}</button>@endif
        @endif
    </div>
    @if(($nextStep === 'years' || $nextStep === 'units' || $nextStep === 'blockers') && !$canManage)<p id="next-step-permission" class="small text-muted mb-3">Curriculum management permission is required to change this setup.</p>@elseif($nextStep === 'review' && !$canApprove)<p id="next-step-permission" class="small text-muted mb-3">Curriculum approval permission is required to approve this version.</p>@elseif($nextStep === 'allocation' && !$canCreateAllocation)<p id="next-step-permission" class="small text-muted mb-3">Course Allocation management permission is required to create an allocation.</p>@endif
    <div class="curriculum-progress" aria-label="Curriculum setup progress">
        @foreach(['Curriculum Details', 'Programme Years', 'Course Units', 'Review & Approve'] as $index => $label)
            @php $done = $stepState[$index]; $current = ($nextStep === 'years' && $index===1) || ($nextStep === 'units' && $index===2) || (in_array($nextStep,['review','blockers']) && $index===3) || ($curriculum->status==='approved' && $index===3); @endphp
            <div class="curriculum-step {{ $done ? 'is-done' : '' }} {{ $current && !$done ? 'is-current' : '' }}" @if($current) aria-current="step" @endif><span class="small d-block">STEP {{ $index + 1 }}</span><strong>{{ $label }}</strong>
                @if($index===1)<small class="d-block mt-1">{{ $stageCount }} saved</small>@elseif($index===2)<small class="d-block mt-1">{{ $membershipCount }} placements saved</small>@endif
            </div>
        @endforeach
    </div>
    @if($nextStep === 'blockers')
        <div class="alert alert-warning mt-3 mb-0"><strong>Approval needs attention.</strong> {{ $reviewBlockers[0] ?? 'Review the curriculum structure before approval.' }} <a href="#review" class="alert-link">See all blockers</a></div>
    @elseif($curriculum->status === 'approved')
        <div class="mt-3 curriculum-no-print">@if($canViewAllocations)<a class="btn btn-sm btn-outline-primary" href="{{ route('admin.course_offerings.index', ['curriculum_id' => $curriculum->id]) }}">View Course Allocations</a>@else<button class="btn btn-sm btn-outline-primary" type="button" disabled aria-describedby="allocation-view-permission">View Course Allocations</button><span id="allocation-view-permission" class="small text-muted ms-2">Course Allocation view permission is required.</span>@endif</div>
    @endif
</section>

<div class="row g-3 mb-3" aria-label="Curriculum summary">
    <div class="col-12 col-md-4"><div class="curriculum-summary"><span class="text-muted">Programme Years</span><strong>{{ $stageCount }}</strong><small>{{ $stageCount ? 'Saved in this curriculum' : 'Add years to organize the study plan' }}</small></div></div>
    <div class="col-12 col-md-4"><div class="curriculum-summary"><span class="text-muted">{{ $courseUnitLabel }}</span><strong>{{ $membershipCount }}</strong><small>{{ $membershipCount ? 'Curriculum placements' : 'No units placed yet' }}</small></div></div>
    <div class="col-12 col-md-4"><div class="curriculum-summary"><span class="text-muted">Total credits</span><strong>{{ number_format($totalCredits, 2) }}</strong><small>Across saved placements</small></div></div>
</div>

<section class="eSection-wrap mb-3" aria-labelledby="structure-heading">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h4 id="structure-heading">Curriculum structure</h4><p class="text-muted mb-0">Course units are grouped by Programme Year and relative {{ strtolower($periodLabel) }}.</p></div>
        @if($isDraft && $canManage)<a class="btn btn-sm btn-outline-primary" href="{{ $stageCount ? $unitsUrl : $yearsUrl }}">{{ $stageCount ? 'Manage Course Units' : 'Manage Programme Years' }}</a>@endif
    </div>
    @forelse($curriculum->stages as $stage)
        <div class="mt-4"><h5>{{ $stage->sequence }}. {{ $stage->label }} <span class="badge bg-light text-dark">{{ $stage->memberships->count() }} units</span></h5>
            @if($stage->memberships->isEmpty())<div class="border rounded p-3 text-muted">No units placed in this Programme Year yet.@if($isDraft && $canManage) <a href="{{ $unitsUrl }}#course-units">Add Course Units</a>@endif</div>
            @else
                @php $periodGroups = $stage->memberships->sortBy(fn($m) => sprintf('%05d-%05d', $m->period_sequence ?? 99999, $m->sequence))->groupBy(fn($m) => $m->period_sequence ? ucfirst($m->period_type ?: strtolower($periodLabel)).' '.$m->period_sequence : 'Period not set'); @endphp
                @foreach($periodGroups as $periodName => $memberships)
                    <h6 class="text-muted mt-3">{{ $periodName }}</h6>
                    <div class="table-responsive"><table class="table table-sm table-striped align-middle curriculum-table"><thead><tr><th>{{ $courseUnitLabel }}</th><th>Classification</th><th>Credits</th><th>Prerequisites</th></tr></thead><tbody>
                        @foreach($memberships as $membership)<tr><td><strong>{{ $membership->subject->code ?: '—' }}</strong><br>{{ $membership->subject->name }}</td><td>{{ ucfirst($membership->classification) }}</td><td>{{ number_format((float)$membership->credits, 2) }}</td><td>{{ $membership->prerequisites->count() }}</td></tr>@endforeach
                    </tbody></table></div>
                @endforeach
            @endif
        </div>
    @empty
        <div class="border rounded p-4 text-center mt-3"><h5>No Programme Years yet</h5><p class="text-muted">Add the study years for this version, then place course units in each year.</p>@if($isDraft && $canManage)<a class="btn btn-primary" href="{{ $yearsUrl }}">Add Programme Years</a>@endif</div>
    @endforelse
</section>

@if($canManage || $isOwner)
<details class="eSection-wrap mb-3" id="manage-curriculum"><summary class="h5 mb-0">Manage Curriculum</summary>
    @if(!$isDraft)<p class="text-muted mt-3">This official version is read-only by default. Changes require a reason and are recorded in the audit history.</p>
        @if($canManage && !$isOwner)
            <form method="POST" action="{{ route('admin.curricula.update', $curriculum->id) }}" class="row g-3 mt-2">@csrf @method('PUT')
                <div class="col-md-3"><label class="form-label">Version</label><input class="form-control" name="version" maxlength="50" required value="{{ old('version', $curriculum->version) }}"></div>
                <div class="col-md-5"><label class="form-label">Effective Academic Year</label><select class="form-select" name="effective_academic_year_id"><option value="">Choose later</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((string)$curriculum->effective_academic_year_id === (string)$year->id)>{{ $year->label }} ({{ $year->start_date->format('d/m/y') }} — {{ $year->end_date->format('d/m/y') }})</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">Reason for official version change</label><textarea class="form-control" name="reason" maxlength="500" required>{{ old('reason') }}</textarea></div>
                <div class="col-12"><button class="btn btn-outline-primary">Save details with reason</button></div>
            </form>
        @endif
        @if($isOwner)
        <form method="POST" action="{{ route('admin.curricula.owner_status', $curriculum->id) }}" class="mt-3">@csrf
            <label class="form-label" for="owner-status">Owner action</label><select id="owner-status" name="status" class="form-select mb-2"><option value="draft">Reopen for editing</option>@if($curriculum->status === 'retired')<option value="approved">Restore to Approved</option>@endif</select><label class="form-label" for="owner-reason">Reason (required)</label><textarea id="owner-reason" name="reason" class="form-control mb-2" maxlength="500" required>{{ old('reason') }}</textarea><button class="btn btn-outline-primary">Apply owner change</button>
        </form>
        @endif
    @else
        @if($canManage)
            <form method="POST" action="{{ route('admin.curricula.update', $curriculum->id) }}" class="row g-3 mt-2">@csrf @method('PUT')
                <div class="col-md-3"><label class="form-label">Version</label><input class="form-control" name="version" maxlength="50" required value="{{ old('version', $curriculum->version) }}"></div>
                <div class="col-md-5"><label class="form-label">Effective Academic Year</label><select class="form-select" name="effective_academic_year_id"><option value="">Choose later</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((string)$curriculum->effective_academic_year_id === (string)$year->id)>{{ $year->label }} ({{ $year->start_date->format('d/m/y') }} — {{ $year->end_date->format('d/m/y') }})</option>@endforeach</select></div>
                @if(!$isDraft)<div class="col-md-4"><label class="form-label">Reason for official version change</label><textarea class="form-control" name="reason" maxlength="500" required>{{ old('reason') }}</textarea></div>@endif
                <div class="col-12"><button class="btn btn-outline-primary">Save details</button></div>
            </form>
        @endif
        @if(in_array($curriculum->status, ['approved','retired'], true) && $canManage)
            <form method="POST" action="{{ route('admin.curricula.successor', $curriculum->id) }}" class="row g-2 mt-4">@csrf
                <div class="col-md-3"><label class="form-label">New version identifier</label><input class="form-control" name="version" maxlength="50" required></div><div class="col-md-4"><label class="form-label">Effective Academic Year</label><select class="form-select" name="effective_academic_year_id"><option value="">Choose later</option>@foreach($academicYears as $year)<option value="{{ $year->id }}">{{ $year->label }}</option>@endforeach</select></div><input type="hidden" name="mode" value="clone"><div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-secondary">Create successor draft</button></div>
            </form>
        @endif
        @if($canManage)<div class="mt-4"><a class="btn btn-sm btn-outline-secondary" href="{{ $unitsUrl }}">Manage Programme Years and Course Units</a></div>@endif
    @endif
</details>
@endif

<section class="eSection-wrap mb-3" id="review"><h4>Review &amp; Approve</h4>
    @if($reviewBlockers)<div class="alert alert-warning"><strong>{{ count($reviewBlockers) }} approval blocker(s)</strong><ul class="mb-0 mt-2">@foreach($reviewBlockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
    @else<p class="text-success">No known blockers. Approval will run the full server-side validation again.</p>@endif
    @if($isDraft && $canApprove)
        <form method="POST" action="{{ route('admin.curricula.approve', $curriculum->id) }}" onsubmit="return confirm('Approve this Curriculum? It becomes official and material changes will require a successor version.')">@csrf<button class="btn btn-success" @disabled($reviewBlockers) aria-describedby="approval-help">Review &amp; Approve</button><small id="approval-help" class="d-block text-muted mt-2">{{ $reviewBlockers ? 'Resolve the blockers above before approval is available.' : 'The curriculum becomes official after approval.' }}</small></form>
    @elseif($isDraft)<p class="text-muted">Curriculum approval permission is required to approve this draft.</p>@endif
</section>

@if($curriculum->status === 'approved' && $canApprove)
<details class="eSection-wrap mb-3"><summary class="h5">Manage approval lifecycle</summary><p class="mt-3">Retirement preserves this official version and its history.</p><form method="POST" action="{{ route('admin.curricula.retire', $curriculum->id) }}" onsubmit="return confirm('Retire this official Curriculum? The historical record remains preserved.')">@csrf<button class="btn btn-outline-danger">Retire Curriculum</button></form></details>
@endif
@if($canManage)<section class="eSection-wrap"><h5>Delete Programme Curriculum</h5>@include('shared.academic_delete_button', ['deleteAction' => route('admin.curricula.destroy', $curriculum->id), 'deleteTitle' => 'Delete Programme Curriculum', 'deleteIdentity' => $curriculum->programme->code.' '.$curriculum->version.' — '.$curriculum->programme->name.' (record #'.$curriculum->id.')', 'deleteReference' => trim($curriculum->programme->code.' '.$curriculum->version), 'deleteRemoves' => 'This version’s Programme Years, Course Unit placements and owned configuration.', 'deleteKeeps' => 'Shared Course Units, other curricula, student/staff accounts, profiles, invoices, payments and historical records.'])</section>@endif
@include('shared.academic_delete_modal')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.location.hash === '#manage-curriculum') document.getElementById('manage-curriculum')?.setAttribute('open', 'open');
});
</script>
</div>
@endsection
