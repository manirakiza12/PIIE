@extends(request()->routeIs('teacher.*') ? 'teacher.navigation' : 'admin.navigation')
@section('content')
@php
    $routePrefix = request()->routeIs('teacher.*') ? 'teacher' : 'admin';
@endphp
<style>
    .live-pulse-dot {
        display: inline-block; width: 8px; height: 8px; border-radius: 50%;
        background: #fff; margin-right: 5px; animation: liveClassPulse 1.4s infinite;
    }
    @keyframes liveClassPulse {
        0% { box-shadow: 0 0 0 0 rgba(255,255,255,.7); }
        70% { box-shadow: 0 0 0 6px rgba(255,255,255,0); }
        100% { box-shadow: 0 0 0 0 rgba(255,255,255,0); }
    }
    .join-now-btn { background: #16a34a !important; border-color: #16a34a !important; color: #fff !important; font-weight: 600; }
    .join-now-btn:hover { background: #15803d !important; color: #fff !important; }
</style>
<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Live Class Details') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="{{ route($routePrefix === 'teacher' ? 'teacher.dashboard' : 'admin.dashboard') }}">{{ get_phrase('Home') }}</a></li>
                        <li><a href="{{ route($routePrefix . '.live_classes.index') }}">{{ get_phrase('Live Classes') }}</a></li>
                        <li><a href="#">{{ get_phrase('Details') }}</a></li>
                    </ul>
                </div>
                <div class="d-flex gap-2">
                    <a href="javascript:;" class="eBtn eBtn-dark" onclick="rightModal('{{ route($routePrefix . '.live_classes.materials', $liveClass->id) }}', '{{ get_phrase('Resources & Recordings') }}')">
                        <i class="bi bi-paperclip"></i> {{ get_phrase('Resources & Recordings') }}
                    </a>
                    <a href="{{ route($routePrefix . '.live_classes.attendance', $liveClass->id) }}" class="eBtn eBtn-dark">
                        <i class="bi bi-people"></i> {{ get_phrase('Attendance') }}
                    </a>
                    <a href="{{ route($routePrefix . '.live_classes.edit', $liveClass->id) }}" class="eBtn eBtn-warning">{{ get_phrase('Edit') }}</a>
                    @if($liveClass->can_join)
                        <a href="{{ route($routePrefix . '.live_classes.join', $liveClass->id) }}" target="_blank" class="eBtn join-now-btn">
                            <i class="bi bi-camera-video-fill"></i> {{ $liveClass->computed_status === 'live' ? get_phrase('Join Now') : get_phrase('Join') }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        @if($liveClass->exceedsGoogleMeetFreeTierLimit())
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle-fill"></i>
                {{ str_replace(':minutes', $liveClass->duration_minutes, get_phrase('This class runs :minutes minutes on Google Meet — over the 60-minute free-tier limit. It may be cut off unless the host has a paid Google Workspace plan.')) }}
            </div>
        @endif
        @if($liveClass->course_offering_id && \Illuminate\Support\Facades\Schema::hasTable('course_offerings'))
            {{-- An Offering-backed class has no Programme of its own: the
                 Programme, Academic Year, Period and Stage all come from the
                 Course Offering. Showing "Programme: —" here is the symptom of
                 reading a field that was never meant to be populated for this
                 kind of class. Derive the whole context instead. --}}
            <div class="eSection-wrap mb-3">
                <h6 class="text-uppercase small fw-bold text-primary mb-3">{{ get_phrase('Course Context') }}</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <strong>{{ get_phrase('Course Offering') }}:</strong>
                        {{ $liveClass->courseOffering?->reference ?: '#'.$liveClass->course_offering_id }}
                    </div>
                    <div class="col-md-6"><strong>{{ get_phrase('Course Unit') }}:</strong> {{ optional($liveClass->subject)->name ?: '—' }}</div>
                    <div class="col-md-6"><strong>{{ get_phrase('Academic Year') }}:</strong> {{ $liveClass->courseOffering?->academicYear?->label ?: '—' }}</div>
                    <div class="col-md-6"><strong>{{ get_phrase('Academic Period') }}:</strong> {{ $liveClass->courseOffering?->academicPeriod?->label ?: '—' }}</div>
                    <div class="col-md-6">
                        <strong>{{ get_phrase('Registered Students') }}:</strong>
                        {{ $liveClass->course_offering_id && \Illuminate\Support\Facades\Schema::hasTable('course_registrations')
                            ? \App\Models\CourseRegistration::query()
                                ->where('school_id', $liveClass->school_id)
                                ->where('course_offering_id', $liveClass->course_offering_id)
                                ->where('status', \App\Models\CourseRegistration::STATUS_CONFIRMED)
                                ->count()
                            : '—' }}
                    </div>
                    <div class="col-12">
                        <a href="{{ $routePrefix === 'teacher' ? route('teacher.course_offerings.show', $liveClass->course_offering_id) : route('admin.course_offerings.show', $liveClass->course_offering_id) }}"
                           class="eBtn eBtn-dark">{{ get_phrase('Open Course Offering') }}</a>
                    </div>
                </div>
            </div>
        @endif

        <div class="eSection-wrap">
            <div class="row g-3">
                <div class="col-md-6"><strong>{{ get_phrase('Title') }}:</strong> {{ $liveClass->title }}</div>
                <div class="col-md-6"><strong>{{ get_phrase('Course') }}:</strong> {{ optional($liveClass->subject)->name ?: '—' }}</div>
                <div class="col-md-6"><strong>{{ get_phrase('Lecturer') }}:</strong> {{ optional($liveClass->teacher)->name ?: '—' }}</div>
                @unless($liveClass->course_offering_id)
                    {{-- Programme is a real, meaningful field for a legacy K12
                         class and is left exactly as it was. --}}
                    <div class="col-md-6"><strong>{{ get_phrase('Programme') }}:</strong> {{ optional($liveClass->programme)->name ?: '—' }}</div>
                @endunless
                <div class="col-md-6"><strong>{{ get_phrase('Platform') }}:</strong> {{ ucwords(str_replace('_', ' ', $liveClass->platform)) }}</div>
                <div class="col-md-6">
                    <strong>{{ get_phrase('Status') }}:</strong>
                    @php
                        // "Completed", not the stored "ended". The enum value is
                        // correct for the database and for every filter, dedup key and
                        // audit record; it is simply not a word a lecturer means. The
                        // mapping is PRESENTATION only - no lifecycle value and no
                        // lifecycle meaning changes in order to fix a word.
                        $statusTone = match ($liveClass->computed_status) {
                            'live' => 'danger',
                            'scheduled' => 'warning',
                            'ended' => 'success',
                            // Never green: nothing about this class was confirmed.
                            'not_concluded' => 'dark',
                            'cancelled' => 'secondary',
                            default => 'dark',
                        };
                    @endphp
                    <span class="badge bg-{{ $statusTone }}">
                        @if($liveClass->computed_status === 'live')<span class="live-pulse-dot"></span>@endif
                        {{ $liveClass->displayStatusLabel() }}
                    </span>
                </div>
                <div class="col-md-6"><strong>{{ get_phrase('Date') }}:</strong> {{ optional($liveClass->start_date)->format('d M Y') }}</div>
                <div class="col-md-6"><strong>{{ get_phrase('Time') }}:</strong> {{ app(App\Support\LiveClasses\LiveClassDisplay::class)->for($liveClass, auth()->user())->timeRange24() ?: '—' }} @if($liveClass->duration_minutes !== null)<span class="text-muted">({{ $liveClass->duration_minutes }} {{ get_phrase('min') }})</span>@endif</div>
                <div class="col-md-6"><strong>{{ get_phrase('Published') }}:</strong> {{ $liveClass->is_published ? get_phrase('Yes') : get_phrase('No') }}</div>
                <div class="col-md-6">
                    <strong>{{ get_phrase('Registered Students') }}:</strong>
                    @if($liveClass->course_offering_id && \Illuminate\Support\Facades\Schema::hasTable('course_registrations'))
                        {{ \App\Models\CourseRegistration::query()->where('school_id', $liveClass->school_id)->where('course_offering_id', $liveClass->course_offering_id)->where('status', \App\Models\CourseRegistration::STATUS_CONFIRMED)->count() }}
                    @else
                        —
                    @endif
                </div>
                <div class="col-md-6">
                    <strong>{{ get_phrase('Timezone') }}:</strong> {{ $liveClass->timezone ?: '—' }}
                </div>
                @unless($liveClass->course_offering_id)
                    {{-- "Attendance enabled" is the legacy K12 flag that records
                         who pressed Join. For an Offering-backed class it is
                         always off and is actively misleading, because official
                         attendance is the governed Course Offering Attendance
                         workflow - a separate, certified system. --}}
                    <div class="col-md-6"><strong>{{ get_phrase('Attendance enabled') }}:</strong> {{ $liveClass->attendance_enabled ? get_phrase('Yes') : get_phrase('No') }}</div>
                @endunless
                <div class="col-12"><strong>{{ get_phrase('Description') }}:</strong><br>{{ $liveClass->description ?: '—' }}</div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="eSection-wrap">
            {{-- ══ CLASSROOM ══
                 Publishing, starting, entering, ending and cancelling are five
                 different things and now have five different controls. Exactly
                 one primary action is offered, chosen from the class's real
                 state, so "Unpublish" is never presented as the main next step
                 on a class that is about to run. The state itself is derived by
                 LiveClassLifecycle from the existing rules - nothing here
                 decides when a class is live. --}}
            @php
                $lcState = $lifecycle['state'];
                $lcBadge = [
                    'upcoming' => 'info', 'ready' => 'primary', 'live' => 'danger',
                    'completed' => 'success', 'cancelled' => 'secondary', 'draft' => 'dark',
                ][$lcState] ?? 'secondary';
            @endphp
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">{{ get_phrase('Classroom') }}</h6>
                <span class="badge bg-{{ $lcBadge }}">{{ $lifecycle['label'] }}</span>
            </div>

            @if($lcState === 'live')
                <div class="alert alert-danger py-2 mb-2">
                    <span class="live-pulse-dot"></span> <strong>{{ get_phrase('LIVE NOW') }}</strong>
                </div>
            @endif

            @if($primaryAction)
                @if($primaryAction['key'] === 'start' || $primaryAction['key'] === 'enter')
                    <a href="{{ route($routePrefix . '.live_classes.join', $liveClass->id) }}" target="_blank"
                       class="eBtn w-100 mb-2" style="background:#16a34a;border-color:#16a34a;color:#fff;font-weight:600;">
                        <i class="bi bi-broadcast"></i> {{ $primaryAction['label'] }}
                    </a>
                @elseif($primaryAction['key'] === 'publish')
                    <form method="POST" action="{{ route($routePrefix . '.live_classes.publish', $liveClass->id) }}" class="mb-2">
                        @csrf
                        <button type="submit" class="eBtn eBtn-primary w-100">
                            <i class="bi bi-megaphone"></i> {{ $primaryAction['label'] }}
                        </button>
                    </form>
                @else
                    <div class="eBtn eBtn-dark w-100 mb-2 disabled" aria-disabled="true">{{ $primaryAction['label'] }}</div>
                @endif
                <p class="text-muted small">{{ $primaryAction['help'] }}</p>
                @if($countdown)
                    <p class="text-muted small mb-0"><strong>{{ $countdown }}</strong></p>
                @endif
            @endif

            {{-- Ending is a distinct decision from cancelling: this class ran, and its
                 record, notifications and any recording are kept.

                 Offered whenever the lecturer may CONCLUDE the class, which includes
                 the 'not concluded' state. That state is the whole reason the clock
                 is no longer allowed to close a class by itself: a lecturer whose
                 class ended at 11:00 and who is marking it at 16:00 must still have a
                 button, or the record stays permanently unclaimed. --}}
            @if($canConclude && $liveClass->canTransitionTo(\App\Models\LiveClass::STATUS_ENDED))
                @if($lcState === 'not_concluded')
                    <div class="alert alert-warning small">
                        {{ $lifecycle['label'] }} &mdash; {{ get_phrase('The scheduled time has passed but this class was never closed. PIIE will not record it as completed on its own, because it cannot know whether it was taught.') }}
                    </div>
                @endif
                <form method="POST" action="{{ route($routePrefix . '.live_classes.end', $liveClass->id) }}"
                      class="mt-2" onsubmit="return confirm('{{ get_phrase('End this Live Class now? Students will no longer be able to join.') }}')">
                    @csrf
                    <button type="submit" class="eBtn eBtn-warning w-100">
                        <i class="bi bi-stop-circle"></i>
                        {{ $lcState === 'not_concluded' ? get_phrase('Mark Class Completed') : get_phrase('End Class') }}
                    </button>
                </form>
            @endif

            <hr class="my-3">
            <h6>{{ get_phrase('Meeting') }}</h6>
            @if($liveClass->course_offering_id)
                <p class="mb-2">{{ $platform['note'] }}</p>
                @if(count($platform['limitations']))
                    <ul class="small text-muted ps-3 mb-2">
                        @foreach($platform['limitations'] as $limitation)
                            <li>{{ $limitation }}</li>
                        @endforeach
                    </ul>
                @endif
                {{-- Lifecycle evidence, when it exists. NULL means the decision was
                     made before this was recorded, which the interface says rather
                     than back-filling a time it does not have. --}}
                <dl class="small mb-0">
                    @if($liveClass->started_at)
                        <dt class="d-inline">{{ get_phrase('Classroom opened') }}:</dt>
                        <dd class="d-inline">{{ $display->startedAt() }} @if($startedByName)({{ $startedByName }})@endif</dd>
                    @endif
                    @if($liveClass->ended_at)
                        <dt class="d-inline">{{ get_phrase('Ended') }}:</dt>
                        <dd class="d-inline">{{ $display->endedAt() }} @if($endedByName)({{ $endedByName }})@endif</dd>
                    @endif
                    @if($liveClass->cancelled_at)
                        <dt class="d-inline">{{ get_phrase('Cancelled') }}:</dt>
                        <dd class="d-inline">{{ $display->cancelledAt() }} @if($cancelledByName)({{ $cancelledByName }})@endif</dd>
                    @endif
                </dl>
            @else
                <p class="mb-2"><strong>{{ get_phrase('URL') }}:</strong><br>{{ $liveClass->safe_meeting_url ?: '—' }}</p>
            @endif
            @php($recordingState = $liveClass->recordingState())
            @if($recordingState === \App\Models\LiveClass::RECORDING_AVAILABLE)
                <a href="{{ $liveClass->course_offering_id ? route('live_classes.recording.access', $liveClass->id) : $liveClass->safe_recording_url }}" target="_blank" rel="noopener" class="eBtn eBtn-dark w-100 mb-2">{{ get_phrase('View Recording') }}</a>
            @else
                {{-- The state is stated, never implied. A class being taught says
                     nothing about a recording, and "no recording", "still processing"
                     and "failed" are three different facts that all used to render as
                     one missing link. --}}
                <p class="small text-muted mb-2">{{ $liveClass->recordingStateLabel() }}.</p>
            @endif

            @if($liveClass->course_offering_id && $canManageRecording)
                {{-- Attaching is the NORMAL path: neither Jitsi nor Google Meet hands
                     this system a finished file, so a recording is attached once the
                     provider has produced one - through the same protected resource
                     architecture as any other material. --}}
                <form method="POST" action="{{ route($routePrefix . '.live_classes.recording.attach', $liveClass->id) }}" class="mb-2">
                    @csrf
                    <label class="eForm-label small" for="lcRecordingStatus">{{ get_phrase('Recording state') }}</label>
                    <select name="recording_status" id="lcRecordingStatus" class="form-select form-select-sm mb-2">
                        @foreach(\App\Models\LiveClass::RECORDING_STATUSES as $option)
                            <option value="{{ $option }}" {{ $recordingState === $option ? 'selected' : '' }}>
                                {{ \App\Models\LiveClass::RECORDING_LABELS[$option] }}
                            </option>
                        @endforeach
                    </select>
                    <input type="url" name="recording_url" class="form-control form-control-sm mb-2"
                           placeholder="https://..."
                           value="{{ old('recording_url', $liveClass->recording_url) }}">
                    <button type="submit" class="btn btn-outline-secondary btn-sm w-100">{{ get_phrase('Save Recording State') }}</button>
                </form>
            @endif
            @if($liveClass->isTerminal())
                {{-- A concluded class is kept, and it is FINAL.

                     Cancel, reschedule and edit-schedule are all gone for a class
                     that has been ended or cancelled: those are the actions that
                     decide WHETHER the session happened, and allowing them after
                     the fact would let a completed academic record be reopened
                     from a menu. Resources and recordings stay available - they
                     are the work that legitimately continues after a class - under
                     their own governed authorization.

                     The controller refuses these moves too. This is a courtesy, not
                     the rule. --}}
                <p class="text-muted small mb-2">
                    {{ $liveClass->status === \App\Models\LiveClass::STATUS_ENDED
                        ? get_phrase('This class is completed and its record is final. It cannot be cancelled, rescheduled or re-timed.')
                        : get_phrase('This class was cancelled and its record is final. It cannot be re-opened, rescheduled or re-timed.') }}
                </p>
                @if($canManageRecording)
                    <p class="text-muted small mb-0">
                        {{ get_phrase('You can still attach resources and a recording below.') }}
                    </p>
                @endif
            @else
            {{-- Publish/Unpublish is a VISIBILITY control, not a classroom one.
                 It is deliberately demoted to a small secondary link so it is
                 never mistaken for "Start". A published class that students
                 were notified about is only withdrawn deliberately. --}}
            <form method="POST" action="{{ route($routePrefix . '.live_classes.publish', $liveClass->id) }}" class="mt-3 mb-2">
                @csrf
                <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none">
                    {{ $liveClass->is_published ? get_phrase('Withdraw from students (Unpublish)') : get_phrase('Publish to students') }}
                </button>
            </form>
            @if($liveClass->computed_status !== \App\Models\LiveClass::STATUS_CANCELLED)
                <form method="POST" action="{{ route($routePrefix . '.live_classes.cancel', $liveClass->id) }}" class="mb-2" onsubmit="return confirm('{{ get_phrase('Cancel this class?') }}')">
                    @csrf
                    <button type="submit" class="eBtn eBtn-danger w-100">{{ get_phrase('Cancel Class') }}</button>
                </form>
            @endif
            @endif

            @if($liveClass->course_offering_id)
                {{-- No Delete for an Offering-backed class. The endpoint already
                     refuses one (destroy() aborts 403), so showing the button
                     only ever led a lecturer to a dead end - and for a class
                     that has already notified students it invited destroying
                     academic history. Cancel is the governed way to end it. --}}
                <p class="text-muted small mb-0">
                    {{ get_phrase('A Course Offering Live Class cannot be deleted. Cancel it instead, so its history and any student notifications are preserved.') }}
                </p>
            @else
                <form method="POST" action="{{ route($routePrefix . '.live_classes.destroy', $liveClass->id) }}" onsubmit="return confirm('{{ get_phrase('Delete this class?') }}')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="eBtn eBtn-danger w-100">{{ get_phrase('Delete') }}</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
