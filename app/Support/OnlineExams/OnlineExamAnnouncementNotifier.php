<?php

namespace App\Support\OnlineExams;

use App\Mail\ApplicantNotificationEmail;
use App\Models\Noticeboard;
use App\Models\OnlineExam;
use App\Models\Session;
use App\Models\User;
use App\Support\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the students an exam is actually meant for, the moment it becomes
 * visible to them — same "intended audience" rule App\Models\OnlineExam::
 * scopeVisibleToStudent() already uses: a class-scoped exam only reaches
 * that class's enrolled students, a school-wide exam (class_id null) reaches
 * every student in the school. Called once, from
 * OnlineExamController::publish() on the draft-to-published transition —
 * a draft is never visible to students in the first place, so publishing is
 * the only moment "new exam available" is true.
 *
 * Same rules as App\Support\OnlineExams\OnlineExamResultNotifier: gated on
 * SMTP actually being configured, and a failed send never blocks publishing
 * — the exam is published whether or not the announcement email got out.
 */
class OnlineExamAnnouncementNotifier
{
    public static function isConfigured(): bool
    {
        return !empty(get_settings('smtp_user'))
            && !empty(get_settings('smtp_pass'))
            && !empty(get_settings('smtp_host'))
            && !empty(get_settings('smtp_port'));
    }

