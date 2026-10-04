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
    .join-now-btn {
        background: #16a34a !important; border-color: #16a34a !important; color: #fff !important;
        font-weight: 600;
    }
    .join-now-btn:hover { background: #15803d !important; }
</style>
<div class="mainSection-title"><div class="row"><div class="col-12">
    <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
        <div class="d-flex flex-column">
            <h4>{{ get_phrase('Live Classes') }}</h4>
            <ul class="d-flex align-items-center eBreadcrumb-2"><li><a href="{{ route($routePrefix === 'teacher' ? 'teacher.dashboard' : 'admin.dashboard') }}">{{ get_phrase('Home') }}</a></li><li><a href="#">{{ get_phrase('Live Classes') }}</a></li></ul>
        </div>
        <div class="d-flex gap-2">
            @if($routePrefix === 'admin')
                <a href="{{ route('admin.live_classes.meet_guests') }}" class="eBtn eBtn-secondary">{{ get_phrase('Meet Guests') }}</a>
            @endif
            <a href="{{ route($routePrefix . '.live_classes.create') }}" class="eBtn eBtn-primary">{{ get_phrase('Schedule Class') }}</a>
            @if($isHei)
                {{-- An instant meeting in higher education must belong to a Course
                     Offering, so the Meeting selector is the Offering - never
                     "All classes" / "All courses" / "All sessions", which are
                     meaningless in an HEI timetable. --}}
                <form method="POST" action="{{ route($routePrefix . '.live_classes.meet_now') }}" target="_blank" class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                    @csrf
                    <select name="course_offering_id" class="form-control eForm-control" style="min-width: 200px; height: 40px;" aria-label="{{ get_phrase('Course Offering') }}" required>
                        <option value="">{{ get_phrase('Choose a Course Offering') }}</option>
                        @foreach($meetNowOfferings as $offeringRow)
                            <option value="{{ $offeringRow->id }}">{{ $offeringRow->reference ?: optional($offeringRow->subject)->name }}</option>
                        @endforeach
                    </select>
                    <select name="platform" class="form-control eForm-control" style="min-width: 165px; height: 40px;" aria-label="{{ get_phrase('Meeting platform') }}">
                        @foreach(['jitsi' => 'Jitsi', 'google_meet' => 'Google Meet', 'zoom' => 'Zoom'] as $value => $label)
                            @if($value === 'jitsi' || !empty($platformStatus[$value]))
                                <option value="{{ $value }}" {{ $defaultPlatform === $value ? 'selected' : '' }}>{{ get_phrase($label) }}</option>
                            @endif
                        @endforeach
                    </select>
                    <button type="submit" class="eBtn eBtn-success">{{ get_phrase('Meet Now') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route($routePrefix . '.live_classes.meet_now') }}" target="_blank" class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                    @csrf
                    <select name="class_id" class="form-control eForm-control" style="min-width: 150px; height: 40px;" aria-label="{{ get_phrase('Class') }}">
                        <option value="">{{ get_phrase('All classes') }}</option>
                        @foreach($classList as $class)
                            <option value="{{ $class->id }}">{{ $class->name }}</option>
                        @endforeach
                    </select>
                    <select name="subject_id" class="form-control eForm-control" style="min-width: 170px; height: 40px;" aria-label="{{ get_phrase('Course') }}">
                        <option value="">{{ get_phrase('All courses') }}</option>
                        @foreach($subjects as $subject)
                            <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                        @endforeach
                    </select>
                    <select name="academic_session_id" class="form-control eForm-control" style="min-width: 170px; height: 40px;" aria-label="{{ get_phrase('Academic session') }}">
                        <option value="">{{ get_phrase('All sessions') }}</option>
                        @foreach($sessions as $session)
                            <option value="{{ $session->id }}">{{ $session->session_title }}</option>
                        @endforeach
                    </select>
                    <select name="platform" class="form-control eForm-control" style="min-width: 165px; height: 40px;" aria-label="{{ get_phrase('Meeting platform') }}">
                        @foreach(['jitsi' => 'Jitsi (Auto In-System)', 'google_meet' => 'Google Meet', 'zoom' => 'Zoom'] as $value => $label)
                            @if($value === 'jitsi' || !empty($platformStatus[$value]))
                                <option value="{{ $value }}" {{ $defaultPlatform === $value ? 'selected' : '' }}>{{ get_phrase($label) }}</option>
                            @endif
                        @endforeach
                    </select>
                    <button type="submit" class="eBtn eBtn-success">{{ get_phrase('Meet Now') }}</button>
                </form>
            @endif
            <a href="javascript:;" class="eBtn eBtn-dark" onclick="rightModal('{{ route($routePrefix . '.live_classes.open_modal') }}', '{{ get_phrase('Quick Schedule') }}')">{{ get_phrase('Quick Schedule') }}</a>
        </div>
    </div>
</div></div></div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if(session('message'))<div class="alert alert-success">{{ session('message') }}</div>@endif

{{-- Google connection panel. Renders only for a lecturer; see the partial for
     why an administrator deliberately gets no Connect control here. --}}
@include('admin.live_class._google_connection')

<div class="row">
    <div class="col-12">
        <div class="eSection-wrap">
            @php
                $activeView = $status ? null : ($view ?? 'upcoming');
                $viewTabs = [
                    'upcoming' => get_phrase('Upcoming'),
                    'live' => get_phrase('Live Now'),
                    'completed' => get_phrase('Completed'),
                    'cancelled' => get_phrase('Cancelled'),
                    'all' => get_phrase('All'),
                ];
            @endphp
            <ul class="nav nav-pills mb-3 gap-2">
                @foreach($viewTabs as $tabKey => $tabLabel)
                    <li class="nav-item">
                        <a class="nav-link {{ $activeView === $tabKey ? 'active' : '' }}"
                           href="{{ route($routePrefix . '.live_classes.index', array_filter(['view' => $tabKey, 'search' => $search, 'platform' => $platform, 'date' => $date, 'course_offering_id' => $courseOfferingId, 'academic_period_id' => $academicPeriodId])) }}">
                            {{ $tabLabel }}
                        </a>
                    </li>
                @endforeach
            </ul>

            <form method="GET" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="view" value="{{ $activeView }}">
                <div class="col-md-3"><label class="eForm-label">{{ get_phrase('Search') }}</label><input type="text" name="search" value="{{ $search }}" class="form-control eForm-control" placeholder="{{ get_phrase('Title') }}"></div>
                @if($isHei)
                    <div class="col-md-3"><label class="eForm-label">{{ get_phrase('Course Offering') }}</label><select name="course_offering_id" class="form-control eForm-control"><option value="">{{ get_phrase('All Course Offerings') }}</option>@foreach($filterOfferings as $filterOffering)<option value="{{ $filterOffering->id }}" {{ (string)$courseOfferingId === (string)$filterOffering->id ? 'selected' : '' }}>{{ $filterOffering->reference ? $filterOffering->reference.' — ' : '' }}{{ optional($filterOffering->subject)->name }}</option>@endforeach</select></div>
                    <div class="col-md-2"><label class="eForm-label">{{ get_phrase('Academic Period') }}</label><select name="academic_period_id" class="form-control eForm-control"><option value="">{{ get_phrase('All') }}</option>@foreach($filterPeriods as $filterPeriod)<option value="{{ $filterPeriod->id }}" {{ (string)$academicPeriodId === (string)$filterPeriod->id ? 'selected' : '' }}>{{ $filterPeriod->label }}</option>@endforeach</select></div>
                @else
                    <div class="col-md-2"><label class="eForm-label">{{ get_phrase('Course') }}</label><select name="subject_id" class="form-control eForm-control"><option value="">{{ get_phrase('All') }}</option>@foreach($subjects as $subject)<option value="{{ $subject->id }}" {{ (string)$subjectId === (string)$subject->id ? 'selected' : '' }}>{{ $subject->name }}</option>@endforeach</select></div>
                @endif
                <div class="col-md-2"><label class="eForm-label">{{ get_phrase('Platform') }}</label><select name="platform" class="form-control eForm-control"><option value="">{{ get_phrase('All') }}</option>@foreach(['jitsi','google_meet','zoom','bigbluebutton','custom'] as $platformValue)<option value="{{ $platformValue }}" {{ $platform === $platformValue ? 'selected' : '' }}>{{ ucwords(str_replace('_',' ', $platformValue)) }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="eForm-label">{{ get_phrase('Status') }}</label><select name="status" class="form-control eForm-control"><option value="">{{ get_phrase('All') }}</option>@foreach(['draft','scheduled','live','ended','cancelled'] as $statusValue)<option value="{{ $statusValue }}" {{ $status === $statusValue ? 'selected' : '' }}>{{ ucfirst($statusValue) }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="eForm-label">{{ get_phrase('Date') }}</label><input type="date" name="date" class="form-control eForm-control" value="{{ $date }}"></div>
                <div class="col-md-1"><button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Go') }}</button></div>
            </form>

            <div class="table-responsive">
                <table class="table eTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>{{ get_phrase('Title') }}</th>
                            <th>{{ get_phrase('Course') }}</th>
                            <th>{{ get_phrase('Lecturer') }}</th>
                            <th>{{ get_phrase('Platform') }}</th>
                            <th>{{ get_phrase('Date') }}</th>
                            <th>{{ get_phrase('Time') }}</th>
                            <th>{{ get_phrase('Status') }}</th>
                            <th class="text-end">{{ get_phrase('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($classes as $i => $lc)
                        @php
                            $statusClass = $lc->computed_status === 'live' ? 'danger' : ($lc->computed_status === 'scheduled' ? 'warning' : ($lc->computed_status === 'cancelled' ? 'secondary' : ($lc->computed_status === 'draft' ? 'dark' : 'success')));
                        @endphp
                        <tr>
                            <td>{{ $classes->firstItem() + $i }}</td>
                            <td>{{ $lc->title }}</td>
                            <td>
                                @if($lc->course_offering_id)
                                    <span class="d-block">{{ $lc->courseOffering?->reference ?: get_phrase('Course Offering') }}</span>
                                    <span class="d-block small text-muted">{{ optional($lc->courseOffering?->academicPeriod)->name ?: optional($lc->subject)->name ?: '—' }}</span>
                                @else
                                    {{ optional($lc->subject)->name ?: '—' }}
                                @endif
                            </td>
                            <td>{{ optional($lc->teacher)->name ?: '—' }}</td>
                            <td>
                                <span class="badge bg-info">{{ ucwords(str_replace('_',' ', $lc->platform)) }}</span>
                                @if($lc->exceedsGoogleMeetFreeTierLimit())
                                    <i class="bi bi-exclamation-triangle-fill text-warning" title="{{ get_phrase('Longer than the 60-minute Google Meet free-tier limit') }}"></i>
                                @endif
                                {{-- Google conference status, where Google is the provider
                                     and has actually been asked. `pending` is a normal
                                     transient state, so it gets a neutral badge and an
                                     explanation rather than a red one. --}}
                                @php($piieConference = \App\Support\LiveClasses\GoogleConferenceStatus::describe($lc->google_conference_status))
                                @if($piieConference)
                                    <span class="badge bg-{{ $piieConference['label'] === 'Ready to join' ? 'success' : ($piieConference['label'] === 'Link not ready yet' ? 'secondary' : 'danger') }}"
                                          title="{{ $piieConference['explanation'] }}">
                                        {{ $piieConference['label'] }}
                                    </span>
                                @endif
                                @if($lc->platform === 'google_meet' && filled($lc->google_calendar_event_id))
                                    <i class="bi bi-calendar-check text-muted" title="{{ get_phrase('Created in Google Calendar') }}"></i>
                                @endif
                            </td>
                            <td>{{ optional($lc->start_date)->format('d M Y') }}</td>
                            <td>{{ app(App\Support\LiveClasses\LiveClassDisplay::class)->for($lc, auth()->user())->timeRange24() ?: '—' }}</td>
                            <td>
                                <span class="badge bg-{{ $statusClass }}">
                                    @if($lc->computed_status === 'live')<span class="live-pulse-dot"></span>@endif
                                    {{ $lc->displayStatusLabel() }}
                                </span>
                            </td>
                            <td>
                                <div class="d-flex justify-content-end align-items-center gap-1 flex-nowrap">
                                    @if($lc->can_join)
                                        <a href="{{ route($routePrefix . '.live_classes.join', $lc->id) }}" target="_blank" class="eBtn eBtn-sm join-now-btn text-nowrap">
                                            <i class="bi bi-camera-video-fill"></i> {{ $lc->computed_status === 'live' ? get_phrase('Join Now') : get_phrase('Join') }}
                                        </a>
                                    @endif
                                    <div class="adminTable-action">
                                        <button type="button" class="eBtn eBtn-black dropdown-toggle table-action-btn-2 live-class-action-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                            {{ get_phrase('Actions') }}
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end eDropdown-menu-2 eDropdown-table-action">
                                            <li><a class="dropdown-item" href="{{ route($routePrefix . '.live_classes.show', $lc->id) }}">{{ get_phrase('View') }}</a></li>
                                            <li><a class="dropdown-item" href="{{ route($routePrefix . '.live_classes.edit', $lc->id) }}">{{ get_phrase('Edit') }}</a></li>
                                            <li><a class="dropdown-item" href="{{ route($routePrefix . '.live_classes.attendance', $lc->id) }}">{{ get_phrase('Attendance') }}</a></li>
                                            <li>
                                                <a class="dropdown-item" href="javascript:;" onclick="rightModal('{{ route($routePrefix . '.live_classes.materials', $lc->id) }}', '{{ get_phrase('Resources & Recordings') }}')">{{ get_phrase('Resources & Recordings') }}</a>
                                            </li>
                                            {{-- Gated on the RECORDING STATE plus a usable link, never on the raw
                 column alone. A class whose recording is only "awaiting" kept a
                 leftover URL and was being offered a Watch action with nothing
                 playable behind it. --}}
            @if($lc->isRecordingAvailable() && ($lc->course_offering_id ? $lc->safe_recording_url : $lc->safe_recording_url))
                                                <li><a class="dropdown-item" href="{{ $lc->course_offering_id ? route('live_classes.recording.access', $lc->id) : $lc->safe_recording_url }}" target="_blank">{{ get_phrase('Recording') }}</a></li>
                                            @endif
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form method="POST" action="{{ route($routePrefix . '.live_classes.publish', $lc->id) }}">
                                                    @csrf
                                                    <button type="submit" class="dropdown-item">{{ $lc->is_published ? get_phrase('Unpublish') : get_phrase('Publish') }}</button>
                                                </form>
                                            </li>
                                            @if($lc->computed_status !== \App\Models\LiveClass::STATUS_CANCELLED)
                                                <li>
                                                    <form method="POST" action="{{ route($routePrefix . '.live_classes.cancel', $lc->id) }}" onsubmit="return confirm('{{ get_phrase('Cancel this class?') }}')">
                                                        @csrf
                                                        <button type="submit" class="dropdown-item text-danger">{{ get_phrase('Cancel') }}</button>
                                                    </form>
                                                </li>
                                            @endif
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4"><h6 class="mb-1">{{ $emptyState[0] }}</h6><p class="mb-0 small">{{ $emptyState[1] }}</p></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $classes->appends(request()->query())->links() }}
        </div>
    </div>
</div>

<script>
    // See resources/views/teacher/online_exam/index.blade.php for why this is
    // needed: .table-responsive's overflow-x: auto forces overflow-y to clip
    // too, so a dropdown opening near the bottom of the table gets cut off
    // unless it's detached from that ancestor with a fixed Popper strategy.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.live-class-action-toggle').forEach(function (toggle) {
            new bootstrap.Dropdown(toggle, { popperConfig: { strategy: 'fixed' } });
        });
    });
</script>
@endsection
