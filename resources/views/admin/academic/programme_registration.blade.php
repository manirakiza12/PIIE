@extends('admin.navigation')
@section('content')
@php
    $permissions = app(\App\Support\Permissions\PermissionService::class);
    $canManage = $permissions->allows(auth()->user(), 'academic.course_registration.manage');
    $canConfirm = $permissions->allows(auth()->user(), 'academic.course_registration.confirm');
    $canPlace = $permissions->allows(auth()->user(), 'academic.programme_cohort.membership');
    $filters = ['programme_id' => $programme?->id, 'curriculum_id' => $curriculum?->id, 'stage_id' => $stage?->id, 'academic_year_id' => $year?->id, 'period_id' => $period?->id];
    if ($page > 1) $filters['page'] = $page;
@endphp
<div class="mainSection-title"><div><h4>Register Students for Courses</h4><p class="text-muted mb-0">Choose the programme and teaching period. Select students and courses, then register them here.</p></div></div>
<section class="eSection-wrap">
    @if($errors->any())<div class="alert alert-danger" role="alert">@foreach($errors->all() as $error)<p class="mb-1">{{ $error }}</p>@endforeach</div>@endif
    @if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
    @if(session('programme_registration_results'))
    <div class="alert alert-info" role="status"><strong>Results</strong>
        @foreach(session('programme_registration_results') as $result)
        <p class="mb-1">{{ $result['course'] }}: {{ $result['summary']['registered'] }} registered, {{ $result['summary']['confirmed'] }} confirmed, {{ $result['summary']['already'] }} already done, {{ $result['summary']['skipped'] }} need attention.</p>
        @if($result['summary']['details'])<ul>@foreach($result['summary']['details'] as $detail)<li>{{ $detail['student'] }}: {{ $detail['reason'] }}</li>@endforeach</ul>@endif
        @endforeach
    </div>
    @endif
    <form method="GET" action="{{ route('admin.course_registrations.index') }}" id="registration-filters" class="row g-3" data-workspace-continuous>
        <div class="col-md-6"><label for="programme" class="form-label">Programme</label><select id="programme" name="programme_id" class="form-select" required><option value="">Choose programme</option>@foreach($programmes as $item)<option value="{{ $item->id }}" @selected($programme?->id == $item->id)>{{ $item->code }} — {{ $item->name }}</option>@endforeach</select></div>
        <div class="col-md-6"><label for="academic-year" class="form-label">Academic year</label><select id="academic-year" name="academic_year_id" class="form-select" required><option value="">Choose academic year</option>@foreach($years as $item)<option value="{{ $item->id }}" @selected($year?->id == $item->id)>{{ $item->label }} ({{ \App\Support\Academic\AcademicDate::input($item->start_date) }} — {{ \App\Support\Academic\AcademicDate::input($item->end_date) }})</option>@endforeach</select></div>
        @if($curricula->count() > 1)<div class="col-md-4"><label for="curriculum" class="form-label">Programme curriculum</label><select id="curriculum" name="curriculum_id" class="form-select" required><option value="">Choose approved version</option>@foreach($curricula as $item)<option value="{{ $item->id }}" @selected($curriculum?->id == $item->id)>Version {{ $item->version }}</option>@endforeach</select></div>@elseif($curriculum)<input type="hidden" name="curriculum_id" value="{{ $curriculum->id }}">@endif
        <div class="col-md-4"><label for="study-year" class="form-label">Year of study</label><select id="study-year" name="stage_id" class="form-select" required><option value="">Choose year of study</option>@foreach($stages as $item)<option value="{{ $item->id }}" @selected($stage?->id == $item->id)>{{ $item->label }}</option>@endforeach</select></div>
        <div class="col-md-4"><label for="period" class="form-label">Semester / term</label><select id="period" name="period_id" class="form-select" required><option value="">Choose semester / term</option>@foreach($periods as $item)<option value="{{ $item->id }}" @selected($period?->id == $item->id)>{{ $item->label }}</option>@endforeach</select></div>
        <div class="col-md-4 d-flex align-items-end"><button class="btn btn-primary" formnovalidate>Show students and courses</button></div>
    </form>
    @if($programme && $curricula->isEmpty())<div class="alert alert-warning mt-3">This programme has no approved curriculum yet. @if($permissions->allows(auth()->user(), 'academic.curriculum.view'))<a href="{{ route('admin.curricula.index') }}">Review programme curricula</a>@else Ask the academic office to approve its curriculum.@endif</div>@endif
    @if(!$ready)<p class="text-muted mt-4">Choose the programme, year of study and semester / term above to continue.</p>@else
    <hr><h5>{{ $programme->code }} · {{ $stage->label }} · {{ $year->label }} · {{ $period->label }}</h5>
    @if($offerings->isEmpty())
        <div class="alert alert-warning">No courses have been allocated for this programme year and teaching period. @if($permissions->allows(auth()->user(), 'academic.course_offering.view'))<a href="{{ route('admin.course_offerings.index') }}">Review course allocations</a>@else Ask the academic office to allocate its courses.@endif</div>
    @endif
    <form id="register-students" method="POST" action="{{ route('admin.course_registrations.register') }}">@csrf
        @foreach($filters as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
        @if($offerings->isNotEmpty())
        <fieldset class="my-3"><legend class="fs-6">Courses to register</legend><div class="d-flex flex-wrap gap-3">
        @foreach($offerings as $offering)<label class="border rounded p-2"><input type="checkbox" name="offering_ids[]" value="{{ $offering->id }}" @checked($offering->status === 'open')> {{ $subjects->get($offering->subject_id)?->name ?? $offering->reference }} <span class="text-muted">({{ \App\Support\CourseOffering\CourseOfferingService::statusLabel($offering->status) }})</span></label>@endforeach
        </div></fieldset>
        @endif
    </form>
    <p class="text-muted">All students in this programme are shown, including those whose year of study is missing. Existing academic placements are preserved.</p>
    <div class="table-responsive"><table class="table align-middle"><caption class="visually-hidden">Programme students and their course registration status</caption><thead><tr><th><input type="checkbox" id="select-page" aria-label="Select all students on this page"></th><th>Student</th><th>Year of study</th><th>Courses and registration status</th></tr></thead><tbody>
    @forelse($students as $student)
        @php $placement = $placements->get($student->id, collect())->first(); @endphp
        <tr><td><input form="register-students" type="checkbox" name="student_ids[]" value="{{ $student->id }}" aria-label="Select {{ $student->name }}"></td><td><strong>{{ $student->name }}</strong><div class="text-muted">{{ $student->code }}</div></td>
        <td>@if(!$placement)
            <span class="text-warning">Year of study missing</span>
            @if($canPlace)<form method="POST" action="{{ route('admin.course_registrations.place') }}" class="mt-2">@csrf @foreach($filters as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach<input type="hidden" name="student_id" value="{{ $student->id }}"><button class="btn btn-sm btn-outline-primary">Set {{ $stage->label }} for {{ $year->label }}</button></form>@else<div>Ask the academic office to set the student's year of study.</div>@endif
        @else
            @if((int)$placement->curriculum_id === (int)$curriculum->id && (int)$placement->entry_curriculum_stage_id === (int)$stage->id && (int)$placement->entry_academic_year_id === (int)$year->id)<span>{{ $stage->label }}</span>@else<span class="text-warning">Placed in another year or curriculum</span>@if($canPlace)<div><a href="{{ route('admin.academic.direct_placement', ['placement_id' => $placement->id]) }}">Review placement</a></div>@endif @endif
        @endif</td>
        <td>
        @php
            $studentCells = collect($cells[$student->id] ?? []);
            $readyCount = $studentCells->where('ready', true)->count();
            $registeredCount = $studentCells->filter(fn ($cell) => $cell['registration'] && $cell['registration']->status === 'registered')->count();
            $confirmedCount = $studentCells->filter(fn ($cell) => $cell['registration'] && $cell['registration']->status === 'confirmed')->count();
            $attentionCount = $studentCells->filter(fn ($cell) => $cell['reason'] !== null || $cell['registration']?->status === 'dropped')->count();
        @endphp
        @if($offerings->isNotEmpty())<details><summary>{{ $readyCount }} ready · {{ $registeredCount }} registered · {{ $confirmedCount }} confirmed @if($attentionCount) · {{ $attentionCount }} need attention @endif</summary><div class="mt-2">@endif
        @forelse($offerings as $offering)
            @php $cell = $cells[$student->id][$offering->id]; @endphp
            <div class="mb-2"><strong>{{ $subjects->get($offering->subject_id)?->name ?? $offering->reference }}</strong>:
            @if($cell['registration'])<span class="badge {{ $cell['registration']->status === 'confirmed' ? 'bg-success' : 'bg-secondary' }}">{{ $cell['registration']->status === 'registered' ? 'Registered — awaiting confirmation' : ucfirst($cell['registration']->status) }}</span>
            @elseif($cell['ready'])<span class="text-success">Ready to register</span>
            @else<span>{{ $cell['reason'] }}</span>@endif
            <a class="ms-1" href="{{ route('admin.course_registrations.index', ['allocation_id' => $offering->id]) }}">Details</a></div>
        @empty<span class="text-muted">No allocated courses for this selection.</span>@endforelse
        @if($offerings->isNotEmpty())</div></details>@endif</td></tr>
    @empty<tr><td colspan="4" class="py-4 text-center">No students are linked to this programme. Check the programme on their student profiles.</td></tr>@endforelse
    </tbody></table></div>
    @if($students->isNotEmpty() && $offerings->isNotEmpty())
    <div class="d-flex flex-wrap gap-2 my-3">@if($canManage)<button form="register-students" class="btn btn-primary">Register selected students</button>@endif
    @if($canConfirm)<button form="register-students" formaction="{{ route('admin.course_registrations.confirm') }}" class="btn btn-outline-primary">Confirm selected registrations</button>@endif</div>
    <p class="text-muted small">Applies to the selected courses and students on this page. Students who need attention are skipped with an explanation. Confirmation checks academic and fee requirements again.</p>
    @endif
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
    changeFilter('programme', ['curriculum_id', 'stage_id']);
    changeFilter('curriculum', ['stage_id']);
    changeFilter('academic-year', ['period_id']);
    const selectPage = document.getElementById('select-page');
    if (selectPage) selectPage.addEventListener('change', function () {
        document.querySelectorAll('input[name="student_ids[]"]').forEach(box => { box.checked = selectPage.checked; });
    });
});
</script>
@endsection
