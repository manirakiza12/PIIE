@extends('admin.navigation')
@section('content')
@php
    $statusLabels = ['registered' => 'Registered', 'confirmed' => 'Confirmed', 'dropped' => 'Dropped'];
    $statusBadges = ['registered' => 'warning text-dark', 'confirmed' => 'success', 'dropped' => 'secondary'];
    $isOpen = $offering->status === 'open';
    $summary = session('bulk_summary');
    $confirmable = $mode === 'registered' ? $students->where('status', 'registered') : collect();
@endphp
<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <a href="{{ route('admin.course_offerings.show', $offering->id) }}" class="btn btn-sm btn-outline-secondary mb-2">Back to Course Offering</a>
            <h4 class="mb-1">Student Registration</h4>
            <p class="text-muted mb-0">Register eligible students for this Course Offering, then confirm their registrations. Only confirmed students can join its Live Classes.</p>
        </div>
    </div>
</div>

<section class="eSection-wrap mb-3" aria-label="Course Offering">
    <div class="row g-3">
        <div class="col-12 col-md-6 col-xl-4"><div class="text-muted small">Course Unit</div><div class="fw-semibold">{{ $offering->subject?->code ? $offering->subject->code.' — ' : '' }}{{ $offering->subject?->name }}</div><div class="small text-muted">{{ $offering->reference ?: '' }}</div></div>
        <div class="col-6 col-md-3 col-xl-2"><div class="text-muted small">Academic Year</div><div>{{ $offering->academicYear?->label }}</div></div>
        <div class="col-6 col-md-3 col-xl-2"><div class="text-muted small">Academic Period</div><div>{{ $offering->academicPeriod?->label }}</div></div>
        <div class="col-6 col-md-6 col-xl-2"><div class="text-muted small">Study Plan Stage</div><div>{{ $stageLabels->isNotEmpty() ? $stageLabels->implode(', ') : 'Not linked yet' }}</div></div>
        <div class="col-6 col-md-6 col-xl-2"><div class="text-muted small">Offering Status</div><span class="badge bg-{{ $isOpen ? 'success' : 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $offering->status)) }}</span></div>
    </div>
    <div class="row g-2 mt-3" aria-label="Student registration summary">
        @foreach(['eligible' => 'Eligible', 'registered' => 'Registered', 'confirmed' => 'Confirmed', 'dropped' => 'Dropped'] as $key => $label)
            <div class="col-6 col-md-3"><div class="border rounded p-2 text-center h-100"><div class="fs-4 fw-semibold">{{ $counts[$key] }}</div><div class="small text-muted">{{ $label }}</div></div></div>
        @endforeach
    </div>
</section>

@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(is_array($summary))
    <div class="alert alert-{{ $summary['skipped'] > 0 ? 'warning' : 'success' }}" role="status">
        <strong>{{ $summary['action'] === 'confirmation' ? 'Confirmation' : 'Registration' }} summary:</strong>
        {{ $summary['reviewed'] }} {{ \Illuminate\Support\Str::plural('student', $summary['reviewed']) }} reviewed ·
        @if($summary['action'] === 'confirmation'){{ $summary['confirmed'] }} confirmed · {{ $summary['already'] }} already confirmed @else{{ $summary['registered'] }} registered · {{ $summary['already'] }} already registered @endif
        · {{ $summary['skipped'] }} skipped
        @if(!empty($summary['details']))
            <ul class="mb-0 mt-2">@foreach($summary['details'] as $detail)<li><strong>{{ $detail['student'] }}:</strong> {{ $detail['reason'] }}</li>@endforeach</ul>
        @endif
    </div>
@endif

<ul class="nav nav-tabs mb-0" role="tablist">
    <li class="nav-item"><a class="nav-link {{ $mode === 'eligible' ? 'active' : '' }}" @if($mode === 'eligible') aria-current="page" @endif href="{{ route('admin.course_offerings.eligible_students', $offering->id) }}">Eligible Students ({{ $counts['eligible'] }})</a></li>
    <li class="nav-item"><a class="nav-link {{ $mode === 'registered' ? 'active' : '' }}" @if($mode === 'registered') aria-current="page" @endif href="{{ route('admin.course_offerings.registrations', $offering->id) }}">Registered Students ({{ $counts['registered'] + $counts['confirmed'] + $counts['dropped'] }})</a></li>
</ul>

