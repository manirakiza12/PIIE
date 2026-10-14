@extends('applicant.layout')

@section('title', get_phrase('Programme Selection'))
@section('subtitle', get_phrase('Choose what you want to study and when you want to start.'))

@section('content')

@include('applicant.application._stepper')

@if($programmes->isEmpty() || $intakes->isEmpty())
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>{{ get_phrase('There are no open intakes or active programmes at the moment. Please check back shortly or contact the admissions office.') }}</div>
    </div>
@endif

<form action="{{ route('applicant.application.programme') }}" method="POST">
    @csrf

    <div class="ap-card">
        <div class="ap-fieldset-title">{{ get_phrase('What You Want to Study') }}</div>

        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">{{ get_phrase('First Choice Programme') }} <span class="req">*</span></label>
                <select name="programme_id" class="form-select" {{ $readOnly ? 'disabled' : 'required' }}>
                    <option value="">{{ get_phrase('Select a programme') }}</option>
                    @foreach($programmes->groupBy('level') as $level => $group)
                        <optgroup label="{{ $level ?: get_phrase('Other') }}">
                            @foreach($group as $programme)
                                <option value="{{ $programme->id }}" {{ (int) old('programme_id', $admission->programme_id ?: ($selectedProgrammeId ?? null)) === $programme->id ? 'selected' : '' }}>
                                    {{ $programme->name }}@if($programme->duration) — {{ $programme->duration }}@endif
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">{{ get_phrase('Study Mode') }}</label>
                <select name="study_mode" class="form-select" {{ $readOnly ? 'disabled' : '' }}>
                    <option value="">{{ get_phrase('Select a mode') }}</option>
                    @foreach($modes as $mode)
                        <option value="{{ $mode }}" {{ old('study_mode', $admission->study_mode) === $mode ? 'selected' : '' }}>{{ $mode }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-8">
                <label class="form-label">{{ get_phrase('Second Choice Programme') }}</label>
                <select name="second_choice_programme_id" class="form-select" {{ $readOnly ? 'disabled' : '' }}>
                    <option value="">{{ get_phrase('None') }}</option>
                    @foreach($programmes as $programme)
                        <option value="{{ $programme->id }}" {{ (int) old('second_choice_programme_id', $admission->second_choice_programme_id) === $programme->id ? 'selected' : '' }}>
                            {{ $programme->name }}
                        </option>
                    @endforeach
                </select>
                <div class="ap-hint">{{ get_phrase('Considered only if you are not admitted to your first choice.') }}</div>
            </div>

            <div class="col-md-4">
                <label class="form-label">{{ get_phrase('Intake') }} <span class="req">*</span></label>
                <select name="intake_session_id" class="form-select" {{ $readOnly ? 'disabled' : 'required' }}>
                    <option value="">{{ get_phrase('Select an intake') }}</option>
                    @foreach($intakes as $intake)
                        <option value="{{ $intake->id }}" {{ (int) old('intake_session_id', $admission->intake_session_id) === $intake->id ? 'selected' : '' }}>
                            {{ $intake->name }}@if($intake->application_fee > 0) ({{ \App\Support\Admissions\ApplicationFee::format((float) $intake->application_fee) }}){{ '' }}@endif
                        </option>
                    @endforeach
                </select>
                <div class="ap-hint">{{ get_phrase('The application fee, if any, depends on the intake you choose.') }}</div>
            </div>
        </div>

        <div class="ap-fieldset-title">{{ get_phrase('Who Is Funding Your Studies') }}</div>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">{{ get_phrase('Sponsorship') }}</label>
                <select name="sponsor_type" class="form-select" {{ $readOnly ? 'disabled' : '' }}>
                    <option value="">{{ get_phrase('Select') }}</option>
                    @foreach($sponsorTypes as $type)
                        <option value="{{ $type }}" {{ old('sponsor_type', $admission->sponsor_type) === $type ? 'selected' : '' }}>{{ get_phrase($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">{{ get_phrase('Sponsor Name') }}</label>
                <input type="text" name="sponsor_name" class="form-control" value="{{ old('sponsor_name', $admission->sponsor_name) }}" {{ $readOnly ? 'readonly' : '' }}>
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ get_phrase('Sponsor Phone') }}</label>
                <input type="text" name="sponsor_phone" class="form-control" value="{{ old('sponsor_phone', $admission->sponsor_phone) }}" {{ $readOnly ? 'readonly' : '' }}>
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ get_phrase('Sponsor Email') }}</label>
                <input type="email" name="sponsor_email" class="form-control" value="{{ old('sponsor_email', $admission->sponsor_email) }}" {{ $readOnly ? 'readonly' : '' }}>
            </div>
        </div>

        <div class="ap-fieldset-title">{{ get_phrase('One Last Thing') }}</div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">{{ get_phrase('How did you hear about us?') }}</label>
                <select name="how_did_you_hear" class="form-select" {{ $readOnly ? 'disabled' : '' }}>
                    <option value="">{{ get_phrase('Select') }}</option>
                    @foreach($heardAbout as $option)
                        <option value="{{ $option }}" {{ old('how_did_you_hear', $admission->how_did_you_hear) === $option ? 'selected' : '' }}>{{ get_phrase($option) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @unless($readOnly)
            <div class="d-flex flex-wrap gap-2 justify-content-end mt-4 pt-4" style="border-top:1px solid var(--ap-line);">
                <button type="submit" name="action" value="save" class="ap-btn ap-btn-ghost">
                    <i class="bi bi-save"></i> {{ get_phrase('Save') }}
                </button>
                <button type="submit" name="action" value="continue" class="ap-btn ap-btn-primary">
                    {{ get_phrase('Save & Continue') }} <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        @endunless
    </div>
</form>

@endsection
