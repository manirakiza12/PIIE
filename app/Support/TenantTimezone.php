<?php

namespace App\Support;

use App\Models\School;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Carbon;

/**
 * The institution's timezone: the one place that answers "what time is it at
 * this institution, and how do we say that to a person?".
 *
 * WHY THIS EXISTS
 *
 * PIIE is multi-tenant, so "the timezone" is a property of an institution, not
 * of the application. Before this, four different things answered that question
 * and only two of them were right: some screens read `schools.timezone`,
 * others read `config('app.timezone')`, and the Live Class create form told the
 * lecturer their institution "has not set a timezone yet" because the
 * administration screen that would have set it asked a human to TYPE a raw IANA
 * identifier into a free-text box. That is not a usable control.
 *
 * STORED VALUE vs SHOWN VALUE
 *
 * Stored: a real IANA identifier, e.g. "Africa/Kampala". Never a fixed offset.
 * This matters for correctness rather than taste - a region with daylight
 * saving (Europe/London, America/New_York) cannot be described by "UTC+3", and
 * a hardcoded offset would silently drift by an hour twice a year.
 * Shown: a human phrase derived from the same data, e.g.
 * "Kampala (UTC+3) - Africa". Nothing about that phrase is hardcoded either; it
 * is generated from PHP's own timezone database, so an institution in Nairobi
 * and one in New York are handled by exactly the same code.
 *
 * WHAT THIS DOES NOT DO
 *
 * It does not convert or rewrite any stored record. A Live Class holds an
 * absolute instant; this class only decides which wall clock that instant is
 * displayed in. Changing an institution's timezone therefore re-labels existing
 * schedules to the new local time without moving a single meeting in real
 * terms, and the original instants remain intact.
 */
class TenantTimezone
{
    /** @var array<string, string>|null memoised identifier list, built once per request */
    private static ?array $options = null;

    /**
     * The institution's stored IANA identifier, or null when it has never set
     * one. Deliberately does NOT fall back to the application default: callers
     * need to be able to tell "not configured" apart from "configured as UTC",
     * because only the former is worth telling a person about.
     */
    public function configured(User|School|null $tenant = null): ?string
    {
        $school = $this->school($tenant);

        if (! $school) {
            return null;
        }

        $value = trim((string) $school->getAttribute('timezone'));

        return $value !== '' && $this->isValid($value) ? $value : null;
    }

    /**
     * The identifier to use for this institution right now: its own if it has
     * one, otherwise the application default. This is the value every display
     * and every interpretation should use, so nothing silently renders in a
     * different clock from the rest of the page.
     */
    public function resolve(User|School|null $tenant = null): string
    {
        return $this->configured($tenant) ?? (string) config('app.timezone', 'UTC');
    }

    /**
     * THE RESOLUTION RULE.
     *
     *     the person's own timezone
     *         -> their institution's timezone
     *             -> the application default
     *
     * The first rung is what makes a multinational institution usable: a
     * lecturer in London and a student in New York both see their own local time
     * for a class whose official time is Kampala's, and neither of them can
     * change what the academic record says. Everyone still refers to ONE
     * stored instant, so a join window opens simultaneously for all of them.
     *
     * A NULL personal timezone is not an error and is not "UTC" - it simply
     * means the person has not chosen, and they follow their institution, which
     * is exactly what they did before personal timezones existed.
     */
    public function effective(?User $user = null, ?School $tenant = null): string
    {
        $own = $this->userConfigured($user);

        if ($own !== null) {
            return $own;
        }

        return $this->resolve($tenant ?? $this->school($user));
    }

    /** The person's own IANA identifier, or null when they have not chosen one. */
    public function userConfigured(?User $user = null): ?string
    {
        if (! $user) {
            return null;
        }

        $value = trim((string) $user->getAttribute('timezone'));

        return $value !== '' && $this->isValid($value) ? $value : null;
    }

    public function hasUserTimezone(?User $user = null): bool
    {
        return $this->userConfigured($user) !== null;
    }

    /**
     * Everything a screen needs to be honest about time, in one array:
     * whose clock this is, what the institution's clock is, and whether they
     * differ. Screens show both only when they differ, because showing a
     * duplicate identical time is noise.
     *
     * @return array<string, mixed>
     */
    public function describe(?User $user = null, ?School $tenant = null, ?Carbon $at = null): array
    {
        $at ??= now();
        $institution = $this->resolve($tenant ?? $this->school($user));
        $personal = $this->effective($user, $tenant);

        return [
            'identifier' => $personal,
            'label' => $this->humanize($personal, $at),
            'institution_identifier' => $institution,
            'institution_label' => $this->humanize($institution, $at),
            'uses_personal' => $this->hasUserTimezone($user) && $personal !== $institution,
            'has_personal' => $this->hasUserTimezone($user),
        ];
    }

    /**
     * Group user ids by the timezone their notification should be rendered in.
     *
     * A notification about one event must say the same thing to everyone about
     * WHEN it happens, but each person should read it in their own clock. The
     * stored instant is identical for every recipient - only the rendering
     * differs, which is why this groups rather than rewrites anything.
     *
     * @param  iterable<int>  $userIds
     * @return array<string, array<int>> timezone => user ids
     */
    public function groupByEffectiveTimezone(iterable $userIds, int $schoolId): array
    {
        $ids = array_values(array_filter(array_map('intval', is_array($userIds) ? $userIds : iterator_to_array($userIds))));

        if ($ids === []) {
            return [];
        }

        $rows = \App\Models\User::query()
            ->where('school_id', $schoolId)
            ->whereIn('id', $ids)
            ->get(['id', 'timezone', 'school_id']);

        $institution = $this->resolve(School::query()->find($schoolId));
        $grouped = [];

        foreach ($rows as $row) {
            $own = trim((string) $row->getAttribute('timezone'));
            $zone = ($own !== '' && $this->isValid($own)) ? $own : $institution;
            $grouped[$zone][] = (int) $row->id;
        }

        return $grouped;
    }

