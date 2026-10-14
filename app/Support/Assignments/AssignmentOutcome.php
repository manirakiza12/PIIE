<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * THE ONE PLACE A STUDENT'S ATTEMPT OUTCOME IS DECIDED.
 *
 * WHY THIS CLASS EXISTS
 *
 * Three features were specified that all need to agree about one fact: whether a
 * student has passed an assessment. Progression gating has to unlock the next
 * topic on it, the lecturer's progress grid has to colour it, and the
 * congratulation popup has to appear on it.
 *
 * Built separately - which is what the benchmark's stages 6 and 7 would
 * otherwise invite - they drift. The characteristic failure is a student shown
 * "failed" on the assignment page while the content screen quietly unlocks the
 * next topic. That is not a cosmetic bug; it is the platform contradicting
 * itself about whether a student earned their place.
 *
 * So there is one class, and every caller asks it.
 *
 * BEST ATTEMPT, NOT LATEST ATTEMPT
 *
 * The outcome is derived from the student's BEST released attempt, not their most
 * recent one. A student who scores 40, then 85, has passed - reading the latest
 * attempt would report them as failed, would withhold the congratulations, and
 * would keep the next topic locked, all three of which are wrong. The best
 * attempt is also the one that matters for the pass mark, because the pass mark
 * is a floor the student has cleared at least once.
 *
 * ONLY A RELEASED ATTEMPT COUNTS
 *
 * A mark that has been recorded but not returned is not a fact the student
 * holds yet. It reads as `pending`, never as `failed`: telling a student they
 * have failed before a lecturer has returned the work would be inventing a
 * result, and refusing them a further attempt on that basis would be worse.
 */
final class AssignmentOutcome
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?AssignmentSubmission $best,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?float $marksAwarded,
        public readonly ?float $passMark,
        public readonly bool $resting,
        public readonly ?Carbon $restEndsAt,
    ) {
    }

    /**
     * The outcome of `assignment` for `student`, at instant `at`.
     *
     * Only RELEASED attempts are considered. Drafts are excluded outright, and so
     * is an attempt that has been marked but not returned: neither is a fact
     * about what the student has achieved.
     */
    public static function for(Assignment $assignment, User $student, ?Carbon $at = null): self
    {
        $at ??= now();

        $attempts = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $student->id)
            ->where('is_draft', false)
            ->whereNotNull('submitted_at')
            ->orderBy('attempt_no')
            ->get();

        $released = $attempts->filter(fn (AssignmentSubmission $s) => $s->isReleased());

        // The best RELEASED attempt. `$released` is empty for an assignment that
        // has never been marked, which is the common case and is handled below.
        $best = $released->sortByDesc(
            fn (AssignmentSubmission $s) => $s->marks_awarded === null ? -1.0 : (float) $s->marks_awarded
        )->first();

        $marks = $best?->marks_awarded === null ? null : (float) $best->marks_awarded;
        $resting = $assignment->isResting($at);

        $outcome = match (true) {
            // A live rest period outranks everything else. The student has spent
            // the cycle and is waiting; whether they had passed is already
            // settled above, because a pass would have ended the rest period
            // when it was set.
            $resting => Assignment::OUTCOME_RESTING,

            // Cleared the threshold, or there is no threshold to clear.
            $best !== null && $assignment->meetsPassMark($marks) => Assignment::OUTCOME_PASSED,

            // Marked and returned, and it fell short. This is the only state
            // that is a genuine failure: the work was assessed and came back
            // short of the bar the lecturer set.
            $best !== null => Assignment::OUTCOME_FAILED,

            // Handed in, or marked but not yet returned. Not a failure.
            $attempts->isNotEmpty() => Assignment::OUTCOME_PENDING,

            default => Assignment::OUTCOME_NOT_ATTEMPTED,
        };

        return new self(
            $outcome,
            $best,
            $attempts->count(),
            $assignment->attemptsAllowed(),
            $marks,
            $assignment->hasPassMark() ? (float) $assignment->pass_mark : null,
            $resting,
            $assignment->locked_until,
        );
    }

    public function passed(): bool
    {
        return $this->outcome === Assignment::OUTCOME_PASSED;
    }

    public function failed(): bool
    {
        return $this->outcome === Assignment::OUTCOME_FAILED;
    }

    /**
     * Whether the student still has an attempt available to spend right now.
     *
     * Distinct from `passed()`: a student who has passed is done with this
     * assessment for good, which is not the same as still being able to submit.
     */
    public function mayAttempt(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->attempts < $this->maxAttempts && ! $this->resting;
    }

    /** Attempts left in the current cycle, never negative. */
    public function attemptsRemaining(): int
    {
        return max(0, $this->maxAttempts - $this->attempts);
    }

    /** The mark as a percentage of the maximum, or null when unmarked. */
    public function percent(?int $maxMarks): ?float
    {
        if ($this->marksAwarded === null || ! $maxMarks) {
            return null;
        }

        return round(($this->marksAwarded / $maxMarks) * 100, 1);
    }

    /** Plain words, safe to show a student. Never congratulates a non-pass. */
    public function studentLabel(): string
    {
        return match ($this->outcome) {
            Assignment::OUTCOME_PASSED => 'Passed',
            Assignment::OUTCOME_FAILED => 'Not yet passed',
            Assignment::OUTCOME_RESTING => 'Paused - re-read the notes and try again shortly',
            Assignment::OUTCOME_PENDING => 'Submitted - awaiting your result',
            default => 'Not attempted',
        };
    }

    /**
     * The congratulation line, or null.
     *
     * Returning null rather than a neutral string is deliberate: the caller can
     * therefore never accidentally render a congratulations panel for a student
     * who did not pass, which is the exact mistake the specification warns about.
     */
    public function congratulation(?string $unit = null): ?string
    {
        if (! $this->passed()) {
            return null;
        }

        $where = $unit !== null && trim($unit) !== '' ? trim($unit) : 'this assessment';

        return 'Passed. You have cleared '.$where.'.'
            .($this->marksAwarded !== null ? ' Your mark is '.$this->marksAwarded.'.' : '');
    }
}
