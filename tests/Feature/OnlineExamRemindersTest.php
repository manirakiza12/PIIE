<?php

namespace Tests\Feature;

use App\Mail\ApplicantNotificationEmail;
use App\Models\OnlineExamNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * Covers App\Console\Commands\SendOnlineExamStartReminders — the "exam is
 * starting soon" email, added because published exams previously had no
 * reminder at all (only OnlineExamResultNotifier for results existed).
 * Mirrors the equivalent Live Class reminder test coverage.
 */
class OnlineExamRemindersTest extends TestCase
{
    use OnlineExamTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSecondaryOnlineExamTestSchema();
    }

    public function test_reminder_command_sends_once_per_window_and_records_a_dedupe_row(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $student = $this->makeUser(7, 1, 'active');
        $student->email = 'reminded@example.test';
        $student->save();
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        // scheduled ~1 hour from now — inside the 1h reminder window.
        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'start_datetime' => now()->addMinutes(58),
            'end_datetime' => now()->addMinutes(118),
        ]);

        Artisan::call('online-exams:send-start-reminders');

        $this->assertDatabaseHas('online_exam_notifications', [
            'online_exam_id' => $examId,
            'type' => OnlineExamNotification::TYPE_REMINDER_1H,
        ]);
        Mail::assertSent(ApplicantNotificationEmail::class, function ($mail) use ($student) {
            return $mail->hasTo($student->email);
        });
    }

    public function test_reminder_command_does_not_resend_within_the_same_window_on_a_second_run(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $student = $this->makeUser(7, 1, 'active');
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'start_datetime' => now()->addMinutes(58),
            'end_datetime' => now()->addMinutes(118),
        ]);

        Artisan::call('online-exams:send-start-reminders');
        Artisan::call('online-exams:send-start-reminders');

        $this->assertSame(1, OnlineExamNotification::where('online_exam_id', $examId)->count());
        Mail::assertSent(ApplicantNotificationEmail::class, 1);
    }

    public function test_reminder_command_ignores_exams_outside_the_reminder_windows(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $student = $this->makeUser(7, 1, 'active');
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        // Starts in 5 days — nowhere near the 24h or 1h windows.
        $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'start_datetime' => now()->addDays(5),
            'end_datetime' => now()->addDays(5)->addHours(2),
        ]);

        Artisan::call('online-exams:send-start-reminders');

        $this->assertSame(0, OnlineExamNotification::count());
        Mail::assertNothingSent();
    }

    public function test_reminder_command_skips_unpublished_exams(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $student = $this->makeUser(7, 1, 'active');
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'start_datetime' => now()->addMinutes(58),
            'end_datetime' => now()->addMinutes(118),
            'workflow_state' => 'draft',
            'is_published' => 0,
        ]);

        Artisan::call('online-exams:send-start-reminders');

        $this->assertSame(0, OnlineExamNotification::count());
        Mail::assertNothingSent();
    }

    public function test_reminder_command_sends_a_15_minute_window_reminder(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $student = $this->makeUser(7, 1, 'active');
        $student->email = 'fifteen@example.test';
        $student->save();
        $classId = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $classId);

        // scheduled ~15 minutes from now — inside the 15m reminder window,
        // but well outside the 1h/24h windows so only reminder_15m fires.
        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'start_datetime' => now()->addMinutes(15),
            'end_datetime' => now()->addMinutes(75),
        ]);

        Artisan::call('online-exams:send-start-reminders');

        $this->assertDatabaseHas('online_exam_notifications', [
            'online_exam_id' => $examId,
            'type' => OnlineExamNotification::TYPE_REMINDER_15M,
        ]);
        Mail::assertSent(ApplicantNotificationEmail::class, function ($mail) use ($student) {
            return $mail->hasTo($student->email);
        });
    }

    /**
     * Every reminder window also posts an in-app Noticeboard entry
     * (App\Support\OnlineExams\OnlineExamAnnouncementNotifier::createNotice()),
     * unconditionally — unlike the email, which is gated on SMTP being
     * configured. This is the only reliable way a student sees the reminder
     * on an install without outbound mail set up (there is no bell/toast UI
     * in this app).
     */
    public function test_reminder_posts_an_in_app_noticeboard_entry_even_without_smtp_configured(): void
    {
        // Deliberately not calling enableSmtpSettings() — the notice must
        // still be created even when mail is not configured.
        $classId = $this->makeClass(1);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'title' => 'Unconfigured Mail Exam',
            'start_datetime' => now()->addMinutes(58),
            'end_datetime' => now()->addMinutes(118),
        ]);

        Artisan::call('online-exams:send-start-reminders');

        $this->assertDatabaseHas('online_exam_notifications', [
            'online_exam_id' => $examId,
            'type' => OnlineExamNotification::TYPE_REMINDER_1H,
        ]);
        $this->assertDatabaseHas('noticeboard', [
            'school_id' => 1,
        ]);
        $notice = \App\Models\Noticeboard::where('school_id', 1)->first();
        $this->assertStringContainsString('Unconfigured Mail Exam', $notice->notice_title);
    }

    public function test_only_students_in_the_target_class_are_reminded(): void
    {
        Mail::fake();
        $this->enableSmtpSettings();

        $targetClass = $this->makeClass(1);
        $otherClass = $this->makeClass(1);

        $inClassStudent = $this->makeUser(7, 1, 'active');
        $inClassStudent->email = 'inclass@example.test';
        $inClassStudent->save();
        $this->enrollStudent($inClassStudent->id, 1, $targetClass);

        $otherClassStudent = $this->makeUser(7, 1, 'active');
        $otherClassStudent->email = 'otherclass@example.test';
        $otherClassStudent->save();
        $this->enrollStudent($otherClassStudent->id, 1, $otherClass);

        $this->makeExam([
            'school_id' => 1,
            'class_id' => $targetClass,
            'start_datetime' => now()->addMinutes(58),
            'end_datetime' => now()->addMinutes(118),
        ]);

        Artisan::call('online-exams:send-start-reminders');

        Mail::assertSent(ApplicantNotificationEmail::class, function ($mail) use ($inClassStudent) {
            return $mail->hasTo($inClassStudent->email);
        });
        Mail::assertNotSent(ApplicantNotificationEmail::class, function ($mail) use ($otherClassStudent) {
            return $mail->hasTo($otherClassStudent->email);
        });
    }
}
