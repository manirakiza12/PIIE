<?php

namespace Tests\Feature;

use App\Models\OnlineExamSubmission;
use Carbon\Carbon;
use Tests\TestCase;

class ExamDeadlineCompatibilityTest extends TestCase
{
    public static function deadlines(): array
    {
        return ['before' => ['11:59:58', 2, false], 'exact' => ['12:00:00', 0, true], 'after' => ['12:00:01', 0, true],
            'fraction before' => ['11:59:59.900000', 0, false], 'fraction after' => ['12:00:00.100000', 0, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deadlines')]
    public function test_signed_deadline_and_timeout_boundaries_are_preserved(string $at, int $remaining, bool $expired): void
    {
        $submission = new OnlineExamSubmission(['expires_at' => '2026-10-08 12:00:00']);
        $submission->setRelation('exam', null);
        $now = Carbon::parse('2026-10-08 '.$at, 'UTC');
        $this->assertSame($remaining, $submission->remainingSeconds($now));
        $this->assertSame($expired, $submission->isExpired($now));
    }

    public function test_carbon_3_fractional_result_is_explicitly_truncated_without_precision_deprecation(): void
    {
        require_once __DIR__.'/CarbonIntervalCompatibilityTest.php';
        $submission = new class extends OnlineExamSubmission {
            public function effectiveExpiresAt(): ?Carbon { return Carbon3DifferenceFixture::parse('2026-10-08 12:00:00', 'UTC'); }
        };
        set_error_handler(static function (int $severity, string $message): bool {
            if ($severity === E_DEPRECATED) { throw new \ErrorException($message, 0, $severity); }
            return false;
        });
        try { $this->assertSame(1, $submission->remainingSeconds(Carbon::parse('2026-10-08 11:59:58.500000', 'UTC'))); }
        finally { restore_error_handler(); }
    }
}
