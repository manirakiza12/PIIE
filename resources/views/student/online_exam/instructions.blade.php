@extends('student.navigation')
@section('content')
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ $exam->title }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2">
                <li><a href="{{ route('student.online_exam.list') }}">{{ get_phrase('Exams') }}</a></li>
                <li><a href="#">{{ get_phrase('Before You Begin') }}</a></li>
            </ul>
        </div>
    </div>
</div></div></div>

<div class="row justify-content-center online-exam-start-page">
    <div class="col-lg-8">
        <div class="eSection-wrap">
            <div id="startError" class="alert alert-danger d-none"></div>

            <div class="row g-3 mb-4">
                <div class="col-md-3 col-6"><div class="text-muted small">{{ get_phrase('Duration') }}</div><strong>{{ $exam->duration_minutes }} {{ get_phrase('min') }}</strong></div>
                <div class="col-md-3 col-6"><div class="text-muted small">{{ get_phrase('Questions') }}</div><strong>{{ $questionCount }}</strong></div>
                <div class="col-md-3 col-6"><div class="text-muted small">{{ get_phrase('Total Marks') }}</div><strong>{{ $exam->total_marks }}</strong></div>
                <div class="col-md-3 col-6"><div class="text-muted small">{{ get_phrase('Attempts') }}</div><strong>{{ $attemptsUsed }} / {{ $exam->max_attempts }}</strong></div>
            </div>

            @if($exam->start_datetime || $exam->end_datetime)
                <div class="alert alert-secondary">
                    <i class="bi bi-calendar-event"></i>
                    {{ get_phrase('This exam is taken at the same scheduled time for everyone') }}:
                    @if($exam->start_datetime)
                        <strong>{{ get_phrase('Opens') }} {{ $exam->start_datetime->format('d M Y, H:i') }}</strong>
                    @endif
                    @if($exam->end_datetime)
                        &mdash; <strong>{{ get_phrase('Closes') }} {{ $exam->end_datetime->format('d M Y, H:i') }}</strong>
                    @endif
                </div>
            @endif

            @unless($withinWindow)
                <div class="alert alert-warning">
                    <i class="bi bi-clock-history"></i>
                    @if($exam->start_datetime && now()->lt($exam->start_datetime))
                        {{ get_phrase('This exam has not opened yet. Come back at the scheduled start time above — the Start button will become available then.') }}
                    @else
                        {{ get_phrase('This exam window has closed. Contact your school administration if you believe this is an error.') }}
                    @endif
                </div>
            @endif

            @if($withinWindow)
                @if($exam->instructions)
                    <div class="alert alert-info">
                        <strong>{{ get_phrase('Instructions') }}:</strong>
                        <div class="piie-prose mt-1">{!! $exam->proseInstructions() !!}</div>
                    </div>
                @endif

                <div class="alert alert-warning">
                    <strong><i class="bi bi-shield-lock"></i> {{ get_phrase('This exam is monitored') }}:</strong>
                    <ul class="mb-0 mt-2">
                        <li>{{ get_phrase('Copying, pasting and right-click are disabled while the exam is open.') }}</li>
                        <li>{{ get_phrase('Switching tabs or minimising the window is logged.') }}</li>
                        @if($exam->fullscreen_required)
                            <li>{{ get_phrase('You must stay in fullscreen for the whole exam — exiting is logged and warned.') }}</li>
                        @endif
                        @if($exam->webcam_required)
                            <li>{{ get_phrase('This exam requires camera access for the duration of the attempt.') }}</li>
                        @endif
                        <li>{{ get_phrase('Your answers are saved automatically as you go, so a lost connection will not lose your work.') }}</li>
                    </ul>
                </div>

                @if($exam->webcam_required)
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6><i class="bi bi-camera-video"></i> {{ get_phrase('Camera Check') }}</h6>
                            <p class="text-muted small mb-2">{{ get_phrase('This exam requires your camera to be on. Grant access to continue.') }}</p>
                            <video id="cameraPreview" width="240" height="180" autoplay muted playsinline style="background:#000; border-radius:6px;"></video>
                            <div class="mt-2">
                                <button type="button" class="eBtn eBtn-sm eBtn-primary" id="grantCameraBtn">{{ get_phrase('Grant Camera Access') }}</button>
                                <span id="cameraStatus" class="ms-2 small text-muted">{{ get_phrase('Not granted yet') }}</span>
                            </div>
                        </div>
                    </div>
                @endif

                @if($exam->fullscreen_required)
                    <div class="card mb-3">
                        <div class="card-body">
                            <h6><i class="bi bi-arrows-fullscreen"></i> {{ get_phrase('Fullscreen Check') }}</h6>
                            <p class="text-muted small mb-2">{{ get_phrase('This exam must be taken in fullscreen.') }}</p>
                            <button type="button" class="eBtn eBtn-sm eBtn-primary" id="enterFullscreenBtn">{{ get_phrase('Enter Fullscreen') }}</button>
                            <span id="fullscreenStatus" class="ms-2 small text-muted">{{ get_phrase('Not entered yet') }}</span>
                        </div>
                    </div>
                @endif

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="declareReady">
                    <label class="form-check-label" for="declareReady">
                        {{ get_phrase('I have read the instructions and I am ready to begin. I understand this attempt cannot be paused once started, other than a disconnection.') }}
                    </label>
                </div>

                <button type="button" class="eBtn eBtn-primary" id="startExamBtn" disabled>
                    <i class="bi bi-play-fill"></i> {{ get_phrase('Start Exam') }}
                </button>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    var webcamRequired = @json((bool) $exam->webcam_required);
    var fullscreenRequired = @json((bool) $exam->fullscreen_required);
    var startUrl = "{{ route('student.online_exam.start', $exam->id) }}";
    var takeUrlTemplate = "{{ route('student.online_exam.take', $exam->id) }}";
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    var cameraReady = !webcamRequired;
    var cameraConsented = !webcamRequired;
    var fullscreenReady = !fullscreenRequired;

    var declareCheckbox = document.getElementById('declareReady');
    var startBtn = document.getElementById('startExamBtn');
    var errorBox = document.getElementById('startError');

    function refreshStartButton() {
        if (!startBtn || !declareCheckbox) {
            return;
        }
        startBtn.disabled = !(declareCheckbox.checked && cameraReady && fullscreenReady);
    }

    if (declareCheckbox) {
        declareCheckbox.addEventListener('change', refreshStartButton);
    }

    // ── Camera check ──────────────────────────────────────────────────
    var grantCameraBtn = document.getElementById('grantCameraBtn');
    if (grantCameraBtn) {
        grantCameraBtn.addEventListener('click', function () {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                document.getElementById('cameraStatus').textContent = "{{ get_phrase('Camera is not supported in this browser.') }}";
                return;
            }

            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function (stream) {
                    document.getElementById('cameraPreview').srcObject = stream;
                    document.getElementById('cameraStatus').textContent = "{{ get_phrase('Camera ready') }}";
                    document.getElementById('cameraStatus').classList.add('text-success');
                    cameraReady = true;
                    cameraConsented = true;
                    refreshStartButton();
                })
                .catch(function () {
                    document.getElementById('cameraStatus').textContent = "{{ get_phrase('Camera access denied') }}";
                    document.getElementById('cameraStatus').classList.add('text-danger');
                    cameraReady = false;
                });
        });
    }

    // ── Fullscreen check ──────────────────────────────────────────────
    var enterFullscreenBtn = document.getElementById('enterFullscreenBtn');
    if (enterFullscreenBtn) {
        enterFullscreenBtn.addEventListener('click', function () {
            var el = document.documentElement;
            var request = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
            if (request) {
                request.call(el).catch(function () {});
            }
        });

        document.addEventListener('fullscreenchange', function () {
            if (document.fullscreenElement) {
                fullscreenReady = true;
                document.getElementById('fullscreenStatus').textContent = "{{ get_phrase('Fullscreen active') }}";
                document.getElementById('fullscreenStatus').classList.add('text-success');
                refreshStartButton();
            }
        });
    }

    // ── Start ───────────────────────────────────────────────────────────
    if (startBtn) {
    startBtn.addEventListener('click', function () {
        startBtn.disabled = true;
        errorBox.classList.add('d-none');

        var browserToken = (window.crypto && window.crypto.randomUUID)
            ? window.crypto.randomUUID()
            : 'bt-' + Date.now() + '-' + Math.random().toString(36).slice(2);

        fetch(startUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                camera_consent_accepted: cameraConsented,
                camera_ready: cameraReady,
                fullscreen_ready: fullscreenReady,
                instructions_acknowledged: declareCheckbox.checked,
                browser_session_token: browserToken,
            }),
        })
        .then(function (response) {
            return response.json().then(function (data) { return { ok: response.ok, data: data }; });
        })
        .then(function (result) {
            if (!result.ok) {
                var messages = [];
                if (result.data.errors) {
                    Object.values(result.data.errors).forEach(function (list) {
                        list.forEach(function (m) { messages.push(m); });
                    });
                } else if (result.data.message) {
                    messages.push(result.data.message);
                }
                errorBox.textContent = messages.join(' ') || "{{ get_phrase('Could not start the exam. Please try again.') }}";
                errorBox.classList.remove('d-none');
                refreshStartButton();
                return;
            }

            window.location.href = takeUrlTemplate;
        })
        .catch(function () {
            errorBox.textContent = "{{ get_phrase('A connection problem stopped the exam from starting. Please try again.') }}";
            errorBox.classList.remove('d-none');
            refreshStartButton();
        });
    });
    }

    refreshStartButton();
})();
</script>
@endpush
@endsection
