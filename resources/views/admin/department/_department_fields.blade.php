<form method="POST" action="{{ $department ? route('admin.department.update', $department->id) : route('admin.create.department') }}" class="row g-3 {{ !empty($ajax) ? 'ajaxForm d-block' : '' }}" novalidate>
    @csrf
    <div class="col-12 col-lg-8"><label class="form-label" for="department-name">Department Name <span aria-hidden="true">*</span></label><input id="department-name" class="form-control @error('name') is-invalid @enderror" type="text" name="name" value="{{ old('name', $department?->name) }}" maxlength="255" required autocomplete="organization">@error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-12 col-md-4"><label class="form-label" for="department-code">Department Code <span class="text-muted">(optional)</span></label><input id="department-code" class="form-control @error('code') is-invalid @enderror" type="text" name="code" value="{{ old('code', $department?->code) }}" maxlength="50" autocomplete="off" placeholder="e.g. CSC">@error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-12 col-lg-8"><label class="form-label" for="department-parent">Belongs To - Select Parent School or Faculty <span aria-hidden="true">*</span></label>
        <select id="department-parent" class="form-select @error('academic_unit_id') is-invalid @enderror" name="academic_unit_id" required>
            <option value="">Select a School or Faculty</option>
            @if($department && !$department->academic_unit_id)<option value="" disabled>Existing parent needs review: select the verified parent before saving</option>@endif
            @foreach($parents as $parent)<option value="{{ $parent->id }}" @selected((string)old('academic_unit_id', $department?->academic_unit_id) === (string)$parent->id)>{{ $parent->hierarchy_label }} (ID: {{ $parent->id }})</option>@endforeach
        </select>
        @error('academic_unit_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Faculty / School ID is saved with this selection.</div>
    </div>
    <div class="col-12 col-lg-8">
        <label class="form-label" for="department-head">Head Staff ID / HOD ID <span class="text-muted">(optional)</span></label>
        <select id="department-head" name="head_staff_id" class="form-select @error('head_staff_id') is-invalid @enderror">
            <option value="">No linked staff member</option>
            @foreach($staff as $member)
                <option value="{{ $member->id }}" @selected((string) old('head_staff_id', $department?->head_staff_id) === (string) $member->id)>{{ $member->name }} — {{ $member->code ?: 'Staff' }} (ID: {{ $member->id }})</option>
            @endforeach
        </select>
        @error('head_staff_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text">Select the staff member who heads this department. Head Staff ID and HOD ID use the same staff record.</div>
    </div>
    <div class="col-12 col-lg-8"><label class="form-label" for="department-hod">Head of Department (HOD) <span class="text-muted">(name if no linked account)</span></label><input id="department-hod" class="form-control @error('hod_name') is-invalid @enderror" type="text" name="hod_name" value="{{ old('hod_name', $department?->hod_name) }}" maxlength="255" autocomplete="name">@error('hod_name')<div class="invalid-feedback">{{ $message }}</div>@enderror<div class="form-text">When a staff member is selected above, their account name is used.</div></div>
    <div class="col-12">
        <label class="form-label" for="department-description">Description <span class="text-muted">(optional)</span></label>
        <textarea id="department-description" name="description" class="form-control @error('description') is-invalid @enderror" rows="5" maxlength="10000">{{ old('description', $department?->description) }}</textarea>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-md-4">
        <label class="form-label" for="department-active">Active</label>
        <select id="department-active" name="is_active" class="form-select @error('is_active') is-invalid @enderror" required>
            <option value="1" @selected((string) old('is_active', $department?->is_active ?? true) === '1')>Yes</option>
            <option value="0" @selected(in_array(old('is_active', $department?->is_active ?? true), [0, '0', false], true))>No</option>
        </select>
        @error('is_active')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12 col-md-4"><label class="form-label" for="department-institution">Institution ID</label><input id="department-institution" class="form-control" type="text" value="{{ auth()->user()->school_id }}" readonly><div class="form-text">Assigned to your signed-in institution.</div></div>
    <div class="col-12 col-md-4"><label class="form-label" for="department-created">Created At</label><input id="department-created" class="form-control" type="text" value="{{ $department?->created_at?->format('Y-m-d H:i:s') ?: 'Recorded when saved' }}" readonly><div class="form-text">Recorded automatically.</div></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">{{ $department ? 'Save changes' : 'Create Department' }}</button><a class="btn btn-outline-secondary" href="{{ route('admin.department.departments') }}">Cancel</a></div>
</form>
