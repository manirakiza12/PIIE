@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><h4>Create Course Offering</h4><p class="text-muted">Choose the {{ $courseUnitLabel }}, Academic Year and {{ $periodLabel }} for this teaching period. Programme Curricula can be linked after the Offering is created.</p></div>
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="eSection-wrap"><form method="POST" action="{{ route('admin.course_offerings.store') }}">@csrf
<div class="mb-3"><label class="form-label" for="subject">{{ $courseUnitLabel }}</label><select class="form-select" name="subject_id" id="subject" required><option value="">Choose</option>@foreach($subjects as $subject)<option value="{{ $subject->id }}" data-code="{{ $subject->code }}" @selected(old('subject_id')==$subject->id)>{{ $subject->code ? $subject->code.' — ' : '' }}{{ $subject->name }}</option>@endforeach</select></div>
<div class="row"><div class="col-md-6 mb-3"><label class="form-label" for="year">Academic Year</label><select class="form-select" name="academic_year_id" id="year" required><option value="">Choose</option>@foreach($years as $year)<option value="{{ $year->id }}" data-start-year="{{ $year->start_date?->format('Y') }}" @selected(old('academic_year_id',request('year_id'))==$year->id)>{{ $year->label }}</option>@endforeach</select></div><div class="col-md-6 mb-3"><label class="form-label" for="period">{{ $periodLabel }}</label><select class="form-select" name="academic_period_id" id="period" required><option value="">Choose</option>@foreach($periods as $period)<option data-year="{{ $period->academic_year_id }}" data-type="{{ $period->type }}" data-sequence="{{ $period->sequence }}" value="{{ $period->id }}" @selected(old('academic_period_id',request('period_id'))==$period->id)>{{ $period->label }}</option>@endforeach</select></div></div>
<div class="mb-3"><label class="form-label" for="reference-preview">Offering Reference</label><input class="form-control" id="reference-preview" type="text" value="" readonly aria-describedby="reference-help"><div class="form-text" id="reference-help">Generated automatically after selecting the {{ $courseUnitLabel }}, Academic Year and {{ $periodLabel }}. The server assigns the authoritative tenant-unique reference.</div></div>
@if(request('curriculum_id'))<div class="alert alert-info">Curriculum context selected. This action does not create Offerings automatically; applicability is reviewed after draft creation.</div>@endif
<button type="submit" class="eBtn eBtn-primary">Create Course Offering</button> <a class="btn btn-light" href="{{ route('admin.course_offerings.index') }}">Cancel</a></form></div>
<script>
    (function () {
        const year = document.getElementById('year');
        const period = document.getElementById('period');
        const subject = document.getElementById('subject');
        const reference = document.getElementById('reference-preview');
        function normalizedCode(code, fallback) {
            return (code || '').toUpperCase().trim().replace(/[^A-Z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'CU' + fallback;
        }
        function previewReference() {
            const selectedSubject = subject.options[subject.selectedIndex];
            const selectedYear = year.options[year.selectedIndex];
            const selectedPeriod = period.options[period.selectedIndex];
            if (!selectedSubject || !selectedSubject.value || !selectedYear || !selectedYear.value || !selectedPeriod || !selectedPeriod.dataset.type) {
                reference.value = '';
                return;
            }
            const type = selectedPeriod.dataset.type.toLowerCase();
            const abbreviation = type === 'semester' ? 'S' : (type === 'term' ? 'T' : (type.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 2) || 'P'));
            reference.value = normalizedCode(selectedSubject.dataset.code, selectedSubject.value) + '-' + selectedYear.dataset.startYear + '-' + abbreviation + selectedPeriod.dataset.sequence;
        }
        function filterPeriods() {
            const selectedYear = year.value;
            period.querySelectorAll('option[data-year]').forEach(function (option) {
                option.hidden = selectedYear !== '' && option.dataset.year !== selectedYear;
                if (option.hidden && option.selected) period.value = '';
            });
            previewReference();
        }
        subject.addEventListener('change', previewReference);
        period.addEventListener('change', previewReference);
        year.addEventListener('change', filterPeriods);
        filterPeriods();
    })();
</script>
@endsection
