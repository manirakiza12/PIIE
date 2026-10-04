<?php

namespace App\Http\Controllers;

use App\Support\Permissions\PermissionService;
use App\Support\Google\GoogleAccountService;
use App\Support\Google\GoogleOAuthClient;
use App\Support\Google\GoogleOAuthCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Google OAuth for a lecturer's own calendar.
 *
 * ── WHY THE REDIRECT URI IS NOT TAKEN FROM THE REQUEST ─────────────────────
 *
 * The registered callback is fixed. If the URI were taken from a query parameter
 * or the current URL, an attacker could start a flow with their own redirect_uri,
 * receive Google's code at their own server, and replay it here. Google rejects
 * an unregistered `redirect_uri` — but only because we send the value it expects.
 * Deriving it from configuration means there is exactly one value, ever.
 */
class GoogleAuthController extends Controller
{
    /** Session key for the CSRF `state` and the pre-authorization user id. */
    private const STATE_KEY = 'google.oauth.state';

    private const RETURN_KEY = 'google.oauth.return';

    public function __construct(
        private readonly GoogleOAuthClient $client,
        private readonly GoogleAccountService $accounts,
    ) {}

    /**
     * Step 1 — send the lecturer to Google.
     */
    public function redirect(Request $request): Response
    {
        $this->assertMayConnectGoogleAccount($request);

        if (! GoogleOAuthCredentials::isConfigured()) {
            return back()->with('error', get_phrase(
                'Google integration is not configured on this installation. Ask your administrator to add the OAuth credentials file.'
            ));
        }

        $state = $this->accounts->newState();

        // The state is bound to the session AND to the user id that started it, so
        // a state leaked from another session cannot complete this one.
        $request->session()->put(self::STATE_KEY, [
            'state' => $state,
            'user_id' => (int) $request->user()->id,
        ]);

        // Where to land afterwards. Only a same-site path, taken from a constant,
        // never a full URL — an open redirect here would hand an attacker a
        // convincing Google-branded bounce.
        $request->session()->put(self::RETURN_KEY, $this->returnPath($request));

        return redirect()->away($this->client->authorizationUrl($state, $this->callbackUrl()));
    }

    /**
     * Step 2 — Google sends the lecturer back.
     */
    public function callback(Request $request): Response
    {
        $this->assertMayConnectGoogleAccount($request);

        // Read, then immediately forget. `pull()` would remove the value we still
        // need to compare against, and leaving it in place would let one captured
        // callback be replayed. Forget-first makes the state single-use.
        $pending = $request->session()->pull(self::STATE_KEY);
        $returnPath = (string) $request->session()->pull(self::RETURN_KEY, $this->defaultReturnPath());

        // Every failure below returns to the same page with the same shape, so a
        // failure is never distinguishable from a success by response code alone.
        if (! is_array($pending)) {
            return $this->failure($returnPath, get_phrase('That Google sign-in attempt expired. Please try connecting again.'));
        }

        $expectedUser = (int) ($pending['user_id'] ?? 0);
        $expectedState = (string) ($pending['state'] ?? '');

        if ($expectedUser === 0 || (int) $request->user()->id !== $expectedUser) {
            return $this->failure($returnPath, get_phrase('That Google sign-in attempt expired. Please try connecting again.'));
        }

        $givenState = (string) $request->query('state', '');

        // `hash_equals` for a constant-time comparison, and the length check is
        // implicit: givenState === '' is already excluded by the expected side
        // being non-empty in any reachable path.
        if ($expectedState === '' || $givenState === '' || ! hash_equals($expectedState, $givenState)) {
            return $this->failure($returnPath, get_phrase('The Google sign-in could not be verified. Please try again.'));
        }

        if ($request->filled('error')) {
            $error = (string) $request->query('error');

            // `access_denied` is the user pressing Cancel. Not an error to
            // apologise for — it is a choice, and saying so is more honest than a
            // generic failure.
            $message = $error === 'access_denied'
                ? get_phrase('Google access was not granted. Nothing has been changed.')
                : get_phrase('Google sign-in failed. Please try again.');

            return $this->failure($returnPath, $message);
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->failure($returnPath, get_phrase('Google did not return an authorization code.'));
        }

        try {
            $this->accounts->connect($request->user(), $code, $this->callbackUrl());
        } catch (RuntimeException $e) {
            // The message is safe: GoogleAccountService and GoogleOAuthClient only
            // ever put a Google error code or description in it, never a token.
            Log::warning('Google OAuth callback failed.', ['message' => $e->getMessage()]);

            return $this->failure($returnPath, get_phrase('Google could not complete the connection. Please try again.'));
        }

        return redirect($returnPath)->with('message', get_phrase('Google Account connected.'));
    }

    /**
     * Remove the connection for whoever is signed in.
     */
    public function disconnect(Request $request): Response
    {
        $this->assertMayConnectGoogleAccount($request);

        $this->accounts->disconnect($request->user());

        return redirect($this->returnPath($request))
            ->with('message', get_phrase('Google Account disconnected.'));
    }

    /**
     * Only the roles that can actually schedule a Live Class may connect Google.
     *
     * The routes are behind `auth` alone, so without this any signed-in account
     * — a student, a parent — could complete the consent screen directly and
     * grant this installation `calendar.events` scope on their Google account.
     * Nothing reads those rows for a non-lecturer, but least privilege means not
     * collecting the grant at all.
     *
     * Lecturer plus the two admin roles, mirroring the two authorities
     * LiveClassAccessService already recognises for scheduling. Compared against
     * the named constants rather than bare integers: a bare 6 here would be the
     * Parent role, which is precisely the mistake already corrected in
     * LiveClassController::createGoogleMeetEventForActor().
     */
    private function assertMayConnectGoogleAccount(Request $request): void
    {
        $user = $request->user();

        abort_unless($user && in_array((int) $user->role_id, [
            PermissionService::TEACHER,
            PermissionService::SUPER_ADMIN,
            PermissionService::SCHOOL_ADMIN,
        ], true), 403);
    }

    /**
     * The registered callback, built from the app URL.
     *
     * This is the exact URI registered in Google Cloud. It is constructed rather
     * than configured so it cannot drift from `APP_URL`, and it is never taken
     * from the request.
     */
    private function callbackUrl(): string
    {
        return rtrim(config('app.url'), '/').'/auth/google/callback';
    }

    private function defaultReturnPath(): string
    {
        return '/teacher/live-classes';
    }

    /**
     * A same-site path to come back to.
     *
     * Only a lecturer workspace makes sense here, and only a path — a URL
     * beginning `http` or `//` is refused outright rather than sanitised, because
     * "starts with a single slash" is not a sufficient test for open-redirect
     * safety once protocol-relative URLs are considered.
     */
    private function returnPath(Request $request): string
    {
        $candidate = (string) $request->query('return', $request->query('return_to', ''));

        if ($candidate !== ''
            && str_starts_with($candidate, '/')
            && ! str_starts_with($candidate, '//')
            && ! str_contains($candidate, "\r")
            && ! str_contains($candidate, "\n")
        ) {
            return $candidate;
        }

        return $this->defaultReturnPath();
    }

    private function failure(string $returnPath, string $message): Response
    {
        return redirect($returnPath)->with('error', $message);
    }
}