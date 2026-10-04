@extends('admin.navigation')

{{-- Read-only view of the complete staff record. Shares the Create/Edit Staff
     presentation (staff-form.css) so the three screens read as one workflow. --}}
@section('page_styles')
    <link rel="stylesheet" href="{{ asset('assets/css/staff-form.css') }}?v=20260920-1">
@endsection
@section('hide_page_toolbar')
@endsection

@section('content')
@php
    $photo = $information['photo'] ?? '';
    $qualificationSummary = $qualifications->map(fn ($q) => trim(($q->qualification_name ?: $q->qualification_level).($q->institution ? ' — '.$q->institution : '')))->filter()->values();
    $registrationSummary = $registrations->map(fn ($r) => trim($r->professional_body.($r->registration_number ? ' ('.$r->registration_number.')' : '')))->filter()->values();
    // The tenant's own lookups, so the names can never come from another school.
    $departmentName = optional($departments->firstWhere('id', $member->department_id))->name;
    $designationName = optional($designations->firstWhere('id', $member->designation_id))->name;
    $qualificationsHeading = str_replace(':count', (string) $qualifications->count(), get_phrase('Qualifications (:count)'));
@endphp

<div class="staff-form">

    <div class="staff-form__head">
        <h1 class="staff-form__title">{{ trim(\App\Support\Staff\StaffTitle::base($profile->title ?? '').' '.$member->name) }}</h1>
        <p class="staff-form__sub">{{ get_phrase('The complete staff record for this institution.') }}</p>
        <ul class="d-flex align-items-center eBreadcrumb-2 staff-form__crumb">
            <li><a href="{{ route('admin.rbac.staff.index') }}">{{ get_phrase('Staff Directory') }}</a></li>
            <li><span>{{ get_phrase('View Profile') }}</span></li>
        </ul>
    </div>

    @if(session('message'))<div class="alert alert-success sc-alert" role="status">{{ session('message') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger sc-alert" role="alert">{{ session('error') }}</div>@endif

    {{-- Identity summary + the actions this record allows. --}}
    <section class="sc-card" aria-labelledby="sec-summary">
        <div class="sc-card-head">
            <div class="sc-identity-preview">
                @if($photo !== '')
                    <img class="sc-photo-img is-loaded" src="{{ asset('assets/uploads/user-images/'.$photo) }}" alt="{{ $member->name }}">
                @else
                    <span class="sc-photo-placeholder" aria-hidden="true"><i class="bi bi-person"></i></span>
                @endif
            </div>
            <div class="sc-card-headings">
                <h2 class="sc-card-title" id="sec-summary">{{ $member->name }}</h2>
                <p class="sc-card-sub">
                    {{ $baseRole }} &middot; {{ $member->code ?: get_phrase('No staff number') }} &middot; {{ $member->email }}
                </p>
            </div>
        </div>
        <div class="sc-card-body">
            <div class="sc-actions sc-actions--flush">
                <p class="sc-actions-note">
                    {{ get_phrase('Employment, personal and professional information are read-only here. Correct them with Edit Staff.') }}
                </p>
                <div class="sc-actions-buttons">
                    <a class="sc-btn sc-btn--ghost" href="{{ route('admin.rbac.staff.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> {{ get_phrase('Back to Staff Directory') }}</a>
                    @if($canManageAccess)
                        <a class="sc-btn sc-btn--ghost" href="{{ route('admin.rbac.staff.show', $member->id) }}"><i class="bi bi-shield-lock" aria-hidden="true"></i> {{ get_phrase('Manage access') }}</a>
                    @endif
                    @if($canEdit)
                        <a class="sc-btn sc-btn--primary" href="{{ route('admin.staff.profile.edit', $member->id) }}"><i class="bi bi-pencil-square" aria-hidden="true"></i> {{ get_phrase('Edit staff') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================= PERSONAL INFORMATION --}}
    <section class="sc-card" aria-labelledby="sec-personal">
        <div class="sc-card-head">
            <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
            <div class="sc-card-headings">
                <h2 class="sc-card-title" id="sec-personal">{{ get_phrase('Personal Information') }}</h2>
            </div>
        </div>
        <div class="sc-card-body">
            <dl class="sc-facts">
                <div><dt>{{ get_phrase('Title') }}</dt><dd>{{ \App\Support\Staff\StaffTitle::base($profile->title ?? '') ?: '—' }}</dd></div>
                <div><dt>{{ get_phrase('First Name') }}</dt><dd>{{ $member->first_name ?: '—' }}</dd></div>
                <div><dt>{{ get_phrase('Middle Name') }}</dt><dd>{{ $profile->middle_name ?: '—' }}</dd></div>
                <div><dt>{{ get_phrase('Last Name') }}</dt><dd>{{ $member->last_name ?: '—' }}</dd></div>
                <div><dt>{{ get_phrase('Gender') }}</dt><dd>{{ $information['gender'] ?? '—' }}</dd></div>
                <div><dt>{{ get_phrase('Date of Birth') }}</dt><dd>{{ $birthday ? date('d M Y', strtotime($birthday)) : '—' }}</dd></div>
                <div><dt>{{ get_phrase('Nationality') }}</dt><dd>{{ $profile->nationality ?: '—' }}</dd></div>
                <div><dt>{{ get_phrase('Blood Group') }}</dt><dd>{{ $information['blood_group'] ?: '—' }}</dd></div>
                {{-- Never the stored NIN: masked with staff.nin.view, otherwise just "recorded". --}}
                <div><dt>{{ get_phrase('National ID / Passport No.') }}</dt><dd>{{ $ninDisplay }}</dd></div>
                <div class="sc-facts-wide"><dt>{{ get_phrase('Address') }}</dt><dd>{{ $information['address'] ?: '—' }}</dd></div>
            </dl>
        </div>
    </section>

    {{-- ============================================ CONTACT INFORMATION --}}
    <section class="sc-card" aria-labelledby="sec-contact">
        <div class="sc-card-head">
            <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-telephone"></i></span>
            <div class="sc-card-headings">
                <h2 class="sc-card-title" id="sec-contact">{{ get_phrase('Contact Information') }}</h2>
            </div>
        </div>
        <div class="sc-card-body">
            <dl class="sc-facts">
                <div><dt>{{ get_phrase('Email Address') }}</dt><dd class="text-break">{{ $member->email }}</dd></div>
                <div><dt>{{ get_phrase('Primary Phone Number') }}</dt><dd>{{ $information['phone'] ?: '—' }}</dd></div>
                <div><dt>{{ get_phrase('Alternative Phone Number') }}</dt><dd>{{ $profile->alternative_phone ?: '—' }}</dd></div>
            </dl>
        </div>
    </section>

    {{-- ========================================= EMPLOYMENT INFORMATION --}}
    <section class="sc-card" aria-labelledby="sec-employment">
        <div class="sc-card-head">
            <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-briefcase"></i></span>
            <div class="sc-card-headings">
                <h2 class="sc-card-title" id="sec-employment">{{ get_phrase('Employment Information') }}</h2>
            </div>
        </div>
        <div class="sc-card-body">
            <dl class="sc-facts">
                <div><dt>{{ get_phrase('Staff Number') }}</dt><dd>{{ $member->code ?: '—' }}</dd></div>
                <div><dt>{{ get_phrase('Department') }}</dt><dd>{{ $departmentName ?: '—' }}</dd></div>
                <div><dt>{{ get_phrase('Designation') }}</dt><dd>{{ $designationName ?: '—' }}</dd></div>
                <div><dt>{{ get_phrase('Employment Type') }}</dt><dd>{{ $member->employment_type ?: '—' }}</dd></div>
                <div>
                    <dt>{{ get_phrase('Employment Status') }}</dt>
                    <dd>
                        <span class="sc-chip {{ \App\Support\Staff\StaffStatus::blocksPortal($member->staff_status) ? 'sc-chip--warn' : 'sc-chip--ok' }}">
                            {{ ucwords(str_replace('_', ' ', (string) ($member->staff_status ?: 'active'))) }}
                        </span>
                    </dd>
                </div>
                <div><dt>{{ get_phrase('Joining Date') }}</dt><dd>{{ $profile->date_joined ? date('d M Y', strtotime($profile->date_joined)) : '—' }}</dd></div>
            </dl>
            @permission('hr.designations')
                <p class="sc-hint mt-3">
                    {{ get_phrase('The designation is master data.') }}
                    <a href="{{ route('admin.designation_list') }}" target="_blank" rel="noopener">{{ get_phrase('Manage Designations') }}</a>
                </p>
            @endpermission
        </div>
    </section>

    {{-- ==================================== NEXT OF KIN / EMERGENCY CONTACT --}}
    <section class="sc-card" aria-labelledby="sec-nok">
        <div class="sc-card-head">
            <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-heart-pulse"></i></span>
            <div class="sc-card-headings">
                <h2 class="sc-card-title" id="sec-nok">{{ get_phrase('Next of Kin / Emergency Contact') }}</h2>
            </div>
        </div>
        <div class="sc-card-body">
            @if(!$profile || !$profile->emergency_contact_name)
                <p class="sc-hint">{{ get_phrase('No Next of Kin is recorded for this staff member.') }}</p>
            @else
                <dl class="sc-facts">
                    <div><dt>{{ get_phrase('Full Name') }}</dt><dd>{{ $profile->emergency_contact_name }}</dd></div>
                    <div>
                        <dt>{{ get_phrase('Relationship') }}</dt>
                        <dd>
                            {{ \App\Support\Staff\StaffNextOfKin::base($profile->emergency_contact_relationship) ?: '—' }}
                            @if($detail = \App\Support\Staff\StaffNextOfKin::otherDetail($profile->emergency_contact_relationship))
                                <span class="sc-hint-sm">{{ $detail }}</span>
                            @endif
                        </dd>
                    </div>
                    <div><dt>{{ get_phrase('Email Address') }}</dt><dd class="text-break">{{ $profile->emergency_contact_email ?: '—' }}</dd></div>
                    <div><dt>{{ get_phrase('Primary Contact Number') }}</dt><dd>{{ $profile->emergency_contact_phone ?: '—' }}</dd></div>
                    <div><dt>{{ get_phrase('Alternative Contact Number') }}</dt><dd>{{ $profile->emergency_contact_alternative_phone ?: '—' }}</dd></div>
                    <div class="sc-facts-wide"><dt>{{ get_phrase('Address') }}</dt><dd>{{ $profile->emergency_contact_address ?: '—' }}</dd></div>
                </dl>
            @endif
        </div>
    </section>

    {{-- ======================== ACADEMIC & PROFESSIONAL (academic roles) --}}
    @if($isAcademic)
        <section class="sc-card" aria-labelledby="sec-academic">
            <div class="sc-card-head">
                <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-mortarboard"></i></span>
                <div class="sc-card-headings">
                    <h2 class="sc-card-title" id="sec-academic">{{ get_phrase('Academic & Professional Information') }}</h2>
                </div>
            </div>
            <div class="sc-card-body">
                <dl class="sc-facts">
                    <div><dt>{{ get_phrase('Highest Qualification') }}</dt><dd>{{ $qualificationSummary->first() ?: '—' }}</dd></div>
                    <div><dt>{{ get_phrase('Years of Teaching / Professional Experience') }}</dt><dd>{{ $profile->years_teaching_experience ?? '—' }}</dd></div>
                    <div><dt>{{ get_phrase('Professional Body') }}</dt><dd>{{ $registrationSummary->first() ?: '—' }}</dd></div>
                </dl>

                <p class="sc-existing-title mt-4">{{ $qualificationsHeading }}</p>
                @if($qualifications->isEmpty())
                    <p class="sc-hint">{{ get_phrase('No qualification is recorded yet.') }}</p>
                @else
                    <ul class="sc-existing-list">
                        @foreach($qualifications as $q)
                            <li>
                                <span>{{ \App\Support\Staff\StaffQualificationLevel::base($q->qualification_level) }}{{ ($d = \App\Support\Staff\StaffQualificationLevel::otherDetail($q->qualification_level)) ? ': '.$d : '' }}</span>
                                <span class="sc-hint-sm">
                                    {{ $q->institution ?: '—' }}
                                    @if($q->specialisation) &middot; {{ $q->specialisation }} @endif
                                    @if($q->completion_year) &middot; {{ $q->completion_year }} @endif
                                    @if($q->verification_status) &middot; {{ ucfirst(str_replace('_', ' ', $q->verification_status)) }} @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if($registrations->isNotEmpty())
                    <p class="sc-existing-title mt-4">{{ get_phrase('Professional registrations') }}</p>
                    <ul class="sc-existing-list">
                        @foreach($registrations as $r)
                            <li>
                                <span>{{ $r->professional_body }}</span>
                                <span class="sc-hint-sm">{{ $r->registration_number ?: '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        {{-- ============================================ SUPPORTING DOCUMENTS --}}
        <section class="sc-card" aria-labelledby="sec-documents">
            <div class="sc-card-head">
                <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-paperclip"></i></span>
                <div class="sc-card-headings">
                    <h2 class="sc-card-title" id="sec-documents">{{ get_phrase('Supporting Documents') }}</h2>
                    <p class="sc-card-sub">{{ get_phrase('Stored privately. A document is never published at a public URL.') }}</p>
                </div>
            </div>
            <div class="sc-card-body">
                @if($documents->isEmpty())
                    <p class="sc-hint">{{ get_phrase('No supporting document is on file.') }}</p>
                @else
                    <ul class="sc-existing-list">
                        @foreach($documents as $document)
                            <li>
                                <span>{{ get_phrase(\App\Models\StaffDocument::CATEGORIES[$document->category] ?? $document->category) }}</span>
                                <span class="sc-hint-sm">
                                    {{ $document->original_name }}
                                    @if($document->verification_status) &middot; {{ ucfirst(str_replace('_', ' ', $document->verification_status)) }} @endif
                                </span>
                                @if($canViewDocuments)
                                    <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ route('admin.staff.documents.download', $document->id) }}" target="_blank" rel="noopener">
                                        <i class="bi bi-download" aria-hidden="true"></i> {{ get_phrase('Download') }}
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    @endif

    {{-- ============================================ ACCOUNT ACCESS / PORTAL --}}
    <section class="sc-card" aria-labelledby="sec-access">
        <div class="sc-card-head">
            <span class="sc-card-icon" aria-hidden="true"><i class="bi bi-key"></i></span>
            <div class="sc-card-headings">
                <h2 class="sc-card-title" id="sec-access">{{ get_phrase('Account Access') }}</h2>
                <p class="sc-card-sub">{{ get_phrase('Portal sign-in: the staff member chooses their own password through a secure setup link. Roles and permissions are managed separately.') }}</p>
            </div>
        </div>
        <div class="sc-card-body">
            <dl class="sc-facts">
                <div>
                    <dt>{{ get_phrase('Portal account') }}</dt>
                    <dd>{{ $member->account_status === 'disable' ? get_phrase('Disabled') : get_phrase('Enabled') }}</dd>
                </div>
                <div>
                    <dt>{{ get_phrase('Password setup') }}</dt>
                    <dd>
                        <span class="sc-chip {{ $setupState === 'completed' ? 'sc-chip--ok' : ($setupState === 'pending' ? 'sc-chip--info' : 'sc-chip--warn') }}">
                            {{ $setupStateLabel }}
                        </span>
                    </dd>
                </div>
                <div><dt>{{ get_phrase('Base system role') }}</dt><dd>{{ $baseRole }}</dd></div>
                <div><dt>{{ get_phrase('Email') }}</dt><dd class="text-break">{{ $member->email }}</dd></div>
            </dl>

            {{-- The next step in words, with the ONE governed action that
                 performs it. This screen never sends a link itself: it opens the
                 existing account-access workflow, which does. --}}
            <div class="sc-access mt-3">
                <span class="sc-access-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
                <div class="sc-access-body">
                    @if ($setupState === 'completed')
                        <p class="sc-access-title">{{ get_phrase('Portal access active') }}</p>
                        <p class="sc-access-text">
                            {{ get_phrase('This staff member has chosen their password and can sign in. If they forget it they use password recovery on the sign-in page.') }}
                        </p>
                        <a class="sc-btn sc-btn--ghost mt-3" href="{{ route('admin.staff.account-access.show', $member->id) }}">
                            <i class="bi bi-key" aria-hidden="true"></i> {{ get_phrase('Account Access') }}
                        </a>
                    @else
                        <p class="sc-access-title">{{ get_phrase('Next step: send a secure setup link') }}</p>
                        <p class="sc-access-text">
                            {{ str_replace(':name', $member->name, get_phrase('Send :name a secure setup link so they can choose their own password. No password is sent by email, and no administrator sets or sees one.')) }}
                        </p>
                        <ul class="sc-access-steps">
                            <li>{{ get_phrase('The link is single-use, it expires, and issuing it is recorded in the audit log.') }}</li>
                            <li>{{ get_phrase('Roles, custom permissions and the staff number are not affected by this.') }}</li>
                        </ul>
                        <a class="sc-btn sc-btn--primary mt-3" href="{{ route('admin.staff.account-access.show', $member->id) }}">
                            <i class="bi bi-send" aria-hidden="true"></i>
                            {{ $setupState === 'pending' ? get_phrase('Resend Password Setup Link') : get_phrase('Set Up Portal Access') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </section>
</div>
@endsection
