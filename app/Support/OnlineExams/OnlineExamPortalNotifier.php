<?php

namespace App\Support\OnlineExams;

use App\Models\OnlineExam;
use App\Models\OnlineExamSubmission;
use App\Models\OnlineExamUserNotification;
use App\Models\User;

final class OnlineExamPortalNotifier
{
    public static function admins(string $type, string $title, string $message, OnlineExam $exam, ?int $actorId = null, ?string $eventKey = null, ?int $submissionId = null): void
    {
        $users = User::where('school_id', $exam->school_id)->where('role_id', 2)->pluck('id');

        // Stored as a LOCATION inside this application, never as an absolute URL.
        // `route()` still decides which page - the path cannot drift from the
        // routing table - but its origin is dropped, because the origin of a
        // notification is the host the reader is on, not the host `APP_URL` said
        // when the row was written. See OnlineExamNotificationLink: an admin
        // clicking this used to be handed http://localhost/... while the app was
        // served from another port entirely, and landed on Apache's 404.
        $url = $submissionId
            ? OnlineExamNotificationLink::route(
                'admin.online_exams.results',
                $exam->id,
                'submission='.(int) $submissionId,
                'submission-'.(int) $submissionId
            )
            : OnlineExamNotificationLink::route('admin.online_exams.show', $exam->id);

        foreach ($users as $userId) self::create($exam->school_id, (int) $userId, $type, $title, $message, $url, $actorId, $exam->id, $submissionId, $eventKey ? $eventKey . ':user:' . $userId : null);
    }

    /**
     * Notify the lecturer(s) entitled to act on this exam.
     *
     * ── WHY THIS IS NO LONGER "WHOEVER CREATED IT" ──────────────────────────
     *
     * It used to resolve exactly one id: `creator_id ?: created_by`. For a Course
     * Offering exam that is the author, and it is the wrong question to ask. The
     * brief is explicit that a student's submission must reach "the lecturer
     * allocated to that Course Offering", and the authority for that is the
     * allocation, not authorship:
     *
     *     exam.course_offering_id -> course_offering_lecturer_allocations
     *
     * Three consequences, all of them the point:
     *
     *  - a CO-LECTURER allocated to the course is notified, not left out because
     *    somebody else wrote the paper;
     *  - `teacher_permissions` is never consulted, so the legacy class graph cannot
     *    decide who marks a higher-education paper;
     *  - a CANCELLED allocation, or one outside its own dates, is not notified -
     *    `activeManagerUserIds()` is the same rule Live Classes and the Course
     *    Offering workspace already apply, asked rather than restated.
     *
     * A LEGACY exam (`course_offering_id` NULL) keeps the original rule exactly:
     * the creator, and only the creator. Nine live exams depend on it, and silently
     * widening their audience would be a behaviour change nobody asked for.
     *
     * @param  int|null  $submissionId  the attempt this concerns, when it concerns one.
     *                                   Passing it makes the link open THAT attempt's
     *                                   row rather than the exam's front page, and
     *                                   records it so the notification can be
     *                                   correlated with its submission later.
     *
     * @return list<int> the notified user ids
     */
    public static function teacher(string $type, string $title, string $message, OnlineExam $exam, ?int $actorId = null, ?string $eventKey = null, ?int $submissionId = null): array
    {
        $teacherIds = self::lecturerRecipients($exam);

        foreach ($teacherIds as $teacherId) {
            self::create(
                $exam->school_id,
                $teacherId,
                $type,
                $title,
                $message,
                $submissionId
                    ? self::linkForSubmission($exam, $submissionId)
                    : self::linkForLecturer($exam),
                $actorId,
                $exam->id,
                $submissionId,
                $eventKey ? $eventKey.':user:'.$teacherId : null
            );
        }

        return $teacherIds;
    }

    /**
     * Notify ONE lecturer, for a message about a message about one attempt.
     *
     * Used where the recipient is already known rather than resolved.
     */
    public static function lecturer(int $userId, string $type, string $title, string $message, OnlineExam $exam, ?int $actorId = null, ?string $eventKey = null, ?int $submissionId = null): void
    {
        self::create(
            $exam->school_id,
            $userId,
            $type,
            $title,
            $message,
            $submissionId
                ? self::linkForSubmission($exam, $submissionId)
                : self::linkForLecturer($exam),
            $actorId,
            $exam->id,
            $submissionId,
            $eventKey ? $eventKey.':user:'.$userId : null
        );
    }

