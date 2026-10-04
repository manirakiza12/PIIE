<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * The assignment lifecycle, in one place.
 *
 *   draft      -> lecturer/admin only. A student cannot see, discover or
 *                 reach it by id.
 *   scheduled  -> approved, but not open until `released_at`. Invisible to
 *                 students before that instant.
 *   published  -> open to confirmed students on this exact Course Offering.
 *   closed     -> no NEW submissions accepted. Existing submissions and every
 *                 mark already recorded are preserved.
 *
 * Why a state machine rather than a status column read ad hoc: "may this student
 * see it", "may they submit" and "what does the lecturer list call it" must never
 * be three separate answers. Each is answered here from the same status plus the
 * same instants, so the list, the detail page and the submit action cannot drift
 * apart.
 *
 * NOTHING IS HARD-DELETED ONCE STUDENT ACTIVITY EXISTS
 *
 * Closing preserves submissions. Unpublishing an assignment that already has
 * submissions is refused outright rather than being allowed to hide work that
 * students have done and lecturers may still need to grade.
 */
class AssignmentLifecycle
{
    /** The states a lecturer may move an assignment to from its current state. */
    public const TRANSITIONS = [
        Assignment::STATUS_DRAFT => [Assignment::STATUS_SCHEDULED, Assignment::STATUS_PUBLISHED],
        // Scheduled may still be pulled back to draft before it opens, which is
        // how an over-eager publish is undone.
        Assignment::STATUS_SCHEDULED => [
            Assignment::STATUS_DRAFT,
            Assignment::STATUS_PUBLISHED,
            Assignment::STATUS_CLOSED,
        ],
        Assignment::STATUS_PUBLISHED => [Assignment::STATUS_CLOSED, Assignment::STATUS_DRAFT],
        // Closed is terminal on purpose. Reopening would silently change what
        // "closed" meant to a student who was told it was closed; a lecturer who
        // needs to reopen should publish a new assignment.
        Assignment::STATUS_CLOSED => [],
    ];

