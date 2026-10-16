@extends('applicant.layout')

@section('title', get_phrase('Account Settings'))
@section('subtitle', get_phrase('Manage your password. Contact the admissions office for corrections to your particulars.'))

@section('content')

<div class="row g-4">
    <div class="col-lg-7">
        <div class="ap-card">
            <div class="ap-card-head">
                <h2 class="ap-card-title"><i class="bi bi-person-gear"></i> {{ get_phrase('Your Details') }}</h2>
            </div>

            <p class="ap-hint mb-3">{{ get_phrase('Your account particulars are read-only. Contact the admissions office if any details need correcting.') }}</p>
            <dl class="row mb-0">
                <dt class="col-sm-4">{{ get_phrase('First Name') }}</dt>
                <dd class="col-sm-8">{{ $applicant->first_name }}</dd>
                <dt class="col-sm-4">{{ get_phrase('Last Name') }}</dt>
                <dd class="col-sm-8">{{ $applicant->last_name }}</dd>
                <dt class="col-sm-4">{{ get_phrase('Login Email') }}</dt>
                <dd class="col-sm-8 text-break">{{ $applicant->email }}</dd>
                <dt class="col-sm-4">{{ get_phrase('Phone Number') }}</dt>
                <dd class="col-sm-8">{{ $applicant->phone }}</dd>
            </dl>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="ap-card">
            <div class="ap-card-head">
                <h2 class="ap-card-title"><i class="bi bi-shield-lock"></i> {{ get_phrase('Change Password') }}</h2>
            </div>

            <form action="{{ route('applicant.password.change') }}" method="POST">
                @csrf

                <div class="mb-3">
                    <label class="form-label">{{ get_phrase('Current Password') }} <span class="req">*</span></label>
                    <div class="input-group">
                        <input type="password" name="current_password" id="current_password" class="form-control" autocomplete="current-password" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('current_password', this)" tabindex="-1" aria-label="{{ get_phrase('Show password') }}">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ get_phrase('New Password') }} <span class="req">*</span></label>
                    <div class="input-group">
                        <input type="password" name="password" id="password" class="form-control" autocomplete="new-password" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('password', this)" tabindex="-1" aria-label="{{ get_phrase('Show password') }}">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="ap-hint">{{ get_phrase('At least 8 characters.') }}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ get_phrase('Confirm New Password') }} <span class="req">*</span></label>
                    <div class="input-group">
                        <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" autocomplete="new-password" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('password_confirmation', this)" tabindex="-1" aria-label="{{ get_phrase('Show password') }}">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="ap-btn ap-btn-ghost"><i class="bi bi-key"></i> {{ get_phrase('Update Password') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
