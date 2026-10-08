<?php

namespace Tests\Feature;

use App\Http\Controllers\LiveClassController;
use App\Models\LiveClass;
use App\Models\LiveClassAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\LiveClassTestHelper;
use Tests\TestCase;

class LiveClassTimeCompatibilityTest extends TestCase
{
    use LiveClassTestHelper;

    protected function setUp(): void { parent::setUp(); $this->bootLiveClassTestSchema(); }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public static function durations(): array
    {
        return ['exact' => ['2026-10-08 10:00:00+00:00', '2026-10-08 11:00:00+00:00', 60],
            'fractional' => ['2026-10-08 10:00:00+00:00', '2026-10-08 11:00:59+00:00', 60],
            'subminute' => ['2026-10-08 10:00:00+00:00', '2026-10-08 10:00:59+00:00', 0],
            'reversed legacy absolute' => ['2026-10-08 11:00:00+00:00', '2026-10-08 10:00:00+00:00', 60],
            'same instant zones' => ['2026-10-08 10:00:00+00:00', '2026-10-08 13:00:00+03:00', 0],
            'cross zone duration' => ['2026-10-08 10:00:00+00:00', '2026-10-08 14:00:00+03:00', 60]];
    }

    /** @dataProvider durations */
    public function test_model_duration_remains_absolute_whole_minutes(string $start, string $end, int $minutes): void
    {
        $class = new LiveClass(['scheduled_at' => Carbon::parse($start)->utc(), 'ends_at' => Carbon::parse($end)->utc()]);
        $this->assertSame($minutes, $class->duration_minutes);
    }

    public function test_missing_boundaries_return_null(): void
    {
        $this->assertNull((new LiveClass())->duration_minutes);
        $this->assertNull((new LiveClass(['scheduled_at' => now()]))->duration_minutes);
        $this->assertNull((new LiveClass(['ends_at' => now()]))->duration_minutes);
    }

    /** @dataProvider durations */
    public function test_zoom_payload_retains_integer_minutes_minimum_one_and_utc_start(string $start, string $end, int $minutes): void
    {
        config(['services.zoom.account_id' => 'fixture-account', 'services.zoom.client_id' => 'fixture-client', 'services.zoom.client_secret' => 'fixture-secret']);
        Http::preventStrayRequests();
        Http::fake(['zoom.us/oauth/token' => Http::response(['access_token' => 'fixture-token']), 'api.zoom.us/*' => Http::response(['join_url' => 'https://zoom.us/j/fixture'])]);
        $method = new \ReflectionMethod(LiveClassController::class, 'createZoomMeetingUrl');
        $method->setAccessible(true);
        $a = Carbon::parse($start);
        $method->invoke(app(LiveClassController::class), 'Fixture', $a, Carbon::parse($end), 'UTC');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/meetings')
            && $request['duration'] === max(1, $minutes)
            && $request['start_time'] === $a->copy()->utc()->toIso8601String());
    }

    public static function attendanceDurations(): array
    {
        return ['exact' => [600, 600], 'fractional minute' => [659, 659], 'reversed legacy absolute' => [-600, 600]];
    }

    /** @dataProvider attendanceDurations */
    public function test_attendance_beacon_preserves_whole_absolute_seconds(int $elapsed, int $expected): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
        $school = $this->makeSchool(); $student = $this->makeStudentUser($school);
        $class = $this->makeLiveClass($school, ['scheduled_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $row = LiveClassAttendance::create(['school_id' => $school, 'live_class_id' => $class->id, 'user_id' => $student->id, 'role_id' => 7, 'joined_at' => now()->subSeconds($elapsed)]);
        $this->actingAs($student)->post(route('student.live_classes.attendance_leave', $class->id), ['attendance_id' => $row->id])->assertNoContent();
        $this->assertSame($expected, $row->fresh()->duration_seconds);
        $this->assertNotNull($row->fresh()->left_at);
    }
}
