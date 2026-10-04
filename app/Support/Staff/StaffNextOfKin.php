<?php

namespace App\Support\Staff;

use DomainException;

/**
 * The controlled vocabulary for a staff member's Next of Kin relationship.
 *
 * A Next of Kin is contact information only. It is never a system user: nothing
 * here creates an account, grants login access, or is validated for uniqueness
 * against the users table.
 *
 * The relationship is a selection rather than free text so reports and exports
 * stay comparable. "Other" is the one escape hatch and carries a short
 * description alongside it, which is stored in the same existing
 * emergency_contact_relationship column as "Other: <description>" so no extra
 * column is needed.
 */
final class StaffNextOfKin
{
    public const SPOUSE = 'Spouse';
    public const PARENT = 'Parent';
    public const SIBLING = 'Sibling';
    public const CHILD = 'Child';
    public const RELATIVE = 'Relative';
    public const GUARDIAN = 'Guardian';
    public const FRIEND = 'Friend';
    public const OTHER = 'Other';

    public const ALL = [
        self::SPOUSE, self::PARENT, self::SIBLING, self::CHILD,
        self::RELATIVE, self::GUARDIAN, self::FRIEND, self::OTHER,
    ];

    /** Column width of staff_profiles.emergency_contact_relationship. */
    private const MAX_LENGTH = 60;

    /**
     * Normalises a submitted relationship to a stored value.
     *
     * @param  string|null  $relationship  the selected relationship
     * @param  string|null  $otherDetail  the free-text description, only used for "Other"
     */
    public static function normalise(?string $relationship, ?string $otherDetail = null): string
    {
        $relationship = trim((string) $relationship);

        if ($relationship === '' || ! in_array($relationship, self::ALL, true)) {
            throw new DomainException('Choose how the Next of Kin is related to this staff member.');
        }

        if ($relationship !== self::OTHER) {
            return $relationship;
        }

        $otherDetail = trim((string) $otherDetail);
        if ($otherDetail === '') {
            throw new DomainException('Describe how the Next of Kin is related to this staff member.');
        }
        if (mb_strlen($otherDetail) > 40) {
            throw new DomainException('The Next of Kin relationship description may not be longer than 40 characters.');
        }

        return self::truncate(self::OTHER.': '.$otherDetail);
    }

    /** The stored value without any "Other: " prefix - for display and export. */
    public static function base(?string $stored): string
    {
        $stored = trim((string) $stored);
        if ($stored === '' || ! str_starts_with($stored, self::OTHER.':')) {
            return $stored;
        }

        return self::OTHER;
    }

    /** The "Other" description, or null when the relationship is a named option. */
    public static function otherDetail(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if (! str_starts_with($stored, self::OTHER.':')) {
            return null;
        }

        return trim(substr($stored, strlen(self::OTHER.':')));
    }

    private static function truncate(string $value): string
    {
        return mb_strlen($value) <= self::MAX_LENGTH ? $value : mb_substr($value, 0, self::MAX_LENGTH);
    }
}
