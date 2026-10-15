@extends('admin.navigation')

@section('content')
@php
    $courseUnitLabel = app(\App\Support\TenantConfiguration::class)->terminology($curriculum->school)['course_unit'] ?? 'Course Unit';
    $periodLabel = $calendarPattern === 'semester' ? 'Semester' : 'Term';
    $isDraft = $curriculum->status === 'draft';
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
        @if($curriculum->status === 'approved' && auth()->user()->hasPermission('academic.course_offering.manage'))
            <a class="eBtn eBtn-primary" href="{{ route('admin.course_offerings.create', ['curriculum_id' => $curriculum->id]) }}">{{ get_phrase('Create Course Offerings') }}</a>
        @endif
    </div>
</div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><strong>{{ get_phrase('Please correct the following:') }}</strong><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($isDraft)<div class="alert alert-warning"><strong>{{ get_phrase('Draft — not official') }}.</strong> {{ get_phrase('Changes are editable until approval. Approval makes the structure immutable.') }}</div>
@elseif($curriculum->status === 'approved')<div class="alert alert-success"><strong>{{ get_phrase('Official approved Curriculum') }}.</strong> {{ get_phrase('This academic structure is read-only. Material changes require a successor version.') }}</div>
@else<div class="alert alert-secondary"><strong>{{ get_phrase('Retired historical Curriculum') }}.</strong> {{ get_phrase('This record is preserved and read-only.') }}</div>@endif

<nav class="nav nav-pills flex-wrap gap-2 mb-3" aria-label="Curriculum sections">
    <a class="nav-link" href="#overview">{{ get_phrase('Overview') }}</a><a class="nav-link" href="#stages">{{ get_phrase('Programme Years & Stages') }}</a><a class="nav-link" href="#course-units">{{ $courseUnitLabel }}s</a><a class="nav-link" href="#prerequisites">{{ get_phrase('Prerequisites') }}</a><a class="nav-link" href="#review">{{ get_phrase('Review') }}</a>
</nav>

<section class="eSection-wrap mb-3" aria-labelledby="metadata-heading">
    <h5 id="metadata-heading">{{ get_phrase('Curriculum Details') }}</h5>
    @if($isDraft && $canManage)
    <form method="POST" action="{{ route('admin.curricula.update', $curriculum->id) }}" class="row g-2">@csrf @method('PUT')
        <div class="col-md-4"><label class="form-label">{{ get_phrase('Programme') }}</label><input class="form-control" readonly value="{{ $curriculum->programme->code }} — {{ $curriculum->programme->name }}"></div>
        <div class="col-md-3"><label class="form-label">{{ get_phrase('Version') }}</label><input class="form-control" name="version" maxlength="50" required value="{{ old('version', $curriculum->version) }}"></div>
        <div class="col-md-3"><label class="form-label">{{ get_phrase('Applies From Academic Year') }}</label><select class="form-select" name="effective_academic_year_id"><option value="">{{ get_phrase('Choose later') }}</option>@foreach($academicYears as $year)<option value="{{ $year->id }}" @selected((string)$curriculum->effective_academic_year_id === (string)$year->id)>{{ $year->label }} ({{ $year->start_date->format('Y-m-d') }} — {{ $year->end_date->format('Y-m-d') }})</option>@endforeach</select></div>
        <div class="col-md-2 d-flex align-items-end"><button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Save draft') }}</button></div>
    </form>
    @else<p class="mb-0">{{ get_phrase('Programme') }}: {{ $curriculum->programme->code }} — {{ $curriculum->programme->name }} · {{ get_phrase('Department') }}: {{ $curriculum->programme->department->name ?? '—' }} · {{ get_phrase('Version') }}: {{ $curriculum->version }}</p>@endif
</section>

