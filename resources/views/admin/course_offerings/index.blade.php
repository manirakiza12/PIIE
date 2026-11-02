@extends('admin.navigation')
@section('content')
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4>Course Allocation</h4>
            <p class="text-muted mb-0">Opening a {{ strtolower($periodLabel) }} creates required Course Allocations from approved curricula automatically. Eligible students enrol automatically. Manage teaching staff inside each allocation; missing lecturers do not block enrolment.</p>
        </div>
        @if(auth()->user()->hasPermission('academic.course_offering.manage'))
            <a class="eBtn eBtn-primary text-nowrap" href="{{ route('admin.course_offerings.create', request()->only('year_id','period_id')) }}">Create Course Allocation</a>
        @endif
    </div>
</div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="eSection-wrap">
    <form method="GET" class="row g-2 mb-3" aria-label="Filter Course Allocations">
        <div class="col-12 col-sm-6 col-xl-4"><label class="form-label" for="offering-search">Search {{ $courseUnitLabel }} name or code</label><input id="offering-search" class="form-control" type="search" name="search" value="{{ request('search') }}" placeholder="Search"></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-year">Academic Year</label><select id="offering-year" class="form-select" name="year_id"><option value="">All Academic Years</option>@foreach($years as $year)<option value="{{ $year->id }}" @selected((string)request('year_id')===(string)$year->id)>{{ $year->label }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-period">{{ $periodLabel }}</label><select id="offering-period" class="form-select" name="period_id"><option value="">All {{ \Illuminate\Support\Str::plural($periodLabel) }}</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected((string)request('period_id')===(string)$period->id)>{{ $period->label }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-programme">Programme</label><select id="offering-programme" class="form-select" name="programme_id"><option value="">All Programmes</option>@foreach($programmes as $programme)<option value="{{ $programme->id }}" @selected((string)request('programme_id')===(string)$programme->id)>{{ $programme->code }} — {{ $programme->name }}</option>@endforeach</select></div>
        <details class="col-12" @if(request()->hasAny(['subject_id', 'curriculum_id', 'department_id', 'status', 'reference'])) open @endif><summary>More filters</summary><div class="row g-2 mt-1">
        <div class="col-12 col-sm-6 col-xl-4"><label class="form-label" for="offering-course-unit">{{ $courseUnitLabel }}</label><select id="offering-course-unit" class="form-select" name="subject_id"><option value="">All {{ \Illuminate\Support\Str::plural($courseUnitLabel) }}</option>@foreach($courseUnits as $courseUnit)<option value="{{ $courseUnit->id }}" @selected((string)request('subject_id')===(string)$courseUnit->id)>{{ $courseUnit->code ? $courseUnit->code.' — ' : '' }}{{ $courseUnit->name }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-curriculum">Programme Curriculum</label><select id="offering-curriculum" class="form-select" name="curriculum_id"><option value="">All Programme Curriculum</option>@foreach($curricula as $curriculum)<option value="{{ $curriculum->id }}" @selected((string)request('curriculum_id')===(string)$curriculum->id)>{{ $curriculum->programme->code ?? '' }} · Curriculum {{ $curriculum->version }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-department">Department</label><select id="offering-department" class="form-select" name="department_id"><option value="">All Departments</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((string)request('department_id')===(string)$department->id)>{{ $department->name }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-status">Status</label><select id="offering-status" class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Models\CourseOffering::STATUSES as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ str_replace('_',' ',ucfirst($status)) }}</option>@endforeach</select></div>
        <div class="col-6 col-xl-4"><label class="form-label" for="offering-reference">Reference</label><input id="offering-reference" class="form-control" name="reference" value="{{ request('reference') }}" placeholder="Reference"></div>
        </div></details>
        <div class="col-12 col-xl-4 d-flex justify-content-end align-items-end gap-2">
            <button type="submit" class="eBtn eBtn-primary">Filter Course Allocations</button>
            @if(request()->hasAny(['search','subject_id','year_id','period_id','programme_id','curriculum_id','department_id','status','reference']))<a class="btn btn-outline-secondary" href="{{ route('admin.course_offerings.index') }}">Clear filters</a>@endif
        </div>
    </form>
    @php
        $user = auth()->user();
        $canManage = $user->hasPermission('academic.course_offering.manage');
        $canViewLecturers = $user->hasPermission('academic.course_offering.lecturer.view');
    @endphp
    <div class="table-responsive table-responsive-actions">
        <table class="table eTable table-compact">
            <thead><tr><th scope="col">Reference</th><th scope="col">{{ $courseUnitLabel }}</th><th scope="col">Academic Year</th><th scope="col">{{ $periodLabel }}</th><th scope="col">Applicable Programme Curriculum</th><th scope="col">Status</th><th scope="col">Updated</th><th scope="col" class="table-actions-nowrap"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
                @forelse($offerings as $offering)
                    <tr>
                        <td class="text-nowrap">{{ $offering->reference ?: '#'.$offering->id }}</td>
                        <td>
                            @if($offering->subject->code)
                                <span class="fw-semibold">{{ $offering->subject->code }}</span>
                                <br><small class="text-muted" title="{{ $offering->subject->name }}">{{ \Illuminate\Support\Str::limit($offering->subject->name, 30) }}</small>
                            @else
                                <span title="{{ $offering->subject->name }}">{{ \Illuminate\Support\Str::limit($offering->subject->name, 40) }}</span>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $offering->academicYear->label }}</td>
                        <td class="text-nowrap">{{ $offering->academicPeriod->label }}</td>
                        <td class="text-truncate-cell" title="{{ $offering->applicability_summary }}">{{ $offering->applicability_summary ?: '—' }}</td>
                        <td>
                            <span class="badge compact-badge bg-{{ $offering->status==='open'?'success':($offering->status==='draft'?'warning text-dark':($offering->status==='cancelled'?'secondary':'primary')) }}">{{ str_replace('_',' ',ucfirst($offering->status)) }}</span>
                            @if($offering->lecturer_required)
                                <br><small class="text-warning" role="status"><i class="bi bi-exclamation-triangle"></i> Lecturer required — students can enrol</small>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $offering->updated_at?->format('Y-m-d') }}</td>
                        <td class="table-actions-nowrap">
                            <div class="d-flex align-items-center gap-1">
                                <a class="btn btn-sm btn-outline-primary text-nowrap" href="{{ route('admin.course_offerings.show',$offering->id) }}">{{ get_phrase('View') }}</a>
                                @include('shared.actions_dropdown', ['actions' => array_filter([
                                    $canViewLecturers ? ['label' => get_phrase('Lecturers'), 'url' => route('admin.course_offerings.lecturers.index', $offering->id), 'variant' => 'secondary'] : null,
                                    $canManage ? ['label' => get_phrase('Edit'), 'url' => route('admin.course_offerings.show', $offering->id).'#offering-details', 'variant' => 'primary'] : null,
                                    $canManage ? ['label' => get_phrase('Delete'), 'url' => route('admin.course_offerings.show', $offering->id).'#delete-allocation', 'variant' => 'danger'] : null,
                                ])] )
                            </div>
                        </td>
                    </tr>
                @empty
                    @if($hasAnyOfferings)
                        <tr><td colspan="8" class="text-center py-4"><p class="mb-2">No Course Allocations match these filters.</p><a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.course_offerings.index') }}">Clear filters</a></td></tr>
                    @else
                        <tr><td colspan="8" class="text-center py-4"><p class="mb-2">No Course Allocations have been created yet.</p>@if(auth()->user()->hasPermission('academic.course_offering.manage'))<a class="eBtn eBtn-primary text-nowrap" href="{{ route('admin.course_offerings.create') }}">Create Course Allocation</a>@endif</td></tr>
                    @endif
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $offerings->links() }}
</div>
@endsection
