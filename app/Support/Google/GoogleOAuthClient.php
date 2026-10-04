<?php

namespace App\Support\Google;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Google OAuth 2.0 wire protocol, and nothing else.
 *
 * Scoped deliberately narrow: build an authorize URL, swap a code for tokens,
 * refresh an access token. No database, no session, no user model — that is
 * {@see GoogleAccountService}'s job. Keeping the two apart is what makes the
 * protocol testable with a fake HTTP transport and the connection logic
 * testable without one.
 *
 * ── WHY Base URIs ARE SETTABLE ──────────────────────────────────────────────
 *
 * So a test can point every call at a local fake instead of the real Google
 * endpoint. Not overridable from `.env`: a production operator choosing which
 * host receives their client secret is not a decision this application should
 * offer.
 */
final class GoogleOAuthClient
{
    /** `calendar.events` — enough to create an event with a Meet conference. */
    public const SCOPE_CALENDAR_EVENTS = 'https://www.googleapis.com/auth/calendar.events';

    /**
     * Ask Google to show the consent screen again when a scope is added later.
     * Without this, connecting a second scope on an already-connected account
     * silently succeeds without ever asking the user's permission.
     */
    private const ACCESS_TYPE = 'offline';
    private const PROMPT = 'consent';

    private string $authorizeBase;
    private string $tokenBase;
    private string $calendarBase;

    /** Seconds before real expiry at which a token is treated as stale. */
    private int $expiryLeeway = 60;

    public function __construct(?string $authorizeBase = null, ?string $tokenBase = null, ?string $calendarBase = null)
    {
        $this->authorizeBase = rtrim($authorizeBase ?? 'https://accounts.google.com/o/oauth2/v2/auth', '/');
        $this->tokenBase = rtrim($tokenBase ?? 'https://oauth2.googleapis.com/token', '/');
        $this->calendarBase = rtrim($calendarBase ?? 'https://www.googleapis.com/calendar/v3', '/');
    }

    /**
     * The URL the lecturer is sent to.
     *
     * `state` is opaque to Google and returned unchanged. It is the CSRF binding
     * for this flow: it must be generated per attempt, stored server-side in the
     * session, and compared on return. Its absence would let an attacker feed a
     * victim's browser a callback carrying an attacker's authorization code,
     * silently connecting the attacker's Google account to the victim's PIIE
     * account.
     *
     * `redirect_uri` is sent because Google compares it to the registered value
     * exactly; omitting it fails for any client with more than one registered URI.
     *
     * @param  list<string>  $scopes
     */
    public function authorizationUrl(string $state, string $redirectUri, array $scopes = [self::SCOPE_CALENDAR_EVENTS]): string
    {
        $credentials = GoogleOAuthCredentials::read();

        $query = http_build_query([
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            // Google's own recommendation for installed-app-style flows that need
            // a refresh token.
            'access_type' => self::ACCESS_TYPE,
            'prompt' => self::PROMPT,
            'include_granted_scopes' => 'true',
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ]);

        return $this->authorizeBase.'?'.$query;
    }

    /**
     * Swap an authorization code for tokens.
     *
     * @return array{access_token:string, refresh_token:?string, expires_in:?int, scope:?string, token_type:?string}
     *
     * @throws RuntimeException on any refusal, with Google's own `error`
     *                          description where it supplied one
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $credentials = GoogleOAuthCredentials::read();

        $response = $this->http()->asForm()->post($this->tokenBase, [
            'code' => $code,
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        return $this->tokensFrom($response);
    }

    /**
     * Exchange a stored refresh token for a new access token.
     *
     * Google omits `refresh_token` from a refresh response, and that is correct
     * behaviour, not an error: the existing refresh token is still valid. So the
     * caller must be able to keep its current one — which is why this returns
     * `refresh_token` as nullable rather than requiring it.
     *
     * @return array{access_token:string, refresh_token:?string, expires_in:?int, scope:?string, token_type:?string}
     */
    public function refresh(string $refreshToken): array
    {
        $credentials = GoogleOAuthCredentials::read();

        $response = $this->http()->asForm()->post($this->tokenBase, [
            'refresh_token' => $refreshToken,
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'grant_type' => 'refresh_token',
        ]);

        return $this->tokensFrom($response);
    }

    /**
     * Ask Google who the token belongs to.
     *
     * Used only to label the connection ("Connected as someone@x.com"), so a
     * lecturer can tell at a glance whether they connected the right account. It
     * needs no extra scope: `openid email` is implied for any granted token.
     *
     * @return array{sub:?string, email:?string, name:?string}
     */
    public function identify(string $accessToken): array
    {
        $response = $this->http()->withToken($accessToken)->acceptJson()
            ->get('https://www.googleapis.com/oauth2/v3/userinfo');

        if (! $response->successful()) {
            return ['sub' => null, 'email' => null, 'name' => null];
        }

        return [
            'sub' => $response->json('sub'),
            'email' => $response->json('email'),
            'name' => $response->json('name'),
        ];
    }

    /**
     * A short-lived access token, refreshed only when actually stale.
     *
     * The alternative — refresh on every call — costs a network round trip per
     * class scheduled. The alternative to this — trust the stored expiry forever
     * — fails mid-schedule with an opaque 401. Leeway absorbs clock skew and the
     * walk from "not expired" to "used".
     *
     * @return array{access_token:string, refresh_token:?string, expires_in:?int, scope:?string, token_type:?string}
     */
    public function freshAccessToken(string $accessToken, string $refreshToken, ?int $expiresAt): array
    {
        if ($expiresAt === null || $expiresAt > time() + $this->expiryLeeway) {
            return [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => $expiresAt === null ? null : max(0, $expiresAt - time()),
                'scope' => null,
                'token_type' => null,
            ];
        }

        return $this->refresh($refreshToken);
    }

    public function calendarBase(): string
    {
        return $this->calendarBase;
    }

    public function http(): PendingRequest
    {
        return Http::acceptJson()
            // Google is a third party on the scheduling path. Without a ceiling a
            // slow provider response would hold a lecturer's HTTP request open
            // indefinitely, and `callMeetingProvider()` in LiveClassController
            // would have nothing meaningful to report back.
            ->connectTimeout(10)
            ->timeout(25);
    }

    /**
     * @return array{access_token:string, refresh_token:?string, expires_in:?int, scope:?string, token_type:?string}
     */
    private function tokensFrom(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException($this->refusalMessage($response));
        }

        $accessToken = trim((string) $response->json('access_token'));

        if ($accessToken === '') {
            throw new RuntimeException('Google returned a token response with no access_token.');
        }

        $refresh = $response->json('refresh_token');
        $expiresIn = $response->json('expires_in');

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : null,
            'expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : null,
            'scope' => $response->json('scope'),
            'token_type' => $response->json('token_type'),
        ];
    }

    /**
     * A message an academic office can act on, without leaking the response body.
     *
     * Google explains refusals in `error_description`, which is safe to surface
     * ("Access denied", "redirect_uri_mismatch"). The full body can contain
     * request echoes, so only the two documented fields are read.
     */
    private function refusalMessage(Response $response): string
    {
        $error = trim((string) $response->json('error'));
        $description = trim((string) $response->json('error_description'));

        if ($error !== '' && $description !== '') {
            return 'Google refused the request: '.$error.' — '.$description;
        }

        if ($error !== '') {
            return 'Google refused the request: '.$error.'.';
        }

        return 'Google refused the request (HTTP '.$response->status().').';
    }
}