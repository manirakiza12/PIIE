@extends('admin.navigation')
@section('content')

@include('admin.admissions.wizard._layout_top')

@php $blank = '—'; @endphp

@if($admission->submitted_at)
    <div class="alert alert-success">
        <strong>{{ get_phrase('This application was submitted on') }} {{ $admission->submitted_at->format('d M Y, H:i') }}.</strong>
        {{ get_phrase('It is now in the admissions queue.') }}
        <a href="{{ route('admin.hei_admissions.review', $admission->id) }}">{{ get_phrase('Go to the review screen') }}</a>
    </div>
@elseif(! $canSubmit)
    <div class="alert alert-warning">
        <strong class="d-block mb-2">{{ get_phrase('A few things still need attention before this can be submitted') }}:</strong>
        <ul class="mb-0">
            @foreach($blockers as $blocker)
                <li>{{ $blocker }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-6">
        <div class="eSection-wrap h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-person-vcard"></i> {{ get_phrase('Personal Information') }}</h6>
                @unless($readOnly)
                    <a href="{{ route('admin.hei_admissions.wizard.step', [$admission->id, 'personal']) }}">{{ get_phrase('Edit') }}</a>
                @endunless
            </div>
            <table class="table table-sm mb-0">
                <tr><th>{{ get_phrase('Full Name') }}</th><td>{{ $admission->full_name ?: $blank }}</td></tr>
                <tr><th>{{ get_phrase('Email') }}</th><td>{{ $admission->email ?: $blank }}</td></tr>
                <tr><th>{{ get_phrase('Phone') }}</th><td>{{ $admission->phone ?: $blank }}</td></tr>
                <tr><th>{{ get_phrase('Date of Birth') }}</th><td>{{ optional($admission->dob)->format('d M Y') ?: $blank }}</td></tr>
                <tr><th>{{ get_phrase('Gender') }}</th><td>{{ $admission->gender ?: $blank }}</td></tr>
                <tr><th>{{ get_phrase('Nationality') }}</th><td>{{ $admission->nationality ?: $blank }}</td></tr>
                <tr><th>{{ get_phrase('Address') }}</th><td>{{ $admission->physical_address ?: $blank }}</td></tr>
                <tr><th>{{ get_phrase('Next of Kin') }}</th><td>{{ $admission->nok_name ?: $blank }}@if($admission->nok_relationship) ({{ $admission->nok_relationship }})@endif</td></tr>
            </table>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="eSection-wrap h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-mortarboard"></i> {{ get_phrase('Programme Selection') }}</h6>
                @unless($readOnly)
                    <a href="{{ route('admin.hei_admissions.wizard.step', [$admission->id, 'programme']) }}">{{ get_phrase('Edit') }}</a>
                @endunless
            </div>
            <table class="table table-sm mb-0">
                <tr><th>{{ get_phrase('First Choice') }}</th><td>{{ optional($admission->programme)->name ?: $blank }}</td></tr>
                <tr><th>{{ get_phrase('Second Choice') }}</th><td>{{ optional($admission->secondChoiceProgramme)->name ?: $blank }}</td></tr>
                <tr><th>{{ get_phrase('Intake') }}</th><td>{{ optional($admission->intakeSession)->name ?: $blank }}</td></tr>
                <tr><th>{{ get_phrase('Study Mode') }}</th><td>{{ $admission->study_mode ?: $blank }}</td></tr>
                <tr><th>{{ get_phrase('Sponsorship') }}</th><td>{{ $admission->sponsor_type ?: $blank }}</td></tr>
            </table>
        </div>
    </div>
</div>

<div class="eSection-wrap mt-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0"><i class="bi bi-journal-text"></i> {{ get_phrase('Education History') }}</h6>
        @unless($readOnly)
            <a href="{{ route('admin.hei_admissions.wizard.step', [$admission->id, 'education']) }}">{{ get_phrase('Edit') }}</a>
        @endunless
    </div>

    @if($admission->educationHistory->isNotEmpty())
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>{{ get_phrase('Institution') }}</th><th>{{ get_phrase('Award') }}</th><th>{{ get_phrase('Subject') }}</th><th>{{ get_phrase('Grade') }}</th><th>{{ get_phrase('Years') }}</th></tr></thead>
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
    @elseif($admission->qualifications)
        <p class="mb-0" style="white-space:pre-line;">{{ $admission->qualifications }}</p>
    @else
        <p class="text-muted mb-0">{{ get_phrase('No education history recorded yet.') }}</p>
    @endif
</div>

<div class="eSection-wrap mt-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0"><i class="bi bi-folder2-open"></i> {{ get_phrase('Supporting Documents') }}</h6>
        @unless($readOnly)
            <a href="{{ route('admin.hei_admissions.wizard.step', [$admission->id, 'documents']) }}">{{ get_phrase('Manage') }}</a>
        @endunless
    </div>

    @forelse(\App\Support\Admissions\ApplicationDocuments::checklist($admission) as $row)
        @php $tone = ['verified' => 'success', 'pending' => 'primary', 'rejected' => 'danger', 'missing' => 'warning'][$row['state']] ?? 'secondary'; @endphp
        <div class="d-flex align-items-center gap-3 py-2" style="border-bottom:1px solid #f1f2f4;">
            <div class="flex-grow-1">{{ $row['requirement']->label }}</div>
            <div class="text-muted" style="font-size:13px;">{{ $row['files']->count() }} {{ get_phrase('file(s)') }}</div>
            <span class="badge bg-{{ $tone }}">{{ ucfirst(str_replace('-', ' ', $row['state'])) }}</span>
        </div>
    @empty
        <p class="text-muted mb-0">{{ get_phrase('No documents are required for this application.') }}</p>
    @endforelse
</div>

@if($feeAmount > 0)
    <div class="eSection-wrap mt-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h6 class="mb-0"><i class="bi bi-credit-card"></i> {{ get_phrase('Application Fee') }}</h6>
            <span class="badge {{ $admission->isFeeSettled() ? 'bg-success' : ($admission->fee_status === 'pending' ? 'bg-info text-dark' : 'bg-warning text-dark') }}">
                {{ ucfirst($admission->fee_status) }}
            </span>
        </div>
        <div class="row g-3 mb-2">
            <div class="col-6 col-md-3">
                <small class="text-muted d-block">{{ get_phrase('Application Fee') }}</small>
                <strong>{{ \App\Support\Admissions\ApplicationFee::format($feeAmount, $admission) }}</strong>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted d-block">{{ get_phrase('Payment Reference') }}</small>
                <strong>{{ $admission->app_number }}</strong>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted d-block">{{ get_phrase('Amount Paid') }}</small>
                <strong>{{ \App\Support\Admissions\ApplicationFee::format($feePaid, $admission) }}</strong>
            </div>
            <div class="col-6 col-md-3">
                <small class="text-muted d-block">{{ get_phrase('Outstanding') }}</small>
                <strong class="{{ $feeOutstanding > 0 ? 'text-danger' : 'text-success' }}">{{ \App\Support\Admissions\ApplicationFee::format($feeOutstanding, $admission) }}</strong>
            </div>
        </div>
        @unless($admission->isFeeSettled())
            <p class="text-muted mb-2" style="font-size:13px;">
                {{ get_phrase('The candidate pays this themselves from their own applicant portal — sending a request gives them a link to set up access and pay, the same way an online applicant would.') }}
            </p>
            <form action="{{ route('admin.hei_admissions.payment.request', $admission->id) }}" method="POST">
                @csrf
                <button type="submit" class="eBtn eBtn-sm eBtn-primary">
                    <i class="bi bi-envelope"></i>
                    {{ $admission->applicant_id ? get_phrase('Resend Payment Instructions') : get_phrase('Send Payment Request') }}
                </button>
            </form>
        @endunless
    </div>
@endif

@unless($readOnly)
    <div class="eSection-wrap mt-3">
        <h6 class="mb-3"><i class="bi bi-send-check"></i> {{ get_phrase('Submit to Admissions Queue') }}</h6>
        <p class="text-muted" style="font-size:13.5px;">
            {{ get_phrase('Submitting sends this application into the same review queue as online applications — accept/reject and academic assignment happen from there.') }}
        </p>
        <form action="{{ route('admin.hei_admissions.wizard.submit', $admission->id) }}" method="POST">
            @csrf
            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <a href="{{ route('admin.hei_admissions.wizard.step', [$admission->id, 'personal']) }}" class="eBtn eBtn-outline-blackish">
                    {{ get_phrase('Keep Editing') }}
                </a>
                <button type="submit" class="eBtn eBtn-primary" {{ $canSubmit ? '' : 'disabled' }}>
                    {{ $admission->status === \App\Models\Admission::STATUS_NEEDS_CORRECTION ? get_phrase('Resubmit Application') : get_phrase('Submit Application') }}
                </button>
            </div>
        </form>
    </div>
@endunless
@endsection
