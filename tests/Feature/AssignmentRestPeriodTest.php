<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Support\Assignments\RestPeriod;
use DomainException;
use Illuminate\Support\Carbon;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Three attempts, then a 48-hour pause.
 *
 * THE RULE BEING ENCODED
 *
 *     attempt  -> mark below the pass mark -> attempt -> below -> attempt -> below
 *     -> the student is told to take a breath, re-read the notes, and come back
 *        in two days.
 *
 * THE FOUR THINGS THAT HAD TO BE GOT RIGHT
 *
 * 1. IT IS PER STUDENT. The moment exists only in relation to one student's own
 *    history, so it cannot be a property of the assignment. One lecturer cannot
 *    sit in front of every class.
 *
 * 2. IT IS PER CYCLE. `attemptsUsed()` counts every attempt a student has EVER
 *    made. Left alone, three attempts would be permanent - a student who failed
 *    three times could never try again for the rest of their enrolment. A cycle
 *    is three attempts; after a pause a new one begins.
 *
 * 3. IT REQUIRES A PASS MARK. With no threshold nothing can be "failed", so
 *    without this condition every assignment with one attempt configured would
 *    silently lock a student out after a single hand-in - precisely the opposite
 *    of what was asked for.
 *
 * 4. IT EXPIRES BY ITSELF. The moment is derived from the last submission rather
 *    than stored and cleared by a scheduled job. A stored timestamp that nobody
 *    clears is a student locked out forever by a process that crashed.
 */
class AssignmentRestPeriodTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    /** A published, thresholded assignment with three attempts a cycle. */
    private function policed(string $title = 'Policed assessment', ?float $passMark = 50): Assignment
    {
        return $this->publishedAssignment([
            'title' => $title,
            'max_marks' => 100,
            'pass_mark' => $passMark,
            'allowed_attempts' => 3,
            'retry_policy' => Assignment::RETRY_AUTOMATIC,
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);
    }

    /**
     * One real attempt, released with the given mark.
     *
     * `$ago` is when it was SUBMITTED, and it matters: the pause is measured from
     * the submission, not from the marking, so a test that wants a live pause must
     * place the attempt recently. Using the fixture's default of an hour ago and
     * then asserting a 48-hour pause is active cannot both be true.
     */
    private function attempt(Assignment $a, float $mark, int $attemptNo = 1, int $cycle = 1, ?int $agoMinutes = null): void
    {
        $ago = $agoMinutes ?? 60;

        $this->submission($a, $this->student, [
            'attempt_no' => $attemptNo,
            'attempt_cycle' => $cycle,
            'marks_awarded' => $mark,
            'graded_at' => now()->subMinutes($ago),
            'marks_released_at' => now()->subMinutes($ago),
            'returned_at' => now()->subMinutes($ago),
            'submitted_at' => now()->subMinutes($ago),
        ]);
    }

    private function rest(Assignment $a): RestPeriod
    {
        return new RestPeriod($a, $this->student);
    }

    // ── The pause itself ───────────────────────────────────────────────────

    public function test_three_failed_attempts_start_a_pause(): void
    {
        $a = $this->policed();

        foreach ([1, 2, 3] as $n) {
            $this->attempt($a, 20, $n);
        }

        $this->assertTrue($this->rest($a)->isResting());
    }

    public function test_a_pause_expires_by_itself_after_two_days(): void
    {
        $a = $this->policed();
        foreach ([1, 2, 3] as $n) {
            $this->attempt($a, 20, $n);
        }

        $rest = $this->rest($a);

        $this->assertTrue($rest->isResting());

        $later = now()->addHours(RestPeriod::DEFAULT_HOURS)->addMinute();

        $this->assertNotNull($rest->restEndsAt(), 'a live pause must report when it ends');

        $this->assertNull(
            $rest->restEndsAt($later),
            'no scheduled job clears anything: the moment is derived, so it simply '
            .'stops being in the future'
        );
        $this->assertFalse($rest->isResting($later));
    }

    public function test_the_pause_message_says_what_to_do_and_when_it_ends(): void
    {
        $a = $this->policed();
        foreach ([1, 2, 3] as $n) {
            $this->attempt($a, 20, $n);
        }

        $message = $this->rest($a)->message();

        // A refusal that only says "no" is indistinguishable from a broken site.
        $this->assertStringContainsString('re-read the notes', $message);
        $this->assertStringContainsString('3 attempts', $message);
        $this->assertMatchesRegularExpression('/come back after/', $message);
    }

    public function test_approval_policy_says_a_lecturer_must_authorise(): void
    {
        $a = $this->policed();
        $a->retry_policy = Assignment::RETRY_APPROVAL;
        $a->save();

        foreach ([1, 2, 3] as $n) {
            $this->attempt($a, 20, $n);
        }

        $this->assertStringContainsString('lecturer will authorise', $this->rest($a)->message());
    }

    // ── What must NOT start a pause ─────────────────────────────────────────

    public function test_no_pass_mark_means_no_pause_ever(): void
    {
        // THE CONDITION MOST EASY TO FORGET.
        //
        // With no threshold there is nothing to fall short of, so an assignment
        // with one attempt configured must not lock a student out after a single
        // hand-in. That would be a lockout nobody asked for, on every existing
        // assignment in the school.
        $a = $this->policed('No threshold', passMark: null);
        $a->allowed_attempts = 1;
        $a->save();

        $this->attempt($a, 0);

        $this->assertFalse($this->rest($a)->isResting());
    }

    public function test_passing_ends_the_matter_forever(): void
    {
        $a = $this->policed();
        foreach ([1, 2, 3] as $n) {
            $this->attempt($a, 20, $n);
        }
        $this->assertTrue($this->rest($a)->isResting());

        // A fourth attempt that passes. The best attempt is what counts, so this
        // must clear the pause rather than leave the student waiting on a result
        // they have already earned.
        $this->attempt($a, 90, 4);

        $this->assertTrue($this->rest($a)->hasPassed());
        $this->assertFalse($this->rest($a)->isResting());
    }

    public function test_an_unreturned_mark_is_not_a_failure(): void
    {
        // A mark the student has not been given yet cannot have failed them.
        $a = $this->policed();

        foreach ([1, 2, 3] as $n) {
            $this->submission($a, $this->student, [
                'attempt_no' => $n,
                'attempt_cycle' => 1,
                'marks_awarded' => 10,
                'graded_at' => now()->subHour(),
                'marks_released_at' => null,
            ]);
        }

        $this->assertFalse($this->rest($a)->isResting());
    }

    public function test_drafts_do_not_spend_attempts_and_do_not_start_a_pause(): void
    {
        $a = $this->policed();

        foreach ([1, 2, 3] as $n) {
            $this->submission($a, $this->student, [
                'attempt_no' => $n,
                'attempt_cycle' => 1,
                'is_draft' => true,
                'submitted_at' => null,
            ]);
        }

        $this->assertFalse($this->rest($a)->isResting());
        $this->assertSame(0, $this->rest($a)->attemptsUsedInCycle());
    }

    public function test_two_of_three_attempts_is_not_a_pause(): void
    {
        $a = $this->policed();
        $this->attempt($a, 20, 1);
        $this->attempt($a, 25, 2);

        $this->assertFalse($this->rest($a)->isResting());
    }

    // ── Cycles: the attempt rule is not permanent ───────────────────────────

    public function test_attempts_are_counted_per_cycle_not_for_the_whole_enrolment(): void
    {
        $a = $this->policed();
        foreach ([1, 2, 3] as $n) {
            $this->attempt($a, 20, $n, cycle: 1);
        }

        $this->assertSame(3, $this->rest($a)->attemptsUsedInCycle());

        // A fourth attempt in a SECOND cycle. The lifetime total is four, but the
        // cycle is only one - and this is what stops three attempts being a
        // permanent sentence.
        $this->attempt($a, 25, 4, cycle: 2);

        $rest = $this->rest($a);
        $this->assertSame(1, $rest->attemptsUsedInCycle());
        $this->assertFalse($rest->isResting());
    }

    // ── The gate is actually enforced on submit ────────────────────────────

    public function test_a_student_inside_the_pause_is_refused_with_the_message(): void
    {
        $a = $this->policed();
        foreach ([1, 2, 3] as $n) {
            $this->attempt($a, 20, $n);
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/re-read the notes/');

        app(\App\Support\Assignments\SubmissionService::class)->submit(
            $this->student,
            $a,
            ['text' => 'One more go.'],
            'rest-period-idempotency-1'
        );
    }

    public function test_a_submission_outside_the_pause_is_still_accepted(): void
    {
        $a = $this->policed();

        $result = app(\App\Support\Assignments\SubmissionService::class)->submit(
            $this->student,
            $a,
            ['text' => 'First go.'],
            'rest-period-idempotency-2'
        );

        $this->assertFalse($result->is_draft);
        $this->assertNotNull($result->submitted_at);
    }
}