<section class="eSection-wrap mb-3" id="stages">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h5>{{ get_phrase('Programme Years & Stages') }}</h5><small class="text-muted">{{ get_phrase('Arrange Year 1, Year 2 or foundation stages here. Student progression is managed separately.') }}</small></div>
    @if($isDraft && $canManage)
        <details class="border rounded p-3 mb-3 bg-light"><summary class="fw-bold">{{ get_phrase('Add Programme Year / Stage') }}</summary>
            <h6 id="add-stage-heading">{{ get_phrase('Add Programme Year / Stage') }}</h6>
            <p class="text-muted">{{ get_phrase('Create a separate stage in this Curriculum. Existing stages will not be changed.') }}</p>
            <form method="POST" action="{{ route('admin.curricula.stages.store', $curriculum->id) }}" class="row g-2">@csrf
                <div class="col-md-7"><label class="form-label">{{ get_phrase('Year / Year / Stage Name') }}</label><input class="form-control" name="label" required maxlength="100" placeholder="{{ get_phrase('Foundation, Year 1, Stage 1…') }}"></div>
                <div class="col-md-2"><label class="form-label">{{ get_phrase('Display Order') }}</label><input class="form-control" type="number" name="sequence" min="1" max="65535" required value="{{ $curriculum->stages->count() + 1 }}"></div>
                <div class="col-md-3 d-flex align-items-end"><button class="eBtn eBtn-primary" type="submit">{{ get_phrase('Add Programme Year / Stage') }}</button></div>
            </form>
        </details>
    @endif
    @forelse($curriculum->stages as $stage)
        <div class="border rounded p-3 mb-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2"><strong>{{ get_phrase('Stage') }} {{ $stage->sequence }} — {{ $stage->label }}</strong><span class="badge bg-light text-dark">{{ $stage->memberships->count() }} {{ $courseUnitLabel }}(s)</span></div>
            @if($isDraft && $canManage)
                <div class="border-top mt-3 pt-3" aria-labelledby="edit-stage-heading-{{ $stage->id }}">
                    <h6 id="edit-stage-heading-{{ $stage->id }}">{{ get_phrase('Edit Programme Year / Stage') }}: {{ $stage->label }}</h6>
                    <form method="POST" action="{{ route('admin.curricula.stages.update', [$curriculum->id, $stage->id]) }}" class="row g-2 mb-2">@csrf @method('PUT')
                        <div class="col-md-6"><label class="form-label">{{ get_phrase('Year / Stage Name') }}</label><input class="form-control form-control-sm" name="label" value="{{ $stage->label }}" maxlength="100" required></div>
                        <div class="col-md-2"><label class="form-label">{{ get_phrase('Position') }}</label><input class="form-control form-control-sm" type="number" name="sequence" value="{{ $stage->sequence }}" min="1" max="65535" required></div>
                        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary" type="submit">{{ get_phrase('Save Changes') }}</button></div>
                    </form>
                    <div class="d-flex flex-wrap gap-2">
                    @foreach(['up' => 'Move up', 'down' => 'Move down'] as $direction => $label)<form method="POST" action="{{ route('admin.curricula.stages.move', [$curriculum->id, $stage->id]) }}">@csrf<input type="hidden" name="direction" value="{{ $direction }}"><button class="btn btn-sm btn-outline-secondary" aria-label="{{ $label }} {{ $stage->label }}">{{ $label }}</button></form>@endforeach
                    <form method="POST" action="{{ route('admin.curricula.stages.destroy', [$curriculum->id, $stage->id]) }}" onsubmit="return confirm('{{ get_phrase('Remove this empty stage?') }}')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">{{ get_phrase('Remove empty stage') }}</button></form>
                    </div>
                </div>
            @endif
        </div>
    @empty<p class="text-muted">{{ get_phrase('No stages yet. An empty draft is allowed but cannot be approved.') }}</p>@endforelse
</section>

