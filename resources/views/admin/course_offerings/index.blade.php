@extends('admin.navigation')
@section('content')
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>Course Offerings</h4>
            <p class="text-muted mb-0">Manage the {{ \Illuminate\Support\Str::plural($courseUnitLabel) }} being taught in each Academic Year and {{ $periodLabel }}, including teaching teams and student registration.</p>
        </div>
        @if(auth()->user()->hasPermission('academic.course_offering.manage'))
            <a class="eBtn eBtn-primary text-nowrap" href="{{ route('admin.course_offerings.create', request()->only('year_id','period_id')) }}">Create Course Offering</a>
        @endif
    </div>
</div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="eSection-wrap">
    <form method="GET" class="row g-2 mb-3" aria-label="Filter Course Offerings">
        <div class="col-12 col-sm-6 col-xl-4"><label class="form-label" for="offering-search">Search {{ $courseUnitLabel }} name or code</label><input id="offering-search" class="form-control" type="search" name="search" value="{{ request('search') }}" placeholder="Search"></div>
        <div class="col-12 col-sm-6 col-xl-4"><label class="form-label" for="offering-course-unit">{{ $courseUnitLabel }}</label><select id="offering-course-unit" class="form-select" name="subject_id"><option value="">All {{ \Illuminate\Support\Str::plural($courseUnitLabel) }}</option>@foreach($courseUnits as $courseUnit)<option value="{{ $courseUnit->id }}" @selected((string)request('subject_id')===(string)$courseUnit->id)>{{ $courseUnit->code ? $courseUnit->code.' — ' : '' }}{{ $courseUnit->name }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-year">Academic Year</label><select id="offering-year" class="form-select" name="year_id"><option value="">All Academic Years</option>@foreach($years as $year)<option value="{{ $year->id }}" @selected((string)request('year_id')===(string)$year->id)>{{ $year->label }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-period">{{ $periodLabel }}</label><select id="offering-period" class="form-select" name="period_id"><option value="">All {{ \Illuminate\Support\Str::plural($periodLabel) }}</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected((string)request('period_id')===(string)$period->id)>{{ $period->label }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-programme">Programme</label><select id="offering-programme" class="form-select" name="programme_id"><option value="">All Programmes</option>@foreach($programmes as $programme)<option value="{{ $programme->id }}" @selected((string)request('programme_id')===(string)$programme->id)>{{ $programme->code }} — {{ $programme->name }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-curriculum">Programme Study Plan</label><select id="offering-curriculum" class="form-select" name="curriculum_id"><option value="">All Programme Study Plans</option>@foreach($curricula as $curriculum)<option value="{{ $curriculum->id }}" @selected((string)request('curriculum_id')===(string)$curriculum->id)>{{ $curriculum->programme->code ?? '' }} · Study Plan {{ $curriculum->version }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-department">Department</label><select id="offering-department" class="form-select" name="department_id"><option value="">All Departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string)request('department_id')===(string)$department->id)>{{ $department->name }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-status">Status</label><select id="offering-status" class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Models\CourseOffering::STATUSES as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ str_replace('_',' ',ucfirst($status)) }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-reference">Reference</label><input id="offering-reference" class="form-control" name="reference" value="{{ request('reference') }}" placeholder="Reference"></div>
        <div class="col-12 col-xl-4 d-flex justify-content-end align-items-end gap-2">
            <button type="submit" class="eBtn eBtn-primary">Filter Course Offerings</button>
            @if(request()->hasAny(['search','subject_id','year_id','period_id','programme_id','curriculum_id','department_id','status','reference']))<a class="btn btn-outline-secondary" href="{{ route('admin.course_offerings.index') }}">Clear filters</a>@endif
        </div>
    </form>
    <div class="table-responsive">
        <table class="table eTable">
            <thead><tr><th scope="col">Reference / ID</th><th scope="col">{{ $courseUnitLabel }}</th><th scope="col">Academic Year</th><th scope="col">{{ $periodLabel }}</th><th scope="col">Applicable Programme Study Plans</th><th scope="col">Status</th><th scope="col">Updated</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
                @forelse($offerings as $offering)
                    <tr><td>{{ $offering->reference ?: '#'.$offering->id }}</td><td>{{ $offering->subject->code ? $offering->subject->code.' — ' : '' }}{{ $offering->subject->name }}</td><td>{{ $offering->academicYear->label }}</td><td>{{ $offering->academicPeriod->label }}</td><td>{{ $offering->applicability_summary ?: '—' }}</td><td><span class="badge bg-{{ $offering->status==='open'?'success':($offering->status==='draft'?'warning text-dark':($offering->status==='cancelled'?'secondary':'primary')) }}">{{ str_replace('_',' ',ucfirst($offering->status)) }}</span></td><td>{{ $offering->updated_at?->format('Y-m-d') }}</td><td><a class="btn btn-sm btn-outline-primary text-nowrap" href="{{ route('admin.course_offerings.show',$offering->id) }}">View</a></td></tr>
                @empty
                    @if($hasAnyOfferings)
                        <tr><td colspan="8" class="text-center py-4"><p class="mb-2">No Course Offerings match these filters.</p><a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.course_offerings.index') }}">Clear filters</a></td></tr>
                    @else
                        <tr><td colspan="8" class="text-center py-4"><p class="mb-2">No Course Offerings have been created yet.</p>@if(auth()->user()->hasPermission('academic.course_offering.manage'))<a class="eBtn eBtn-primary text-nowrap" href="{{ route('admin.course_offerings.create') }}">Create Course Offering</a>@endif</td></tr>
                    @endif
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $offerings->links() }}
</div>
@endsection
