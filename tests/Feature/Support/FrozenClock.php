<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Carbon;

/**
 * Pins "now" for tests whose fixtures use absolute calendar dates.
 *
 * Those fixtures describe a moment relative to the scenario (an allocation that
 * "starts after today", a class "in two days"), but were written with literal
 * dates. Against the real clock they silently flip meaning the day the literal
 * date passes. Freezing the clock makes the scenario mean the same thing
 * forever; no assertion is weakened.
 *
 * Tests that install their own clock keep doing so: they restore it to null
 * themselves, and the freeze is re-applied by the next test's setUp.
 */
trait FrozenClock
{
    /** The scenario date the fixtures in these classes were written against. */
    protected function freezeClock(string $at = '2026-09-29 12:00:00'): void
    {
        Carbon::setTestNow(Carbon::parse($at, 'UTC'));
        $this->beforeApplicationDestroyed(static fn () => Carbon::setTestNow());
    }
}
