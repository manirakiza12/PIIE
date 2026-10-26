@include('admin.subject.programme_search')
<div class="eoff-form">
    <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.subject.update', ['id' => $subject->id, 'curriculum_id' => $curriculumId ?? request()->query('curriculum_id')]) }}">
        @csrf
        @if($curriculumId ?? request()->query('curriculum_id'))<input type="hidden" name="curriculum_id" value="{{ $curriculumId ?? request()->query('curriculum_id') }}">@endif
        @if($curriculumStageId ?? request()->query('curriculum_stage_id'))<input type="hidden" name="curriculum_stage_id" value="{{ $curriculumStageId ?? request()->query('curriculum_stage_id') }}">@endif
        <div class="form-row">
            @if($isHigherEducation)
                <div class="mb-3">
                    <label for="programme_id_on_edit" class="eForm-label">{{ get_phrase('Programme') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <select name="programme_id" data-dropdown-css-class="course-unit-programme-dropdown" data-minimum-results-for-search="0" data-width="100%" id="programme_id_on_edit" class="form-select eForm-select eChoice-multiple-with-remove" required>
                        <option value="">{{ get_phrase('Select a Programme') }}</option>
                        @foreach($programmes as $programme)<option value="{{ $programme->id }}" @selected((string)$programme->id === (string)old('programme_id', $subject->programme_id))>{{ $programme->code }} — {{ $programme->name }}</option>@endforeach
                    </select>
                </div>
            @elseif($isMixed)
                <p class="form-text">{{ get_phrase('Choose either a Class for a school Subject or a Programme for a higher-education Course Unit.') }}</p>
                <div class="mb-3">
                    <label for="class_id_on_edit" class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }}</label>
                    <select name="class_id" id="class_id_on_edit" class="form-select eForm-select eChoice-multiple-with-remove">
                        <option value="">{{ get_phrase('No Class association') }}</option>
                        @foreach($classes as $class)<option value="{{ $class->id }}" @selected((string)$class->id === (string)old('class_id', $subject->class_id))>{{ $class->name }}</option>@endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label for="programme_id_on_edit" class="eForm-label">{{ get_phrase('Programme') }}</label>
                    <select name="programme_id" data-dropdown-css-class="course-unit-programme-dropdown" data-minimum-results-for-search="0" data-width="100%" id="programme_id_on_edit" class="form-select eForm-select eChoice-multiple-with-remove">
                        <option value="">{{ get_phrase('No Programme association') }}</option>
                        @foreach($programmes as $programme)<option value="{{ $programme->id }}" @selected((string)$programme->id === (string)old('programme_id', $subject->programme_id))>{{ $programme->code }} — {{ $programme->name }}</option>@endforeach
                    </select>
                </div>
            @else
                <div class="mb-3">
                    <label for="class_id_on_edit" class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <select name="class_id" id="class_id_on_edit" class="form-select eForm-select eChoice-multiple-with-remove" required>
                        <option value="">{{ get_phrase('Select a Class') }}</option>
                        @foreach($classes as $class)<option value="{{ $class->id }}" @selected((string)$class->id === (string)old('class_id', $subject->class_id))>{{ $class->name }}</option>@endforeach
                    </select>
                </div>
            @endif

            <div class="mb-3">
                <label for="course-unit-name-edit" class="eForm-label">{{ get_phrase($isHigherEducation ? 'Course Unit Name' : ($isMixed ? 'Subject / Course Unit Name' : 'Subject Name')) }} <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="text" class="form-control eForm-control" value="{{ old('name', $subject->name) }}" id="course-unit-name-edit" name="name" maxlength="255" required>
            </div>
            <div class="mb-3">
                <label for="course-unit-code-edit" class="eForm-label">{{ get_phrase($isHigherEducation ? 'Course Unit Code' : 'Subject / Course Unit Code') }} <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="text" class="form-control eForm-control" value="{{ old('code', $subject->code) }}" id="course-unit-code-edit" name="code" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9._-]*" aria-describedby="course-unit-code-edit-help" required>
                <div id="course-unit-code-edit-help" class="form-text">{{ get_phrase('Use a unique code within this institution.') }}</div>
            </div>
            @if($isHigherEducation || $isMixed)
                <div class="alert alert-warning" role="note">
                    <strong>{{ get_phrase('Shared catalogue details') }}</strong>
                    <span class="ms-1">{{ get_phrase('Name, code and other catalogue properties are shared by every curriculum that uses this Course Unit. Changes here may affect other curricula.') }}</span>
                </div>
                <div class="border rounded p-3 mb-3">
                    <h6 class="mb-1">{{ get_phrase('Edit Curriculum Placement') }}</h6>
                    <p class="text-muted mb-2">{{ get_phrase('Programme Year, semester or term, credits, classification, order and prerequisites belong to a specific curriculum placement.') }}</p>
                    @if($curriculumId ?? request()->query('curriculum_id'))
                        <a class="btn btn-outline-primary btn-sm" href="{{ route('admin.curricula.show', $curriculumId ?? request()->query('curriculum_id')) }}{{ ($curriculumStageId ?? request()->query('curriculum_stage_id')) ? '#curriculum-year-'.($curriculumStageId ?? request()->query('curriculum_stage_id')) : '#course-units' }}">{{ get_phrase('Return to Curriculum Placement') }}</a>
                    @endif
                </div>
            @endif
            <div class="pt-2 d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="{{ ($curriculumId ?? request()->query('curriculum_id')) ? route('admin.curricula.show', $curriculumId ?? request()->query('curriculum_id')) . (($curriculumStageId ?? request()->query('curriculum_stage_id')) ? '#curriculum-year-'.($curriculumStageId ?? request()->query('curriculum_stage_id')) : '#course-units') : route('admin.subject_list', request()->only(['programme_id', 'search'])) }}">{{ get_phrase('Cancel') }}</a>
                <button class="btn-form eBtn-primary" type="submit">{{ get_phrase($isHigherEducation ? 'Save Course Unit' : ($isMixed ? 'Update Subject / Course Unit' : 'Update Subject')) }}</button>
            </div>
        </div>
    </form>
</div>

<script type="text/javascript">
    "use strict";
    $(document).ready(function () { $(".eChoice-multiple-with-remove").select2(); });
</script>
