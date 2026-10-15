<div class="eoff-form">
    <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.student.create') }}">
        <div class="alert alert-info">Add an existing student using their current registration number. No enrolment or fee invoice will be created. The student must change their temporary password on first login.</div>
        <div class="fpb-7">
            <label for="registration_number" class="eForm-label">Existing registration number</label>
            <input type="text" class="form-control eForm-control" id="registration_number" name="registration_number" maxlength="255" required>
        </div>
        @csrf 
        <div class="form-row">
            <div class="fpb-7">
                <label for="name" class="eForm-label">{{ get_phrase('Name') }}</label>
                <input type="text" class="form-control eForm-control" id="name" name = "name" required>
            </div>

            <div class="fpb-7">
                <label for="email" class="eForm-label">{{ get_phrase('Email') }}</label>
                <input type="email" class="form-control eForm-control" id="email" name = "email" required>
            </div>

            <div class="fpb-7">
                <label class="eForm-label">{{ get_phrase('Portal Password') }}</label>
                <div class="d-flex align-items-center gap-3 mb-2">
                    <label class="me-2"><input type="radio" name="password_option" value="auto" checked onclick="document.getElementById('password_manual_wrap').style.display='none'; document.getElementById('password').required=false;"> {{ get_phrase('Auto-generate') }}</label>
                    <label><input type="radio" name="password_option" value="manual" onclick="document.getElementById('password_manual_wrap').style.display='block'; document.getElementById('password').required=true;"> {{ get_phrase('Set preferred password') }}</label>
                </div>
                <div id="password_manual_wrap" style="display:none">
                    <input type="password" class="form-control eForm-control" id="password" name="password" minlength="6">
                </div>
            </div>

            <div class="fpb-7">
                <label for="programme_id" class="eForm-label">{{ academic_term('programme', auth()->user()->school_id) }}</label>
                <select name="programme_id" id="programme_id" class="form-select eForm-select eChoice-multiple-with-remove">
                    <option value="">{{ get_phrase('Select a programme') }}</option>
                    @foreach($programmes as $programme)
                        <option value="{{ $programme->id }}">{{ $programme->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="fpb-7">
                <label for="intake_session_id" class="eForm-label">{{ get_phrase('Intake') }}</label>
                <select name="intake_session_id" id="intake_session_id" class="form-select eForm-select eChoice-multiple-with-remove">
                    <option value="">{{ get_phrase('Select an intake') }}</option>
                    @foreach($intakeSessions as $session)
                        <option value="{{ $session->id }}">{{ $session->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="fpb-7">
                <label for="year_of_study" class="eForm-label">{{ get_phrase('Year of Study') }}</label>
                <input type="number" min="1" max="20" class="form-control eForm-control" id="year_of_study" name="year_of_study">
            </div>

            <div class="fpb-7">
                <label for="nationality" class="eForm-label">{{ get_phrase('Nationality') }}</label>
                <input type="text" class="form-control eForm-control" id="nationality" name="nationality">
            </div>

            <div class="fpb-7">
                <label for="national_id_or_passport" class="eForm-label">{{ get_phrase('National ID/Passport Number') }}</label>
                <input type="text" class="form-control eForm-control" id="national_id_or_passport" name="national_id_or_passport">
            </div>

            <div class="fpb-7">
                <label for="next_of_kin_address" class="eForm-label">{{ get_phrase('Next of Kin Address') }}</label>
                <textarea class="form-control eForm-control" id="next_of_kin_address" name="next_of_kin_address" rows="3"></textarea>
            </div>

            <div class="fpb-7">
                <label for="next_of_kin_contact" class="eForm-label">{{ get_phrase('Next of Kin Contact') }}</label>
                <input type="text" class="form-control eForm-control" id="next_of_kin_contact" name="next_of_kin_contact">
            </div>

            <div class="fpb-7">
                <label for="status" class="eForm-label">{{ get_phrase('Status') }}</label>
                <select name="status" id="status" class="form-select eForm-select eChoice-multiple-with-remove">
                    <option value="active" selected>{{ get_phrase('Active') }}</option>
                    <option value="suspended">{{ get_phrase('Suspended') }}</option>
                    <option value="graduated">{{ get_phrase('Graduated') }}</option>
                    <option value="withdrawn">{{ get_phrase('Withdrawn') }}</option>
                    <option value="deferred">{{ get_phrase('Deferred') }}</option>
                </select>
            </div>

            <div class="fpb-7">
                <label for="additional_photo" class="eForm-label">{{ get_phrase('Additional Image') }}</label>
                <input class="form-control eForm-control-file" id="additional_photo" name="additional_photo" accept="image/*" type="file">
            </div>

            <div class="fpb-7">
                <label for="birthdatepicker" class="eForm-label">{{ get_phrase('Birthday') }}<span class="required"></span></label>
                <input type="text" class="form-control eForm-control" id="eInputDate" name="birthday" value="{{ date('m/d/Y') }}" />
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
                <label for="phone" class="eForm-label">{{ get_phrase('Phone number') }}</label>
                <input type="text" class="form-control eForm-control" id="phone" name = "phone" required>
            </div>

            <div class="fpb-7">
                <label for="blood_group" class="eForm-label">{{ get_phrase('Blood group') }}</label>
                <select name="blood_group" id="blood_group" class="form-select eForm-select eChoice-multiple-with-remove">
                    <option value="">{{ get_phrase('Select a blood group') }}</option>
                    <option value="a+">{{ get_phrase('A+') }}</option>
                    <option value="a-">{{ get_phrase('A-') }}</option>
                    <option value="b+">{{ get_phrase('B+') }}</option>
                    <option value="b-">{{ get_phrase('B-') }}</option>
                    <option value="ab+">{{ get_phrase('AB+') }}</option>
                    <option value="ab-">{{ get_phrase('AB-') }}</option>
                    <option value="o+">{{ get_phrase('O+') }}</option>
                    <option value="o-">{{ get_phrase('O-') }}</option>
                </select>
            </div>

            <div class="fpb-7">
                <label for="phone" class="eForm-label">{{ get_phrase('Address') }}</label>
                <textarea class="form-control eForm-control" id="address" name = "address" rows="5" required></textarea>
            </div>

            <div class="fpb-7">
              <label for="formFile" class="eForm-label"
                >{{ get_phrase('Photo') }}</label
              >
              <input
                class="form-control eForm-control-file"
                id="photo" name="photo" accept="image/*"
                type="file"
              />
            </div>

            <div class="fpb-7 pt-2">
                <button class="btn-form" type="submit">{{ get_phrase('Create') }}</button>
            </div>
        </div>
    </form>
</div>

<script type="text/javascript">

    "use strict";

    $(document).ready(function () {
      $(".eChoice-multiple-with-remove").select2();
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

    $(function () {
      $('input[name="birthday"]').daterangepicker(
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

</script>
