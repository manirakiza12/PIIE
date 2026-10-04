<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentNotification;
use App\Models\AssignmentSubmission;
use App\Models\CourseRegistration;
use App\Models\School;
use App\Models\User;
use App\Support\Notifications\NotificationService;
use App\Support\TenantTimezone;
use Illuminate\Support\Collection;

/**
 * Assignment notifications, on PIIE's existing in-app channel.
 *
 * WHAT THIS DELIVERS, AND WHAT IT DELIBERATELY DOES NOT
 *
 * Only the in-app channel is used - the existing bell/inbox that
 * NotificationService already writes to, rendered by the existing screens. No
 * email is sent, and none is claimed: PIIE's SMTP is not configured in this
 * environment, so announcing "we emailed your students" would be a claim about a
 * capability that does not exist. When SMTP is genuinely configured, a mail
 * channel can be added to deliver() without any caller, event or message
 * changing - the same extension point LiveClassNotifier has.
 *
 * EVERY EVENT POINTS AT ONE URL
 *
 * All events resolve to the student's assignment DETAIL page, never to a
 * lifecycle-specific action. That page re-authorises on confirmed registration
 * and renders whatever state the assignment is truly in, so an old or forwarded
 * notification cannot become a 404 because the assignment has since been closed,
 * scheduled or withdrawn. A notification is a mailbox and a browser history
 * entry; it must never carry a link that can rot.
 *
 * DEDUPLICATION IS DONE BY THE DATABASE
 *
 * The dedup key is claimed with insertOrIgnore BEFORE anything is sent, so the
 * unique index - not a read-then-write check - decides whether an event has
 * already gone out. Two lecturers publishing the same assignment, or a retried
 * request, cannot both notify.
 *
 * TIMES ARE RENDERED IN THE RECIPIENT'S OWN CLOCK
 *
 * Everyone is told about ONE stored instant, rendered in each recipient's
 * effective timezone. The instant is never re-stored, so a student in New York
 * and a student in Kampala are told about the same moment and each reads it in
 * their own time.
 */
class AssignmentNotifier
{
    /**
     * The assignment became visible to students.
     *
     * @return int students told
     */
    public static function announcePublished(Assignment $assignment): int
    {
        $recipients = self::eligibleStudentIds($assignment);

        if ($recipients->isEmpty()) {
            return 0;
        }

        return self::announce(
            $assignment,
            AssignmentNotification::assignmentKey((int) $assignment->id, AssignmentNotification::TYPE_PUBLISHED),
            AssignmentNotification::TYPE_PUBLISHED,
            $recipients,
            fn (array $context): array => [
                'title' => 'New assignment — '.$context['unit'],
                'body' => $context['title'].' is set for '.$context['due'].'.',
            ]
        );
    }

    /**
     * A recorded mark and its feedback have been released to one student.
     *
     * Keyed on the ATTEMPT, not the student, so a second attempt that is graded
     * again is a genuinely new event rather than being suppressed because
     * "graded" already went out for that student.
     *
     * @return int students told (0 or 1)
     */
    public static function announceGraded(AssignmentSubmission $submission): int
    {
        $assignment = $submission->assignment ?? Assignment::query()->find($submission->assignment_id);

        if (! $assignment) {
            return 0;
        }

        $student = $submission->student_id;
        if (! $student) {
            return 0;
        }

        return self::announce(
            $assignment,
            AssignmentNotification::submissionKey(
                (int) $assignment->id,
                (int) $submission->id,
                AssignmentNotification::TYPE_GRADED
            ),
            AssignmentNotification::TYPE_GRADED,
            collect([(int) $student]),
            fn (array $context): array => [
                'title' => 'Assignment marked — '.$context['unit'],
                'body' => $context['title'].' has been marked and returned. Your mark and feedback are available.',
            ],
            $submission
        );
    }

