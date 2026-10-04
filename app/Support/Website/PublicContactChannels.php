<?php

namespace App\Support\Website;

/**
 * Resolves the institution's PUBLIC contact channels from `website_settings`.
 *
 * ── WHY A SINGLE CLASS ────────────────────────────────────────────────────────
 * The header utility bar, the contact page cards and the footer all need the same
 * telephone numbers, email addresses, address and office hours. Resolving them three
 * times is how a footer ends up showing an address the contact page does not, or a
 * `mailto:` that 404s. One class, one answer.
 *
 * ── WHY THE VALUES ARE NOT INVENTED ───────────────────────────────────────────
 * As of this writing the CMS holds exactly one contact detail:
 * `contact_address` = "Nansana Municipality, Wakiso District, Uganda". There is no
 * published telephone number and no published email address. (`schools.email` holds
 * `info@piie.test` with `schools.phone` = 0, but that is the tenant row, not the
 * website settings, and `.test` is an IANA-reserved TLD that can never receive mail.)
 *
 * So every method here returns an EMPTY collection for an unconfigured field, and
 * the templates omit that item. `contactPhones()` returning `[]` is a correct answer,
 * not a failure, and it is what makes it safe to call these from shared partials.
 *
 * ── WHY MULTIPLE VALUES ARE SUPPORTED WITHOUT A SCHEMA CHANGE ─────────────────
 * The brief asks for "multiple telephone numbers and email addresses" with a minimal
 * extension. `website_settings` is a key/value table and already carries an
 * `is_json` flag, so one row can hold a JSON array:
 *
 *     contact_phone  = ["+256 700 111 222", "+256 414 555 666"]
 *
 * A plain delimited string works too and needs no JSON at all:
 *
 *     contact_phone  = "+256 700 111 222\n+256 414 555 666"
 *
 * `values()` accepts either. That means Super Admin can publish one number today
 * with no code change and a second tomorrow, still with no code change.
 */
class PublicContactChannels
{
    /**
     * TLDs reserved by IANA / RFC 2606 for testing and documentation.
     *
     * Suppressing them is not overriding the CMS: the row stays exactly as the
     * administrator saved it, it simply is not printed as a way to make contact.
     */
    private const RESERVED_TLDS = [
        'test', 'example', 'invalid', 'localhost',
    ];

    /**
     * Whole domains reserved for documentation.
     *
     * Separate from the TLD list because `example.com` has the TLD `com`, so a
     * TLD-only check lets it straight through — and it is just as undeliverable as
     * `x.test`. RFC 2606 reserves all three.
     */
    private const RESERVED_DOMAINS = [
        'example.com', 'example.net', 'example.org',
    ];

    /** @return array<int, string> */
    public static function phones(array $settings): array
    {
        return self::clean(
            self::values($settings['contact_phone'] ?? null),
            // A telephone number must contain at least one NON-ZERO digit.
            //
            // `/\d/` alone is not enough: the tenant row in this database stores
            // `schools.phone = 0`, which passes a "has a digit" test and would publish
            // a `tel:0` link. A number made only of zeros is a placeholder, not a
            // number anyone can ring.
            fn ($v) => (bool) preg_match('/[1-9]/', $v),
        );
    }

    /** @return array<int, string> */
    public static function emails(array $settings): array
    {
        return self::clean(
            self::values($settings['contact_email'] ?? null),
            fn ($v) => self::isPublishableEmail($v),
        );
    }

    /**
     * The institution's official switchboard line, as a distinct channel.
     *
     * Uganda institutions publish a landline ("official line") and a mobile
     * ("telephone") and visitors need to tell them apart, so the label is not
     * decoration. Returned separately from `phones()` because the contact page's CALL
     * US card labels them individually, while the header shows the numbers only.
     *
     * Falls back to the first entry of `contact_phone` when `contact_line` has not
     * been recorded, so a partially configured institution still gets a labelled
     * line rather than an empty card.
     */
    public static function officialLine(array $settings): ?string
    {
        $line = self::text($settings['contact_line'] ?? null);

        if ($line !== null && (bool) preg_match('/[1-9]/', $line)) {
            return $line;
        }

        return self::phones($settings)[0] ?? null;
    }

