{{--
    GOOGLE ACCOUNT CONNECTION — lecturer panel.

    Shown to lecturers only. An administrator sees scheduled classes and their
    status but never gets a Connect button: a Google grant is the holder's own
    credential, and an administrator who could connect one would be able to
    create conferences on an arbitrary personal calendar from an institutional
    screen. Admin visibility of classes is unaffected — see the class list.

    This partial reveals ONE fact about the connection: connected or not, and as
    whom. It never renders a token, a scope or an expiry — those are not the
    lecturer's business and, on a shared or observed machine, not safe to display.
--}}

@php
    // role_id 3 is the lecturer. This read 6, which is the PARENT role, so the
    // panel was invisible to every real lecturer and would have been shown to a
    // parent instead. Resolved through the same named constant TeacherMiddleware
    // uses, so the view cannot drift from the middleware again.
    $isLecturer = (int) (auth()->user()->role_id ?? 0) === \App\Support\Permissions\PermissionService::TEACHER;
    $configured = $googleConfigured ?? false;
    $connection = $googleConnection ?? null;
    $usable = $connection && $connection->isUsable();
    $needsReauth = $connection && $connection->needsReauthorization();

    // Where to come back to after consent. Passed by the caller when it knows —
    // the Course Offering creation page sends its own path, so a lecturer who
    // connects from there lands back on the form they were filling in for the
    // Offering, not on the generic Live Classes list.
    //
    // A PATH ONLY, never a full URL. `GoogleAuthController::returnPath()` re-validates
    // this server-side and rejects anything that is not a same-site path, so a
    // tampered query string cannot turn the return into an open redirect. That
    // server-side check is the authority; this is only a convenience default.
    $returnPath = $googleReturnPath ?? null;
    $connectUrl = route('google.auth.connect');
    if (is_string($returnPath) && $returnPath !== '' && str_starts_with($returnPath, '/') && ! str_starts_with($returnPath, '//')) {
        $connectUrl .= '?return='.rawurlencode($returnPath);
    }

    $disconnectReturn = $returnPath;
@endphp

@if($isLecturer)
    <div class="eSection-wrap mb-3" data-google-connection>
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h5 class="mb-1">{{ get_phrase('Google Account') }}</h5>

                @if($usable)
                    <p class="text-muted small mb-0">
                        <span class="badge bg-success">{{ get_phrase('Connected') }}</span>
                        @if($connection->google_email)
                            {{-- Interpolated, not a ':email' placeholder. `get_phrase()`
                                 is a phrase lookup and performs NO placeholder
                                 substitution, so ':email' would reach the page
                                 literally. Pinned by GoogleConnectionAudienceTest. --}}
                            {{ get_phrase('as') }} {{ $connection->google_email }}
                        @endif
                    </p>
                    <p class="text-muted small mb-0">
                        {{ get_phrase('Classes you schedule on Google Meet are created on your own calendar.') }}
                    </p>
                @elseif($needsReauth)
                    <p class="text-muted small mb-0">
                        <span class="badge bg-warning">{{ get_phrase('Reconnect required') }}</span>
                        {{ get_phrase('Google no longer accepts this connection. Reconnect to keep scheduling Google Meet classes.') }}
                    </p>
                @elseif($connection)
                    <p class="text-muted small mb-0">
                        <span class="badge bg-secondary">{{ get_phrase('Not connected') }}</span>
                        {{ get_phrase('Your Google connection is no longer usable. Reconnect it to schedule Google Meet classes.') }}
                    </p>
                @else
                    <p class="text-muted small mb-0">
                        <span class="badge bg-secondary">{{ get_phrase('Not connected') }}</span>
                        {{ get_phrase('Connect your Google Account to schedule classes with Google Meet links created automatically.') }}
                    </p>
                @endif
            </div>

            <div class="d-flex gap-2">
                @if($usable)
                    {{-- POST, and a confirm: disconnecting means the next Google Meet
                         class cannot be created automatically, and a lecturer who
                         clears their browser history should not lose that by
                         clicking the wrong button. --}}
                    <form method="POST" action="{{ route('google.auth.disconnect') }}"
                          onsubmit="return confirm('{{ get_phrase('Disconnect your Google Account? New Google Meet classes will need another connection.') }}')">
                        @csrf
                        @if($disconnectReturn)
                            {{-- Post/redirect/GET: the controller reads `return` from the
                                 query string, so it has to survive a POST body. Hidden
                                 rather than appended to the action, because a POST that
                                 redirects to a URL built from user input is exactly the
                                 open-redirect shape this feature must not have. --}}
                            <input type="hidden" name="return" value="{{ $disconnectReturn }}">
                        @endif
                        <button type="submit" class="eBtn eBtn-sm eBtn-danger">
                            {{ get_phrase('Disconnect') }}
                        </button>
                    </form>
                @else
                    <a href="{{ $connectUrl }}" class="eBtn eBtn-sm eBtn-primary">
                        <i class="bi bi-google"></i>
                        {{ $needsReauth ? get_phrase('Reconnect Google') : get_phrase('Connect Google Account') }}
                    </a>
                @endif
            </div>
        </div>

        @unless($configured)
            {{-- An installation-level problem, not the lecturer's. Said plainly,
                 because otherwise a lecturer clicks Connect and bounces to a
                 Google error they cannot act on. --}}
            <div class="alert alert-warning mt-3 mb-0 small">
                {{ get_phrase('Google integration is not configured on this installation yet. Your administrator needs to add the Google OAuth credentials file. Until then, you can still schedule a Google Meet class by pasting a meeting link.') }}
            </div>
        @endunless
    </div>
@endif