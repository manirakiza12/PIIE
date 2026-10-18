@extends('admin.navigation')

@section('content')
@php
    $academicPeriodLabel = ucfirst($school->academic_calendar_pattern ?: 'semester');
    $showPeriods = request('section') === 'semesters';
    $reopenDialog = in_array(old('_academic_dialog'), ['createAcademicYear', 'createAcademicPeriod'], true) ? old('_academic_dialog') : null;
@endphp
<div class="mainSection-title">
    <div class="row"><div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
            <div><h4>{{ $showPeriods ? $academicPeriodLabel.'s' : 'Academic Years' }}</h4><ul class="d-flex align-items-center eBreadcrumb-2"><li>Academic Setup</li></ul></div>
        </div>
    </div></div>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<div class="academic-calendar-page">
@if($years->groupBy(fn ($year) => mb_strtolower(trim($year->label)))->contains(fn ($group) => $group->count() > 1))
<div class="alert alert-warning" role="status">Some Academic Years share the same name. Check their dates before choosing a year. Existing records are preserved; do not create another copy.</div>
@endif

<div class="row g-3">
    <div class="col-lg-5">
        <div class="eSection-wrap {{ $showPeriods ? 'd-none' : '' }}">
            <button class="eBtn eBtn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createAcademicYear">Create Academic Year</button>
        </div>
        <div class="modal fade" data-compact-dialog id="createAcademicYear" tabindex="-1" aria-labelledby="createAcademicYearTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="createAcademicYearTitle">Create Academic Year</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <form method="post" action="{{ route('admin.academic_structure.years.store') }}">
                @csrf
                <input type="hidden" name="_academic_dialog" value="createAcademicYear">
                <div class="mb-3"><label class="form-label">Year label</label><input class="form-control" name="label" value="{{ old('label') }}" required maxlength="100" placeholder="2026/2027"></div>
                <div class="mb-3"><label class="form-label">Start date</label><input class="form-control" type="date" name="start_date" value="{{ old('start_date') }}" required></div>
                <div class="mb-3"><label class="form-label">End date</label><input class="form-control" type="date" name="end_date" value="{{ old('end_date') }}" required></div>
                <p class="text-muted">New academic years are created as Planned. Activate a year explicitly before selecting it as current.</p>
                <button class="eBtn eBtn-primary" type="submit">Create year</button>
            </form>
                </div>
            </div></div>
        </div>
        <div class="eSection-wrap mt-3">
            <h5>{{ $showPeriods ? 'Current '.$academicPeriodLabel : 'Current Academic Year' }}</h5>
            <form method="post" action="{{ route('admin.academic_structure.current') }}">
                @csrf
                <div class="mb-3"><label class="form-label">Current year</label><select class="form-select" name="current_academic_year_id" id="current_year"><option value="">No current year</option>@foreach($years->where('status', 'active') as $year)<option value="{{ $year->id }}" @selected((int)$school->current_academic_year_id === (int)$year->id)>{{ $year->label }} ({{ $year->start_date->format('Y-m-d') }} — {{ $year->end_date->format('Y-m-d') }})</option>@endforeach</select></div>
                @if($showPeriods)
                <input type="hidden" name="section" value="semesters">
                <div class="mb-3"><label class="form-label">Current {{ strtolower($academicPeriodLabel) }}</label><select class="form-select" name="current_academic_period_id" id="current_period"><option value="">No current period (break)</option>@foreach($years as $year)@foreach($year->periods->where('status', 'active') as $period)<option data-year="{{ $year->id }}" value="{{ $period->id }}" @selected((int)$school->current_academic_period_id === (int)$period->id)>{{ $year->label }} ({{ $year->start_date->format('Y-m-d') }}) — {{ ucfirst($period->type) }} {{ $period->sequence }} ({{ $period->start_date->format('Y-m-d') }} — {{ $period->end_date->format('Y-m-d') }})</option>@endforeach @endforeach</select></div>
                @else
                <input type="hidden" name="current_academic_period_id" id="current_period" value="{{ $school->current_academic_period_id }}">
                @endif
                <button class="eBtn eBtn-primary" type="submit">Save current selection</button>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="eSection-wrap {{ !$showPeriods ? 'd-none' : '' }}">
            <button class="eBtn eBtn-primary" type="button" data-bs-toggle="modal" data-bs-target="#createAcademicPeriod">Create {{ $academicPeriodLabel }}</button>
        </div>
        <div class="modal fade" data-compact-dialog id="createAcademicPeriod" tabindex="-1" aria-labelledby="createAcademicPeriodTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="createAcademicPeriodTitle">Create {{ $academicPeriodLabel }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif<p class="text-muted">Set the actual dates within the selected Academic Year.</p>
            <form method="post" action="{{ route('admin.academic_structure.periods.store') }}">
                @csrf
                <input type="hidden" name="_academic_dialog" value="createAcademicPeriod">
                <div class="mb-3"><label class="form-label">Academic year</label><select class="form-select" name="academic_year_id" required>@foreach($years as $year)<option value="{{ $year->id }}" @selected((string)old('academic_year_id', $school->current_academic_year_id) === (string)$year->id)>{{ $year->label }} ({{ $year->start_date->format('Y-m-d') }} — {{ $year->end_date->format('Y-m-d') }})</option>@endforeach</select></div>
                <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Type</label><select class="form-select" name="type" required>@foreach(['semester','term'] as $type)<option value="{{ $type }}" @selected(old('type', $school->academic_calendar_pattern) === $type)>{{ ucfirst($type) }}</option>@endforeach</select></div><div class="col-md-6 mb-3"><label class="form-label">Sequence</label><input class="form-control" type="number" min="1" name="sequence" value="{{ old('sequence', 1) }}" required></div></div>
                <div class="mb-3"><label class="form-label">Label</label><input class="form-control" name="label" value="{{ old('label') }}" required maxlength="100" placeholder="Semester 1 or Term 1"></div>
                <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Start date</label><input class="form-control" type="date" name="start_date" value="{{ old('start_date') }}" required></div><div class="col-md-6 mb-3"><label class="form-label">End date</label><input class="form-control" type="date" name="end_date" value="{{ old('end_date') }}" required></div></div>
                <p class="text-muted">New semesters or terms start as Planned. Activate one before selecting it as current.</p>
                <button class="eBtn eBtn-primary" type="submit">Create {{ strtolower($academicPeriodLabel) }}</button>
            </form>
                </div>
            </div></div>
        </div>
        <div class="eSection-wrap mt-3">
            <h5>{{ $showPeriods ? $academicPeriodLabel.'s by Academic Year' : 'Academic Years' }}</h5>
            @forelse($years->when($showPeriods && request('year_id'), fn ($items) => $items->where('id', request('year_id'))) as $year)
                <div class="border rounded p-3 mb-3"><div class="d-flex justify-content-between"><strong>{{ $year->label }}</strong><span>{{ ucfirst($year->status) }}</span></div><div class="text-muted">{{ $year->start_date->format('Y-m-d') }} — {{ $year->end_date->format('Y-m-d') }}</div>
                    @if(!$showPeriods)
                    @include('admin.academic_structure.actions', ['record' => $year, 'kind' => 'years'])
                    @endif
                    @if(!$showPeriods && $year->status === 'planned')
                        <form method="post" action="{{ route('admin.academic_structure.years.status', $year->id) }}" class="d-inline-block mt-2">@csrf<input type="hidden" name="status" value="active"><button class="btn btn-sm btn-outline-primary" type="submit">Activate year</button></form>
                    @endif
                    @if(!$showPeriods && $year->status === 'active' && ! $year->periods->contains(fn ($period) => in_array($period->status, ['planned', 'active'], true)))
                        <form method="post" action="{{ route('admin.academic_structure.years.status', $year->id) }}" class="d-inline-block mt-2">@csrf<input type="hidden" name="status" value="completed"><button class="btn btn-sm btn-outline-success" type="submit">Complete year</button></form>
                    @endif
                    @if(!$showPeriods && in_array($year->status, ['planned', 'active'], true))
                        <form method="post" action="{{ route('admin.academic_structure.years.status', $year->id) }}" class="d-inline-block mt-2" onsubmit="return confirm('Cancel this academic year? Historical academic records will be preserved.');">@csrf<input type="hidden" name="status" value="cancelled"><input type="hidden" name="confirm_cancellation" value="1"><button class="btn btn-sm btn-outline-danger" type="submit">Cancel year</button></form>
                    @endif
                    @if($showPeriods && $year->periods->isNotEmpty())<ul class="mb-0 mt-2">@foreach($year->periods as $period)<li class="border-top pt-3 mt-3"><strong>{{ $period->label }}</strong> · {{ $academicPeriodLabel }} {{ $period->sequence }} · {{ $period->start_date->format('Y-m-d') }} — {{ $period->end_date->format('Y-m-d') }} · {{ ucfirst($period->status) }}
                        @include('admin.academic_structure.actions', ['record' => $period, 'kind' => 'periods'])
                        @if($period->status === 'planned')
                            <form method="post" action="{{ route('admin.academic_structure.periods.status', $period->id) }}" class="d-inline-block">@csrf<input type="hidden" name="status" value="active"><button class="btn btn-sm btn-outline-primary" type="submit">Activate</button></form>
                        @elseif($period->status === 'active')
                            <form method="post" action="{{ route('admin.academic_structure.periods.status', $period->id) }}" class="d-inline-block">@csrf<input type="hidden" name="status" value="completed"><button class="btn btn-sm btn-outline-success" type="submit">Complete</button></form>
                        @endif
                        @if(in_array($period->status, ['planned', 'active'], true))
                            <form method="post" action="{{ route('admin.academic_structure.periods.status', $period->id) }}" class="d-inline-block" onsubmit="return confirm('Cancel this academic period? Historical academic records will be preserved.');">@csrf<input type="hidden" name="status" value="cancelled"><input type="hidden" name="confirm_cancellation" value="1"><button class="btn btn-sm btn-outline-danger" type="submit">Cancel</button></form>
                        @endif
                    </li>@endforeach</ul>@elseif($showPeriods)<p class="mb-0 mt-2 text-muted">No semesters or terms configured.</p>@endif
                </div>
            @empty<p class="text-muted mb-0">No academic years configured yet.</p>@endforelse
        </div>
    </div>
</div>
<script>
function filterCurrentPeriods() {
    const year = document.getElementById('current_year').value;
    const periods = document.getElementById('current_period');
    if (periods.tagName !== 'SELECT') return;
    Array.from(periods.options).forEach(option => {
        if (!option.dataset.year) return;
        option.hidden = option.dataset.year !== year;
        option.disabled = option.hidden;
        if (option.hidden && option.selected) periods.value = '';
    });
}
document.getElementById('current_year').addEventListener('change', function () {
    document.getElementById('current_period').value = '';
    filterCurrentPeriods();
});
filterCurrentPeriods();
@if($errors->any())
document.addEventListener('DOMContentLoaded', function () {
    const dialogId = @json($reopenDialog);
    if (dialogId && window.bootstrap) bootstrap.Modal.getOrCreateInstance(document.getElementById(dialogId)).show();
});
@endif
</script>
</div>
@endsection
