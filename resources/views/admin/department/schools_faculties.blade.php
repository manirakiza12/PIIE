@extends('admin.navigation')

@section('content')
<div class="mainSection-title">
    <div class="row align-items-center gr-15">
        <div class="col"><h4>Schools &amp; Faculties</h4><p class="text-muted mb-0">Organise your academic units. Faculties belong to a School or another Faculty; Schools are top-level.</p></div>
        <div class="col-auto d-flex flex-wrap gap-2">
            <a class="export_btn" href="{{ route('admin.department.units.create', ['type' => 'school']) }}"><i class="bi bi-plus"></i> Add School</a>
            <a class="export_btn" href="{{ route('admin.department.units.create', ['type' => 'faculty']) }}"><i class="bi bi-plus"></i> Add Faculty</a>
        </div>
    </div>
</div>
<div class="eSection-wrap">
    @include('admin.department._sections')
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    <form method="GET" action="{{ route('admin.department.schools_faculties') }}" class="row g-2 mb-3" role="search">
        <div class="col-sm-6"><label class="visually-hidden" for="unit-search">Search Schools and Faculties</label><input id="unit-search" class="form-control" type="search" name="search" value="{{ request('search') }}" placeholder="Search Schools or Faculties"></div>
        <div class="col-sm-3"><label class="visually-hidden" for="unit-type">Unit type</label><select id="unit-type" name="type" class="form-select"><option value="">All types</option><option value="school" @selected(request('type') === 'school')>Schools</option><option value="faculty" @selected(request('type') === 'faculty')>Faculties</option></select></div>
        <div class="col-auto"><button class="btn btn-outline-primary" type="submit">Search</button></div>
    </form>
    @if($units->isEmpty())
        <div class="alert alert-light border"><strong>No Schools or Faculties yet.</strong><br>Add a School first, then add Faculties under a School or an existing Faculty.</div>
    @else
        <div class="table-responsive"><table class="table eTable align-middle">
            <thead><tr><th>Name</th><th>Code</th><th>Type</th><th>Belongs To</th><th>Dean / Director / Head</th><th>Departments</th><th>Programmes</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>@foreach($units as $unit)
                <tr>
                    <td class="fw-semibold">{{ $unit->name }}</td>
                    <td>{{ $unit->code ?: '—' }}</td>
                    <td><span class="badge text-bg-light">{{ ucfirst($unit->type) }}</span></td>
                    <td>{{ $unit->parent ? $unit->parent->hierarchy_label : 'Top-level' }}</td>
                    <td>{{ $unit->head_name ? trim(($unit->head_title ? $unit->head_title.' - ' : '').$unit->head_name) : 'Not assigned' }}</td>
                    <td>{{ $unit->departments_count }}</td><td>{{ $unit->programmes_count }}</td>
                    <td><span class="badge {{ $unit->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">{{ ucfirst($unit->status) }}</span></td>
                    <td class="text-end"><div class="d-inline-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.department.units.show', $unit->id) }}">View</a><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.department.units.edit', $unit->id) }}">Edit</a></div></td>
                </tr>
            @endforeach</tbody>
        </table></div>
        {{ $units->links() }}
    @endif
</div>
@endsection
