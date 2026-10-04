@extends('admin.navigation')

{{-- Reuses the Create Staff presentation verbatim (staff-form.css), so correcting
     a record looks and behaves exactly like creating one. --}}
@section('page_styles')
    <link rel="stylesheet" href="{{ asset('assets/css/staff-form.css') }}?v=20260920-1">
@endsection
@section('hide_page_toolbar')
@endsection

@section('content')
@php
    $error = fn ($f) => $errors->first($f);

    // Stored controlled values may be "Other: <detail>"; the SELECT compares
    // against the base option while the description carries the detail, so an
    // existing "Other" title/qualification/relationship round-trips instead of
    // silently reverting to the empty option.
    $storedTitle = $profile->title ?? '';
    $titleSelection = \App\Support\Staff\StaffTitle::base($storedTitle);
    $storedRelationship = $profile->emergency_contact_relationship ?? '';
    $relationshipSelection = \App\Support\Staff\StaffNextOfKin::base($storedRelationship);
    $storedQualification = $qualification->qualification_level ?? '';
    $qualificationSelection = \App\Support\Staff\StaffQualificationLevel::base($storedQualification);

    $showOtherTitle = old('title', $titleSelection) === \App\Support\Staff\StaffTitle::OTHER;
    $showOtherTitleValue = old('title_other', \App\Support\Staff\StaffTitle::otherDetail($storedTitle) ?? '');
    $showOtherRelationship = old('emergency_contact_relationship', $relationshipSelection) === \App\Support\Staff\StaffNextOfKin::OTHER;
    $showOtherRelationshipValue = old('emergency_contact_relationship_other', \App\Support\Staff\StaffNextOfKin::otherDetail($storedRelationship) ?? '');
    $showOtherQualification = old('qualification_level', $qualificationSelection) === \App\Support\Staff\StaffQualificationLevel::OTHER;
    $showOtherQualificationValue = old('qualification_level_other', \App\Support\Staff\StaffQualificationLevel::otherDetail($storedQualification) ?? '');

    $documentHint = str_replace(
        [':types', ':size'],
        [$acceptedMime, round($maxKb / 1024, 1).' MB'],
        get_phrase('Files are stored privately and are never published at a public URL. Accepted: :types, up to :size each.')
    );
    $onFileHint = str_replace(':count', (string) $existingDocuments->count(),
        get_phrase('Already on file (:count) — this form never replaces or removes them'));
    $codeHint = str_replace(':code', $member->code ?: '—',
        get_phrase('Staff Number (:code) is generated once and never edited.'));
    $roleHint = str_replace(':role', $baseRole,
        get_phrase('Base system role (:role) is an access decision, changed from Manage Access.'));

    $oldDocs = old('documents', []);
    $initialRows = max(1, min((int) $maxDocuments, count($oldDocs) ?: 1));
@endphp