<section class="eSection-wrap">
@if($mode === 'eligible')
    <p class="text-muted">Eligibility is checked against each student's academic placement: Programme, Study Plan, Year of Study (Study Plan Stage), Academic Year and current Programme Cohort. It is checked again when you register, so a student whose record changed in the meantime will be skipped with a reason.</p>
    @if(!$isOpen)
        <div class="alert alert-info">Students can be registered only while the Course Offering is open.</div>
    @endif

    @if($cohorts->isNotEmpty())
        <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
            <form method="GET" action="{{ route('admin.course_offerings.eligible_students', $offering->id) }}" class="d-flex flex-wrap gap-2 align-items-end">
                <div><label class="form-label small mb-1" for="cohort-filter">Programme Cohort</label>
                    <select id="cohort-filter" name="cohort_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All relevant cohorts</option>
                        @foreach($cohorts as $cohort)<option value="{{ $cohort->id }}" @selected($cohortId === (int) $cohort->id)>{{ $cohort->name }}</option>@endforeach
                    </select></div>
                <noscript><button class="btn btn-sm btn-outline-secondary">Filter</button></noscript>
            </form>
            @if($canManage && $isOpen && $cohortId !== null)
                <form method="POST" action="{{ route('admin.course_offerings.registrations.bulk', $offering->id) }}" data-single-submit onsubmit="return confirm('Register every eligible current member of this Programme Cohort? Each student is checked individually; ineligible students are skipped.')">
                    @csrf<input type="hidden" name="mode" value="cohort"><input type="hidden" name="programme_cohort_id" value="{{ $cohortId }}">
                    <button class="btn btn-sm btn-primary">Register Eligible Cohort Students</button>
                </form>
            @endif
        </div>
    @endif

    @if($students->isEmpty())
        <div class="alert alert-info mb-0">
            @if(!$isOpen) This Course Offering is not open for registration.
            @elseif($stageLabels->isEmpty()) No Programme Study Plan is linked to this Course Offering yet, so no students can be eligible. Link a Study Plan from the Course Offering page.
            @else No students are currently eligible to register{{ $cohortId ? ' from this Programme Cohort' : '' }}. @if($ineligible->isNotEmpty()) See the students who are not eligible below. @endif
            @endif
        </div>
    @else
        <form method="POST" action="{{ route('admin.course_offerings.registrations.bulk', $offering->id) }}" id="bulk-register-form" data-single-submit>
            @csrf<input type="hidden" name="mode" value="selected">
            @if($canManage && $isOpen)
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-select-all="bulk-register-form">Select All Eligible</button>
                    <button class="btn btn-sm btn-primary">Register Selected</button>
                </div>
            @endif
            <div class="table-responsive"><table class="table align-middle">
                <thead><tr>@if($canManage && $isOpen)<th scope="col"><span class="visually-hidden">Select</span></th>@endif<th scope="col">Student Number</th><th scope="col">Student Name</th><th scope="col">Programme Cohort</th><th scope="col">Year of Study (Stage)</th><th scope="col">Registration Status</th>@if($canManage && $isOpen)<th scope="col"><span class="visually-hidden">Action</span></th>@endif</tr></thead>
                <tbody>
                @foreach($students as $student)
                    <tr>
                        @if($canManage && $isOpen)<td><input class="form-check-input" type="checkbox" name="student_ids[]" value="{{ $student->id }}" id="student-{{ $student->id }}" aria-label="Select {{ $student->name }}"></td>@endif
                        <td>{{ $student->code ?: '—' }}</td>
                        <td><label for="student-{{ $student->id }}" class="mb-0">{{ $student->name }}</label></td>
                        <td>{{ $student->roster_cohort ?: '—' }}</td>
                        <td>{{ $student->roster_stage ?: '—' }}</td>
                        <td><span class="badge bg-light text-dark border">Not registered</span></td>
                        @if($canManage && $isOpen)<td><button class="btn btn-sm btn-outline-primary text-nowrap" form="register-one-{{ $student->id }}">Register</button></td>@endif
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </form>
        @if($canManage && $isOpen)
            @foreach($students as $student)
                <form method="POST" action="{{ route('admin.course_offerings.registrations.store', $offering->id) }}" id="register-one-{{ $student->id }}" class="d-none" data-single-submit>@csrf<input type="hidden" name="student_id" value="{{ $student->id }}"></form>
            @endforeach
        @endif
    @endif

    @if($ineligible->isNotEmpty())
        <h6 class="mt-4">Not eligible ({{ $ineligible->count() }})</h6>
        <p class="text-muted small">These students follow a Study Plan linked to this Course Offering, or belong to a relevant Programme Cohort, but cannot register yet.</p>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th scope="col">Student Number</th><th scope="col">Student Name</th><th scope="col">Programme Cohort</th><th scope="col">Year of Study (Stage)</th><th scope="col">Reason</th></tr></thead>
            <tbody>@foreach($ineligible as $student)<tr><td>{{ $student->code ?: '—' }}</td><td>{{ $student->name }}</td><td>{{ $student->roster_cohort ?: '—' }}</td><td>{{ $student->roster_stage ?: '—' }}</td><td>{{ $student->roster_reason }}</td></tr>@endforeach</tbody>
        </table></div>
    @endif
