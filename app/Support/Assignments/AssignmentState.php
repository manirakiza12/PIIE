<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;

/**
 * WHAT STATE IS THIS STUDENT'S WORK IN, IN WORDS A STUDENT CAN ACT ON.
 *
 * ── WHY THIS IS A SHARED CLASS AND NOT A VIEW CONCERN ─────────────────────
 *
 * The Course Offering assignment list and the Course Home card both need to say
 * "Open", "Missing", "Returned (late)" and so on. Written twice, the two would
 * disagree - and the disagreement would be invisible, because both sentences
 * would be individually reasonable and only their juxtaposition would be wrong. A
 * student told "Open" on a card and "Missing" on the list has been given two
 * contradictory facts about the same work.
 *
 * So the vocabulary lives here once, and every surface asks the same question of
 * the same code. The wording below is the wording the assignment list already
 * used, copied exactly - including its em dashes - because changing a string a
 * student may have already read, in order to move it, is a cost with no benefit.
 *
 * ── THE ORDER OF THE QUESTIONS, AND WHY IT IS THIS ORDER ──────────────────
 *
 * 1. Has a MARK come back?           -> Returned / Returned (late)
 * 2. Has WORK come back?             -> Submitted / Submitted (late)
 * 3. Can this student still hand in? -> if not: Overdue-prepared / Missing
 * 4. Is the due date past but still open? -> Overdue, still open
 * 5. Is there prepared work?         -> In progress
 * 6. Otherwise                       -> Open / Upcoming
 *
 * The single most useful question is the third: whether the student CAN act. A
 * date is a fact about the calendar; acceptance is a fact about the student's
 * options, and it is the one that tells them whether there is anything to do.
 *
 * That is also why an overdue assignment with late work still permitted reads
 * "Overdue — still open for late submission" and NOT "Missing". Telling a
 * student their work is missing when they can still submit it is both false and
 * needlessly alarming.
 *
 * A PREPARED DRAFT IS NAMED AS ITS OWN THING
 *
 * "Missing" about work a student prepared but did not submit would be unfair and
 * untrue, so a draft changes the words - never the underlying facts.
 */
final class AssignmentState
{
    public const RETURNED = 'Returned';
    public const RETURNED_LATE = 'Returned (late)';
    public const SUBMITTED = 'Submitted';
    public const SUBMITTED_LATE = 'Submitted (late)';
    public const OVERDUE_PREPARED = 'Overdue — work prepared but not submitted';
    public const MISSING = 'Missing';
    public const OVERDUE_OPEN = 'Overdue — still open for late submission';
    public const OVERDUE_OPEN_DRAFT = 'Overdue — you can still submit your prepared work';
    public const IN_PROGRESS = 'In progress — not yet submitted';
    public const OPEN = 'Open';
    public const UPCOMING = 'Upcoming';

    /**
     * The states that mean the student still has something TO DO.
     *
     * A card counting outstanding work uses this list rather than "anything not
     * returned", because "not yet submitted" and "already handed back" are not the
     * same category of thing and a total mixing them would overstate the work
     * waiting on the student.
     *
     * @var list<string>
     */
    public const ACTIONABLE = [
        self::OPEN,
        self::IN_PROGRESS,
        self::OVERDUE_OPEN,
        self::OVERDUE_OPEN_DRAFT,
        self::OVERDUE_PREPARED,
    ];

    /**
     * The states that mean the deadline has PASSED and nothing has arrived.
     *
     * @var list<string>
     */
    public const OVERDUE = [
        self::OVERDUE_PREPARED,
        self::OVERDUE_OPEN,
        self::OVERDUE_OPEN_DRAFT,
        self::MISSING,
    ];

    public static function for(Assignment $assignment, ?AssignmentSubmission $latest, int $attemptsUsed, bool $hasDraft): string
    {
        if ($latest && $latest->isReleased()) {
            return $latest->isLate() ? self::RETURNED_LATE : self::RETURNED;
        }

        if ($latest) {
            return $latest->isLate() ? self::SUBMITTED_LATE : self::SUBMITTED;
        }

        // CAN THEY STILL ACT? Asked before anything about dates, because it is the
        // question that determines whether there is anything they can do.
        if (! AssignmentLifecycle::mayAcceptNewSubmission($assignment, $attemptsUsed)) {
            return $hasDraft ? self::OVERDUE_PREPARED : self::MISSING;
        }

        if (AssignmentLifecycle::isOverdueButOpen($assignment)) {
            return $hasDraft ? self::OVERDUE_OPEN_DRAFT : self::OVERDUE_OPEN;
        }

        if ($hasDraft) {
            return self::IN_PROGRESS;
        }

        if (AssignmentLifecycle::isOpenToStudents($assignment)) {
            return self::OPEN;
        }

        return self::UPCOMING;
    }

    /** Is this state one where the student still has work to hand in? */
    public static function isActionable(string $state): bool
    {
        return in_array($state, self::ACTIONABLE, true);
    }

    /** Has the deadline passed with nothing to show for it? */
    public static function isOverdue(string $state): bool
    {
        return in_array($state, self::OVERDUE, true);
    }

    /**
     * A short word for a chip, without the clause that explains it.
     *
     * A chip in a card has room for a word, not a sentence. The full sentence is
     * still available to anything that can hold it, so the chip is a summary of
     * the same claim rather than a different one - and a chip is never the only
     * place a state is stated.
     */
    public static function shortLabel(string $state): string
    {
        if ($state === self::RETURNED || $state === self::RETURNED_LATE) {
            return 'Returned';
        }

        if ($state === self::SUBMITTED || $state === self::SUBMITTED_LATE) {
            return 'Submitted';
        }

        if ($state === self::OVERDUE_PREPARED) {
            return 'Prepared';
        }

        if (str_starts_with($state, 'Overdue')) {
            return 'Overdue';
        }

        if ($state === self::IN_PROGRESS) {
            return 'In progress';
        }

        return $state;
    }

    /**
     * The visual treatment for a state, as a CSS class suffix.
     *
     * Kept beside the wording on purpose. A state that is stated in words and
     * coloured separately can drift - the word "Overdue" ends up in a green chip
     * and nothing notices, because each was right on its own. One function
     * returning both means a new state cannot be added without also choosing how
     * it looks, or explicitly choosing not to.
     */
    public static function chipClass(string $state): string
    {
        if (in_array($state, [self::RETURNED, self::RETURNED_LATE], true)) {
            return 'as-chip-returned';
        }

        if (in_array($state, [self::SUBMITTED, self::SUBMITTED_LATE], true)) {
            return 'as-chip-progress';
        }

        if (self::isOverdue($state)) {
            return 'as-chip-missing';
        }

        if ($state === self::IN_PROGRESS) {
            return 'as-chip-draft';
        }

        if ($state === self::OPEN) {
            return 'as-chip-open';
        }

        return 'as-chip-muted';
    }
}
