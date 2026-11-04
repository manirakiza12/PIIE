@extends('admin.navigation')

@section('content')
<div class="mainSection-title"><div class="d-flex justify-content-between align-items-center flex-wrap gap-3"><div><h4>Programme Cohorts</h4><p class="text-muted mb-0">Manage programme cohorts, memberships, and academic placement.</p><ul class="d-flex align-items-center eBreadcrumb-2"><li>Academics</li><li>Programme Cohorts</li></ul></div>
    <div class="d-flex gap-2 flex-wrap"><a class="btn btn-primary text-nowrap" href="{{ route('admin.programme_cohorts.create') }}">Create Programme Cohort</a><a class="btn btn-outline-secondary text-nowrap" href="{{ route('admin.academic.direct_placement') }}">Place Student in Programme</a></div>
</div></div>
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif
<div class="eSection-wrap">
    <form method="GET" action="{{ route('admin.programme_cohorts.index') }}" class="row g-3 mb-3">
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="cohort-search">Search cohorts</label><input id="cohort-search" class="form-control" name="search" value="{{ request('search') }}" placeholder="Name or code"></div>
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="filter-programme">Programme</label><select id="filter-programme" class="form-select" name="programme_id"><option value="">All Programmes</option>@foreach($programmes as $item)<option value="{{ $item->id }}" @selected((string)request('programme_id')===(string)$item->id)>{{ $item->code }} — {{ $item->name }}</option>@endforeach</select></div>
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="filter-intake">Admissions Intake</label><select id="filter-intake" class="form-select" name="intake_session_id"><option value="">All Admissions Intakes</option>@foreach($intakes as $item)<option value="{{ $item->id }}" @selected((string)request('intake_session_id')===(string)$item->id)>{{ $item->name }}</option>@endforeach</select></div>
        <div class="col-12 col-md-6 col-xl-3"><label class="form-label" for="filter-entry-year">Starting Academic Year</label><select id="filter-entry-year" class="form-select" name="entry_academic_year_id"><option value="">All Starting Academic Years</option>@foreach($years as $item)<option value="{{ $item->id }}" @selected((string)request('entry_academic_year_id')===(string)$item->id)>{{ $item->label }}</option>@endforeach</select></div>
        <div class="col-12 col-sm-6 col-md-4 col-xl-3"><label class="form-label" for="filter-status">Status</label><select id="filter-status" class="form-select" name="status"><option value="">All statuses</option>@foreach(\App\Models\ProgrammeCohort::STATUSES as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
        <div class="col-12 col-sm-6 col-md-8 col-xl-9 d-flex align-items-end gap-2"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="{{ route('admin.programme_cohorts.index') }}">Clear filters</a></div>
    </form>
    <style>
        .cohort-table-wrap { overflow-x: auto; }
        .cohort-table { font-size: .875rem; }
        .cohort-table th, .cohort-table td { padding: .4rem .5rem; vertical-align: middle; white-space: nowrap; }
        .cohort-table .cohort-name { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cohort-table .dropdown-menu { z-index: 1055; }
    </style>
    <div class="cohort-table-wrap">
        <table class="table table-sm table-hover cohort-table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Cohort Code</th>
                    <th>Cohort Name</th>
                    <th>Programme</th>
                    <th>Admissions Intake</th>
                    <th>Starting Academic Year</th>
                    <th class="text-center">Student Count</th>
                    <th>Cohort Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($cohorts as $cohort)
                <tr>
                    <td class="fw-semibold">{{ $cohort->code }}</td>
                    <td class="cohort-name" title="{{ $cohort->name }}"><a href="{{ route('admin.programme_cohorts.show', $cohort->id) }}">{{ $cohort->name }}</a></td>
                    <td title="{{ $cohort->programme?->code }} — {{ $cohort->programme?->name }}">{{ $cohort->programme?->code }}</td>
                    <td>{{ $cohort->intakeSession?->name }}</td>
                    <td>{{ $cohort->entryAcademicYear?->label }}</td>
                    <td class="text-center">{{ $cohort->members_count }}</td>
                    <td><span class="badge bg-{{ $cohort->status==='active'?'success':($cohort->status==='draft'?'secondary':($cohort->status==='completed'?'primary':'dark')) }}">{{ ucfirst($cohort->status) }}</span></td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false">Actions</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="{{ route('admin.programme_cohorts.show', $cohort->id) }}">View Details</a></li>
                                @if($cohort->status === 'draft' && auth()->user()->hasPermission('academic.programme_cohort.manage'))
                                    <li><a class="dropdown-item" href="{{ route('admin.programme_cohorts.edit', $cohort->id) }}">Edit Cohort</a></li>
                                @endif
                                @if($cohort->status === 'draft' && auth()->user()->hasPermission('academic.programme_cohort.lifecycle'))
                                    <li><form method="POST" action="{{ route('admin.programme_cohorts.lifecycle', $cohort->id) }}" onsubmit="return confirm('Activate this Programme Cohort? It will become operational for student memberships and academic placement.');">@csrf<input type="hidden" name="status" value="active"><button type="submit" class="dropdown-item">Activate Cohort</button></form></li>
                                    <li><form method="POST" action="{{ route('admin.programme_cohorts.lifecycle', $cohort->id) }}" onsubmit="return confirm('Cancel this unused draft? It will be kept as a historical record and cannot be reactivated.');">@csrf<input type="hidden" name="status" value="cancelled"><button type="submit" class="dropdown-item text-danger">Cancel Cohort</button></form></li>
                                @endif
                                @if($cohort->status === 'active')
                                    @if(auth()->user()->hasPermission('academic.programme_cohort.membership'))
                                        <li><a class="dropdown-item" href="{{ route('admin.programme_cohorts.show', $cohort->id) }}#cohort-members">Manage Students</a></li>
                                        <li><a class="dropdown-item" href="{{ route('admin.programme_cohorts.show', $cohort->id) }}#cohort-members">Add Student</a></li>
                                    @endif
                                    @if(auth()->user()->hasPermission('academic.programme_cohort.lifecycle'))
                                        @if($cohort->members_count === 0)
                                            <li><form method="POST" action="{{ route('admin.programme_cohorts.lifecycle', $cohort->id) }}" onsubmit="return confirm('Mark this Programme Cohort completed? This closes the cohort to new membership.');">@csrf<input type="hidden" name="status" value="completed"><button type="submit" class="dropdown-item">Complete Cohort</button></form></li>
                                        @endif
                                        @if($cohort->members_count === 0)
                                            <li><form method="POST" action="{{ route('admin.programme_cohorts.lifecycle', $cohort->id) }}" onsubmit="return confirm('Cancel this active Programme Cohort? This action is final.');">@csrf<input type="hidden" name="status" value="cancelled"><button type="submit" class="dropdown-item text-danger">Cancel Cohort</button></form></li>
                                        @endif
                                    @endif
                                @endif
                                <li><a class="dropdown-item" href="{{ route('admin.programme_cohorts.show', $cohort->id) }}#cohort-activity">View Membership History</a></li>
                            </ul>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center py-4">
                    <h5 class="mb-1">No Programme Cohorts match these filters</h5>
                    <p class="text-muted mb-3">Create a Programme Cohort or adjust the filters.</p>
                    <div class="d-flex justify-content-center flex-wrap gap-2"><a class="btn btn-outline-secondary" href="{{ route('admin.programme_cohorts.index') }}">Clear filters</a><a class="btn btn-primary" href="{{ route('admin.programme_cohorts.create') }}">Create Programme Cohort</a></div>
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $cohorts->links() }}</div>
</div>
@endsection
