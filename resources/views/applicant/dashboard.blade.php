@extends('applicant.layout')

@section('title', get_phrase('Dashboard'))
@section('subtitle', get_phrase('Track your application progress and manage your admission process'))

@section('content')

{{--
    The one place the applicant is told "the admissions office just did
    something" — reusing ApplicationWorkflow::titleFor() so this headline can
    never say something different from what the same status shows in the
    Track My Application timeline. Always reflects the current status rather
    than a dismissible/stored notification (there's no read/unread state to
    track), so it simply disappears once the applicant moves past that status.
--}}
@php
    $statusBannerStyle = [
        \App\Models\Admission::STATUS_NEEDS_CORRECTION => ['warning', 'bi-pencil-square', '#b54708'],
        \App\Models\Admission::STATUS_ACCEPTED         => ['success', 'bi-check-circle-fill', '#0f6e3d'],
        \App\Models\Admission::STATUS_REJECTED         => ['secondary', 'bi-info-circle-fill', '#344054'],
        \App\Models\Admission::STATUS_ENROLLED         => ['success', 'bi-mortarboard-fill', '#0f6e3d'],
        \App\Models\Admission::STATUS_UNDER_REVIEW     => ['info', 'bi-hourglass-split', '#0c5c8a'],
    ][$admission->status] ?? null;
@endphp

@if($statusBannerStyle)
    @php
        $bannerVariant = $statusBannerStyle[0];
        $bannerIcon    = $statusBannerStyle[1];
        $bannerColor   = $statusBannerStyle[2];
    @endphp
    <div class="alert alert-{{ $bannerVariant }} d-flex align-items-start gap-3">
        <i class="bi {{ $bannerIcon }} fs-4"></i>
        <div>
            <strong>{{ \App\Support\Admissions\ApplicationWorkflow::titleFor($admission->status) }}</strong>

            @if($admission->status === \App\Models\Admission::STATUS_NEEDS_CORRECTION)
                @if($admission->correction_note)
                    <p class="mb-2 mt-1">{{ $admission->correction_note }}</p>
                @endif
                <a href="{{ route('applicant.application') }}" class="fw-bold" style="color: {{ $bannerColor }};">
                    {{ get_phrase('Make the changes and resubmit') }} <i class="bi bi-arrow-right"></i>
                </a>
            @else
                @if($admission->decision_note)
                    <p class="mb-2 mt-1">{{ $admission->decision_note }}</p>
                @endif

                @if($admission->status === \App\Models\Admission::STATUS_ACCEPTED)
                    <a href="{{ route('applicant.offer_letter') }}" class="fw-bold" style="color: {{ $bannerColor }};">
                        {{ get_phrase('Download Offer Letter') }} <i class="bi bi-download"></i>
                    </a>
                @elseif($admission->status === \App\Models\Admission::STATUS_ENROLLED)
                    <p class="mb-0" style="font-size:14px; color:var(--ap-muted);">
                        {{ get_phrase('Check your email for your student portal login details.') }}
                    </p>
                @else
                    <a href="{{ route('applicant.track') }}" class="fw-bold" style="color: {{ $bannerColor }};">
                        {{ get_phrase('Track My Application') }} <i class="bi bi-arrow-right"></i>
                    </a>
                @endif
            @endif
        </div>
    </div>
@endif