    public static function examPublished(OnlineExam $exam): int
    {
        $students = self::eligibleStudents($exam);

        NotificationService::notifyMany(
            $students->pluck('id'),
            $exam->school_id,
            get_phrase('New Exam Available') . ': ' . $exam->title,
            get_phrase('A new exam has been scheduled for you. Please review the details below.'),
            route('student.online_exam.list'),
            'online_exam_published'
        );

        if (!self::isConfigured()) {
            return 0;
        }

        $sent = 0;
        foreach ($students as $student) {
            if (self::send($student, $exam, [
                'subject'  => get_phrase('New Exam Available') . ': ' . $exam->title,
                'heading'  => get_phrase('New Exam Available'),
                'paragraphs' => [
                    get_phrase('A new exam has been scheduled for you. Please review the details below.'),
                ],
            ])) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * The 24h/1h/15m "exam starting soon" reminder — same recipients as
     * examPublished(), sent by App\Console\Commands\SendOnlineExamStartReminders.
     * $windowLabel is a phrase like "starts in 24 hours" / "starts in 15 minutes",
     * dropped straight into the email body and the in-app notice.
     *
     * Always posts an in-app Noticeboard entry first (mirrors
     * LiveClassNotifier::createNotice()) — email is gated on SMTP actually
     * being configured and there is no bell/toast UI in this app, so the
     * Noticeboard is the only reliable way a student sees this reminder on
     * an install without outbound mail set up.
     */
    public static function startingSoon(OnlineExam $exam, string $windowLabel): int
    {
        self::createNotice($exam, $windowLabel);

        $students = self::eligibleStudents($exam);

        NotificationService::notifyMany(
            $students->pluck('id'),
            $exam->school_id,
            get_phrase('Exam Reminder') . ': ' . $exam->title,
            $exam->title . ' ' . $windowLabel . '. ' . get_phrase('Make sure you are ready before it begins.'),
            route('student.online_exam.list'),
            'online_exam_reminder'
        );

        if (!self::isConfigured()) {
            return 0;
        }

        $sent = 0;
        foreach ($students as $student) {
            if (self::send($student, $exam, [
                'subject'  => get_phrase('Exam Reminder') . ': ' . $exam->title,
                'heading'  => get_phrase('Your Exam Is Starting Soon'),
                'paragraphs' => [
                    $exam->title . ' ' . $windowLabel . '. ' . get_phrase('Make sure you are ready before it begins.'),
                ],
            ])) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Same school-wide Noticeboard entry style Live Classes already use for
     * their reminders (LiveClassNotifier::createNotice()) — a class-scoped
     * exam still posts a school-wide notice (identical simplification to the
     * Live Class precedent), just with the target class named in the body.
     */
    private static function createNotice(OnlineExam $exam, string $windowLabel): void
    {
        $subjectName = optional($exam->subject)->name ?: get_phrase('All subjects');
        $classInfo = $exam->class_id
            ? (get_phrase('Class') . ': ' . (optional($exam->classRoom)->name ?: ('ID ' . $exam->class_id)))
            : get_phrase('Class') . ': ' . get_phrase('All classes');

        $noticeTitle = get_phrase('Exam Reminder') . ': ' . $exam->title . ' ' . $windowLabel;
        $noticeBody = get_phrase('This exam') . " {$windowLabel}.\n"
            . get_phrase('Subject') . ": {$subjectName}\n"
            . "{$classInfo}\n"
            . get_phrase('Opens') . ': ' . (optional($exam->start_datetime)->format('Y-m-d H:i') ?: 'TBD') . "\n"
            . get_phrase('Duration') . ': ' . $exam->duration_mins . ' ' . get_phrase('minutes');

        $sessionId = (int) get_school_settings($exam->school_id)->value('running_session');
        if ($sessionId === 0) {
            $sessionId = (int) Session::where('school_id', $exam->school_id)->max('id');
        }

        $startDate = optional($exam->start_datetime)->format('Y-m-d') ?: now()->format('Y-m-d');

        Noticeboard::create([
            'notice_title' => $noticeTitle,
            'notice' => $noticeBody,
            'start_date' => $startDate,
            'start_time' => optional($exam->start_datetime)->format('H:i') ?: '',
            'end_date' => $startDate,
            'end_time' => optional($exam->end_datetime)->format('H:i') ?: '',
            'status' => 1,
            'show_on_website' => 0,
            'image' => '',
            'school_id' => $exam->school_id,
            'session_id' => $sessionId > 0 ? $sessionId : 0,
        ]);
    }

    /**
     * Same audience rule App\Models\OnlineExam::scopeVisibleToStudent() uses:
     * a class-scoped exam only reaches that class's enrolled students, a
     * school-wide exam (class_id null) reaches every student in the school.
     */
    /**
     * The students an announcement may reach.
     *
     * Delegates to `OnlineExamRecipients`, which is the same resolution the exam's
     * visibility gate and the "exam published" notification both use.
     *
     * This method previously repeated the class-only narrowing:
     *
     *     if ($exam->class_id) { ... enrollment ... }
     *
     * so a Course Offering exam - `class_id` NULL by design - reached EVERY student
     * in the school with a "your exam starts soon" reminder. A reminder is not
     * harmless where a notification is: it asserts that the recipient has an exam
     * coming, and a student who is not on the course would be told one was.
     *
     * The legacy arm is unchanged: a NULL `class_id` exam is still school-wide,
     * because nine live exams depend on that.
     */
    private static function eligibleStudents(OnlineExam $exam)
    {
        return User::whereIn('id', OnlineExamRecipients::forExam($exam))->get();
    }

    private static function send(User $student, OnlineExam $exam, array $content): bool
    {
        if (blank($student->email)) {
            return false;
        }

        $details = array_filter([
            get_phrase('Exam')     => $exam->title,
            get_phrase('Duration') => $exam->duration_mins . ' ' . get_phrase('minutes'),
            get_phrase('Opens')    => $exam->start_datetime?->format('d M Y H:i'),
            get_phrase('Closes')   => $exam->end_datetime?->format('d M Y H:i'),
        ]);

        try {
            Mail::to($student->email)->send(new ApplicantNotificationEmail(array_merge([
                'greeting' => get_phrase('Dear') . ' ' . $student->name . ',',
                'details' => $details,
                'cta_label'   => get_phrase('View Exam'),
                'cta_url'     => route('student.online_exam.list'),
                'footer_note' => get_phrase('If you have questions about this exam, please contact your school administration.'),
                'school_id'   => $exam->school_id,
            ], $content)));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
