@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><div><h4>Place Student in Programme</h4><p class="text-muted mb-0">Choose the student, approved Programme Curriculum, Study Stage and entry Academic Year.</p></div></div>
<div class="row"><div class="col-lg-8"><div class="eSection-wrap">
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($existing)<div class="alert alert-warning">This student already has an active academic placement.</div>@endif
    <form method="POST" action="{{ route('admin.academic.direct_placement.store') }}">@csrf
        <div class="mb-3"><label class="form-label" for="student_id">Student</label><select id="student_id" name="student_id" class="form-select" required @disabled($existing)><option value="">Choose student</option>@if($student && $existing)<option value="{{ $student->id }}" selected>{{ $student->name }} · {{ $student->email }}</option>@endif @foreach($students as $item)<option value="{{ $item->id }}" @selected(old('student_id', $student?->id)==$item->id)>{{ $item->name }} · {{ $item->email }}</option>@endforeach</select>@if($existing)<input type="hidden" name="student_id" value="{{ $student->id }}">@endif</div>
        <div class="mb-3"><label class="form-label" for="curriculum_id">Approved Programme Curriculum</label><select id="curriculum_id" name="curriculum_id" class="form-select" required><option value="">Choose curriculum</option>@foreach($curricula as $item)<option value="{{ $item->id }}" @selected(old('curriculum_id', request('curriculum_id'))==$item->id)>{{ $item->programme?->code }} — {{ $item->programme?->name }} · v{{ $item->version }}</option>@endforeach</select></div>
        <div class="mb-3"><label class="form-label" for="entry_curriculum_stage_id">Study Stage / Year</label><select id="entry_curriculum_stage_id" name="entry_curriculum_stage_id" class="form-select" required><option value="">Choose stage</option>@foreach($stages as $stage)<option value="{{ $stage->id }}" data-curriculum="{{ $stage->curriculum_id }}" @selected(old('entry_curriculum_stage_id')==$stage->id)>{{ $stage->sequence }}. {{ $stage->label }}</option>@endforeach</select></div>
        <div class="mb-3"><label class="form-label" for="academic_year_id">Entry Academic Year</label><select id="academic_year_id" name="academic_year_id" class="form-select" required><option value="">Choose academic year</option>@foreach($years as $year)<option value="{{ $year->id }}" @selected(old('academic_year_id', $years->firstWhere('status', 'active')?->id)==$year->id)>{{ $year->label }}{{ $year->status==='active' ? ' (Current)' : '' }}</option>@endforeach</select></div>
        <button class="btn btn-primary" @disabled($existing)>Save Placement</button>
    </form>
</div></div></div>
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
