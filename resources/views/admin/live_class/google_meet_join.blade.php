@extends(request()->routeIs('teacher.*') ? 'teacher.navigation' : (request()->routeIs('student.*') ? 'student.navigation' : 'admin.navigation'))
@section('content')
@php
    $routePrefix = request()->routeIs('teacher.*') ? 'teacher' : (request()->routeIs('student.*') ? 'student' : 'admin');
    $isStaff = (int) auth()->user()->role_id !== 7;

    /**
     * WHICH GOOGLE ACCOUNT ACTUALLY OWNS THIS CLASS.
     *
     * This page used to tell every staff member that the class "runs on this
     * school's one shared Google Meet account", and to sign into that shared
     * account before joining. Both halves were wrong once per-lecturer OAuth
     * landed, and the second half was actively harmful: signing into a different
     * Google account than the one that owns the event means Google does not
     * recognise the lecturer as host, so the person about to teach the class
     * would sit in the waiting room like a student.
     *
     * The account is not guessed. It is read from the class itself, and the
     * discriminator is already persisted:
     *
     *   - a Calendar event id is recorded ONLY by the per-lecturer path
     *     (createGoogleMeetEventForActor -> GoogleCalendarService), because the
     *     installation-wide fallback in createGoogleMeetUrl() returns a bare
     *     hangoutLink string and never records an id;
     *   - no event id therefore means the institution-wide credential made it.
     *
     * Both branches are still real, so both are described honestly rather than
     * pretending the shared-credential path no longer exists.
     */
    $onLecturerOwnAccount = filled($liveClass->google_calendar_event_id);
@endphp

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Join Google Meet') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        <li><a href="{{ route($routePrefix . '.live_classes.index') }}">{{ get_phrase('Live Classes') }}</a></li>
                        <li><a href="#">{{ $liveClass->title }}</a></li>
                    </ul>
                </div>
                @if($routePrefix !== 'student')
                    <a href="{{ route($routePrefix . '.live_classes.show', $liveClass->id) }}" class="eBtn eBtn-dark">{{ get_phrase('Class Details') }}</a>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap" style="max-width: 640px;">
            <h5 class="mb-3">{{ $liveClass->title }}</h5>

            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle-fill"></i>
                {{--
                    Not wrapped in get_phrase(): that helper auto-inserts
                    every string it's given into language.phrase, a
                    varchar(191) column — anything longer than that throws
                    a SQL truncation error under strict mode (which is how
                    this was actually caught). Long explanatory paragraphs
                    like this one have to stay as plain, untranslated text.
                --}}
                @if(! $isStaff)
                    This class runs on Google Meet. You may briefly see a "waiting to be admitted" screen until the host lets you in — that is expected, just wait a moment.
                @elseif($onLecturerOwnAccount)
                    This class was created on the Google account of the lecturer who scheduled it. If you are running this session, continue in a browser tab already signed in to that same Google account — Google Meet identifies the host by that account, and signing in as anyone else means you will wait to be let in like everyone else. You can check which account owns it on the Live Classes page under Google Account.
                @else
                    This class was created on this school's shared Google Meet account rather than an individual lecturer's own account, so it is not tied to any one person's Google sign-in. If you are running this session, an administrator can confirm which account to use; otherwise continue and wait to be admitted.
                @endif
            </div>

            <a href="{{ $meetingUrl }}" target="_blank" rel="noopener" class="eBtn eBtn-primary">
                <i class="bi bi-camera-video-fill"></i> {{ get_phrase('Continue to Google Meet') }}
            </a>
        </div>
    </div>
</div>
@endsection
