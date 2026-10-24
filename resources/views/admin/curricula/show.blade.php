@extends('admin.navigation')

@section('content')
@php
    $courseUnitLabel = app(\App\Support\TenantConfiguration::class)->terminology($curriculum->school)['course_unit'] ?? 'Course Unit';
    $periodLabel = $calendarPattern === 'semester' ? 'Semester' : 'Term';
    $isDraft = $curriculum->status === 'draft';
    $isOwner = \App\Support\Curriculum\CurriculumOwnerAccess::allows(auth()->user(), (int) $curriculum->school_id);
    $useUnifiedUnitSetup = $isDraft && $canManage && auth()->user()->hasPermission('academic.structure');
    $totalCredits = $curriculum->memberships->sum(fn ($membership) => (float) $membership->credits);
@endphp
<div class="mainSection-title" id="overview">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div><h4>{{ $curriculum->programme->code }} — {{ $curriculum->programme->name }}</h4>
            <p class="mb-1">{{ get_phrase('Curriculum Version') }} <strong>{{ $curriculum->version }}</strong> · {{ get_phrase('Applies From Academic Year') }}: <strong>{{ $curriculum->effectiveAcademicYear->label ?? '—' }}</strong></p>
            <span class="badge {{ $curriculum->status === 'approved' ? 'bg-success' : ($curriculum->status === 'retired' ? 'bg-secondary' : 'bg-warning text-dark') }}">{{ strtoupper($curriculum->status) }}</span>
        </div>
        <a class="eBtn eBtn-secondary" href="{{ route('admin.curricula.index', ['programme_id' => $curriculum->programme_id]) }}">{{ get_phrase('All versions for this Programme') }}</a>
    </div>
</div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><strong>{{ get_phrase('Please correct the following:') }}</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($isDraft)<div class="alert alert-warning"><strong>{{ get_phrase('Draft — not official') }}.</strong> {{ get_phrase('Managers can edit this version. Approval marks it official; edits to official or retired versions require a reason.') }}</div>
@elseif($isOwner)<div class="alert alert-info"><strong>Owner access.</strong> You can reopen this {{ $curriculum->status }} curriculum for editing{{ $curriculum->status === 'retired' ? ' or restore it to Approved' : '' }} using the controls below.</div>
@elseif($curriculum->status === 'approved')<div class="alert alert-success"><strong>{{ get_phrase('Official approved Curriculum') }}.</strong> {{ get_phrase('Managers may correct this version; provide a reason for saved changes.') }}</div>
@else<div class="alert alert-secondary"><strong>{{ get_phrase('Retired historical Curriculum') }}.</strong> {{ get_phrase('Managers may correct this version; provide a reason for saved changes.') }}</div>@endif



@if($isOwner && !$isDraft)
<section class="eSection-wrap mb-3" id="owner-controls"><h5>Owner curriculum controls</h5>
    <p>Reopen this same version to change its details, Programme Years, Course Units, credits and prerequisites. Approve it again when finished to enable new placements, allocation links and student registration or confirmation. Existing records are retained. Your reason and the current structure are recorded in the audit history.</p>
    <form method="POST" action="{{ route('admin.curricula.owner_status', $curriculum->id) }}">@csrf
        <div class="mb-3"><label for="owner-status" class="form-label">Action</label><select id="owner-status" name="status" class="form-select"><option value="draft">Reopen for Editing</option>@if($curriculum->status === 'retired')<option value="approved">Restore to Approved</option>@endif</select></div>
        <div class="mb-3"><label for="owner-reason" class="form-label">Reason</label><textarea id="owner-reason" name="reason" class="form-control" maxlength="500" required>{{ old('reason') }}</textarea></div>
        <button class="btn btn-primary">Apply Owner Change</button>
    </form>
