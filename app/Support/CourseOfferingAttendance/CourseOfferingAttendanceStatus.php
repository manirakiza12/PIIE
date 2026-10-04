<?php

namespace App\Support\CourseOfferingAttendance;

/**
 * Governed vocabularies for HEI Course Offering attendance.
 *
 * Statuses are named constants, never magic numbers in controllers or views.
 *
 * ATTENDANCE_STATUS deliberately extends the meaning the legacy K12 register
 * already gave its integer column - 0 absent, 1 present - so the two domains
 * read the same way, and adds 2 late and 3 excused for the HEI register. The K12
 * table is not modified and keeps using only 0 and 1.
 *
 * Each sibling vocabulary now lives in its own PSR-4 file named after it:
 *   CourseOfferingAttendanceSessionStatus
 *   CourseOfferingAttendanceSessionType
 *   CourseOfferingAttendanceRules
 * They used to be declared in this file, which meant they could only be
 * autoloaded when this class happened to be loaded first. The lecturer
 * Attendance create page reached CourseOfferingAttendanceSessionType first and
 * failed with "Class ... not found" (500). One class per file, one file per
 * class; the definitions themselves are unchanged.
 */
final class CourseOfferingAttendanceStatus
{
    public const ABSENT = 0;
    public const PRESENT = 1;
    public const LATE = 2;
    public const EXCUSED = 3;

    /** Statuses that count as having attended. */
    public const ATTENDED = [self::PRESENT, self::LATE];

    public const ALL = [self::ABSENT, self::PRESENT, self::LATE, self::EXCUSED];

    public const LABELS = [
        self::ABSENT => 'Absent',
        self::PRESENT => 'Present',
        self::LATE => 'Late',
        self::EXCUSED => 'Excused',
    ];

    public static function label(int $status): string
    {
        return self::LABELS[$status] ?? 'Unknown';
    }

    public static function isValid(int $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
