<div class="eoff-form">
    <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.warden.update', ['id' => $user->id]) }}">
        @csrf
        <div class="form-row">
            <div class="fpb-7">
                <label for="email" class="eForm-label">{{ get_phrase('Email') }}</label>
                <input type="email" class="form-control eForm-control" value="{{ $user->email }}" id="email" name = "email" required>
            </div>
            <?php
            $info = json_decode($user->user_information ?? '') ?: (object) [];
            ?>

            @include('admin.staff._edit_fields', ['user' => $user, 'departments' => $departments, 'designations' => $designations])

            <div class="fpb-7">
                <label for="birthday" class="eForm-label">{{ get_phrase('Birthday') }}<span class="required"></span></label>
                {{-- A missing date of birth stays blank. Falling back to today would silently write today's date onto a staff record that never had one. --}}
                <input type="text" class="form-control eForm-control inputDate" id="birthday" name="birthday" value="{{ !empty($info->birthday) ? date('m/d/Y', (int) $info->birthday) : '' }}" />
            </div>
        </div>

        <div class="fpb-7">
            <label for="gender" class="eForm-label">{{ get_phrase('Gender') }}</label>
            <select name="gender" id="gender" class="form-select eForm-select eChoice-multiple-with-remove" required>
                <option value="">{{ get_phrase('Select gender') }}</option>
                <option value="Male" {{ $info->gender == 'Male' ? 'selected' : '' }}>{{ get_phrase('Male') }}</option>
                <option value="Female" {{ $info->gender == 'Female' ? 'selected' : '' }}>{{ get_phrase('Female') }}</option>
                <option value="Others" {{ $info->gender == 'Others' ? 'selected' : '' }}>{{ get_phrase('Others') }}</option>
            </select>
        </div>

        <div class="fpb-7">
            <label for="phone" class="eForm-label">{{ get_phrase('Phone number') }}</label>
            <input type="text" class="form-control eForm-control" value="{{ $info->phone }}" id="phone" name = "phone" required>
        </div>
        <div class="fpb-7">
            <label for="blood_group" class="eForm-label">{{ get_phrase('Blood group') }}</label>
            <select name="blood_group" id="blood_group" class="form-select eForm-select eChoice-multiple-with-remove">
                <option value="">{{ get_phrase('Select a blood group') }}</option>
                <option value="a+" {{ $info->blood_group == 'a+' ? 'selected' : '' }}>{{ get_phrase('A+') }}</option>
                <option value="a-" {{ $info->blood_group == 'a-' ? 'selected' : '' }}>{{ get_phrase('A-') }}</option>
                <option value="b+" {{ $info->blood_group == 'b+' ? 'selected' : '' }}>{{ get_phrase('B+') }}</option>
                <option value="b-" {{ $info->blood_group == 'b-' ? 'selected' : '' }}>{{ get_phrase('B-') }}</option>
                <option value="ab+" {{ $info->blood_group == 'ab+' ? 'selected' : '' }}>{{ get_phrase('AB+') }}</option>
                <option value="ab-" {{ $info->blood_group == 'ab-' ? 'selected' : '' }}>{{ get_phrase('AB-') }}</option>
                <option value="o+" {{ $info->blood_group == 'o+' ? 'selected' : '' }}>{{ get_phrase('O+') }}</option>
                <option value="o-" {{ $info->blood_group == 'o-' ? 'selected' : '' }}>{{ get_phrase('O-') }}</option>
            </select>
        </div>

        <div class="fpb-7">
            <label for="phone" class="eForm-label">{{ get_phrase('Address') }}</label>
            <textarea class="form-control eForm-control" id="address" name = "address" rows="5" required>{{ $info->address }}</textarea>
        </div>

        <div class="fpb-7">
            <label for="formFile" class="eForm-label">{{ get_phrase('Photo') }}</label>
            <input class="form-control eForm-control-file" id="photo" name="photo" accept="image/*" type="file" />
        </div>

        <div class="fpb-7 pt-2">
            <button class="btn-form" type="submit">{{ get_phrase('Update') }}</button>
        </div>
</div>
</form>
</div>

<script type="text/javascript">
    "use strict";
    $(document).ready(function() {
        $(".eChoice-multiple-with-remove").select2();
    });

    $(function() {
        $('.inputDate').daterangepicker({
                singleDatePicker: true,
                showDropdowns: true,
                minYear: 1901,
                maxYear: parseInt(moment().format("YYYY"), 10),
            },
            function(start, end, label) {
                var years = moment().diff(start, "years");
            }
        );
    });
</script>
