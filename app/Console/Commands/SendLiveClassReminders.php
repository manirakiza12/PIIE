<?php

namespace App\Console\Commands;

use App\Models\LiveClass;
use App\Models\LiveClassNotification;
use App\Support\LiveClasses\LiveClassNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sends the 24-hour and 1-hour reminders for published, upcoming live
 * classes. Registered in App\Console\Kernel::schedule() to run every five
 * minutes — but the schedule only fires anything if something on the server
 * actually invokes `php artisan schedule:run` once a minute (a cron entry on
 * Linux, a Task Scheduler entry on Windows). This command can also be run
 * directly at any time for testing, independent of whether that's set up:
 *
 *   php artisan live-classes:send-reminders
 */
class SendLiveClassReminders extends Command
{
    protected $signature = 'live-classes:send-reminders';

    protected $description = 'Send 24-hour and 1-hour reminders for upcoming published live classes';

    /**
     * How wide a net each run casts around the exact 24h/1h mark. Must be at
     * least as large as the gap between scheduler runs, or a class whose
     * exact reminder moment falls between two runs would never be caught by
     * either. At the scheduled 5-minute cadence, 10 minutes gives margin
     * without meaningfully widening when a reminder actually goes out.
     */
    private const WINDOW_MINUTES = 10;

    public function handle(): int
    {
        $sentFor24h = $this->sendWindow(LiveClassNotification::TYPE_REMINDER_24H, 24 * 60);
        $sentFor1h = $this->sendWindow(LiveClassNotification::TYPE_REMINDER_1H, 60);

        $this->info("24h reminders sent for {$sentFor24h} class(es); 1h reminders sent for {$sentFor1h} class(es).");

        return self::SUCCESS;
    }

    private function sendWindow(string $type, int $minutesBefore): int
    {
        $target = now()->addMinutes($minutesBefore);
        $windowStart = $target->copy()->subMinutes(self::WINDOW_MINUTES);
        $windowEnd = $target->copy()->addMinutes(self::WINDOW_MINUTES);

        $classes = LiveClass::query()
            ->where('is_published', 1)
            ->where('status', LiveClass::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
            ->get();

        $classesNotified = 0;

        foreach ($classes as $liveClass) {
            try {
                $delivered = DB::transaction(function () use ($liveClass, $type, $windowStart, $windowEnd): bool {
                    // Recheck under the same class lock used by edits. A stale
                    // scheduler candidate must not notify after cancellation or rescheduling.
                    $current = LiveClass::whereKey($liveClass->id)->lockForUpdate()->firstOrFail();
                    if (!$current->is_published || $current->status !== LiveClass::STATUS_SCHEDULED
                        || !$current->scheduled_at?->betweenIncluded($windowStart, $windowEnd)) return false;
                    $key = LiveClassNotification::reminderKey($current, $type);
                    $recipients = \App\Support\LiveClasses\LiveClassEligibility::eligibleStudentUserIds($current);
                    if ($recipients->isEmpty()) return false;
                    $claimed = LiveClassNotification::query()->insertOrIgnore([
                        'school_id' => $current->school_id, 'live_class_id' => $current->id,
                        'type' => $key, 'recipient_count' => $recipients->count(), 'sent_at' => now(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if (!$claimed) return false;
                    LiveClassNotifier::sendReminder($current, $type);
                    return true;
                });
                if ($delivered) {
                    $classesNotified++;
                    $this->line("  #{$liveClass->id} — {$type}: reminder recorded.");
                }
            } catch (\Throwable $e) {
                // One broken class (bad data, mail failure, whatever) must
                // never stop the rest of the run from going out.
                report($e);
            }
        }

        return $classesNotified;
    }
}
