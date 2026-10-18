@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><h4>{{ get_phrase('Programme Years') }}</h4><p class="text-muted">Define Year 1, Year 2 and other years within a reusable Programme Curriculum. These are study positions, not dated Academic Years.</p></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="eSection-wrap mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-9"><label class="form-label" for="filter-curriculum">Programme Curriculum</label><select id="filter-curriculum" name="curriculum_id" class="form-select"><option value="">All curricula</option>@foreach($curricula as $curriculum)<option value="{{ $curriculum->id }}" @selected($selected?->id === $curriculum->id)>{{ $curriculum->programme?->name }} — {{ $curriculum->version }} ({{ ucfirst($curriculum->status) }})</option>@endforeach</select></div>
        <div class="col-md-3"><button class="btn btn-primary">Filter</button></div>
    </form>
</section>
@if(auth()->user()->hasPermission('academic.curriculum.manage'))
<section class="eSection-wrap mb-3"><h5>Create Programme Year</h5>
    @if($curricula->where('status', 'draft')->isEmpty())<p class="text-muted mb-0">Create a draft in the Programme Curriculum module before adding its years.</p>@else
    <form method="POST" action="{{ route('admin.programme_years.store') }}" class="row g-3">@csrf
        <div class="col-lg-6"><label class="form-label" for="new-curriculum">Draft Programme Curriculum</label><select id="new-curriculum" name="curriculum_id" class="form-select" required><option value="">Choose curriculum</option>@foreach($curricula->where('status', 'draft') as $curriculum)<option value="{{ $curriculum->id }}" @selected(old('curriculum_id', $selected?->id) == $curriculum->id)>{{ $curriculum->programme?->name }} — {{ $curriculum->version }}</option>@endforeach</select></div>
        <div class="col-lg-3"><label class="form-label" for="new-label">Year name</label><input id="new-label" name="label" class="form-control" value="{{ old('label') }}" maxlength="100" required placeholder="Year 1"></div>
        <div class="col-lg-3"><label class="form-label" for="new-sequence">Order</label><input id="new-sequence" name="sequence" type="number" class="form-control" min="1" max="65535" value="{{ old('sequence', 1) }}" required></div>
        <div class="col-12"><button class="btn btn-primary">Create Year</button></div>
    </form>@endif
</section>@endif
<section class="eSection-wrap"><h5>Programme Years</h5>
    @forelse($years as $year)
    <article class="border rounded p-3 mb-3">
        <div class="d-flex justify-content-between flex-wrap gap-2"><div><strong>{{ $year->sequence }}. {{ $year->label }}</strong><div class="text-muted">{{ $year->curriculum->programme?->name }} — {{ $year->curriculum->version }} · {{ ucfirst($year->curriculum->status) }}</div></div><span>{{ $year->memberships_count }} course units</span></div>
        <a class="btn btn-sm btn-outline-secondary mt-2" href="{{ route('admin.programme_years.show', $year->id) }}">View Year</a>
        @if($year->curriculum->status === 'draft' && auth()->user()->hasPermission('academic.curriculum.manage'))
        <details class="mt-3"><summary>Edit Year</summary><form method="POST" action="{{ route('admin.programme_years.update', $year->id) }}" class="row g-2 mt-1">@csrf @method('PUT')<div class="col-md-7"><label class="form-label" for="label-{{ $year->id }}">Year name</label><input id="label-{{ $year->id }}" name="label" class="form-control" value="{{ $year->label }}" maxlength="100" required></div><div class="col-md-3"><label class="form-label" for="order-{{ $year->id }}">Order</label><input id="order-{{ $year->id }}" name="sequence" type="number" class="form-control" min="1" max="65535" value="{{ $year->sequence }}" required></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary">Save</button></div></form></details>
        @if($year->memberships_count === 0)<form method="POST" action="{{ route('admin.programme_years.destroy', $year->id) }}" class="mt-3" onsubmit="return confirm('Delete this empty Programme Year?')">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">Delete Year</button></form>@else<p class="small text-muted mt-2 mb-0">Move or remove its course-unit placements before deleting this year.</p>@endif
        @else<p class="small text-muted mt-2 mb-0">Approved and retired curriculum years are read-only. Use a successor curriculum for structural changes.</p>@endif
    </article>
    @empty<p class="text-muted">No Programme Years found.</p>@endforelse
    {{ $years->links() }}
</section>
@endsection