    /**
     * CONFIRMED students on this exact Offering, and nobody else.
     *
     * Never Programme, Cohort, Study Plan or legacy Class membership. A student
     * awaiting confirmation is not told about work they cannot open.
     *
     * @return Collection<int, int>
     */
    public static function eligibleStudentIds(Assignment $assignment): Collection
    {
        if ($assignment->course_offering_id === null) {
            return collect();
        }

        return CourseRegistration::query()
            ->where('school_id', (int) $assignment->school_id)
            ->where('course_offering_id', (int) $assignment->course_offering_id)
            ->where('status', CourseRegistration::STATUS_CONFIRMED)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, int>  $recipients
     * @param  callable(array<string, string>): array{title: string, body: string}  $message
     */
    private static function announce(
        Assignment $assignment,
        string $dedupKey,
        string $type,
        Collection $recipients,
        callable $message,
        ?AssignmentSubmission $submission = null
    ): int {
        // Claimed FIRST. If the insert is ignored the event has already been
        // announced and nothing is sent - the database decides, not a check.
        $claimed = AssignmentNotification::query()->insertOrIgnore([
            'school_id' => $assignment->school_id,
            'assignment_id' => $assignment->id,
            'submission_id' => $submission?->id,
            'dedup_key' => $dedupKey,
            'type' => $type,
            'recipient_count' => $recipients->count(),
            'sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            return 0;
        }

        $tz = app(TenantTimezone::class);
        $school = School::query()->find($assignment->school_id);

        // One stored instant, each recipient rendered in their own effective
        // zone. Grouping avoids re-deriving the same wall clock per recipient.
        $grouped = $tz->groupByEffectiveTimezone($recipients->all(), (int) $assignment->school_id);

        if ($grouped === []) {
            $grouped = [$tz->resolve($school) => $recipients->all()];
        }

        foreach ($grouped as $zone => $ids) {
            $copy = $message(self::context($assignment, is_string($zone) ? $zone : null));

            NotificationService::notifyMany(
                $ids,
                (int) $assignment->school_id,
                $copy['title'],
                $copy['body'],
                self::actionUrl($assignment),
                'assignment_'.$type
            );
        }

        return $recipients->count();
    }

    /**
     * The human facts an assignment notification may contain.
     *
     * No mark, no file, no stored path. A notification renders in a list and ends
     * up in a browser history, so it must not carry a learner's work or a
     * resource path; the student is meant to open the assignment through PIIE,
     * where authority is re-checked.
     *
     * @return array<string, string>
     */
    private static function context(Assignment $assignment, ?string $inZone = null): array
    {
        $tz = app(TenantTimezone::class);
        $school = $assignment->school_id ? School::query()->find($assignment->school_id) : null;

        $unit = $assignment->courseOffering?->subject?->name
            ?: $assignment->courseOffering?->reference
            ?: 'your course unit';

        $local = $inZone !== null
            ? $tz->inZone($assignment->due_date, $inZone)
            : $tz->inTenantTime($assignment->due_date, $school);

        return [
            'unit' => (string) $unit,
            'title' => (string) $assignment->title,
            'due' => $local
                ? $local->format('j M Y').' at '.$local->format('H:i')
                : 'a date to be confirmed',
        ];
    }

    /**
     * ONE destination for every event, for the whole life of the assignment.
     *
     * The student's detail page authorises on confirmed registration and renders
     * the assignment's real state, whether it is open, scheduled, submitted or
     * closed. A closed assignment with the student's own feedback on it is
     * exactly what they most need to read, so closing never invalidates a link
     * that was already sent.
     */
    private static function actionUrl(Assignment $assignment): string
    {
        return route('student.courses.assignments.show', [
            'id' => (int) $assignment->course_offering_id,
            'assignment' => (int) $assignment->id,
        ]);
    }

    /**
     * Is email genuinely configured?
     *
     * Exposed so the UI can state the truth about channels rather than implying
     * an email went out. Nothing calls it to send today: assignment email is not
     * wired up, and this exists so that when it is, the condition is explicit.
     */
    public static function isMailConfigured(): bool
    {
        return ! empty(get_settings('smtp_user'))
            && ! empty(get_settings('smtp_pass'))
            && ! empty(get_settings('smtp_host'))
            && ! empty(get_settings('smtp_port'));
    }
}
