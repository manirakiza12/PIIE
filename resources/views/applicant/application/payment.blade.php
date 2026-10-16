@extends('applicant.layout')

@section('title', get_phrase('Pay and Submit'))
@section('subtitle', get_phrase('Submit your reviewed application and complete the application fee payment here.'))

@section('content')

@include('applicant.application._stepper')

@if($admission->submitted_at)
    <div class="alert alert-success">
        <strong>{{ get_phrase('Your application has been submitted.') }}</strong>
        {{ get_phrase('You can follow its progress under Track My Application.') }}
    </div>
@elseif(! $canSubmit)
    <div class="alert alert-warning">
        <strong>{{ get_phrase('Resolve these items before submitting') }}:</strong>
        <ul class="mb-0">
            @foreach($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach
        </ul>
    </div>
@endif

@if($amount > 0 && ! $admission->submitted_at)
    <div class="alert alert-info">{{ get_phrase('Submit your application using the declaration below to enable online payment. You can then complete payment on this page.') }}</div>
@endif

@if($amount > 0)
    @include('applicant.partials._payment')
@elseif($admission->intakeSession)
    <div class="alert alert-info">{{ get_phrase('No application fee is payable for your chosen intake.') }}</div>
@endif

@include('applicant.application._submission')

<div class="d-flex justify-content-start mt-4">
    <a href="{{ route('applicant.application.step', 'review') }}" class="ap-btn ap-btn-ghost">
        <i class="bi bi-arrow-left"></i> {{ get_phrase('Back to Review') }}
    </a>
</div>

@endsection
