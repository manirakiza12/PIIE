<?php

namespace Tests\Feature;

use App\Models\Leavelist;
use Tests\Feature\Support\LeaveTestHelper;
use Tests\TestCase;

class LeaveDateCompatibilityTest extends TestCase
{
    use LeaveTestHelper;

    protected function setUp(): void { parent::setUp(); $this->bootLeaveTestSchema(); }

    public static function dates(): array
    {
        return ['same day' => ['2026-10-08', '2026-10-08', 1], 'consecutive' => ['2026-10-08', '2026-10-09', 2],
            'month boundary' => ['2026-10-31', '2026-11-01', 2], 'leap boundary' => ['2024-02-28', '2024-03-01', 3],
            'fractional day' => ['2026-10-08 12:00:00', '2026-10-09 11:59:59', 1]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dates')]
    public function test_leave_days_remain_inclusive_whole_calendar_days(string $from, string $to, int $expected): void
    {
        $user = $this->makeUser(3, 1);
        $this->actingAs($user)->post(route('staff.leave.store'), ['from_date' => $from, 'to_date' => $to, 'reason' => 'Fixture'])->assertRedirect();
        $leave = Leavelist::firstOrFail();
        $this->assertSame($expected, (int) $leave->days);
        $this->assertSame($user->id, $leave->user_id);
    }

    public function test_reversed_dates_are_rejected_without_creating_leave(): void
    {
        $this->actingAs($this->makeUser(3, 1))->post(route('staff.leave.store'), ['from_date' => '2026-10-09', 'to_date' => '2026-10-08', 'reason' => 'Fixture'])->assertSessionHasErrors('to_date');
        $this->assertSame(0, Leavelist::count());
    }
}
