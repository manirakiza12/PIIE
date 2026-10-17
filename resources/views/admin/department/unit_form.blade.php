@extends('admin.navigation')

@section('content')
<div class="mainSection-title"><h4>{{ $unit ? 'Edit' : 'Add' }} {{ ucfirst($type) }}</h4><p class="text-muted">{{ $type === 'school' ? 'A School is a top-level academic unit.' : 'Choose the School or Faculty this Faculty belongs to.' }}</p></div>
<div class="eSection-wrap">
    @include('admin.department._sections')
    <form method="POST" action="{{ $unit ? route('admin.department.units.update', $unit->id) : route('admin.department.units.store') }}" class="row g-3" novalidate>
        @csrf
        @if($unit) @method('PUT') @else <input type="hidden" name="type" value="{{ $type }}"> @endif
        <div class="col-12 col-lg-8">
            <label for="unit-name" class="form-label">{{ ucfirst($type) }} Name <span aria-hidden="true">*</span></label>
            <input id="unit-name" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name', $unit?->name) }}" maxlength="255" required autocomplete="organization">
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-4">
            <label for="unit-code" class="form-label">{{ ucfirst($type) }} Code <span class="text-muted">(optional)</span></label>
            <input id="unit-code" class="form-control @error('code') is-invalid @enderror" name="code" value="{{ old('code', $unit?->code) }}" maxlength="50" autocomplete="off" placeholder="e.g. BUS">
            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @if($type === 'faculty')
            <div class="col-12 col-lg-8">
                <label for="unit-parent" class="form-label">Belongs To - Select Parent School or Faculty <span aria-hidden="true">*</span></label>
                <select id="unit-parent" class="form-select @error('parent_id') is-invalid @enderror" name="parent_id" required>
                    <option value="">Select a School or Faculty</option>
                    @foreach($parents as $parent)<option value="{{ $parent->id }}" @selected((string)old('parent_id', $unit?->parent_id) === (string)$parent->id)>{{ $parent->hierarchy_label }}</option>@endforeach
                </select>
                @error('parent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        <div class="col-12 col-md-4">
            <label for="unit-head-title" class="form-label">Dean / Director / Head Title <span class="text-muted">(optional)</span></label>
            <input id="unit-head-title" class="form-control @error('head_title') is-invalid @enderror" name="head_title" value="{{ old('head_title', $unit?->head_title) }}" maxlength="100" placeholder="e.g. Dean or Director">
            @error('head_title')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-4">
            <label for="unit-head-name" class="form-label">Dean / Director / Head Name <span class="text-muted">(optional)</span></label>
            <input id="unit-head-name" class="form-control @error('head_name') is-invalid @enderror" name="head_name" value="{{ old('head_name', $unit?->head_name) }}" maxlength="255" autocomplete="name">
            @error('head_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-lg-8"><label for="unit-description" class="form-label">Description <span class="text-muted">(optional)</span></label><textarea id="unit-description" class="form-control @error('description') is-invalid @enderror" name="description" rows="3" maxlength="2000">{{ old('description', $unit?->description) }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-12 col-lg-8"><label for="unit-status" class="form-label">Status</label><select id="unit-status" name="status" class="form-select @error('status') is-invalid @enderror"><option value="active" @selected(old('status', $unit?->status ?? 'active') === 'active')>Active</option><option value="inactive" @selected(old('status', $unit?->status) === 'inactive')>Inactive</option></select>@error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">{{ $unit ? 'Save changes' : 'Create '.ucfirst($type) }}</button><a class="btn btn-outline-secondary" href="{{ route('admin.department.schools_faculties') }}">Cancel</a></div>
    </form>
</div>
@endsection
