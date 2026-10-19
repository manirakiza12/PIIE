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
            <a href="{{ route('admin.course_registrations.index') }}" class="btn btn-sm btn-outline-secondary mb-2">All Course Registrations</a>
            <h4 class="mb-1">Student Registration</h4>
            <p class="text-muted mb-0">Register eligible students for this Course Allocation, then confirm their registrations. Only confirmed students can join its Live Classes.</p>
        </div>
    </div>
</div>

<section class="eSection-wrap mb-3" aria-label="How students reach course registration">
    <h5>How students get into these lists</h5>
    <ol class="mb-2">
        <li>Create the student account in <strong>Students</strong>.</li>
        <li>In <strong>Student Placement</strong>, assign the student to the approved Programme Curriculum, Programme Year and Academic Year.</li>
        <li>In <strong>Course Allocation</strong>, link that curriculum's course unit and matching semester or term, then open the allocation.</li>
        <li>The student appears under <strong>Not Registered</strong>. A student who fails a placement or fee check appears under <strong>Needs Attention</strong> with the reason.</li>
        <li>Select <strong>Register</strong> or <strong>Register Selected</strong>. The student moves to <strong>Registered Students</strong>, awaiting confirmation.</li>
        <li>Select <strong>Confirm</strong> to activate the registration. Withdrawn registrations remain in history.</li>
    </ol>
    <p class="mb-0 text-muted">These lists are calculated automatically; there is no separate upload or Add Student action here. Missing students need a matching academic placement first. Registration and confirmation both recheck eligibility, including the 20% fee requirement or an authorised waiver.</p>
    @if(!$canManage)<div class="alert alert-info mt-3 mb-0">Your account can view this list but cannot register students. An administrator must grant Manage Course Registrations access.</div>@endif
    @if(!$isOpen)<div class="alert alert-warning mt-3 mb-0">This allocation is {{ str_replace('_', ' ', $offering->status) }}. Registration controls are available only when the allocation is Open.</div>@endif
</section>

<section class="eSection-wrap mb-3" aria-label="Course Allocation">
    <div class="row g-3">
        <div class="col-12 col-md-6 col-xl-4"><div class="text-muted small">Course Unit</div><div class="fw-semibold">{{ $offering->subject?->code ? $offering->subject->code.' — ' : '' }}{{ $offering->subject?->name }}</div><div class="small text-muted">{{ $offering->reference ?: '' }}</div></div>
        <div class="col-6 col-md-3 col-xl-2"><div class="text-muted small">Academic Year</div><div>{{ $offering->academicYear?->label }}</div></div>
        <div class="col-6 col-md-3 col-xl-2"><div class="text-muted small">Academic Period</div><div>{{ $offering->academicPeriod?->label }}</div></div>
        <div class="col-6 col-md-6 col-xl-2"><div class="text-muted small">Programme Year</div><div>{{ $stageLabels->isNotEmpty() ? $stageLabels->implode(', ') : 'Not linked yet' }}</div></div>
        <div class="col-6 col-md-6 col-xl-2"><div class="text-muted small">Allocation Status</div><span class="badge bg-{{ $isOpen ? 'success' : 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $offering->status)) }}</span></div>
    </div>
    <div class="row g-2 mt-3" aria-label="Student registration summary">
        @foreach(['not_registered' => 'Not Registered', 'registered' => 'Awaiting Confirmation', 'confirmed' => 'Confirmed', 'dropped' => 'Withdrawn'] as $key => $label)
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
    <li class="nav-item"><a class="nav-link {{ $mode === 'eligible' ? 'active' : '' }}" @if($mode === 'eligible') aria-current="page" @endif href="{{ route('admin.course_offerings.eligible_students', $offering->id) }}">Not Registered ({{ $counts['not_registered'] }})</a></li>
    <li class="nav-item"><a class="nav-link {{ $mode === 'registered' ? 'active' : '' }}" @if($mode === 'registered') aria-current="page" @endif href="{{ route('admin.course_offerings.registrations', $offering->id) }}">Registered Students ({{ $counts['registered'] + $counts['confirmed'] }}) · History ({{ $counts['dropped'] }})</a></li>
</ul>

<section class="eSection-wrap">
@if($mode === 'eligible')
    <p class="text-muted">Not Registered lists active students with an academic placement in a linked Curriculum and no registration record for this offering. Withdrawn students appear in Registered Students and History.</p>
