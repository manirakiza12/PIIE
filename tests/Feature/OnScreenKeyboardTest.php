<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * An on-screen keyboard for short answers.
 *
 * WHY THIS IS ASSERTED ON THE EMITTED PAGE AND THE SCRIPT, NOT IN A BROWSER
 *
 * The behaviour is JavaScript, and neither Blade nor PHPUnit can run it. So the
 * tests pin the two things that decide whether it can work at all: that the script
 * is delivered and parses, and that the page offers the fields it attaches to.
 *
 * The properties worth defending are in the script itself and are stated here
 * because they are the ones a future edit would plausibly break:
 *
 *   1. OPT-IN. It never intercepts keystrokes and never traps focus. A student
 *      with a physical keyboard must be completely unaffected, which is why there
 *      is no `keydown` listener on `document` except `Escape`.
 *
 *   2. IT FIRES A REAL `input` EVENT. Assigning `field.value` updates the text and
 *      autosaves nothing - so a student could type a whole answer on the
 *      on-screen keyboard, press Submit, and lose every character while the
 *      indicator said "Saved". The two features only work together because the
 *      event is dispatched.
 *
 *   3. IT IS CARET-AWARE. Appending at the end is the version of this that gets a
 *      student to stop using it.
 *
 *   4. IT IS ADDITIVE. The answer can still be typed, saved and submitted with the
 *      script absent entirely.
 */
class OnScreenKeyboardTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignmentSetUp();
    }

    private function script(): string
    {
        $path = base_path('public/js/on-screen-keyboard.js');

        $this->assertFileExists($path, 'the on-screen keyboard script must exist');

        return (string) file_get_contents($path);
    }

    private function page(): string
    {
        $assignment = $this->publishedAssignment([
            'title' => 'Short answers',
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);

        $this->question($assignment, ['prompt' => 'Name one cause.']);

        return $this->actingAs($this->student)
            ->get(route('student.courses.assignments.show', [$this->offering->id, $assignment->id]))
            ->assertOk()
            ->getContent();
    }

    // ── It is delivered, and it is valid ────────────────────────────────────

    public function test_the_student_page_loads_the_keyboard(): void
    {
        $this->assertStringContainsString('on-screen-keyboard.js', $this->page());
    }

    public function test_the_keyboard_is_offered_for_a_generic_assignment_too(): void
    {
        // A generic assignment's written response is just as much a short answer.
        // Gating the script on the question-based branch would silently leave it
        // missing from exactly the simpler form.
        $assignment = $this->publishedAssignment([
            'title' => 'A written brief',
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);

        $html = $this->actingAs($this->student)
            ->get(route('student.courses.assignments.show', [$this->offering->id, $assignment->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('on-screen-keyboard.js', $html);
    }

    public function test_the_script_is_valid_javascript(): void
    {
        // A file with unmatched braces is a SYNTAX ERROR: the browser discards the
        // whole script and every feature in it silently does nothing. This is the
        // same class of fault that left the lesson form's step buttons dead, and
        // it survived because Blade only resolves directives and PHPUnit only reads
        // markup - nothing else would notice.
        $source = $this->script();

        $tmp = tempnam(sys_get_temp_dir(), 'osk').'.js';
        file_put_contents($tmp, $source);

        exec('node --check '.escapeshellarg($tmp).' 2>&1', $output, $status);
        unlink($tmp);

        $this->assertSame(0, $status, 'on-screen-keyboard.js must parse: '.implode(' ', $output));
    }

    // ── 1. Opt-in, never in the way ─────────────────────────────────────────

    public function test_it_never_intercepts_keystrokes(): void
    {
        // No `keydown` listener on the document except Escape. A listener that
        // captured letters would make a student with a working keyboard type into
        // the on-screen panel instead of the field - which is how an assistive
        // feature becomes a regression for everyone else.
        $source = $this->script();

        $this->assertSame(
            1,
            preg_match_all("/document\.addEventListener\(\s*'keydown'/", $source),
            'there must be exactly one document-level keydown listener'
        );
        $this->assertSame(
            1,
            preg_match_all("/event\.key === 'Escape'/", $source),
            'the only key this script listens for is Escape'
        );
        $this->assertStringNotContainsString('keypress', $source, 'no keypress handling at all');
    }

    public function test_the_button_is_added_by_script_not_baked_into_the_markup(): void
    {
        // The page must not ship a button that does nothing without JavaScript, and
        // the script must not need the server to have known which fields to offer.
        $this->assertStringContainsString('data-osk-trigger', $this->script());
        $this->assertStringNotContainsString('data-osk-trigger', $this->page());
    }

    // ── 2. The event autosave needs ─────────────────────────────────────────

    public function test_every_insertion_dispatches_a_real_input_event(): void
    {
        // THE property that makes the two features one feature. Without it, an
        // answer typed on the on-screen keyboard is text the server never sees -
        // and the autosave indicator would still say "Saved", because the request
        // it sent contained the older value and the request succeeded.
        $source = $this->script();

        $this->assertGreaterThanOrEqual(
            2,
            preg_match_all("/dispatchEvent\(new Event\('input'/", $source),
            'both insert() and backspace() must dispatch an input event'
        );
        $this->assertStringContainsString("bubbles: true", $source, 'the event must bubble to the form');
    }

    public function test_insertion_respects_maxlength(): void
    {
        // The browser enforces `maxlength` for physical keystrokes. A field that
        // accepted one more character only on the on-screen keyboard produces a
        // submission the server refuses, with nothing on screen to explain it.
        $source = $this->script();

        $this->assertStringContainsString("getAttribute('maxlength')", $source);
    }

    // ── 3. Caret-aware ──────────────────────────────────────────────────────

    public function test_it_replaces_a_selection_rather_than_only_appending(): void
    {
        $source = $this->script();

        $this->assertStringContainsString('selectionStart', $source);
        $this->assertStringContainsString('selectionEnd', $source);
        // The value is rebuilt as before + text + after, not value + text.
        $this->assertStringContainsString('value.slice(0, start) + text + value.slice(end)', $source);
    }

    public function test_backspace_deletes_before_the_caret_and_does_nothing_at_the_start(): void
    {
        $source = $this->script();

        // Deleting the character AFTER the caret when there is no selection is the
        // classic on-screen-keyboard bug: a student fixing the first letter of a
        // word loses the last one instead.
        $this->assertStringContainsString('start -= 1;', $source);
        $this->assertStringContainsString('if (start === 0) { return; }', $source);
    }

    // ── 4. Additive ─────────────────────────────────────────────────────────

    public function test_the_answer_can_still_be_typed_and_submitted_without_the_script(): void
    {
        // Nothing about this feature may become the only way to answer. The form,
        // the fields and the submit button are the server's contract; the keyboard
        // is one optional way of driving them.
        $html = $this->page();

        // The submit form, its CSRF token and the per-question answer fields are
        // the server's contract. The keyboard is one optional way of driving them,
        // and must never be the only one.
        $this->assertStringContainsString('<form method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('idempotency_key', $html);
        $this->assertStringContainsString('[text]', $html);

        // And the rich editor's own symbol toolbar remains, for the answers this
        // deliberately does not attempt to serve.
        $this->assertStringContainsString('academic-editor', $html);
    }

    public function test_it_stays_out_of_the_rich_editor(): void
    {
        // The academic editor keeps its own symbol toolbar, and its source field is
        // synchronised into that toolbar's state. Writing into it from here would
        // be a third party editing something two other components own.
        $source = $this->script();

        $this->assertStringContainsString('insideRichEditor', $source);
        $this->assertStringContainsString('.piie-editor-shell', $source);
    }

    // ── What it actually offers ─────────────────────────────────────────────

    public function test_it_offers_the_characters_a_maths_or_business_answer_needs(): void
    {
        $source = $this->script();

        // The currency and the arithmetic operators are the characters that are
        // hardest to produce on a touchscreen and most common in the answers here.
        foreach (['UGX', '%', '+', '×', '÷', '='] as $character) {
            $this->assertStringContainsString("'".$character."'", $source);
        }
    }

    public function test_no_symbol_is_offered_twice(): void
    {
        // The first draft of the symbols layer had `$`, `=`, `÷`, `(`, `)` and `£`
        // twice, which cost six keys' worth of row and read as a broken keyboard.
        // A duplicate is worse than a missing key: a student who presses it twice
        // by accident cannot tell whether the second press was ignored or lost.
        $symbols = $this->symbols();

        $this->assertSame(
            [],
            array_values(array_diff_assoc($symbols, array_unique($symbols))),
            'the symbols layer must contain no duplicates'
        );
    }

    /**
     * The symbols layer, read out of the script.
     *
     * THE ARRAY TERMINATOR IS ANCHORED, AND THAT IS THE WHOLE TRICK.
     *
     * The obvious regex - `/symbols:\s*\[(.*?)\]/s` - stops at the first `]`,
     * which is the one inside the layer's own `'[', ']'` pair. It therefore read 8
     * of the 40 keys, and a duplicate injected anywhere in the other 32 was
     * invisible: the test passed with a defect it claimed to be checking. It was
     * only caught by injecting a duplicate and watching the test still pass.
     *
     * So the match ends at `]` followed by the object's closing brace, which no
     * string in the layer can produce.
     */
    private function symbols(): array
    {
        $source = $this->script();

        $matched = preg_match('/symbols:\s*\[(.*?)\]\s*\n\s*\}/s', $source, $matches);

        $this->assertSame(1, $matched, 'the symbols layer must be readable out of the script');

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $matches[1], $found);

        // Guarded, because a regex that quietly matched a fragment is exactly the
        // failure this method just had once.
        $this->assertGreaterThan(
            30,
            count($found[1]),
            'the whole symbols layer must be extracted, not a fragment'
        );

        return array_map(
            fn (string $ch) => str_replace(["\\'", '\\\\'], ["'", '\\'], $ch),
            $found[1]
        );
    }

    public function test_it_names_the_field_it_is_typing_into(): void
    {
        // "Typing into: Question 2" is what makes this usable by a student who
        // cannot see which box the keys will reach.
        $source = $this->script();

        $this->assertStringContainsString('Typing into:', $source);
        $this->assertStringContainsString('field.labels', $source);
    }

    public function test_escape_and_a_close_button_both_dismiss_it(): void
    {
        $source = $this->script();

        $this->assertStringContainsString("'Hide the on-screen keyboard'", $source);
        $this->assertStringContainsString('closePanel', $source);
        // Focus goes back to the field. A keyboard that closes with focus on the
        // document body leaves a screen-reader user with no idea where they are,
        // and stops a physical keyboard user carrying on.
        $this->assertStringContainsString('target.focus()', $source);
    }
}