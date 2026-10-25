<div class="existing-student-form eoff-form">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <p class="text-uppercase small fw-semibold text-primary mb-1">Student records</p>
            <h3 class="h4 mb-2">Add Existing Student</h3>
            <p class="text-muted mb-0">Use the student's existing PIIE registration details to create portal access.</p>
        </div>
        <a href="{{ route('admin.hei_admissions.wizard.create') }}" class="btn btn-outline-primary flex-shrink-0">Add a new application</a>
    </div>

    <p class="small text-muted mb-3">Documents are uploaded after creation. This form does not create an application or academic placement.</p>

    <form method="POST" enctype="multipart/form-data" action="{{ route('admin.student.create') }}" id="existing-student-form" data-workspace-continuous>
        @csrf

        <section class="card border-0 shadow-sm mb-4" aria-labelledby="existing-registration-heading">
            <div class="card-body p-3 p-md-4">
                <div class="mb-3">
                    <h4 class="h5 mb-1" id="existing-registration-heading">Existing registration and institution</h4>
                    <p class="small text-muted mb-0">Use the current PIIE number; add partner institution details if applicable.</p>
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
                    <h4 class="h5 mb-1" id="student-account-heading">Student account and portal access</h4>
                    <p class="small text-muted mb-0">A password setup email is sent when mail delivery is available.</p>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="first_name" class="form-label">First name <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control @error('first_name') is-invalid @enderror" id="first_name" name="first_name" value="{{ old('first_name') }}" maxlength="100" required autocomplete="given-name">
                        @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label for="last_name" class="form-label">Last name <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="text" class="form-control @error('last_name') is-invalid @enderror" id="last_name" name="last_name" value="{{ old('last_name') }}" maxlength="100" required autocomplete="family-name">
                        @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                    <h4 class="h5 mb-1" id="academic-details-heading">Programme, intake, and academic details</h4>
                    <p class="small text-muted mb-0">Profile details only; use Student Placement to assign an approved curriculum.</p>
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
                        <div class="form-text">Progression stage; Finance uses the Academic Year below.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="fee_academic_year_id" class="form-label">Academic Year (invoice context) <span class="text-muted small">Required for Finance setup</span></label>
                        <select id="fee_academic_year_id" name="fee_academic_year_id" class="form-select @error('fee_academic_year_id') is-invalid @enderror" aria-describedby="fee-academic-year-help fee_academic_year_id_error">
                            <option value="">Select an academic year</option>
                            @foreach($academicYears as $academicYear)
                                <option value="{{ $academicYear->id }}" @selected((string) old('fee_academic_year_id', $defaultFeeAcademicYearId) === (string) $academicYear->id)>{{ $academicYear->label }}</option>
                            @endforeach
                        </select>
                        @error('fee_academic_year_id')<div class="invalid-feedback" id="fee_academic_year_id_error">{{ $message }}</div>@enderror
                        <div class="form-text" id="fee-academic-year-help">Sets the invoice’s academic context. The programme Fee Structure, not the year, determines the amount.</div>
                        @unless($hasConfiguredActiveAcademicYear ?? false)
                            <div class="small text-warning mt-1" id="active-year-message" role="status">No active Academic Year is configured. Select a year before setting up Finance.</div>
                        @endunless
                    </div>
                    <div class="col-12">
                        <div class="small text-muted" id="cohort-context" role="status" aria-live="polite">
                            Programme and intake are profile details; assign an approved curriculum later in Student Placement.
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
                        <h4 class="h5 mb-1" id="finance-setup-heading">Optional financial setup</h4>
                        <p class="small text-muted mb-0">Leave unchecked to add the student without an invoice.</p>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="record_fees" name="record_fees" value="1" aria-controls="existing_student_fee_fields" aria-expanded="{{ old('record_fees') ? 'true' : 'false' }}" @checked(old('record_fees'))>
                        <label class="form-check-label fw-semibold" for="record_fees">Set up this student's fee invoice now</label>
                    </div>
                    @error('record_fees')<div class="text-danger small mt-2" role="alert">{{ $message }}</div>@enderror
                    <p class="small text-muted mt-2 mb-0">Prior payments, awards, and waivers remain pending until Finance review.</p>
                    <div id="existing_student_fee_fields" @if(!old('record_fees')) hidden @endif>
                        <div class="alert alert-light border" role="note">The selected programme Fee Structure sets the fee type, amount, and billing basis. Academic Year and Finance Session supply invoice context. Unverified prior-payment claims, awards, and waivers do not reduce the balance or change eligibility.</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="fee_session_id" class="form-label">Finance Session / billing period <span class="text-danger" aria-hidden="true">*</span></label>
                                <select id="fee_session_id" name="fee_session_id" class="form-select @error('fee_session_id') is-invalid @enderror" @if(!old('record_fees')) disabled @endif @if(old('record_fees')) required @endif aria-describedby="fee-session-help fee_session_id_error">
                                    <option value="">Select a Finance Session linked to the Academic Year above</option>
                                    @foreach($sessions as $session)
                                        <option value="{{ $session->id }}" data-academic-year-id="{{ $session->academic_year_id }}" @selected((string) old('fee_session_id', $defaultFeeSessionId) === (string) $session->id)>{{ $session->session_title }}</option>
                                    @endforeach
                                </select>
                                @error('fee_session_id')<div class="invalid-feedback" id="fee_session_id_error">{{ $message }}</div>@enderror
                                <div class="form-text text-warning" id="fee-session-context-message" role="status" aria-live="polite" hidden></div>
                                <div class="form-text" id="fee-session-help">Invoices require this legacy Finance Session ID for accounting reports, balances, payment flows, and study-eligibility checks. It is separate from the Academic Semester record. If no session is listed, <a href="{{ route('admin.settings.session_manager') }}" target="_blank" rel="noopener">create or edit one in Session Manager</a> and link it to this Academic Year.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="fee_structure_id" class="form-label">Programme Fee Structure <span class="text-danger" aria-hidden="true">*</span></label>
                                <select id="fee_structure_id" name="fee_structure_id" class="form-select @error('fee_structure_id') is-invalid @enderror" @if(!old('record_fees')) disabled @endif @if(old('record_fees')) required @endif>
                                    <option value="">Select a mandatory fee for the selected programme</option>
                                    @foreach($feeStructures as $structure)
                                        <option value="{{ $structure->id }}" data-name="{{ $structure->name }}" data-fee-type="{{ $structure->fee_type }}" data-programme-id="{{ $structure->programme_id ?? '' }}" data-session-id="{{ $structure->session_id ?? '' }}" data-session-academic-year-id="{{ optional($sessions->firstWhere('id', $structure->session_id))->academic_year_id ?? '' }}" data-amount="{{ number_format((float) $structure->amount, 2, '.', '') }}" data-per-semester="{{ (int) ($structure->per_semester ?? 0) }}" @selected((string) old('fee_structure_id') === (string) $structure->id)>{{ $structure->name }} · {{ ucfirst($structure->fee_type) }} · {{ school_currency($structure->amount) }} · {{ $structure->per_semester ? 'Per semester' : 'Not per semester' }}</option>
                                    @endforeach
                                </select>
                                @error('fee_structure_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text text-warning" id="fee-structure-context-message" role="status" aria-live="polite" hidden></div>
                                @if($feeStructures->isEmpty())<div class="form-text text-danger">No mandatory institution or programme fee structures are configured. Finance must configure the correct fee before using this option.</div>@endif
                            </div>
                            <div class="col-md-6">
                                <label for="selected-fee-amount" class="form-label">Configured Fee Structure amount ({{ school_currency() }})</label>
                                <output id="selected-fee-amount" class="form-control bg-light" aria-live="polite" data-currency="{{ school_currency() }}">Select a Fee type to view its amount</output>
                                <div class="form-text" id="fee-billing-basis">The configured amount is used as listed; it is never calculated from the Academic Year.</div>
                                <output id="invoice-period-summary" class="form-text d-block" aria-live="polite">Select an Academic Year and Finance Session to confirm the invoice period.</output>
                                <div class="form-text">For a per-semester fee, the configured amount applies to this one selected Finance Session. The invoice stores the Finance Session ID; use a session title that identifies the billed semester. No semester multiplier is applied.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="received_payment_amount" class="form-label">Confirmed payment received now ({{ school_currency() }}) <span class="text-muted small">Optional</span></label>
                                <input id="received_payment_amount" name="received_payment_amount" type="number" min="0" step="0.01" class="form-control @error('received_payment_amount') is-invalid @enderror" value="{{ old('received_payment_amount', '0') }}" @if(!old('record_fees')) disabled @endif>
                                @error('received_payment_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <div class="form-text text-danger" id="received-payment-context-message" role="status" aria-live="polite" hidden></div>
                                <div class="form-text">Enter money actually received and verified by an authorized Finance user.</div>
                            </div>
                            <div class="col-12 row g-3 mx-0 px-0" id="received_payment_details" @if(!old('record_fees') || (float) old('received_payment_amount', 0) <= 0) hidden @endif>
                                <div class="col-md-6">
                                    <label for="received_payment_on" class="form-label">Date received</label>
                                    <input id="received_payment_on" name="received_payment_on" type="date" max="{{ date('Y-m-d') }}" class="form-control @error('received_payment_on') is-invalid @enderror" value="{{ old('received_payment_on', date('Y-m-d')) }}" @if(!old('record_fees') || (float) old('received_payment_amount', 0) <= 0) disabled @else required @endif>
                                    @error('received_payment_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="received_payment_method" class="form-label">Payment method</label>
                                    <select id="received_payment_method" name="received_payment_method" class="form-select @error('received_payment_method') is-invalid @enderror" @if(!old('record_fees') || (float) old('received_payment_amount', 0) <= 0) disabled @else required @endif>
                                        <option value="">Select method</option>
                                        @foreach(['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'mobile_money' => 'Mobile money', 'card' => 'Card', 'cheque' => 'Cheque', 'other' => 'Other'] as $value => $label)
                                            <option value="{{ $value }}" @selected(old('received_payment_method') === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('received_payment_method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="received_payment_reference" class="form-label">Receipt or transaction reference</label>
                                    <input id="received_payment_reference" name="received_payment_reference" maxlength="150" class="form-control @error('received_payment_reference') is-invalid @enderror" value="{{ old('received_payment_reference') }}" @if(!old('record_fees') || (float) old('received_payment_amount', 0) <= 0) disabled @else required @endif>
                                    @error('received_payment_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label for="received_payment_reason" class="form-label">Verification note</label>
                                    <textarea id="received_payment_reason" name="received_payment_reason" minlength="10" maxlength="500" class="form-control @error('received_payment_reason') is-invalid @enderror" @if(!old('record_fees') || (float) old('received_payment_amount', 0) <= 0) disabled @else required @endif>{{ old('received_payment_reason') }}</textarea>
                                    @error('received_payment_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="remaining-fee-balance" class="form-label">Remaining balance preview ({{ school_currency() }})</label>
                                <output id="remaining-fee-balance" class="form-control bg-light" aria-live="polite">Select a Fee type to calculate</output>
                                <div class="form-text">The invoice and payment ledger are saved together; this preview alone does not post a payment.</div>
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
                    <h4 class="h5 mb-1" id="other-details-heading">Next of kin and other student details</h4>
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
