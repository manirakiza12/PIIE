@extends('admin.navigation')

{{-- The Create Staff form owns its presentation: staff-form.css is linked here
     rather than inlined, and the shared Print/PDF/Excel toolbar is switched off
     because a blank creation form has nothing to export. --}}
@section('page_styles')
    <link rel="stylesheet" href="{{ asset('assets/css/staff-form.css') }}?v=20260920-1">
@endsection
@section('hide_page_toolbar')
@endsection

@section('content')
@php
    $label = $config['label'];

    // The subtitle is one translatable sentence with a single placeholder, so
    // it reads "Add the lecturer's personal, employment and professional
    // information." for every staff type.
    $subtitle = str_replace(':role', strtolower($label), get_phrase("Add the :role's personal, employment and professional information."));

    // Same approach for the document hint, whose accepted types and size limit
    // depend on the private store's configuration.
    $documentHint = str_replace(
        [':types', ':size'],
        [$acceptedMime, round($maxKb / 1024, 1).' MB'],
        get_phrase('Files are stored privately and are never published at a public URL. Accepted: :types, up to :size each.')
    );

    // Reveal the "Other" description only for the "Other" option, rendered that
    // way it is also correct without JavaScript.
    $showOtherDetail = old('emergency_contact_relationship') === \App\Support\Staff\StaffNextOfKin::OTHER;
    $showOtherTitle = old('title') === \App\Support\Staff\StaffTitle::OTHER;
    $err = fn ($f) => $errors->first($f);

    // One reusable document row up front; "Add another document" reveals more,
    // so the page is never three native file inputs at once.
    $oldDocs = old('documents', []);
    $initialRows = max(1, min((int) $maxDocuments, count($oldDocs) ?: 1));
@endphp

