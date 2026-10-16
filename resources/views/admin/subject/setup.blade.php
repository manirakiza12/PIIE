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
<div class="mainSection-title"><h4>{{ get_phrase(isset($subject) ? ($isHigherEducation ? 'Edit Course Unit' : 'Edit Subject / Course Unit') : ($isHigherEducation ? 'Add Course Unit' : 'Add Subject / Course Unit')) }}</h4></div>
@if($isHigherEducation)
<div class="alert alert-info" role="note"><strong>Academic Year and Semester:</strong> A Course Unit is a reusable catalogue record. Choose the dated teaching year and semester under Course Registrations when creating its Course Offering.
@if(isset($subject) && auth()->user()->hasPermission('academic.course_offering.manage'))<a class="alert-link" href="{{ route('admin.course_offerings.create', ['subject_id'=>$subject->id]) }}">Schedule this Course Unit</a>@endif
</div>
@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="eSection-wrap mt-3">
    <p class="text-muted">{{ institution_name() }}</p>
    @include($formView)
</div>
@if(isset($subject) && $unitSetup)
    <div class="eSection-wrap mt-3"><h5>{{ get_phrase('Existing Curriculum placements') }}</h5>
        @foreach($unitSetup['plans'] as $plan)
            @foreach($plan->memberships->where('subject_id', $subject->id) as $placement)
                <div class="d-flex justify-content-between flex-wrap gap-2 border-bottom py-2">
                    <span>{{ $plan->programme->name }} — {{ $plan->version }} · {{ $placement->stage->label }} · {{ $placement->credits }} {{ get_phrase('credits') }}</span>
                    <form method="POST" action="{{ route('admin.curricula.memberships.destroy', [$plan->id, $placement->id]) }}" onsubmit="return confirm('Remove this draft placement and its prerequisite links? The Course Unit will be preserved.');">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">{{ get_phrase('Remove placement') }}</button></form>
                </div>
            @endforeach
        @endforeach
    </div>
@endif
@endsection