<section class="eSection-wrap mb-3" id="course-units">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><h5>{{ $courseUnitLabel }} / {{ get_phrase('Curriculum placement') }}</h5><small class="text-muted">{{ get_phrase('Manage credits, classification and prerequisites from Course Unit setup. Placements remain part of this Curriculum.') }}</small></div>
    @if($useUnifiedUnitSetup)
        <div class="d-flex flex-wrap gap-2 my-3">
            <a class="eBtn eBtn-primary" href="{{ route('admin.subject.setup', ['curriculum_id' => $curriculum->id, 'programme_id' => $curriculum->programme_id]) }}">{{ get_phrase('Add Course Unit') }}</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.subject_list', ['curriculum_id' => $curriculum->id]) }}">{{ get_phrase('Add existing Course Units') }}</a>
        </div>
    @else
        @include('admin.subject.study_plan_picker')
    @endif

    @forelse($curriculum->stages as $stage)
        <details open class="border rounded mb-2"><summary class="p-2 fw-bold">{{ $stage->sequence }}. {{ $stage->label }} ({{ $stage->memberships->count() }})</summary>
            @forelse($stage->memberships as $membership)
                <div class="border-top p-3">
                    <div class="d-flex justify-content-between"><div><strong>{{ $membership->subject->code ?: '—' }} — {{ $membership->subject->name }}</strong><div class="small text-muted">{{ $periodLabel }} {{ $membership->period_sequence ?: '—' }} · {{ ucfirst($membership->classification) }} · {{ number_format((float)$membership->credits, 2) }} credits</div></div>
                    @if($isDraft && $canManage && !$useUnifiedUnitSetup)<form method="POST" action="{{ route('admin.curricula.memberships.destroy', [$curriculum->id, $membership->id]) }}" onsubmit="return confirm('{{ get_phrase('Remove this Course Unit from the draft? Its prerequisite edges will also be removed.') }}')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">{{ get_phrase('Remove') }}</button></form>@endif</div>
                    @if($useUnifiedUnitSetup)
                        <a class="btn btn-sm btn-outline-primary mt-2" href="{{ route('admin.subject.setup', ['id' => $membership->subject_id, 'curriculum_id' => $curriculum->id]) }}">{{ get_phrase('Edit Course Unit setup') }}</a>
                    @elseif($isDraft && $canManage)
                    <form method="POST" action="{{ route('admin.curricula.memberships.update', [$curriculum->id, $membership->id]) }}" class="row g-2 mt-1">@csrf @method('PUT')
                        <div class="col-lg-3"><label class="form-label small">{{ get_phrase('Stage') }}</label><select class="form-select form-select-sm" name="curriculum_stage_id">@foreach($curriculum->stages as $choice)<option value="{{ $choice->id }}" @selected($choice->id === $stage->id)>{{ $choice->sequence }}. {{ $choice->label }}</option>@endforeach</select></div>
                        <div class="col-lg-2"><label class="form-label small">{{ $periodLabel }} (relative)</label><input class="form-control form-control-sm" type="number" name="period_sequence" min="1" max="65535" value="{{ $membership->period_sequence }}" placeholder="—" onchange="this.form.elements.period_type.value=this.value ? '{{ $calendarPattern }}' : ''"><input type="hidden" name="period_type" value="{{ $membership->period_sequence ? $calendarPattern : '' }}"></div>
                        <div class="col-lg-2"><label class="form-label small">{{ get_phrase('Classification') }}</label><select class="form-select form-select-sm" name="classification"><option value="compulsory" @selected($membership->classification==='compulsory')>Compulsory</option><option value="elective" @selected($membership->classification==='elective')>Elective</option></select></div>
                        <div class="col-lg-2"><label class="form-label small">{{ get_phrase('Credits') }}</label><input class="form-control form-control-sm" type="number" min="0" max="9999.99" step="0.01" name="credits" value="{{ $membership->credits }}"></div>
                        <div class="col-lg-1"><label class="form-label small">{{ get_phrase('Order') }}</label><input class="form-control form-control-sm" type="number" min="0" name="sequence" value="{{ $membership->sequence }}"></div>
                        <div class="col-lg-2 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary">{{ get_phrase('Save placement') }}</button></div>
                    </form>@endif
                </div>
            @empty<p class="p-3 text-muted mb-0">{{ get_phrase('No Course Units in this stage.') }}</p>@endforelse
        </details>
    @empty<p class="text-muted">{{ get_phrase('Add a stage before placing Course Units.') }}</p>@endforelse
</section>

