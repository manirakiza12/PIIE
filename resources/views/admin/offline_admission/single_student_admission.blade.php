{{-- Start button style  --}}
<style>
    .inport_btn {
        display: flex;
        justify-content: center;
        align-items: center;
        height: 30px;
        padding: 12px 18px;
        border: 1px solid transparent;
        border-radius: 5px;
        background-color: #EFF2EF;
        font-size: 13px;
        font-weight: 500;
        color: #595d6f;
        transition: all 0.3s;
    }

    .inport_btn:hover {
        background-color: transparent;
        border-color: #DFDFE7;
        color: #6B708A;
    }

    .bottom-border {
        border-bottom: 2px solid #c9ccdb;
        border-top: 2px solid #c9ccdb;
        /* 2px solid black border at the bottom */
    }
</style>

{{-- End button style  --}}

<div class="eForm-layouts">
    <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm"
        action="{{ route('admin.offline_admission.create') }}">
        @csrf
        <div class="row fmb-14 justify-content-between align-items-center">
            <label for="name" class="col-sm-2 col-eForm-label">{{ get_phrase('Name') }}</label>
            <div class="col-sm-10 col-md-9 col-lg-10">
                <input type="text" class="form-control eForm-control" id="name" name="name" required>
            </div>
        </div>
        <div class="row fmb-14 justify-content-between align-items-center">
            <label for="email" class="col-sm-2 col-eForm-label">{{ get_phrase('Email') }}</label>
            <div class="col-sm-10 col-md-9 col-lg-10">
                <input type="email" class="form-control eForm-control" id="email" name="email" required>
            </div>
        </div>
        <div class="row fmb-14 justify-content-between align-items-center">
            <label for="password" class="col-sm-2 col-eForm-label">{{ get_phrase('Password') }}</label>
            <div class="col-sm-10 col-md-9 col-lg-10">
                <input type="password" class="form-control eForm-control" id="password" name="password" required>
            </div>
        </div>

        <div class="row fmb-14 justify-content-between align-items-center">
            <label for="class_id" class="col-sm-2 col-eForm-label">{{ get_phrase('Class') }}</label>
            <div class="col-md-10">
                <select name="class_id" id="class_id" class="form-select eForm-select eChoice-multiple-with-remove"
                    required onchange="classWiseSection(this.value)">
                    <option value="">{{ get_phrase('Select a class') }}</option>
                    @foreach ($data['classes'] as $class)
                        <option value="{{ $class->id }}">{{ $class->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="row fmb-14 justify-content-between align-items-center">
            <label for="section_id" class="col-sm-2 col-eForm-label">{{ get_phrase('Section') }}</label>
            <div class="col-md-10" id = "section_content">
                <select name="section_id" id="section_id" class="form-select eForm-select eChoice-multiple-with-remove"
                    required>
                    <option value="">{{ get_phrase('Select section') }}</option>
                </select>
            </div>
        </div>

        <div class="row fmb-14 justify-content-between align-items-center">
            <label for="birthdatepicker" class="col-sm-2 col-eForm-label">{{ get_phrase('Birthday') }}<span
                    class="required"></span></label>
            <div class="col-md-10">
                <input type="text" class="form-control eForm-control" id="eInputDate" name="eDefaultDateRange"
                    value="{{ date('m/d/Y') }}" />
            </div>
        </div>

        <div class="row fmb-14 justify-content-between align-items-center">
            <label for="gender" class="col-sm-2 col-eForm-label">{{ get_phrase('Gender') }}</label>
            <div class="col-md-10">
                <select name="gender" id="gender" class="form-select eForm-select eChoice-multiple-with-remove"
                    required>
                    <option value="">{{ get_phrase('Select gender') }}</option>
                    <option value="Male">{{ get_phrase('Male') }}</option>
                    <option value="Female">{{ get_phrase('Female') }}</option>
                    <option value="Others">{{ get_phrase('Others') }}</option>
                </select>
            </div>
        </div>

        <div class="row fmb-14 justify-content-between align-items-center">
            <label for="address" class="col-sm-2 col-eForm-label">{{ get_phrase('Address') }}</label>
            <div class="col-md-10">
                <textarea class="form-control eForm-control" id="address" rows="4" name = "address" required></textarea>
            </div>
        </div>

        <div class="row fmb-14 justify-content-between align-items-center">
            <label for="phone" class="col-sm-2 col-eForm-label">{{ get_phrase('Phone') }}</label>
            <div class="col-md-10">
                <input type="text" id="phone" name="phone" class="form-control eForm-control" required>
            </div>
        </div>

        <div class="row fmb-14 justify-content-between align-items-center">
            <label for="photo" class="col-sm-2 col-eForm-label">{{ get_phrase('Student profile image') }}</label>
            <div class="col-md-10">
                <input class="form-control eForm-control-file" type="file" id="photo" name="photo"
                    accept="image/*">
            </div>
        </div>

        <hr style="background-color:#6B708A;">
        <div class="row fmb-14 justify-content-between align-items-center">
            <label for="student_info"
                class="col-sm-2 col-eForm-label">{{ get_phrase('Additional information') }}</label>
            <div class="col-md-10">
                <div class="">
                    <div class="fmb-14" id="inputContainer">
                        <input type="text" name="student_info[]" class="form-control eForm-control text-secondary"
                            placeholder="{{ get_phrase('Enter student information') }}">
                    </div>
                    <div class="col-sm-3 p-0 d-flex gap-2">
                        <button type="button" onclick="appendInput()" data-bs-placement="right"
                            data-bs-toggle="tooltip" title="Add more" class="inport_btn"><i
                                class="bi bi-plus"></i></button>
                        <button type="button" onclick="removeInput()" data-bs-toggle="tooltip"
                            data-bs-placement="right" title="Remove" class="inport_btn">
                            <i class="bi bi-dash"></i></button>
                    </div>
                </div>
            </div>
        </div>
        <hr style="background-color:#6B708A;">

        <div class="row p-3">
            <div class="col-sm-10 offset-sm-2">
                <button type="submit" class="btn-form">{{ get_phrase('Add Student') }}</button>
            </div>
        </div>
    </form>
</div>

{{-- Start javasript --}}
<script type="text/javascript">
    "use strict";

    $(document).ready(function() {
        $(".eChoice-multiple-with-remove").select2();
    });

    function togglepackageWiseOptions(interval) {
        if (interval === "life_time") {

            $("#days").hide();
            $('#limited').prop('disabled', true);
        } else {

            $("#days").show();
            $('#limited').prop('disabled', false);
        }
    }

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




    function appendInput() {
        var container = document.getElementById('inputContainer');
        var newInput = document.createElement('input');
        newInput.setAttribute('type', 'text');
        newInput.setAttribute('placeholder', '{{ get_phrase('Enter student information') }}');
        newInput.setAttribute('class', 'form-control eForm-control mt-2');
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
