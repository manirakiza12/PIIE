@extends('admin.navigation')

@section('page_styles')
<link rel="stylesheet" href="{{ asset('assets/css/curriculum-workspace.css') }}">
<style>
    .course-unit-view .eSection-wrap { padding: 24px; }
    .course-unit-view .view-summary { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; padding: 16px 20px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px; margin-bottom: 16px; }
    .course-unit-view .view-summary .code-badge { font-size: 18px; font-weight: 700; color: #0E4C87; background: #eef3fa; padding: 6px 14px; border-radius: 6px; }
    .course-unit-view .view-summary .name { font-size: 20px; font-weight: 600; color: #181c32; }
    .course-unit-view fieldset[disabled] .form-control, .course-unit-view fieldset[disabled] .form-select { background-color: #f8f9fa; }
</style>
@endsection
@section('content')
<div class="curriculum-workspace course-unit-view">
    <div class="mainSection-title">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h4>{{ get_phrase(request('view') === '1' ? 'View Course Unit' : (isset($subject) ? ($isHigherEducation ? 'Edit Course Unit' : 'Edit Subject / Course Unit') : ($isHigherEducation ? 'Add Course Unit' : 'Add Subject / Course Unit'))) }}</h4>
                <ul class="d-flex align-items-center eBreadcrumb-2">
                    <li><a href="#">{{ get_phrase('Home') }}</a></li>
                    <li><a href="#">{{ get_phrase('Academic') }}</a></li>
                    <li><a href="{{ route('admin.subject_list') }}">{{ get_phrase($isHigherEducation ? 'Course Units' : 'Subjects') }}</a></li>
                    <li aria-current="page">{{ get_phrase(request('view') === '1' ? 'View Course Unit' : (isset($subject) ? 'Edit' : 'Create')) }}</li>
                </ul>
                @if(isset($subject) && request('view') === '1')
                    <p class="text-muted mt-1 mb-0">{{ get_phrase('Read-only view of the shared Course Unit catalogue record.') }}</p>
                @elseif(isset($subject))
                    <p class="text-muted mt-1 mb-0">{{ get_phrase('Edit shared Course Unit catalogue details') }}</p>
                @else
                    <p class="text-muted mt-1 mb-0">{{ get_phrase('Create a reusable Course Unit catalogue record') }}</p>
                @endif
            </div>
            @if(isset($subject))
                <div class="export-btn-area">
                    <a href="{{ route('admin.subject_list', request()->only(['programme_id', 'search'])) }}" class="eBtn eBtn-dark">{{ get_phrase('Back to list') }}</a>
                </div>
            @endif
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

    @if(isset($subject) && request('view') === '1')
        <div class="view-summary">
            <span class="code-badge">{{ $subject->code ?: '—' }}</span>
            <span class="name">{{ $subject->name }}</span>
        </div>
    @endif

    <div class="eSection-wrap mt-3">
        @if(request('view') === '1')
            <fieldset disabled>@include($formView)</fieldset>
        @else
            @include($formView)
        @endif
    </div>
</div>
@endsection