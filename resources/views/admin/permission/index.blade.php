@extends('admin.navigation')
   
@section('content')
@php
    $tertiaryGroups = academic_education_level(auth()->user()->school_id) === 'tertiary';
    $wholeGroupLabel = 'Whole class (no section)';
@endphp
<div class="mainSection-title">
    <div class="row">
      <div class="col-12">
        <div
          class="d-flex justify-content-between align-items-center flex-wrap gr-15"
        >
          <div class="d-flex flex-column">
            <h4>{{ academic_term('teacher_assignment', auth()->user()->school_id) }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
              <li><a href="#">{{ get_phrase('Home') }}</a></li>
              <li><a href="#">{{ get_phrase('Academics') }}</a></li>
              <li><a href="#">{{ academic_term('teacher_assignment', auth()->user()->school_id) }}</a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
</div>
@if($tertiaryGroups)
<div class="alert alert-info" role="note">
    <strong>Assign lecturers to a course unit:</strong> Open Course Registrations, select its Academic Year and Semester, then View ? Teaching Team.
    <p class="mb-0 mt-1">The controls below grant marks and attendance access for a Programme. These access settings remain available for the workflows that use them.</p>
    @if(auth()->user()->hasPermission('academic.course_offering.view'))<a class="alert-link" href="{{ route('admin.course_offerings.index') }}">Open Course Registrations</a>@endif
</div>
@endif
@unless($tertiaryGroups)
<details open class="mb-3"><summary class="fw-bold mb-3">{{ academic_term('class', auth()->user()->school_id) }} {{ get_phrase('Access') }}</summary>
<div class="row">
    <div class="col-10 offset-md-1">
        <div class="eSection-wrap-2">
        	<form method="GET" class="d-block ajaxForm">
                <div class="row mt-3">

                    <div class="col-md-2"></div>

                    <div class="col-md-3">
                        <select name="class_id" id="class_id" class="form-select eForm-select eChoice-multiple-with-remove" required onchange="classWiseSection(this.value)">
                            <option value="">{{ get_phrase('Select').' '.academic_term('class', auth()->user()->school_id) }}</option>
                            @foreach($classes as $class)
                                <option value="{{ $class->id }}" {{ (string)$class->id === (string)$default_class_id ? 'selected' : '' }}>{{ $class->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <select name="section_id" id="section_id" class="form-select eForm-select" >
                            @if(count($sections) > 0)
                                <option value="0" {{ (int)$default_section_id === 0 ? 'selected' : '' }}>{{ get_phrase($wholeGroupLabel) }}</option>
                                @foreach($sections as $section)
                                    <option value="{{ $section->id }}" {{ (string)$section->id === (string)$default_section_id ? 'selected' : '' }}>{{ $section->name }}</option>
                                @endforeach
                            @else
                                <option value="0" selected>{{ get_phrase($wholeGroupLabel) }}</option>
                            @endif
                        </select>
                    </div>

                    <div class="col-md-3">
                        <button class="eBtn eBtn btn-secondary" type="button" id = "filter_routine" onclick="filter()">{{ get_phrase('Filter') }}</button>
                    </div>

                    <div class="card-body permission_content">
                        @if(!empty($default_class_id))
                            @include('admin.permission.list', ['teachers' => $teachers, 'class_id' => $default_class_id, 'section_id' => $default_section_id])
                        @else
                            <div class="empty_box center">
                                <img class="mb-3" width="150px" src="{{ asset('assets/images/empty_box.png') }}" />
                            </div>
                        @endif
                    </div>

                </div>
            </form>
        </div>
    </div>
</div>
<div class="row mt-3"><div class="col-10 offset-md-1"><div class="alert alert-info mb-0">
    @if($tertiaryGroups)
    Manage lecturer access through the Course Allocation teaching team.
    @else
    {{ get_phrase('Teacher/Lecturer subject access is derived from the assigned class and its Subjects/Courses. Select a class, choose Whole class (no section) when sections are not used, then enable Marks for the teacher.') }}
    @endif
</div></div></div>
</details>
@endunless

@if($programmes->count() > 0)
<details class="mb-3" @if(request()->filled('programme_id')) open @endif><summary class="fw-bold mb-3">{{ get_phrase('Programme Access') }}</summary>
<div class="row mt-4">
    <div class="col-10 offset-md-1">
        <div class="eSection-wrap-2">
            <p class="text-muted">{{ get_phrase('Assign marks and attendance access for a whole programme.') }}</p>
            <form method="GET" class="d-block ajaxForm">
                <div class="row mt-3">

                    <div class="col-md-2"></div>

                    <div class="col-md-6">
                        <select name="programme_id" id="programme_id" class="form-select eForm-select eChoice-multiple-with-remove">
                            @foreach($programmes as $programme)
                                <option value="{{ $programme->id }}" {{ (string)$programme->id === (string)$default_programme_id ? 'selected' : '' }}>{{ $programme->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <button class="eBtn eBtn btn-secondary" type="button" onclick="filterProgramme()">{{ get_phrase('Filter') }}</button>
                    </div>

                    <div class="card-body programme_permission_content">
                        @if(!empty($default_programme_id))
                            @include('admin.permission.programme_list', ['teachers' => $teachers, 'programme_id' => $default_programme_id])
                        @else
                            <div class="empty_box center">
                                <img class="mb-3" width="150px" src="{{ asset('assets/images/empty_box.png') }}" />
                            </div>
                        @endif
                    </div>

                </div>
            </form>
        </div>
    </div>
</div>
</details>
<script type="text/javascript">
    "use strict";

    function filterProgramme(){
        var programme_id = $('#programme_id').val();

        if(programme_id == ""){
            toastr.error('{{ get_phrase('Please select a programme') }}');
            return;
        }

        let url = "{{ route('admin.teacher.programme_permission_list', ['programme_id' => ':value']) }}";
        url = url.replace(':value', programme_id);

        $.ajax({
            url: url,
            success: function(response){
                $('.programme_permission_content').html(response);
            }
        });
    }
</script>
@endif

<script type="text/javascript">

  "use strict";


    function classWiseSection(classId, callback) {
        if (classId == "") {
            $('#section_id').html('<option value="0">{{ get_phrase($wholeGroupLabel) }}</option>');
            if (typeof callback === 'function') callback('0');
            return;
        }

        let url = "{{ route('class_wise_sections', ['id' => ":classId"]) }}";
        url = url.replace(":classId", classId);

        $.ajax({
            url: url,
            success: function(response){
                $('#section_id').html(response);

                // Auto-select first available section so filter can run reliably.
                var firstSection = $('<select>' + response + '</select>').find('option[value!=""]').first().val() || '0';
                $('#section_id').val(firstSection || '0');

                if (typeof callback === 'function') callback(firstSection);
            },
            error: function() {
                toastr.error('{{ get_phrase('Unable to load sections') }}');
                if (typeof callback === 'function') callback('');
            }
        });
    }

    function filter(){
        var class_id = $('#class_id').val();
        var section_id = $('#section_id').val();

        if(class_id == ""){
            toastr.error('{{ get_phrase('Select').' '.academic_term('class', auth()->user()->school_id).' / '.academic_term('section', auth()->user()->school_id) }}');
            return;
        }

        const loadPermissionList = function(selectedSectionId) {
            selectedSectionId = selectedSectionId || '0';

            var value = class_id + '-' + selectedSectionId;
            let url = "{{ route('admin.teacher.permission_list', ['filter' => ":value"]) }}";
            url = url.replace(":value", value);

            $.ajax({
                url: url,
                success: function(response){
                    $('.permission_content').html(response);
                }
            });
        };

        if (section_id == "") {
            classWiseSection(class_id, function(selectedSectionId) {
                loadPermissionList(selectedSectionId || '0');
            });
        } else {
            loadPermissionList(section_id);
        }
    }

</script>

@endsection
