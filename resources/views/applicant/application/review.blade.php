@extends('applicant.layout')

@section('title', get_phrase('Review'))
@section('subtitle', get_phrase('Check your information and documents before continuing to Pay and Submit.'))

@section('content')

@include('applicant.application._stepper')

@if($admission->submitted_at)
    <div class="alert alert-success d-flex align-items-start gap-3">
        <i class="bi bi-check-circle-fill fs-4"></i>
        <div>
            <strong>{{ get_phrase('Your application was submitted on') }} {{ $admission->submitted_at->format('d M Y, H:i') }}.</strong>
            <p class="mb-0">{{ get_phrase('You can follow its progress under Track My Application.') }}</p>
        </div>
    </div>
@elseif(! $canSubmit)
    <div class="alert alert-warning">
        <strong class="d-block mb-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            {{ get_phrase('A few things still need your attention before you can submit') }}:
        </strong>
        <ul class="mb-0">
            @foreach($blockers as $blocker)
                <li>{{ $blocker }}</li>
            @endforeach
        </ul>
    </div>
@endif

@include('applicant.partials._summary')

<div class="d-flex flex-wrap justify-content-between gap-2 mt-4">
    <a href="{{ route('applicant.application.step', 'documents') }}" class="ap-btn ap-btn-ghost">
        <i class="bi bi-arrow-left"></i> {{ get_phrase('Back to Documents') }}
    </a>
    <a href="{{ route('applicant.application.step', 'payment') }}" class="ap-btn ap-btn-primary">
        {{ get_phrase('Continue to Pay and Submit') }} <i class="bi bi-arrow-right"></i>
    </a>
</div>

@endsection
