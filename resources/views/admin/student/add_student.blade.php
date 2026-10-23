<div class="eoff-form">
    <div class="alert alert-info">
        {{ get_phrase('For a new student, complete an application with personal information, programme selection, education history and required documents. Submit and accept the application before enrolling the student.') }}
        <a href="{{ route('admin.hei_admissions.wizard.create') }}" class="fw-bold">{{ get_phrase('Add Application') }}</a>
    </div>
    <form method="POST" enctype="multipart/form-data" class="d-block ajaxForm" action="{{ route('admin.student.create') }}">
        <div class="alert alert-info">Add an existing student using their current registration number. The student receives only a password setup email for the student portal. After saving, upload the documents held by the administration on the Documents page. No applicant account, application fee or new enrolment is created.</div>
        <div class="alert alert-secondary">Finance can set up this student's fee once below. Captured prior payments, approved awards, and study-waiver requests are held for review in Fees Management; they do not change the payable balance or study eligibility until separately confirmed by an authorized reviewer.</div>
        <div class="alert alert-warning"><strong>Academic placement is a separate step.</strong> Programme, intake and year below are profile details only. After saving, you will open this student's Documents page. Use its Student Placement link to select the approved curriculum, Programme Year and Academic Year, then register and confirm the student in matching Course Allocations. This form creates no class/section enrollment or module registrations.</div>
        <div class="fpb-7">
            <label for="registration_number" class="eForm-label">Existing registration number</label>
            <input type="text" class="form-control eForm-control" id="registration_number" name="registration_number" maxlength="255" required>
        </div>
        @csrf 
        @include('admin.student._affiliation_fields')
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

            @if($canRecordExistingStudentFees ?? false)
                <div class="fpb-7 border rounded p-3">
                    <h5>Financial Setup</h5>
                    <p class="small text-muted">Step 1: enter the agreed fee and study period. Optional prior cash, scholarship/bursary, and study-waiver details are saved once and reviewed from this invoice in Fees Management.</p>
                    <label class="eForm-label" for="record_fees">
                        <input type="checkbox" id="record_fees" name="record_fees" value="1" {{ old('record_fees') ? 'checked' : '' }}>
                        Set up this student's fee invoice now
                    </label>
                    <p class="small text-muted mb-2">The full agreed fee remains on the invoice. Review items below are not cash receipts and do not reduce the balance until confirmed.</p>
                    <div id="existing_student_fee_fields" {{ old('record_fees') ? '' : 'hidden' }}>
                        <div class="fpb-7">
                            <label for="fee_session_id" class="eForm-label">Study period</label>
                            <select id="fee_session_id" name="fee_session_id" class="form-select eForm-select" {{ old('record_fees') ? '' : 'disabled' }}>
                                <option value="">Select a study period</option>
                                @foreach($sessions as $session)
                                    <option value="{{ $session->id }}" {{ (string) old('fee_session_id', $runningSessionId) === (string) $session->id ? 'selected' : '' }}>{{ $session->session_title }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="fpb-7">
                            <label for="fee_structure_id" class="eForm-label">Fee type</label>
                            <select id="fee_structure_id" name="fee_structure_id" class="form-select eForm-select" {{ old('record_fees') ? '' : 'disabled' }}>
                                <option value="">Select a mandatory fee type</option>
                                @foreach($feeStructures as $structure)
                                    <option value="{{ $structure->id }}" {{ (string) old('fee_structure_id') === (string) $structure->id ? 'selected' : '' }}>{{ $structure->name }} (standard {{ school_currency($structure->amount) }})</option>
                                @endforeach
                            </select>
                            @if($feeStructures->isEmpty())<small class="text-danger">No mandatory institution or programme fee structures are configured. Finance must configure the correct fee before using this option.</small>@endif
                        </div>
                        <div class="fpb-7">
                            <label for="actual_fee_amount" class="eForm-label">Student's actual fee for this period ({{ school_currency() }})</label>
                            <input id="actual_fee_amount" name="actual_fee_amount" type="number" min="0.01" step="0.01" class="form-control eForm-control" value="{{ old('actual_fee_amount') }}" {{ old('record_fees') ? 'required' : 'disabled' }}>
                        </div>
                        <div class="fpb-7">
                            <label for="prior_paid_amount" class="eForm-label">Verified amount already paid ({{ school_currency() }})</label>
                            <input id="prior_paid_amount" name="prior_paid_amount" type="number" min="0" step="0.01" class="form-control eForm-control" value="{{ old('prior_paid_amount', '0') }}" {{ old('record_fees') ? '' : 'disabled' }}>
                        </div>
                        <div class="row" id="prior_payment_details" {{ old('record_fees') && (float) old('prior_paid_amount', 0) > 0 ? '' : 'hidden' }}>
                            <div class="fpb-7 col-md-6">
                                <label for="prior_paid_on" class="eForm-label">Original payment date</label>
                                <input id="prior_paid_on" name="prior_paid_on" type="date" max="{{ date('Y-m-d') }}" class="form-control eForm-control" value="{{ old('prior_paid_on') }}" {{ old('record_fees') && (float) old('prior_paid_amount', 0) > 0 ? 'required' : 'disabled' }}>
                            </div>
                            <div class="fpb-7 col-md-6">
                                <label for="prior_payment_method" class="eForm-label">Original payment method</label>
                                <select id="prior_payment_method" name="prior_payment_method" class="form-select eForm-select" {{ old('record_fees') && (float) old('prior_paid_amount', 0) > 0 ? 'required' : 'disabled' }}>
                                    <option value="">Select method</option>
                                    @foreach(['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'mobile_money' => 'Mobile money', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'] as $value => $label)
                                        <option value="{{ $value }}" {{ old('prior_payment_method') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="fpb-7 col-md-6">
                                <label for="prior_payment_reference" class="eForm-label">Receipt or reference</label>
                                <input id="prior_payment_reference" name="prior_payment_reference" maxlength="150" class="form-control eForm-control" value="{{ old('prior_payment_reference') }}" {{ old('record_fees') && (float) old('prior_paid_amount', 0) > 0 ? 'required' : 'disabled' }}>
                            </div>
                            <div class="fpb-7 col-md-6">
                                <label for="prior_payment_reason" class="eForm-label">Verification note</label>
                                <textarea id="prior_payment_reason" name="prior_payment_reason" minlength="10" maxlength="500" class="form-control eForm-control" {{ old('record_fees') && (float) old('prior_paid_amount', 0) > 0 ? 'required' : 'disabled' }}>{{ old('prior_payment_reason') }}</textarea>
                            </div>
                        </div>
                        <div class="border-top mt-3 pt-3">
                            <label class="eForm-label" for="record_award">
                                <input type="checkbox" id="record_award" name="record_award" value="1" {{ old('record_award') ? 'checked' : '' }}>
                                Capture an approved scholarship or bursary for review
                            </label>
                            <p class="small text-muted">An award is fee coverage, never cash received. It reduces the payable invoice only after Finance confirms it.</p>
                            <div id="award_details" {{ old('record_fees') && old('record_award') ? '' : 'hidden' }}>
                                <div class="row">
                                    <div class="fpb-7 col-md-4">
                                        <label for="award_type" class="eForm-label">Award type</label>
                                        <select id="award_type" name="award_type" class="form-select eForm-select" {{ old('record_fees') && old('record_award') ? 'required' : 'disabled' }}>
                                            <option value="">Select type</option>
                                            <option value="scholarship" {{ old('award_type') === 'scholarship' ? 'selected' : '' }}>Scholarship</option>
                                            <option value="bursary" {{ old('award_type') === 'bursary' ? 'selected' : '' }}>Bursary</option>
                                        </select>
                                    </div>
                                    <div class="fpb-7 col-md-4">
                                        <label for="award_amount" class="eForm-label">Amount covered ({{ school_currency() }})</label>
                                        <input id="award_amount" name="award_amount" type="number" min="0.01" step="0.01" class="form-control eForm-control" value="{{ old('award_amount') }}" {{ old('record_fees') && old('record_award') ? 'required' : 'disabled' }}>
                                    </div>
                                    <div class="fpb-7 col-md-4">
                                        <label for="award_reference" class="eForm-label">Approval reference</label>
                                        <input id="award_reference" name="award_reference" maxlength="150" class="form-control eForm-control" value="{{ old('award_reference') }}" {{ old('record_fees') && old('record_award') ? 'required' : 'disabled' }}>
                                    </div>
                                    <div class="fpb-7 col-12">
                                        <label for="award_reason" class="eForm-label">Approval reason</label>
                                        <textarea id="award_reason" name="award_reason" minlength="10" maxlength="500" class="form-control eForm-control" {{ old('record_fees') && old('record_award') ? 'required' : 'disabled' }}>{{ old('award_reason') }}</textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @if($canRequestStudyWaiver ?? false)
                            <div class="border-top mt-3 pt-3">
                                <label class="eForm-label" for="record_study_waiver">
                                    <input type="checkbox" id="record_study_waiver" name="record_study_waiver" value="1" {{ old('record_study_waiver') ? 'checked' : '' }}>
                                    Request the 20% study-requirement waiver for review
                                </label>
                                <p class="small text-muted">For the institution's current fee session only. This waives the 20% fee threshold for study registration, not the fee itself or academic requirements.</p>
                                <div id="study_waiver_details" {{ old('record_fees') && old('record_study_waiver') ? '' : 'hidden' }}>
                                    <label for="study_waiver_reason" class="eForm-label">Required reason</label>
                                    <textarea id="study_waiver_reason" name="study_waiver_reason" minlength="10" maxlength="500" class="form-control eForm-control" {{ old('record_fees') && old('record_study_waiver') ? 'required' : 'disabled' }}>{{ old('study_waiver_reason') }}</textarea>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

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
                <label for="next_of_kin_name" class="eForm-label">{{ get_phrase('Next of Kin Name') }}</label>
                <input type="text" class="form-control eForm-control" id="next_of_kin_name" name="next_of_kin_name" maxlength="150" value="{{ old('next_of_kin_name') }}">
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

    (function () {
        const setup = document.getElementById('record_fees');
        const fields = document.getElementById('existing_student_fee_fields');
        const priorAmount = document.getElementById('prior_paid_amount');
        const priorDetails = document.getElementById('prior_payment_details');
        const awardToggle = document.getElementById('record_award');
        const awardDetails = document.getElementById('award_details');
        const waiverToggle = document.getElementById('record_study_waiver');
        const waiverDetails = document.getElementById('study_waiver_details');
        if (!setup || !fields || !priorAmount || !priorDetails) return;
        function syncDetails(toggle, details) {
            if (!toggle || !details) return;
            details.hidden = !setup.checked || !toggle.checked;
            details.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = details.hidden;
                field.required = !details.hidden;
            });
        }
        function syncFinanceFields() {
            fields.hidden = !setup.checked;
            fields.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = !setup.checked;
            });
            priorDetails.hidden = !setup.checked || Number(priorAmount.value || 0) <= 0;
            priorDetails.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = priorDetails.hidden;
                field.required = !priorDetails.hidden;
            });
            syncDetails(awardToggle, awardDetails);
            syncDetails(waiverToggle, waiverDetails);
        }
        setup.addEventListener('change', syncFinanceFields);
        priorAmount.addEventListener('input', syncFinanceFields);
        if (awardToggle) awardToggle.addEventListener('change', syncFinanceFields);
        if (waiverToggle) waiverToggle.addEventListener('change', syncFinanceFields);
        syncFinanceFields();
    })();

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
