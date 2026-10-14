<?php

namespace App\Support;

use App\Models\School;

/**
 * Resolves the school_id that the public (unauthenticated) application
 * form belongs to. This deployment serves exactly one institution's public
 * site, so resolution is a single configured value — never a dropdown, and
 * never derived from a logged-in user (there isn't one on this flow).
 *
 * Kept as one small, isolated entry point so that if another school later
 * needs its own domain-specific application portal, only this class needs
 * to change (e.g. to resolve by request host) — the public controller,
 * routes, and views never need to know how resolution works.
 */
class PublicTenantResolver
{
    public static function resolveSchoolId(): ?int
    {
        $configured = get_settings('primary_school_id');

        $id = filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id !== false && School::query()->whereKey($id)->exists() ? (int) $id : null;
    }
}
