<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingModule;
use App\Support\CourseContent\ProgressionGate;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Sequential gating: a student cannot open a topic before finishing the last one.
 *
 * THE DEFECT THIS EXISTS TO PIN
 *
 * There was no gating of any kind. `resolveLessonForStudent()` checked tenant,
 * registration, lesson release and module release - never progress. A student
 * could open lesson 12 by typing the URL having completed none of lessons 1-11.
 *
 * THE PROPERTY THAT HAD TO SURVIVE IT
 *
 * Gating is OPT-IN, defaulting to off. A student population mid-course must not
 * be locked out of content they have already paid for and already started, so an
 * offering that has never opted in behaves exactly as it did before. This is
 * asserted rather than assumed, and it is the reason the feature is safe to ship
 * at all.
 */
class ProgressionGateTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    private ProgressionGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignmentSetUp();

        $this->gate = new ProgressionGate;
    }

    // ── The default: nothing changes for anyone ─────────────────────────────

    public function test_progression_is_not_gated_until_a_lecturer_turns_it_on(): void
    {
        $first = $this->gatedModule('Topic 1', sequence: 1);
        $second = $this->gatedModule('Topic 2', sequence: 2);

        // Topic 1 is untouched by the student. Gating is off, so Topic 2 opens.
        $this->assertFalse($this->gate->moduleSatisfied($this->student, $first));
        $this->assertTrue($this->gate->mayOpenModule($this->student, $second));
    }

    public function test_an_existing_offering_is_never_locked_out_by_this_feature(): void
    {
        // The safety property stated directly: a course that has not opted in
        // cannot deny a student a lesson they could reach yesterday.
        $module = $this->gatedModule('Any topic', sequence: 1);
        $lesson = $this->lesson($module, ['status' => CourseOfferingLesson::STATUS_PUBLISHED]);

        $this->assertFalse($this->gate->gatingEnabled($module->fresh()));
        $this->assertTrue($this->gate->mayOpenLesson($this->student, $lesson->fresh()));
    }

    // ── The gate, once enabled ──────────────────────────────────────────────

    public function test_a_student_cannot_open_a_later_topic_while_an_earlier_one_is_unfinished(): void
    {
        $this->enableGating();

        $first = $this->gatedModule('Topic 1', sequence: 1);
        $second = $this->gatedModule('Topic 2', sequence: 2);

        $this->assertFalse($this->gate->mayOpenModule($this->student, $second));
        $this->assertTrue($this->gate->mayOpenModule($this->student, $first), 'the first topic is always open');
    }

    public function test_the_refusal_says_what_to_do_next(): void
    {
        $this->enableGating();

        $first = $this->gatedModule('Topic 1', sequence: 1);
        $second = $this->gatedModule('Topic 2', sequence: 2);

        $state = $this->gate->stateFor($this->student, $second);

        $this->assertFalse($state['open']);
        $this->assertSame('Topic 1', $state['blocking_module']->title);
        $this->assertStringContainsString('Topic 1', (string) $state['message']);
    }

    public function test_finishing_the_earlier_topic_opens_the_next_one(): void
    {
        $this->enableGating();

        $first = $this->gatedModule('Topic 1', sequence: 1);
        $second = $this->gatedModule('Topic 2', sequence: 2);

        $this->completeLessons($first);

        $this->assertTrue($this->gate->mayOpenModule($this->student, $second));
    }

    public function test_failing_the_required_assessment_does_not_open_the_next_topic(): void
    {
        // The whole point of the pass mark: handing work in is not the same as
        // passing it, and only passing opens the next topic.
        $this->enableGating();

        $first = $this->gatedModule('Topic 1', sequence: 1);
        $second = $this->gatedModule('Topic 2', sequence: 2);
        $this->completeLessons($first);

        $task = $this->requiredTask($first, passMark: 50);
        $this->submission($task, $this->student, [
            'marks_awarded' => 20,
            'graded_at' => now()->subHour(),
            'marks_released_at' => now()->subHour(),
            'returned_at' => now()->subHour(),
        ]);

        $this->assertFalse(
            $this->gate->mayOpenModule($this->student, $second),
            'a released 20 out of 100 must not open the next topic'
        );
    }

    public function test_passing_the_required_assessment_opens_the_next_topic(): void
    {
        $this->enableGating();

        $first = $this->gatedModule('Topic 1', sequence: 1);
        $second = $this->gatedModule('Topic 2', sequence: 2);
        $this->completeLessons($first);

        $task = $this->requiredTask($first, passMark: 50);
        $this->submission($task, $this->student, [
            'marks_awarded' => 70,
            'graded_at' => now()->subHour(),
            'marks_released_at' => now()->subHour(),
            'returned_at' => now()->subHour(),
        ]);

        $this->assertTrue($this->gate->mayOpenModule($this->student, $second));
    }

    public function test_a_module_the_student_cannot_see_is_never_used_as_a_gate(): void
    {
        // A module that is not released to students must not appear in the
        // sequence, or a student could be held back by content that does not
        // exist for them.
        $this->enableGating();

        $hidden = $this->gatedModule('Hidden', sequence: 1, releasedAt: now()->addWeek());
        $this->gatedModule('Topic 2', sequence: 2);

        $sequence = $this->gate->sequenceFor($this->student, $hidden->fresh());

        $this->assertCount(1, $sequence, 'the unreleased module is excluded from the sequence');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function enableGating(): void
    {
        \Illuminate\Support\Facades\DB::table('course_offerings')
            ->where('id', $this->offering->id)
            ->update(['gate_progression' => true]);
    }

    private function gatedModule(string $title, int $sequence, ?\Illuminate\Support\Carbon $releasedAt = null): CourseOfferingModule
    {
        $module = $this->module([
            'title' => $title,
            'sequence' => $sequence,
            'status' => CourseOfferingModule::STATUS_PUBLISHED,
            'released_at' => $releasedAt,
        ]);

        // A module with no lessons and no assessments is VACUOUSLY complete -
        // there is nothing outstanding, so the gate correctly lets it through.
        // Every gating test therefore needs a module with real work in it, or it
        // would be testing the empty case and pass for the wrong reason.
        $this->lesson($module, [
            'title' => $title.' - reading',
            'status' => CourseOfferingLesson::STATUS_PUBLISHED,
        ]);

        return $module->fresh();
    }

    private function completeLessons(CourseOfferingModule $module): void
    {
        $lessons = CourseOfferingLesson::query()
            ->where('course_offering_module_id', $module->id)
            ->where('status', CourseOfferingLesson::STATUS_PUBLISHED)
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

    private function requiredTask(CourseOfferingModule $module, ?float $passMark): Assignment
    {
        return $this->requiredModuleTask($module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'max_marks' => 100,
            'pass_mark' => $passMark,
        ]);
    }
}
