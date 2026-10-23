@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div><h4>{{ $placement ? 'Edit Student Placement' : 'Place Student in Programme' }}</h4><p class="text-muted mb-0">Choose the student, approved Programme Curriculum, Programme Year and entry Academic Year. Matching allocations will review the student under Not Registered; staff then register and confirm the student.</p><a class="btn btn-outline-secondary mt-2" href="{{ route('admin.academic.placements.index') }}">All Student Placements</a></div></div>
<div class="row"><div class="col-lg-8"><div class="eSection-wrap">
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($existing)<div class="alert alert-warning">{{ $placement ? 'This placement has academic history and is protected from correction here.' : 'This student already has an active academic placement.' }}</div>@endif
    @if($selectedStatus)<div class="alert alert-info"><strong>{{ $selectedStatus->name }} · {{ $selectedStatus->code }} · ID {{ $selectedStatus->id }}</strong><br>Profile programme: {{ $selectedStatus->profile_programme ?: 'Not recorded' }}; profile year: {{ $selectedStatus->profile_year ?: 'Not recorded' }}; intake: {{ $selectedStatus->intake_name ?: 'Not recorded' }}.<br>{{ app(\App\Support\Academic\StudentPlacementDirectory::class)->reason($selectedStatus) }}<br>Saving places this account in the selected curriculum, Programme Year and entry Academic Year. It does not create a class/section enrollment or course registrations.</div>@endif
    <form method="POST" action="{{ $placement ? route('admin.academic.placements.update', $placement->id) : route('admin.academic.direct_placement.store') }}">@csrf @if($placement) @method('PUT') @endif
        @if($placement)<p><strong>Student:</strong> {{ $student->name }}</p><input type="hidden" name="student_id" value="{{ $student->id }}">@else
        <div class="mb-3"><label class="form-label" for="student_id">Student</label><select id="student_id" name="student_id" class="form-select" required @disabled($existing)><option value="">Choose student</option>@if($student && $existing)<option value="{{ $student->id }}" selected>{{ $student->name }} · {{ $student->email }}</option>@endif @foreach($students as $item)<option value="{{ $item->id }}" @selected(old('student_id', $student?->id)==$item->id)>{{ $item->name }} · {{ $item->email }}</option>@endforeach</select>@if($existing)<input type="hidden" name="student_id" value="{{ $student->id }}">@endif</div>
        @endif
        <div class="mb-3"><label class="form-label" for="curriculum_id">Approved Programme Curriculum</label><select id="curriculum_id" name="curriculum_id" class="form-select" required><option value="">Choose curriculum</option>@foreach($curricula as $item)<option value="{{ $item->id }}" @selected(old('curriculum_id', $placement?->curriculum_id ?? request('curriculum_id'))==$item->id)>{{ $item->programme?->code }} — {{ $item->programme?->name }} · v{{ $item->version }}</option>@endforeach</select></div>
        <div class="mb-3"><label class="form-label" for="entry_curriculum_stage_id">Programme Year</label><select id="entry_curriculum_stage_id" name="entry_curriculum_stage_id" class="form-select" required><option value="">Choose Programme Year</option>@foreach($stages as $stage)<option value="{{ $stage->id }}" data-curriculum="{{ $stage->curriculum_id }}" @selected(old('entry_curriculum_stage_id', $placement?->entry_curriculum_stage_id)==$stage->id)>{{ $stage->sequence }}. {{ $stage->label }}</option>@endforeach</select></div>
        <div class="mb-3"><label class="form-label" for="academic_year_id">Entry Academic Year</label><select id="academic_year_id" name="academic_year_id" class="form-select" required><option value="">Choose academic year</option>@foreach($years as $year)<option value="{{ $year->id }}" @selected(old('academic_year_id', $placement?->entry_academic_year_id ?? $years->firstWhere('status', 'active')?->id)==$year->id)>{{ $year->label }} ({{ $year->start_date?->format('d/m/y') }} — {{ $year->end_date?->format('d/m/y') }}){{ $year->status==='active' ? ' (Current)' : '' }}</option>@endforeach</select></div>
        @if($placement)<div class="mb-3"><label class="form-label" for="correction-reason">Reason for correction</label><textarea id="correction-reason" name="reason" class="form-control" maxlength="500" required>{{ old('reason') }}</textarea></div>@endif
        <button class="btn btn-primary" @disabled($existing)>{{ $placement ? 'Update Placement' : 'Save Placement' }}</button>
    </form>
</div></div></div>
@include('admin.academic._student_placement_statuses')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const curriculum = document.getElementById('curriculum_id');
    const stage = document.getElementById('entry_curriculum_stage_id');
    function filterStages() {
        const selectedCurriculum = curriculum.value;
        [...stage.options].forEach(option => {
            if (!option.dataset.curriculum) return;
            option.hidden = selectedCurriculum !== '' && option.dataset.curriculum !== selectedCurriculum;
            option.disabled = option.hidden;
            if (option.disabled && option.selected) stage.value = '';
        });
    }
    curriculum.addEventListener('change', filterStages);
    filterStages();
});
</script>
@endsection
