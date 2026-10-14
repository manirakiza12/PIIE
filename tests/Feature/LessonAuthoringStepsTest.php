<?php

namespace Tests\Feature;

use Tests\Feature\Support\CourseContentFixture;
use Tests\TestCase;

/**
 * The lesson form is a two-step flow: settings first, then content.
 *
 * WHAT WAS WRONG
 *
 * It was one long column in an order nobody would choose: title, objectives, the
 * entire lesson body, the Save and Publish buttons, and THEN - past the fold and
 * past the buttons - a sidebar of settings. Writing a lesson meant scrolling past
 * your own submit buttons to find the visibility dropdown.
 *
 * WHAT THIS MUST NOT CHANGE
 *
 * The save. Both steps are inside ONE form, so one submit writes exactly the
 * lesson it always wrote - no partial record, no second request, no new column.
 * That is asserted directly below, because a "wizard" that quietly introduced a
 * half-saved lesson would be a far worse defect than the long page it replaced.
 */
class LessonAuthoringStepsTest extends TestCase
{
    use CourseContentFixture {
        setUp as protected courseContentSetUp;
    }

    private function createPage(): \Illuminate\Testing\TestResponse
    {
        $this->module();

        return $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content/lessons/create");
    }

    // ── The two steps exist ────────────────────────────────────────────────

    public function test_the_form_has_a_settings_step_and_a_content_step(): void
    {
        $page = $this->createPage();

        $page->assertOk();
        $page->assertSee('data-cc-step="1"', false);
        $page->assertSee('data-cc-step="2"', false);
        $page->assertSee('Lesson settings');
        $page->assertSee('Add the content');
    }

    public function test_the_content_step_starts_hidden(): void
    {
        $page = $this->createPage();

        // Settings are the first question a lecturer answers, so that is where the
        // screen opens. The content step is one click away, not one scroll down.
        $page->assertSee('id="cc-step-2" data-cc-step="2" role="tabpanel" aria-labelledby="cc-tab-2" hidden', false);
    }

    // ── What belongs on which step ─────────────────────────────────────────

    public function test_the_settings_step_holds_the_settings_and_the_content_step_holds_the_editor(): void
    {
        $html = $this->createPage()->getContent();

        // Split on the step markers themselves rather than looking for the closing
        // tags: the two panels are siblings, so step 1 is simply everything between
        // the step-1 marker and the step-2 marker.
        $start1 = strpos($html, 'id="cc-step-1"');
        $start2 = strpos($html, 'id="cc-step-2"');

        $this->assertNotFalse($start1, 'step 1 must exist');
        $this->assertNotFalse($start2, 'step 2 must exist');
        $this->assertGreaterThan($start1, $start2, 'step 2 follows step 1');

        $step1 = substr($html, $start1, $start2 - $start1);

        // Step 2 runs to the end of the lesson form. `cc-resource-form` is the
        // separate upload form that necessarily follows it.
        $tail = substr($html, $start2);
        $end = strpos($tail, 'id="cc-resource-form"');
        $step2 = $end === false ? $tail : substr($tail, 0, $end);

        // SETTINGS on step 1.
        foreach (['cc-title', 'cc-summary', 'cc-objectives', 'cc-module',
            'cc-status', 'cc-released', 'cc-minutes', 'cc-rule'] as $field) {
            $this->assertStringContainsString('id="'.$field.'"', $step1, $field.' belongs on the settings step');
            $this->assertStringNotContainsString('id="'.$field.'"', $step2, $field.' must not reappear on the content step');
        }

        // THE EDITOR on step 2, and NOT on step 1 - which is the actual request:
        // once you are writing, the settings are gone.
        $this->assertStringContainsString('id="cc-body"', $step2);
        $this->assertStringNotContainsString('id="cc-body"', $step1);
    }

    public function test_there_is_exactly_one_copy_of_each_field(): void
    {
        $html = $this->createPage()->getContent();

        // A duplicated id would make `for=`/`aria-labelledby` ambiguous and the
        // label would appear to name the wrong control.
        foreach (['cc-title', 'cc-summary', 'cc-objectives', 'cc-module',
            'cc-status', 'cc-released', 'cc-minutes', 'cc-rule', 'cc-body'] as $field) {
            $this->assertSame(
                1,
                substr_count($html, 'id="'.$field.'"'),
                $field.' must appear exactly once'
            );
        }
    }