<div class="staff-form">

    <div class="staff-form__head">
        <h1 class="staff-form__title">{{ get_phrase('Edit staff') }}</h1>
        <p class="staff-form__sub">{{ get_phrase('Correct this staff member’s personal, employment and professional information.') }}</p>
        <ul class="d-flex align-items-center eBreadcrumb-2 staff-form__crumb">
            <li><a href="{{ route('admin.rbac.staff.index') }}">{{ get_phrase('Staff Directory') }}</a></li>
            <li><a href="{{ route('admin.staff.profile.show', $member->id) }}">{{ $member->name }}</a></li>
            <li><span>{{ get_phrase('Edit staff') }}</span></li>
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

    {{-- What this form deliberately does not touch. --}}
    <div class="sc-access mb-4" role="note">
        <span class="sc-access-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
        <div class="sc-access-body">
            <p class="sc-access-title">{{ get_phrase('Identity and access are not changed here') }}</p>
            <ul class="sc-access-steps">
                <li>{{ $codeHint }}</li>
                <li>{{ $roleHint }}</li>
                <li>{{ get_phrase('Custom roles, direct permissions and the portal password are untouched by this form.') }}</li>
            </ul>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.staff.profile.update', $member->id) }}" enctype="multipart/form-data" id="staff-edit-form">
        @csrf
        @method('PUT')

        {{-- ============================================= PERSONAL INFORMATION --}}
        <section class="sc-card" aria-labelledby="sec-personal">
            <div class="sc-card-head">
                <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
                <div class="sc-card-headings">
                    <h2 class="sc-card-title" id="sec-personal">{{ get_phrase('Personal Information') }}</h2>
                    <p class="sc-card-sub">{{ get_phrase('Who this person is.') }}</p>
                </div>
            </div>
            <div class="sc-card-body">

                <div class="sc-identity">
                    <div class="sc-identity-preview">
                        @if(!empty($information['photo']))
                            <img class="sc-photo-img is-loaded" src="{{ asset('assets/uploads/user-images/'.$information['photo']) }}" alt="{{ $member->name }}">
                        @else
                            <span class="sc-photo-placeholder" aria-hidden="true"><i class="bi bi-person"></i></span>
                        @endif
                    </div>
                    <div class="sc-identity-main">
                        <p class="sc-identity-title">{{ get_phrase('Profile Photo') }}</p>
                        <span class="sc-hint">{{ get_phrase('Leave empty to keep the current photo.') }}</span>
                        <div class="sc-identity-actions">
                            <input type="file" id="photo" name="photo" class="sc-file-input @error('photo') is-invalid @enderror"
                                   accept="image/jpeg,image/png,.jpg,.jpeg,.png"
                                   data-preview="sc-photo-preview"
                                   data-placeholder="sc-photo-placeholder"
                                   data-filename="sc-photo-name">
                            <label class="sc-file-btn" for="photo"><i class="bi bi-upload" aria-hidden="true"></i> {{ get_phrase('Replace photo') }}</label>
                            <span class="sc-file-name" id="sc-photo-name">{{ get_phrase('No file selected') }}</span>
                        </div>
                        @error('photo')<span class="invalid-feedback">{{ $error('photo') }}</span>@enderror
                    </div>
                </div>

                <div class="row sc-grid">
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="first_name">{{ get_phrase('First Name') }}<span class="req" aria-hidden="true">*</span></label>
                        <input id="first_name" name="first_name" class="form-control @error('first_name') is-invalid @enderror" value="{{ old('first_name', $member->first_name ?: '') }}" required>
                        @error('first_name')<span class="invalid-feedback">{{ $error('first_name') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="middle_name">{{ get_phrase('Middle Name') }}</label>
                        <input id="middle_name" name="middle_name" class="form-control" value="{{ old('middle_name', $profile->middle_name ?? '') }}">
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="last_name">{{ get_phrase('Last Name') }}<span class="req" aria-hidden="true">*</span></label>
                        <input id="last_name" name="last_name" class="form-control @error('last_name') is-invalid @enderror" value="{{ old('last_name', $member->last_name) }}" required>
                        @error('last_name')<span class="invalid-feedback">{{ $error('last_name') }}</span>@enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="title">{{ get_phrase('Title') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="title" name="title" class="form-select @error('title') is-invalid @enderror" required>
                            <option value="">{{ get_phrase('Select a title') }}</option>
                            @foreach($titles as $t)<option value="{{ $t }}" @selected(old('title', $titleSelection) === $t)>{{ get_phrase($t) }}</option>@endforeach
                        </select>
                        @error('title')<span class="invalid-feedback">{{ $error('title') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field @unless($showOtherTitle) d-none @endunless" id="title-other-wrap">
                        <label class="sc-label" for="title_other">{{ get_phrase('Title') }}<span class="req" aria-hidden="true">*</span></label>
                        <input id="title_other" name="title_other" class="form-control @error('title_other') is-invalid @enderror" value="{{ old('title_other', $showOtherTitleValue) }}">
                        @error('title_other')<span class="invalid-feedback">{{ $error('title_other') }}</span>@enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="gender">{{ get_phrase('Gender') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="gender" name="gender" class="form-select @error('gender') is-invalid @enderror" required>
                            <option value="">{{ get_phrase('Select gender') }}</option>
                            @foreach($genders as $g)<option value="{{ $g }}" @selected(old('gender', $information['gender'] ?? '') === $g)>{{ get_phrase($g) }}</option>@endforeach
                        </select>
                        @error('gender')<span class="invalid-feedback">{{ $error('gender') }}</span>@enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="birthday">{{ get_phrase('Date of Birth') }}</label>
                        <input type="date" id="birthday" name="birthday" class="form-control @error('birthday') is-invalid @enderror" value="{{ old('birthday', $birthday) }}">
                        @error('birthday')<span class="invalid-feedback">{{ $error('birthday') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="nationality">{{ get_phrase('Nationality') }}</label>
                        <input id="nationality" name="nationality" class="form-control" value="{{ old('nationality', $profile->nationality ?? '') }}">
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="blood_group">{{ get_phrase('Blood Group') }}</label>
                        <select id="blood_group" name="blood_group" class="form-select">
                            <option value="">{{ get_phrase('Select a blood group') }}</option>
                            @foreach($bloodGroups as $b)<option value="{{ $b }}" @selected(old('blood_group', $information['blood_group'] ?? '') === $b)>{{ get_phrase($b) }}</option>@endforeach
                        </select>
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="nin">{{ get_phrase('National ID / Passport No.') }}</label>
                        <input id="nin" name="nin" class="form-control @error('nin') is-invalid @enderror" value="{{ old('nin') }}"
                               placeholder="{{ $ninDisplay !== 'Not provided' ? $ninDisplay : get_phrase('Not recorded') }}"
                               autocomplete="off">
                        <span class="sc-hint">{{ get_phrase('Leave empty to keep the recorded value. Enter a new number only to replace it.') }}</span>
                        @error('nin')<span class="invalid-feedback">{{ $error('nin') }}</span>@enderror
                    </div>

                    <div class="col-12 sc-field">
                        <label class="sc-label" for="address">{{ get_phrase('Address') }}</label>
                        <textarea id="address" name="address" class="form-control" rows="2">{{ old('address', $information['address'] ?? '') }}</textarea>
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
                        <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $member->email) }}" required>
                        @error('email')<span class="invalid-feedback">{{ $error('email') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="phone">{{ get_phrase('Primary Phone Number') }}<span class="req" aria-hidden="true">*</span></label>
                        <input type="tel" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone', $information['phone'] ?? '') }}" placeholder="+256 712 345 678" required>
                        @error('phone')<span class="invalid-feedback">{{ $error('phone') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="alternative_phone">{{ get_phrase('Alternative Phone Number') }}</label>
                        <input type="tel" id="alternative_phone" name="alternative_phone" class="form-control @error('alternative_phone') is-invalid @enderror" value="{{ old('alternative_phone', $profile->alternative_phone ?? '') }}">
                        @error('alternative_phone')<span class="invalid-feedback">{{ $error('alternative_phone') }}</span>@enderror
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
                        <select id="department_id" name="department_id" class="form-select @error('department_id') is-invalid @enderror">
                            <option value="">{{ get_phrase('Select a department') }}</option>
                            @foreach($departments as $d)<option value="{{ $d->id }}" @selected((string) old('department_id', $member->department_id) === (string) $d->id)>{{ $d->name }}</option>@endforeach
                        </select>
                        @error('department_id')<span class="invalid-feedback">{{ $error('department_id') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="designation_id">{{ get_phrase('Designation') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="designation_id" name="designation_id" class="form-select @error('designation_id') is-invalid @enderror" required>
                            <option value="">{{ get_phrase('Select a designation') }}</option>
                            @foreach($designations as $d)<option value="{{ $d->id }}" @selected((string) old('designation_id', $member->designation_id) === (string) $d->id)>{{ $d->name }}</option>@endforeach
                        </select>
                        <span class="sc-hint">
                            {{ get_phrase('The job title, e.g. Lecturer or Head of Department.') }}
                            @permission('hr.designations')
                                <a href="{{ route('admin.designation_list') }}" target="_blank" rel="noopener">{{ get_phrase('Manage Designations') }}</a>
                            @endpermission
                        </span>
                        @error('designation_id')<span class="invalid-feedback">{{ $error('designation_id') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="employment_type">{{ get_phrase('Employment Type') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="employment_type" name="employment_type" class="form-select @error('employment_type') is-invalid @enderror" required>
                            @foreach($employmentTypes as $e)<option value="{{ $e }}" @selected(old('employment_type', $member->employment_type ?: 'Full Time') === $e)>{{ get_phrase($e) }}</option>@endforeach
                        </select>
                        @error('employment_type')<span class="invalid-feedback">{{ $error('employment_type') }}</span>@enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="staff_status">{{ get_phrase('Employment Status') }}<span class="req" aria-hidden="true">*</span></label>
                        <select id="staff_status" name="staff_status" class="form-select @error('staff_status') is-invalid @enderror" required>
                            @foreach($staffStatuses as $s)<option value="{{ $s }}" @selected(old('staff_status', $member->staff_status ?: 'active') === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>@endforeach
                        </select>
                        <span class="sc-hint">{{ get_phrase('Suspended, inactive and terminated block the portal login.') }}</span>
                        @error('staff_status')<span class="invalid-feedback">{{ $error('staff_status') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="date_joined">{{ get_phrase('Joining Date') }}</label>
                        <input type="date" id="date_joined" name="date_joined" class="form-control" value="{{ old('date_joined', $profile->date_joined ? date('Y-m-d', strtotime($profile->date_joined)) : '') }}">
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        {{-- Identity, shown but never writable. --}}
                        <label class="sc-label" for="staff_number_readonly">{{ get_phrase('Staff Number') }}</label>
                        <input id="staff_number_readonly" class="form-control" value="{{ $member->code ?: '—' }}" disabled>
                        <span class="sc-hint">{{ get_phrase('Generated once when the record was created. It cannot be edited.') }}</span>
                    </div>
                </div>
            </div>
        </section>

        {{-- ========================= ACADEMIC & PROFESSIONAL INFORMATION --}}
        @if($isAcademic)
            <section class="sc-card" aria-labelledby="sec-academic">
                <div class="sc-card-head">
                    <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-mortarboard"></i></span>
                    <div class="sc-card-headings">
                        <h2 class="sc-card-title" id="sec-academic">{{ get_phrase('Academic & Professional Information') }}</h2>
                        <p class="sc-card-sub">
                            {{ $qualification
                                ? get_phrase('Saving corrects the existing qualification in place. Nothing is replaced or deleted.')
                                : get_phrase('This staff member has no qualification recorded yet. Completing this block adds one.') }}
                        </p>
                    </div>
                </div>
                <div class="sc-card-body">
                    <div class="row sc-grid">
                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="qualification_level">{{ get_phrase('Highest Qualification') }}</label>
                            <select id="qualification_level" name="qualification_level" class="form-select @error('qualification_level') is-invalid @enderror">
                                <option value="">{{ get_phrase('Leave empty to keep as recorded') }}</option>
                                @foreach($qualificationLevels as $q)<option value="{{ $q }}" @selected(old('qualification_level', $qualificationSelection) === $q)>{{ get_phrase($q) }}</option>@endforeach
                            </select>
                            @error('qualification_level')<span class="invalid-feedback">{{ $error('qualification_level') }}</span>@enderror
                        </div>
                        <div class="col-12 col-md-6 col-xl-4 sc-field @unless($showOtherQualification) d-none @endunless" id="qual-level-other-wrap">
                            <label class="sc-label" for="qualification_level_other">{{ get_phrase('Qualification') }}<span class="req" aria-hidden="true">*</span></label>
                            <input id="qualification_level_other" name="qualification_level_other" class="form-control @error('qualification_level_other') is-invalid @enderror" value="{{ old('qualification_level_other', $showOtherQualificationValue) }}">
                            @error('qualification_level_other')<span class="invalid-feedback">{{ $error('qualification_level_other') }}</span>@enderror
                        </div>
                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="field_of_study">{{ get_phrase('Field of Study / Specialisation') }}</label>
                            <input id="field_of_study" name="field_of_study" class="form-control @error('field_of_study') is-invalid @enderror" value="{{ old('field_of_study', $qualification->specialisation ?? $profile->specialisation ?? '') }}">
                            @error('field_of_study')<span class="invalid-feedback">{{ $error('field_of_study') }}</span>@enderror
                        </div>

                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="institution">{{ get_phrase('Awarding Institution') }}</label>
                            <input id="institution" name="institution" class="form-control @error('institution') is-invalid @enderror" value="{{ old('institution', $qualification->institution ?? '') }}">
                            @error('institution')<span class="invalid-feedback">{{ $error('institution') }}</span>@enderror
                        </div>
                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="completion_year">{{ get_phrase('Year Awarded') }}</label>
                            <input type="number" id="completion_year" name="completion_year" class="form-control @error('completion_year') is-invalid @enderror" value="{{ old('completion_year', $qualification->completion_year ?? '') }}" min="1900" max="{{ (int) date('Y') + 10 }}">
                            @error('completion_year')<span class="invalid-feedback">{{ $error('completion_year') }}</span>@enderror
                        </div>
                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="years_teaching_experience">{{ get_phrase('Years of Teaching / Professional Experience') }}</label>
                            <input type="number" id="years_teaching_experience" name="years_teaching_experience" class="form-control @error('years_teaching_experience') is-invalid @enderror" value="{{ old('years_teaching_experience', $profile->years_teaching_experience) }}" min="0" max="80">
                            @error('years_teaching_experience')<span class="invalid-feedback">{{ $error('years_teaching_experience') }}</span>@enderror
                        </div>

                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="professional_body">{{ get_phrase('Professional Body') }}</label>
                            <input id="professional_body" name="professional_body" class="form-control @error('professional_body') is-invalid @enderror" value="{{ old('professional_body', $registration->professional_body ?? '') }}">
                            @error('professional_body')<span class="invalid-feedback">{{ $error('professional_body') }}</span>@enderror
                        </div>
                        <div class="col-12 col-md-6 col-xl-4 sc-field">
                            <label class="sc-label" for="registration_number">{{ get_phrase('Professional Registration Number') }}</label>
                            <input id="registration_number" name="registration_number" class="form-control @error('registration_number') is-invalid @enderror" value="{{ old('registration_number', $registration->registration_number ?? '') }}">
                            @error('registration_number')<span class="invalid-feedback">{{ $error('registration_number') }}</span>@enderror
                        </div>
                    </div>
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
                        <label class="sc-label" for="emergency_contact_name">{{ get_phrase('Full Name') }}</label>
                        <input id="emergency_contact_name" name="emergency_contact_name" class="form-control @error('emergency_contact_name') is-invalid @enderror" value="{{ old('emergency_contact_name', $profile->emergency_contact_name ?? '') }}">
                        @error('emergency_contact_name')<span class="invalid-feedback">{{ $error('emergency_contact_name') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="emergency_contact_relationship">{{ get_phrase('Relationship') }}</label>
                        <select id="emergency_contact_relationship" name="emergency_contact_relationship" class="form-select @error('emergency_contact_relationship') is-invalid @enderror">
                            <option value="">{{ get_phrase('Select relationship') }}</option>
                            @foreach($relationships as $r)<option value="{{ $r }}" @selected(old('emergency_contact_relationship', $relationshipSelection) === $r)>{{ get_phrase($r) }}</option>@endforeach
                        </select>
                        @error('emergency_contact_relationship')<span class="invalid-feedback">{{ $error('emergency_contact_relationship') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field @unless($showOtherRelationship) d-none @endunless" id="nok-other-wrap">
                        <label class="sc-label" for="emergency_contact_relationship_other">{{ get_phrase('Relationship Description') }}<span class="req" aria-hidden="true">*</span></label>
                        <input id="emergency_contact_relationship_other" name="emergency_contact_relationship_other" class="form-control @error('emergency_contact_relationship_other') is-invalid @enderror" value="{{ old('emergency_contact_relationship_other', $showOtherRelationshipValue) }}">
                        @error('emergency_contact_relationship_other')<span class="invalid-feedback">{{ $error('emergency_contact_relationship_other') }}</span>@enderror
                    </div>

                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="emergency_contact_email">{{ get_phrase('Email Address') }}</label>
                        <input type="email" id="emergency_contact_email" name="emergency_contact_email" class="form-control @error('emergency_contact_email') is-invalid @enderror" value="{{ old('emergency_contact_email', $profile->emergency_contact_email ?? '') }}">
                        @error('emergency_contact_email')<span class="invalid-feedback">{{ $error('emergency_contact_email') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="emergency_contact_phone">{{ get_phrase('Primary Contact Number') }}</label>
                        <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone" class="form-control @error('emergency_contact_phone') is-invalid @enderror" value="{{ old('emergency_contact_phone', $profile->emergency_contact_phone ?? '') }}" placeholder="+256 712 345 678">
                        @error('emergency_contact_phone')<span class="invalid-feedback">{{ $error('emergency_contact_phone') }}</span>@enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl-4 sc-field">
                        <label class="sc-label" for="emergency_contact_alternative_phone">{{ get_phrase('Alternative Contact Number') }}</label>
                        <input type="tel" id="emergency_contact_alternative_phone" name="emergency_contact_alternative_phone" class="form-control @error('emergency_contact_alternative_phone') is-invalid @enderror" value="{{ old('emergency_contact_alternative_phone', $profile->emergency_contact_alternative_phone ?? '') }}">
                        @error('emergency_contact_alternative_phone')<span class="invalid-feedback">{{ $error('emergency_contact_alternative_phone') }}</span>@enderror
                    </div>

                    <div class="col-12 sc-field">
                        <label class="sc-label" for="emergency_contact_address">{{ get_phrase('Address') }}</label>
                        <textarea id="emergency_contact_address" name="emergency_contact_address" class="form-control" rows="2">{{ old('emergency_contact_address', $profile->emergency_contact_address ?? '') }}</textarea>
                        @error('emergency_contact_address')<span class="invalid-feedback">{{ $error('emergency_contact_address') }}</span>@enderror
                    </div>
                </div>
            </div>
        </section>

        {{-- ================================ SUPPORTING DOCUMENTS (add only) --}}
        @if($isAcademic)
            <section class="sc-card" aria-labelledby="sec-documents">
                <div class="sc-card-head">
                    <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-paperclip"></i></span>
                    <div class="sc-card-headings">
                        <h2 class="sc-card-title" id="sec-documents">{{ get_phrase('Supporting Documents') }}</h2>
                        <p class="sc-card-sub">{{ $documentHint }}</p>
                    </div>
                </div>
                <div class="sc-card-body">
                    @if($existingDocuments->isNotEmpty())
                        <div class="sc-existing">
                            <p class="sc-existing-title">{{ $onFileHint }}</p>
                            <ul class="sc-existing-list">
                                @foreach($existingDocuments as $document)
                                    <li>
                                        <span>{{ get_phrase(\App\Models\StaffDocument::CATEGORIES[$document->category] ?? $document->category) }}</span>
                                        <span class="sc-hint-sm">{{ $document->original_name }}</span>
                                        @if($canViewDocuments)
                                            <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ route('admin.staff.documents.download', $document->id) }}" target="_blank" rel="noopener">
                                                <i class="bi bi-download" aria-hidden="true"></i> {{ get_phrase('Download') }}
                                            </a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if($canUploadDocuments)
                        <p class="sc-existing-title mt-4">{{ get_phrase('Add another document') }}</p>
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
                        @error('documents')<span class="invalid-feedback">{{ $error('documents') }}</span>@enderror
                    @else
                        <p class="sc-hint">{{ get_phrase('You do not have permission to upload staff documents.') }}</p>
                    @endif
                </div>
            </section>
        @endif

        {{-- =================================================== ACTION AREA --}}
        <div class="sc-actions">
            <p class="sc-actions-note">
                {{ get_phrase('Saving changes this staff record only. Roles, permissions and the portal password stay exactly as they are.') }}
            </p>
            <div class="sc-actions-buttons">
                <a class="sc-btn sc-btn--ghost" href="{{ route('admin.staff.profile.show', $member->id) }}">{{ get_phrase('Cancel') }}</a>
                <button class="sc-btn sc-btn--primary" type="submit">
                    <i class="bi bi-check-lg" aria-hidden="true"></i> {{ get_phrase('Save Changes') }}
                </button>
            </div>
        </div>
    </form>
</div>

@include('admin.staff._create_script')
@endsection
