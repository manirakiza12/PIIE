<?php

namespace App\Support\CourseOfferingAttendance;

/**
 * Session lifecycle. Correction happens by moving a session back through these
 * states, never by deleting an academic record.
 *
 * This lives in its own PSR-4 file, named after the class. It was previously
 * declared alongside CourseOfferingAttendanceStatus, which meant
 * CourseOfferingAttendanceSessionStatus could only be autoloaded when a
 * sibling class had already been loaded - so the lecturer Attendance create page
 * died with "Class ... not found" the first time it was reached in a request.
 */
final class CourseOfferingAttendanceSessionStatus
{
    /** Attendance may be marked or edited by an authorized current lecturer. */
    public const DRAFT = 'draft';
    /** Normal lecturer editing has stopped; the register is the record of truth. */
    public const FINALISED = 'finalised';
    /** No lecturer mutation at all. */
    public const LOCKED = 'locked';

    public const ALL = [self::DRAFT, self::FINALISED, self::LOCKED];

    public const LABELS = [
        self::DRAFT => 'Draft',
        self::FINALISED => 'Finalised',
        self::LOCKED => 'Locked',
    ];

    /** Only a draft register accepts lecturer changes. */
    public const MUTABLE = [self::DRAFT];

    public static function label(?string $status): string
    {
        return self::LABELS[(string) $status] ?? 'Unknown';
    }

    public static function acceptsMarking(?string $status): bool
    {
        return in_array($status, self::MUTABLE, true);
    }
}
