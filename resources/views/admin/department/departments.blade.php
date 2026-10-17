@extends('admin.navigation')

@section('content')
<div class="mainSection-title">
    <div class="row align-items-center gr-15">
        <div class="col"><h4>Departments</h4><p class="text-muted mb-0">Departments report to the School or Faculty responsible for them.</p></div>
        <div class="col-auto"><a class="export_btn" href="{{ route('admin.department.departments.create') }}"><i class="bi bi-plus"></i> Add Department</a></div>
    </div>
</div>
<div class="eSection-wrap">
    @include('admin.department._sections')
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif
    <form method="GET" action="{{ route('admin.department.departments') }}" class="row g-2 mb-3" role="search">
        <div class="col-sm-5"><label class="visually-hidden" for="department-search">Search departments</label><input id="department-search" class="form-control" type="search" name="search" value="{{ request('search') }}" placeholder="Search by department name"></div>
        <div class="col-sm-5"><label class="visually-hidden" for="department-parent">Filter by School or Faculty</label><select id="department-parent" name="academic_unit_id" class="form-select"><option value="">All Schools and Faculties</option>@foreach($parents as $parent)<option value="{{ $parent->id }}" @selected((string)request('academic_unit_id') === (string)$parent->id)>{{ $parent->hierarchy_label }}</option>@endforeach</select></div>
        <div class="col-auto"><button class="btn btn-outline-primary" type="submit">Filter</button></div>
    </form>
    @if($departments->isEmpty())
        <div class="alert alert-light border"><strong>No departments found.</strong><br>Create a School or Faculty first, then add a Department under it. <a href="{{ route('admin.department.schools_faculties') }}">Manage Schools &amp; Faculties</a>.</div>
    @else
        <div class="table-responsive"><table class="table eTable align-middle">
            <thead><tr><th>Department</th><th>Code</th><th>Belongs To</th><th>Head of Department</th><th>Programmes</th><th>Parent assignment</th><th class="text-end">Actions</th></tr></thead>
            <tbody>@foreach($departments as $department)
                <tr>
                    <td class="fw-semibold">{{ $department->name }}</td>
                    <td>{{ $department->code ?: '—' }}</td>
                    <td>{{ $department->academicUnit ? $department->academicUnit->hierarchy_label : 'Parent not assigned' }}</td>
                    <td>{{ $department->hod_name ?: 'Not assigned' }}</td>
                    <td>{{ $department->programmes_count }}</td>
                    <td>@if($department->academic_unit_id)<span class="badge text-bg-success">Assigned</span>@else<span class="badge text-bg-warning">Needs review</span>@endif</td>
                    <td class="text-end"><div class="d-inline-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.department.show', $department->id) }}">View</a><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.department.departments.edit', $department->id) }}">Edit</a></div></td>
                </tr>
            @endforeach</tbody>
        </table></div>
        {{ $departments->links() }}
    @endif
</div>
@endsection
