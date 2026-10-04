<?php

namespace App\Support\Staff;

use DomainException;

/**
 * The highest academic qualification a staff member holds.
 *
 * A selection rather than free text, so a lecturer's seniority is comparable
 * and reportable across the institution. It is stored on the EXISTING
 * staff_qualifications.qualification_level column (varchar 50) as-is: no new
 * column, and the existing per-staff 0..N qualification records are untouched,
 * so a staff member can still hold many qualifications and can add more later
 * from their profile.
 *
 * The creation form captures the primary/highest qualification only. It is one
 * record in the existing many-per-staff table, never a single permanent column.
 */
final class StaffQualificationLevel
{
    public const CERTIFICATE = 'Certificate';
    public const DIPLOMA = 'Diploma';
    public const BACHELORS = "Bachelor's Degree";
    public const POSTGRADUATE_DIPLOMA = 'Postgraduate Diploma';
    public const MASTERS = "Master's Degree";
    public const DOCTORATE = 'Doctorate / PhD';
    public const OTHER = 'Other';

    public const ALL = [
        self::CERTIFICATE, self::DIPLOMA, self::BACHELORS, self::POSTGRADUATE_DIPLOMA,
        self::MASTERS, self::DOCTORATE, self::OTHER,
    ];

    /** Column width of staff_qualifications.qualification_level. */
    private const MAX_LENGTH = 50;

    private const MAX_DETAIL = 35;

    /**
     * Normalises the selection, folding an "Other" description into the same
     * existing column rather than adding one.
     */
    public static function normalise(?string $level, ?string $otherDetail = null): string
    {
        $level = trim((string) $level);

        if ($level === '' || ! in_array($level, self::ALL, true)) {
            throw new DomainException('Choose the highest qualification held.');
        }

        if ($level !== self::OTHER) {
            return $level;
        }

        $otherDetail = trim((string) $otherDetail);
        if ($otherDetail === '') {
            throw new DomainException('Enter the qualification held.');
        }
        if (mb_strlen($otherDetail) > self::MAX_DETAIL) {
            throw new DomainException('That qualification may not be longer than 35 characters.');
        }

        return mb_substr(self::OTHER.': '.$otherDetail, 0, self::MAX_LENGTH);
    }

    /** The stored value without the "Other: " prefix. */
    public static function base(?string $stored): string
    {
        $stored = trim((string) $stored);
        if ($stored === '' || ! str_starts_with($stored, self::OTHER.':')) {
            return $stored;
        }

        return self::OTHER;
    }

    public static function otherDetail(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if (! str_starts_with($stored, self::OTHER.':')) {
            return null;
        }

        return trim(substr($stored, strlen(self::OTHER.':')));
    }
}
