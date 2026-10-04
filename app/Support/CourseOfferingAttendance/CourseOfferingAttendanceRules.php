<?php

namespace App\Support\CourseOfferingAttendance;

use DomainException;
use Illuminate\Support\Carbon;

/**
 * Field validation shared by the session service and its controller, so a rule
 * is never enforced in only one of them.
 *
 * Lives in its own PSR-4 file named after the class; see
 * CourseOfferingAttendanceSessionStatus for why that matters.
 */
final class CourseOfferingAttendanceRules
{
    public static function assertType(?string $type): string
    {
        $type = (string) $type;
        if (! in_array($type, CourseOfferingAttendanceSessionType::ALL, true)) {
            throw new DomainException('Choose a valid Attendance Session type.');
        }

        return $type;
    }

    /** ends_at must be later than starts_at whenever both are supplied. */
    public static function assertTimes(?string $startsAt, ?string $endsAt): void
    {
        if ($startsAt === null || $endsAt === null) {
            return;
        }
        $start = Carbon::createFromFormat('!H:i', $startsAt);
        $end = Carbon::createFromFormat('!H:i', $endsAt);
        if (! $start || ! $end) {
            throw new DomainException('Attendance Session times must use a valid HH:MM value.');
        }
        if ($end->lessThanOrEqualTo($start)) {
            throw new DomainException('The Attendance Session end time must be later than its start time.');
        }
    }
}
