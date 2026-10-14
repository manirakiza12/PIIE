<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * THE 48-HOUR REST PERIOD: three attempts, then a pause.
 *
 * WHY IT IS A SEPARATE OBJECT AND NOT A COLUMN
 *
 * The moment is per STUDENT, not per assignment. One lecturer cannot sit in front
 * of every class, so a rest period cannot be a property of the assignment the way
 * `allowed_attempts` is - the moment exists only in relation to a particular
 * student's own history. PIIE has no per-student lock table, and adding one for a
 * single feature would be a poor trade; so the state is derived, and the
 * `locked_until` column on the assignment records the CURRENT pending pause so a
 * lecturer can see at a glance that somebody is waiting.
 *
 * DERIVED, NOT STORED, WHERE IT COUNTS
 *
 * A pause starts the moment the student's best released attempt falls short AND
 * they have no attempt left. Both facts are already recorded - in
 * `assignment_submissions` - so the moment is computed from them. That means the
 * pause cannot drift from the evidence, cannot be set by a request, and expires by
 * itself with no scheduled job to forget to run. A stored "locked until" that
 * nobody clears is a student locked out forever by a process that crashed.
 *
 * WHY THE ATTEMPT COUNT IS PER CYCLE
 *
 * `attemptsUsed()` counts every non-draft attempt a student has ever made. Left
 * alone, the three-attempt rule is permanent: a student who fails three times
 * could never try again, for the rest of their enrolment. A cycle is three
 * attempts; exhausting one starts a pause; after the pause a new cycle begins and
 * the student has three more. `retry_policy` decides whether that happens
 * automatically or only once a lecturer authorises it.
 */
final class RestPeriod
{
    /** The pause the institution asked for. */
    public const DEFAULT_HOURS = 48;

    public function __construct(
        private Assignment $assignment,
        private User $student,
    ) {
    }

    /**
     * Attempts spent in the student's CURRENT cycle.
     *
     * Scoped by `attempt_cycle`, so a student working through a second cycle is
     * not permanently blocked by the attempts of the first. Rows written before
     * the column existed are cycle 1 by the column's default, which is exactly
     * how they should read.
     */
    public function attemptsUsedInCycle(): int
    {
        return AssignmentSubmission::query()
            ->where('assignment_id', $this->assignment->id)
            ->where('student_id', $this->student->id)
            ->where('is_draft', false)
            ->where('attempt_cycle', $this->currentCycle())
            ->count();
    }

    /**
     * Which cycle the student is in.
     *
     * Derived from the highest cycle they have actually attempted, so the answer
     * is the same before and after the pause - there is no "advancing" step that
     * could be missed, skipped or replayed.
     */
    public function currentCycle(): int
    {
        $highest = (int) AssignmentSubmission::query()
            ->where('assignment_id', $this->assignment->id)
            ->where('student_id', $this->student->id)
            ->where('is_draft', false)
            ->max('attempt_cycle');

        return max(1, $highest);
    }

    /**
     * The student's BEST released attempt, as a mark.
     *
     * The best, not the latest. The pass mark is a floor the student has cleared
     * at least once, so clearing it once and then doing worse must not un-clear it.
     */
    public function bestMark(): ?float
    {
        $best = AssignmentSubmission::query()
            ->where('assignment_id', $this->assignment->id)
            ->where('student_id', $this->student->id)
            ->where('is_draft', false)
            ->whereNotNull('marks_released_at')
            ->whereNotNull('marks_awarded')
            ->orderByDesc('marks_awarded')
            ->first();

        return $best === null ? null : (float) $best->marks_awarded;
    }

    public function hasPassed(): bool
    {
        return $this->assignment->meetsPassMark($this->bestMark());
    }

