<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOfferingModule;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * A lecturer can SEE whether a task gates its module.
 *
 * A requirement that exists only in the database and in the edit form is not
 * satisfied. The brief asks that a lecturer "be able to determine whether it is
 * supplementary/optional, or required for module completion" - which means the
 * answer has to be on the LIST they actually read, and on the detail page they
 * actually open.
 *
 * These tests therefore assert on RENDERED TEXT, not on model state. A test
 * asserting `$assignment->isRequiredForModule()` would pass whether or not the
 * view mentioned it at all, which is exactly the gap this closes.
 */
class CourseOfferingModuleTaskVisibilityTest extends TestCase
{
    use AssignmentFixture {
        // ALIASED, and called by name below. A class's own `setUp` always wins over
        // a trait's, so without this the fixture's `setUp` would be shadowed and
        // `parent::setUp()` would reach only the base TestCase - leaving every
        // typed property the fixture exposes uninitialised.
        setUp as protected assignmentSetUp;
    }

    private CourseOfferingModule $module;

    protected function setUp(): void
    {
        $this->assignmentSetUp();

        $this->module = CourseOfferingModule::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'title' => 'Module 1 — Introduction to Mathematics',
            'sequence' => 1,
            'status' => CourseOfferingModule::STATUS_PUBLISHED,
            'released_at' => now()->subDay(),
        ]);
    }

    public function test_the_list_shows_which_module_a_task_belongs_to(): void
    {
        $this->moduleTask($this->module, ['title' => 'Ratios worksheet']);

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments")
            ->assertOk()
            ->assertSee('Ratios worksheet')
            // The module the task is attached to, so it is obviously placed.
            ->assertSee('Module 1 — Introduction to Mathematics');
    }

    public function test_the_list_distinguishes_a_gating_task_from_a_supplementary_one(): void
    {
        $this->moduleTask($this->module, ['title' => 'Optional practice']);
        $this->requiredModuleTask($this->module, ['title' => 'Gating task']);

        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments")
            ->assertOk()
            // The lecturer's own words, not a bare role value.
            ->assertSee('Required for the module')
            ->assertSee('Supplementary');

        $html = $response->getContent();

        // A colour is never the only signal: both words are present, and neither is
        // conveyed by styling alone.
        $this->assertStringContainsString('data-testid="as-module-role"', $html);
        $this->assertStringNotContainsString('requirement_role', $html, 'the raw column name must never be shown');
    }

    public function test_the_list_says_plainly_when_a_task_is_not_attached_to_a_module(): void
    {
        // A task with no module is the NORM, and the list must say so rather than
        // showing an empty cell that reads as something missing.
        $this->publishedAssignment(['title' => 'Course-wide task']);

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments")
            ->assertOk()
            ->assertSee('Course-wide task')
            ->assertSee('Not attached to a module');
    }

    public function test_the_detail_page_states_the_rule_that_satisfies_a_gating_task(): void
    {
        $required = $this->requiredModuleTask($this->module, [
            'title' => 'Gating task',
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
        ]);

        // The STORED value, asserted alongside the rendered text. Proving the rule
        // is only that it is persisted separates "the fixture did not set it" from
        // "the page does not show it" - two very different defects.
        $this->assertSame(Assignment::RULE_RELEASED_MARK, $required->fresh()->completion_rule);
        $this->assertTrue($required->fresh()->isRequiredForModule());

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$required->id}")
            ->assertOk()
            ->assertSee('Required for this module')
            // AND what actually counts as done, so a lecturer is not left guessing
            // what will unblock a student.
            ->assertSee($required->fresh()->completionRuleLabel())
            ->assertSee('not fully complete until this is satisfied');
    }

    public function test_the_detail_page_says_an_optional_task_gates_nothing(): void
    {
        $optional = $this->moduleTask($this->module, ['title' => 'Supplementary practice']);

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$optional->id}")
            ->assertOk()
            ->assertSee('Supplementary')
            ->assertSee('No student is ever blocked by it');
    }

    public function test_the_detail_page_lists_the_other_tasks_on_the_same_module(): void
    {
        $this->moduleTask($this->module, ['title' => 'Practice set A']);
        $required = $this->requiredModuleTask($this->module, ['title' => 'Gating task']);

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$required->id}")
            ->assertOk()
            // The sibling, so a lecturer managing a module sees the whole set.
            ->assertSee('Practice set A')
            ->assertSee('Other tasks on this module');
    }

    public function test_the_detail_page_offers_no_sibling_list_for_an_unattached_task(): void
    {
        $loose = $this->publishedAssignment(['title' => 'Course-wide task']);

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$loose->id}")
            ->assertOk()
            ->assertDontSee('Other tasks on this module');
    }

    public function test_the_authoring_form_offers_the_module_picker_and_both_roles(): void
    {
        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/create")
            ->assertOk()
            ->assertSee('as-module-panel');

        $html = $response->getContent();

        // The picker, with "no module" first and the module listed.
        $this->assertStringContainsString('name="course_offering_module_id"', $html);
        $this->assertStringContainsString('Not attached to a module', $html);
        $this->assertStringContainsString('Module 1 — Introduction to Mathematics', $html);

        // Both roles offered.
        $this->assertStringContainsString('name="requirement_role"', $html);
        $this->assertStringContainsString('Optional', $html);
        $this->assertStringContainsString('Required', $html);

        // A module needs no task, and the form says so.
        $this->assertStringContainsString('A module needs no task.', $html);
    }

    public function test_the_authoring_form_does_not_offer_an_unobservable_completion_rule(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/create")
            ->assertOk()
            ->getContent();

        // `teacher_verification` is declared in the vocabulary but not implemented.
        // Offering it would let a lecturer create a required task that no student
        // could ever satisfy.
        $this->assertStringNotContainsString('teacher_verification', $html);
        $this->assertStringContainsString('Once the student has submitted their work', $html);
        $this->assertStringContainsString('Once the work is marked and the result returned', $html);
    }

    public function test_the_authoring_form_offers_all_six_evidence_kinds_and_no_recorder(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/create")
            ->assertOk()
            ->getContent();

        foreach (['text', 'document', 'image', 'audio', 'video', 'link'] as $kind) {
            $this->assertStringContainsString(
                'value="'.$kind.'"',
                $html,
                "the '{$kind}' evidence kind must be offered"
            );
        }

        // And nothing that claims to record anything. A platform offering a
        // Record button it cannot honour is worse than one that does not.
        foreach (['MediaRecorder', 'getUserMedia', 'startRecording'] as $claim) {
            $this->assertStringNotContainsString($claim, $html);
        }
    }
}