<section class="eSection-wrap mb-3" id="prerequisites"><h5>{{ get_phrase('Prerequisites') }}</h5><p class="text-muted">{{ get_phrase('Prerequisites are relationships between Course Units in this Curriculum only.') }}</p>
    @if($isDraft && $canManage && !$useUnifiedUnitSetup && $curriculum->memberships->count() > 1)
    <form method="POST" action="{{ route('admin.curricula.prerequisites.store', $curriculum->id) }}" class="row g-2 mb-3">@csrf
        <div class="col-md-5"><label class="form-label">{{ get_phrase('Course Unit') }}</label><select class="form-select" name="membership_id" required>@foreach($curriculum->memberships as $membership)<option value="{{ $membership->id }}">{{ $membership->subject->code ?: '—' }} — {{ $membership->subject->name }}</option>@endforeach</select></div>
        <div class="col-md-5"><label class="form-label">{{ get_phrase('Requires first') }}</label><select class="form-select" name="prerequisite_membership_id" required>@foreach($curriculum->memberships as $membership)<option value="{{ $membership->id }}">{{ $membership->subject->code ?: '—' }} — {{ $membership->subject->name }}</option>@endforeach</select></div>
        <div class="col-md-2 d-flex align-items-end"><button class="eBtn eBtn-primary">{{ get_phrase('Add prerequisite') }}</button></div>
    </form>@endif
    @php $edgeCount=0; @endphp
    @foreach($curriculum->memberships as $membership)
        @foreach($membership->prerequisites as $required)
            @php $edgeCount++; @endphp
            <div class="d-flex justify-content-between border rounded p-2 mb-1"><span>{{ $membership->subject->code ?: '—' }} — {{ $membership->subject->name }} <span class="text-muted">{{ get_phrase('requires') }}</span> {{ $required->subject->code ?: '—' }} — {{ $required->subject->name }}</span>
            @if($isDraft && $canManage && !$useUnifiedUnitSetup)<form method="POST" action="{{ route('admin.curricula.prerequisites.destroy', $curriculum->id) }}">@csrf @method('DELETE')<input type="hidden" name="membership_id" value="{{ $membership->id }}"><input type="hidden" name="prerequisite_membership_id" value="{{ $required->id }}"><button class="btn btn-sm btn-outline-danger">{{ get_phrase('Remove') }}</button></form>@endif</div>
        @endforeach
    @endforeach
    @if(!$edgeCount)<p class="text-muted">{{ get_phrase('No prerequisites defined.') }}</p>@endif
</section>

<section class="eSection-wrap mb-3" id="review"><h5>{{ get_phrase('Review before approval') }}</h5>
    <div class="row g-2 mb-3"><div class="col-md-3"><strong>{{ get_phrase('Programme') }}</strong><br>{{ $curriculum->programme->code }} — {{ $curriculum->programme->name }}</div><div class="col-md-2"><strong>{{ get_phrase('Version') }}</strong><br>{{ $curriculum->version }}</div><div class="col-md-3"><strong>{{ get_phrase('Applies From Academic Year') }}</strong><br>{{ $curriculum->effectiveAcademicYear->label ?? '—' }}</div><div class="col-md-2"><strong>{{ get_phrase('Stages / Course Units') }}</strong><br>{{ $curriculum->stages->count() }} / {{ $curriculum->memberships->count() }}</div><div class="col-md-2"><strong>{{ get_phrase('Total credits') }}</strong><br>{{ number_format($totalCredits, 2) }}</div></div>
    <h6>{{ get_phrase('Hard blockers') }}</h6>
    @if($reviewBlockers)<ul class="text-danger">@foreach($reviewBlockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>@else<p class="text-success">{{ get_phrase('No known approval blockers. The server will revalidate all domain rules at approval time.') }}</p>@endif
    <h6>{{ get_phrase('Warnings') }}</h6><ul><li>{{ get_phrase('Confirm the version, stages, relative periods and credit values with the institution before approval.') }}</li><li>{{ get_phrase('Relative periods are Curriculum positions, not dated or scheduled Academic Period instances.') }}</li></ul>
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
        <div class="col-md-3"><label class="form-label">{{ get_phrase('Applies From Academic Year') }}</label><select class="form-select" name="effective_academic_year_id"><option value="">{{ get_phrase('Choose later') }}</option>@foreach($academicYears as $year)<option value="{{ $year->id }}">{{ $year->label }} ({{ $year->start_date->format('Y-m-d') }} — {{ $year->end_date->format('Y-m-d') }})</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">{{ get_phrase('Starting structure') }}</label><select class="form-select" name="mode"><option value="clone">{{ get_phrase('Clone previous version') }}</option><option value="blank">{{ get_phrase('Start blank') }}</option></select></div>
        <div class="col-md-3 d-flex align-items-end"><button class="eBtn eBtn-primary">{{ get_phrase('Create successor Curriculum') }}</button></div>
    </form>
</section>
@endif
@endsection