<h6>Eligible Students ({{ $students->count() }})</h6>
<p class="text-muted">Eligibility checks each student's academic placement and whether at least 20% of their current academic-session fees are paid. Both checks are repeated when you register, so changed records are skipped with a reason. An authorised admin may waive the payment threshold for an individual student; a reason is required and recorded.</p>
    @if(!$isOpen)
        <div class="alert alert-info">Students can be registered only while the Course Allocation is open.</div>
    @endif

    @if($students->isEmpty())
        <div class="alert alert-info mb-0">
            @if(!$isOpen) This Course Allocation is not open for registration.
            @elseif($stageLabels->isEmpty()) No Programme Curriculum is linked to this Course Allocation yet, so no students can be eligible. Link a Curriculum from the Course Allocation page.
            @else No students are currently eligible to register. @if($ineligible->isNotEmpty()) See the students who are not eligible below. @endif
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
                <thead><tr>@if($canManage && $isOpen)<th scope="col"><span class="visually-hidden">Select</span></th>@endif<th scope="col">Student Number</th><th scope="col">Student Name</th><th scope="col">Programme Year</th><th scope="col">Registration Status</th>@if($canManage && $isOpen)<th scope="col"><span class="visually-hidden">Action</span></th>@endif</tr></thead>
                <tbody>
                @foreach($students as $student)
                    <tr>
                        @if($canManage && $isOpen)<td><input class="form-check-input" type="checkbox" name="student_ids[]" value="{{ $student->id }}" id="student-{{ $student->id }}" aria-label="Select {{ $student->name }}"></td>@endif
                        <td>{{ $student->code ?: '—' }}</td>
                        <td><label for="student-{{ $student->id }}" class="mb-0">{{ $student->name }}</label>@if($student->roster_fee_waived)<div><span class="badge bg-info text-dark">Student fee waiver active</span></div>@endif</td>

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
        @if($canManageFeeWaivers)
            @foreach($students->where('roster_fee_waived', true) as $student)
                <details class="border rounded p-3 mb-2"><summary class="fw-semibold">Revoke {{ $student->name }}’s fee waiver</summary>
                    <form method="POST" action="{{ route('admin.course_offerings.students.fee_waiver.destroy', [$offering->id, $student->id]) }}" class="mt-2">@csrf @method('DELETE')
                        <label class="form-label" for="revoke-fee-waiver-{{ $student->id }}">Reason for revoking</label>
                        <input required minlength="10" maxlength="500" class="form-control mb-2" id="revoke-fee-waiver-{{ $student->id }}" name="reason" placeholder="Explain why the waiver is being revoked">
                        <button class="btn btn-sm btn-outline-danger">Revoke Fee Waiver</button>
                    </form>
                </details>
            @endforeach
        @endif
    @endif

    @if($ineligible->isNotEmpty())
        <h6 class="mt-4">Not Registered: Needs Attention ({{ $ineligible->count() }})</h6>
        <p class="text-muted small">These students follow a Curriculum linked to this Course Allocation but cannot register yet.</p>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th scope="col">Student Number</th><th scope="col">Student Name</th><th scope="col">Programme Year</th><th scope="col">Reason</th>@if($canManageFeeWaivers)<th scope="col">Fee Waiver</th>@endif</tr></thead>
            <tbody>@foreach($ineligible as $student)<tr><td>{{ $student->code ?: '—' }}</td><td>{{ $student->name }}</td><td>{{ $student->roster_stage ?: '—' }}</td><td>{{ $student->roster_reason }}</td>@if($canManageFeeWaivers)<td>@if($student->roster_can_waive_fees)<details><summary class="btn btn-sm btn-outline-warning text-nowrap">Waive 20% rule</summary><form method="POST" action="{{ route('admin.course_offerings.students.fee_waiver.store', [$offering->id, $student->id]) }}" class="mt-2">@csrf<label class="form-label small" for="grant-fee-waiver-{{ $student->id }}">Reason (required)</label><input required minlength="10" maxlength="500" class="form-control form-control-sm mb-1" id="grant-fee-waiver-{{ $student->id }}" name="reason" placeholder="Explain why this student is exempt"><button class="btn btn-sm btn-warning text-nowrap">Grant Fee Waiver</button></form></details>@else<span class="small text-muted">Waiver unavailable</span>@endif</td>@endif</tr>@endforeach</tbody>
        </table></div>
    @endif
@else
    <p class="text-muted">Registration and confirmation are separate steps. Students must remain academically eligible and have paid at least 20% of current academic-session fees for either step. Dropped registrations stay in the history.</p>
    @if($students->isEmpty())
        <div class="alert alert-info mb-0">No students are registered for this Course Allocation yet. <a class="alert-link" href="{{ route('admin.course_offerings.eligible_students', $offering->id) }}">Review eligible students</a> to begin registration.</div>
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
                                @if($canManage && $isOpen && $registration->status === 'registered')<button class="btn btn-sm btn-outline-danger" form="delete-pending-{{ $registration->id }}">Delete Pending</button>@endif
                                @if($canManage && in_array($registration->status, ['registered', 'confirmed'], true) && in_array($offering->status, ['open', 'in_progress'], true))<button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#drop-{{ $registration->id }}" aria-expanded="false" aria-controls="drop-{{ $registration->id }}">Withdraw</button>@endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </form>
        @foreach($students as $registration)
            @if($canManage && $isOpen && $registration->status === 'registered')<form method="POST" action="{{ route('admin.course_offerings.registrations.destroy', [$offering->id, $registration->id]) }}" id="delete-pending-{{ $registration->id }}" class="d-none" data-single-submit onsubmit="return confirm('Delete this pending registration before confirmation? The student account and placement are kept.')">@csrf @method('DELETE')</form>@endif
            @if($canConfirm && $isOpen && $registration->status === 'registered')
                <form method="POST" action="{{ route('admin.course_offerings.registrations.confirm', [$offering->id, $registration->id]) }}" id="confirm-one-{{ $registration->id }}" class="d-none" data-single-submit>@csrf</form>
            @endif
            @if($canManage && in_array($registration->status, ['registered', 'confirmed'], true) && in_array($offering->status, ['open', 'in_progress'], true))
                <form method="POST" action="{{ route('admin.course_offerings.registrations.drop', [$offering->id, $registration->id]) }}" id="drop-{{ $registration->id }}" class="collapse border rounded p-2 mb-2" data-single-submit onsubmit="return confirm('Withdraw this student from the Course Allocation? The registration stays in the history.')">
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