    public function isConfigured(User|School|null $tenant = null): bool
    {
        return $this->configured($tenant) !== null;
    }

    public function isValid(string $identifier): bool
    {
        return $identifier !== '' && in_array($identifier, DateTimeZone::listIdentifiers(), true);
    }

    /** e.g. "+03:00" */
    public function offsetLabel(string $identifier, ?Carbon $at = null): string
    {
        $offset = $this->offsetSeconds($identifier, $at);
        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);

        return sprintf('%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
    }

    public function offsetSeconds(string $identifier, ?Carbon $at = null): int
    {
        $at ??= now();
        $zone = new DateTimeZone($identifier);

        return $at->setTimezone($zone)->getOffset();
    }

    /**
     * "Kampala (UTC+3) - Africa". Derived from the identifier itself, so no
     * institution is special-cased and no city name is hardcoded.
     */
    public function humanize(string $identifier, ?Carbon $at = null): string
    {
        if (! $this->isValid($identifier)) {
            return $identifier;
        }

        $parts = explode('/', $identifier);
        $city = str_replace('_', ' ', (string) array_pop($parts));
        $area = count($parts) > 0 ? implode('/', $parts) : 'UTC';
        $offset = $this->offsetSeconds($identifier, $at);
        $sign = $offset < 0 ? '-' : '+';
        $absolute = abs($offset);
        $hours = intdiv($absolute, 3600);
        $minutes = intdiv($absolute % 3600, 60);

        $offsetText = $minutes > 0
            ? sprintf('UTC%s%d:%02d', $sign, $hours, $minutes)
            : sprintf('UTC%s%d', $sign, $hours);

        return sprintf('%s (%s) - %s', $city, $offsetText, $area);
    }

    /** The same, for the institution in front of us. */
    public function humanizeFor(User|School|null $tenant = null, ?Carbon $at = null): string
    {
        return $this->humanize($this->resolve($tenant), $at);
    }

    /**
     * Every identifier PHP supports, for the administration dropdown.
     *
     * @return array<string, array{value: string, label: string, area: string}>
     */
    public function options(?Carbon $at = null): array
    {
        if (self::$options !== null) {
            return self::$options;
        }

        $at ??= now();
        $options = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $options[$identifier] = [
                'value' => $identifier,
                'label' => $this->humanize($identifier, $at),
                'area' => str_contains($identifier, '/')
                    ? explode('/', $identifier)[0]
                    : 'Other',
            ];
        }

        // The application's own default first, because an institution that has
        // never chosen anything is currently running on it.
        $default = (string) config('app.timezone', 'UTC');
        if (isset($options[$default])) {
            $options = [$default => $options[$default]] + $options;
        }

        return self::$options = $options;
    }

    /** Options grouped by area, ready for <optgroup>. */
    public function groupedOptions(?Carbon $at = null): array
    {
        $grouped = [];
        foreach ($this->options($at) as $option) {
            $grouped[$option['area']][$option['value']] = $option;
        }
        ksort($grouped);

        return $grouped;
    }

    // ── Conversion helpers ──────────────────────────────────────────────
    // Everything below is a thin, honest wrapper so callers never have to
    // remember which direction they are converting.

    /** Render an absolute instant in the institution's clock. */
    public function inTenantTime(?Carbon $moment, User|School|null $tenant = null): ?Carbon
    {
        return $moment?->copy()->setTimezone($this->resolve($tenant));
    }

    /**
     * Render an instant in the PERSONAL (or institution) clock chosen for one
     * person. The presentation counterpart of inTenantTime().
     */
    public function inEffectiveTime(?Carbon $moment, ?User $user = null, ?School $tenant = null): ?Carbon
    {
        return $moment?->copy()->setTimezone($this->effective($user, $tenant));
    }

    /** Render an instant in an explicitly named zone, for per-recipient mail. */
    public function inZone(?Carbon $moment, string $identifier): ?Carbon
    {
        if (! $moment || ! $this->isValid($identifier)) {
            return $moment?->copy();
        }

        return $moment->copy()->setTimezone($identifier);
    }

    /**
     * Read a wall-clock time the institution entered and return the absolute
     * instant it refers to. This is the step that makes "09:20" mean
     * 09:20 Africa/Kampala rather than 09:20 UTC.
     */
    public function interpretInTenantTime(string $value, User|School|null $tenant = null, ?string $format = null): Carbon
    {
        $zone = $this->resolve($tenant);

        return $format !== null
            ? Carbon::createFromFormat($format, $value, $zone)
            : Carbon::parse($value, $zone);
    }

    /** "j F Y" etc., in the institution's clock. */
    public function format(?Carbon $moment, string $format = 'j F Y', User|School|null $tenant = null): string
    {
        if (! $moment) {
            return '';
        }

        return $moment->copy()->setTimezone($this->resolve($tenant))->format($format);
    }

    public function formatTime(?Carbon $moment, User|School|null $tenant = null): string
    {
        if (! $moment) {
            return '';
        }

        return $moment->copy()->setTimezone($this->resolve($tenant))->format('g:i A');
    }

    /** Resolve the School row a user belongs to, from a User or a School. */
    private function school(User|School|null $tenant): ?School
    {
        if ($tenant instanceof School) {
            return $tenant;
        }

        if ($tenant instanceof User) {
            $id = (int) $tenant->school_id;

            return $id > 0 ? School::query()->find($id) : null;
        }

        return null;
    }
}
