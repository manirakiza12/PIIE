@extends('admin.navigation')

@section('content')
<div class="mainSection-title"><h4>{{ $department->name }}</h4><p class="text-muted">Department details</p></div>
<div class="eSection-wrap">
    <dl class="row"><dt class="col-sm-3">Code</dt><dd class="col-sm-9">{{ $department->code ?: 'Not set' }}</dd><dt class="col-sm-3">Belongs To</dt><dd class="col-sm-9">{{ $department->academicUnit ? $department->academicUnit->hierarchy_label : 'Parent not assigned - needs review' }}</dd><dt class="col-sm-3">Head of Department (HOD)</dt><dd class="col-sm-9">{{ $department->hod_name ?: 'Not assigned' }}</dd><dt class="col-sm-3">Programmes</dt><dd class="col-sm-9">{{ $department->programmes_count }}</dd><dt class="col-sm-3">Created</dt><dd class="col-sm-9">{{ $department->created_at?->format('Y-m-d') ?: '—' }}</dd></dl>
    <div class="d-flex gap-2"><a class="btn btn-primary" href="{{ route('admin.department.departments.edit', $department->id) }}">Edit</a><a class="btn btn-outline-secondary" href="{{ route('admin.department.departments') }}">Back</a></div>
</div>
@endsection
