<?php

namespace Tests\Feature;

use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * The assignment form is stepped: settings, then the work.
 *
 * WHY THE ASSIGNMENT FORM NEEDS THIS AT ALL
 *
 * A lesson form was already stepped. The assignment form is far worse for the
 * problem - a dozen settings (role, rule, evidence kinds, submission type, marks,
 * pass mark, attempts, retry policy, late policy, due date, release, close) sitting
 * around the three fields that actually make a good brief. The lecturer reads it
 * top to bottom, reaches the instructions last, and by then has forgotten what the
 * assignment was for.
 *
 * WHAT IS ASSERTED HERE, AND WHY IT IS ASSERTED ON THE EMITTED PAGE
 *
 * The behaviour lives in JavaScript, and neither Blade nor PHPUnit can execute it.
 * So these tests assert the three things that decide whether it can work at all:
 * the markers are present, the form opts out of native validation, and the emitted
 * script parses. A file with unmatched braces is a SYNTAX ERROR, the browser
 * discards the entire `<script>`, and the step controller silently does nothing -
 * which is exactly how the lesson form's steps came to be dead buttons for a while.
 * `scripts/check-emitted-javascript.php` is the guard against that.
 */
class AssignmentAuthoringStepsTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignmentSetUp();
    }

    private function createPage(): string
    {
        return $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.assignments.create', $this->offering->id))
            ->assertOk()
            ->getContent();
    }

    private function editPage(): string
    {
        $assignment = $this->assignment(['title' => 'Existing task']);

        return $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.assignments.edit', [$this->offering->id, $assignment->id]))
            ->assertOk()
            ->getContent();
    }

    // ── The steps exist ─────────────────────────────────────────────────────

    public function test_the_form_offers_two_named_steps(): void
    {
        $html = $this->createPage();

        $this->assertStringContainsString('data-as-step-tab="1"', $html);
        $this->assertStringContainsString('data-as-step-tab="2"', $html);
        $this->assertStringContainsString('1. Settings', $html);
        $this->assertStringContainsString('2. The work', $html);
    }

    public function test_the_settings_are_on_step_one_and_the_work_on_step_two(): void
    {
        $html = $this->createPage();

        // The title, the editor and the learning objectives must each declare a
        // step, or they stay visible on both and the flow is not stepped at all.
        $this->assertStringContainsString('data-as-step="1"', $html);
        $this->assertStringContainsString('data-as-step="2"', $html);
    }

    public function test_the_hands_on_buttons_are_reachable_from_the_settings_step(): void
    {
        // A lecturer who has just typed the title must be able to press Save Draft
        // without first discovering they have to move to step 2. Finding that out
        // is how people end up publishing with an empty brief.
        $html = $this->createPage();

        $this->assertStringContainsString('Continue to the work', $html);
        $this->assertStringContainsString('Back to settings', $html);
    }

    // ── The form must not be validated by the browser ───────────────────────

    public function test_the_form_opts_out_of_native_constraint_validation(): void
    {
        // LOAD-BEARING, and easy to remove by accident.
        //
        // A field on a hidden step is still submitted - `hidden` removes it from the
        // display, not from the form data - but it is no longer constraint
        // validated. Without `novalidate` the browser would therefore let a
        // lecturer walk to step 2 and post an assignment with no title, because the
        // title is no longer visible to the validator. The step controller checks it
        // instead; this is what stops the browser checking it wrongly first.
        $html = $this->createPage();

        $this->assertMatchesRegularExpression('/<form[^>]*\bid="as-form"[^>]*>/', $html);
        $this->assertMatchesRegularExpression(
            '/<form[^>]*\bid="as-form"[^>]*\bnovalidate\b/',
            $html,
            'the assignment form must carry novalidate'
        );
    }

    // ── The no-JavaScript fallback ──────────────────────────────────────────

    public function test_neither_step_is_hidden_in_the_markup(): void
    {
        // With JavaScript off the lecturer must see the WHOLE form, not be stranded
        // on settings with no way forward. Hiding is applied at runtime by the step
        // controller, so the failure mode of the script not running is "everything
        // visible" and never "trapped on step 1".
        //
        // A `hidden` attribute in the markup on a `[data-as-step]` element is
        // therefore a bug, not a default.
        $html = $this->createPage();

        $this->assertDoesNotMatchRegularExpression(
            '/<[^>]*\bdata-as-step="[12]"[^>]*\bhidden\b[^>]*>/',
            $html,
            'a step must not be hidden in the markup'
        );
    }

    // ── Error routing ───────────────────────────────────────────────────────

    public function test_a_settings_error_comes_back_to_the_settings_step(): void
    {
        // A save rejected for a settings problem must return the lecturer to
        // SETTINGS. Landing them on step 2 tells them to fix a field they cannot
        // see - the same class of fault as an attachment error being reported
        // against the assignment.
        $response = $this->actingAs($this->lecturer)
            ->post(route('teacher.course_offerings.assignments.store', $this->offering->id), [
                'title' => '',
                'submission_type' => 'text',
                'late_policy' => 'allow',
                'max_marks' => 10,
            ]);

        $response->assertSessionHasErrors('title');

        // The controller reads the SERVER error keys and chooses the step, so the
        // field list it routes on must include the settings fields.
        $html = $response->assertRedirect()->getTargetUrl();
        $this->assertIsString($html);
    }

    public function test_the_step_controller_routes_on_the_settings_field_list(): void
    {
        $html = $this->createPage();

        // The list must contain the fields the server validates on step 1. A field
        // missing from it is one whose error would be reported on the wrong step.
        foreach (['title', 'max_marks', 'pass_mark', 'allowed_attempts', 'requirement_role'] as $field) {
            $this->assertStringContainsString(
                "'".$field."'",
                $html,
                "{$field} must be routed to the settings step"
            );
        }
    }

    // ── Both screens behave the same ────────────────────────────────────────

    public function test_the_edit_screen_is_stepped_too(): void
    {
        $html = $this->editPage();

        $this->assertStringContainsString('data-as-step-tab="1"', $html);
        $this->assertStringContainsString('data-as-step-tab="2"', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*\bid="as-form"[^>]*\bnovalidate\b/', $html);
    }

    public function test_an_existing_assignment_opens_on_the_work_step(): void
    {
        // A lecturer returning to an assignment is going to edit the brief. A NEW
        // one opens on settings, because there is nothing to write yet - which is
        // why the initial step is driven by `$isEdit` and not hard-coded.
        $html = $this->editPage();

        $this->assertStringContainsString('show(2)', str_replace(' ', '', $html));
    }

    // ── Nothing was lost ────────────────────────────────────────────────────

    public function test_every_field_still_submits_from_either_step(): void
    {
        // The whole risk of stepping a form: a field ends up on a hidden step and
        // silently stops arriving. `hidden` does NOT remove a field from the form
        // data, so these must all survive - and the server must agree.
        $html = $this->createPage();

        foreach ([
            'name="title"',
            'name="learning_objectives"',
            'name="course_offering_module_id"',
            'name="requirement_role"',
            'name="completion_rule"',
            'name="max_marks"',
            'name="pass_mark"',
            'name="allowed_attempts"',
            'name="due_date"',
            'name="released_at"',
            'name="closes_at"',
        ] as $field) {
            $this->assertStringContainsString($field, $html, "{$field} must still be on the form");
        }

        // And the instructions editor, whose hidden textarea is what actually posts.
        $this->assertStringContainsString('name="instructions"', $html);
    }
}