    /**
     * The lecturer's own view of ONE attempt, scrolled to that attempt's row.
     *
     * A lecturer triaging notifications needs the attempt, not the paper's front
     * page: `teacher.online_exams.results` lists this exam's submissions, and the
     * query and fragment select the right one, exactly as the admin-side link does.
     */
    private static function linkForSubmission(OnlineExam $exam, int $submissionId): string
    {
        return OnlineExamNotificationLink::route(
            'teacher.online_exams.results',
            $exam->id,
            'submission='.(int) $submissionId,
            'submission-'.(int) $submissionId
        );
    }

    /**
     * Who may act as a marker on this exam.
     *
     * @return list<int>
     */
    public static function lecturerRecipients(OnlineExam $exam): array
    {
        if ($exam->course_offering_id !== null) {
            return app(\App\Support\CourseExams\CourseOfferingExamAccess::class)
                ->activeMarkerUserIds($exam);
        }

        $creatorId = (int) ($exam->creator_id ?: $exam->created_by);

        return $creatorId ? [$creatorId] : [];
    }

    /**
     * The page a lecturer should land on for this exam.
     *
     * A Course Offering paper points at the lecturer's own exam view, which now
     * carries the offering's derived context. A legacy paper keeps its own view.
     * Both are stored as locations, for the reason above.
     */
    private static function linkForLecturer(OnlineExam $exam): string
    {
        return OnlineExamNotificationLink::route('teacher.online_exams.show', $exam->id);
    }

    /**
     * Tell the students who may ACTUALLY SIT this exam.
     *
     * ── WHY THIS IS NOT A FILTER AND A SEPARATE RULE ─────────────────────────
     *
     * This used to narrow by `class_id` alone:
     *
     *     if ($exam->class_id) $query->whereExists(... enrollment ...);
     *
     * A Course Offering exam has `class_id = NULL` by design, so the narrowing never
     * ran and the query became "every student in the school". Simulated on the real
     * rows, publishing an Offering-5 exam would have notified 4 students when only 3
     * are confirmed on it.
     *
     * That is the same class-only blind spot that made exam VISIBILITY school-wide,
     * and it had already been fixed there. The two had never been joined, so a page
     * that correctly showed a student nothing would still have emailed them about
     * it - which is worse, because the student is left believing they have work to do.
     *
     * Recipients now come from `OnlineExamRecipients`, the same resolution the
     * exam's own visibility gate uses. A notification is eligibility acted on
     * earlier, not a softer version of it.
     */
    public static function eligibleStudents(OnlineExam $exam, string $type, string $title, string $message, ?int $actorId = null, ?string $eventKey = null, ?OnlineExamSubmission $submission = null): void
    {
        foreach (OnlineExamRecipients::forExam($exam) as $userId) {
            self::create(
                $exam->school_id,
                $userId,
                $type,
                $title,
                $message,
                $submission
                    ? OnlineExamNotificationLink::route('student.online_exam.result', $submission->id)
                    : OnlineExamNotificationLink::route('student.online_exam.list'),
                $actorId,
                $exam->id,
                $submission?->id,
                $eventKey ? $eventKey . ':user:' . $userId : null
            );
        }
    }

    public static function create(int $schoolId, int $userId, string $type, string $title, string $message, ?string $url, ?int $actorId = null, ?int $examId = null, ?int $submissionId = null, ?string $eventKey = null): void
    {
        $payload = [
                'school_id' => $schoolId, 'user_id' => $userId, 'actor_id' => $actorId,
                'online_exam_id' => $examId, 'submission_id' => $submissionId,
                'type' => $type, 'title' => $title, 'message' => $message,
                'action_url' => $url, 'event_key' => $eventKey,
                'created_at' => now(), 'updated_at' => now(),
            ];
        // insertOrIgnore makes repeated lifecycle requests idempotent and
        // remains safe under concurrent delivery attempts.
        \Illuminate\Support\Facades\DB::table('online_exam_user_notifications')->insertOrIgnore($payload);
    }
}
