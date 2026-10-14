<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingModule;
use App\Support\Assignments\PassCelebration;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * The pass popup appears - and, far more importantly, does not appear.
 *
 * THE REQUIREMENT AND ITS WARNING
 *
 * "A dynamic congratulation popup saying your mark is X and you have passed, you
 * qualify for the next topic" - "and it must NEVER fire for a non-pass."
 *
 * That second clause is the hard one, and it is not a bug in the popup: it is a
 * constraint on the whole path that decides to show it. A view guarded by its own
 * `@if` is one variable-name slip away from congratulating a failure. So
 * `PassCelebration::for()` returns null unless the student has passed, and the view
 * is never handed a string in any other case. Most of these tests are about the
 * nulls.
 *
 * "QUALIFY FOR THE NEXT TOPIC" IS ALSO A CONSTRAINT
 *
 * The popup must only claim the next topic when the student actually gets it. A
 * pass clears ONE requirement; a topic can still have lessons open. Telling a
 * student to go on and then refusing them at the next click is worse than not
 * mentioning it.
 */
class PassCelebrationTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    /** A thresholded, required assessment inside a topic. */
    private function policedIn(?CourseOfferingModule $module = null): Assignment
    {
        $module ??= $this->module(['title' => 'Introduction to Sets']);

        return $this->requiredModuleTask($module, [
            'title' => 'Set theory check',
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'max_marks' => 100,
            'pass_mark' => 50,
            'allowed_attempts' => 3,
            'retry_policy' => Assignment::RETRY_AUTOMATIC,
        ]);
    }

    private function released(Assignment $a, float $mark, int $attemptNo = 1): void
    {
        $this->submission($a, $this->student, [
            'attempt_no' => $attemptNo,
            'attempt_cycle' => 1,
            'marks_awarded' => $mark,
            'graded_at' => now()->subMinutes(10),
            'marks_released_at' => now()->subMinutes(10),
            'returned_at' => now()->subMinutes(10),
            'submitted_at' => now()->subMinutes(30),
        ]);
    }

    private function celebrate(Assignment $a, string $unit = 'Introduction to Sets'): ?PassCelebration
    {
        return PassCelebration::for($a, $this->student, $unit);
    }

    // ── It appears for a pass ───────────────────────────────────────────────

    public function test_a_passing_student_is_celebrated_with_their_mark(): void
    {
        $assignment = $this->policedIn();
        $this->released($assignment, 78);

        $celebration = $this->celebrate($assignment);

        $this->assertNotNull($celebration);
        $this->assertStringContainsString('Passed', $celebration->line());
        // The mark is in the words, not merely available somewhere on the page.
        $this->assertStringContainsString('78', $celebration->line());
    }

    public function test_a_passing_student_who_has_only_the_last_topic_is_not_told_about_a_next_one(): void
    {
        $module = $this->module(['title' => 'The only topic', 'sequence' => 1]);
        $assignment = $this->policedIn($module);
        $this->released($assignment, 90);

        $celebration = $this->celebrate($assignment, 'The only topic');

        $this->assertNotNull($celebration);
        $this->assertFalse($celebration->qualifiedForNextTopic());
        $this->assertStringNotContainsString('qualified for the next topic', $celebration->line());
    }

    // ── It must NOT appear ─────────────────────────────────────────────────

    public function test_a_failing_student_is_never_celebrated(): void
    {
        // THE TEST THAT MATTERS MOST.
        $assignment = $this->policedIn();
        $this->released($assignment, 30);

        $this->assertNull($this->celebrate($assignment));
    }

    public function test_a_student_who_has_not_attempted_is_never_celebrated(): void
    {
        $this->assertNull($this->celebrate($this->policedIn()));
    }

    public function test_a_mark_that_has_not_been_returned_is_never_celebrated(): void
    {
        // Graded but not released: the student has not been told this, so celebrating
        // would announce a result the platform is not yet willing to stand behind -
        // and a mark can change before release.
        $assignment = $this->policedIn();

        $this->submission($assignment, $this->student, [
            'marks_awarded' => 95,
            'graded_at' => now()->subHour(),
            'marks_released_at' => null,
        ]);

        $this->assertNull($this->celebrate($assignment));
    }

    public function test_an_unthresholded_assignment_is_never_celebrated(): void
    {
        // With no pass mark nothing can be "passed". Celebrating a high score on an
        // assignment the lecturer never thresholded would claim a qualification
        // that does not exist, and open a topic the gate will still refuse.
        $assignment = $this->requiredModuleTask($this->module(), [
            'title' => 'Unthresholded',
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'max_marks' => 100,
            'pass_mark' => null,
        ]);

        $this->released($assignment, 100);

        $this->assertNull($this->celebrate($assignment));
    }

    public function test_a_student_waiting_out_a_pause_is_never_celebrated(): void
    {
        $assignment = $this->policedIn();

        foreach ([1, 2, 3] as $n) {
            $this->released($assignment, 25, $n);
        }

        $this->assertNull($this->celebrate($assignment));
    }

    public function test_the_best_attempt_counts_not_the_latest(): void
    {
        // Passed, then did worse. The pass mark is a floor the student cleared once;
        // clearing it and then doing worse must not un-clear it - and must not
        // replace the celebration with a silence.
        $assignment = $this->policedIn();
        $this->released($assignment, 85, 1);
        $this->released($assignment, 20, 2);

        $celebration = $this->celebrate($assignment);

        $this->assertNotNull($celebration);
        $this->assertStringContainsString('85', $celebration->line());
    }

    // ── "You qualify for the next topic" must be true ───────────────────────

    public function test_it_says_qualified_only_when_the_topic_is_complete_and_the_next_one_opens(): void
    {
        $first = $this->module(['title' => 'Introduction to Sets', 'sequence' => 1]);
        $second = $this->module(['title' => 'Relations and Functions', 'sequence' => 2]);

        // A lesson still open in the SAME topic. Passing the assessment clears one
        // requirement, not the topic.
        $this->lesson($first, ['status' => CourseOfferingLesson::STATUS_PUBLISHED]);

        $assignment = $this->policedIn($first);
        $this->released($assignment, 80);

        $celebration = $this->celebrate($assignment);

        $this->assertNotNull($celebration);
        $this->assertFalse(
            $celebration->qualifiedForNextTopic(),
            'the topic still has a lesson open, so the next one is not theirs yet'
        );
        // And it says why, rather than staying silent about a greyed-out button.
        $this->assertStringContainsString('Finish the rest of this topic', $celebration->message());

        // Complete the lesson. Now the qualification is real.
        foreach (CourseOfferingLesson::query()
            ->where('course_offering_module_id', $first->id)->get() as $lesson) {
            CourseOfferingLessonProgress::query()->updateOrCreate([
                'course_offering_lesson_id' => $lesson->id,
                'student_id' => $this->student->id,
            ], [
                'school_id' => $this->school,
                'course_offering_id' => $this->offering->id,
                'status' => CourseOfferingLessonProgress::STATUS_COMPLETED,
                'started_at' => now()->subHour(),
                'completed_at' => now(),
                'last_viewed_at' => now(),
            ]);
        }

        $after = $this->celebrate($assignment);

        $this->assertNotNull($after);
        $this->assertTrue($after->qualifiedForNextTopic());
        $this->assertSame('Relations and Functions', $after->nextTopicTitle());
        $this->assertStringContainsString('qualified for the next topic', $after->message());
    }

    public function test_an_assignment_with_no_topic_claims_no_qualification(): void
    {
        // A loose assessment gates nothing and unlocks nothing. Saying "you have
        // qualified for the next topic" would be inventing a consequence.
        $assignment = $this->publishedAssignment([
            'title' => 'Loose task',
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'max_marks' => 100,
            'pass_mark' => 50,
            'released_at' => now()->subDay(),
        ]);

        $this->released($assignment, 95);

        $celebration = PassCelebration::for($assignment, $this->student, 'Loose task');

        $this->assertNotNull($celebration);
        $this->assertFalse($celebration->qualifiedForNextTopic());
    }

    // ── The page ────────────────────────────────────────────────────────────

    public function test_the_page_shows_the_popup_for_a_pass(): void
    {
        $assignment = $this->policedIn();
        $this->released($assignment, 82);

        $this->actingAs($this->student)
            ->get(route('student.courses.assignments.show', [$this->offering->id, $assignment->id]))
            ->assertOk()
            ->assertSee('as-pass-popup', false)
            ->assertSee('82', false);
    }

    public function test_the_page_shows_no_popup_for_a_failure(): void
    {
        $assignment = $this->policedIn();
        $this->released($assignment, 12);

        $this->actingAs($this->student)
            ->get(route('student.courses.assignments.show', [$this->offering->id, $assignment->id]))
            ->assertOk()
            ->assertDontSee('as-pass-popup', false);
    }

    public function test_the_page_shows_no_popup_before_a_mark_is_returned(): void
    {
        $assignment = $this->policedIn();

        $this->submission($assignment, $this->student, [
            'marks_awarded' => 100,
            'graded_at' => now()->subHour(),
            'marks_released_at' => null,
        ]);

        $this->actingAs($this->student)
            ->get(route('student.courses.assignments.show', [$this->offering->id, $assignment->id]))
            ->assertOk()
            ->assertDontSee('as-pass-popup', false);
    }
}