    /**
     * The mobile numbers, excluding the official line.
     *
     * So the CALL US card can say "Official Line" for one and "Telephone" for the
     * other without printing the same number twice when an institution records a
     * single number in both settings.
     */
    public static function telephoneNumbers(array $settings): array
    {
        $line = self::officialLine($settings);

        return array_values(array_filter(
            self::phones($settings),
            fn ($p) => $p !== $line
        ));
    }

    /**
     * The institution's website, as an absolute HTTPS URL, or null.
     *
     * ── WHY THE SCHEME IS ADDED HERE ─────────────────────────────────────────
     * The institution supplied `www.primeinternationalinstitute.ac.ug` with no scheme.
     * Stored verbatim, a template would have to decide between `http://` (which a
     * browser flags as insecure and which most browsers upgrade anyway, inconsistently)
     * and `https://`. Deciding once, here, means the header, the contact page and the
     * footer cannot disagree, and an administrator cannot accidentally record a link
     * that downgrades.
     *
     * An administrator who DOES record a scheme is respected, so an `http://` value
     * entered deliberately is not silently rewritten.
     *
     * Rejected outright: anything whose host is not a plausible domain. A value of
     * `javascript:alert(1)` must never become an `href`.
     */
    public static function website(array $settings): ?string
    {
        $raw = self::text($settings['contact_website'] ?? null);

        if ($raw === null) {
            return null;
        }

        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }

        // Must look like a hostname: labels of letters/digits/hyphens, at least one
        // dot, and a plausible TLD. This is what stops a script URL being rendered.
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $raw)) {
            return null;
        }

        return 'https://'.$raw;
    }

    public static function address(array $settings): ?string
    {
        return self::text($settings['contact_address'] ?? null);
    }

    public static function hours(array $settings): ?string
    {
        return self::text($settings['office_hours'] ?? null);
    }

    /**
     * Whether ANY channel is configured.
     *
     * The contact bar and the footer use this to hide themselves entirely rather
     * than render an empty strip, which reads as a mistake.
     */
    public static function hasAny(array $settings): bool
    {
        return self::phones($settings) !== []
            || self::emails($settings) !== []
            || self::website($settings) !== null
            || self::address($settings) !== null
            || self::hours($settings) !== null;
    }

    /**
     * A telephone number reduced to characters a `tel:` URI accepts.
     *
     * `tel:` takes a global number. Spaces, dashes, brackets and dots are removed;
     * a leading `00` is rewritten to `+` so a number written for humans dials.
     */
    public static function telHref(string $phone): string
    {
        $raw = trim($phone);

        if (str_starts_with($raw, '00')) {
            $raw = '+'.substr($raw, 2);
        }

        return 'tel:'.preg_replace('/[^\d+]/', '', $raw);
    }

    /** A label for a phone number: the first line only, so a two-line value is not crammed. */
    public static function phoneLabel(string $phone): string
    {
        $first = trim(preg_split('/[\r\n]+/', $phone)[0] ?? $phone);

        return $first !== '' ? $first : trim($phone);
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /**
     * Split a stored setting into individual values.
     *
     * JSON array first, because `is_json` rows are stored that way. A delimited
     * string is the fallback so a Super Admin who types two numbers on two lines
     * gets two numbers without learning the JSON form.
     */
    private static function values(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }

        $text = self::text($raw);

        if ($text === null) {
            return [];
        }

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return array_values($decoded);
        }

        // Split on newlines, semicolons and pipes, but NOT on commas: a value such as
        // "Nansana, Wakiso District" is a single address and must not be torn in two.
        $parts = preg_split('/[\r\n;|]+/', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($v) => $v !== ''));
    }

    /** @return array<int, string> */
    private static function clean(array $values, callable $keep): array
    {
        $out = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '' && $keep($value) && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    private static function text(mixed $raw): ?string
    {
        if (! is_scalar($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }

    private static function isPublishableEmail(string $value): bool
    {
        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $domain = strtolower(substr(strrchr($value, '@') ?: '', 1));

        if ($domain === '' || in_array($domain, self::RESERVED_DOMAINS, true)) {
            return false;
        }

        $tld = substr(strrchr($domain, '.') ?: '', 1);

        return ! in_array($tld, self::RESERVED_TLDS, true);
    }
}
