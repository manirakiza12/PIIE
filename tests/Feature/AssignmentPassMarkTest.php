<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingModule;
use App\Support\Assignments\AssignmentOutcome;
use App\Support\CourseContent\ModuleCompletion;
use Illuminate\Support\Carbon;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Pass marks, and the one vocabulary every feature reads to decide whether a
 * student has passed an assessment.
 *
 * THE DEFECT THIS EXISTS TO PIN
 *
 * `Assignment::RULE_RELEASED_MARK` was satisfied purely by `marks_released_at`
 * being set. `ModuleCompletion::assessmentRow()` read the state as 'returned'
 * and stopped. A lecturer who released a mark of ZERO out of a hundred therefore
 * satisfied a REQUIRED task and completed the module: a zero mark passed. There
 * was no threshold anywhere in the assignment path, and `pass_mark` is the fix.
 *
 * THE PROPERTY THAT HAD TO SURVIVE IT
 *
 * An assignment with NO pass mark must behave exactly as it did before. Work
 * already returned to students stays returned. Inventing a default threshold
 * would retroactively un-complete assessments students have already sat - so
 * null is a first-class value, not "not configured yet".
 *
 * WHY BEST ATTEMPT, NOT LATEST
 *
 * A student who scores 40 then 85 has passed. Reading the latest attempt would
 * report them failed, withhold the congratulations, and keep the next topic
 * locked - three separate wrongs from one wrong question.
 */
class AssignmentPassMarkTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    private CourseOfferingModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignmentSetUp();

        $this->module = $this->module();
    }

    /**
     * Complete every PUBLISHED lesson in the module.
     *
     * A pass-mark test that failed because a lesson was still open would be
     * testing the wrong thing, so lessons are cleared first and the assertion is
     * left to speak only about the assessment.
     */
    private function markLessonComplete(): void
    {
        $lessons = CourseOfferingLesson::query()
            ->where('course_offering_module_id', $this->module->id)
            ->where('status', CourseOfferingLesson::STATUS_PUBLISHED)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        foreach ($lessons as $lesson) {
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
    }

    /** The module's completion report for the student. */
    private function completion(): array
    {
        return app(ModuleCompletion::class)->forModule($this->module, $this->student);
    }

    // ── The defect ──────────────────────────────────────────────────────────

    public function test_a_released_zero_does_not_pass_a_required_task_that_has_a_pass_mark(): void
    {
        $task = $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'max_marks' => 100,
            'pass_mark' => 50,
        ]);
        $this->markLessonComplete();

        // The lecturer marks it zero AND releases it. Under the old rule this
        // state - 'returned' - completed the module outright.
        $this->submission($task, $this->student, [
            'marks_awarded' => 0,
            'graded_at' => now()->subMinutes(30),
            'marks_released_at' => now()->subMinutes(20),
            'returned_at' => now()->subMinutes(20),
        ]);

        $completion = $this->completion();

        $this->assertFalse($completion['is_complete'], 'a released zero must not complete a required task');
        $this->assertSame(1, $completion['assessments_outstanding']);
    }

    // ── The property that had to survive ────────────────────────────────────

    public function test_an_assignment_with_no_pass_mark_still_accepts_a_released_zero(): void
    {
        // Backwards compatibility, stated as a test rather than assumed. No
        // threshold means no threshold - including at zero.
        $task = $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'max_marks' => 100,
            'pass_mark' => null,
        ]);
        $this->markLessonComplete();

        $this->submission($task, $this->student, [
            'marks_awarded' => 0,
            'graded_at' => now()->subMinutes(30),
            'marks_released_at' => now()->subMinutes(20),
            'returned_at' => now()->subMinutes(20),
        ]);

        $completion = $this->completion();

        $this->assertTrue($completion['is_complete'], 'an unthresholded assignment keeps its old behaviour');
    }

    public function test_a_mark_that_meets_the_pass_mark_completes_the_module(): void
    {
        $task = $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'max_marks' => 100,
            'pass_mark' => 50,
        ]);
        $this->markLessonComplete();

        $this->submission($task, $this->student, [
            'marks_awarded' => 50,
            'graded_at' => now()->subMinutes(30),
            'marks_released_at' => now()->subMinutes(20),
            'returned_at' => now()->subMinutes(20),
        ]);

        $completion = $this->completion();

        // 50 meets a pass mark of 50. The threshold is a floor, not a hurdle.
        $this->assertTrue($completion['is_complete']);
    }

    // ── The shared vocabulary ───────────────────────────────────────────────

    public function test_the_outcome_reads_the_best_attempt_not_the_latest(): void
    {
        $task = $this->publishedAssignment(['max_marks' => 100, 'pass_mark' => 50]);

        // Failed first, then passed. The latest attempt is the 20.
        $this->submission($task, $this->student, [
            'attempt_no' => 1,
            'marks_awarded' => 20,
            'graded_at' => now()->subHours(3),
            'marks_released_at' => now()->subHours(3),
        ]);
        $this->submission($task, $this->student, [
            'attempt_no' => 2,
            'marks_awarded' => 85,
            'graded_at' => now()->subHour(),
            'marks_released_at' => now()->subHour(),
        ]);

        $outcome = AssignmentOutcome::for($task->fresh(), $this->student);

        $this->assertSame(Assignment::OUTCOME_PASSED, $outcome->outcome);
        $this->assertSame(85.0, $outcome->marksAwarded);
    }

    public function test_a_marked_but_unreleased_result_is_pending_not_failed(): void
    {
        // Telling a student they failed before a lecturer has returned the work
        // would be inventing a result, and refusing them a retry on that basis
        // would be worse.
        $task = $this->publishedAssignment(['max_marks' => 100, 'pass_mark' => 50]);

        $this->submission($task, $this->student, [
            'marks_awarded' => 10,
            'graded_at' => now()->subHour(),
            'marks_released_at' => null,
        ]);

        $outcome = AssignmentOutcome::for($task->fresh(), $this->student);

        $this->assertSame(Assignment::OUTCOME_PENDING, $outcome->outcome);
        $this->assertFalse($outcome->failed());
    }

    public function test_a_draft_is_not_an_attempt(): void
    {
        $task = $this->publishedAssignment(['max_marks' => 100, 'pass_mark' => 50]);

        $this->submission($task, $this->student, [
            'is_draft' => true,
            'submitted_at' => null,
            'marks_awarded' => 90,
            'marks_released_at' => now(),
        ]);

        $outcome = AssignmentOutcome::for($task->fresh(), $this->student);

        $this->assertSame(Assignment::OUTCOME_NOT_ATTEMPTED, $outcome->outcome);
        $this->assertSame(0, $outcome->attempts);
    }

    // ── The congratulations guard ───────────────────────────────────────────

    public function test_a_congratulations_is_never_produced_for_a_student_who_did_not_pass(): void
    {
        $task = $this->publishedAssignment(['max_marks' => 100, 'pass_mark' => 50]);
        $this->submission($task, $this->student, [
            'marks_awarded' => 12,
            'graded_at' => now()->subHour(),
            'marks_released_at' => now()->subHour(),
        ]);

        $outcome = AssignmentOutcome::for($task->fresh(), $this->student);

        // Null, not a neutral string. A caller therefore cannot accidentally
        // render a congratulations panel for a student who failed.
        $this->assertNull($outcome->congratulation('Introduction to Information Technology'));
    }

    public function test_a_congratulations_names_the_unit_and_the_mark(): void
    {
        $task = $this->publishedAssignment(['max_marks' => 100, 'pass_mark' => 50]);
        $this->submission($task, $this->student, [
            'marks_awarded' => 88,
            'graded_at' => now()->subHour(),
            'marks_released_at' => now()->subHour(),
        ]);

        $outcome = AssignmentOutcome::for($task->fresh(), $this->student);

        $this->assertStringContainsString('Introduction to Information Technology', (string) $outcome->congratulation('Introduction to Information Technology'));
        $this->assertStringContainsString('88', (string) $outcome->congratulation('Introduction to Information Technology'));
    }

    // ── Configuration ───────────────────────────────────────────────────────

    public function test_a_pass_mark_above_the_maximum_is_refused_r_than_clamped(): void
    {
        // Clamping would store a number the lecturer never asked for and would
        // quietly make an impossible assessment possible.
        $this->actingAs($this->lecturer);

        $response = $this->post(route('teacher.course_offerings.assignments.store', $this->offering->id), [
            'title' => 'Impossible threshold',
            'max_marks' => 10,
            'pass_mark' => 50,
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'late_policy' => Assignment::LATE_ALLOWED,
            'status' => Assignment::STATUS_DRAFT,
            'requirement_role' => Assignment::ROLE_OPTIONAL,
            'completion_rule' => Assignment::RULE_SUBMISSION,
        ]);

        $response->assertSessionHasErrors('pass_mark');
        $this->assertDatabaseMissing('assignments', ['title' => 'Impossible threshold']);
    }

    public function test_an_untouched_pass_mark_field_means_no_threshold(): void
    {
        // An empty number input posts ''. That means "not set", NOT zero - a
        // zero pass mark has to be typed deliberately, or a lecturer who never
        // touched the field would police their assessment by accident.
        $this->actingAs($this->lecturer);

        $this->post(route('teacher.course_offerings.assignments.store', $this->offering->id), [
            'title' => 'No threshold chosen',
            'max_marks' => 100,
            'pass_mark' => '',
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'late_policy' => Assignment::LATE_ALLOWED,
            'status' => Assignment::STATUS_DRAFT,
            'requirement_role' => Assignment::ROLE_OPTIONAL,
            'completion_rule' => Assignment::RULE_SUBMISSION,
        ])->assertSessionHasNoErrors();

        $assignment = Assignment::query()->where('title', 'No threshold chosen')->sole();

        $this->assertFalse($assignment->hasPassMark());
        $this->assertNull($assignment->pass_mark);
    }

    public function test_a_pass_marked_assignment_defaults_to_requiring_lecturer_approval(): void
    {
        // Setting a pass mark means asking to be told when a student fell short.
        // Defaulting to unlimited silent retries would make that request hollow.
        $this->actingAs($this->lecturer);

        $this->post(route('teacher.course_offerings.assignments.store', $this->offering->id), [
            'title' => 'Policed assessment',
            'max_marks' => 100,
            'pass_mark' => 50,
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'late_policy' => Assignment::LATE_ALLOWED,
            'status' => Assignment::STATUS_DRAFT,
            'requirement_role' => Assignment::ROLE_OPTIONAL,
            'completion_rule' => Assignment::RULE_SUBMISSION,
        ])->assertSessionHasNoErrors();

        $assignment = Assignment::query()->where('title', 'Policed assessment')->sole();

        $this->assertSame(Assignment::RETRY_APPROVAL, $assignment->retry_policy);
        $this->assertFalse($assignment->allowsAutomaticRetry());
    }

    public function test_an_unthresholded_assignment_keeps_automatic_retries(): void
    {
        // Nothing is being policed, so the attempt limit must behave exactly as
        // it always has rather than silently changing meaning.
        $this->actingAs($this->lecturer);

        $this->post(route('teacher.course_offerings.assignments.store', $this->offering->id), [
            'title' => 'Unpoliced assessment',
            'max_marks' => 100,
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'late_policy' => Assignment::LATE_ALLOWED,
            'status' => Assignment::STATUS_DRAFT,
            'requirement_role' => Assignment::ROLE_OPTIONAL,
            'completion_rule' => Assignment::RULE_SUBMISSION,
        ])->assertSessionHasNoErrors();

        $assignment = Assignment::query()->where('title', 'Unpoliced assessment')->sole();

        $this->assertTrue($assignment->allowsAutomaticRetry());
    }

    // ── The rest period, at the level the model owns it ─────────────────────

    public function test_a_lock_in_the_past_is_not_a_lock(): void
    {
        $task = $this->publishedAssignment();
        $task->locked_until = Carbon::now()->subDay();
        $task->save();

        // The column is left for the audit trail rather than cleared on read:
        // clearing it during a read would mean a page view mutates state.
        $this->assertFalse($task->fresh()->isResting());
    }

    public function test_a_lock_in_the_future_is_a_lock(): void
    {
        $task = $this->publishedAssignment();
        $task->locked_until = Carbon::now()->addDays(2);
        $task->save();

        $this->assertTrue($task->fresh()->isResting());
    }
}
