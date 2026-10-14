<?php

namespace Tests\Feature;

use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * The assignment Instructions editor is a real editor, not a failure panel.
 *
 * THE FAULT
 *
 * This form carried its own copy of the Summernote bootstrap, inside a div it
 * called `.as-editor-shell`. When the layout stopped loading Summernote globally,
 * `$.fn.summernote` came back undefined - and that bootstrap's first act was to
 * REPLACE THE SHELL WITH A RED PANEL reading "The editor could not be loaded.
 * Your work has not been lost, but please reload this page before writing."
 *
 * So a lecturer could not write a single word of assignment instructions. The
 * page rendered, every other field worked, and the one field the screen exists
 * for was unusable. That is the whole feature failing silently behind a notice
 * that reads like a temporary glitch.
 *
 * WHY A TEST HAD TO BE WRITTEN
 *
 * The identical fault killed the lesson form earlier the same day, and no test
 * caught it - Blade does not execute JavaScript, and the feature tests assert on
 * markup. `scripts/check-emitted-javascript.php` now parses the emitted script,
 * which catches the SYNTAX half of this family of faults; this test catches the
 * other half, which is an editor that is present in the markup and simply not
 * working.
 */
class AssignmentEditorSurfaceTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    private function newAssignmentPage(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.assignments.create', [$this->offering->id]));
    }

    public function test_the_instructions_field_renders_the_shared_editor(): void
    {
        $page = $this->newAssignmentPage();

        $page->assertOk();
        // The component emits this shell, and the bundle that builds the editor.
        $page->assertSee('piie-editor-shell', false);
        $page->assertSee('academic-editor.js', false);
    }

    public function test_the_instructions_field_is_still_the_submitted_field(): void
    {
        $page = $this->newAssignmentPage();

        // The editor is a face on a real textarea. If the name were lost, the
        // instructions would silently stop being saved and nothing would say so.
        $page->assertSee('id="as-instructions"', false);
        $page->assertSee('name="instructions"', false);
    }

    public function test_the_dead_editor_bootstrap_is_gone(): void
    {
        $page = $this->newAssignmentPage();

        // Comments are stripped first - BOTH syntaxes. This file's own explanation
        // of the fault NAMES the old class and quotes the old message verbatim,
        // and the stylesheet's own comment explains why its selector changed. An
        // unstripped negative assertion matches that prose and fails on the very
        // fix it is checking for.
        //
        // `/* */` is stripped as well as `<!-- -->`: a CSS comment renders into
        // the page exactly like an HTML one, and a test that only knows about one
        // of them reports a real CSS rule as a leak.
        $html = (string) preg_replace(
            ['/<!--.*?-->/s', '/\/\*.*?\*\//s'],
            '',
            $page->getContent()
        );

        // The hand-rolled wrapper and its failure notice both belonged to the
        // deleted bootstrap. Leaving the notice would be worse than useless: it
        // would still be the thing that replaces the editor if anything ever
        // looked for that class again.
        $this->assertStringNotContainsString('as-editor-shell', (string) $html);
        $this->assertStringNotContainsString('The editor could not be loaded', (string) $html);
    }

    public function test_there_is_exactly_one_editor_on_the_page(): void
    {
        $html = preg_replace('/<!--.*?-->/s', '', $this->newAssignmentPage()->getContent());

        // Two editors is what a re-initialised field looks like, and it is what a
        // lecturer would see as a duplicated toolbar over an empty box.
        $this->assertSame(
            1,
            substr_count((string) $html, 'data-piie-editor'),
            'exactly one editor source field may exist'
        );
    }

    public function test_instructions_are_saved_through_the_editor(): void
    {
        // End to end, because a working-looking editor that does not save is the
        // same defect wearing a different hat.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.course_offerings.assignments.store', [$this->offering->id]), [
                'title' => 'Business ratios',
                'instructions' => '<p>Calculate the ratio of margin to revenue.</p>',
                'max_marks' => 20,
                'submission_type' => \App\Models\Assignment::SUBMISSION_FILE_AND_TEXT,
                'late_policy' => \App\Models\Assignment::LATE_ALLOWED,
                'status' => \App\Models\Assignment::STATUS_DRAFT,
                'requirement_role' => \App\Models\Assignment::ROLE_OPTIONAL,
                'completion_rule' => \App\Models\Assignment::RULE_SUBMISSION,
            ])
            ->assertSessionHasNoErrors();

        $assignment = \App\Models\Assignment::query()
            ->where('title', 'Business ratios')
            ->sole();

        $this->assertStringContainsString('Calculate the ratio of margin', (string) $assignment->instructions);
    }
}
