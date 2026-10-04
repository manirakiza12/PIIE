@extends('student.navigation')
@section('content')
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
    .join-now-btn { background: #16a34a !important; border-color: #16a34a !important; color: #fff !important; }
    .join-now-btn:hover { background: #15803d !important; }
</style>
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Live Classes') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="{{ route('student.dashboard') }}">{{ get_phrase('Home') }}</a></li><li><a href="#">{{ get_phrase('Live Classes') }}</a></li></ul>
        </div>
    </div>
</div></div></div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="row"><div class="col-12"><div class="eSection-wrap mb-3">
    @php
        $activeView = $status ? null : ($view ?? 'upcoming');
        $viewTabs = [
            'upcoming' => get_phrase('Upcoming'),
            'live' => get_phrase('Live Now'),
            'completed' => get_phrase('Completed'),
            'all' => get_phrase('All'),
        ];
    @endphp
    <ul class="nav nav-pills mb-3 gap-2">
        @foreach($viewTabs as $tabKey => $tabLabel)
            <li class="nav-item">
                <a class="nav-link {{ $activeView === $tabKey ? 'active' : '' }}"
                   href="{{ route('student.live_classes.index', array_filter(['view' => $tabKey, 'search' => $search, 'subject_id' => $subjectId, 'platform' => $platform, 'date' => $date])) }}">
                    {{ $tabLabel }}
                </a>
            </li>
        @endforeach
    </ul>

    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3"><label class="eForm-label">{{ get_phrase('Search') }}</label><input type="text" name="search" value="{{ $search }}" class="form-control eForm-control"></div>
        <div class="col-md-2"><label class="eForm-label">{{ get_phrase('Course') }}</label><select name="subject_id" class="form-control eForm-control"><option value="">{{ get_phrase('All') }}</option>@foreach($subjects as $subject)<option value="{{ $subject->id }}" {{ (string)$subjectId===(string)$subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="eForm-label">{{ get_phrase('Platform') }}</label><select name="platform" class="form-control eForm-control"><option value="">{{ get_phrase('All') }}</option>@foreach(['jitsi','google_meet','zoom','bigbluebutton','custom'] as $platformValue)<option value="{{ $platformValue }}" {{ $platform === $platformValue ? 'selected' : '' }}>{{ ucwords(str_replace('_', ' ', $platformValue)) }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="eForm-label">{{ get_phrase('Status') }}</label><select name="status" class="form-control eForm-control"><option value="">{{ get_phrase('All') }}</option>@foreach(['scheduled','live','ended'] as $statusValue)<option value="{{ $statusValue }}" {{ $status === $statusValue ? 'selected' : '' }}>{{ ucfirst($statusValue) }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="eForm-label">{{ get_phrase('Date') }}</label><input type="date" name="date" value="{{ $date }}" class="form-control eForm-control"></div>
        <div class="col-md-1"><button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Go') }}</button></div>
    </form>
</div></div></div>

<div class="row"><div class="col-12"><div class="eSection-wrap">
    <div class="row g-3">
        @forelse($classes as $lc)
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <h6>{{ $lc->title }}</h6>
                        <span class="badge bg-{{ $lc->computed_status=='live'?'danger':($lc->computed_status=='scheduled'?'warning':'secondary') }}">
                            @if($lc->computed_status === 'live')<span class="live-pulse-dot"></span>@endif
                            {{ $lc->displayStatusLabel() }}
                        </span>
                    </div>
                    <div class="text-muted small">{{ optional($lc->subject)->name }}</div>
                    {{-- Who is teaching it. A student planning to attend a class has
                         as much right to know the lecturer's name as to know the
                         room. --}}
                    @if(optional($lc->teacher)->name)
                        <div class="text-muted small"><i class="bi bi-person"></i> {{ optional($lc->teacher)->name }}</div>
                    @endif
                    @if($lc->start_date)
                    {{-- One stored instant, in this student's own clock. The raw start_time/end_time
     columns were rendered here; they hold the wall clock as TYPED by whoever
     scheduled it, in whatever zone that person was in, so a student in another
     country read a time hours away from the real meeting. --}}
@php
    // One formatter, resolved once. It was previously built twice in the same
    // markup, and nothing showed WHICH clock the times were in: a student read
    // "04 Oct 2026 3:25 AM" with no zone, and when the institution timezone was
    // unset that was UTC while the class was really 06:25 Kampala - three hours
    // out, presented as if it were certain. The zone label is now shown.
    //
    // A plain PHP comment, not a Blade {{-- --}} one: Blade does not process
    // comments inside an @php block, and {{-- here is a PHP parse error.
    $piieDisplay = app(App\Support\LiveClasses\LiveClassDisplay::class)->for($lc, auth()->user());
@endphp
<div class="mt-2">
                            <i class="bi bi-calendar-event"></i>
                            {{ $piieDisplay->date() }}
                            {{ $piieDisplay->timeRange() }}
                        </div>
                        <div class="text-muted small">
                            <i class="bi bi-globe2"></i>
                            {{ $piieDisplay->zoneNote() }}
                        </div>
                        {{-- Duration, derived from the two stored instants rather
                             than shown as a stored column, so it can never disagree
                             with the times above it. --}}
                        @if($lc->duration_minutes !== null)
                            <div class="text-muted small">
                                <i class="bi bi-hourglass-split"></i>
                                {{ $lc->duration_minutes >= 60
                                    ? intdiv($lc->duration_minutes, 60).'h'.($lc->duration_minutes % 60 ? ' '.($lc->duration_minutes % 60).'m' : '')
                                    : $lc->duration_minutes.'m' }}
                            </div>
                        @endif
                    @endif
                    {{-- Google conference status. A student shown "Link not ready
                         yet" needs to be told this is normal, or they will assume
                         the class is cancelled. --}}
                    @php($piieConference = \App\Support\LiveClasses\GoogleConferenceStatus::describe($lc->google_conference_status))
                    @if($piieConference && ! $piieConference['joinable'])
                        <div class="small text-muted mt-1">
                            <i class="bi bi-info-circle"></i>
                            {{ $piieConference['label'] }} — {{ $piieConference['explanation'] }}
                        </div>
                    @elseif($piieConference)
                        <div class="small text-success mt-1">
                            <i class="bi bi-camera-video"></i> {{ $piieConference['label'] }}
                        </div>
                    @endif
                    @if($lc->description)<p class="small mt-2">{{ Str::limit($lc->description,80) }}</p>@endif
                    @if($lc->exceedsGoogleMeetFreeTierLimit())
                        <div class="small text-warning mt-1"><i class="bi bi-exclamation-triangle-fill"></i> {{ get_phrase('Longer than 60 minutes — may be cut off on the free Google Meet plan.') }}</div>
                    @endif
                    @if($lc->can_join)
                        <a href="{{ route('student.live_classes.join', $lc->id) }}" class="eBtn eBtn-sm w-100 mt-2 {{ $lc->computed_status=='live'?'join-now-btn':'eBtn-primary' }}">
                            <i class="bi bi-camera-video-fill"></i> {{ $lc->computed_status=='live' ? get_phrase('Join Now') : get_phrase('Join Class') }}
                        </a>
                    @endif
                    <a href="javascript:;" class="eBtn eBtn-sm eBtn-dark w-100 mt-2" onclick="rightModal('{{ route('student.live_classes.materials', $lc->id) }}', '{{ get_phrase('Resources & Recordings') }}')">
                        <i class="bi bi-paperclip"></i> {{ get_phrase('Resources & Recordings') }}
                    </a>
                    @if($lc->computed_status === \App\Models\LiveClass::STATUS_ENDED && ($lc->course_offering_id ? $lc->recording_url : $lc->safe_recording_url))
                        <a href="{{ $lc->course_offering_id ? route('live_classes.recording.access', $lc->id) : $lc->safe_recording_url }}" target="_blank" class="eBtn eBtn-sm eBtn-dark w-100 mt-2">{{ get_phrase('View Recording') }}</a>
                    @endif
                </div>
            </div>
        </div>
        @empty
        <div class="col-12 text-center text-muted py-4">{{ get_phrase('No live classes scheduled') }}</div>
        @endforelse
    </div>
    <div class="mt-3">{{ $classes->appends(request()->query())->links() }}</div>
</div></div></div>
@endsection
