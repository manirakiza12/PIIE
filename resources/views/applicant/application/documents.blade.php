@extends('applicant.layout')

@section('title', get_phrase('Supporting Documents'))
@section('subtitle', get_phrase('Upload the certificates and identification we need to assess your application.'))

@section('content')

@include('applicant.application._stepper')

@include('applicant.partials._documents')

@unless($readOnly)
    <div class="d-flex justify-content-end mt-4">
        <a href="{{ route('applicant.application.step', 'review') }}"
           class="ap-btn ap-btn-primary">
            {{ get_phrase('Continue') }} <i class="bi bi-arrow-right"></i>
        </a>
    </div>
@endunless

@endsection