</section>
@endif
<section class="eSection-wrap mb-3" aria-labelledby="metadata-heading">
    <h5 id="metadata-heading">{{ get_phrase('Curriculum Details') }}</h5>
    @if($canManage)
    <form method="POST" action="{{ route('admin.curricula.update', $curriculum->id) }}" class="row g-2">@csrf @method('PUT')
        <div class="col-md-4"><label class="form-label">{{ get_phrase('Programme') }}</label><input class="form-control" readonly value="{{ $curriculum->programme->code }} — {{ $curriculum->programme->name }}"></div>
        <div class="col-md-3"><label class="form-label">{{ get_phrase('Version') }}</label><input class="form-control" name="version" maxlength="50" required value="{{ old('version', $curriculum->version) }}"></div>
        <div class="col-md-3"><label class="form-label">{{ get_phrase('Applies From Academic Year') }}</label><select class="form-select" name="effective_academic_year_id"><option value="">{{ get_phrase('Choose later') }}</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((string)$curriculum->effective_academic_year_id === (string)$year->id)>{{ $year->label }} ({{ $year->start_date->format('d/m/y') }} — {{ $year->end_date->format('d/m/y') }})</option>@endforeach</select></div>
        @if(!$isDraft)<div class="col-md-12"><label class="form-label">Reason for changing this {{ $curriculum->status }} version</label><textarea class="form-control" name="reason" maxlength="500" required>{{ old('reason') }}</textarea></div>@endif
        <div class="col-md-2 d-flex align-items-end"><button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Save changes') }}</button></div>
    </form>
    @else<p class="mb-0">{{ get_phrase('Programme') }}: {{ $curriculum->programme->code }} — {{ $curriculum->programme->name }} · {{ get_phrase('Department') }}: {{ $curriculum->programme->department->name ?? '—' }} · {{ get_phrase('Version') }}: {{ $curriculum->version }}</p>@endif
</section>

<section class="eSection-wrap mb-3"><h5>Curriculum structure</h5><p class="text-muted">Manage Programme Years and Course Units in their standalone modules. This overview shows the structure used for approval.</p><p class="mb-0">An approved curriculum can be reused in later Academic Years without editing it. Create separate Course Allocations for each teaching period.</p></section>

