@extends('admin.navigation')

@section('content')
<div class="mainSection-title">
    <div class="row"><div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gr-15">
            <div><h4 class="title">{{ get_phrase($workflow ? 'Prepare a New '.$calendarUnitLabel : 'Academic Dashboard') }}</h4>
                <ul class="breadcrumb-link"><li><a href="{{ route('admin.dashboard') }}">{{ get_phrase('Dashboard') }}</a></li><li>{{ get_phrase('Academic') }}</li></ul>
            </div>
            @if($workflow)
                <a class="btn btn-outline-primary" href="{{ route('admin.academic.workspace', array_filter(['year' => $year?->id, 'period' => $period?->id])) }}">{{ get_phrase('Back to Academic Dashboard') }}</a>
            @else
                <a class="btn btn-primary" href="{{ route('admin.academic.workspace', array_filter(['mode' => 'prepare', 'year' => $year?->id, 'period' => $period?->id])) }}">{{ get_phrase('Prepare a New '.$calendarUnitLabel) }}</a>
            @endif
        </div>
    </div></div>
</div>

<div class="card border-0 shadow-sm mt-3"><div class="card-body">
    <p class="mb-3 text-muted">{{ get_phrase('Choose existing academic dates to review current work. Progress is read from existing academic records and updates as you complete each task.') }}</p>
    @if($canSelectAcademicContext)
    <form method="GET" action="{{ route('admin.academic.workspace', array_filter(['mode' => $workflow ? 'prepare' : null])) }}" class="row g-3 align-items-end">
        <div class="col-12 col-md-5"><label for="academic-year" class="form-label">{{ get_phrase('Academic year') }}</label>
            <select id="academic-year" name="year" class="form-select" onchange="this.form.submit()">
                <option value="">{{ get_phrase('Select an existing year') }}</option>
                @foreach($years as $item)<option value="{{ $item->id }}" @selected($year?->id === $item->id)>{{ $item->label }} ({{ ucfirst($item->status) }})</option>@endforeach
            </select>
        </div>
        <div class="col-12 col-md-5"><label for="academic-period" class="form-label">{{ get_phrase($calendarUnitLabel) }}</label>
            <select id="academic-period" name="period" class="form-select" onchange="this.form.submit()" @disabled(!$year || $periods->isEmpty())>
                <option value="">{{ get_phrase($year ? 'Select an existing '.$calendarUnitLabel : 'Choose a year first') }}</option>
                @foreach($periods as $item)<option value="{{ $item->id }}" @selected($period?->id === $item->id)>{{ $item->label }} ({{ ucfirst($item->status) }})</option>@endforeach
            </select>
        </div>
        <div class="col-12 col-md-2"><noscript><button class="btn btn-outline-primary w-100">{{ get_phrase('Apply') }}</button></noscript></div>
    </form>
    @endif
</div></div>

@if($usesClassBasedSessions)
<div class="alert alert-info mt-3" role="note">
    <strong>{{ get_phrase('Class-based sessions are separate from this academic calendar.') }}</strong>
    {{ get_phrase('This page selects an academic year and term or semester for course and programme planning. Class timetables and other class-based records use the active session in Session Manager. Changing the selection here does not change that session; keep them aligned when your school uses both workflows.') }}
    @if($canManageSessions)
        <a class="alert-link ms-1" href="{{ route('admin.settings.session_manager') }}">{{ get_phrase('Review class-based session') }}</a>
    @endif
</div>
@endif

@if(!$year && $canSelectAcademicContext)
<div class="alert alert-info mt-3" role="status">{{ get_phrase('No academic year is configured yet. Start with Academic Setup to add the institution’s year; the dashboard will use it automatically.') }}
    @if($canStructure)<a href="{{ route('admin.academic_structure.index') }}" class="alert-link">{{ get_phrase('Open Academic Years') }}</a>@endif
