<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * EXAMINATION LIFECYCLE TIMEZONE AND BOUNDARY REGRESSION.
 *
 * Pins the contract that scopeActive/scopeEnded/scopeUpcoming and
 * getLifecycleStatusAttribute agree on every boundary, regardless of the
 * application timezone, because database timestamps are stored in UTC and
 * comparison instants are resolved to UTC before binding.
 */
class OnlineExamLifecycleTimezoneTest extends TestCase
{
    use OnlineExamTestHelper;

    private const KAMPALA = 'Africa/Kampala';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();
        Carbon::setTestNow(null);
    }

    /**
     * Build a published exam whose window is 10:00-11:00 Kampala on 2026-11-01
     * (07:00-08:00 UTC), expressed as UTC Carbon instances exactly as
     * setStartDatetimeAttribute/setEndDatetimeAttribute store them.
     */
    private function makeKampalaWindowExam(array $overrides = []): OnlineExam
    {
        $start = Carbon::parse('2026-11-01 10:00:00', self::KAMPALA)->setTimezone('UTC');
        $end = Carbon::parse('2026-11-01 11:00:00', self::KAMPALA)->setTimezone('UTC');

        $examId = (int) $this->makeExam(array_merge([
            'school_id' => 1,
            'title' => 'Kampala window exam',
            'workflow_state' => 'published',
            'is_published' => 1,
            'start_datetime' => $start,
            'end_datetime' => $end,
        ], $overrides));

        return OnlineExam::query()->findOrFail($examId);
    }

    /** @return array{label:string, moment:Carbon} */
    private function boundaryMoments(): array
    {
        $kampala = self::KAMPALA;

        return [
            'one second before opening' => Carbon::parse('2026-11-01 09:59:59', $kampala),
            'exactly at opening'        => Carbon::parse('2026-11-01 10:00:00', $kampala),
            'during examination'        => Carbon::parse('2026-11-01 10:30:00', $kampala),
            'one second before closing' => Carbon::parse('2026-11-01 10:59:59', $kampala),
            'exactly at closing'        => Carbon::parse('2026-11-01 11:00:00', $kampala),
            'one second after closing'  => Carbon::parse('2026-11-01 11:00:01', $kampala),
            'several hours after closing' => Carbon::parse('2026-11-01 14:00:00', $kampala),
        ];
    }

    /** @return array{label:string, active:bool, ended:bool, status:string} */
    private function expectedByBoundary(): array
    {
        return [
            'one second before opening' => ['active' => false, 'ended' => false, 'status' => 'published'],
            'exactly at opening'        => ['active' => true,  'ended' => false, 'status' => 'active'],
            'during examination'        => ['active' => true,  'ended' => false, 'status' => 'active'],
            'one second before closing' => ['active' => true,  'ended' => false, 'status' => 'active'],
            'exactly at closing'        => ['active' => false, 'ended' => true,  'status' => 'ended'],
            'one second after closing'  => ['active' => false, 'ended' => true,  'status' => 'ended'],
            'several hours after closing' => ['active' => false, 'ended' => true,  'status' => 'ended'],
        ];
    }

    private function freeze(Carbon $moment): void
    {
        Carbon::setTestNow($moment->copy());
    }

    /** scopeActive/scopeEnded agree with the accessor at every boundary. */
    public function test_scope_and_accessor_agree_at_every_boundary(): void
    {
        $exam = $this->makeKampalaWindowExam();
        $expected = $this->expectedByBoundary();

        foreach ($this->boundaryMoments() as $label => $moment) {
            $this->freeze($moment);

            $active = (bool) OnlineExam::where('id', $exam->id)->active()->exists();
            $ended = (bool) OnlineExam::where('id', $exam->id)->ended()->exists();
            $status = $exam->lifecycle_status;
            $within = $exam->isWithinScheduledWindow();

            $exp = $expected[$label];

            $this->assertSame($exp['active'], $active, "$label: scopeActive");
            $this->assertSame($exp['ended'], $ended, "$label: scopeEnded");
            $this->assertSame($exp['status'], $status, "$label: lifecycle_status accessor");
            $this->assertSame($exp['active'], $within, "$label: isWithinScheduledWindow");

            // scopeActive and the accessor must never disagree about "active".
            $this->assertSame($active, $status === 'active', "$label: scopeActive vs accessor active");
            // scopeEnded and the accessor must never disagree about "ended".
            $this->assertSame($ended, $status === 'ended', "$label: scopeEnded vs accessor ended");
        }
    }

    /** scopeUpcoming is true only before opening. */
    public function test_scope_upcoming_before_opening_only(): void
    {
        $exam = $this->makeKampalaWindowExam();

        $this->freeze(Carbon::parse('2026-11-01 09:59:59', self::KAMPALA));
        $this->assertTrue(OnlineExam::where('id', $exam->id)->upcoming()->exists());

        $this->freeze(Carbon::parse('2026-11-01 10:00:00', self::KAMPALA));
        $this->assertFalse(OnlineExam::where('id', $exam->id)->upcoming()->exists());

        $this->freeze(Carbon::parse('2026-11-01 11:00:00', self::KAMPALA));
        $this->assertFalse(OnlineExam::where('id', $exam->id)->upcoming()->exists());
    }

    /** The application timezone is ignored by lifecycle queries; only Kampala/UTC matters. */
    public function test_lifecycle_is_immune_to_application_timezone(): void
    {
        $exam = $this->makeKampalaWindowExam();

        // Pretend the application server runs in US/Pacific (UTC-7).
        Config::set('app.timezone', 'America/Los_Angeles');

        // At 6:26 PM Kampala (the screenshot moment) the exam closed at 2:48 PM
        // Kampala, so it must be ended even though the app clock reads a very
        // different local wall time.
        $kampalaClose = Carbon::parse('2026-11-01 14:26:00', self::KAMPALA);
        $this->freeze($kampalaClose);

        $this->assertFalse(OnlineExam::where('id', $exam->id)->active()->exists(),
            'active must not leak through an unrelated application timezone');
        $this->assertTrue(OnlineExam::where('id', $exam->id)->ended()->exists());
        $this->assertSame('ended', $exam->lifecycle_status);

        Config::set('app.timezone', 'UTC');
    }

    /** Database timestamps are UTC; scheduledStartAt/scheduledEndAt render Kampala wall time. */
    public function test_database_timestamps_are_utc_rendered_as_kampala(): void
    {
        $exam = $this->makeKampalaWindowExam();

        $storedStart = $exam->getRawOriginal('start_datetime');
        $storedEnd = $exam->getRawOriginal('end_datetime');

        $this->assertSame('07:00:00', (string) Carbon::parse($storedStart)->format('H:i:s'));
        $this->assertSame('08:00:00', (string) Carbon::parse($storedEnd)->format('H:i:s'));

        $this->assertSame('10:00', $exam->scheduledStartAt()->format('H:i'));
        $this->assertSame('11:00', $exam->scheduledEndAt()->format('H:i'));
        $this->assertSame('1 Nov 2026, 10:00 AM to 1 Nov 2026, 11:00 AM', $exam->windowSummary());
    }

    /** Historical schedules are preserved: no stored value is rewritten. */
    public function test_historical_schedules_are_preserved(): void
    {
        $before = $this->makeKampalaWindowExam();
        $startBefore = $before->getRawOriginal('start_datetime');
        $endBefore = $before->getRawOriginal('end_datetime');

        // Exercise every lifecycle query without writing to the exam row.
        OnlineExam::active()->where('id', $before->id)->get();
        OnlineExam::ended()->where('id', $before->id)->get();
        OnlineExam::upcoming()->where('id', $before->id)->get();
        $before->lifecycle_status;
        $before->isWithinScheduledWindow();

        $after = OnlineExam::find($before->id);
        $this->assertSame($startBefore, $after->getRawOriginal('start_datetime'));
        $this->assertSame($endBefore, $after->getRawOriginal('end_datetime'));
    }

    /** scopeActive uses strict end boundary so an exam closing exactly now is not active. */
    public function test_scope_active_strict_end_boundary(): void
    {
        $exam = $this->makeKampalaWindowExam();

        $this->freeze(Carbon::parse('2026-11-01 11:00:00', self::KAMPALA));
        $this->assertFalse(OnlineExam::where('id', $exam->id)->active()->exists(),
            'at exactly closing time the exam is ended, not active');

        $this->freeze(Carbon::parse('2026-11-01 10:59:59', self::KAMPALA));
        $this->assertTrue(OnlineExam::where('id', $exam->id)->active()->exists(),
            'one second before closing the exam is still active');
    }
}
