@extends('admin.navigation')

@section('content')
<div class="mainSection-title"><h4>{{ $unit->name }}</h4><p class="text-muted">{{ ucfirst($unit->type) }} details</p></div>
<div class="eSection-wrap">
    @include('admin.department._sections')
    <dl class="row">
        <dt class="col-sm-3">Type</dt><dd class="col-sm-9">{{ ucfirst($unit->type) }}</dd>
        <dt class="col-sm-3">Code</dt><dd class="col-sm-9">{{ $unit->code ?: 'Not set' }}</dd>
        <dt class="col-sm-3">Dean / Director / Head</dt><dd class="col-sm-9">{{ $unit->head_name ? trim(($unit->head_title ? $unit->head_title.' - ' : '').$unit->head_name) : 'Not assigned' }}</dd>
        <dt class="col-sm-3">Belongs To</dt><dd class="col-sm-9">{{ $unit->parent ? $unit->parent->hierarchy_label : 'Top-level' }}</dd>
        <dt class="col-sm-3">Description</dt><dd class="col-sm-9">{{ $unit->description ?: '—' }}</dd>
        <dt class="col-sm-3">Status</dt><dd class="col-sm-9">{{ ucfirst($unit->status) }}</dd>
        <dt class="col-sm-3">Departments</dt><dd class="col-sm-9">{{ $unit->departments->count() }}</dd>
        <dt class="col-sm-3">Programmes</dt><dd class="col-sm-9">{{ $programmesCount }}</dd>
        @if($unit->children->isNotEmpty())<dt class="col-sm-3">Child Faculties</dt><dd class="col-sm-9">{{ $unit->children->pluck('name')->join(', ') }}</dd>@endif
    </dl>
    <div class="d-flex gap-2"><a class="btn btn-primary" href="{{ route('admin.department.units.edit', $unit->id) }}">Edit</a><a class="btn btn-outline-secondary" href="{{ route('admin.department.schools_faculties') }}">Back</a></div>
</div>
@endsection
