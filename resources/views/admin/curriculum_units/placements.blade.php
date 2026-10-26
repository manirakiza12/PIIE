<section class="eSection-wrap mb-3" id="course-units">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h5>{{ $courseUnitLabel }} placements</h5><small class="text-muted">A placement assigns a shared catalogue Course Unit to a Programme Year and relative period. Placement settings apply to this curriculum only.</small></div></div>
    @include('admin.subject.study_plan_picker')

    @forelse($curriculum->stages as $stage)
        <details open class="border rounded mb-2" id="curriculum-year-{{ $stage->id }}"><summary class="p-2 fw-bold">{{ $stage->sequence }}. {{ $stage->label }} ({{ $stage->memberships->count() }})</summary>
            @forelse($stage->memberships as $membership)
                <div class="border-top p-3">
                    <div class="d-flex justify-content-between"><div><strong>{{ $membership->subject->code ?: '—' }} — {{ $membership->subject->name }}</strong><div class="small text-muted">{{ $periodLabel }} {{ $membership->period_sequence ?: '—' }} · {{ ucfirst($membership->classification) }} · {{ number_format((float)$membership->credits, 2) }} credits</div></div>
                    @if($canManage)<div class="d-flex flex-wrap gap-2">@if(auth()->user()->hasPermission('academic.structure'))<a class="btn btn-sm btn-outline-primary" href="{{ route('admin.edit.subject', ['id' => $membership->subject_id, 'curriculum_id' => $curriculum->id, 'curriculum_stage_id' => $stage->id]) }}">Edit Course Unit</a>@endif<form method="POST" action="{{ route('admin.curricula.memberships.destroy', [$curriculum->id, $membership->id]) }}" onsubmit="return confirm('{{ get_phrase('Remove this Course Unit placement from the Curriculum? The shared catalogue unit and its other placements are preserved.') }}')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Remove placement</button></form></div>@endif</div>
                    @if($canManage)
                    <div class="small fw-semibold text-primary mt-3">{{ get_phrase('Edit Curriculum Placement') }}</div>
                    <p class="small text-muted mb-2">{{ get_phrase('Changes here apply only to this curriculum: Programme Year, semester or term, credits, classification and order. Manage prerequisite relationships in the Prerequisites section below.') }}</p>
                    <form method="POST" action="{{ route('admin.curricula.memberships.update', [$curriculum->id, $membership->id]) }}" class="row g-2 mt-1" aria-label="Edit Curriculum Placement for {{ $membership->subject->name }}">@csrf @method('PUT')
                        <div class="col-lg-3"><label class="form-label small">{{ get_phrase('Programme Year') }}</label><select class="form-select form-select-sm" name="curriculum_stage_id">@foreach($curriculum->stages as $choice)<option value="{{ $choice->id }}" @selected($choice->id === $stage->id)>{{ $choice->sequence }}. {{ $choice->label }}</option>@endforeach</select></div>
                        <div class="col-lg-2"><label class="form-label small">{{ $periodLabel }} (relative)</label><input class="form-control form-control-sm" type="number" name="period_sequence" min="1" max="65535" value="{{ $membership->period_sequence }}" placeholder="—" onchange="this.form.elements.period_type.value=this.value ? '{{ $calendarPattern }}' : ''"><input type="hidden" name="period_type" value="{{ $membership->period_sequence ? $calendarPattern : '' }}"></div>
                        <div class="col-lg-2"><label class="form-label small">{{ get_phrase('Classification') }}</label><select class="form-select form-select-sm" name="classification"><option value="compulsory" @selected($membership->classification==='compulsory')>Compulsory</option><option value="elective" @selected($membership->classification==='elective')>Elective</option></select></div>
                        <div class="col-lg-2"><label class="form-label small">{{ get_phrase('Credits') }}</label><input class="form-control form-control-sm" type="number" min="0" max="9999.99" step="0.01" name="credits" value="{{ $membership->credits }}"></div>
                        <div class="col-lg-1"><label class="form-label small">{{ get_phrase('Order') }}</label><input class="form-control form-control-sm" type="number" min="0" name="sequence" value="{{ $membership->sequence }}"></div>
                        <div class="col-lg-2 d-flex align-items-end"><button class="btn btn-sm btn-outline-primary">{{ get_phrase('Save placement') }}</button></div>
                    </form>@endif
                </div>
            @empty<p class="p-3 text-muted mb-0">{{ get_phrase('No Course Units in this year.') }}</p>@endforelse
        </details>
    @empty<p class="text-muted">{{ get_phrase('Create a Programme Year in the Programme Years module before placing Course Units.') }}</p>@endforelse
</section>

<section class="eSection-wrap mb-3" id="prerequisites"><h5>{{ get_phrase('Prerequisites') }}</h5><p class="text-muted">{{ get_phrase('Prerequisites are relationships between Course Units in this Curriculum only.') }}</p>
    @if($canManage && $curriculum->memberships->count() > 1)
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
            @if($canManage)<form method="POST" action="{{ route('admin.curricula.prerequisites.destroy', $curriculum->id) }}">@csrf @method('DELETE')<input type="hidden" name="membership_id" value="{{ $membership->id }}"><input type="hidden" name="prerequisite_membership_id" value="{{ $required->id }}"><button class="btn btn-sm btn-outline-danger">{{ get_phrase('Remove') }}</button></form>@endif</div>
        @endforeach
    @endforeach
    @if(!$edgeCount)<p class="text-muted">{{ get_phrase('No prerequisites defined.') }}</p>@endif
</section>
