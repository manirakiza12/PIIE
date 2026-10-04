@extends('teacher.navigation')
@section('content')
@include('teacher.course_offerings._workspace_nav')

@php
    $programmeLabel = $terms['programme'] ?? 'Programme';
    $canMark = $canTeach && $session->acceptsMarking();
@endphp

<div class="mainSection-title">
    <a href="{{ route('teacher.course_offerings.attendance.index', $offering->id) }}" class="btn btn-sm btn-outline-secondary mb-2">Back to Attendance</a>
    <h4 class="mb-1">Mark Attendance &mdash; {{ $session->typeLabel() }}</h4>
    <p class="text-muted mb-0">
        {{ $session->session_date?->format('l, j F Y') }}
        @if($session->timeRange()) &middot; {{ $session->timeRange() }} @endif
        @if($session->topic) &middot; {{ $session->topic }} @endif
    </p>
</div>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@if(!$canMark)
    <div class="alert alert-{{ $session->acceptsMarking() ? 'warning' : 'info' }}" role="status">
        <strong>This Attendance Session is {{ strtolower($session->statusLabel()) }}.</strong>
        @if(!$canTeach)
            Attendance can only be marked by a lecturer with a current teaching allocation while the Course Offering is in progress.
        @else
            A finalised register is read-only. Ask the academic office if it needs to be corrected.
        @endif
    </div>
@endif

<div class="row g-2 mb-3" aria-label="Register summary">
    @foreach(['present' => 'Present', 'late' => 'Late', 'excused' => 'Excused', 'absent' => 'Absent'] as $key => $label)
        <div class="col-6 col-md-3">
            <div class="border rounded p-2 text-center h-100">
                <div class="fs-4 fw-semibold">{{ $summary[$key] }}</div>
                <div class="small text-muted">{{ $label }}</div>
            </div>
        </div>
    @endforeach
</div>

@if($evidence->isNotEmpty())
    <div class="alert alert-info" role="status">
        <strong>Live Class participation evidence.</strong>
        {{ $evidence->count() }} {{ \Illuminate\Support\Str::plural('participant', $evidence->count()) }} joined the related Live Class.
        Joining is evidence only — academic attendance is still your decision.
    </div>
@endif

@if($roster->isEmpty())
    <section class="eSection-wrap">
        <div class="border rounded p-4 text-center" role="status">
            <h6>No confirmed students are currently registered for this Course Offering.</h6>
            <p class="text-muted mb-0">Attendance can only be marked for confirmed course registrations.</p>
        </div>
    </section>
@else
    <section class="eSection-wrap">
        @if($canMark)
            <form method="POST" action="{{ route('teacher.course_offerings.attendance.mark', [$offering->id, $session->id]) }}" id="mark-attendance-form" data-single-submit>
                @csrf
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-mark-all="present">Mark All Present</button>
                    <button class="btn btn-sm btn-primary">Save Attendance</button>
                </div>
        @endif
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th scope="col">Student Number</th>
                        <th scope="col">Student Name</th>
                        <th scope="col">{{ $programmeLabel }}</th>
                        <th scope="col">Year / Stage</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($roster as $registration)
                    @php $current = $registration->marked_status; @endphp
                    <tr>
                        <td>{{ $registration->registration_number ?: '—' }}</td>
                        <td>{{ $registration->student_name }}</td>
                        <td>{{ $registration->programme_code ?: $registration->programme_name ?: '—' }}</td>
                        <td>{{ $registration->year_of_study ?: '—' }}</td>
                        <td>
                            @if($canMark)
                                {{-- The authoritative roster identity is course_registrations.id.
                                     The SELECT submits the chosen status; the registration id
                                     travels in a hidden field. These two were previously
                                     swapped, so the select posted a 0-3 status value as
                                     "course_registration_id" and validation refused the whole
                                     register with "must be at least 1". --}}
                                <input type="hidden" name="marks[{{ $registration->id }}][course_registration_id]" value="{{ $registration->id }}">
                                <select class="form-select form-select-sm attendance-status" name="marks[{{ $registration->id }}][status]" data-registration-id="{{ $registration->id }}" aria-label="Attendance status for {{ $registration->student_name }}">
                                    {{-- An unmarked student must never look "Absent": the empty
                                         value means "not marked" and is skipped server-side. --}}
                                    <option value="" @selected($current === null)>Not marked</option>
                                    @foreach($statuses as $value => $label)
                                        <option value="{{ $value }}" @selected($current !== null && $current === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            @else
                                @if($current === null)<span class="badge bg-light text-dark border">Not marked</span>
                                @else<span class="badge bg-{{ $current === 1 ? 'success' : ($current === 2 ? 'primary' : ($current === 3 ? 'secondary' : 'danger')) }}">{{ $statuses[$current] }}</span>@endif
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if($canMark)</form>@endif
    </section>
@endif

@if($canTeach)
    <div class="d-flex flex-wrap gap-2 mt-3">
        @if($canMark)
            <form method="POST" action="{{ route('teacher.course_offerings.attendance.finalise', [$offering->id, $session->id]) }}" onsubmit="return confirm('Finalise this register? It becomes read-only; only the academic office can correct it afterwards.')">
                @csrf<button class="btn btn-success">Finalise Session</button>
            </form>
        @endif
    </div>
@endif

@if($canMark)
<script>
    // A row left on "Not marked" (empty value) is removed from the submission
    // entirely, so a partially completed register saves cleanly. The server
    // never converts an unmarked student into Absent.
    var form = document.getElementById('mark-attendance-form');
    if (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('select.attendance-status').forEach(function (select) {
                var row = select.closest('tr');
                if (select.value === '') {
                    var input = row.querySelector('input[type="hidden"][name$="[course_registration_id]"]');
                    if (input) { input.disabled = true; }
                    select.disabled = true;
                }
            });
        });
    }
    // Bulk helper: sets a real status on every row (never a fabricated default).
    var markAll = document.querySelector('[data-mark-all="present"]');
    if (markAll) {
        markAll.addEventListener('click', function (event) {
            event.preventDefault();
            form.querySelectorAll('select.attendance-status').forEach(function (select) {
                select.value = markAll.getAttribute('data-mark-all');
            });
        });
    }
</script>
@endif
@endsection
