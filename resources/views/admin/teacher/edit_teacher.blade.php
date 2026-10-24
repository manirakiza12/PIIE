<div class="eForm-layouts">
    <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.teacher.update', ['id' => $user->id]) }}">
         @csrf 
        <div class="form-row">
            <div class="fpb-7">
                <label for="email" class="eForm-label">{{ get_phrase('Email') }}</label>
                <input type="email" class="form-control  eForm-control" value="{{ $user->email }}" id="email" name = "email" required>
            </div>
            <?php
            $info = json_decode($user->user_information ?? '') ?: (object) [];
            $birthday = !empty($info->birthday) ? date('m/d/Y', (int) $info->birthday) : date('m/d/Y');
            ?>

            @include('admin.staff._edit_fields', ['user' => $user, 'departments' => $departments, 'designations' => $designations])

            <div class="fpb-7">
                <label for="birthday" class="eForm-label">{{ get_phrase('Birthday') }}<span class="required"></span></label>
                <input type="text" class="form-control eForm-control inputDate" id="birthday" name="birthday" value="{{ $birthday }}" />
                </div>
            </div>

            <div class="fpb-7">
                <label for="gender" class="eForm-label">{{ get_phrase('Gender') }}</label>
                <select name="gender" id="gender" class="form-select eForm-select eChoice-multiple-with-remove"  required>
                    <option value="">{{ get_phrase('Select gender') }}</option>
                    <option value="Male" {{ ($info->gender ?? '') == 'Male' ?  'selected':'' }} >{{ get_phrase('Male') }}</option>
                    <option value="Female" {{ ($info->gender ?? '') == 'Female' ?  'selected':'' }}>{{ get_phrase('Female') }}</option>
                    <option value="Others" {{ ($info->gender ?? '') == 'Others' ?  'selected':'' }}>{{ get_phrase('Others') }}</option>
                </select>
            </div>
            <div class="fpb-7">
                <label for="phone" class="eForm-label">{{ get_phrase('Phone number') }}</label>
                <input type="text" class="form-control  eForm-control" value="{{ $info->phone ?? '' }}" id="phone" name = "phone" required>
            </div>


            <div class="fpb-7">
                <label for="address" class="eForm-label">{{ get_phrase('Address') }}</label>
                <textarea class="form-control eForm-control" id="address" name = "address" rows="5" required>{{ $info->address ?? '' }}</textarea>
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

            <div class="form-group mt-2 col-md-12">
                <button class="btn-form" type="submit">{{ get_phrase('Update') }}</button>
            </div>
        </div>
    </form>
</div>

<script type="text/javascript">
    "use strict";
        if (window.jQuery) {
            $(document).ready(function () {
                if ($.fn.select2) {
                    $(".eChoice-multiple-with-remove").select2();
                }

                if ($.fn.daterangepicker && window.moment) {
                    $('.inputDate').daterangepicker(
                        {
                            singleDatePicker: true,
                            showDropdowns: true,
                            minYear: 1901,
                            maxYear: parseInt(moment().format("YYYY"), 10),
                        },
                        function (start) {
                            moment().diff(start, "years");
                        }
                    );
                }
            });
        }
</script>
