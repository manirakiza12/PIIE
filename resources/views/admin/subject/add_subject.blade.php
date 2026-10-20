@include('admin.subject.programme_search')
<div class="eoff-form">
    <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.create.subject') }}">
        @csrf
        <div class="form-row">
            @if($isHigherEducation)
                <div class="mb-3">
                    <label for="programme_id_on_create" class="eForm-label">{{ get_phrase('Programme') }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <select name="programme_id" data-dropdown-css-class="course-unit-programme-dropdown" data-minimum-results-for-search="0" data-width="100%" id="programme_id_on_create" class="form-select eForm-select eChoice-multiple-with-remove" required>
                        <option value="">{{ get_phrase('Select a Programme') }}</option>
                        @foreach($programmes as $programme)
                            <option value="{{ $programme->id }}" @selected((string)old('programme_id', request()->query('programme_id')) === (string)$programme->id)>{{ $programme->code }} — {{ $programme->name }}</option>
                        @endforeach
                    </select>
                </div>
            @elseif($isMixed)
                <div class="mb-3" role="group" aria-label="{{ get_phrase('Catalogue association') }}">
                    <p class="form-text mb-0">{{ get_phrase('Choose either a Class for a school Subject or a Programme for a higher-education Course Unit.') }} <span class="text-danger">{{ get_phrase('Required') }}</span></p>
                </div>
                <div class="mb-3">
                    <label for="class_id_on_create" class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }}</label>
                    <select name="class_id" id="class_id_on_create" class="form-select eForm-select eChoice-multiple-with-remove">
                        <option value="">{{ get_phrase('No Class association') }}</option>
                        @foreach($classes as $class)<option value="{{ $class->id }}" @selected((string)old('class_id') === (string)$class->id)>{{ $class->name }}</option>@endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label for="programme_id_on_create" class="eForm-label">{{ get_phrase('Programme') }}</label>
                    <select name="programme_id" data-dropdown-css-class="course-unit-programme-dropdown" data-minimum-results-for-search="0" data-width="100%" id="programme_id_on_create" class="form-select eForm-select eChoice-multiple-with-remove">
                        <option value="">{{ get_phrase('No Programme association') }}</option>
                        @foreach($programmes as $programme)<option value="{{ $programme->id }}" @selected((string)old('programme_id', request()->query('programme_id')) === (string)$programme->id)>{{ $programme->code }} — {{ $programme->name }}</option>@endforeach
                    </select>
                </div>
            @else
                <div class="mb-3">
                    <label for="class_id_on_create" class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <select name="class_id" id="class_id_on_create" class="form-select eForm-select eChoice-multiple-with-remove" required>
                        <option value="">{{ get_phrase('Select a Class') }}</option>
                        @foreach($classes as $class)<option value="{{ $class->id }}" @selected((string)old('class_id') === (string)$class->id)>{{ $class->name }}</option>@endforeach
                    </select>
                </div>
            @endif

            <div class="mb-3">
                <label for="course-unit-name" class="eForm-label">{{ get_phrase($isHigherEducation ? 'Course Unit Name' : ($isMixed ? 'Subject / Course Unit Name' : 'Subject Name')) }} <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="text" class="form-control eForm-control" id="course-unit-name" value="{{ old('name') }}" name="name" maxlength="255" placeholder="{{ get_phrase($isHigherEducation ? 'Enter the Course Unit name' : 'Enter the Subject name') }}" required>
            </div>

            <div class="mb-3">
                <label for="course-unit-code" class="eForm-label">{{ get_phrase($isHigherEducation ? 'Course Unit Code' : 'Subject / Course Unit Code') }} <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="text" class="form-control eForm-control" id="course-unit-code" value="{{ old('code') }}" name="code" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9._-]*" placeholder="{{ get_phrase('For example, BBIT1101') }}" aria-describedby="course-unit-code-help" required>
                <div id="course-unit-code-help" class="form-text">{{ get_phrase('Use a unique code within this institution. Letters, numbers, dots, underscores and hyphens are allowed.') }}</div>
            </div>
            @include('admin.subject.placement_fields')
            <div class="pt-2 d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="{{ route('admin.subject_list') }}">{{ get_phrase('Cancel') }}</a>
                <button class="btn-form" type="submit">{{ get_phrase($isHigherEducation ? 'Create Course Unit' : ($isMixed ? 'Create Subject / Course Unit' : 'Create Subject')) }}</button>
            </div>
        </div>
    </form>
</div>

<script type="text/javascript">
    "use strict";
    $(document).ready(function () { $(".eChoice-multiple-with-remove").select2(); });
</script>
