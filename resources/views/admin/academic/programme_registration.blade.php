@extends('admin.navigation')
@section('content')
@php
    $permissions = app(\App\Support\Permissions\PermissionService::class);
    $canManage = $permissions->allows(auth()->user(), 'academic.course_registration.manage');
    $canConfirm = $permissions->allows(auth()->user(), 'academic.course_registration.confirm');
    $filters = ['programme_id' => $programme?->id, 'curriculum_id' => $curriculum?->id, 'stage_id' => $stage?->id, 'academic_year_id' => $year?->id, 'period_id' => $period?->id];
    if ($selectedStudent) $filters['student_id'] = $selectedStudent->id;
    if ($page > 1) $filters['page'] = $page;
    if ($studentSearch !== '') $filters['student_search'] = $studentSearch;
@endphp
<div class="mainSection-title"><div><h4>Student Course Registration</h4><p class="text-muted mb-0">Review each student's academic placement, current fee requirement and every course unit in the selected Programme Year and semester.</p></div></div>
<section class="eSection-wrap">
    @if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<p class="mb-1">{{ $error }}</p>@endforeach</div>@endif
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if(session('programme_registration_results'))
    <div class="alert alert-info" role="status"><strong>Registration results</strong>
        @foreach(session('programme_registration_results') as $result)
        <p class="mb-1">{{ $result['course'] }}: {{ $result['summary']['registered'] }} registered, {{ $result['summary']['confirmed'] }} confirmed, {{ $result['summary']['already'] }} already recorded, {{ $result['summary']['skipped'] }} need review.</p>
        @if($result['summary']['details'])<ul>@foreach($result['summary']['details'] as $detail)<li>{{ $detail['student'] }}: {{ $detail['reason'] }}</li>@endforeach</ul>@endif
        @endforeach
    </div>
    @endif
    <form method="GET" action="{{ route('admin.course_registrations.index') }}" id="registration-filters" class="row g-3" data-workspace-continuous>
        @if($selectedStudent)<input type="hidden" name="student_id" value="{{ $selectedStudent->id }}">@endif
        <div class="col-md-6"><label for="programme" class="form-label">Programme</label><select id="programme" name="programme_id" class="form-select" required><option value="">Choose programme</option>@foreach($programmes as $item)<option value="{{ $item->id }}" @selected($programme?->id == $item->id)>{{ $item->code }} — {{ $item->name }}</option>@endforeach</select></div>
        <div class="col-md-6"><label for="academic-year" class="form-label">Academic year</label><select id="academic-year" name="academic_year_id" class="form-select" required><option value="">Choose academic year</option>@foreach($years as $item)<option value="{{ $item->id }}" @selected($year?->id == $item->id)>{{ $item->label }} ({{ \App\Support\Academic\AcademicDate::input($item->start_date) }} — {{ \App\Support\Academic\AcademicDate::input($item->end_date) }})</option>@endforeach</select></div>
        @if($curricula->count() > 1)<div class="col-md-4"><label for="curriculum" class="form-label">Programme curriculum</label><select id="curriculum" name="curriculum_id" class="form-select" required><option value="">Choose approved version</option>@foreach($curricula as $item)<option value="{{ $item->id }}" @selected($curriculum?->id == $item->id)>Version {{ $item->version }}</option>@endforeach</select></div>@elseif($curriculum)<input type="hidden" name="curriculum_id" value="{{ $curriculum->id }}">@endif
        <div class="col-md-4"><label for="study-year" class="form-label">Programme Year</label><select id="study-year" name="stage_id" class="form-select" required @disabled(!$curriculum)><option value="">Choose Programme Year</option>@foreach($stages as $item)<option value="{{ $item->id }}" @selected($stage?->id == $item->id)>{{ $item->label }}</option>@endforeach</select></div>
        <div class="col-md-4"><label for="period" class="form-label">Semester / term</label><select id="period" name="period_id" class="form-select" required><option value="">Choose semester / term</option>@foreach($periods as $item)<option value="{{ $item->id }}" @selected($period?->id == $item->id)>{{ $item->label }}</option>@endforeach</select></div>
        @if(!$selectedStudent)<div class="col-md-8"><label for="student-search" class="form-label">Find student by name or registration number</label><input id="student-search" type="search" name="student_search" value="{{ $studentSearch }}" maxlength="100" class="form-control" placeholder="All students in this programme"></div>@endif
        <div class="col-md-4 d-flex align-items-end gap-2"><button class="btn btn-primary" formnovalidate>Find students and course units</button>@if($studentSearch !== '')<a class="btn btn-outline-secondary" href="{{ route('admin.course_registrations.index', array_diff_key($filters, ['student_search' => true, 'page' => true])) }}">Clear search</a>@endif</div>
    </form>
    @if($programme && $curricula->isEmpty())<div class="alert alert-warning mt-3">This programme needs an approved curriculum with its Programme Years and course units before registration. @if($permissions->allows(auth()->user(), 'academic.curriculum.view'))<a href="{{ route('admin.curricula.index', ['programme_id' => $programme->id]) }}">Set up this programme curriculum</a>@else Ask the academic office to approve its curriculum.@endif</div>@endif
    @if($selectedStudent && !$programme)<div class="alert alert-warning mt-3">This student has no Programme recorded on the profile or active academic placement. Next step: complete the student profile and governed academic placement before checking course eligibility.</div>@endif
    @if(!)@unless($programme && $curricula->isEmpty())<p class="text-muted mt-4">Choose a programme, Programme Year and semester / term above to continue.</p>@endunless @else
    <hr>
    @if(session('programme_placement_results'))<div class="alert alert-info" role="status">@foreach(session('programme_placement_results') as $result)<p class="mb-1">{{ $result['student'] }}: {{ $result['message'] }}</p>@endforeach</div>@endif
    <h5>{{ $programme->code }} · {{ $curriculum->version }} · {{ $stage->label }} · {{ $year->label }} · {{ $period->label }}</h5>
    <p class="text-muted">The curriculum determines which units apply. A Course Allocation makes a unit available for this period. “In progress” describes teaching in that allocation; each student's registration is shown separately as awaiting confirmation, confirmed or withdrawn.</p>
    @if($curriculumUnits->isEmpty())<div class="alert alert-warning">No course units are recorded for this Programme Year and semester in the approved curriculum. Next step: review the curriculum setup.</div>@endif
    <form id="register-students" method="POST" action="{{ route('admin.course_registrations.register') }}">@csrf<input type="hidden" name="register_all_eligible" value="1">
        @foreach($filters as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
        @if($curriculumUnits->isNotEmpty())
        <fieldset class="my-3"><legend class="fs-6">Course units for this Programme Year and semester</legend><div class="d-flex flex-column gap-2">
        @foreach($curriculumUnits as $unit)
            @php $allocations = $unitAllocations->get($unit->membership_id, collect()); @endphp
            <div class="border rounded p-2">
                <strong>{{ $unit->code }} — {{ $unit->name }}</strong>
                <span class="text-muted">({{ ucfirst($unit->classification) }}, {{ number_format((float) $unit->credits, 2) }} credits)</span>
                @if($allocations->count() === 1)
                    @php $allocation = $allocations->first(); @endphp
                    <span class="ms-2">Course Allocation: {{ \App\Support\CourseOffering\CourseOfferingService::statusLabel($allocation->status) }}</span>
                    @if($allocation->status !== 'open')<div class="small text-muted">This allocation does not accept new registrations. Next step: the academic office can review or reopen it if appropriate.</div>@endif
                @elseif($allocations->count() > 1)
                    <div class="small text-warning">Multiple Course Allocations are linked to this unit for the selected period. Next step: ask the academic office to review them.</div>
                @else
                    <div class="small text-muted">Not allocated for this period. Next step: ask the academic office to create the Course Allocation.</div>
                @endif
            </div>
        @endforeach
        </div></fieldset>
        @endif
    </form>
    <h6>Students and eligibility</h6>
    <p class="text-muted">Admission creates the student account after the application is accepted and enrolled. Academic eligibility then comes from the student's active Programme placement and the selected curriculum year. Existing placements and registrations are preserved.</p>
    <div class="table-responsive"><table class="table align-middle"><caption class="visually-hidden">Programme students and their course registration status</caption><thead><tr><th><input type="checkbox" id="select-page" aria-label="Select all students on this page"></th><th>Student</th><th>Programme placement</th><th>Applicable course units and next steps</th></tr></thead><tbody>
    @forelse($students as $student)
        @php $placement = $placements->get($student->id, collect())->first(); @endphp
        <tr><td><input form="register-students" type="checkbox" name="student_ids[]" value="{{ $student->id }}" @checked($selectedStudent && (int) $selectedStudent->id === (int) $student->id) aria-label="Select {{ $student->name }}"></td><td><strong>{{ $student->name }}</strong><div class="text-muted">{{ $student->code }}</div></td>
        <td>@if(!$placement)
            <span class="text-warning">No academic placement recorded for this year.</span>
            @if($canPlace)<div class="small"><a href="{{ route('admin.academic.direct_placement', ['student_id' => $student->id]) }}">Record Programme placement</a></div>@else<div class="small text-muted">Next step: an authorised academic placement administrator must record it.</div>@endif
        @elseif((int)$placement->curriculum_id === (int)$curriculum->id && (int)$placement->entry_curriculum_stage_id === (int)$stage->id && (int)$placement->entry_academic_year_id === (int)$year->id)
            <span>{{ $stage->label }} · {{ $year->label }}</span>
        @else
            <span class="text-warning">Placement does not match this curriculum year.</span>
            <div class="small text-muted">Next step: review the recorded placement and progression for {{ $year->label }}.</div>
            @if($canPlace)<div class="small"><a href="{{ route('admin.academic.placements.index', ['student_id' => $student->id]) }}">Review placement history</a></div>@endif
        @endif</td>
        <td>
        @php
            $studentCells = $cells[$student->id] ?? [];
            $readyCount = $registeredCount = $confirmedCount = $attentionCount = 0;
            foreach ($studentCells as $studentCell) {
                if ($studentCell['ready']) $readyCount++;
                if (($studentCell['registration']->status ?? null) === 'registered') $registeredCount++;
                if (($studentCell['registration']->status ?? null) === 'confirmed') $confirmedCount++;
                if ($studentCell['reason'] !== null || ($studentCell['registration']->status ?? null) === 'dropped') $attentionCount++;
            }
        @endphp
        @if($curriculumUnits->isNotEmpty())
            <details>
                <summary>{{ $readyCount }} ready to register · {{ $registeredCount }} awaiting confirmation · {{ $confirmedCount }} confirmed @if($attentionCount) · {{ $attentionCount }} need review @endif</summary>
                <div class="mt-2">
        @endif
        @forelse($curriculumUnits as $unit)
            @php $cell = $cells[$student->id][$unit->membership_id]; @endphp
            <div class="mb-2"><strong>{{ $unit->name }}</strong>:
            @if($cell['allocations']->count() === 1)<span class="text-muted">Allocation {{ \App\Support\CourseOffering\CourseOfferingService::statusLabel($cell['offering']->status) }}.</span>@endif
            @if($cell['registration'])
                <span class="badge {{ $cell['registration']->status === 'confirmed' ? 'bg-success' : ($cell['registration']->status === 'registered' ? 'bg-secondary' : 'bg-warning text-dark') }}">{{ $cell['registration']->status === 'registered' ? 'Registered — awaiting confirmation' : ($cell['registration']->status === 'confirmed' ? 'Confirmed registration' : 'Withdrawn registration') }}</span>
            @elseif($cell['ready'])
                <span class="text-success">Eligible to register.</span>
            @else
                <span>{{ $cell['reason'] }}</span>
            @endif
            @if($cell['offering'])<a class="ms-1" href="{{ route('admin.course_registrations.index', ['allocation_id' => $cell['offering']->id]) }}">Allocation details</a>@endif
            </div>
        @empty<span class="text-muted">No units are defined for this Programme Year and semester.</span>@endforelse
        @if($curriculumUnits->isNotEmpty())</div></details>@endif</td></tr>
    @empty<tr><td colspan="4" class="py-4 text-center">No students are linked to this programme. Check the programme recorded on their student profiles or placements.</td></tr>@endforelse
    </tbody></table></div>
    @if($students->isNotEmpty() && $curriculumUnits->isNotEmpty())
    @if($canPlace && $canManage)<div class="form-check my-3"><input form="register-students" class="form-check-input" type="checkbox" name="place_missing" value="1" id="place-missing" checked><label class="form-check-label" for="place-missing">Set missing placements to {{ $stage->label }} for {{ $year->label }} when registering. Existing placements stay unchanged.</label></div>@endif
    <div class="d-flex flex-wrap gap-2 my-3">
    @if($offerings->contains('status', 'open'))
        @if($canManage && $canConfirm)<button form="register-students" name="confirm_now" value="1" class="btn btn-primary">Register and confirm all eligible allocated units</button>
        @elseif($canManage)<button form="register-students" class="btn btn-primary">Register all eligible allocated units</button>
        @elseif($canConfirm)<button form="register-students" formaction="{{ route('admin.course_registrations.confirm') }}" class="btn btn-primary">Confirm selected registrations</button>@endif
    @elseif($canManage || $canConfirm)
        <button type="button" class="btn btn-secondary" disabled>No open allocation accepts registration</button>
    @endif
    </div>
    <p class="text-muted small">Select students. The action checks every open Course Allocation for this Programme Year and semester, then registers each eligible unit. Pending payment or waiver claims need authorised review first. Registered units await confirmation; only authorised staff can confirm them.</p>
    @if($canManage && $canConfirm)<details class="my-3"><summary>Other registration actions</summary><div class="d-flex flex-wrap gap-2 mt-2"><button form="register-students" class="btn btn-outline-secondary">Register without confirmation</button><button form="register-students" formaction="{{ route('admin.course_registrations.confirm') }}" class="btn btn-outline-secondary">Confirm existing registrations</button></div></details>@endif
    @endif
    @if($canPlace)<details class="my-3"><summary>Placement history and corrections</summary><p class="mt-2 mb-0"><a href="{{ route('admin.academic.placements.index', $selectedStudent ? ['student_id' => $selectedStudent->id] : []) }}">Review student placement history</a>. Existing course registration history protects the academic placement from editing or deletion.</p></details>@endif
    {{ $students->links() }}
    @endif
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('registration-filters');
    function changeFilter(id, clear) {
        const field = document.getElementById(id);
        if (!field) return;
        field.addEventListener('change', function () {
            clear.forEach(name => { const control = form.elements.namedItem(name); if(control) control.value = ''; });
            form.submit();
        });
    }
    changeFilter('programme', ['curriculum_id', 'stage_id', 'student_search', 'student_id']);
    changeFilter('curriculum', ['stage_id']);
    changeFilter('academic-year', ['period_id']);
    changeFilter('study-year', []);
    changeFilter('period', []);
    const selectPage = document.getElementById('select-page');
    if (selectPage) selectPage.addEventListener('change', function () {
        document.querySelectorAll('input[name="student_ids[]"]').forEach(box => { box.checked = selectPage.checked; });
    });
});
</script>
@endsection