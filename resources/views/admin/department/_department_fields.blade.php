<form method="POST" action="{{ $department ? route('admin.department.update', $department->id) : route('admin.create.department') }}" class="row g-3 {{ !empty($ajax) ? 'ajaxForm d-block' : '' }}" novalidate>
    @csrf
    <div class="col-12 col-lg-8"><label class="form-label" for="department-name">Department Name <span aria-hidden="true">*</span></label><input id="department-name" class="form-control @error('name') is-invalid @enderror" type="text" name="name" value="{{ old('name', $department?->name) }}" maxlength="255" required autocomplete="organization">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-12 col-md-4"><label class="form-label" for="department-code">Department Code <span class="text-muted">(optional)</span></label><input id="department-code" class="form-control @error('code') is-invalid @enderror" type="text" name="code" value="{{ old('code', $department?->code) }}" maxlength="50" autocomplete="off" placeholder="e.g. CSC">@error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-12 col-lg-8"><label class="form-label" for="department-parent">Belongs To - Select Parent School or Faculty <span aria-hidden="true">*</span></label>
        <select id="department-parent" class="form-select @error('academic_unit_id') is-invalid @enderror" name="academic_unit_id" required>
            <option value="">Select a School or Faculty</option>
            @if($department && !$department->academic_unit_id)<option value="" disabled>Existing parent needs review: select the verified parent before saving</option>@endif
            @foreach($parents as $parent)<option value="{{ $parent->id }}" @selected((string)old('academic_unit_id', $department?->academic_unit_id) === (string)$parent->id)>{{ $parent->hierarchy_label }}</option>@endforeach
        </select>
        @error('academic_unit_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-lg-8"><label class="form-label" for="department-hod">Head of Department (HOD) <span class="text-muted">(optional)</span></label><input id="department-hod" class="form-control @error('hod_name') is-invalid @enderror" type="text" name="hod_name" value="{{ old('hod_name', $department?->hod_name) }}" maxlength="255" autocomplete="name">@error('hod_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">{{ $department ? 'Save changes' : 'Create Department' }}</button><a class="btn btn-outline-secondary" href="{{ route('admin.department.departments') }}">Cancel</a></div>
</form>
