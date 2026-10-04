<?php

namespace App\Support\OnlineExams;

/**
 * WHERE A NOTIFICATION LINK ACTUALLY POINTS.
 *
 * ── THE REPORTED BUG, AND WHY IT HAPPENED ────────────────────────────────────
 *
 * An administrator clicked an Online Exam notification and the browser left the
 * application:
 *
 *     notification said  http://localhost/admin/online-exams/17
 *     browser went to    http://localhost/admin/online-exams/17   → Apache, 404
 *     application runs at http://127.0.0.1:8000
 *
 * `OnlineExamPortalNotifier` built its link with `route()`. `route()` returns an
 * ABSOLUTE url rooted at `config('app.url')`, which comes from `APP_URL`. In this
 * deployment `APP_URL=localhost`, so every notification link was minted with the
 * scheme and host of the configured value and none of the host the app was actually
 * being served on. The 404 was reported by Apache on port 80, which is running and
 * answering - it simply has no document root for that path.
 *
 * Hard-coding `127.0.0.1:8000` would have made this one click work on this machine
 * and would have been wrong in every other environment: a staging host, a real
 * domain, a load balancer. The value of `APP_URL` is also not the authority on which
 * host the current user is browsing - the REQUEST is.
 *
 * ── THE ROOT CAUSE, STATED ──────────────────────────────────────────────────
 *
 * A link that leaves the application was persisted before anyone clicked it. The
 * correct thing to persist is not a URL at all but a LOCATION: which page of this
 * application the notification is about. The host is a property of the session that
 * clicks it, not of the row that was written days earlier.
 *
 * So `toRelative()` is what every writer uses, and `toAbsolute()` is what every
 * reader uses. `toAbsolute()` resolves against the CURRENT REQUEST, so the same
 * stored row renders correctly whether the user reached it through
 * `localhost:8000`, `127.0.0.1:8000`, `piie.ac.ug`, or an IP address.
 *
 * ── WHY EXISTING ROWS ARE REPAIRED RATHER THAN MIGRATED ──────────────────────
 *
 * Links already in the table are absolute and rooted at `APP_URL`. Rewriting them
 * would need a migration over data that is correct in every respect except its
 * origin, and a migration is a thing that can be rolled back into a broken state.
 * `toAbsolute()` recognises a stored origin that belongs to this application and
 * re-roots it onto the current request instead, so rows written before this change
 * become correct as soon as they are clicked, with no data change at all.
 *
 * A link pointing somewhere genuinely EXTERNAL - and this engine has none, but the
 * guard exists so this cannot become the place that breaks one - is returned
 * untouched.
 */
final class OnlineExamNotificationLink
{
    /**
     * Reduce a generated URL to where it lives INSIDE this application.
     *
     * Returns a path with its query and fragment intact, e.g.
     * `/admin/online-exams/17?submission=10#submission-10`.
     */
    public static function toRelative(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        // Already relative: nothing to strip, but normalise a bare "admin/x" typo.
        if (! self::isAbsolute($url)) {
            return '/'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['path'])) {
            return $url;
        }

        // A host that is NOT this application is a different application. Left alone
        // deliberately: rewriting it would turn a valid external link into a 404.
        if (! self::belongsToThisApplication($parts['host'] ?? null, $parts['scheme'] ?? null)) {
            return $url;
        }

        return self::composePath($parts);
    }

    /**
     * Resolve a stored link for the CURRENT viewer.
     *
     * Relative -> absolute against the request in flight. Absolute-but-stale ->
     * re-rooted onto the request, which is what repairs rows written before this
     * class existed. Genuinely external -> untouched.
     */
    public static function toAbsolute(?string $stored): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }

        $stored = trim($stored);

        if (! self::isAbsolute($stored)) {
            return url()->to($stored);
        }

        $parts = parse_url($stored);

        if ($parts === false || ! self::belongsToThisApplication($parts['host'] ?? null, $parts['scheme'] ?? null)) {
            return $stored;
        }

        // `url()->to()` with a path resolves against the current request's root, so
        // the scheme, host and port are the ones the user is demonstrably on.
        return url()->to(self::composePath($parts));
    }

    /**
     * The absolute URL a named route points at, reduced to a location.
     *
     * `route()` is still what decides WHICH page, so the path can never drift from
     * the routing table. Only the origin is dropped.
     */
    public static function route(string $name, mixed $parameters = [], ?string $query = null, ?string $fragment = null): string
    {
        $absolute = route($name, $parameters);
        $parts = parse_url($absolute);
        $path = self::composePath(is_array($parts) ? $parts : []);

        if ($query !== null) {
            $path .= '?'.$query;
        }

        if ($fragment !== null) {
            $path .= '#'.$fragment;
        }

        return $path;
    }

    private static function composePath(array $parts): string
    {
        $path = $parts['path'] ?? '/';

        if ($path === '') {
            $path = '/';
        }

        return $path
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private static function isAbsolute(string $url): bool
    {
        return (bool) preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)
            || str_starts_with($url, '//');
    }

    /**
     * Does this origin belong to the application we are running?
     *
     * Two origins count, and both must:
     *
     *  - `config('app.url')`, which is what `route()` mints. This is the origin of
     *    every link already in the table.
     *  - the current request's host, which is where the user actually is.
     *
     * Comparing on host alone, case-insensitively, and ignoring the scheme: a
     * deployment behind a TLS terminator can legitimately write `http` into
     * `APP_URL` while serving `https`, and treating that as "somewhere else" would
     * leave exactly the bug this class exists to fix in place.
     */
    private static function belongsToThisApplication(?string $host, ?string $scheme): bool
    {
        if (! $host) {
            return false;
        }

        $host = strtolower($host);
        $known = [];

        $configured = parse_url((string) config('app.url'));
        if (is_array($configured) && ! empty($configured['host'])) {
            $known[] = strtolower($configured['host']);
        }

        // `APP_URL=localhost` with no scheme parses without a host, so the bare
        // value is worth checking too. Without this, `localhost` links would look
        // external and never be repaired.
        $raw = trim((string) config('app.url'));
        if ($raw !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $known[] = strtolower($raw);
        }

        $known[] = strtolower((string) request()->getHost());

        /**
         * LOOPBACK, ALWAYS OURS.
         *
         * A link stored as `http://localhost/...` — the exact value `APP_URL=localhost`
         * minted before this class existed — matches neither `config('app.url')` nor the
         * request host once `APP_URL` has been corrected to a real origin. Without this
         * allowance such a row is classified as EXTERNAL and returned untouched, and
         * clicking it goes to port 80 where Apache answers 404: the reported bug,
         * surviving the fix.
         *
         * `localhost` and the loopback addresses cannot be a legitimate external
         * destination — nothing outside this machine is reachable at them — so treating
         * them as ours cannot swallow a valid external link.
         */
        foreach (['localhost', '127.0.0.1', '[::1]', '::1'] as $loopback) {
            $known[] = $loopback;
        }

        // Compare without the port: a stored `localhost:8000` and a request for
        // `localhost` are the same application, and a stored link with no port is the
        // broken case this class exists to repair.
        $hostOnly = strtolower(explode(':', $host, 2)[0]);

        if (in_array($hostOnly, array_unique($known), true)) {
            return true;
        }

        return in_array($host, array_unique($known), true);
    }
}