@else
    <p class="text-muted">Registration and confirmation are separate steps. Confirmation requires the student to remain academically eligible and financially cleared. Dropped registrations stay in the history.</p>
    @if($students->isEmpty())
        <div class="alert alert-info mb-0">No students are registered for this Course Offering yet. <a class="alert-link" href="{{ route('admin.course_offerings.eligible_students', $offering->id) }}">Review eligible students</a> to begin registration.</div>
    @else
        <form method="POST" action="{{ route('admin.course_offerings.registrations.confirm_bulk', $offering->id) }}" id="bulk-confirm-form" data-single-submit>
            @csrf
            @if($canConfirm && $isOpen && $confirmable->isNotEmpty())
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-select-all="bulk-confirm-form">Select All Registered</button>
                    <button class="btn btn-sm btn-success">Confirm Selected</button>
                </div>
            @endif
            <div class="table-responsive"><table class="table align-middle">
                <thead><tr>@if($canConfirm && $isOpen)<th scope="col"><span class="visually-hidden">Select</span></th>@endif<th scope="col">Student Number</th><th scope="col">Student Name</th><th scope="col">Programme</th><th scope="col">Year of Study</th><th scope="col">Status</th><th scope="col">Registered on</th>@if($canConfirm || $canManage)<th scope="col"><span class="visually-hidden">Actions</span></th>@endif</tr></thead>
                <tbody>
                @foreach($students as $registration)
                    <tr>
                        @if($canConfirm && $isOpen)<td>@if($registration->status === 'registered')<input class="form-check-input" type="checkbox" name="registration_ids[]" value="{{ $registration->id }}" aria-label="Select {{ $registration->student_name }}">@endif</td>@endif
                        <td>{{ $registration->registration_number ?: '—' }}</td>
                        <td>{{ $registration->student_name }}</td>
                        <td>{{ $registration->programme_code ?: '—' }}</td>
                        <td>{{ $registration->year_of_study ?: '—' }}</td>
                        <td><span class="badge bg-{{ $statusBadges[$registration->status] ?? 'secondary' }}">{{ $statusLabels[$registration->status] ?? ucfirst($registration->status) }}</span></td>
                        <td>{{ $registration->created_at?->format('Y-m-d') ?: '—' }}</td>
                        @if($canConfirm || $canManage)
                            <td class="text-nowrap">
                                @if($canConfirm && $isOpen && $registration->status === 'registered')<button class="btn btn-sm btn-success" form="confirm-one-{{ $registration->id }}">Confirm</button>@endif
                                @if($canManage && in_array($registration->status, ['registered', 'confirmed'], true) && in_array($offering->status, ['open', 'in_progress'], true))<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#drop-{{ $registration->id }}" aria-expanded="false" aria-controls="drop-{{ $registration->id }}">Withdraw</button>@endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </form>
        @foreach($students as $registration)
            @if($canConfirm && $isOpen && $registration->status === 'registered')
                <form method="POST" action="{{ route('admin.course_offerings.registrations.confirm', [$offering->id, $registration->id]) }}" id="confirm-one-{{ $registration->id }}" class="d-none" data-single-submit>@csrf</form>
            @endif
            @if($canManage && in_array($registration->status, ['registered', 'confirmed'], true) && in_array($offering->status, ['open', 'in_progress'], true))
                <form method="POST" action="{{ route('admin.course_offerings.registrations.drop', [$offering->id, $registration->id]) }}" id="drop-{{ $registration->id }}" class="collapse border rounded p-2 mb-2" data-single-submit onsubmit="return confirm('Withdraw this student from the Course Offering? The registration stays in the history.')">
                    @csrf<label class="form-label small" for="reason-{{ $registration->id }}">Withdrawal reason for {{ $registration->student_name }}</label>
                    <div class="d-flex flex-wrap gap-2"><input class="form-control form-control-sm" style="max-width: 28rem" id="reason-{{ $registration->id }}" name="reason" required maxlength="1000"><button class="btn btn-sm btn-outline-danger">Withdraw</button></div>
                </form>
            @endif
        @endforeach
    @endif
@endif
</section>
<script>
    document.querySelectorAll('[data-select-all]').forEach(function (button) {
        button.addEventListener('click', function () {
            var boxes = document.querySelectorAll('#' + button.dataset.selectAll + ' input[type=checkbox]');
            var select = Array.prototype.some.call(boxes, function (box) { return !box.checked; });
            boxes.forEach(function (box) { box.checked = select; });
        });
    });
    document.querySelectorAll('form[data-single-submit]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) return;
            if (form.dataset.submitted) { event.preventDefault(); return; }
            form.dataset.submitted = '1';
            document.querySelectorAll('[form="' + form.id + '"], #' + (form.id || '_none') + ' button[type=submit], #' + (form.id || '_none') + ' button:not([type])').forEach(function (b) { b.disabled = true; });
        });
    });
</script>
@endsection
