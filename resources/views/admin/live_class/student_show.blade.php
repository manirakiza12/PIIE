@extends('student.navigation')
@section('content')
@php
    /**
     * The student's read-only Live Class page.
     *
     * Reached from a notification's "View Live Class" action. It answers "what
     * is this class and when does it run" and offers Join as a SECOND,
     * deliberate action that is only enabled inside the governed join window.
     *
     * Nothing provider-internal is rendered here: no meeting URL, no meeting
     * ID, no meeting password, no recording token. The platform is named, not
     * linked, and the actual link is reached only through the authorised join
     * action, which re-checks access at that moment.
     *
     * The academic context is DERIVED from the Course Offering - never stored on
     * live_classes - so a class can never disagree with the Offering it belongs
     * to.
     */
    $offering = $liveClass->courseOffering;
    $computed = $liveClass->computed_status;
    $statusClass = match ($computed) {
        'live' => 'danger',
        'scheduled', 'ready', 'upcoming' => 'warning',
        'cancelled' => 'secondary',
        'ended' => 'success',
        // Never green and never "completed": this class has no confirmed outcome.
        'not_concluded' => 'dark',
        default => 'dark',
    };

    // Every time on this page comes from the ONE stored instant, rendered in
    // this viewer's own clock. The start_time/end_time COLUMNS are deliberately
    // not read: they hold the wall clock as typed at scheduling time, in
    // whatever zone the scheduler was in, so rendering them beside an instant
    // converted to the reader's zone put two different numbers for the same
    // moment on the same page. They are a record of intent, not a display value.
    $display = app(App\Support\LiveClasses\LiveClassDisplay::class)->for($liveClass, auth()->user());
    $platform = app(App\Support\LiveClasses\LiveClassPlatform::class)
        ->describe($liveClass, (bool) ($lifecycle['canHost'] ?? false));
@endphp

