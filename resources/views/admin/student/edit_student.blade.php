<style>
    .inport_btn {

        justify-content: center;
        align-items: center;
        height: 42px;
        padding: 10px 20px;
        border: 1px solid transparent;
        border-radius: 5px;
        background-color: #f0f5fa;
        font-size: 13px;
        font-weight: 500;
        color: #1466AF;
        transition: all 0.3s;
    }

    .inport_btn:hover {
        background-color: transparent;
        border-color: #7CA7CD;
        color: #1466AF;
    }
</style>
<?php use App\Models\Section; ?>
<div class="eoff-form">
    <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm"
        action="{{ route('admin.student.update', ['id' => $user->id]) }}">
        @csrf
        <div class="form-row">
            <div class="fpb-7">
                <label for="name" class="eForm-label">{{ get_phrase('Name') }}</label>
                <input type="text" class="form-control eForm-control" value="{{ $user->name }}" id="name"
                    name = "name" required>
            </div>

            <div class="fpb-7">
                <label for="email" class="eForm-label">{{ get_phrase('Email') }}</label>
                <input type="email" class="form-control eForm-control" value="{{ $user->email }}" id="email"
                    name = "email" required>
            </div>

            @if(academic_education_level(auth()->user()->school_id) !== 'tertiary')
            <div class="fpb-7">
                <label for="class_id" class="eForm-label">{{ academic_term('class', auth()->user()->school_id) }}</label>
                <small class="text-muted d-block">{{ get_phrase('Students enrolled in this class can access matching class-targeted online exams.') }}</small>
                <select name="class_id" id="class_id" class="form-select eForm-select eChoice-multiple-with-remove"
                    required onchange="classWiseSection(this.value)">
                    <option value="">{{ get_phrase('Select a class') }}</option>
                    @foreach ($classes as $class)
                        <option value="{{ $class->id }}"
                            {{ $student_details['class_id'] == $class->id ? 'selected' : '' }}>{{ $class->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="fpb-7">
                <label for="section_id" class="eForm-label">{{ get_phrase('Class Section (optional)') }}</label>
                <select name="section_id" id="section_id" class="form-select eForm-select eChoice-multiple-with-remove"
                    >
                    <?php if($student_details['section_id'] !=""){
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            $sections = Section::get()->where('class_id', $student_details['class_id']); ?>
                    @foreach ($sections as $section)
                        <option value="{{ $section->id }}"
                            {{ $student_details['section_id'] == $section->id ? 'selected' : '' }}>
                            {{ $section->name }}
                        </option>
                    @endforeach
                    <?php } else { ?>
                    <option value="">{{ get_phrase('No section / whole class') }}</option>
                    <?php } ?>
                </select>
            </div>

            <div class="fpb-7">
                <label for="session_id" class="eForm-label">{{ academic_term('session', auth()->user()->school_id) }}</label>
                <select name="session_id" id="session_id" class="form-select eForm-select">
                    <option value="">{{ get_phrase('Use current running session') }}</option>
                    @foreach($sessions as $session)<option value="{{ $session->id }}" {{ (int)($student_details['session_id'] ?? 0) === (int)$session->id ? 'selected' : '' }}>{{ $session->name ?? $session->session_title ?? ('Session '.$session->id) }}</option>@endforeach
                </select>
            </div>
            @if($departments->count())
            <div class="fpb-7">
                <label for="department_id" class="eForm-label">{{ get_phrase('Department (optional)') }}</label>
                <select name="department_id" id="department_id" class="form-select eForm-select">
                    <option value="">{{ get_phrase('No department') }}</option>
                    @foreach($departments as $department)<option value="{{ $department->id }}" {{ (int)($student_details['department_id'] ?? 0) === (int)$department->id ? 'selected' : '' }}>{{ $department->name }}</option>@endforeach
                </select>
            </div>
            @endif

            @endif
            <?php
            $info = json_decode($user->user_information ?? '') ?: (object) [];
            $birthday = !empty($info->birthday) ? date('m/d/Y', (int) $info->birthday) : date('m/d/Y');
            ?>

            <div class="fpb-7">
                <label for="birthday" class="eForm-label">{{ get_phrase('Birthday') }}<span
                        class="required"></span></label>
                <input type="text" class="form-control eForm-control inputDate" id="birthday" name="birthday"
                    value="{{ $birthday }}" />
            </div>
        </div>

        <div class="fpb-7">
            <label for="gender" class="eForm-label">{{ get_phrase('Gender') }}</label>
            <select name="gender" id="gender" class="form-select eForm-select eChoice-multiple-with-remove"
                required>
                <option value="">{{ get_phrase('Select gender') }}</option>
                <option value="Male" {{ ($info->gender ?? '') == 'Male' ? 'selected' : '' }}>{{ get_phrase('Male') }}
                </option>
                <option value="Female" {{ ($info->gender ?? '') == 'Female' ? 'selected' : '' }}>{{ get_phrase('Female') }}
                </option>
                <option value="Others" {{ ($info->gender ?? '') == 'Others' ? 'selected' : '' }}>{{ get_phrase('Others') }}
                </option>
            </select>
        </div>

        <div class="fpb-7">
            <label for="phone" class="eForm-label">{{ get_phrase('Phone number') }}</label>
            <input type="text" class="form-control eForm-control" value="{{ $info->phone ?? '' }}" id="phone"
                name = "phone" placeholder="Provide student number" required>
        </div>
        <div class="fpb-7">
            <label for="blood_group" class="eForm-label">{{ get_phrase('Blood group') }}</label>
            <select name="blood_group" id="blood_group" class="form-select eForm-select eChoice-multiple-with-remove">
                <option value="">{{ get_phrase('Select a blood group') }}</option>
                <option value="a+" {{ ($info->blood_group ?? '') == 'a+' ? 'selected' : '' }}>{{ get_phrase('A+') }}
                </option>
                <option value="a-" {{ ($info->blood_group ?? '') == 'a-' ? 'selected' : '' }}>{{ get_phrase('A-') }}
                </option>
                <option value="b+" {{ ($info->blood_group ?? '') == 'b+' ? 'selected' : '' }}>{{ get_phrase('B+') }}
                </option>
                <option value="b-" {{ ($info->blood_group ?? '') == 'b-' ? 'selected' : '' }}>{{ get_phrase('B-') }}
                </option>
                <option value="ab+" {{ ($info->blood_group ?? '') == 'ab+' ? 'selected' : '' }}>{{ get_phrase('AB+') }}
                </option>
                <option value="ab-" {{ ($info->blood_group ?? '') == 'ab-' ? 'selected' : '' }}>{{ get_phrase('AB-') }}
                </option>
                <option value="o+" {{ ($info->blood_group ?? '') == 'o+' ? 'selected' : '' }}>{{ get_phrase('O+') }}
                </option>
                <option value="o-" {{ ($info->blood_group ?? '') == 'o-' ? 'selected' : '' }}>{{ get_phrase('O-') }}
                </option>
            </select>
        </div>

        <div class="fpb-7">
            <label for="others_information" class="eForm-label">{{ get_phrase('Additional information') }}</label>
            <div class="new_div">
                <div class="row">
                    @php

                        $student_details = json_decode($student_details->student_info);

                    @endphp
                    <div class="col-sm-8" id="inputContainer">
                        @if (!empty($student_details))
                            @foreach ($student_details as $student_detail)
                                <input type="text" name="student_info[]" class="eForm-control fmb-14 form-control"
                                placeholder="{{ get_phrase('Enter student information') }}"
                                value="{{ $student_detail }}">
                            @endforeach
                        @else
                            <input type="text" name="student_info[]" class="eForm-control fmb-14 form-control" placeholder="{{ get_phrase('Enter student information') }}" value="">
                        @endif
                    </div>
                    <div class="col-sm-4 p-0">
                        <button type="button" onclick="appendInput()" data-bs-toggle="tooltip"
                            data-bs-placement="bottom" title="Add more" class="inport_btn"><i
                                class="bi bi-plus"></i></button>
                        <button type="button" onclick="removeInput()" data-bs-toggle="tooltip"
                            data-bs-placement="bottom" title="Remove" class="inport_btn"> <i
                                class="bi bi-dash"></i></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="fpb-7">
            <label for="phone" class="eForm-label">{{ get_phrase('Address') }}</label>
            <textarea class="form-control eForm-control" id="address" name = "address" rows="5"
                placeholder="Provide student address" required>{{ $info->address ?? '' }}</textarea>
        </div>

        <div class="fpb-7">
            <label for="formFile" class="eForm-label">{{ get_phrase('Photo') }}</label>
            <input class="form-control eForm-control-file" id="photo" name="photo" accept="image/*"
                type="file" />
        </div>

        <div class="fpb-7">
            <label for="programme_id" class="eForm-label">{{ academic_term('programme', auth()->user()->school_id) }}</label>
            <select name="programme_id" id="programme_id" class="form-select eForm-select eChoice-multiple-with-remove">
                <option value="">{{ get_phrase('Select a programme') }}</option>
                @foreach($programmes as $programme)
                    <option value="{{ $programme->id }}" {{ optional($studentProfile)->programme_id == $programme->id ? 'selected' : '' }}>{{ $programme->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="fpb-7">
            <label for="intake_session_id" class="eForm-label">{{ get_phrase('Intake') }}</label>
            <select name="intake_session_id" id="intake_session_id" class="form-select eForm-select eChoice-multiple-with-remove">
                <option value="">{{ get_phrase('Select an intake') }}</option>
                @foreach($intakeSessions as $session)
                    <option value="{{ $session->id }}" {{ optional($studentProfile)->intake_session_id == $session->id ? 'selected' : '' }}>{{ $session->name }}</option>
                @endforeach
            </select>
        </div>

        @include('admin.student._affiliation_fields')
        <div class="fpb-7">
            <label for="year_of_study" class="eForm-label">{{ get_phrase('Year of Study') }}</label>
            <input type="number" min="1" max="20" class="form-control eForm-control" id="year_of_study" name="year_of_study" value="{{ optional($studentProfile)->year_of_study }}">
        </div>

        <div class="fpb-7">
            <label for="nationality" class="eForm-label">{{ get_phrase('Nationality') }}</label>
            <input type="text" class="form-control eForm-control" id="nationality" name="nationality" value="{{ optional($studentProfile)->nationality }}">
        </div>

        <div class="fpb-7">
            <label for="national_id_or_passport" class="eForm-label">{{ get_phrase('National ID/Passport Number') }}</label>
            <input type="text" class="form-control eForm-control" id="national_id_or_passport" name="national_id_or_passport" value="{{ optional($studentProfile)->national_id_or_passport }}">
        </div>

        <div class="fpb-7">
            <label for="next_of_kin_name" class="eForm-label">{{ get_phrase('Next of Kin Name') }}</label>
            <input type="text" class="form-control eForm-control" id="next_of_kin_name" name="next_of_kin_name" maxlength="150" value="{{ old('next_of_kin_name', optional($studentProfile)->next_of_kin_name) }}">
        </div>

        <div class="fpb-7">
            <label for="next_of_kin_relationship" class="eForm-label">{{ get_phrase('Next of Kin Relationship') }}</label>
            <input type="text" class="form-control eForm-control" id="next_of_kin_relationship" name="next_of_kin_relationship" maxlength="100" value="{{ old('next_of_kin_relationship', optional($studentProfile)->next_of_kin_relationship) }}">
        </div>

        <div class="fpb-7">
            <label for="next_of_kin_email" class="eForm-label">{{ get_phrase('Next of Kin Email (Optional)') }}</label>
            <input type="email" class="form-control eForm-control" id="next_of_kin_email" name="next_of_kin_email" maxlength="255" value="{{ old('next_of_kin_email', optional($studentProfile)->next_of_kin_email) }}" autocomplete="email">
        </div>

        <div class="fpb-7">
            <label for="next_of_kin_address" class="eForm-label">{{ get_phrase('Next of Kin Address') }}</label>
            <textarea class="form-control eForm-control" id="next_of_kin_address" name="next_of_kin_address" rows="3">{{ optional($studentProfile)->next_of_kin_address }}</textarea>
        </div>

        <div class="fpb-7">
            <label for="next_of_kin_contact" class="eForm-label">{{ get_phrase('Next of Kin Contact') }}</label>
            <input type="text" class="form-control eForm-control" id="next_of_kin_contact" name="next_of_kin_contact" value="{{ optional($studentProfile)->next_of_kin_contact }}">
        </div>

        <div class="fpb-7">
            <label for="status" class="eForm-label">{{ get_phrase('Status') }}</label>
            <select name="status" id="status" class="form-select eForm-select eChoice-multiple-with-remove">
                @foreach(['active' => 'Active', 'suspended' => 'Suspended', 'graduated' => 'Graduated', 'withdrawn' => 'Withdrawn', 'deferred' => 'Deferred'] as $value => $label)
                    <option value="{{ $value }}" {{ optional($studentProfile)->status == $value || (!$studentProfile && $value === 'active') ? 'selected' : '' }}>{{ get_phrase($label) }}</option>
                @endforeach
            </select>
        </div>

        <div class="fpb-7">
            <label for="additional_photo" class="eForm-label">{{ get_phrase('Additional Image') }}</label>
            <input class="form-control eForm-control-file" id="additional_photo" name="additional_photo" accept="image/*" type="file">
        </div>

        <div class="fpb-7 pt-2">
            <button class="btn-form" type="submit">{{ get_phrase('Update') }}</button>
        </div>
</div>
</form>
</div>

<script type="text/javascript">
    "use strict";
    if (window.jQuery) {
        $(document).ready(function() {
            if ($.fn.select2) {
                $(".eChoice-multiple-with-remove").select2();
            }
        });
    }

    function classWiseSection(classId) {
        let url = "{{ route('admin.class_wise_sections', ['id' => ':classId']) }}";
        url = url.replace(":classId", classId);
        $.ajax({
            url: url,
            success: function(response) {
                $('#section_id').html(response);
            }
        });
    }

    if (window.jQuery && $.fn.daterangepicker && window.moment) {
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
    }
</script>

<script type="text/javascript">
    "use strict";

    if (window.jQuery) {
        $(document).ready(function() {
            if ($.fn.select2) {
                $(".eChoice-multiple-with-remove").select2();
            }
        });
    }

    function togglepackageWiseOptions(interval) {
        if (interval === "life_time") {

            $("#days").hide();
            $('#limited').prop('disabled', true);
        } else {

            $("#days").show();
            $('#limited').prop('disabled', false);
        }
    }

    if (window.jQuery) {
        $(document).ready(function() {
            $("#unlimitedst").click(function() {
                $(".limitedst").hide();
            });
            $("#limited").click(function() {
                $(".limitedst").show();
                $("#studentLimit").attr('name', 'studentLimit');

            });
            $("#life_time").click(function() {
                $("#interval").hide();
            });
        });
    }




    function appendInput() {
        var container = document.getElementById('inputContainer');
        var newInput = document.createElement('input');
        newInput.setAttribute('type', 'text');
        newInput.setAttribute('placeholder', '{{ get_phrase('Enter student information') }}');
        newInput.setAttribute('class', 'eForm-control form-control mt-2');
        newInput.setAttribute('name', 'student_info[]');
        container.appendChild(newInput);
    }

    function removeInput() {
        var container = document.getElementById('inputContainer');
        var inputs = container.getElementsByTagName('input');
        if (inputs.length > 1) {
            container.removeChild(inputs[inputs.length - 1]);
        }
    }
</script>
