@extends('admin.navigation')

@section('content')
@php($legacyRootFaculty = $unit && $unit->legacy_department_id && !$unit->parent_id)
<div class="mainSection-title"><h4>{{ $unit ? 'Edit' : 'Add School / Faculty' }}</h4><p class="text-muted">Choose whether this is a School or Faculty. Faculties belong to a School or another Faculty.</p></div>
<div class="eSection-wrap">
    <form method="POST" action="{{ $unit ? route('admin.department.units.update', $unit->id) : route('admin.department.units.store') }}" class="row g-3" novalidate>
        @csrf
        @if($unit)
            @method('PUT')
            <input type="hidden" name="type" value="{{ $type }}">
            <div class="col-12 col-md-4"><label class="form-label">Type</label><input class="form-control" value="{{ ucfirst($type) }}" disabled></div>
        @else
            <div class="col-12 col-md-4">
                <label for="unit-type" class="form-label">Type <span aria-hidden="true">*</span></label>
                <select id="unit-type" class="form-select @error('type') is-invalid @enderror" name="type" required>
                    <option value="">Choose School or Faculty</option>
                    <option value="school" @selected(old('type', $type) === 'school')>School</option>
                    <option value="faculty" @selected(old('type', $type) === 'faculty')>Faculty</option>
                </select>
                @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        <div class="col-12 col-lg-8">
            <label for="unit-name" class="form-label"><span id="unit-name-type">{{ $type ? ucfirst($type) : 'School / Faculty' }}</span> Name <span aria-hidden="true">*</span></label>
            <input id="unit-name" class="form-control @error('name') is-invalid @enderror" name="name" value="{{ old('name', $unit?->name) }}" maxlength="255" required autocomplete="organization">
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-4">
            <label for="unit-code" class="form-label"><span id="unit-code-type">{{ $type ? ucfirst($type) : 'School / Faculty' }}</span> Code <span class="text-muted">(optional)</span></label>
            <input id="unit-code" class="form-control @error('code') is-invalid @enderror" name="code" value="{{ old('code', $unit?->code) }}" maxlength="50" autocomplete="off" placeholder="e.g. BUS">
            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div id="unit-parent-wrap" class="col-12 col-lg-8" @if($type !== 'faculty') hidden @endif>
            <label for="unit-parent" class="form-label">Belongs To - Select Parent School or Faculty @if(!$legacyRootFaculty)<span aria-hidden="true">*</span>@else<span class="text-muted">(optional for this existing top-level Faculty)</span>@endif</label>
            <select id="unit-parent" class="form-select @error('parent_id') is-invalid @enderror" name="parent_id" @if($type !== 'faculty') disabled @endif>
                <option value="">Select a School or Faculty</option>
                @foreach($parents as $parent)<option value="{{ $parent->id }}" @selected((string)old('parent_id', $unit?->parent_id) === (string)$parent->id)>{{ $parent->hierarchy_label }}</option>@endforeach
            </select>
            @error('parent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-4">
            <label for="unit-head-name" class="form-label">Dean / Director / Head <span class="text-muted">(optional)</span></label>
            <input id="unit-head-name" class="form-control @error('head_name') is-invalid @enderror" name="head_name" value="{{ old('head_name', $unit?->head_name) }}" maxlength="255" autocomplete="name" placeholder="Enter the person's name">
            @error('head_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-lg-8"><label for="unit-description" class="form-label">Description <span class="text-muted">(optional)</span></label><textarea id="unit-description" class="form-control @error('description') is-invalid @enderror" name="description" rows="3" maxlength="2000">{{ old('description', $unit?->description) }}</textarea>@error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-12 col-lg-8"><label for="unit-status" class="form-label">Status</label><select id="unit-status" name="status" class="form-select @error('status') is-invalid @enderror"><option value="active" @selected(old('status', $unit?->status ?? 'active') === 'active')>Active</option><option value="inactive" @selected(old('status', $unit?->status) === 'inactive')>Inactive</option></select>@error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">{{ $unit ? 'Save changes' : 'Create School / Faculty' }}</button><a class="btn btn-outline-secondary" href="{{ route('admin.department.schools_faculties') }}">Cancel</a></div>
    </form>
</div>
@unless($unit)
<script>
(() => {
    const type = document.getElementById('unit-type');
    const parentWrap = document.getElementById('unit-parent-wrap');
    const parent = document.getElementById('unit-parent');
    const nameType = document.getElementById('unit-name-type');
    const codeType = document.getElementById('unit-code-type');
    const sync = () => {
        const faculty = type.value === 'faculty';
        parentWrap.hidden = !faculty;
        parent.disabled = !faculty;
        parent.required = faculty;
        nameType.textContent = type.value ? (faculty ? 'Faculty' : 'School') : 'School / Faculty';
        codeType.textContent = nameType.textContent;
    };
    type.addEventListener('change', sync);
    sync();
})();
</script>
@endunless
@endsection