<div class="row g-4">
    {{-- Application status + progress --}}
    <div class="col-xl-8">
        <div class="ap-card h-100">
            <div class="d-flex align-items-start gap-3 mb-4">
                <div class="ap-icon-tile ap-tile-rose"><i class="bi bi-clipboard-check"></i></div>
                <div>
                    <h2 class="ap-card-title mb-1">{{ get_phrase('Application Status') }}</h2>
                    <div style="color:var(--ap-muted); font-size:14px; letter-spacing:.02em;">{{ $admission->app_number }}</div>
                </div>
                <span class="ap-pill ms-auto bg-{{ $admission->statusColor() }} bg-opacity-10 text-{{ $admission->statusColor() }}">
                    <i class="bi bi-clock-history"></i> {{ $admission->statusLabel() }}
                </span>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <span style="font-weight:600; font-size:14.5px;">{{ get_phrase('Application Progress') }}</span>
                <span style="color:var(--ap-muted); font-size:14px;">
                    {{ $completed }}/{{ $total }} {{ get_phrase('steps completed') }}
                </span>
            </div>

            <div class="ap-progress mb-2">
                <div class="ap-progress-bar" style="width: {{ $percent }}%"></div>
            </div>

            <div style="color:var(--ap-muted); font-size:14px;">{{ $percent }}% {{ get_phrase('complete') }}</div>

            <div class="row g-2 mt-4">
                @foreach($steps as $stepItem)
                    <div class="col-auto">
                        <a href="{{ $stepItem['url'] }}" class="ap-step {{ $stepItem['complete'] ? 'done' : '' }}">
                            <span class="ap-step-num">
                                @if($stepItem['complete'])<i class="bi bi-check"></i>@else{{ $loop->iteration }}@endif
                            </span>
                            {{ $stepItem['label'] }}
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Next step --}}
    <div class="col-xl-4">
        <div class="ap-card h-100 d-flex flex-column">
            <div class="d-flex align-items-start gap-3">
                <div class="ap-icon-tile ap-tile-green">
                    <i class="bi {{ $nextStep ? 'bi-plus-lg' : 'bi-check2-all' }}"></i>
                </div>
                <div>
                    <h2 class="ap-card-title mb-1">{{ $nextStep ? get_phrase('Next Step') : get_phrase('All done') }}</h2>
                    <div style="color:var(--ap-muted); font-size:14px;">
                        {{ $nextStep ? $nextStep['label'] : get_phrase('Your application is with the admissions office.') }}
                    </div>
                </div>
            </div>

            <div class="mt-auto pt-4">
                @if($nextStep)
                    <a href="{{ $nextStep['url'] }}" class="ap-btn ap-btn-accent w-100">
                        {{ $admission->submitted_at ? get_phrase('Update Application') : get_phrase('Continue Application') }}
                        <i class="bi bi-arrow-right"></i>
                    </a>
                @elseif(in_array($admission->status, ['accepted', 'enrolled']))
                    <a href="{{ route('applicant.offer_letter') }}" class="ap-btn ap-btn-accent w-100">
                        <i class="bi bi-download"></i> {{ get_phrase('Download Offer Letter') }}
                    </a>
                @else
                    <a href="{{ route('applicant.track') }}" class="ap-btn ap-btn-ghost w-100">
                        <i class="bi bi-activity"></i> {{ get_phrase('Track My Application') }}
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Quick actions --}}
<div class="row g-4 mt-1">
    <div class="col-md-4">
        <a href="{{ route('applicant.application') }}" class="ap-quick">
            <div class="ap-icon-tile ap-tile-rose"><i class="bi bi-file-earmark-text"></i></div>
            <div>
                <p class="ap-quick-title">{{ get_phrase('My Application') }}</p>
                <p class="ap-quick-sub">{{ $admission->submitted_at ? get_phrase('View submitted application') : get_phrase('Continue Application') }}</p>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="{{ route('applicant.documents') }}" class="ap-quick">
            <div class="ap-icon-tile ap-tile-green"><i class="bi bi-folder2-open"></i></div>
            <div>
                <p class="ap-quick-title">{{ get_phrase('Documents') }}</p>
                <p class="ap-quick-sub">{{ $docCount }} {{ get_phrase('documents uploaded') }}</p>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        @if($feeAmount > 0)
            <a href="{{ route('applicant.payment') }}" class="ap-quick">
                <div class="ap-icon-tile ap-tile-amber"><i class="bi bi-credit-card"></i></div>
                <div>
                    <p class="ap-quick-title">{{ get_phrase('Application Fee') }}</p>
                    <p class="ap-quick-sub">
                        {{ \App\Support\Admissions\ApplicationFee::format($feeAmount, $admission) }} —
                        {{ ucfirst($admission->fee_status) }}
                    </p>
                </div>
            </a>
        @else
            <a href="{{ route('applicant.track') }}" class="ap-quick">
                <div class="ap-icon-tile ap-tile-blue"><i class="bi bi-headset"></i></div>
                <div>
                    <p class="ap-quick-title">{{ get_phrase('Track My Application') }}</p>
                    <p class="ap-quick-sub">{{ get_phrase('See every update in one place') }}</p>
                </div>
            </a>
        @endif
    </div>
</div>

{{-- Checklist + recent documents --}}
<div class="row g-4 mt-1">
    <div class="col-lg-7">
        <div class="ap-card h-100">
            <div class="ap-card-head">
                <h2 class="ap-card-title">
                    <i class="bi bi-check-circle-fill" style="color:var(--ap-accent);"></i>
                    {{ get_phrase('Application Checklist') }}
                </h2>
            </div>

            @foreach($checklist as $item)
                <a href="{{ $item['url'] }}" class="ap-check-row {{ $item['complete'] ? 'done' : '' }}">
                    <span class="ap-check-box"><i class="bi bi-check-lg"></i></span>
                    <span class="ap-check-label">{{ $item['label'] }}</span>
                    @unless($item['complete'])
                        <i class="bi bi-chevron-right ms-auto" style="color:var(--ap-muted);"></i>
                    @endunless
                </a>
            @endforeach
        </div>
    </div>

    <div class="col-lg-5">
        <div class="ap-card h-100">
            <div class="ap-card-head">
                <h2 class="ap-card-title">
                    <i class="bi bi-file-earmark-richtext"></i> {{ get_phrase('Recent Documents') }}
                </h2>
                <a href="{{ route('applicant.documents') }}" style="color:var(--ap-primary); font-weight:600; font-size:14px;">
                    {{ get_phrase('View All') }}
                </a>
            </div>

            @forelse($recentDocs as $doc)
                <div class="ap-file-chip">
                    <i class="bi {{ $doc->isImage() ? 'bi-file-image' : 'bi-file-earmark-pdf' }}" style="color:var(--ap-primary); font-size:18px;"></i>
                    <div class="flex-grow-1 min-width-0">
                        <div class="name">{{ $doc->label ?: $doc->original_name }}</div>
                        <div class="meta">{{ $doc->human_size }} · {{ $doc->created_at->format('d M Y') }}</div>
                    </div>
                    <span class="ap-pill bg-{{ $doc->status === 'verified' ? 'success' : ($doc->status === 'rejected' ? 'danger' : 'secondary') }} bg-opacity-10 text-{{ $doc->status === 'verified' ? 'success' : ($doc->status === 'rejected' ? 'danger' : 'secondary') }}">
                        {{ get_phrase(ucfirst($doc->status)) }}
                    </span>
                </div>
            @empty
                <div class="ap-empty">
                    <i class="bi bi-folder2"></i>
                    <p class="fw-bold mb-1" style="color:var(--ap-primary);">{{ get_phrase('No documents uploaded') }}</p>
                    <p class="mb-3">{{ get_phrase('Upload your first document to get started.') }}</p>
                    <a href="{{ route('applicant.documents') }}" class="fw-bold" style="color:var(--ap-primary);">
                        {{ get_phrase('Upload Documents') }} <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            @endforelse
        </div>
    </div>
</div>

@endsection
