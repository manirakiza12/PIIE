@extends('admin.navigation')

@section('content')
<div class="mainSection-title"><div class="d-flex justify-content-between align-items-center flex-wrap gap-3"><div><h4>Programme Student Groups</h4><ul class="d-flex align-items-center eBreadcrumb-2"><li>Academics</li><li>Programmes</li></ul></div>
    @if(auth()->user()->hasPermission('academic.programme_cohort.manage'))<a class="btn btn-primary text-nowrap" href="{{ route('admin.programme_cohorts.create') }}">Create Programme Student Group</a>@endif
</div></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="eSection-wrap">
    <form method="GET" action="{{ route('admin.programme_cohorts.index') }}" class="row g-3 mb-3">
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="cohort-search">Search cohorts</label><input id="cohort-search" class="form-control" name="search" value="{{ request('search') }}" placeholder="Name or code"></div>
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="filter-programme">Programme</label><select id="filter-programme" class="form-select" name="programme_id"><option value="">All Programmes</option>@foreach($programmes as $item)<option value="{{ $item->id }}" @selected((string)request('programme_id')===(string)$item->id)>{{ $item->code }} — {{ $item->name }}</option>@endforeach</select></div>
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="filter-intake">Admissions Intake</label><select id="filter-intake" class="form-select" name="intake_session_id"><option value="">All Admissions Intakes</option>@foreach($intakes as $item)<option value="{{ $item->id }}" @selected((string)request('intake_session_id')===(string)$item->id)>{{ $item->name }}</option>@endforeach</select></div>
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="filter-entry-year">Starting Academic Year</label><select id="filter-entry-year" class="form-select" name="entry_academic_year_id"><option value="">All Starting Academic Years</option>@foreach($years as $item)<option value="{{ $item->id }}" @selected((string)request('entry_academic_year_id')===(string)$item->id)>{{ $item->label }}</option>@endforeach</select></div>
        <div class="col-12 col-sm-6 col-md-4 col-xl-3"><label class="form-label" for="filter-status">Status</label><select id="filter-status" class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Models\ProgrammeCohort::STATUSES as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
        <div class="col-12 col-sm-6 col-md-8 col-xl-9 d-flex align-items-end gap-2"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="{{ route('admin.programme_cohorts.index') }}">Clear filters</a></div>
    </form>
    @forelse($cohorts as $cohort)
        <article class="border rounded p-3 mb-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div class="min-width-0"><a class="fw-semibold text-break" href="{{ route('admin.programme_cohorts.show',$cohort->id) }}">{{ $cohort->name }}</a><div class="small text-muted">Student Group Code: {{ $cohort->code }}</div></div>
                <div class="d-flex align-items-center gap-3"><span class="badge bg-{{ $cohort->status==='active'?'success':($cohort->status==='draft'?'secondary':'dark') }}">{{ ucfirst($cohort->status) }}</span><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.programme_cohorts.show',$cohort->id) }}">Open Student Group</a></div>
            </div>
            <div class="row g-3 small">
                <div class="col-12 col-sm-6 col-xl-4"><span class="text-muted d-block">Programme</span><span class="text-break">{{ $cohort->programme?->code }} — {{ $cohort->programme?->name }}</span></div>
                <div class="col-12 col-sm-6 col-xl-4"><span class="text-muted d-block">Admissions Intake</span>{{ $cohort->intakeSession?->name }}</div>
                <div class="col-12 col-sm-6 col-xl-4"><span class="text-muted d-block">Starting Academic Year</span>{{ $cohort->entryAcademicYear?->label }}</div>
                <div class="col-12 col-sm-6 col-xl-4"><span class="text-muted d-block">Programme Curriculum</span>{{ $cohort->studyPlan?->version }} · {{ ucfirst($cohort->studyPlan?->status ?? '') }}</div>
                <div class="col-6 col-sm-3 col-xl-4"><span class="text-muted d-block">Current Students</span>{{ $cohort->members_count }}</div>
                <div class="col-6 col-sm-3 col-xl-4"><span class="text-muted d-block">Expected Completion</span>{{ $cohort->expected_completion_date?->format('M j, Y') ?? '—' }}</div>
            </div>
        </article>
    @empty
        <div class="text-center py-5"><h5>No Programme Student Groups match these filters</h5><p class="text-muted mb-3">A Programme Student Group groups students entering the same Programme through one Admissions Intake. Clear the filters or create a draft to get started.</p><div class="d-flex justify-content-center flex-wrap gap-2"><a class="btn btn-outline-secondary" href="{{ route('admin.programme_cohorts.index') }}">Clear filters</a>@if(auth()->user()->hasPermission('academic.programme_cohort.manage'))<a class="btn btn-primary" href="{{ route('admin.programme_cohorts.create') }}">Create Programme Student Group</a>@endif</div></div>
    @endforelse
    {{ $cohorts->links() }}
</div>
@endsection