<section class="eSection-wrap mb-3" id="review"><h5>{{ get_phrase('Review before approval') }}</h5>
    <div class="row g-2 mb-3"><div class="col-md-3"><strong>{{ get_phrase('Programme') }}</strong><br>{{ $curriculum->programme->code }} — {{ $curriculum->programme->name }}</div><div class="col-md-2"><strong>{{ get_phrase('Version') }}</strong><br>{{ $curriculum->version }}</div><div class="col-md-3"><strong>{{ get_phrase('Applies From Academic Year') }}</strong><br>{{ $curriculum->effectiveAcademicYear->label ?? '—' }}</div><div class="col-md-2"><strong>{{ get_phrase('Years / Course Units') }}</strong><br>{{ $curriculum->stages->count() }} / {{ $curriculum->memberships->count() }}</div><div class="col-md-2"><strong>{{ get_phrase('Total credits') }}</strong><br>{{ number_format($totalCredits, 2) }}</div></div>
    <h6>{{ get_phrase('Hard blockers') }}</h6>
    @if($reviewBlockers)<ul class="text-danger">@foreach($reviewBlockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>@else<p class="text-success">{{ get_phrase('No known approval blockers. The server will revalidate all domain rules at approval time.') }}</p>@endif
    <h6>{{ get_phrase('Warnings') }}</h6><ul><li>{{ get_phrase('Confirm the version, Programme Years, relative periods and credit values with the institution before approval.') }}</li><li>{{ get_phrase('Relative periods are Curriculum positions, not dated or scheduled Academic Period instances.') }}</li></ul>
    @foreach($curriculum->stages as $stage)<h6>{{ $stage->sequence }}. {{ $stage->label }}</h6><ul>@forelse($stage->memberships as $membership)<li>{{ $membership->subject->code ?: '—' }} — {{ $membership->subject->name }} · {{ ucfirst($membership->classification) }} · {{ number_format((float)$membership->credits,2) }} credits · {{ $periodLabel }} {{ $membership->period_sequence ?: '—' }} · {{ $membership->prerequisites->count() }} prerequisite(s)</li>@empty<li class="text-muted">{{ get_phrase('No Course Units') }}</li>@endforelse</ul>@endforeach
    @if($isDraft && $canApprove)
        <form method="POST" action="{{ route('admin.curricula.approve', $curriculum->id) }}" onsubmit="return confirm('{{ get_phrase('Approve this Curriculum? It becomes official and immutable. Material changes will require a successor version.') }}')">@csrf<button class="eBtn eBtn-success" @disabled($reviewBlockers)>{{ get_phrase('Approve Curriculum') }}</button></form>
    @elseif($isDraft)<p class="text-muted">{{ get_phrase('Approval is available to users with Curriculum approval permission.') }}</p>@endif
</section>

@if($curriculum->status === 'approved' && $canApprove)
<section class="eSection-wrap mb-3"><h5>{{ get_phrase('Retirement') }}</h5><p>{{ get_phrase('Retirement preserves this official Curriculum and does not delete Subjects or rewrite history.') }}</p><form method="POST" action="{{ route('admin.curricula.retire', $curriculum->id) }}" onsubmit="return confirm('{{ get_phrase('Retire this official Curriculum? The historical record will remain read-only.') }}')">@csrf<button class="eBtn eBtn-danger">{{ get_phrase('Retire Curriculum') }}</button></form></section>
@endif

@if(in_array($curriculum->status, ['approved', 'retired'], true) && $canManage)
<section class="eSection-wrap"><h5>{{ get_phrase('Create successor version') }}</h5><p>{{ get_phrase('A successor is a new draft. This historical version remains unchanged; students are not assigned automatically.') }}</p>
    <form method="POST" action="{{ route('admin.curricula.successor', $curriculum->id) }}" class="row g-2">@csrf
        <div class="col-md-3"><label class="form-label">{{ get_phrase('New version identifier') }}</label><input class="form-control" name="version" maxlength="50" required></div>
        <div class="col-md-3"><label class="form-label">{{ get_phrase('Applies From Academic Year') }}</label><select class="form-select" name="effective_academic_year_id"><option value="">{{ get_phrase('Choose later') }}</option>@foreach($academicYears as $year)<option value="{{ $year->id }}">{{ $year->label }} ({{ $year->start_date->format('d/m/y') }} — {{ $year->end_date->format('d/m/y') }})</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">{{ get_phrase('Starting structure') }}</label><select class="form-select" name="mode"><option value="clone">{{ get_phrase('Clone previous version') }}</option><option value="blank">{{ get_phrase('Start blank') }}</option></select></div>
        <div class="col-md-3 d-flex align-items-end"><button class="eBtn eBtn-primary">{{ get_phrase('Create successor Curriculum') }}</button></div>
    </form>
</section>
@endif
@if($canManage)
<section class="eSection-wrap mt-3"><h5>Delete Programme Curriculum</h5><p class="text-danger">This permanently removes {{ $curriculum->programme->code }} {{ $curriculum->version }}, its Programme Years and Course Unit placements, student curriculum assignments, cohort links, applicability links, registrations and dependent registration workflows. Shared Course Units, other curriculum versions and student accounts remain. This cannot be undone.</p><form method="POST" action="{{ route('admin.curricula.destroy', $curriculum->id) }}">@csrf @method('DELETE')<label class="form-label" for="confirm-curriculum-delete">Type DELETE or {{ $curriculum->programme->code }} {{ $curriculum->version }}</label><input class="form-control mb-2" id="confirm-curriculum-delete" name="confirm_deletion" required><button class="btn btn-outline-danger">Permanently delete version</button></form></section>
@endif
@endsection
