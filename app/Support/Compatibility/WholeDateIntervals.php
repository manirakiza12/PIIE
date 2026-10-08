<?php

namespace App\Support\Compatibility;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

/** Preserve Carbon 2's absolute, whole calendar-unit policy across versions. */
final class WholeDateIntervals
{
    private static function interval(DateTimeInterface $from, DateTimeInterface $to): DateInterval
    {
        $start = DateTimeImmutable::createFromInterface($from);
        $end = DateTimeImmutable::createFromInterface($to)->setTimezone($start->getTimezone());
        return $start->diff($end, true);
    }

    public static function seconds(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $interval = self::interval($from, $to);
        return $interval->days * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
    }

    public static function minutes(DateTimeInterface $from, DateTimeInterface $to): int
    {
        return intdiv(self::seconds($from, $to), 60);
    }

    public static function days(DateTimeInterface $from, DateTimeInterface $to): int
    {
        return self::interval($from, $to)->days;
    }
}
