@extends('superadmin.navigation')

@section('content')
<div class="mainSection-title">
    <div class="row"><div class="col-12">
        <h4>{{ get_phrase('SMTP Settings') }}</h4>
        <p>{{ get_phrase('Configure platform email delivery. Leave the password blank to keep the saved password.') }}</p>
    </div></div>
</div>

<div class="eSection-wrap">
    @if (session('message'))<div class="alert alert-success" role="status">{{ session('message') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger" role="alert">{{ session('error') }}</div>@endif
    @if ($errors->has('mail_settings'))<div class="alert alert-danger" role="alert">{{ $errors->first('mail_settings') }}</div>@endif

    <form method="POST" action="{{ route('superadmin.smtp.update') }}" novalidate>
        @csrf
        <h5>{{ get_phrase('SMTP Server') }}</h5>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="smtp_protocol">{{ get_phrase('Protocol') }} *</label>
                <select id="smtp_protocol" name="smtp_protocol" class="form-control" required>
                    <option value="smtp" @selected(old('smtp_protocol', 'smtp') === 'smtp')>SMTP</option>
                </select>
                @error('smtp_protocol')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 mb-3">
                <label for="smtp_crypto">{{ get_phrase('SMTP Crypto') }} *</label>
                <select id="smtp_crypto" name="smtp_crypto" class="form-control" required>
                    <option value="">{{ get_phrase('Select encryption') }}</option>
                    @foreach (['tls' => 'TLS', 'ssl' => 'SSL'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('smtp_crypto', get_settings('smtp_crypto')) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('smtp_crypto')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 mb-3">
                <label for="smtp_host">{{ get_phrase('SMTP Host') }} *</label>
                <input id="smtp_host" name="smtp_host" class="form-control" value="{{ old('smtp_host', get_settings('smtp_host')) }}" required autocomplete="off">
                @error('smtp_host')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 mb-3">
                <label for="smtp_port">{{ get_phrase('SMTP Port') }} *</label>
                <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" class="form-control" value="{{ old('smtp_port', get_settings('smtp_port')) }}" required>
                @error('smtp_port')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 mb-3">
                <label for="smtp_user">{{ get_phrase('SMTP Username') }} *</label>
                <input id="smtp_user" name="smtp_user" type="email" class="form-control" value="{{ old('smtp_user', get_settings('smtp_user')) }}" required autocomplete="username">
                @error('smtp_user')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 mb-3">
                <label for="smtp_pass">{{ get_phrase('SMTP Password') }} *</label>
                <input id="smtp_pass" name="smtp_pass" type="password" class="form-control" value="" autocomplete="new-password" @unless($passwordConfigured) required @endunless>
                @if ($passwordConfigured)
                    <small class="text-muted">{{ get_phrase('SMTP password is configured.') }} {{ get_phrase('Leave blank to keep it, or enter a new password to replace it.') }}</small>
                @else
                    <small class="text-muted">{{ get_phrase('Enter the SMTP password for the first configuration.') }}</small>
                @endif
                @error('smtp_pass')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
        </div>

        <h5>{{ get_phrase('Sender Identity') }}</h5>
        <p class="text-muted">{{ get_phrase('These fields update the existing platform System Email and System Title settings.') }}</p>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="from_name">{{ get_phrase('From Name') }} *</label>
                <input id="from_name" name="from_name" class="form-control" value="{{ old('from_name', get_settings('system_title')) }}" maxlength="100" required>
                @error('from_name')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 mb-3">
                <label for="from_email">{{ get_phrase('From Email') }} *</label>
                <input id="from_email" name="from_email" type="email" class="form-control" value="{{ old('from_email', get_settings('system_email')) }}" required autocomplete="email">
                @error('from_email')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
        </div>
        @error('mail_settings')<div class="text-danger mb-3">{{ $message }}</div>@enderror
        <button class="btn btn-primary" type="submit">{{ get_phrase('Save Settings') }}</button>
    </form>

    <hr>
    <h5>{{ get_phrase('Send Test Email') }}</h5>
    <p>{{ get_phrase('Send a harmless test message using the active platform mail configuration.') }}</p>
    <form method="POST" action="{{ route('superadmin.smtp.test-email') }}">
        @csrf
        <div class="row align-items-end">
            <div class="col-md-6 mb-3">
                <label for="recipient_email">{{ get_phrase('Recipient Email') }} *</label>
                <input id="recipient_email" name="recipient_email" type="email" class="form-control" value="{{ old('recipient_email') }}" required autocomplete="email">
                @error('recipient_email')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6 mb-3">
                <button class="btn btn-outline-primary" type="submit">{{ get_phrase('Send Test Email') }}</button>
            </div>
        </div>
    </form>
</div>
@endsection