</div>
@elseif($year)
<div class="row g-3 mt-1">
    @if($canSelectAcademicContext)
    <div class="col-12 col-md-6"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ get_phrase('Selected academic year') }}</div><div class="fs-5 fw-semibold">{{ $year->label }}</div><span class="badge bg-light text-dark">{{ ucfirst($year->status) }}</span></div></div></div>
    <div class="col-12 col-md-6"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ get_phrase('Selected '.$calendarUnitLabel) }}</div><div class="fs-5 fw-semibold">{{ $period?->label ?? get_phrase('No '.$calendarUnitLabel.' selected') }}</div>@if($period)<span class="badge bg-light text-dark">{{ ucfirst($period->status) }}</span>@endif</div></div></div>
    @endif
    @if($canOfferings)
    <div class="col-12 col-md-6"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ get_phrase($period ? 'Course allocations in this period' : 'Course allocations in this year') }}</div><div class="fs-4 fw-semibold" data-academic-offering-count="{{ $offeringCount }}">{{ $offeringCount }}</div><a href="{{ route('admin.course_offerings.index', array_filter(['year_id' => $year?->id, 'period_id' => $period?->id])) }}">{{ get_phrase('Review course allocations') }}</a></div></div></div>
    @if($withoutLecturer !== null)<div class="col-12 col-md-6"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ get_phrase('Allocations without a lecturer') }}</div><div class="fs-4 fw-semibold">{{ $withoutLecturer }}</div><a href="{{ route('admin.course_offerings.index', array_filter(['year_id' => $year?->id, 'period_id' => $period?->id])) }}">{{ get_phrase('Review assignments') }}</a></div></div></div>@endif
    @endif
    @if($canRegistrations && $registrationCount !== null)
    <div class="col-12 col-md-6"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ get_phrase('Student registrations recorded') }}</div><div class="fs-4 fw-semibold">{{ $registrationCount }}</div><div class="small text-muted">{{ get_phrase('Confirmed') }}: {{ $confirmedRegistrationCount }} · {{ get_phrase('Awaiting confirmation') }}: {{ $awaitingConfirmationCount }}@if($otherRegistrationStatusCount > 0) · {{ get_phrase('Other status') }}: {{ $otherRegistrationStatusCount }}@endif</div><a href="{{ route('admin.course_registrations.index', array_filter(['academic_year_id' => $year?->id, 'period_id' => $period?->id])) }}">{{ get_phrase('Review registrations') }}</a></div></div></div>
    @endif
</div>
@endif

<div class="row g-3 mt-1">
    @if($timetableEntries !== null)
    <div class="col-12 col-md-6"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ get_phrase('Timetable entries in the active academic session') }}</div><div class="fs-4 fw-semibold">{{ $timetableEntries }}</div><div class="small text-muted">{{ $activeTimetableSession->session_title ?: get_phrase('Active session') }}</div><a href="{{ route('admin.routine') }}">{{ get_phrase('Review timetable') }}</a></div></div></div>
    @endif
    @if($upcomingOnlineExams !== null)
    <div class="col-12 col-md-6"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ get_phrase('Upcoming published online exams') }}</div><div class="fs-4 fw-semibold">{{ $upcomingOnlineExams }}</div><a href="{{ route('admin.online_exams.index') }}">{{ get_phrase('Review online exams') }}</a></div></div></div>
    @endif
    @if($pendingResultReviews !== null)
    <div class="col-12 col-md-6"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ get_phrase('Exam results awaiting review') }}</div><div class="fs-4 fw-semibold">{{ $pendingResultReviews }}</div><a href="{{ route('admin.online_exams.result_review_queue') }}">{{ get_phrase('Review results') }}</a></div></div></div>
    @endif
    @if($pendingAttendanceSessions !== null)
    <div class="col-12 col-md-6"><div class="card h-100"><div class="card-body"><div class="text-muted small">{{ get_phrase('Past attendance sessions still in draft') }}</div><div class="fs-4 fw-semibold">{{ $pendingAttendanceSessions }}</div>
        @if($pendingAttendanceOfferingId)<a href="{{ route('admin.course_offerings.show', ['id' => $pendingAttendanceOfferingId]) }}">{{ get_phrase('Open an affected course allocation') }}</a>
        @else<a href="{{ route('admin.course_offerings.index', array_filter(['year_id' => $year?->id, 'period_id' => $period?->id])) }}">{{ get_phrase('Review course allocations') }}</a>@endif
    </div></div></div>
    @endif
</div>

@if($workflow)
<section class="mt-4" aria-labelledby="calendar-checklist-title">
    <div class="d-flex justify-content-between align-items-baseline flex-wrap"><h5 id="calendar-checklist-title">{{ get_phrase($calendarUnitLabel.' preparation') }}</h5><span class="small text-muted">{{ get_phrase('You can return here at any time. Each status reflects saved records.') }}</span></div>
    @if($steps)
    <ol class="list-group list-group-numbered mt-2">
        @foreach($steps as $step)
        <li class="list-group-item d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div class="ms-2 me-auto"><div class="fw-semibold">{{ get_phrase($step['label']) }}</div><div class="text-muted small">{{ get_phrase($step['detail']) }}</div></div>
            <span class="badge rounded-pill {{ $step['state'] === 'Ready' ? 'bg-success' : ($step['state'] === 'Blocked by a prerequisite' ? 'bg-secondary' : ($step['state'] === 'In progress' ? 'bg-info text-dark' : 'bg-warning text-dark')) }}">{{ get_phrase($step['state']) }}</span>
            <a class="btn btn-sm btn-outline-primary" href="{{ route($step['route'], $step['parameters']) }}">{{ get_phrase('Open') }}</a>
        </li>
        @endforeach
    </ol>
    @else
    <p class="alert alert-info">{{ get_phrase('Your current role has no preparation tasks available. Use the academic links shown in your navigation for the work you are authorized to do.') }}</p>
    @endif
</section>
@endif
@endsection
