@extends('admin.navigation')

@section('page_styles')<link rel="stylesheet" href="{{ asset('assets/css/curriculum-workspace.css') }}">@endsection
@section('content')
<div class="curriculum-workspace">
    <div class="mainSection-title">
        <div>
            <h4>{{ get_phrase(request('view') === '1' ? 'View Course Unit' : (isset($subject) ? ($isHigherEducation ? 'Edit Course Unit' : 'Edit Subject / Course Unit') : ($isHigherEducation ? 'Add Course Unit' : 'Add Subject / Course Unit'))) }}</h4>
            <p class="text-muted mt-1 mb-0">{{ get_phrase(isset($subject) ? 'Edit shared Course Unit catalogue details' : 'Create a reusable Course Unit catalogue record') }}</p>
        </div>
    </div>

    @if(($isHigherEducation || $isMixed) && !isset($subject))
        <div class="alert alert-info" role="note">
            <strong>{{ get_phrase('Reusable Course Unit') }}</strong>
            <span class="ms-1">{{ get_phrase('Catalogue details can be reused in multiple curricula; placement settings belong to each curriculum separately.') }}</span>
        </div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="eSection-wrap mt-3">
        @if(request('view') === '1')
            <h5>{{ get_phrase('View Course Unit') }}</h5><fieldset disabled>@include($formView)</fieldset>
        @else
            @include($formView)
        @endif
    </div>
</div>
@endsection
