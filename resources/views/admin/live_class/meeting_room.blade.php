@extends(request()->routeIs('teacher.*') ? 'teacher.navigation' : (request()->routeIs('student.*') ? 'student.navigation' : 'admin.navigation'))
@section('content')
@php
    $routePrefix = request()->routeIs('teacher.*') ? 'teacher' : (request()->routeIs('student.*') ? 'student' : 'admin');
@endphp

<div class="mainSection-title">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
                <div class="d-flex flex-column">
                    <h4>{{ get_phrase('Live Meeting Room') }}</h4>
                    <ul class="d-flex align-items-center eBreadcrumb-2">
                        @if($routePrefix !== 'student')
                            <li><a href="{{ route($routePrefix . '.live_classes.index') }}">{{ get_phrase('Live Classes') }}</a></li>
                        @else
                            <li><a href="{{ route('student.live_classes.index') }}">{{ get_phrase('Live Classes') }}</a></li>
                        @endif
                        <li><a href="#">{{ $liveClass->title }}</a></li>
                    </ul>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ $externalMeetingUrl }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" class="eBtn eBtn-primary">{{ get_phrase('Open External') }}</a>
                    @if($routePrefix !== 'student')
                        <a href="{{ route($routePrefix . '.live_classes.show', $liveClass->id) }}" class="eBtn eBtn-dark">{{ get_phrase('Class Details') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap p-3">
            @if($jitsiConfigured)
                <div class="alert alert-success mb-3">
                    @if($isModerator)
                        {{ get_phrase('You are joining with the lecturer or administrator identity for this meeting.') }}
                    @else
                        {{ get_phrase('You are joining as a participant. Your lecturer manages the meeting.') }}
                    @endif
                </div>
            @else
                <div class="alert alert-warning mb-3">
                    <strong>{{ get_phrase('If you see "Waiting for a moderator"') }}:</strong>
                    @if($piiAuthorisedHost)
                        {{ get_phrase('Open the meeting in a new tab and sign in with the meeting provider when prompted to start the room.') }}
                    @else
                        {{ get_phrase('Please wait for your lecturer to start the room. You do not need to sign in as the host.') }}
                    @endif
                </div>
            @endif

            <div id="jitsi-meet-container" style="height: clamp(240px, calc(100vh - 260px), 900px); height: clamp(240px, calc(100dvh - 260px), 900px); border-radius: 8px; overflow: hidden; background: #111;"></div>
            <noscript>
                <div class="alert alert-danger mt-3">{{ get_phrase('JavaScript is required to join this meeting. You can also') }} <a href="{{ $externalMeetingUrl }}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">{{ get_phrase('open it in a new tab') }}</a>.</div>
            </noscript>
        </div>
    </div>
</div>

@php
$piieRoomConfig = [
    'domain' => $jitsiDomain,
    'roomName' => $jitsiRoomPath,
    'displayName' => $displayName,
    'isModerator' => $isModerator,
    'jwt' => $jitsiJwt,
    'attendanceId' => $attendanceId,
    'leaveUrl' => route($routePrefix . '.live_classes.attendance_leave', $liveClass->id),
    'csrfToken' => csrf_token(),
    'loadError' => get_phrase('Could not load the meeting. Try "Open External" above instead.'),
];
@endphp
<script id="piie-live-class-room-config" type="application/json">@json($piieRoomConfig)</script>
<script src="https://{{ $jitsiDomain }}/external_api.js"></script>
<script src="{{ asset('assets/js/live-class-room.js') }}?v=20261014-live-room-1"></script>
@endsection
