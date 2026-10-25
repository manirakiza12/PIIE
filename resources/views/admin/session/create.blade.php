<div class="eForm-layouts">
    <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.session_manager.create') }}">
        @csrf 
        <div class="form-row">
            <div class="fpb-7">
                <label for="session_title" class="eForm-label">{{ get_phrase('Finance Session / billing period title') }}</label>
                <input type="text" maxlength="100" class="form-control eForm-control" id="session_title" name="session_title" required placeholder="2026/27 Semester 1">
                @error('session_title')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror
            </div>
            <div class="fpb-7">
                <label for="academic_year_id" class="eForm-label">{{ get_phrase('Academic Year for invoice context') }}</label>
                <select class="form-control eForm-control" id="academic_year_id" name="academic_year_id">
                    <option value="">{{ get_phrase('Leave unlinked') }}</option>
                    @foreach($academicYears as $academicYear)
                        <option value="{{ $academicYear->id }}" @selected((string) old('academic_year_id') === (string) $academicYear->id)>{{ $academicYear->label }}</option>
                    @endforeach
                </select>
                @error('academic_year_id')<div class="text-danger small mt-1" role="alert">{{ $message }}</div>@enderror
                <small class="text-muted">{{ get_phrase('Link this Finance Session to the Academic Year used for invoice reporting. For a per-semester Fee Structure, use a title that identifies the billed semester. This is separate from Academic Semester records.') }}</small>
            </div>
            <div class="fpb-7 pt-2">
                <button class="btn-form" type="submit">{{ get_phrase('Create session') }}</button>
            </div>
		</div>
	</form>
</div>
