<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Proxies whose X-Forwarded-* headers may be trusted.
     *
     * Null (the default) trusts nothing, which is the safe posture for a
     * directly-exposed host: an attacker could otherwise forge X-Forwarded-Proto
     * and make the application mint https:// links, or poison password-reset
     * and signed URLs. A host sitting behind a known reverse proxy or TLS
     * terminator can opt in per environment with TRUSTED_PROXIES=127.0.0.1
     * (comma-separated) without a code change.
     *
     * @return array<int, string>|string|null
     */
    protected function proxies()
    {
        $configured = env('TRUSTED_PROXIES');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        $proxies = array_values(array_filter(array_map('trim', explode(',', $configured)), fn ($value) => $value !== ''));

        return $proxies ?: null;
    }
}
