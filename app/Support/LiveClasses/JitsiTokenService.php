<?php

namespace App\Support\LiveClasses;

use App\Models\LiveClass;
use App\Models\User;
use Firebase\JWT\JWT;

/**
 * Signs the Jitsi moderator JWT — see LIVE_CLASS_JITSI_JWT_SETUP.md.
 *
 * Without this, LiveClassController embeds Jitsi anonymously: nobody in the
 * call is authenticated, so Jitsi's server never grants moderator rights to
 * anyone, including the person who started the class — that's the
 * "waiting for a moderator" bug. A signed token with `context.user.moderator`
 * is Jitsi's own documented mechanism for fixing this; there is no
 * config-only workaround that works reliably on the public meet.jit.si.
 *
 * Supports both shapes production schools realistically have access to:
 *   - RS256: 8x8 JaaS (jaas.8x8.vc) — no server to run, works on ordinary
 *     shared hosting. `sub` is the JaaS app id, matching the `/{appId}/room`
 *     path JaaS expects in the meeting URL itself.
 *   - HS256: a self-hosted Jitsi with the jitsi-meet-tokens prosody plugin.
 *     `sub` is conventionally '*' (any configured domain).
 *
 * Treats missing/incomplete credentials as "not configured" and returns
 * null rather than throwing — every call site must keep working exactly as
 * it does today (anonymous embed, no moderator rights) until this is set up.
 */
class JitsiTokenService
{
    public static function isConfigured(): bool
    {
        $appId = (string) config('services.jitsi.app_id');
        if ($appId === '') {
            return false;
        }

        if (self::algorithm() === 'HS256') {
            // JWT 7 requires at least 256 bits for HS256. Weak credentials
            // must not advertise moderator support or reach the signer.
            return strlen((string) config('services.jitsi.app_secret')) >= 32;
        }

        if ((string) config('services.jitsi.kid') === '') {
            return false;
        }
        $key = @openssl_pkey_get_private(self::normalizedPrivateKey((string) config('services.jitsi.private_key')));
        if ($key === false) {
            return false;
        }
        $details = openssl_pkey_get_details($key);
        return is_array($details) && $details['type'] === OPENSSL_KEYTYPE_RSA && $details['bits'] >= 2048;
    }

    /**
     * @return string|null A signed JWT, or null when Jitsi credentials
     *                      aren't configured (caller falls back to the
     *                      anonymous embed) or the class has no usable
     *                      meeting URL to derive a room name from.
     */
    public static function generate(LiveClass $liveClass, User $user, bool $isModerator): ?string
    {
        if (!self::isConfigured()) {
            return null;
        }

        $room = self::roomNameFromUrl((string) $liveClass->meeting_url);
        if ($room === null) {
            return null;
        }

        $algorithm = self::algorithm();
        $appId = (string) config('services.jitsi.app_id');
        $now = time();

        $payload = [
            'aud' => 'jitsi',
            'iss' => $appId,
            // JaaS ties the token to its own app id; a self-hosted server
            // with jitsi-meet-tokens conventionally accepts '*' (any of its
            // configured domains) here.
            'sub' => $algorithm === 'RS256' ? $appId : '*',
            'room' => $room,
            'exp' => $now + (4 * 3600),
            'nbf' => $now - 10,
            'context' => [
                'user' => [
                    'id' => (string) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'moderator' => $isModerator,
                ],
                // JaaS-specific feature flags; harmless no-ops on a
                // self-hosted server that doesn't look for them.
                'features' => [
                    'livestreaming' => $isModerator,
                    'recording' => $isModerator,
                    'transcription' => $isModerator,
                    'outbound-call' => false,
                ],
            ],
        ];

        if ($algorithm === 'HS256') {
            $secret = (string) config('services.jitsi.app_secret');
            return JWT::encode($payload, $secret, 'HS256');
        }

        $privateKey = self::normalizedPrivateKey((string) config('services.jitsi.private_key'));
        $kid = (string) config('services.jitsi.kid');

        return JWT::encode($payload, $privateKey, 'RS256', $kid);
    }

    private static function algorithm(): string
    {
        $algorithm = strtoupper((string) config('services.jitsi.algorithm', 'RS256'));
        return $algorithm === 'HS256' ? 'HS256' : 'RS256';
    }

    /**
     * The room is whatever path segment Jitsi actually treats as the room
     * name — the last one. For a plain meet.jit.si-shaped URL that's the
     * whole path; for JaaS (https://8x8.vc/{appId}/{room}) the app id
     * prefix must NOT be included in the `room` claim, only the trailing
     * segment.
     */
    private static function roomNameFromUrl(string $meetingUrl): ?string
    {
        $path = trim((string) parse_url($meetingUrl, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        return end($segments) ?: null;
    }

    /**
     * Accepts a private key pasted into .env either as a real multi-line
     * PEM block or with literal "\n" sequences (the common way to keep a
     * PEM on one .env line) — normalizes to what firebase/php-jwt expects.
     */
    private static function normalizedPrivateKey(string $key): string
    {
        return str_contains($key, '\\n') ? str_replace('\\n', "\n", $key) : $key;
    }
}