<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-start flex-wrap gr-15">
        <div>
            <h4>{{ $liveClass->title }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="{{ route('student.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                <li><a href="{{ route('student.live_classes.index') }}">{{ get_phrase('My Live Classes') }}</a></li>
                <li><span>{{ $liveClass->title }}</span></li>
            </ul>
        </div>
        <a href="{{ route('student.live_classes.index') }}" class="eBtn eBtn-dark">{{ get_phrase('Back to My Live Classes') }}</a>
    </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning">{{ session('error') }}</div>@endif

<div class="row g-3">
    <div class="col-12 col-lg-8">

        <div class="eSection-wrap mb-3">
            <h6 class="text-uppercase small fw-bold text-primary mb-3">{{ get_phrase('This Live Class') }}</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <strong>{{ get_phrase('Course Unit') }}:</strong>
                    {{ $offering
                        ? ($offering->reference ? $offering->reference.' — ' : '').(optional($liveClass->subject)->name ?: get_phrase('Course Unit'))
                        : (optional($liveClass->subject)->name ?: '—') }}
                </div>
                <div class="col-md-6"><strong>{{ get_phrase('Lecturer') }}:</strong> {{ optional($liveClass->teacher)->name ?: '—' }}</div>
                <div class="col-md-6">
                    <strong>{{ get_phrase('Date') }}:</strong>
                    {{ optional($liveClass->start_date)->format('d M Y') ?: '—' }}
                </div>
                <div class="col-md-6">
                    <strong>{{ get_phrase('Time') }}:</strong>
                    {{ $display->timeRange() }}
                    @if($liveClass->duration_minutes !== null)
                        <span class="text-muted">({{ $liveClass->duration_minutes }} {{ get_phrase('min') }})</span>
                    @endif
                    <div class="small text-muted">{{ $display->zoneNote() }}</div>
                </div>
                <div class="col-md-6">
                    <strong>{{ get_phrase('Platform') }}:</strong>
                    {{ $platform['label'] }}
                    <div class="small text-muted">{{ $platform['note'] }}</div>
                </div>
                <div class="col-md-6">
                    <strong>{{ get_phrase('Status') }}:</strong>
                    <span class="badge bg-{{ $statusClass }}">{{ ucfirst($computed) }}</span>
                </div>
                <div class="col-12">
                    <strong>{{ get_phrase('Description / Agenda') }}:</strong><br>
                    {{ $liveClass->description ?: '—' }}
                </div>
            </div>
        </div>

        @if($isOfferingBacked && $offering)
            <div class="eSection-wrap">
                <h6 class="text-uppercase small fw-bold text-primary mb-3">{{ get_phrase('Course Context') }}</h6>
                <p class="mb-1">
                    {{ optional($offering->academicYear)->label ?: '—' }}
                    @if($offering->academicPeriod)
                        &middot; {{ $offering->academicPeriod->label }}
                    @endif
                </p>
                @if($registeredCount !== null)
                    <p class="text-muted small mb-0">
                        {{ $registeredCount }}
                        {{ $registeredCount === 1 ? get_phrase('registered student') : get_phrase('registered students') }}
                    </p>
                @endif
            </div>
        @endif

        {{-- Cancellation is retained history, not a withdrawal. This page used
             to 404 for a cancelled class, so the notification that told a
             student it was cancelled led to a page saying the class did not
             exist. Cancellation is a fact ABOUT the class, so the class stays
             readable and the fact is stated plainly - with no Join action,
             because cancelled means it will not run. --}}
        @if($computed === 'cancelled')
            <div class="eSection-wrap border-danger">
                <h6 class="text-uppercase small fw-bold text-danger mb-3">{{ get_phrase('This Live Class was cancelled') }}</h6>
                <p class="mb-2">{{ $joinMessage }}</p>
                @if($liveClass->cancelled_at)
                    <p class="small text-muted mb-0">
                        {{ get_phrase('Cancelled on') }}
                        {{ $display->cancelledAt() }}
                        @if($cancelledByName)
                            {{ get_phrase('by') }} {{ $cancelledByName }}
                        @endif
                    </p>
                @else
                    {{-- Honest about the gap rather than quietly filling it: this
                         class predates the cancellation-evidence columns, and a
                         made-up timestamp would be a fabricated academic record. --}}
                    <p class="small text-muted mb-0">{{ get_phrase('The exact cancellation time was not recorded for this class.') }}</p>
                @endif
                <p class="small text-muted mb-0 mt-2">{{ get_phrase('The class details above are kept for the record. There is nothing to join.') }}</p>
            </div>
        @endif

    <div class="col-12 col-lg-4">
        <div class="eSection-wrap">
            {{-- One state, one panel. Previously this showed a live-looking
                 "Join Live Class" button while the text beneath said joining had
                 not opened, so pressing it appeared to do nothing. --}}
            @php
                $state = $lifecycle['state'];
                $stateBadge = [
                    'upcoming' => 'info',
                    'ready' => 'primary',
                    'live' => 'danger',
                    'completed' => 'success',
                    'cancelled' => 'secondary',
                    'draft' => 'dark',
                ][$state] ?? 'secondary';
            @endphp

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-uppercase small fw-bold text-primary mb-0">{{ get_phrase('Live Class') }}</h6>
                <span class="badge bg-{{ $stateBadge }}">{{ $lifecycle['label'] }}</span>
            </div>

            @if($state === 'live')
                <div class="alert alert-danger py-2 mb-2">
                    <span class="live-pulse-dot" style="background:#dc3545;margin-right:6px;"></span>
                    <strong>{{ get_phrase('LIVE NOW') }}</strong>
                </div>
            @endif

            @if($canJoin)
                <a href="{{ route('student.live_classes.join', $liveClass->id) }}" target="_blank"
                   class="eBtn w-100 mb-2" style="background:#16a34a;border-color:#16a34a;color:#fff;font-weight:600;">
                    <i class="bi bi-camera-video-fill"></i> {{ get_phrase('Join Live Class') }}
                </a>
            @elseif($state === 'upcoming' || $state === 'ready')
                {{-- A disabled control must still answer a click rather than
                     silently ignoring it. This is a real button that explains
                     itself, not a dead <a> or a dead disabled attribute. --}}
                <button type="button" class="eBtn eBtn-dark w-100 mb-2" data-join-explains
                        aria-expanded="false" aria-controls="joinWhy">
                    <i class="bi bi-clock"></i>
                    {{ str_replace(':time', $lifecycle['joinOpensAt'] ? $lifecycle['joinOpensAt']->format('g:i A') : '—', get_phrase('Join opens at :time')) }}
                </button>
                <div id="joinWhy" class="alert alert-info py-2 px-3 mb-2 small d-none">
                    {{ $joinMessage }}
                    @if($countdown)
                        <div class="mt-1 fw-semibold">{{ $countdown }}</div>
                    @endif
                </div>
            @elseif($state === 'completed')
                <div class="alert alert-success py-2 mb-2 small">
                    <i class="bi bi-check-circle"></i> {{ get_phrase('Class Completed') }}
                </div>
                {{-- No second, raw-URL-gated Watch button here. It was keyed on
                     the recording_url column, so a class whose recording was only
                     "awaiting" still showed a Watch action with nothing playable
                     behind it. The Recording panel below is the single place, and
                     it is gated on the state. --}}
            @elseif($state === 'not_concluded')
                {{-- Never a success badge: nothing was confirmed. --}}
                <div class="alert alert-dark py-2 mb-2 small">
                    <i class="bi bi-question-circle"></i> {{ get_phrase('Ended without confirmation') }}
                </div>
            @elseif($state === 'cancelled')
                <div class="alert alert-secondary py-2 mb-2 small">
                    <i class="bi bi-x-circle"></i> {{ get_phrase('Class Cancelled') }}
                </div>
            @endif

            <p class="text-muted small mb-0">{{ $joinMessage }}</p>
            @if($countdown && $canJoin)
                <p class="text-muted small mb-0"><strong>{{ $countdown }}</strong></p>
            @endif

            <hr class="my-3">
            <p class="text-muted small mb-0">
                {{ get_phrase('Joining opens 15 minutes before the class starts and closes 15 minutes after it ends. The meeting link is released only when you join.') }}
            </p>
        </div>

        {{-- What the provider can actually do, said plainly. PIIE authorises WHO
             may open the room; it cannot decide who is a moderator inside the
             provider, and on a public Jitsi room it is nobody's moderator. The
             old wording implied PIIE had arranged both, which is how a lecturer
             ended up believing they controlled a room they could not silence. --}}
        @if(! empty($platform['limitations']))
            <div class="eSection-wrap">
                <h6 class="text-uppercase small fw-bold text-primary mb-3">{{ get_phrase('About this meeting provider') }}</h6>
                <ul class="small text-muted mb-0 ps-3">
                    @foreach($platform['limitations'] as $limitation)
                        <li>{{ $limitation }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- The recording state, STATED rather than implied.

             A class being taught says nothing about a recording: neither Jitsi
             nor Google Meet hands this system a finished file over any API it can
             call, so "nobody turned recording on", "it is still processing" and
             "it failed" are three different facts - and all three used to render
             as one missing link, which made an unrecorded completed class look
             broken rather than unrecorded. --}}
        <div class="eSection-wrap">
            <h6 class="text-uppercase small fw-bold text-primary mb-3">{{ get_phrase('Recording') }}</h6>
            @php($recordingState = $liveClass->recordingState())
            @if($recordingState === \App\Models\LiveClass::RECORDING_AVAILABLE)
                <a href="{{ route('live_classes.recording.access', ['liveClass' => $liveClass->id]) }}"
                   class="eBtn eBtn-primary" target="_blank" rel="noopener">
                    {{ get_phrase('Watch Recording') }}
                </a>
            @elseif($recordingState === \App\Models\LiveClass::RECORDING_PROCESSING)
                <p class="mb-0">{{ $liveClass->recordingStateLabel() }}. {{ get_phrase('You will be notified when it is ready.') }}</p>
            @elseif($recordingState === \App\Models\LiveClass::RECORDING_UNAVAILABLE)
                <p class="mb-0">{{ $liveClass->recordingStateLabel() }}. {{ get_phrase('Ask your lecturer if you need a copy.') }}</p>
            @else
                <p class="mb-0 text-muted">
                    {{ get_phrase('No recording') }}.
                    @if($computed !== 'ended')
                        {{ get_phrase('A recording is only published after the class has been completed.') }}
                    @endif
                </p>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // A disabled-looking control that still answers a click. Without this the
    // student presses "Join opens at ..." and nothing happens, which is exactly
    // the confusion this panel replaced.
    document.querySelectorAll('[data-join-explains]').forEach(function (button) {
        button.addEventListener('click', function () {
            var panel = document.getElementById(button.getAttribute('aria-controls'));
            if (!panel) return;
            var hidden = panel.classList.toggle('d-none');
            button.setAttribute('aria-expanded', hidden ? 'false' : 'true');
        });
    });
})();
</script>
@endpush

@if($lifecycle['state'] === 'live')
    <style>
        .live-pulse-dot { display:inline-block; width:8px; height:8px; border-radius:50%; animation:liveClassPulse 1.4s infinite; }
        @keyframes liveClassPulse {
            0% { box-shadow: 0 0 0 0 rgba(220,53,69,.7); }
            70% { box-shadow: 0 0 0 6px rgba(220,53,69,0); }
            100% { box-shadow: 0 0 0 0 rgba(220,53,69,0); }
        }
    </style>
@endif
