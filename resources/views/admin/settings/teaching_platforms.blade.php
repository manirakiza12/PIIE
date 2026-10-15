@extends('admin.navigation')
@section('content')
<div class="mainSection-title"><h4>{{ get_phrase('Teaching Platforms') }}</h4><p class="text-muted">{{ get_phrase('Choose which platforms are available when scheduling live classes.') }}</p></div>
@include('admin.settings.partials.settings_nav', ['active'=>'platforms'])
@if(session('success'))<div class="alert alert-success" role="status">{{ session('success') }}</div>@endif
<div class="eSection-wrap p-4">
<form method="POST" data-workspace-continuous action="{{ route('admin.settings.academic.save') }}">@csrf
    <input type="hidden" name="_settings_section" value="platforms">
        <div class="row g-2 mb-3">
            <div class="col-md-4">
                <label class="d-flex align-items-center gap-2">
                    <input type="checkbox" name="live_class_platform_jitsi" value="1" {{ $platform_settings['live_class_platform_jitsi'] ? 'checked' : '' }}>
                    {{ get_phrase('Enable Jitsi Meet') }}
                </label>
            </div>
            <div class="col-md-4">
                <label class="d-flex align-items-center gap-2">
                    <input type="checkbox" name="live_class_platform_google_meet" value="1" {{ $platform_settings['live_class_platform_google_meet'] ? 'checked' : '' }}>
                    {{ get_phrase('Enable Google Meet') }}
                </label>
            </div>
            <div class="col-md-4">
                <label class="d-flex align-items-center gap-2">
                    <input type="checkbox" name="live_class_platform_zoom" value="1" {{ $platform_settings['live_class_platform_zoom'] ? 'checked' : '' }}>
                    {{ get_phrase('Enable Zoom') }}
                </label>
            </div>
            <div class="col-md-4">
                <label class="d-flex align-items-center gap-2">
                    <input type="checkbox" name="live_class_platform_bigbluebutton" value="1" {{ $platform_settings['live_class_platform_bigbluebutton'] ? 'checked' : '' }}>
                    {{ get_phrase('Enable BigBlueButton') }}
                </label>
            </div>
            <div class="col-md-4">
                <label class="d-flex align-items-center gap-2">
                    <input type="checkbox" name="live_class_platform_custom" value="1" {{ $platform_settings['live_class_platform_custom'] ? 'checked' : '' }}>
                    {{ get_phrase('Enable Custom Platform') }}
                </label>
            </div>
            <div class="col-md-8">
                <label class="eForm-label">{{ get_phrase('Jitsi Base URL') }}</label>
                <input type="url" class="form-control eForm-control" name="live_class_jitsi_base_url" value="{{ $platform_settings['live_class_jitsi_base_url'] }}" placeholder="https://meet.jit.si">
                <small class="d-block mt-1">
                    {{ get_phrase('Moderator token (JWT)') }}:
                    @if($platform_settings['jitsi_jwt_configured'])
                        <span class="text-success"><i class="bi bi-check-circle-fill"></i> {{ get_phrase('Configured — hosts get real moderator rights.') }}</span>
                    @else
                        <span class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> {{ get_phrase('Moderator access is not configured. Contact your administrator.') }}</span>
                    @endif
                </small>
            </div>
        </div>


    <button class="eBtn eBtn-primary w-auto" type="submit">{{ get_phrase('Save Teaching Platforms') }}</button>
</form></div>
@endsection
