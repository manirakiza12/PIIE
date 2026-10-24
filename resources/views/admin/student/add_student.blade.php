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
<div class="existing-student-form eoff-form">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <p class="text-uppercase small fw-semibold text-primary mb-1">Student records</p>
            <h3 class="h4 mb-2">Add Existing Student</h3>
            <p class="text-muted mb-0">Create a student portal account for someone who already has a PIIE registration number.</p>
        </div>
        <a href="{{ route('admin.hei_admissions.wizard.create') }}" class="btn btn-outline-primary flex-shrink-0">Add a new application</a>
    </div>

    <div class="alert alert-info" role="note">
        This creates an existing-student portal account only. It does not create an applicant account, application fee, enrolment, curriculum placement, or course registration. Upload documents held by the administration after saving.
    </div>
    <div class="alert alert-secondary" role="note">
        Prior payments, awards, and study-waiver requests remain pending for authorized Finance review. They are not treated as confirmed cash, do not reduce the invoice balance, and do not change study eligibility until reviewed.
    </div>

    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.student.create') }}" id="existing-student-form">
        @csrf

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="existing-registration-heading">
            <div class="card-body p-3 p-md-4">
                <div class="mb-3">
                    <h4 class="h5 mb-1" id="existing-registration-heading">1. Existing registration</h4>
                    <p class="small text-muted mb-0">Use the student's current PIIE number. Add partner institution details when applicable.</p>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="registration_number" class="form-label">Existing registration number <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control @error('registration_number') is-invalid @enderror" id="registration_number" name="registration_number" maxlength="255" value="{{ old('registration_number') }}" required aria-describedby="registration_number_error">
                        @error('registration_number')<div class="invalid-feedback" id="registration_number_error">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        @include('admin.student._affiliation_fields')
                    </div>
                </div>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="student-account-heading">
            <div class="card-body p-3 p-md-4">
                <div class="mb-3">
                    <h4 class="h5 mb-1" id="student-account-heading">2. Student account and portal access</h4>
                    <p class="small text-muted mb-0">The student receives a password setup email when mail delivery is available.</p>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Student name <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" maxlength="255" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Student email <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" maxlength="255" required autocomplete="email">
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <fieldset class="col-12">
                        <legend class="form-label mb-2">Portal password</legend>
                        <div class="d-flex flex-wrap gap-3" role="radiogroup" aria-label="Portal password setup">
                            <label class="form-check-label"><input class="form-check-input me-1" type="radio" name="password_option" value="auto" @checked(old('password_option', 'auto') === 'auto')> Auto-generate and email a setup link</label>
                            <label class="form-check-label"><input class="form-check-input me-1" type="radio" name="password_option" value="manual" @checked(old('password_option') === 'manual')> Set a preferred password</label>
                        </div>
                        <div id="password_manual_wrap" class="mt-3" @if(old('password_option', 'auto') !== 'manual') hidden @endif>
                            <label for="password" class="form-label">Preferred password</label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" minlength="6" autocomplete="new-password" @if(old('password_option') === 'manual') required @endif>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Use at least 6 characters. The student can change it later.</div>
                        </div>
                    </fieldset>
                </div>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="academic-details-heading">
            <div class="card-body p-3 p-md-4">
                <div class="mb-3">
                    <h4 class="h5 mb-1" id="academic-details-heading">3. Programme, intake, and academic details</h4>
                    <p class="small text-muted mb-0">Programme and intake describe the student's record. Year of Study is their progression stage; it is not an academic year or a placement.</p>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="programme_id" class="form-label">{{ academic_term('programme', auth()->user()->school_id) }} <span class="text-muted small">Optional</span></label>
                        <select name="programme_id" id="programme_id" class="form-select @error('programme_id') is-invalid @enderror">
                            <option value="">{{ get_phrase('Select a programme') }}</option>
                            @foreach($programmes as $programme)
                                <option value="{{ $programme->id }}" @selected((string) old('programme_id') === (string) $programme->id)>{{ $programme->name }}</option>
                            @endforeach
                        </select>
                        @error('programme_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="intake_session_id" class="form-label">Intake <span class="text-muted small">Optional</span></label>
                        <select name="intake_session_id" id="intake_session_id" class="form-select @error('intake_session_id') is-invalid @enderror">
                            <option value="">{{ get_phrase('Select an intake') }}</option>
                            @foreach($intakeSessions as $intake)
                                <option value="{{ $intake->id }}" @selected((string) old('intake_session_id') === (string) $intake->id)>{{ $intake->name }}</option>
                            @endforeach
                        </select>
                        @error('intake_session_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="year_of_study" class="form-label">Year of Study <span class="text-muted small">Optional</span></label>
                        <input type="number" min="1" max="20" class="form-control @error('year_of_study') is-invalid @enderror" id="year_of_study" name="year_of_study" value="{{ old('year_of_study') }}" inputmode="numeric">
                        @error('year_of_study')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Progression stage (1–20). It is separate from the academic year used for a fee invoice.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="fee_academic_year_id" class="form-label">Academic year for Finance <span class="text-muted small">Optional</span></label>
                        <select id="fee_academic_year_id" name="fee_academic_year_id" class="form-select @error('fee_academic_year_id') is-invalid @enderror" aria-describedby="fee-academic-year-help fee_academic_year_id_error">
                            <option value="">Select an academic year</option>
                            @foreach($academicYears as $academicYear)
                                <option value="{{ $academicYear->id }}" @selected((string) old('fee_academic_year_id', $defaultFeeAcademicYearId) === (string) $academicYear->id)>{{ $academicYear->label }}</option>
                            @endforeach
                        </select>
                        @error('fee_academic_year_id')<div class="invalid-feedback" id="fee_academic_year_id_error">{{ $message }}</div>@enderror
                        <div class="form-text" id="fee-academic-year-help">This context filters Finance's fee-session list only. This form does not create an academic-year placement.</div>
                    </div>
                    <div class="col-12">
                        <div class="small text-muted p-3 bg-light rounded" id="cohort-context" role="status" aria-live="polite">
                            Programme cohorts can connect a programme and intake to an entry academic year. This form saves profile details only; use Student Placement after creation to assign an approved curriculum and academic year.
                        </div>
                    </div>
                </div>
                <script type="application/json" id="cohort-entry-year-map">@json($cohortEntryYears ?? [])</script>
            </div>
        </section>

        @if($canRecordExistingStudentFees ?? false)
            <section class="card border-0 shadow-sm mb-4" aria-labelledby="finance-setup-heading">
                <div class="card-body p-3 p-md-4">
                    <div class="mb-3">
                        <h4 class="h5 mb-1" id="finance-setup-heading">4. Optional financial setup</h4>
                        <p class="small text-muted mb-0">Set up a fee invoice now, or leave this section unchecked to add the student without financial records.</p>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="record_fees" name="record_fees" value="1" @checked(old('record_fees'))>
                        <label class="form-check-label fw-semibold" for="record_fees">Set up this student's fee invoice now</label>
                    </div>
                    @error('record_fees')<div class="alert alert-danger py-2" role="alert">{{ $message }}</div>@enderror
                    <div id="existing_student_fee_fields" @if(!old('record_fees')) hidden @endif>
                        <div class="alert alert-light border" role="note">The agreed fee stays fully payable. Prior payments, awards, and waiver requests are stored for review and do not affect the balance or eligibility until an authorized reviewer confirms them.</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fee_session_id" class="form-label">Fee session <span class="text-danger" aria-hidden="true">*</span></label>
                                <select id="fee_session_id" name="fee_session_id" class="form-select @error('fee_session_id') is-invalid @enderror" @if(!old('record_fees')) disabled @endif @if(old('record_fees')) required @endif aria-describedby="fee-session-help fee_session_id_error">
                                    <option value="">Select a session in the academic year above</option>
                                    @foreach($sessions as $session)
                                        <option value="{{ $session->id }}" data-academic-year-id="{{ $session->academic_year_id }}" @selected((string) old('fee_session_id', $defaultFeeSessionId) === (string) $session->id)>{{ $session->session_title }}</option>
                                    @endforeach
                                </select>
                                @error('fee_session_id')<div class="invalid-feedback" id="fee_session_id_error">{{ $message }}</div>@enderror
                                <div class="form-text text-warning" id="fee-session-context-message" role="status" aria-live="polite" hidden></div>
                                <div class="form-text" id="fee-session-help">Only legacy Finance sessions linked to the selected academic year are available. Academic semesters are separate records; invoices store a Finance session ID.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="fee_structure_id" class="form-label">Fee type <span class="text-danger" aria-hidden="true">*</span></label>
                                <select id="fee_structure_id" name="fee_structure_id" class="form-select @error('fee_structure_id') is-invalid @enderror" @if(!old('record_fees')) disabled @endif @if(old('record_fees')) required @endif>
                                    <option value="">Select a mandatory fee type</option>
                                    @foreach($feeStructures as $structure)
                                        <option value="{{ $structure->id }}" data-programme-id="{{ $structure->programme_id ?? '' }}" data-session-id="{{ $structure->session_id ?? '' }}" @selected((string) old('fee_structure_id') === (string) $structure->id)>{{ $structure->name }} (standard {{ school_currency($structure->amount) }})</option>
                                    @endforeach
                                </select>
                                @error('fee_structure_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text text-warning" id="fee-structure-context-message" role="status" aria-live="polite" hidden></div>
                                @if($feeStructures->isEmpty())<div class="form-text text-danger">No mandatory institution or programme fee structures are configured. Finance must configure the correct fee before using this option.</div>@endif
                            </div>
                            <div class="col-md-6">
                                <label for="actual_fee_amount" class="form-label">Student's agreed fee for this session ({{ school_currency() }}) <span class="text-danger" aria-hidden="true">*</span></label>
                                <input id="actual_fee_amount" name="actual_fee_amount" type="number" min="0.01" step="0.01" class="form-control @error('actual_fee_amount') is-invalid @enderror" value="{{ old('actual_fee_amount') }}" @if(!old('record_fees')) disabled @endif @if(old('record_fees')) required @endif>
                                @error('actual_fee_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6">
                                <label for="prior_paid_amount" class="form-label">Prior payment to verify ({{ school_currency() }}) <span class="text-muted small">Optional</span></label>
                                <input id="prior_paid_amount" name="prior_paid_amount" type="number" min="0" step="0.01" class="form-control @error('prior_paid_amount') is-invalid @enderror" value="{{ old('prior_paid_amount', '0') }}" @if(!old('record_fees')) disabled @endif>
                                @error('prior_paid_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-12 row g-3 mx-0 px-0" id="prior_payment_details" @if(!old('record_fees') || (float) old('prior_paid_amount', 0) <= 0) hidden @endif>
                                <div class="col-md-6">
                                    <label for="prior_paid_on" class="form-label">Original payment date</label>
                                    <input id="prior_paid_on" name="prior_paid_on" type="date" max="{{ date('Y-m-d') }}" class="form-control @error('prior_paid_on') is-invalid @enderror" value="{{ old('prior_paid_on') }}" @if(!old('record_fees') || (float) old('prior_paid_amount', 0) <= 0) disabled @else required @endif>
                                    @error('prior_paid_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="prior_payment_method" class="form-label">Original payment method</label>
                                    <select id="prior_payment_method" name="prior_payment_method" class="form-select @error('prior_payment_method') is-invalid @enderror" @if(!old('record_fees') || (float) old('prior_paid_amount', 0) <= 0) disabled @else required @endif>
                                        <option value="">Select method</option>
                                        @foreach(['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'mobile_money' => 'Mobile money', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'] as $value => $label)
                                            <option value="{{ $value }}" @selected(old('prior_payment_method') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('prior_payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="prior_payment_reference" class="form-label">Receipt or reference</label>
                                    <input id="prior_payment_reference" name="prior_payment_reference" maxlength="150" class="form-control @error('prior_payment_reference') is-invalid @enderror" value="{{ old('prior_payment_reference') }}" @if(!old('record_fees') || (float) old('prior_paid_amount', 0) <= 0) disabled @else required @endif>
                                    @error('prior_payment_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="prior_payment_reason" class="form-label">Verification note</label>
                                    <textarea id="prior_payment_reason" name="prior_payment_reason" minlength="10" maxlength="500" class="form-control @error('prior_payment_reason') is-invalid @enderror" @if(!old('record_fees') || (float) old('prior_paid_amount', 0) <= 0) disabled @else required @endif>{{ old('prior_payment_reason') }}</textarea>
                                    @error('prior_payment_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="col-12 border-top pt-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="record_award" name="record_award" value="1" @checked(old('record_award')) @if(!old('record_fees')) disabled @endif>
                                    <label class="form-check-label" for="record_award">Capture an approved scholarship or bursary for review</label>
                                </div>
                                <p class="small text-muted mt-1">An award is fee coverage, never cash received. It changes the invoice only after Finance confirms it.</p>
                                @error('record_award')<div class="text-danger small">{{ $message }}</div>@enderror
                                <div id="award_details" class="row g-3 mt-1" @if(!old('record_fees') || !old('record_award')) hidden @endif>
                                    <div class="col-md-4">
                                        <label for="award_type" class="form-label">Award type</label>
                                        <select id="award_type" name="award_type" class="form-select @error('award_type') is-invalid @enderror" @if(!old('record_fees') || !old('record_award')) disabled @else required @endif>
                                            <option value="">Select type</option><option value="scholarship" @selected(old('award_type') === 'scholarship')>Scholarship</option><option value="bursary" @selected(old('award_type') === 'bursary')>Bursary</option>
                                        </select>
                                        @error('award_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-4">
                                        <label for="award_amount" class="form-label">Amount covered ({{ school_currency() }})</label>
                                        <input id="award_amount" name="award_amount" type="number" min="0.01" step="0.01" class="form-control @error('award_amount') is-invalid @enderror" value="{{ old('award_amount') }}" @if(!old('record_fees') || !old('record_award')) disabled @else required @endif>
                                        @error('award_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-md-4">
                                        <label for="award_reference" class="form-label">Approval reference</label>
                                        <input id="award_reference" name="award_reference" maxlength="150" class="form-control @error('award_reference') is-invalid @enderror" value="{{ old('award_reference') }}" @if(!old('record_fees') || !old('record_award')) disabled @else required @endif>
                                        @error('award_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-12">
                                        <label for="award_reason" class="form-label">Approval reason</label>
                                        <textarea id="award_reason" name="award_reason" minlength="10" maxlength="500" class="form-control @error('award_reason') is-invalid @enderror" @if(!old('record_fees') || !old('record_award')) disabled @else required @endif>{{ old('award_reason') }}</textarea>
                                        @error('award_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>
                            @if($canRequestStudyWaiver ?? false)
                                <div class="col-12 border-top pt-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="record_study_waiver" name="record_study_waiver" value="1" @checked(old('record_study_waiver')) @if(!old('record_fees')) disabled @endif>
                                        <label class="form-check-label" for="record_study_waiver">Request the 20% study-requirement waiver for review</label>
                                    </div>
                                    <p class="small text-muted mt-1">For the institution's current fee session only. A waiver request does not change the fee or academic requirements.</p>
                                    @error('record_study_waiver')<div class="text-danger small">{{ $message }}</div>@enderror
                                    <div id="study_waiver_details" class="mt-2" @if(!old('record_fees') || !old('record_study_waiver')) hidden @endif>
                                        <label for="study_waiver_reason" class="form-label">Required reason</label>
                                        <textarea id="study_waiver_reason" name="study_waiver_reason" minlength="10" maxlength="500" class="form-control @error('study_waiver_reason') is-invalid @enderror" @if(!old('record_fees') || !old('record_study_waiver')) disabled @else required @endif>{{ old('study_waiver_reason') }}</textarea>
                                        @error('study_waiver_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
        @endif

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="other-details-heading">
            <div class="card-body p-3 p-md-4">
                <div class="mb-3">
                    <h4 class="h5 mb-1" id="other-details-heading">5. Next of kin and other details</h4>
                    <p class="small text-muted mb-0">Add the remaining profile information. Fields marked optional can be completed later.</p>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="next_of_kin_name" class="form-label">Next of Kin Name <span class="text-muted small">Optional</span></label>
                        <input type="text" class="form-control @error('next_of_kin_name') is-invalid @enderror" id="next_of_kin_name" name="next_of_kin_name" maxlength="150" value="{{ old('next_of_kin_name') }}">
                        @error('next_of_kin_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="next_of_kin_contact" class="form-label">Next of Kin Contact <span class="text-muted small">Optional</span></label>
                        <input type="text" class="form-control @error('next_of_kin_contact') is-invalid @enderror" id="next_of_kin_contact" name="next_of_kin_contact" maxlength="30" value="{{ old('next_of_kin_contact') }}">
                        @error('next_of_kin_contact')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label for="next_of_kin_address" class="form-label">Next of Kin Address <span class="text-muted small">Optional</span></label>
                        <textarea class="form-control @error('next_of_kin_address') is-invalid @enderror" id="next_of_kin_address" name="next_of_kin_address" rows="2">{{ old('next_of_kin_address') }}</textarea>
                        @error('next_of_kin_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="nationality" class="form-label">Nationality <span class="text-muted small">Optional</span></label>
                        <input type="text" class="form-control @error('nationality') is-invalid @enderror" id="nationality" name="nationality" maxlength="80" value="{{ old('nationality') }}">
                        @error('nationality')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="national_id_or_passport" class="form-label">National ID / Passport Number <span class="text-muted small">Optional</span></label>
                        <input type="text" class="form-control @error('national_id_or_passport') is-invalid @enderror" id="national_id_or_passport" name="national_id_or_passport" maxlength="50" value="{{ old('national_id_or_passport') }}">
                        @error('national_id_or_passport')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="status" class="form-label">Student status</label>
                        <select name="status" id="status" class="form-select">
                            @foreach(['active' => 'Active', 'suspended' => 'Suspended', 'graduated' => 'Graduated', 'withdrawn' => 'Withdrawn', 'deferred' => 'Deferred'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('status', 'active') === $value)>{{ get_phrase($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="birthday" class="form-label">Birthday <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control @error('birthday') is-invalid @enderror" id="birthday" name="birthday" value="{{ old('birthday', date('m/d/Y')) }}" required autocomplete="bday">
                        @error('birthday')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="gender" class="form-label">Gender <span class="text-danger" aria-hidden="true">*</span></label>
                        <select name="gender" id="gender" class="form-select @error('gender') is-invalid @enderror" required>
                            <option value="">{{ get_phrase('Select gender') }}</option>
                            @foreach(['Male', 'Female', 'Others'] as $gender)
                                <option value="{{ $gender }}" @selected(old('gender') === $gender)>{{ get_phrase($gender) }}</option>
                            @endforeach
                        </select>
                        @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="phone" class="form-label">Phone number <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone') }}" required autocomplete="tel">
                        @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label for="address" class="form-label">Address <span class="text-danger" aria-hidden="true">*</span></label>
                        <textarea class="form-control @error('address') is-invalid @enderror" id="address" name="address" rows="3" required autocomplete="street-address">{{ old('address') }}</textarea>
                        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="photo" class="form-label">Photo <span class="text-muted small">Optional</span></label>
                        <input class="form-control @error('photo') is-invalid @enderror" id="photo" name="photo" accept="image/*" type="file">
                        @error('photo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="additional_photo" class="form-label">Additional image <span class="text-muted small">Optional</span></label>
                        <input class="form-control @error('additional_photo') is-invalid @enderror" id="additional_photo" name="additional_photo" accept="image/*" type="file">
                        @error('additional_photo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </section>

        <div class="d-flex flex-column flex-sm-row justify-content-end align-items-sm-center gap-3 py-2">
            <span class="small text-muted me-sm-auto"><span class="text-danger" aria-hidden="true">*</span> Required fields</span>
            <button class="btn btn-primary px-4" type="submit">Create Student</button>
        </div>
    </form>
</div>

<script src="{{ asset('assets/js/existing-student-form.js') }}?v=20261024-1" defer></script>
