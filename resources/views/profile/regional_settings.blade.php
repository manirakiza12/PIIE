@extends(request()->routeIs('superadmin.*') ? 'superadmin.navigation' : (request()->routeIs('admin.*') ? 'admin.navigation' : (request()->routeIs('teacher.*') ? 'teacher.navigation' : (request()->routeIs('student.*') ? 'student.navigation' : (request()->routeIs('parent.*') ? 'parent.navigation' : 'admin.navigation')))))
@section('content')

@php
    /**
     * Profile → Regional Settings.
     *
     * This is a personal presentation preference, not an academic setting. The
     * wording says so explicitly, because the two being confused is how an
     * institution ends up with a lecturer's phone timezone silently becoming
     * the official exam schedule timezone.
     */
    $tz = app(App\Support\TenantTimezone::class);
    $current = old('timezone', $user->timezone ?? '');
@endphp

<div class="mainSection-title">
    <div class="d-flex justify-content-between align-items-start flex-wrap gr-15">
        <div>
            <h4>{{ get_phrase('Regional Settings') }}</h4>
            <p class="text-muted mb-0">{{ get_phrase('Your timezone, language and region preferences.') }}</p>
        </div>
        <a href="{{ url()->previous() }}" class="eBtn eBtn-dark">{{ get_phrase('Back') }}</a>
    </div>
</div>

@if(session('message'))
    <div class="alert alert-success" role="status">{{ session('message') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <p class="mb-1 fw-semibold">{{ get_phrase('Please check the following and try again.') }}</p>
        <ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<div class="row">
    <div class="col-12 col-lg-8">
        <form method="POST" action="{{ route('profile.regional.update') }}" class="eSection-wrap">
            @csrf

            <div class="mb-3">
                <label for="timezone" class="eForm-label">{{ get_phrase('Timezone') }}</label>
                {{-- Searchable, and explicitly allowed to be "follow the
                     institution". An administrator never has to type an IANA
                     identifier, and no location is inferred. --}}
                <select name="timezone" id="timezone" class="form-select eForm-select"
                        data-placeholder="{{ get_phrase('Search for your city or region') }}">
                    <option value="">{{ get_phrase('Use institution timezone') }}</option>
                    @foreach($timezoneOptions as $area => $options)
                        <optgroup label="{{ $area }}">
                            @foreach($options as $identifier => $option)
                                <option value="{{ $identifier }}" {{ $current === $identifier ? 'selected' : '' }}>
                                    {{ $option['label'] }} — {{ $identifier }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <small class="text-muted d-block mt-1">
                    {{ get_phrase('Choose the timezone used to display your classes, deadlines and schedules.') }}
                </small>
                <small class="text-muted d-block mt-1">
                    {{ get_phrase('This affects only what you see. Official academic times stay in your institution\'s timezone.') }}
                </small>
                @error('timezone')<span class="text-danger d-block mt-1">{{ $message }}</span>@enderror
            </div>

            <div class="alert alert-info mb-3">
                <strong>{{ get_phrase('Your institution timezone') }}:</strong>
                {{ $tz->humanizeFor($institutionTimezone ? $user : null) }}
                <span class="d-block small mt-1">
                    {{ $description['has_personal']
                        ? get_phrase('You are currently displaying times in: :tz', [':tz' => $description['label']])
                        : get_phrase('You are currently following your institution timezone.') }}
                </span>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="eBtn eBtn-primary">{{ get_phrase('Save Preference') }}</button>
                <a href="{{ url()->previous() }}" class="eBtn eBtn-dark">{{ get_phrase('Cancel') }}</a>
            </div>
        </form>
    </div>

    <div class="col-12 col-lg-4">
        <div class="eSection-wrap">
            <h6 class="text-uppercase small fw-bold text-primary mb-3">{{ get_phrase('Why two timezones?') }}</h6>
            <p class="text-muted small mb-2">
                {{ get_phrase('Your institution timezone is the official reference for calendars, exams, attendance and deadlines. It is set by your institution and applies to everyone.') }}
            </p>
            <p class="text-muted small mb-0">
                {{ get_phrase('Your own timezone only changes how times are shown to you and how you enter them. The class still happens at one single moment in time, and everyone joins at the same moment.') }}
            </p>
        </div>
    </div>
</div>

<link rel="stylesheet" href="{{ asset('assets/css/select2.min.css') }}" />

@push('scripts')
<script>
(function () {
    if (typeof $ === "undefined") { return; }
    $(document).ready(function () {
        if ($.fn.select2) {
            $("#timezone").select2({
                width: "100%",
                placeholder: "{{ get_phrase('Search for your city or region') }}",
                matcher: function (params, data) {
                    if ($.trim(params.term) === "") { return null; }
                    return data.text.toLowerCase().indexOf(params.term.toLowerCase()) > -1 ? data : null;
                }
            });
        }
    });
})();
</script>
@endpush

<script src="{{ asset('assets/js/select2.min.js') }}"></script>
@endsection
