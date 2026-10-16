@extends('admin.navigation')
@section('content')

@include('admin.admissions.wizard._layout_top')

@if($programmes->isEmpty() || $intakes->isEmpty())
    <div class="alert alert-warning">
        {{ get_phrase('There are no open intakes or active programmes configured yet. Set these up under Admissions before continuing.') }}
    </div>
@endif

<div class="eSection-wrap mb-3">
    <h5 class="mb-3">{{ get_phrase('Programme Selection') }}</h5>

    <form action="{{ route('admin.hei_admissions.wizard.programme', $admission->id) }}" method="POST">
        @csrf
        <div class="row g-3">
            <div class="col-md-8 fpb-7">
                <label class="eForm-label">{{ get_phrase('First Choice Programme') }} *</label>
                <select name="programme_id" class="form-select eForm-control" {{ $readOnly ? 'disabled' : 'required' }}>
                    <option value="">{{ get_phrase('— Select —') }}</option>
                    @foreach($programmes->groupBy('level') as $level => $group)
                        <optgroup label="{{ $level ?: get_phrase('Other') }}">
                            @foreach($group as $programme)
                                <option value="{{ $programme->id }}" {{ (int) old('programme_id', $admission->programme_id) === $programme->id ? 'selected' : '' }}>
                                    {{ $programme->name }}@if($programme->duration) — {{ $programme->duration }}@endif
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 fpb-7">
                <label class="eForm-label">{{ get_phrase('Study Mode') }}</label>
                <select name="study_mode" class="form-select eForm-control" {{ $readOnly ? 'disabled' : '' }}>
                    <option value="">{{ get_phrase('— Select —') }}</option>
                    @foreach($modes as $mode)
                        <option value="{{ $mode }}" {{ old('study_mode', $admission->study_mode) === $mode ? 'selected' : '' }}>{{ $mode }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-8 fpb-7">
                <label class="eForm-label">{{ get_phrase('Second Choice Programme') }}</label>
                <select name="second_choice_programme_id" class="form-select eForm-control" {{ $readOnly ? 'disabled' : '' }}>
                    <option value="">{{ get_phrase('None') }}</option>
                    @foreach($programmes as $programme)
                        <option value="{{ $programme->id }}" {{ (int) old('second_choice_programme_id', $admission->second_choice_programme_id) === $programme->id ? 'selected' : '' }}>
                            {{ $programme->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 fpb-7">
                <label class="eForm-label">{{ get_phrase('Intake') }} *</label>
                <select name="intake_session_id" class="form-select eForm-control" {{ $readOnly ? 'disabled' : 'required' }}>
                    <option value="">{{ get_phrase('— Select —') }}</option>
                    @foreach($intakes as $intake)
                        <option value="{{ $intake->id }}" {{ (int) old('intake_session_id', $admission->intake_session_id) === $intake->id ? 'selected' : '' }}>
                            {{ $intake->name }}@if($intake->application_fee > 0) ({{ \App\Support\Admissions\ApplicationFee::format((float) $intake->application_fee, $admission) }}){{ '' }}@endif
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <h6 class="mt-4 mb-3">{{ get_phrase('Sponsorship') }}</h6>
        <div class="row g-3">
            <div class="col-md-4 fpb-7">
                <label class="eForm-label">{{ get_phrase('Sponsorship Type') }}</label>
                <select name="sponsor_type" class="form-select eForm-control" {{ $readOnly ? 'disabled' : '' }}>
                    <option value="">{{ get_phrase('— Select —') }}</option>
                    @foreach($sponsorTypes as $type)
                        <option value="{{ $type }}" {{ old('sponsor_type', $admission->sponsor_type) === $type ? 'selected' : '' }}>{{ get_phrase($type) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-8 fpb-7">
                <label class="eForm-label">{{ get_phrase('Sponsor Name') }}</label>
                <input type="text" name="sponsor_name" class="form-control eForm-control" value="{{ old('sponsor_name', $admission->sponsor_name) }}" {{ $readOnly ? 'readonly' : '' }}>
            </div>
            <div class="col-md-6 fpb-7">
                <label class="eForm-label">{{ get_phrase('Sponsor Phone') }}</label>
                <input type="text" name="sponsor_phone" class="form-control eForm-control" value="{{ old('sponsor_phone', $admission->sponsor_phone) }}" {{ $readOnly ? 'readonly' : '' }}>
            </div>
            <div class="col-md-6 fpb-7">
                <label class="eForm-label">{{ get_phrase('Sponsor Email') }}</label>
                <input type="email" name="sponsor_email" class="form-control eForm-control" value="{{ old('sponsor_email', $admission->sponsor_email) }}" {{ $readOnly ? 'readonly' : '' }}>
            </div>
            <div class="col-md-6 fpb-7">
                <label class="eForm-label">{{ get_phrase('How did the candidate hear about us?') }}</label>
                <select name="how_did_you_hear" class="form-select eForm-control" {{ $readOnly ? 'disabled' : '' }}>
                    <option value="">{{ get_phrase('— Select —') }}</option>
                    @foreach($heardAbout as $option)
                        <option value="{{ $option }}" {{ old('how_did_you_hear', $admission->how_did_you_hear) === $option ? 'selected' : '' }}>{{ get_phrase($option) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @unless($readOnly)
            <div class="d-flex flex-wrap gap-2 justify-content-end mt-4 pt-3">
                <button type="submit" name="action" value="save" class="eBtn eBtn-outline-blackish">{{ get_phrase('Save') }}</button>
                <button type="submit" name="action" value="continue" class="eBtn eBtn-primary">{{ get_phrase('Save & Continue') }}</button>
            </div>
        @endunless
    </form>
</div>
@endsection
