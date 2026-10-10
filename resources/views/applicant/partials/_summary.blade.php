{{--
    Read-only rendering of everything on an application. Used by the Review
    step (before submitting) and the Summary page (after), so what the
    applicant checks is literally what they later see on record.
    Expects: $admission. Optional: $docChecklist, $feeAmount.
--}}

@php
    use App\Support\Admissions\ApplicationFee;

    $blank = '—';
@endphp

<div class="row g-4">
    <div class="col-lg-6">
        <div class="ap-card h-100">
            <div class="ap-card-head">
                <h2 class="ap-card-title"><i class="bi bi-person-vcard"></i> {{ get_phrase('Personal Information') }}</h2>
                @if($admission->isEditableByApplicant())
                    <a href="{{ route('applicant.application.step', 'personal') }}" style="color:var(--ap-primary); font-weight:600; font-size:14px;">
                        {{ get_phrase('Edit') }}
                    </a>
                @endif
            </div>

            <dl class="mb-0">
                <div class="ap-kv"><dt>{{ get_phrase('Full Name') }}</dt><dd>{{ $admission->full_name ?: $blank }}</dd></div>
                <div class="ap-kv"><dt>{{ get_phrase('Email') }}</dt><dd>{{ $admission->email ?: $blank }}</dd></div>
                <div class="ap-kv"><dt>{{ get_phrase('Phone') }}</dt><dd>{{ $admission->phone ?: $blank }}</dd></div>
                <div class="ap-kv"><dt>{{ get_phrase('Date of Birth') }}</dt><dd>{{ optional($admission->dob)->format('d M Y') ?: $blank }}</dd></div>
                <div class="ap-kv"><dt>{{ get_phrase('Gender') }}</dt><dd>{{ $admission->gender ?: $blank }}</dd></div>
                <div class="ap-kv"><dt>{{ get_phrase('Nationality') }}</dt><dd>{{ $admission->nationality ?: $blank }}</dd></div>
                <div class="ap-kv"><dt>{{ get_phrase('Address') }}</dt><dd>{{ $admission->physical_address ?: $blank }}</dd></div>
                <div class="ap-kv"><dt>{{ get_phrase('Next of Kin') }}</dt>
                    <dd>{{ $admission->nok_name ?: $blank }}@if($admission->nok_relationship) ({{ $admission->nok_relationship }})@endif</dd>
                </div>
                <div class="ap-kv"><dt>{{ get_phrase('Next of Kin Phone') }}</dt><dd>{{ $admission->nok_phone ?: $blank }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="ap-card h-100">
            <div class="ap-card-head">
                <h2 class="ap-card-title"><i class="bi bi-mortarboard"></i> {{ get_phrase('Programme Selection') }}</h2>
                @if($admission->isEditableByApplicant())
                    <a href="{{ route('applicant.application.step', 'programme') }}" style="color:var(--ap-primary); font-weight:600; font-size:14px;">
                        {{ get_phrase('Edit') }}
                    </a>
                @endif
            </div>

            <dl class="mb-0">
                <div class="ap-kv"><dt>{{ get_phrase('First Choice') }}</dt><dd>{{ optional($admission->programme)->name ?: $blank }}</dd></div>
                <div class="ap-kv"><dt>{{ get_phrase('Second Choice') }}</dt><dd>{{ optional($admission->secondChoiceProgramme)->name ?: $blank }}</dd></div>
                <div class="ap-kv"><dt>{{ get_phrase('Intake') }}</dt><dd>{{ optional($admission->intakeSession)->name ?: $blank }}</dd></div>
                <div class="ap-kv"><dt>{{ get_phrase('Study Mode') }}</dt><dd>{{ $admission->study_mode ?: $blank }}</dd></div>
                <div class="ap-kv"><dt>{{ get_phrase('Sponsorship') }}</dt><dd>{{ $admission->sponsor_type ?: $blank }}</dd></div>
                @if($admission->sponsor_name)
                    <div class="ap-kv"><dt>{{ get_phrase('Sponsor') }}</dt><dd>{{ $admission->sponsor_name }}</dd></div>
                @endif
                @isset($feeAmount)
                    <div class="ap-kv">
                        <dt>{{ get_phrase('Application Fee') }}</dt>
                        <dd>
                            {{ $feeAmount > 0 ? ApplicationFee::format((float) $feeAmount, $admission) : get_phrase('Not applicable') }}
                            <span class="ap-pill bg-{{ $admission->isFeeSettled() ? 'success' : 'warning' }} bg-opacity-10 text-{{ $admission->isFeeSettled() ? 'success' : 'warning' }} ms-1">
                                {{ get_phrase(ucfirst($admission->fee_status)) }}
                            </span>
                        </dd>
                    </div>
                @endisset
            </dl>
        </div>
    </div>
</div>

<div class="ap-card mt-4">
    <div class="ap-card-head">
        <h2 class="ap-card-title"><i class="bi bi-journal-text"></i> {{ get_phrase('Education History') }}</h2>
        @if($admission->isEditableByApplicant())
            <a href="{{ route('applicant.application.step', 'education') }}" style="color:var(--ap-primary); font-weight:600; font-size:14px;">
                {{ get_phrase('Edit') }}
            </a>
        @endif
    </div>

    @if($admission->educationHistory->isNotEmpty())
        <div class="table-responsive">
            <table class="table align-middle mb-0" style="font-size:14px;">
                <thead>
                    <tr style="color:var(--ap-muted); font-size:12.5px; text-transform:uppercase;">
                        <th>{{ get_phrase('Institution') }}</th>
                        <th>{{ get_phrase('Award') }}</th>
                        <th>{{ get_phrase('Subject') }}</th>
                        <th>{{ get_phrase('Grade') }}</th>
                        <th>{{ get_phrase('Years') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($admission->educationHistory as $qualification)
                        <tr>
                            <td>{{ $qualification->institution }}</td>
                            <td>{{ $qualification->award ?: $blank }}</td>
                            <td>{{ $qualification->subject ?: $blank }}</td>
                            <td>{{ $qualification->grade ?: $blank }}</td>
                            <td>{{ $qualification->period ?: $blank }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($admission->qualifications)
        <div class="mt-3 p-3" style="background:#f9fafb; border-radius:9px; font-size:14px; white-space:pre-line;">{{ $admission->qualifications }}</div>
    @endif

    @if($admission->educationHistory->isEmpty() && ! $admission->qualifications)
        <div class="ap-empty"><i class="bi bi-journal"></i><p class="mb-0">{{ get_phrase('No education history recorded yet.') }}</p></div>
    @endif
</div>

@isset($docChecklist)
    <div class="ap-card mt-4">
        <div class="ap-card-head">
            <h2 class="ap-card-title"><i class="bi bi-folder2-open"></i> {{ get_phrase('Supporting Documents') }}</h2>
            @if($admission->isEditableByApplicant())
                <a href="{{ route('applicant.documents') }}" style="color:var(--ap-primary); font-weight:600; font-size:14px;">
                    {{ get_phrase('Manage') }}
                </a>
            @endif
        </div>

        @foreach($docChecklist as $row)
            <div class="d-flex align-items-center gap-3 py-2" style="border-bottom:1px solid #f1f2f4;">
                @php
                    $tone = ['verified' => 'success', 'pending' => 'primary', 'rejected' => 'danger', 'missing' => 'warning'][$row['state']] ?? 'secondary';
                @endphp
                <i class="bi {{ $row['files']->isNotEmpty() ? 'bi-file-earmark-check' : 'bi-file-earmark-x' }}" style="font-size:18px; color:var(--ap-muted);"></i>
                <div class="flex-grow-1" style="font-size:14.5px; font-weight:600;">{{ $row['requirement']->label }}</div>
                <div style="color:var(--ap-muted); font-size:13px;">{{ $row['files']->count() }} {{ get_phrase('file(s)') }}</div>
                <span class="ap-pill bg-{{ $tone }} bg-opacity-10 text-{{ $tone }}">{{ get_phrase(ucfirst(str_replace('-', ' ', $row['state']))) }}</span>
            </div>
        @endforeach
    </div>
@endisset