<div class="staff-form">

    {{-- ================================================== PAGE HEADING --}}
    <div class="staff-form__head">
        <h1 class="staff-form__title">{{ get_phrase('Create ' . $label) }}</h1>
        <p class="staff-form__sub">{{ $subtitle }}</p>
        <ul class="d-flex align-items-center eBreadcrumb-2 staff-form__crumb">
            <li><a href="{{ route('admin.staff.add') }}">{{ get_phrase('Staff') }}</a></li>
            <li><a href="{{ route('admin.staff.add') }}">{{ get_phrase('Add Staff') }}</a></li>
            <li><span>{{ get_phrase('Create ' . $label) }}</span></li>
        </ul>
    </div>

    @if(session('message'))<div class="alert alert-success sc-alert" role="status">{{ session('message') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger sc-alert" role="alert">{{ session('error') }}</div>@endif
    @if($errors->any())
        <div class="alert alert-danger sc-alert" role="alert">
            <strong>{{ get_phrase('Please correct the following') }}</strong>
            <ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.staff.create.store', $type) }}" enctype="multipart/form-data" id="staff-create-form">
        @csrf
        <input type="hidden" name="role_key" value="{{ $type }}">

        {{-- ============================================= PERSONAL INFORMATION --}}
        <section class="sc-card" aria-labelledby="sec-personal">
            <div class="sc-card-head">
                <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
                <div class="sc-card-headings">
                    <h2 class="sc-card-title" id="sec-personal">{{ get_phrase('Personal Information') }}</h2>
                    <p class="sc-card-sub">{{ get_phrase('Who this person is. The profile photo is optional and can be added later.') }}</p>
                </div>
            </div>
            <div class="sc-card-body">

                {{-- Profile photo: a styled upload area with a live preview. The
                     real file input is still posted under the same name, so the
                     existing storage behaviour is untouched. --}}
                <div class="sc-identity">
                    <div class="sc-identity-preview">
                        <img class="sc-photo-img" id="sc-photo-preview" alt="">
                        <span class="sc-photo-placeholder" id="sc-photo-placeholder" aria-hidden="true"><i class="bi bi-person"></i></span>
                    </div>
                    <div class="sc-identity-main">
                        <p class="sc-identity-title">{{ get_phrase('Profile Photo') }}</p>
                        <span class="sc-hint">{{ get_phrase('A clear, square JPG or PNG of up to 4 MB.') }}</span>
                        <div class="sc-identity-actions">
                            <input type="file" id="photo" name="photo" class="sc-file-input @error('photo') is-invalid @enderror"
                                   accept="image/jpeg,image/png,.jpg,.jpeg,.png"
                                   data-preview="sc-photo-preview"
                                   data-placeholder="sc-photo-placeholder"
                                   data-filename="sc-photo-name">
                            <label class="sc-file-btn" for="photo"><i class="bi bi-upload" aria-hidden="true"></i> {{ get_phrase('Choose photo') }}</label>
                            <span class="sc-file-name" id="sc-photo-name">{{ get_phrase('No file selected') }}</span>
                        </div>
                        @error('photo')<span class="invalid-feedback">{{ $err('photo') }}</span>@enderror
                    </div>
                </div>

                <div class="row sc-grid">
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="first_name">{{ get_phrase('First Name') }}<span class="req" aria-hidden="true">*</span></label>
                        <input id="first_name" name="first_name" class="form-control @error('first_name') is-invalid @enderror" value="{{ old('first_name') }}" autocomplete="given-name" required>
                        @error('first_name')<span class="invalid-feedback">{{ $err('first_name') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="middle_name">{{ get_phrase('Middle Name') }}</label>
                        <input id="middle_name" name="middle_name" class="form-control" value="{{ old('middle_name') }}" autocomplete="additional-name">
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="last_name">{{ get_phrase('Last Name') }}<span class="req" aria-hidden="true">*</span></label>
                        <input id="last_name" name="last_name" class="form-control @error('last_name') is-invalid @enderror" value="{{ old('last_name') }}" autocomplete="family-name" required>
                        @error('last_name')<span class="invalid-feedback">{{ $err('last_name') }}</span>@enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="title">{{ get_phrase('Title') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="title" name="title" class="form-select @error('title') is-invalid @enderror" required>
                            <option value="">{{ get_phrase('Select a title') }}</option>
                            @foreach($titles as $t)<option value="{{ $t }}" @selected(old('title') === $t)>{{ get_phrase($t) }}</option>@endforeach
                        </select>
                        <span class="sc-hint">{{ get_phrase('A personal title. Roles like Head of Department belong under Designation.') }}</span>
                        @error('title')<span class="invalid-feedback">{{ $err('title') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field @unless($showOtherTitle) d-none @endunless" id="title-other-wrap">
                        <label class="sc-label" for="title_other">{{ get_phrase('Title') }}<span class="req" aria-hidden="true">*</span></label>
                        <input id="title_other" name="title_other" class="form-control @error('title_other') is-invalid @enderror" value="{{ old('title_other') }}">
                        @error('title_other')<span class="invalid-feedback">{{ $err('title_other') }}</span>@enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="gender">{{ get_phrase('Gender') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="gender" name="gender" class="form-select @error('gender') is-invalid @enderror" required>
                            <option value="">{{ get_phrase('Select gender') }}</option>
                            @foreach($genders as $g)<option value="{{ $g }}" @selected(old('gender') === $g)>{{ get_phrase($g) }}</option>@endforeach
                        </select>
                        @error('gender')<span class="invalid-feedback">{{ $err('gender') }}</span>@enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="birthday">{{ get_phrase('Date of Birth') }}</label>
                        {{-- Intentionally blank: a new staff member's date of birth is never today. --}}
                        <input type="date" id="birthday" name="birthday" class="form-control @error('birthday') is-invalid @enderror" value="{{ old('birthday') }}">
                        @error('birthday')<span class="invalid-feedback">{{ $err('birthday') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="nationality">{{ get_phrase('Nationality') }}</label>
                        <input id="nationality" name="nationality" class="form-control" value="{{ old('nationality') }}">
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="blood_group">{{ get_phrase('Blood Group') }}</label>
                        <select id="blood_group" name="blood_group" class="form-select">
                            <option value="">{{ get_phrase('Select a blood group') }}</option>
                            @foreach($bloodGroups as $b)<option value="{{ $b }}" @selected(old('blood_group') === $b)>{{ get_phrase($b) }}</option>@endforeach
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        {{-- Presentation only: the encrypted NIN architecture is unchanged. --}}
                        <label class="sc-label" for="nin">{{ get_phrase('National ID / Passport No.') }}</label>
                        <input id="nin" name="nin" class="form-control @error('nin') is-invalid @enderror" value="{{ old('nin') }}">
                        <span class="sc-hint">{{ get_phrase('Your national ID or passport number. Stored encrypted and never shown in full.') }}</span>
                        @error('nin')<span class="invalid-feedback">{{ $err('nin') }}</span>@enderror
                    </div>

                    <div class="col-12 sc-field">
                        <label class="sc-label" for="address">{{ get_phrase('Address') }}</label>
                        <textarea id="address" name="address" class="form-control" rows="2">{{ old('address') }}</textarea>
                    </div>

                    <div class="col-12 sc-field">
                        <p class="sc-note">{{ get_phrase('Enter whichever identity document number the institution holds for this person.') }}</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============================================ CONTACT INFORMATION --}}
        <section class="sc-card" aria-labelledby="sec-contact">
            <div class="sc-card-head">
                <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-telephone"></i></span>
                <div class="sc-card-headings">
                    <h2 class="sc-card-title" id="sec-contact">{{ get_phrase('Contact Information') }}</h2>
                    <p class="sc-card-sub">{{ get_phrase('The email address is also the staff member’s portal login.') }}</p>
                </div>
            </div>
            <div class="sc-card-body">
                <div class="row sc-grid">
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="email">{{ get_phrase('Email Address') }}<span class="req" aria-hidden="true">*</span></label>
                        <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email') }}" autocomplete="email" required>
                        @error('email')<span class="invalid-feedback">{{ $err('email') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="phone">{{ get_phrase('Primary Phone Number') }}<span class="req" aria-hidden="true">*</span></label>
                        <input type="tel" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone') }}" placeholder="+256 712 345 678" autocomplete="tel" required>
                        <span class="sc-hint">{{ get_phrase('International numbers are accepted.') }}</span>
                        @error('phone')<span class="invalid-feedback">{{ $err('phone') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="alternative_phone">{{ get_phrase('Alternative Phone Number') }}</label>
                        <input type="tel" id="alternative_phone" name="alternative_phone" class="form-control @error('alternative_phone') is-invalid @enderror" value="{{ old('alternative_phone') }}">
                        @error('alternative_phone')<span class="invalid-feedback">{{ $err('alternative_phone') }}</span>@enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- ========================================= EMPLOYMENT INFORMATION --}}
        <section class="sc-card" aria-labelledby="sec-employment">
            <div class="sc-card-head">
                <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-briefcase"></i></span>
                <div class="sc-card-headings">
                    <h2 class="sc-card-title" id="sec-employment">{{ get_phrase('Employment Information') }}</h2>
                    <p class="sc-card-sub">{{ get_phrase('Where this person works and what they do.') }}</p>
                </div>
            </div>
            <div class="sc-card-body">
                <div class="row sc-grid">
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="department_id">{{ get_phrase('Department') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="department_id" name="department_id" class="form-select @error('department_id') is-invalid @enderror" required>
                            <option value="">{{ get_phrase('Select a department') }}</option>
                            @foreach($departments as $d)<option value="{{ $d->id }}" @selected(old('department_id') == $d->id)>{{ $d->name }}</option>@endforeach
                        </select>
                        @error('department_id')<span class="invalid-feedback">{{ $err('department_id') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="designation_id">{{ get_phrase('Designation') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="designation_id" name="designation_id" class="form-select @error('designation_id') is-invalid @enderror" required>
                            <option value="">{{ get_phrase('Select a designation') }}</option>
                            @foreach($designations as $d)<option value="{{ $d->id }}" @selected(old('designation_id') == $d->id)>{{ $d->name }}</option>@endforeach
                        </select>
                        <span class="sc-hint">{{ get_phrase('The job title, e.g. Lecturer or Head of Department.') }}</span>
                        @error('designation_id')<span class="invalid-feedback">{{ $err('designation_id') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="employment_type">{{ get_phrase('Employment Type') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="employment_type" name="employment_type" class="form-select @error('employment_type') is-invalid @enderror" required>
                            @foreach($employmentTypes as $e)<option value="{{ $e }}" @selected(old('employment_type', 'Full Time') === $e)>{{ get_phrase($e) }}</option>@endforeach
                        </select>
                        @error('employment_type')<span class="invalid-feedback">{{ $err('employment_type') }}</span>@enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="staff_status">{{ get_phrase('Employment Status') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="staff_status" name="staff_status" class="form-select @error('staff_status') is-invalid @enderror" required>
                            @foreach($staffStatuses as $s)<option value="{{ $s }}" @selected(old('staff_status', 'active') === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>@endforeach
                        </select>
                        @error('staff_status')<span class="invalid-feedback">{{ $err('staff_status') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="date_joined">{{ get_phrase('Joining Date') }}</label>
                        <input type="date" id="date_joined" name="date_joined" class="form-control" value="{{ old('date_joined') }}">
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="staff_number">{{ get_phrase('Staff Number') }}</label>
                        <input id="staff_number" class="form-control" value="{{ get_phrase('Generated automatically on save') }}" disabled>
                    </div>
                </div>
            </div>
        </section>

        {{-- ========================= ACADEMIC & PROFESSIONAL INFORMATION --}}
        @if($config['academic'])
            <section class="sc-card" aria-labelledby="sec-academic">
                <div class="sc-card-head">
                    <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-mortarboard"></i></span>
                    <div class="sc-card-headings">
                        <h2 class="sc-card-title" id="sec-academic">{{ get_phrase('Academic & Professional Information') }}</h2>
                        <p class="sc-card-sub">{{ get_phrase('The highest qualification held. Further qualifications and experience can be added from the staff profile.') }}</p>
                    </div>
                </div>
                <div class="sc-card-body">
                    <div class="row sc-grid">
                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="qualification_level">{{ get_phrase('Highest Qualification') }}<span class="req" aria-hidden="true">*</span></label>
                            <select id="qualification_level" name="qualification_level" class="form-select @error('qualification_level') is-invalid @enderror" required>
                                <option value="">{{ get_phrase('Select the highest qualification') }}</option>
                                @foreach($qualificationLevels as $q)<option value="{{ $q }}" @selected(old('qualification_level') === $q)>{{ get_phrase($q) }}</option>@endforeach
                            </select>
                            @error('qualification_level')<span class="invalid-feedback">{{ $err('qualification_level') }}</span>@enderror
                        </div>
                        <div class="col-12 col-md-6 col-xl-4 sc-field @unless(old('qualification_level') === \App\Support\Staff\StaffQualificationLevel::OTHER) d-none @endunless" id="qual-level-other-wrap">
                            <label class="sc-label" for="qualification_level_other">{{ get_phrase('Qualification') }}<span class="req" aria-hidden="true">*</span></label>
                            <input id="qualification_level_other" name="qualification_level_other" class="form-control @error('qualification_level_other') is-invalid @enderror" value="{{ old('qualification_level_other') }}">
                            @error('qualification_level_other')<span class="invalid-feedback">{{ $err('qualification_level_other') }}</span>@enderror
                        </div>
                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="field_of_study">{{ get_phrase('Field of Study / Specialisation') }}<span class="req" aria-hidden="true">*</span></label>
                            <input id="field_of_study" name="field_of_study" class="form-control @error('field_of_study') is-invalid @enderror" value="{{ old('field_of_study') }}" required>
                            @error('field_of_study')<span class="invalid-feedback">{{ $err('field_of_study') }}</span>@enderror
                        </div>

                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="institution">{{ get_phrase('Awarding Institution') }}<span class="req" aria-hidden="true">*</span></label>
                            <input id="institution" name="institution" class="form-control @error('institution') is-invalid @enderror" value="{{ old('institution') }}" required>
                            @error('institution')<span class="invalid-feedback">{{ $err('institution') }}</span>@enderror
                        </div>
                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="completion_year">{{ get_phrase('Year Awarded') }}</label>
                            <input type="number" id="completion_year" name="completion_year" class="form-control @error('completion_year') is-invalid @enderror" value="{{ old('completion_year') }}" min="1900" max="{{ (int) date('Y') + 10 }}" placeholder="{{ date('Y') }}">
                            @error('completion_year')<span class="invalid-feedback">{{ $err('completion_year') }}</span>@enderror
                        </div>
                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="years_teaching_experience">{{ get_phrase('Years of Teaching / Professional Experience') }}</label>
                            <input type="number" id="years_teaching_experience" name="years_teaching_experience" class="form-control @error('years_teaching_experience') is-invalid @enderror" value="{{ old('years_teaching_experience') }}" min="0" max="80">
                            @error('years_teaching_experience')<span class="invalid-feedback">{{ $err('years_teaching_experience') }}</span>@enderror
                        </div>

                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="professional_body">{{ get_phrase('Professional Body') }}</label>
                            <input id="professional_body" name="professional_body" class="form-control @error('professional_body') is-invalid @enderror" value="{{ old('professional_body') }}">
                        </div>
                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="registration_number">{{ get_phrase('Professional Registration Number') }}</label>
                            <input id="registration_number" name="registration_number" class="form-control @error('registration_number') is-invalid @enderror" value="{{ old('registration_number') }}">
                        </div>
                    </div>
                </div>
            </section>

            {{-- ==================================== SUPPORTING DOCUMENTS --}}
            <section class="sc-card" aria-labelledby="sec-documents">
                <div class="sc-card-head">
                    <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-paperclip"></i></span>
                    <div class="sc-card-headings">
                        <h2 class="sc-card-title" id="sec-documents">{{ get_phrase('Supporting Documents') }}</h2>
                        <p class="sc-card-sub">{{ $documentHint }}</p>
                    </div>
                </div>
                <div class="sc-card-body">
                    <div id="documents-list" class="sc-docs">
                        @for ($i = 0; $i < $initialRows; $i++)
                            @include('admin.staff._document_row', ['i' => $i, 'documentCategories' => $documentCategories, 'row' => $oldDocs[$i] ?? []])
                        @endfor
                    </div>

                    <div class="sc-docs-foot">
                        <button type="button" class="sc-add-doc" id="add-document">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ get_phrase('Add another document') }}
                        </button>
                        <span class="sc-doc-count" id="document-count"></span>
                    </div>
                    @error('documents')<span class="invalid-feedback">{{ $err('documents') }}</span>@enderror
                </div>
            </section>
        @endif

        {{-- ==================================== NEXT OF KIN / EMERGENCY CONTACT --}}
        <section class="sc-card" aria-labelledby="sec-nok">
            <div class="sc-card-head">
                <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-heart-pulse"></i></span>
                <div class="sc-card-headings">
                    <h2 class="sc-card-title" id="sec-nok">{{ get_phrase('Next of Kin / Emergency Contact') }}</h2>
                    <p class="sc-card-sub">{{ get_phrase('The person we contact in an emergency. This does not create a system login.') }}</p>
                </div>
            </div>
            <div class="sc-card-body">
                <div class="row sc-grid">
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="emergency_contact_name">{{ get_phrase('Full Name') }}<span class="req" aria-hidden="true">*</span></label>
                        <input id="emergency_contact_name" name="emergency_contact_name" class="form-control @error('emergency_contact_name') is-invalid @enderror" value="{{ old('emergency_contact_name') }}" required>
                        @error('emergency_contact_name')<span class="invalid-feedback">{{ $err('emergency_contact_name') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="emergency_contact_relationship">{{ get_phrase('Relationship') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="emergency_contact_relationship" name="emergency_contact_relationship" class="form-select @error('emergency_contact_relationship') is-invalid @enderror" required>
                            <option value="">{{ get_phrase('Select relationship') }}</option>
                            @foreach($relationships as $r)<option value="{{ $r }}" @selected(old('emergency_contact_relationship') === $r)>{{ get_phrase($r) }}</option>@endforeach
                        </select>
                        @error('emergency_contact_relationship')<span class="invalid-feedback">{{ $err('emergency_contact_relationship') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field @unless($showOtherDetail) d-none @endunless" id="nok-other-wrap">
                        <label class="sc-label" for="emergency_contact_relationship_other">{{ get_phrase('Relationship Description') }}<span class="req" aria-hidden="true">*</span></label>
                        <input id="emergency_contact_relationship_other" name="emergency_contact_relationship_other" class="form-control @error('emergency_contact_relationship_other') is-invalid @enderror" value="{{ old('emergency_contact_relationship_other') }}">
                        @error('emergency_contact_relationship_other')<span class="invalid-feedback">{{ $err('emergency_contact_relationship_other') }}</span>@enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="emergency_contact_email">{{ get_phrase('Email Address') }}<span class="req" aria-hidden="true">*</span></label>
                        <input type="email" id="emergency_contact_email" name="emergency_contact_email" class="form-control @error('emergency_contact_email') is-invalid @enderror" value="{{ old('emergency_contact_email') }}" required>
                        @error('emergency_contact_email')<span class="invalid-feedback">{{ $err('emergency_contact_email') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="emergency_contact_phone">{{ get_phrase('Primary Contact Number') }}<span class="req" aria-hidden="true">*</span></label>
                        <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" class="form-control @error('emergency_contact_phone') is-invalid @enderror" value="{{ old('emergency_contact_phone') }}" placeholder="+256 712 345 678" required>
                        @error('emergency_contact_phone')<span class="invalid-feedback">{{ $err('emergency_contact_phone') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="emergency_contact_alternative_phone">{{ get_phrase('Alternative Contact Number') }}</label>
                        <input type="tel" id="emergency_contact_alternative_phone" name="emergency_contact_alternative_phone" class="form-control @error('emergency_contact_alternative_phone') is-invalid @enderror" value="{{ old('emergency_contact_alternative_phone') }}">
                        @error('emergency_contact_alternative_phone')<span class="invalid-feedback">{{ $err('emergency_contact_alternative_phone') }}</span>@enderror
                    </div>

                    <div class="col-12 sc-field">
                        <label class="sc-label" for="emergency_contact_address">{{ get_phrase('Address') }}</label>
                        <textarea id="emergency_contact_address" name="emergency_contact_address" class="form-control" rows="2">{{ old('emergency_contact_address') }}</textarea>
                    </div>
                </div>
            </div>
        </section>

        {{-- ================================================== PORTAL ACCESS --}}
        <section class="sc-card" aria-labelledby="sec-access">
            <div class="sc-card-head">
                <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-key"></i></span>
                <div class="sc-card-headings">
                    <h2 class="sc-card-title" id="sec-access">{{ get_phrase('Portal Access') }}</h2>
                </div>
            </div>
            <div class="sc-card-body">
                <div class="sc-access" role="status">
                    <span class="sc-access-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
                    <div class="sc-access-body">
                        <p class="sc-access-title">
                            {{ get_phrase('Account setup required') }}
                            <span class="sc-access-badge">{{ get_phrase('Setup Required') }}</span>
                        </p>
                        <p class="sc-access-text">
                            {{ get_phrase('This creates the staff profile only. No password is set on this page.') }}
                        </p>
                        <ul class="sc-access-steps">
                            <li>{{ get_phrase('The staff member will choose their own password through a secure setup link.') }}</li>
                            <li>{{ get_phrase('From Staff Directory → Manage Access, send that link when you are ready.') }}</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        {{-- =================================================== ACTION AREA --}}
        <div class="sc-actions">
            <p class="sc-actions-note">{{ get_phrase('Fields marked * are required. The staff number is generated when the record is saved.') }}</p>
            <div class="sc-actions-buttons">
                <a class="sc-btn sc-btn--ghost" href="{{ route('admin.staff.add') }}">{{ get_phrase('Cancel') }}</a>
                <button class="sc-btn sc-btn--primary" type="submit">
                    <i class="bi bi-person-check" aria-hidden="true"></i> {{ get_phrase('Create ' . $label) }}
                </button>
            </div>
        </div>
    </form>
</div>

@include('admin.staff._create_script')
@endsection
