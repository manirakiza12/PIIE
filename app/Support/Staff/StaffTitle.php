<?php

namespace App\Support\Staff;

use DomainException;

/**
 * A staff member's personal title (Mr, Dr, Prof …).
 *
 * This is the courtesy title that precedes the name, and nothing else. A
 * responsibility such as "Head of Department" belongs to the designation and
 * the delegated roles in Roles & Permissions, never here.
 *
 * Reuses the existing staff_profiles.title column (varchar 30) as-is: no new
 * column, and historical free-text titles stay readable because the stored
 * value is just text.
 *
 * "Other" is the single escape hatch for an uncommon title and carries a short
 * description, stored in the same existing column as "Other: <description>" so
 * no additional column is needed.
 */
final class StaffTitle
{
    public const MR = 'Mr';
    public const MRS = 'Mrs';
    public const MS = 'Ms';
    public const MISS = 'Miss';
    public const DR = 'Dr';
    public const PROF = 'Prof';
    public const REV = 'Rev';
    public const FR = 'Fr';
    public const SR = 'Sr';
    public const ENG = 'Eng';
    public const OTHER = 'Other';

    public const ALL = [
        self::MR, self::MRS, self::MS, self::MISS, self::DR,
        self::PROF, self::REV, self::FR, self::SR, self::ENG, self::OTHER,
    ];

    /** Column width of staff_profiles.title. */
    private const MAX_LENGTH = 30;

    /** Leaves room for the "Other: " prefix inside the column. */
    private const MAX_DETAIL = 20;

    public static function normalise(?string $title, ?string $otherDetail = null): string
    {
        $title = trim((string) $title);

        if ($title === '' || ! in_array($title, self::ALL, true)) {
            throw new DomainException('Choose a title.');
        }

        if ($title !== self::OTHER) {
            return $title;
        }

        $otherDetail = trim((string) $otherDetail);
        if ($otherDetail === '') {
            throw new DomainException('Enter the title to use.');
        }
        if (mb_strlen($otherDetail) > self::MAX_DETAIL) {
            throw new DomainException('That title may not be longer than 20 characters.');
        }

        return mb_substr(self::OTHER.': '.$otherDetail, 0, self::MAX_LENGTH);
    }

    /** The stored value without the "Other: " prefix - for display and export. */
    public static function base(?string $stored): string
    {
        $stored = trim((string) $stored);
        if ($stored === '' || ! str_starts_with($stored, self::OTHER.':')) {
            return $stored;
        }

        return self::OTHER;
    }

    /** The "Other" description, or null when the title is a named option. */
    public static function otherDetail(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if (! str_starts_with($stored, self::OTHER.':')) {
            return null;
        }

        return trim(substr($stored, strlen(self::OTHER.':')));
    }
}
