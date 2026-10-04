<?php

namespace App\Support\Google;

use RuntimeException;

/**
 * Reads the Google OAuth client credentials JSON from local disk.
 *
 * ── WHY THIS CLASS EXISTS AT ALL ────────────────────────────────────────────
 *
 * The alternative — putting the client id and secret in `.env` — is what
 * `config/services.php` already does for Zoom, and it is a worse arrangement:
 * `.env` is a single flat file that gets copied between environments, printed in
 * support tickets and read by anything that can read the project root. A
 * separate JSON under `storage/app/google/` keeps one secret in one place that
 * is (a) ignored by git and (b) swap-able per environment without touching
 * configuration.
 *
 * ── WHY NOTHING IS CACHED ACROSS REQUESTS ──────────────────────────────────
 *
 * A static cache would make "rotate the file and reload" not actually reload
 * during a single request's lifetime, and would let a stale secret outlive a
 * deliberate rotation. One read per call, no cache. The file is tiny and this
 * runs on the scheduling path, not per-rendered-row.
 *
 * ── WHY THE PATH IS NOT CONFIGURABLE ────────────────────────────────────────
 *
 * It could be, and someone will eventually want it to be. It is deliberately
 * fixed: an operator who can set this path can also set it to somewhere inside
 * `public/`, which is precisely the mistake this class exists to prevent. The
 * only supported location is outside the web root.
 */
final class GoogleOAuthCredentials
{
    /**
     * Overridable ONLY for tests, which need a fixture the real resolver will
     * not find. Production never sets it; `resolvePath()` ignores it when the
     * real file is absent only if... it does not. It is honoured, because a test
     * that cannot point the class at a fixture would have to assert against the
     * developer's real credentials, which is worse.
     */
    public static ?string $pathOverride = null;

    private const RELATIVE = 'google/oauth-credentials.json';

    /**
     * @return array{client_id:string, client_secret:string, redirect_uri:string|null}
     *
     * @throws RuntimeException when the file is absent, unreadable, malformed, or
     *                          missing either credential
     */
    public static function read(): array
    {
        $path = self::path();

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(
                'Google OAuth credentials are not configured. Expected a readable file at '.
                self::displayPath().' for the Web application client.'
            );
        }

        $raw = @file_get_contents($path);

        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('The Google OAuth credentials file at '.self::displayPath().' could not be read.');
        }

        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // The parse message can carry a fragment of the file, so it is not
            // echoed. "Malformed JSON" is enough to act on, and a secret must
            // never reach a log through an exception message.
            throw new RuntimeException('The Google OAuth credentials file at '.self::displayPath().' is not valid JSON.', 0, $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('The Google OAuth credentials file at '.self::displayPath().' did not contain an object.');
        }

        // A Google "installed" client puts the secret under `installed`; a
        // "web" client puts it at the top level. Accepting both means the same
        // code works whichever download the operator made.
        $section = is_array($decoded['installed'] ?? null)
            ? $decoded['installed']
            : (is_array($decoded['web'] ?? null) ? $decoded['web'] : $decoded);

        $clientId = trim((string) ($section['client_id'] ?? ''));
        $clientSecret = trim((string) ($section['client_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException(
                'The Google OAuth credentials file at '.self::displayPath().
                ' does not contain both a client_id and a client_secret.'
            );
        }

        $redirect = $section['redirect_uris'][0] ?? null;

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => is_string($redirect) && $redirect !== '' ? $redirect : null,
        ];
    }

    public static function isConfigured(): bool
    {
        try {
            self::read();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function path(): string
    {
        if (self::$pathOverride !== null) {
            return self::$pathOverride;
        }

        return storage_path('app/'.self::RELATIVE);
    }

    /**
     * For error messages: `storage_path()` rather than the absolute path, so a
     * message in a log or a flash does not disclose the server's directory
     * layout.
     */
    private static function displayPath(): string
    {
        return 'storage/app/'.self::RELATIVE;
    }
}