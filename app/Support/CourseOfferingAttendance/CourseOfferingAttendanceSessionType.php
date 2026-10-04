<?php

namespace App\Support\CourseOfferingAttendance;

/**
 * What kind of teaching occurrence a session records.
 *
 * Lives in its own PSR-4 file named after the class; see
 * CourseOfferingAttendanceSessionStatus for why that matters.
 */
final class CourseOfferingAttendanceSessionType
{
    public const LECTURE = 'lecture';
    public const TUTORIAL = 'tutorial';
    public const LAB = 'lab';
    public const SEMINAR = 'seminar';
    public const WORKSHOP = 'workshop';
    public const LIVE_CLASS = 'live_class';
    public const OTHER = 'other';

    public const ALL = [self::LECTURE, self::TUTORIAL, self::LAB, self::SEMINAR, self::WORKSHOP, self::LIVE_CLASS, self::OTHER];

    public const LABELS = [
        self::LECTURE => 'Lecture',
        self::TUTORIAL => 'Tutorial',
        self::LAB => 'Lab',
        self::SEMINAR => 'Seminar',
        self::WORKSHOP => 'Workshop',
        self::LIVE_CLASS => 'Live Class',
        self::OTHER => 'Other',
    ];

    public static function label(?string $type): string
    {
        return self::LABELS[(string) $type] ?? 'Session';
    }
}