    public static function canTransition(Assignment $assignment, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$assignment->status] ?? [], true);
    }

    /**
     * @throws DomainException when the move is not permitted
     */
    public static function assertTransition(Assignment $assignment, string $to): void
    {
        if (! in_array($to, Assignment::STATUSES, true)) {
            throw new DomainException('That assignment status is not recognised.');
        }

        if (! self::canTransition($assignment, $to)) {
            throw new DomainException(
                'An assignment that is '.self::labelFor($assignment).' cannot be changed to '.ucfirst($to).'.'
            );
        }
    }

    /**
     * Validate the fields a target state requires.
     *
     * `scheduled` without a release time would be a state that means "will open
     * when it opens", which is not a promise anyone can act on. It is refused at
     * save time rather than silently opening immediately.
     */
    public static function assertStateRequirements(Assignment $assignment, string $status): void
    {
        if ($status === Assignment::STATUS_SCHEDULED && ! $assignment->released_at) {
            throw new DomainException('Choose a release date and time for a scheduled assignment, or publish it now.');
        }

        if ($assignment->due_date && $assignment->released_at
            && $assignment->released_at->greaterThan($assignment->due_date)) {
            throw new DomainException('The release time must be on or before the due date.');
        }

        if ($assignment->closes_at && $assignment->due_date
            && $assignment->closes_at->lessThan($assignment->due_date)) {
            throw new DomainException('The final closing time must be on or after the due date.');
        }
    }

    /**
     * Is this assignment open to a student RIGHT NOW?
     *
     * The single student-visibility rule. Authorisation (a confirmed registration
     * on the exact Offering) is a separate question answered by
     * AssignmentAccess; this only answers "has the lecturer released it, and is
     * it withdrawn".
     *
     * `closed` is deliberately still visible. A closed assignment with the
     * student's submission and feedback on it is exactly what they most need to
     * read, and hiding it would be the same mistake as hiding a cancelled Live
     * Class from the people who were told about it.
     */
    public static function isOpenToStudents(Assignment $assignment, ?Carbon $at = null): bool
    {
        $at ??= now();

        return match ($assignment->status) {
            Assignment::STATUS_PUBLISHED => true,
            Assignment::STATUS_SCHEDULED => $assignment->released_at !== null
                && $assignment->released_at->lessThanOrEqualTo($at),
            Assignment::STATUS_CLOSED => true,
            default => false,
        };
    }

    public static function hasReleased(Assignment $assignment, ?Carbon $at = null): bool
    {
        $at ??= now();

        return $assignment->status === Assignment::STATUS_SCHEDULED
            && $assignment->released_at !== null
            && $assignment->released_at->lessThanOrEqualTo($at);
    }

    /**
     * Has the FINAL CLOSING TIME passed? After this, nothing new is accepted,
     * whatever the late policy says.
     *
     * This asks about `closes_at` ONLY, never about the due date. The two
     * deadlines are different facts and conflating them is what would make the
     * late policy unreachable: with a fallback to the due date, any assignment
     * past its due date would be closed and a permitted late submission would be
     * impossible. See Assignment::hardCloseAt().
     *
     * A null `closes_at` means there is no hard stop, so this is false - and the
     * due date is then governed solely by the late policy.
     */
    public static function isPastFinalClose(Assignment $assignment, ?Carbon $at = null): bool
    {
        $at ??= now();
        $hardClose = $assignment->hardCloseAt();

        return $hardClose !== null && $hardClose->lessThan($at);
    }

    /**
     * Is the DUE DATE in the past, while the assignment is still able to accept
     * work? This is the "overdue but still open" situation, and it is what
     * distinguishes a student who may still submit from one who may not.
     */
    public static function isOverdueButOpen(Assignment $assignment, ?Carbon $at = null): bool
    {
        $at ??= now();

        return $assignment->due_date !== null
            && $assignment->due_date->lessThan($at)
            && self::mayAcceptNewSubmission($assignment, 0, $at);
    }

    /**
     * The plain-word label a lecturer and a student both read.
     *
     * A published assignment whose release moment has not arrived is called
     * "Scheduled", because telling a lecturer their work is live when it is not
     * would be the single most misleading thing this feature could do.
     */
    public static function labelFor(Assignment $assignment, ?Carbon $at = null): string
    {
        $at ??= now();

        return match (true) {
            $assignment->status === Assignment::STATUS_DRAFT => 'Draft',
            $assignment->status === Assignment::STATUS_CLOSED => 'Closed',
            $assignment->status === Assignment::STATUS_SCHEDULED && ! self::hasReleased($assignment, $at) => 'Scheduled',
            default => 'Published',
        };
    }

    /**
     * The lecturer-facing state word for a list row.
     *
     * A published assignment whose deadline has passed is shown as "Closed" to
     * the lecturer, because that is the truthful description of what it now does
     * - it accepts nothing new. The stored status is not rewritten, so a
     * re-published assignment does not have to be "un-closed" first.
     */
    /**
     * The lecturer-facing state word for a list row.
     *
     * A published assignment whose FINAL CLOSING TIME has passed is shown as
     * "Closed" to the lecturer, because that is the truthful description of what
     * it now does - it accepts nothing new. The stored status is not rewritten,
     * so a re-published assignment does not have to be "un-closed" first.
     *
     * An assignment merely PAST ITS DUE DATE is NOT called closed. If the late
     * policy still permits work, calling it closed would tell a lecturer it has
     * stopped accepting submissions when it has not.
     */
    public static function lecturerLabel(Assignment $assignment, ?Carbon $at = null): string
    {
        if ($assignment->status === Assignment::STATUS_PUBLISHED && self::isPastFinalClose($assignment, $at)) {
            return 'Closed';
        }

        return self::labelFor($assignment, $at);
    }

    /**
     * May this student attempt a NEW submission right now?
     *
     * Three separate facts, deliberately not collapsed:
     *   - has it opened (lifecycle)
     *   - has the FINAL CLOSING TIME passed (always stops new work)
     *   - did the due date pass while late work is still permitted
     *
     * A draft in progress does not consume an attempt, so a student whose work
     * is half-finished is not locked out.
     */
    public static function mayAcceptNewSubmission(Assignment $assignment, int $attemptsUsed, ?Carbon $at = null): bool
    {
        $at ??= now();

        // A CLOSED ASSIGNMENT ACCEPTS NOTHING NEW.
        //
        // Checked by STATUS, and checked first, because `isOpenToStudents()`
        // deliberately answers true for a closed assignment - a student must be
        // able to READ their submission and any released mark on it. Using that
        // call as the first gate here read "you may see it" as "you may hand work
        // in to it", so a closed assignment with no `closes_at` - the default,
        // because `closes_at` is null on most assignments - accepted new work.
        if ($assignment->status === Assignment::STATUS_CLOSED) {
            return false;
        }

        if (! self::isOpenToStudents($assignment, $at)) {
            return false;
        }

        if (self::isPastFinalClose($assignment, $at)) {
            return false;
        }

        // The due date is a soft deadline: what happens after it is the late
        // policy's decision, and this is the one place that decision is made.
        if ($assignment->due_date
            && $assignment->due_date->lessThan($at)
            && ! $assignment->allowsLateSubmissions()) {
            return false;
        }

        return $attemptsUsed < $assignment->attemptsAllowed();
    }

    /**
     * Why a submission was refused, in words a student can act on.
     */
    public static function refusalReason(Assignment $assignment, int $attemptsUsed, ?Carbon $at = null): ?string
    {
        $at ??= now();

        // THE SAME CHECK AS ABOVE, in the same place. These are two questions -
        // "may they hand in?" and "why not?" - and the one thing worse than
        // answering them differently is having only one of them enforce the rule.
        if ($assignment->status === Assignment::STATUS_CLOSED) {
            return $assignment->closed_at
                ? 'This assignment was closed on '.self::date($assignment->closed_at)
                    .'. New submissions are not accepted, but you can still read your work and any result.'
                : 'This assignment has been closed. New submissions are not accepted, but you can still read your work and any result.';
        }

        if (! self::isOpenToStudents($assignment, $at)) {
            return 'This assignment is not open for submissions.';
        }

        if (self::isPastFinalClose($assignment, $at)) {
            return 'This assignment closed on '.self::date($assignment->hardCloseAt()).'. Submissions are no longer accepted.';
        }

        if ($assignment->due_date
            && $assignment->due_date->lessThan($at)
            && ! $assignment->allowsLateSubmissions()) {
            return 'The due date passed on '.self::date($assignment->due_date).' and this assignment does not accept late submissions.';
        }

        if ($attemptsUsed >= $assignment->attemptsAllowed()) {
            return $assignment->attemptsAllowed() === 1
                ? 'You have already submitted this assignment.'
                : 'You have used all '.$assignment->attemptsAllowed().' attempts for this assignment.';
        }

        return null;
    }

    /**
     * The FULFILFILLED state a submission carries, for the legacy `status` enum
     * ('submitted' | 'late' | 'graded'), which the K12 screens still read.
     */
    public static function legacyStatusFor(AssignmentSubmission $submission): string
    {
        if ($submission->isGraded()) {
            return 'graded';
        }

        return $submission->isLate() ? 'late' : 'submitted';
    }

    private static function date(?Carbon $moment): string
    {
        return $moment?->format('j M Y, H:i') ?? 'the deadline';
    }
}