    /**
     * Is the student waiting out a pause right now?
     *
     * Four things must ALL be true, and every one of them is load-bearing:
     *
     *   1. there is a pass mark - with no threshold, nothing can be "failed";
     *   2. the student has not passed - a pass ends the matter for good;
     *   3. they have spent every attempt in this cycle;
     *   4. the pause has not expired.
     *
     * Condition 1 is the one most easily forgotten. Without it, EVERY assignment
     * with one attempt configured would silently lock a student out after a
     * single hand-in, which is the opposite of what was asked for.
     */
    public function isResting(?Carbon $at = null): bool
    {
        $at ??= now();

        if (! $this->assignment->hasPassMark() || $this->hasPassed()) {
            return false;
        }

        // NOT YET MARKED IS NOT "FAILED".
        //
        // A student who has handed work in three times and has had nothing
        // returned is not resting - they are WAITING. Starting a 48-hour pause
        // against a student who has not been told their mark yet punishes them
        // for a lecturer's delay, and hides the real problem behind a message
        // about re-reading notes.
        if (! $this->hasBeenAssessedInCycle()) {
            return false;
        }

        if ($this->attemptsUsedInCycle() < $this->assignment->attemptsAllowed()) {
            return false;
        }

        return $this->restEndsAt($at) !== null;
    }

    /**
     * Has the student been TOLD a mark in this cycle?
     *
     * "Told" means released, not merely graded. A mark sitting in the lecturer's
     * queue is not something the student has, and they cannot act on it.
     */
    public function hasBeenAssessedInCycle(): bool
    {
        return AssignmentSubmission::query()
            ->where('assignment_id', $this->assignment->id)
            ->where('student_id', $this->student->id)
            ->where('is_draft', false)
            ->where('attempt_cycle', $this->currentCycle())
            ->whereNotNull('marks_released_at')
            ->whereNotNull('marks_awarded')
            ->exists();
    }

    /**
     * When the current pause ends, or null when there is no live pause.
     *
     * Derived from the moment the LAST attempt of the cycle was released, not from
     * a stored timestamp, so it survives a crash mid-pause and expires on its own.
     * A stored timestamp would need a job to clear it; a derived one needs nothing.
     */
    public function restEndsAt(?Carbon $at = null): ?Carbon
    {
        $at ??= now();

        if (! $this->assignment->hasPassMark() || $this->hasPassed()) {
            return null;
        }

        if ($this->attemptsUsedInCycle() < $this->assignment->attemptsAllowed()) {
            return null;
        }

        // Same guard as `isResting()`: no mark returned means no pause to report.
        if (! $this->hasBeenAssessedInCycle()) {
            return null;
        }

        $last = AssignmentSubmission::query()
            ->where('assignment_id', $this->assignment->id)
            ->where('student_id', $this->student->id)
            ->where('is_draft', false)
            ->where('attempt_cycle', $this->currentCycle())
            ->orderByDesc('attempt_no')
            ->first();

        if ($last === null) {
            return null;
        }

        // From the SUBMISSION, not the marking: a lecturer who returns three
        // marks at once must not hand the student three pauses.
        $ends = $last->submitted_at->copy()->addHours($this->restHours());

        // `Carbon::isFuture()` TAKES NO ARGUMENT and always compares against the
        // real clock, so passing `$at` to it is silently discarded - a call that
        // looks time-aware and is not. The explicit `greaterThan($at)` is what
        // makes an injected instant actually mean something, which is what makes
        // this class testable at all.
        return $ends->greaterThan($at) ? $ends : null;
    }

    /**
     * May the student begin a new cycle without a lecturer?
     *
     * False under `approval`, which is the default - see `Assignment::RETRY_*`.
     */
    public function mayRetryAutomatically(): bool
    {
        return $this->assignment->allowsAutomaticRetry();
    }

    public function restHours(): int
    {
        return self::DEFAULT_HOURS;
    }

    /**
     * The words a student sees.
     *
     * Says what to do, not merely that they cannot. A refusal that only says "no"
     * is indistinguishable from a broken site, and a student who cannot tell
     * whether they have been blocked by a mistake asks a lecturer instead of
     * trying again.
     */
    public function message(?Carbon $at = null): string
    {
        $ends = $this->restEndsAt($at);

        if ($ends === null) {
            return 'You may attempt this again.';
        }

        return 'You have used all '.$this->assignment->attemptsAllowed().' attempts and not reached the pass mark. '
            .'Take a breath, re-read the notes, and come back after '
            .$ends->format('j M \a\t H:i').' to try again.'
            .($this->mayRetryAutomatically()
                ? ''
                : ' A lecturer will authorise your next attempt.');
    }
}
