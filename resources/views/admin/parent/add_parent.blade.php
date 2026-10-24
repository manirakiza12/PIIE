@extends('admin.navigation')

@section('content')

<div class="mainSection-title">
    <div class="row">
      <div class="col-12">
        <div
          class="d-flex justify-content-between align-items-center flex-wrap gr-15"
        >
          <div class="d-flex flex-column">
            <h4>{{ get_phrase('Parent Create') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
              <li><a href="#">{{ get_phrase('Home') }}</a></li>
              <li><a href="#">{{ get_phrase('Users') }}</a></li>
              <li><a href="#">{{ get_phrase('Parent') }}</a></li>
              <li><a href="#">{{ get_phrase('Add Parent') }}</a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap-2">
            <div class="eMain">
                <div class="row">
                    <div class="col-md-6 pb-3">
                        <div class="eForm-layouts">
                            <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.parent.create') }}">
                                @csrf 

                                <div class="fpb-7">
                                    <label for="name" class="eForm-label">{{ get_phrase('Name') }}</label>
                                    <input type="text" class="form-control eForm-control" id="name" name = "name" required>
                                </div>

                                <div class="fpb-7">
                                    <label for="email" class="eForm-label">{{ get_phrase('Email') }}</label>
                                    <input type="email" class="form-control eForm-control" id="email" name = "email" required>
                                </div>

                                <div class="fpb-7">
                                    <label for="password" class="eForm-label">{{ get_phrase('Password') }}</label>
                                    <input type="password" class="form-control eForm-control" id="password" name = "password" required>
                                </div>

                                <div class="fpb-7">
                                    <label for="birthdatepicker" class="eForm-label">{{ get_phrase('Birthday') }}</label>
                                    <input type="text" class="form-control eForm-control inputDate" id="birthday" name="birthday" value="{{ date('m/d/Y') }}" />
                                </div>

                                <div class="row">
                                    <div class="form-group col-xl-5 col-lg-5 col-md-5 col-sm-12 col-xs-12 pt-2">
                                        <label for="class_id" class="eForm-label">{{ get_phrase('Class') }}</label>
                                        <select name="class_id" id="class_id" class="form-select eForm-select eChoice-multiple-with-remove" required onchange="classWiseSection(this.value)">
                                            <option value="">{{ get_phrase('Select a class') }}</option>
                                            @foreach($classes as $class)
                                                <option value="{{ $class->id }}">{{ $class->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div class="form-group col-xl-5 col-lg-5 col-md-5 col-sm-12 col-xs-12 pt-2">
                                        <label for="section_id" class="eForm-label">
                                            {{ get_phrase('Section') }}
                                            <a href="javascript:;" class="ms-1" title="{{ get_phrase('Add Section') }}" onclick="openSectionManager()">
                                                <i class="bi bi-plus-circle"></i>
                                            </a>
                                        </label>
                                        <select name="section_id" id="section_id" class="form-select eForm-select eChoice-multiple-with-remove" required onchange="sectionWiseStudent(this.value)" >
                                            <option value="">{{ get_phrase('Select section') }}</option>
                                        </select>
                                    </div>
                                </div>

                                <div id="first_row">

                                    <div class="row p-0">
                                        <div class="form-group col-xl-10 col-lg-5 col-md-5 col-sm-12 col-xs-12 pt-2">
                                            <label for="student_id[]" class="eForm-label">
                                                {{ get_phrase('Child') }}
                                                <a href="javascript:;" class="ms-1" title="{{ get_phrase('Add Student') }}" onclick="openStudentCreateModal()">
                                                    <i class="bi bi-plus-circle"></i>
                                                </a>
                                            </label>
                                            <select name="student_id[]" id="student_id_1" class="form-select eForm-select eChoice-multiple-with-remove" >
                                                <option value=""></option>
                                            </select>
                                        </div>

                                        <div class="col-xl-1 col-lg-1 col-md-2 col-sm-12 mb-3 mb-lg-0 pt-3">
                                            <label class="eForm-label">{{ get_phrase('') }}</label>
                                            <button type="button" class="btn btn-icon btn-success" onclick="appendRow()"> <i class="bi bi-plus"></i> </button>
                                        </div>
                                    </div>

                                </div>


                                <div id="blank_row">
                                    <div class="child_added_row row p-0">
                                        <div class="form-group col-xl-10 col-lg-5 col-md-5 col-sm-12 col-xs-12 pt-2">
                                            <input type="text" name="student_name[]" id="student_name" class="student_name form-control eForm-control" readonly>
                                        </div>

                                        <input type="text" name="student_id[]" id="student_id" class="student_id form-control eForm-control" readonly hidden>

                                        <div class="col-xl-1 col-lg-1 col-md-2 col-sm-12 mb-3 mb-lg-0 pt-2">
                                            <button type="button" class="delete_child btn btn-icon btn-danger" id="delete_child" onclick="removeRow(this)"> <i class="bi bi-x"></i> </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="fpb-7">
                                    <label for="gender" class="eForm-label">{{ get_phrase('Gender') }}</label>
                                    <select name="gender" id="gender" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                                        <option value="">{{ get_phrase('Select gender') }}</option>
                                        <option value="Male">{{ get_phrase('Male') }}</option>
                                        <option value="Female">{{ get_phrase('Female') }}</option>
                                        <option value="Others">{{ get_phrase('Others') }}</option>
                                    </select>
                                </div>



                                <div class="fpb-7">
                                    <label for="address" class="eForm-label">{{ get_phrase('Address') }}</label>
                                    <textarea class="form-control eForm-control" id="address" name="address" rows="4" required="" spellcheck="false"></textarea>
                                </div>

                                <div class="fpb-7">
                                    <label for="phone" class="eForm-label">{{ get_phrase('Phone') }}</label>
                                    <input type="text" class="form-control eForm-control" id="phone" name="phone" required />
                                </div>

                                <div class="fpb-7">
                                    <label for="formFile" class="eForm-label">{{ get_phrase('Photo') }}</label>
                                    <input class="form-control eForm-control-file" id="photo" name="photo" accept="image/*" type="file" />
                                </div>

                                <div class="fpb-7 pt-2">
                                    <button type="submit" class="btn-form">{{ get_phrase('Add parent') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<script type="text/javascript">
    "use strict";

     // CREATING BLANK ALLOWANCE INPUT
    var blank_field = '';
    var student_array = [];

    $(document).ready(function () {
        $('#blank_row').hide();
        $(".eChoice-multiple-with-remove").select2();
        blank_field = $('#blank_row').html();
    });

    $(function () {
      $('.inputDate').daterangepicker(
        {
          singleDatePicker: true,
          showDropdowns: true,
          minYear: 1901,
          maxYear: parseInt(moment().format("YYYY"), 10),
        },
        function (start, end, label) {
          var years = moment().diff(start, "years");
        }
      );
    });


    function classWiseSection(classId) {
        let url = "{{ route('class_wise_sections', ['id' => ":classId"]) }}";
        url = url.replace(":classId", classId);
        $.ajax({
            url: url,
            success: function(response){
                $('#section_id').html(response);
            }
        });
    }

    function sectionWiseStudent(sectionId) {
        let url = "{{ route('section_wise_students', ['id' => ":sectionId"]) }}";
        url = url.replace(":sectionId", sectionId);
        $.ajax({
            url: url,
            success: function(response){
                $('#student_id_1').html(response);
            }
        });
    }

    var child_count     = 1;

    function appendRow() {
        child_count++;
        var id=$('#student_id_1').val();
        var class_id=$('#class_id').val();
        var section_id=$('#section_id').val();

        if(class_id == '') {
            toastr.warning('Select a class first');
            return;
        }

        if(section_id == '') {
            toastr.warning('Select a section first, or tap + to add section');
            return;
        }

        if(id == '') {
            var options = $('#student_id_1 option').length;
            if(options <= 1) {
                toastr.info('No students available. Opening create student form.');
                openStudentCreateModal();
            } else {
                toastr.warning('Select a child first');
            }
            return;
        }

        if(!student_array.includes(id)) {
            student_array.push(id);
            $('#first_row').append(blank_field);
            $('#student_id').attr('id', 'student_id_' + child_count);
            $('#student_name').attr('id', 'student_name_' + child_count);


            var name = "";

            let url = "{{ route('id_wise_user_name', ['id' => ":id"]) }}";
            url = url.replace(":id", id);
            $.ajax({
                url: url,
                success: function(response){
                    name=response;

                    $('#student_name_' + child_count).val(name);
                    $('#student_id_' + child_count).val(id);
                }
            });

            document.getElementById("student_id_1").value = "";
        } else {
            toastr.warning('Student already added');
        }
    }

    function openSectionManager() {
        var class_id = $('#class_id').val();
        if(class_id == '') {
            toastr.warning('Select a class first to manage sections');
            return;
        }

        let url = "{{ route('admin.edit.section', ['id' => ':classId']) }}";
        url = url.replace(':classId', class_id);
        rightModal(url, '{{ get_phrase('Manage Sections') }}');
    }

    function openStudentCreateModal() {
        rightModal("{{ route('admin.student.open_modal') }}", "{{ get_phrase('Create Student') }}");
    }

    function removeRow(elem) {
        $(elem).closest('.child_added_row').remove();
    }


</script>


@endsection
