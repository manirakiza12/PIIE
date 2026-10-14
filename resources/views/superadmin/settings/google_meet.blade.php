@extends('superadmin.navigation')
@section('content')
<div class="mainSection-title"><h4>{{ get_phrase('Google Meet Setup') }}</h4></div>
<div class="row g-3">
    <div class="col-12 order-0"><div class="eSection-wrap p-3">
        <details><summary class="h5 mb-0">1. Google Cloud setup instructions</summary>
        <div class="mt-3">
        <p>Enable the <strong>Google Calendar API</strong> in your Google Cloud project. Configure the OAuth consent screen and create an OAuth client of type <strong>Web application</strong>.</p>
        <p class="mb-2">Add these exact <strong>Authorized redirect URIs</strong> in Google Cloud so sign-in works on each supported website hostname.</p>
        @foreach($callbackUrls as $callbackUrl)
            <label class="visually-hidden" for="googleCallback{{ $loop->index }}">Authorized redirect URI {{ $loop->iteration }}</label>
            <input id="googleCallback{{ $loop->index }}" class="form-control mb-2" readonly value="{{ $callbackUrl }}">
        @endforeach
        <p class="small text-muted mt-2 mb-0">If your Google app is in Testing, add the account you will connect as a test user. Google may expire test grants after seven days; use the appropriate production publishing and verification process for ongoing access.</p>
        <a href="https://console.cloud.google.com/apis/library/calendar-json.googleapis.com" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary mt-3">Open Google Cloud</a>
        </div></details>
    </div></div>
    <div class="col-12 col-lg-7 {{ $configured ? 'order-2 order-lg-1' : 'order-1' }}"><div class="eSection-wrap p-3 h-100">
        <h5>2. Save OAuth credentials</h5>
        <p class="text-muted mb-2">Paste the Client ID and Client Secret, or upload the Web application credentials JSON downloaded from Google. A regular API key cannot create meetings for your Google account.</p>
        <form action="{{ route('superadmin.google_meet.save') }}" method="post" enctype="multipart/form-data" autocomplete="off">
            @csrf
            <div class="mb-2"><label class="form-label" for="googleSchool">Institution calendar available to</label>
                <select name="school_id" id="googleSchool" class="form-select" required>
                    @foreach($schools as $school)<option value="{{ $school->id }}" @selected((int) old('school_id', $settings?->school_id ?? get_settings('primary_school_id')) === (int) $school->id)>{{ $school->title }}</option>@endforeach
                </select>
            </div>
            <div class="mb-2"><label class="form-label" for="googleClient">OAuth Client ID</label><input id="googleClient" name="client_id" class="form-control" value="{{ old('client_id', $settings?->client_id) }}" placeholder="123456-example.apps.googleusercontent.com" maxlength="255"></div>
            <div class="mb-2"><label class="form-label" for="googleSecret">Client Secret</label><input id="googleSecret" name="client_secret" type="password" class="form-control" autocomplete="new-password" placeholder="{{ $settings ? 'Leave blank to keep the saved secret' : 'Paste your Google client secret' }}"><small class="text-muted">Saved secrets are encrypted and never displayed. Changing the Client ID requires Google accounts to reconnect.</small></div>
            <div class="mb-2"><label class="form-label" for="googleFile">Or import credentials JSON</label><input id="googleFile" name="credentials_file" type="file" accept=".json,application/json" class="form-control"><small class="text-muted">Maximum 32 KB. The upload is not stored publicly.</small></div>
            <div class="mb-2"><label class="form-label" for="googleCalendar">Calendar ID</label><input id="googleCalendar" name="calendar_id" class="form-control" required maxlength="255" value="{{ old('calendar_id', $settings?->calendar_id ?? 'primary') }}"><small class="text-muted">Use primary for the connected account's default calendar.</small></div>
            <input type="hidden" name="enabled" value="0">
            <div class="form-check mb-3"><input id="googleEnabled" name="enabled" value="1" type="checkbox" class="form-check-input" @checked(old('enabled', $settings?->enabled ?? true))><label class="form-check-label" for="googleEnabled">Enable Google Meet integration</label></div>
            @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <button class="btn btn-primary" type="submit">Save settings</button>
        </form>
    </div></div>
    <div class="col-12 col-lg-5 {{ $configured ? 'order-1 order-lg-2' : 'order-2' }}"><div class="eSection-wrap p-3 h-100">
        <h5>3. Connect and verify</h5>
        <p><span class="badge {{ $configured ? 'bg-success' : 'bg-secondary' }}">{{ $configured ? 'OAuth configured' : 'Save credentials first' }}</span></p>
        @if($connection?->isUsable())
            <p>Institution Google account: <strong>{{ $connection->google_email }}</strong></p>
            <p class="small">{{ $settings?->verified_at ? 'Calendar access verified '.$settings->verified_at->format('d M Y, H:i') : 'Connected. Verify calendar access below.' }}</p>
        @else
            <p>No active institution Google account. Connect the Google account that will host classes scheduled by institution administrators.</p>
        @endif
        @if($configured)
            <a class="btn btn-primary mb-3" href="{{ route('google.auth.connect', ['return' => '/superadmin/settings/google-meet']) }}">{{ $connection ? 'Reconnect / change Google account' : 'Connect institution Google account' }}</a>
        @endif
        @if($connection?->isUsable())
            <form method="post" action="{{ route('superadmin.google_meet.verify') }}" class="mb-2">@csrf<button class="btn btn-outline-primary">Verify calendar access</button></form>
            <form method="post" action="{{ route('superadmin.google_meet.disconnect') }}">@csrf<button class="btn btn-outline-danger">Disconnect institution account</button></form>
        @endif
        <hr><p class="small text-muted">Verification reads calendar access without creating a meeting. Once connected, administrators can select Google Meet when adding a live class for the selected institution. Sign in to the connected Google account to host those meetings. Lecturers connect their own Google accounts from Live Classes for their course offerings.</p>
    </div></div>
</div>
@endsection
