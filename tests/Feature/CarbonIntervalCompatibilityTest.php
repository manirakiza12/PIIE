<?php

namespace Tests\Feature;

use App\Support\Compatibility\WholeDateIntervals;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class CarbonIntervalCompatibilityTest extends TestCase
{
    public static function intervals(): array
    {
        return [
            'exact minutes' => ['2026-10-08 10:00:00', '2026-10-08 11:00:00', 'UTC', 'UTC', 3600, 60, 0],
            'fractional minute' => ['2026-10-08 10:00:00.900000', '2026-10-08 11:00:59.800000', 'UTC', 'UTC', 3658, 60, 0],
            'reversed' => ['2026-10-08 11:01:00', '2026-10-08 10:00:00', 'UTC', 'UTC', 3660, 61, 0],
            'same instant different zones' => ['2026-10-08 10:00:00', '2026-10-08 13:00:00', 'UTC', 'Africa/Nairobi', 0, 0, 0],
            'spring DST day' => ['2026-03-08 00:00:00', '2026-03-09 00:00:00', 'America/New_York', 'America/New_York', 86400, 1440, 1],
            'autumn DST day' => ['2026-11-01 00:00:00', '2026-11-02 00:00:00', 'America/New_York', 'America/New_York', 86400, 1440, 1],
            'spring fractional day' => ['2026-03-07 12:00:00', '2026-03-09 11:59:59', 'America/New_York', 'America/New_York', 172799, 2879, 1],
            'different DST zones' => ['2026-03-08 00:00:00', '2026-03-09 04:00:00', 'America/New_York', 'UTC', 86400, 1440, 1],
            'leap year' => ['2024-02-28 10:00:00', '2024-03-01 09:59:59', 'UTC', 'UTC', 172799, 2879, 1],
            'fractional second' => ['2026-10-08 10:00:00.900000', '2026-10-08 10:00:01.100000', 'UTC', 'UTC', 0, 0, 0],
        ];
    }

    /** @dataProvider intervals */
    public function test_native_whole_intervals_preserve_carbon_2_policy(string $start, string $end, string $tz1, string $tz2, int $seconds, int $minutes, int $days): void
    {
        $a = Carbon::parse($start, $tz1); $b = Carbon::parse($end, $tz2);
        $before = [$a->format('c.u'), $b->format('c.u')];
        $this->assertSame($seconds, WholeDateIntervals::seconds($a, $b));
        $this->assertSame($minutes, WholeDateIntervals::minutes($a, $b));
        $this->assertSame($days, WholeDateIntervals::days($a, $b));
        $this->assertSame($before, [$a->format('c.u'), $b->format('c.u')]);
    }

    public function test_carbon_3_signed_float_semantics_cannot_change_native_results(): void
    {
        $later = Carbon3DifferenceFixture::parse('2026-10-08 11:01:30', 'UTC');
        $earlier = Carbon3DifferenceFixture::parse('2026-10-08 10:00:00', 'UTC');
        $this->assertSame(-61.5, $later->diffInMinutes($earlier));
        $this->assertSame(61, WholeDateIntervals::minutes($later, $earlier));
        $this->assertSame(3690, WholeDateIntervals::seconds($later, $earlier));
        $this->assertSame(0, WholeDateIntervals::days($later, $earlier));
    }
}

/** Test-only Carbon 3 seconds/minutes default sign and fractional return shape. */
class Carbon3DifferenceFixture extends Carbon
{
    public function diffInSeconds($date = null, $absolute = false): float
    {
        $value = (float) $date->format('U.u') - (float) $this->format('U.u');
        return $absolute ? abs($value) : $value;
    }
    public function diffInMinutes($date = null, $absolute = false): float
    {
        return $this->diffInSeconds($date, $absolute) / 60;
    }
}
