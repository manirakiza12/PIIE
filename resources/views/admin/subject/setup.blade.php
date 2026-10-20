@extends('admin.navigation')
@section('content')
<style>
@media (max-width: 575.98px) {
    .home-header { height: auto; min-height: 64px; }
    .home-header > .row { flex-wrap: nowrap; margin: 0; }
    .home-header > .row > .col-auto { padding: 0; }
    .home-header > .row > .col-auto.d-flex { align-items: center; gap: 4px; max-width: calc(100% - 28px); }
    .home-header .adminTable-action { margin: 0 !important; }
    .home-header #defaultDropdown { padding: 0; }
    .home-header #defaultDropdown > .text-start { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); padding: 0 !important; }
}
</style>
<div class="mainSection-title"><h4>{{ get_phrase(request('view') === '1' ? 'View Course Unit' : (isset($subject) ? ($isHigherEducation ? 'Edit Course Unit' : 'Edit Subject / Course Unit') : ($isHigherEducation ? 'Add Course Unit' : 'Add Subject / Course Unit'))) }}</h4></div>
@if($isHigherEducation)
<div class="alert alert-info" role="note"><strong>Academic Year and Semester:</strong> A Course Unit is a reusable catalogue record. Choose the dated teaching year and semester in the Course Allocation module.

</div>
@endif
@if(($isHigherEducation || $isMixed) && !isset($subject))
<div class="eSection-wrap mt-3">
    <h5>{{ get_phrase('Find an existing Course Unit') }}</h5>
    <p class="text-muted">{{ get_phrase('Search by name or code before creating a new unit.') }}</p>
    <form method="GET" action="{{ route('admin.subject.setup') }}" class="row g-2 align-items-end" role="search">
        <div class="col-12 col-md-8">
            <label for="existing-course-unit-search" class="form-label">{{ get_phrase('Course Unit name or code') }}</label>
            <input id="existing-course-unit-search" class="form-control" type="search" name="search" value="{{ $courseUnitSearch }}" placeholder="{{ get_phrase('Search by name or code') }}">
        </div>
        <div class="col-auto"><button class="btn btn-outline-primary" type="submit">{{ get_phrase('Search') }}</button></div>
        @if($courseUnitSearch !== '')<div class="col-auto"><a class="btn btn-outline-secondary" href="{{ route('admin.subject.setup') }}">{{ get_phrase('Clear') }}</a></div>@endif
    </form>
    @if($courseUnitSearch !== '')
        @if($existingCourseUnits->isEmpty())
            <p class="mt-3 mb-0" role="status">{{ get_phrase('No matching Course Units found.') }}</p>
        @else
            <div class="table-responsive mt-3"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>{{ get_phrase('Code') }}</th><th>{{ get_phrase('Course Unit') }}</th><th>{{ get_phrase('Programme') }}</th><th class="text-end">{{ get_phrase('Actions') }}</th></tr></thead>
                <tbody>@foreach($existingCourseUnits as $existingUnit)
                    <tr><td class="text-nowrap">{{ $existingUnit->code }}</td><td>{{ $existingUnit->name }}</td><td>{{ $existingUnit->programme?->name ?: '—' }}</td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.subject.setup', ['id' => $existingUnit->id]) }}">{{ get_phrase('Edit') }}</a></td></tr>
                @endforeach</tbody>
            </table></div>
        @endif
    @endif
</div>
@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="eSection-wrap mt-3">
    <p class="text-muted">{{ institution_name() }}</p>
    @if(request('view') === '1')<h5>View Course Unit</h5><fieldset disabled>@include($formView)</fieldset>@else @include($formView) @endif
</div>
@if(isset($subject) && $unitSetup)
    <div class="eSection-wrap mt-3"><h5>{{ get_phrase('Existing Curriculum placements') }}</h5>
        @foreach($unitSetup['plans'] as $plan)
            @foreach($plan->memberships->where('subject_id', $subject->id) as $placement)
                <div class="d-flex justify-content-between flex-wrap gap-2 border-bottom py-2">
                    <span>{{ $plan->programme->name }} — {{ $plan->version }} · {{ $placement->stage->label }} · {{ $placement->credits }} {{ get_phrase('credits') }}</span>
                    @if(request('view') !== '1')<form method="POST" action="{{ route('admin.curricula.memberships.destroy', [$plan->id, $placement->id]) }}" onsubmit="return confirm('Remove this draft placement and its prerequisite links? The Course Unit will be preserved.');">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">{{ get_phrase('Remove placement') }}</button></form>@endif
                </div>
            @endforeach
        @endforeach
    </div>
@endif
@endsection