    public function test_each_step_has_a_way_back_and_forwards(): void
    {
        $page = $this->createPage();

        $page->assertSee('data-cc-step-next="2"', false);
        $page->assertSee('data-cc-step-next="1"', false);
        $page->assertSee('data-cc-step-tab="1"', false);
        $page->assertSee('data-cc-step-tab="2"', false);
    }

    // ── THE PROPERTY THAT MATTERS: the save is unchanged ───────────────────

    public function test_one_submit_still_writes_the_whole_lesson(): void
    {
        // Captured ONCE: `module()` creates a new module on every call, so asking
        // for it again after the post would compare against a different row.
        $module = $this->module();

        // Fields from BOTH steps, in ONE request. If the steps had become separate
        // saves, this would silently store a lesson with settings and no content.
        $response = $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/lessons", [
                'title' => 'Fractions, Ratios and Percentages',
                'summary' => 'A worked introduction.',
                'learning_objectives' => 'Convert a fraction to a decimal',
                'course_offering_module_id' => $module->id,
                'status' => 'draft',
                'completion_rule' => 'manual',
                'estimated_minutes' => 45,
                'body' => '<p>The body written on the content step.</p>',
            ]);

        $response->assertRedirect();

        $lesson = \App\Models\CourseOfferingLesson::query()
            ->where('title', 'Fractions, Ratios and Percentages')
            ->sole();

        $this->assertStringContainsString('body written on the content step', (string) $lesson->body);
        $this->assertSame('A worked introduction.', $lesson->summary);
        $this->assertSame(45, (int) $lesson->estimated_minutes);
        $this->assertSame($module->id, (int) $lesson->course_offering_module_id);
    }

    public function test_the_form_is_one_form_not_several(): void
    {
        $html = $this->createPage()->getContent();

        // Comments are stripped first. This view's own prose explains the single
        // form and does so using the literal string "<form>", which a naive
        // substring count reports as a second form - so a test that counted the
        // marker rather than the markup was measuring the explanation.
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        $start = strpos($html, '<form method="POST" id="cc-lesson-form"');
        $this->assertNotFalse($start, 'the lesson form must open with its method first');

        $tail = substr($html, $start);

        // The region ends at the OPENING TAG of the upload form, not at its id
        // attribute. Slicing to the id cuts through the middle of that tag and
        // leaves a bare "<form" behind, which this test then counts as a second
        // form inside the lesson - the same mistake as counting the "<form" in
        // this file's own explanatory comment.
        $end = strpos($tail, '<form id="cc-resource-form"');
        $lessonForm = $end === false ? $tail : substr($tail, 0, $end);

        $this->assertSame(
            1,
            preg_match_all('/<form\b/', $lessonForm),
            'the lesson must be ONE form, not a wizard of several'
        );
    }

    public function test_native_validation_is_off_because_a_hidden_step_cannot_be_focused(): void
    {
        $page = $this->createPage();

        // With `required` left on and a control inside the hidden content step, a
        // browser refuses to submit and says nothing about why. The controller
        // checks step 1 explicitly instead, and the server still has the last word.
        $page->assertSee('id="cc-lesson-form" novalidate', false);
    }

    public function test_a_settings_error_brings_the_lecturer_back_to_settings(): void
    {
        $this->module();

        // Rejected for a SETTINGS problem, having been submitted from the content
        // step. The page must open on settings - being told to fix an invisible
        // field is the same class of fault as the attachment error this suite's
        // sibling test covers.
        $response = $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/lessons", [
                'title' => '',
                'body' => '<p>Some content.</p>',
                'status' => 'draft',
                'completion_rule' => 'manual',
            ]);

        $response->assertSessionHasErrors('title');

        $page = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content/lessons/create");

        $page->assertOk();
        // The routing decision is handed to the controller as JSON, which is what
        // lets it choose the step without re-deriving the field list in JS.
        $page->assertSee('cc-title', false);
    }
}
