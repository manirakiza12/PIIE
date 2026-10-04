@extends(request()->routeIs('teacher.*') ? 'teacher.navigation' : 'admin.navigation')
@section('content')
@php
    /**
     * The Course-Offering-driven scheduling form.
     *
     * Deliberately short. The lecturer chooses a title, a time, a provider and
     * one of two actions. Everything else - subject, programme, academic year,
     * period, lecturer role, registered students - is DERIVED from the Course
     * Offering in the URL and shown read-only, because a lecturer who could
     * also pick a "Course" or a "Session" would be able to build a class whose
     * academic identity contradicts the Offering it claims to belong to.
     *
     * Removed from this form, and why:
     *  - status dropdown  -> replaced by the two real intentions. A pre-filled
     *    internal state made a future class look "Live" the moment the form
     *    opened, which is meaningless to a lecturer and wrong about the class.
     *  - meeting_id / meeting_password -> provider-internal. The auto-created
     *    providers generate their own; the rest do not use them.
     *  - recording_url -> an after-class action. Publishing one notifies
     *    registered students through the recording event.
     *  - attendance_enabled -> the legacy K12 "record who pressed Join" habit.
     *    For an Offering-backed class it stays off: participation is evidence,
     *    and official Attendance is the certified Course Offering Attendance
     *    workflow, always lecturer-controlled. Joining never marks anyone
     *    present.
     */
    $routePrefix = request()->routeIs('teacher.*') ? 'teacher' : 'admin';
    $isOfferingScopedTeacher = $routePrefix === 'teacher';
    $selectedPlatform = old('platform', $liveClass->platform ?? $scheduling->defaultPlatform(auth()->user()));
    $currentPlatform = $platformOptions[$selectedPlatform] ?? null;
@endphp

<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-start flex-wrap gr-15">
        <div>
            <h4>{{ get_phrase('Schedule Live Class') }}</h4>
            <p class="text-muted mb-0">
                <a href="{{ $isOfferingScopedTeacher ? route('teacher.course_offerings.show', $offering->id) : route('admin.course_offerings.show', $offering->id) }}">{{ $offering->reference ?: get_phrase('Course Offering') }}</a>
            </p>
        </div>
        <a href="{{ route($routePrefix . '.live_classes.index') }}" class="eBtn eBtn-dark">{{ get_phrase('Back to Live Classes') }}</a>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <p class="mb-1 fw-semibold">{{ get_phrase('Please check the following and try again.') }}</p>
        <ul class="mb-0">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

{{--
    The ONE existing Google connection panel, reused rather than reimplemented.

    Placed OUTSIDE the scheduling <form> deliberately: the panel renders its own
    POST form for Disconnect, and a form nested inside a form is invalid HTML that
    browsers resolve unpredictably - the outer form's fields would be submitted by
    the inner button.

    Shown only when it is relevant: Google Meet is the selected platform, or the
    platform field itself failed validation. A lecturer scheduling Jitsi or Zoom
    has no Google question on this form, and an always-present panel would train
    people to ignore it. The partial additionally renders nothing at all unless
    the signed-in user is a lecturer, so an administrator scheduling on someone's
    behalf still gets no Connect control.
--}}
@if($selectedPlatform === 'google_meet' || $errors->has('platform'))
    @include('admin.live_class._google_connection')
@endif

