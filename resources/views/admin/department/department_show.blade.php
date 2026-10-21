@extends('admin.navigation')

@section('content')
<div class="mainSection-title"><h4>{{ $department->name }}</h4><p class="text-muted">Department details</p></div>
<div class="eSection-wrap">
    <dl class="row">
        <dt class="col-sm-3">Name</dt><dd class="col-sm-9">{{ $department->name }}</dd>
        <dt class="col-sm-3">Code</dt><dd class="col-sm-9">{{ $department->code ?: 'Not set' }}</dd>
        <dt class="col-sm-3">Faculty / School ID</dt><dd class="col-sm-9">{{ $department->academic_unit_id ?: 'Not assigned' }}</dd>
        <dt class="col-sm-3">Belongs To</dt><dd class="col-sm-9">{{ $department->academicUnit ? $department->academicUnit->hierarchy_label : 'Parent not assigned - needs review' }}</dd>
        <dt class="col-sm-3">Head Staff ID / HOD ID</dt><dd class="col-sm-9">{{ $department->headStaff ? $department->headStaff->id.' ('.($department->headStaff->code ?: 'Staff').')' : 'Not assigned' }}</dd>
        <dt class="col-sm-3">Head of Department (HOD)</dt><dd class="col-sm-9">{{ $department->headStaff?->name ?: ($department->hod_name ?: 'Not assigned') }}</dd>
        <dt class="col-sm-3">Institution ID</dt><dd class="col-sm-9">{{ $department->school_id }}</dd>
        <dt class="col-sm-3">Description</dt><dd class="col-sm-9" style="white-space: pre-wrap">{{ $department->description ?: 'Not set' }}</dd>
        <dt class="col-sm-3">Active</dt><dd class="col-sm-9">{{ ($department->is_active ?? true) ? 'Yes' : 'No' }}</dd>
        <dt class="col-sm-3">Programmes</dt><dd class="col-sm-9">{{ $department->programmes_count }}</dd>
        <dt class="col-sm-3">Created At</dt><dd class="col-sm-9">{{ $department->created_at?->format('Y-m-d H:i:s') ?: '—' }}</dd>
    </dl>
    <div class="d-flex gap-2"><a class="btn btn-primary" href="{{ route('admin.department.departments.edit', $department->id) }}">Edit</a><a class="btn btn-outline-secondary" href="{{ route('admin.department.departments') }}">Back</a></div>
</div>
@endsection
