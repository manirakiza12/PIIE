<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingModule;
use App\Models\User;
use App\Support\CourseExperience\CourseProgressGrid;
use App\Support\CourseContent\ModuleCompletion;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * The teacher's progress grid: every confirmed student against every topic.
 *
 * WHY THIS MATTERS
 *
 * This is the screen that answers "who is behind, and where", and it is stage 6 of
 * the teaching cycle the benchmark describes - the one PIIE had nothing for. Every
 * piece of data was already computed and thrown away; this is that data, shaped.
 *
 * WHAT MUST NOT HAPPEN
 *
 * The grid must not decide anything. It reads `ModuleCompletion` and
 * `AssignmentOutcome`, which between them already own every question it reports. A
 * second answer to "has this student passed" is how a lecturer ends up told a
 * student passed while the student is locked out of the next topic - the exact
 * contradiction the shared outcome vocabulary exists to prevent.
 */
class CourseProgressGridTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignmentSetUp();

        // Resolved from the container rather than constructed by hand:
        // `ModuleCompletion` takes collaborators, and a hand-built copy would drift
        // from what production builds - the same reason the controller resolves it
        // rather than instantiating it.
        $this->grid = app(CourseProgressGrid::class);
    }

    private CourseProgressGrid $grid;

    private function topic(string $title = 'Topic', int $sequence = 1): CourseOfferingModule
    {
        return $this->module([
            'title' => $title,
            'sequence' => $sequence,
            'status' => CourseOfferingModule::STATUS_PUBLISHED,
        ]);
    }

    private function lessonIn(CourseOfferingModule $module): CourseOfferingLesson
    {
        return $this->lesson($module, ['status' => CourseOfferingLesson::STATUS_PUBLISHED]);
    }

    private function completeLessons(CourseOfferingModule $module): void
    {
        foreach (CourseOfferingLesson::query()
            ->where('course_offering_module_id', $module->id)
            ->where('status', CourseOfferingLesson::STATUS_PUBLISHED)->get() as $lesson) {
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

    // ── The screen ──────────────────────────────────────────────────────────

    public function test_the_progress_page_renders_for_an_allocated_lecturer(): void
    {
        $this->module();

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.progress', $this->offering->id))
            ->assertOk()
            ->assertSee('Students enrolled', false);
    }

    public function test_the_grid_lists_a_confirmed_student(): void
    {
        $this->module();

        $grid = $this->grid->forOffering($this->offering);

        $this->assertSame(1, $grid['totals']['students']);
        $this->assertSame($this->student->name, $grid['rows'][0]['student']->name);
    }

    public function test_a_topic_the_student_has_finished_is_marked_complete(): void
    {
        $module = $this->module();
        $this->lessonIn($module);
        $this->completeLessons($module);

        $grid = $this->grid->forOffering($this->offering);

        $this->assertTrue($grid['rows'][0]['modules'][$module->id]['complete']);
    }

    public function test_an_unfinished_assessment_leaves_the_topic_incomplete(): void
    {
        $module = $this->module();
        $this->lessonIn($module);
        $this->completeLessons($module);

        $this->requiredModuleTask($module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'max_marks' => 100,
            'pass_mark' => 50,
        ]);

        $grid = $this->grid->forOffering($this->offering);

        $this->assertFalse($grid['rows'][0]['modules'][$module->id]['complete']);
        $this->assertSame(1, $grid['rows'][0]['modules'][$module->id]['assessments_outstanding']);
    }

    public function test_a_failed_assessment_flags_the_student_as_needing_support(): void
    {
        // The distinction a percentage cannot make: a student who has been TOLD they
        // fell short is waiting out a pause, and a grid showing only "topic 2"
        // would hide that completely.
        $module = $this->module();
        $this->lessonIn($module);
        $this->completeLessons($module);

        $task = $this->requiredModuleTask($module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'max_marks' => 100,
            'pass_mark' => 50,
        ]);

        $this->submission($task, $this->student, [
            'marks_awarded' => 20,
            'graded_at' => now()->subHour(),
            'marks_released_at' => now()->subHour(),
            'returned_at' => now()->subHour(),
        ]);

        $grid = $this->grid->forOffering($this->offering);

        $this->assertTrue($grid['rows'][0]['needs_attention']);
        $this->assertSame(1, $grid['totals']['needing_attention']);
    }

    public function test_a_passing_student_is_not_flagged(): void
    {
        $module = $this->module();
        $this->lessonIn($module);
        $this->completeLessons($module);

        $task = $this->requiredModuleTask($module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'max_marks' => 100,
            'pass_mark' => 50,
        ]);

        $this->submission($task, $this->student, [
            'marks_awarded' => 90,
            'graded_at' => now()->subHour(),
            'marks_released_at' => now()->subHour(),
            'returned_at' => now()->subHour(),
        ]);

        $grid = $this->grid->forOffering($this->offering);

        $this->assertFalse($grid['rows'][0]['needs_attention']);
        $this->assertTrue($grid['rows'][0]['modules'][$module->id]['complete']);
    }

    public function test_the_grid_names_the_topic_the_student_is_currently_on(): void
    {
        $first = $this->topic('Foundations', 1);
        $second = $this->topic('Algebra', 2);
        $this->lessonIn($first);
        $this->lessonIn($second);

        // First topic finished, second untouched.
        $this->completeLessons($first);

        $grid = $this->grid->forOffering($this->offering);

        // "Currently on" is the first topic they have NOT completed - not the first
        // with outstanding work, which for a student who skipped ahead would name
        // a topic they are done with.
        $this->assertSame('Algebra', $grid['rows'][0]['current_module']);
    }

    // ── Who appears at all ──────────────────────────────────────────────────

    public function test_only_confirmed_students_appear(): void
    {
        // A pending or cancelled registration cannot have work to mark, so
        // listing them would show a lecturer a row of nothing and imply they are
        // behind on work they were never given.
        $this->module();

        \App\Models\CourseRegistration::query()->where('course_offering_id', $this->offering->id)->update([
            'status' => 'cancelled',
        ]);

        $grid = $this->grid->forOffering($this->offering);

        $this->assertSame(0, $grid['totals']['students']);
        $this->assertSame([], $grid['rows']);
    }

    public function test_a_lecturer_without_an_allocation_is_refused_the_grid(): void
    {
        $this->module();

        $stranger = User::query()->create([
            'school_id' => $this->school,
            'role_id' => 3,
            'name' => 'Unallocated Stranger',
            'email' => 'progress-stranger@example.test',
            'account_status' => 'active',
        ]);

        // 404, not a refusal page. A 404 does not confirm the Course Offering
        // exists, so the page cannot be used to discover which offerings exist for
        // a lecturer who may not see them. A polite "you are not allocated" would
        // confirm all of that to anyone who guessed an id - and this workspace
        // answers 404 everywhere else.
        $this->actingAs($stranger)
            ->get(route('teacher.course_offerings.progress', $this->offering->id))
            ->assertNotFound();
    }
    public function test_an_offering_with_no_topics_says_so_rather_than_rendering_an_empty_table(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.progress', $this->offering->id))
            ->assertOk()
            ->assertSee('no topics yet', false);
    }

    // ── The single percentage, and what it is not ───────────────────────────

    public function test_the_overall_percentage_is_never_presented_without_its_components(): void
    {
        // There is no governed weighting between a lesson and an assessment, so the
        // blended number is a sorting aid and the page says so. The two component
        // columns must therefore always be present beside it.
        $module = $this->module();
        $this->lessonIn($module);
        $this->completeLessons($module);

        $content = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.progress', $this->offering->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Lessons', $content);
        $this->assertStringContainsString('Assessments', $content);
        $this->assertStringContainsString('not a grade', $content);
    }
}