<form method="POST" action="{{ $isOfferingScopedTeacher
        ? route('teacher.course_offerings.live_classes.store', $offering->id)
        : route('admin.course_offerings.live_classes.store', $offering->id) }}">
    @csrf

    <div class="row">
        <div class="col-12 col-xl-8">

            {{-- ── Course context: derived, never chosen ── --}}
            <div class="eSection-wrap mb-3">
                <h6 class="text-uppercase small fw-bold text-primary mb-3">{{ get_phrase('Course Context') }}</h6>
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h5 class="mb-1">
                            {{ $offering->reference ? $offering->reference.' — ' : '' }}
                            {{ optional($offering->subject)->name ?? get_phrase('Course Unit') }}
                        </h5>
                        <p class="text-muted mb-0">
                            @if($programmeNames){{ implode(', ', $programmeNames) }}@else{{ optional($offering->subject)->name ?? get_phrase('Course Unit') }}@endif
                            @if($offering->academicYear)&middot; {{ $offering->academicYear->label }}@endif
                            @if($offering->academicPeriod)&middot; {{ $offering->academicPeriod->label }}@endif
                            @if($stageNames)&middot; {{ implode(', ', $stageNames) }}@endif
                        </p>
                        <p class="text-muted small mb-0">
                            @if($roleLabel){{ get_phrase('You are facilitating as') }} {{ $roleLabel }}@endif
                            @if($studyPlanVersions)&middot; {{ get_phrase('Study Plan') }} {{ implode(', ', $studyPlanVersions) }}@endif
                        </p>
                        <p class="text-muted small mb-0">
                            {{ $registeredCount }} {{ $registeredCount === 1 ? get_phrase('registered student') : get_phrase('registered students') }}
                        </p>
                    </div>
                    @if($isOfferingScopedTeacher && $otherManageableOfferings > 0)
                        <a href="{{ route('teacher.live_classes.create') }}" class="eBtn eBtn-dark">{{ get_phrase('Change Course Offering') }}</a>
                    @endif
                </div>
            </div>

            {{-- ── Live Class details ── --}}
            <div class="eSection-wrap mb-3">
                <h6 class="text-uppercase small fw-bold text-primary mb-3">{{ get_phrase('Live Class Details') }}</h6>
                <div class="mb-3">
                    <label class="eForm-label" for="lcTitle">{{ get_phrase('Title') }} *</label>
                    <input type="text" class="form-control eForm-control @error('title') is-invalid @enderror"
                           id="lcTitle" name="title" maxlength="255" required
                           value="{{ old('title') }}"
                           placeholder="{{ get_phrase('e.g. Introduction Live Class') }}">
                    @error('title')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label class="eForm-label" for="lcDescription">{{ get_phrase('Description / Agenda') }}</label>
                    <textarea class="form-control eForm-control @error('description') is-invalid @enderror"
                              id="lcDescription" name="description" rows="3"
                              placeholder="{{ get_phrase('What will this class cover?') }}">{{ old('description') }}</textarea>
                    @error('description')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
            </div>

            {{-- ── Schedule ── --}}
            <div class="eSection-wrap mb-3">
                <h6 class="text-uppercase small fw-bold text-primary mb-3">{{ get_phrase('Schedule') }}</h6>
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="eForm-label" for="lcDate">{{ get_phrase('Date') }} *</label>
                        <input type="date" class="form-control eForm-control @error('start_date') is-invalid @enderror"
                               id="lcDate" name="start_date" required value="{{ old('start_date', now()->toDateString()) }}">
                        @error('start_date')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="eForm-label" for="lcStart">{{ get_phrase('Start Time') }} *</label>
                        <input type="time" class="form-control eForm-control @error('start_time') is-invalid @enderror"
                               id="lcStart" name="start_time" required value="{{ old('start_time', '10:00') }}">
                        @error('start_time')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="eForm-label" for="lcEnd">{{ get_phrase('End Time') }} *</label>
                        <input type="time" class="form-control eForm-control @error('end_time') is-invalid @enderror"
                               id="lcEnd" name="end_time" required value="{{ old('end_time', '11:00') }}">
                        @error('end_time')<span class="invalid-feedback">{{ $message }}</span>@enderror
                    </div>
                    <div class="col-12">
                        {{-- A lecturer may be in another country from the
                             institution. Both clocks are stated so the typed time
                             is never ambiguous, and the typed time is interpreted
                             in the lecturer's OWN clock. The institution's clock
                             is shown alongside for the same instant - one
                             meeting, not two. Read-only: nobody overrides the
                             institution's official reference here. --}}
                        <label class="eForm-label" for="lcTimezone">{{ get_phrase('Timezone') }}</label>
                        <input type="text" class="form-control eForm-control" id="lcTimezone" readonly
                               value="{{ $timezones['label'] }}">
                        <input type="hidden" name="timezone" value="{{ old('timezone', $inputTimezone) }}">
                        <small class="text-muted d-block mt-1">
                            {{-- Honesty first: if the institution has never
                                 chosen a clock, say so rather than let the
                                 platform default pass as an official
                                 academic time. --}}
                            @if(! $hasConfiguredTimezone)
                                {{ get_phrase('Your institution has not set a timezone yet, so the system default is being used. An administrator can set it in institution settings.') }}
                            @elseif($timezones['uses_personal'])
                                {{ get_phrase('You are entering times in your own timezone. The class is officially recorded in the institution\'s timezone.') }}
                            @else
                                {{ get_phrase('Times are shown in your institution\'s timezone.') }}
                            @endif
                        </small>
                        @if($timezones['uses_personal'])
                            <div class="alert alert-info py-2 px-3 small mt-2 mb-0">
                                <div>{{ get_phrase('Your timezone') }}: <strong>{{ $timezones['label'] }}</strong></div>
                                <div>{{ get_phrase('Institution timezone') }}: <strong>{{ $timezones['institution_label'] }}</strong></div>
                                <div class="mt-1" id="lcInstitutionEquivalent">
                                    {{ get_phrase('Institution time') }}: <strong data-role="institution-time">—</strong>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ── Meeting ── --}}
            <div class="eSection-wrap mb-3">
                <h6 class="text-uppercase small fw-bold text-primary mb-3">{{ get_phrase('Meeting') }}</h6>
                <div class="mb-3">
                    <label class="eForm-label" for="lcPlatform">{{ get_phrase('Platform') }} *</label>
                    <select class="form-select eForm-control @error('platform') is-invalid @enderror"
                            id="lcPlatform" name="platform" required>
                        @foreach($platformOptions as $key => $option)
                            <option value="{{ $key }}" @selected(old('platform', $selectedPlatform) === $key)>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @error('platform')<span class="invalid-feedback">{{ $message }}</span>@enderror
                </div>
                <div id="lcLinkRow" class="{{ $currentPlatform && ! $currentPlatform['auto_creates'] ? '' : 'd-none' }}">
                    <label class="eForm-label" for="lcMeetingUrl">{{ get_phrase('Meeting Link') }} *</label>
                    <input type="url" class="form-control eForm-control @error('meeting_url') is-invalid @enderror"
                           id="lcMeetingUrl" name="meeting_url" maxlength="500"
                           value="{{ old('meeting_url') }}" placeholder="https://">
                    <small class="text-muted d-block mt-1">{{ get_phrase('Paste the link your provider gave you. It must start with https://') }}</small>
                    @error('meeting_url')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                </div>
                <p id="lcAutoNote" class="text-muted small mb-0 {{ $currentPlatform && ! $currentPlatform['auto_creates'] ? 'd-none' : '' }}">
                    {{ $currentPlatform['note'] ?? '' }}
                </p>
            </div>
        </div>

        {{-- ── Publishing ── --}}
        <div class="col-12 col-xl-4">
            <div class="eSection-wrap">
                <h6 class="text-uppercase small fw-bold text-primary mb-3">{{ get_phrase('Publishing') }}</h6>
                @if($isAdmin && $facilitators->count() > 0)
                    <div class="mb-3">
                        <label class="eForm-label" for="lcFacilitator">{{ get_phrase('Facilitator') }}</label>
                        <select class="form-select eForm-control" id="lcFacilitator" name="teacher_id">
                            <option value="">{{ get_phrase('Select facilitator') }}</option>
                            @foreach($facilitators as $facilitator)
                                <option value="{{ $facilitator->id }}"
                                    @selected((string) old('teacher_id') === (string) $facilitator->id)>{{ $facilitator->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <button type="submit" class="eBtn eBtn-primary w-100 mb-2" name="action" value="publish">{{ get_phrase('Schedule & Notify Students') }}</button>
                <p class="text-muted small">{{ get_phrase('Makes the class available and notifies the students registered for this Course Offering.') }}</p>

                <hr class="my-3">

                <button type="submit" class="eBtn eBtn-dark w-100 mb-2" name="action" value="draft">{{ get_phrase('Save as Draft') }}</button>
                <p class="text-muted small mb-0">{{ get_phrase('Only you can see this class. Registered students are not notified.') }}</p>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    // Reveal the meeting link only for a provider that cannot create one, and
    // say so plainly for a provider that can. Server-side validation still
    // enforces the requirement, so this is a courtesy, not the control.
    var MANUAL = @json(collect($platformOptions)->filter(fn ($o) => ! $o['auto_creates'])->keys()->values());
    var select = document.getElementById('lcPlatform');
    var linkRow = document.getElementById('lcLinkRow');
    var linkInput = document.getElementById('lcMeetingUrl');
    var autoNote = document.getElementById('lcAutoNote');

    function refresh() {
        var needsManual = MANUAL.indexOf(select.value) !== -1;
        linkRow.classList.toggle('d-none', !needsManual);
        autoNote.classList.toggle('d-none', needsManual);
        linkInput.required = needsManual;
    }

    select.addEventListener('change', refresh);
    refresh();

    // ── Institution-time preview ──────────────────────────────────────
    // When the lecturer's own clock differs from the institution's, show what
    // the time they typed means officially, before they save. Purely a
    // convenience: the server re-derives the instant authoritatively and
    // ignores whatever this displays.
    (function institutionPreview() {
        var holder = document.querySelector('[data-role="institution-time"]');
        if (! holder) { return; }

        var inputZone = @json($timezones['identifier']);
        var institutionZone = @json($timezones['institution_identifier']);
        var dateInput = document.getElementById('lcDate');
        var startInput = document.getElementById('lcStart');
        var endInput = document.getElementById('lcEnd');
        var readStart = document.getElementById('lcInstitutionStart');
        var readEnd = document.getElementById('lcInstitutionEnd');
        if (!dateInput || !startInput) { return; }

        function localWallClock(dateStr, timeStr) {
            if (!dateStr || !timeStr) { return null; }
            var y = dateStr.slice(0, 4), mo = dateStr.slice(5, 7), d = dateStr.slice(8, 10);
            var h = timeStr.slice(0, 2), mi = timeStr.slice(3, 5);
            if (y === '' || mo === '' || d === '') { return null; }
            return new Date(Date.UTC(+y, +mo - 1, +d, +h, +mi));
        }

        function formatAt(utcMillis, zone) {
            if (utcMillis === null) { return '—'; }
            return new Intl.DateTimeFormat(undefined, {
                timeZone: zone, hour: 'numeric', minute: '2-digit', dateStyle: 'medium'
            }).format(new Date(utcMillis));
        }

        // The typed value is a wall clock in the lecturer's zone; convert that
        // wall clock to a real instant, then read the instant in the
        // institution's zone. No offset is hardcoded anywhere.
        function wallClockToInstant(dateStr, timeStr, zone) {
            var naive = localWallClock(dateStr, timeStr);
            if (naive === null) { return null; }
            // Guess the offset at that wall-clock time, then correct once.
            var guess = new Date(naive.toLocaleString('en-US', { timeZone: zone }));
            var offset = guess.getTime() - naive.getTime();
            var instant = new Date(naive.getTime() - offset);
            var secondGuess = new Date(instant.toLocaleString('en-US', { timeZone: zone }));
            var refined = new Date(naive.getTime() - (secondGuess.getTime() - naive.getTime()));
            return refined.getTime();
        }

        function refresh() {
            var startInstant = wallClockToInstant(dateInput.value, startInput.value, inputZone);
            var endInstant = wallClockToInstant(dateInput.value, endInput ? endInput.value : null, inputZone);
            holder.textContent = formatAt(startInstant, institutionZone);
            if (readStart) { readStart.textContent = formatAt(startInstant, institutionZone); }
            if (readEnd) { readEnd.textContent = formatAt(endInstant, institutionZone); }
        }

        [dateInput, startInput, endInput].forEach(function (el) {
            if (el) { el.addEventListener('change', refresh); el.addEventListener('input', refresh); }
        });
        refresh();
    })();
})();
</script>
@endsection
