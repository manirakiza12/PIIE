<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OnlineExams\QuestionPrompt;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * THE QUESTION-CREATION FORM AS THE BROWSER ACTUALLY GETS IT.
 *
 * ── WHY THIS FILE EXISTS ───────────────────────────────────────────────────
 *
 * Exam 21, "final test". A lecturer typed a question into the visible rich-text
 * editor, pressed Add Question, and Laravel answered:
 *
 *     "The question field is required.
 *      Write the question. A question with no text is not a question."
 *
 * The server was right. `question` genuinely arrived empty, because the browser
 * serialises a form from its OWN field values and nothing had copied the editable
 * region into the `name="question"` textarea underneath it.
 *
 * `tests/js/academic-editor-bridge.test.js` proves the editor module now performs
 * that copy. This file proves the OTHER half, which a JavaScript harness cannot
 * reach: that the real Blade page actually renders the field, inside a real form,
 * with the attributes the module depends on. A module that fixed the sync would
 * still fail in production if the form did not give it something to sync into.
 *
 * So this file asserts the CONTRACT between the view and the script:
 *
 *   - exactly ONE source textarea named `question`, carrying `data-piie-editor`;
 *   - it sits inside a `.piie-editor-shell`, which is how the script finds the
 *     editable region belonging to it;
 *   - it is inside a real `<form method="POST">` whose action is the store route;
 *   - Add Question is `type="submit"`, so the capture-phase sweep runs before the
 *     browser serialises — a scripted handler would read the field too early;
 *   - `academic-editor.js` is included ONCE, and the Summernote bundle likewise, so
 *     one question cannot end up with two mounted editors and two toolbars.
 */
class OnlineExamQuestionEditorFormTest extends TestCase
{
    use OnlineExamTestHelper;

    private int $lecturerId;
    private int $classId;
    private int $examId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();

        $this->classId = $this->makeClass(1);
        $this->lecturerId = (int) $this->makeUser(3, 1, 'active', 'Daniel Okello')->id;

        DB::table('teacher_permissions')->insert([
            'class_id' => $this->classId,
            'section_id' => 1,
            'school_id' => 1,
            'teacher_id' => $this->lecturerId,
            'marks' => 1,
            'attendance' => 1,
            'updated_at' => now(),
        ]);

        $this->examId = $this->makeExam([
            'title' => 'final test',
            'class_id' => $this->classId,
            'created_by' => $this->lecturerId,
            'creator_id' => $this->lecturerId,
        ]);
    }

    private function asLecturer(): self
    {
        $this->actingAs(User::find($this->lecturerId));

        return $this;
    }

    /**
     * The rendered question-creation form, and nothing else.
     *
     * Scoped to the form so that "there is exactly one editor for the question" is a
     * claim about THIS form rather than about the whole page, which also carries a
     * reorder form and an edit form per existing question.
     */
    private function creationForm(): string
    {
        $html = $this->asLecturer()
            ->get(route('teacher.online_exams.questions.index', $this->examId))
            ->assertOk()
            ->getContent();

        $action = e(route('teacher.online_exams.questions.store', $this->examId));

        // Blade escapes nothing in an action beyond the URL itself, so match on the
        // action attribute rather than the whole tag, whose attribute order is not part
        // of any contract.
        $at = strpos($html, 'action="'.$action.'"');

        $this->assertNotFalse($at, 'The page must render the real store endpoint, or the sweep syncs a field nothing posts.');

        // Cut from the opening `<form` tag, not from the action attribute, or `method`
        // — which is written before it — would be sliced off and never asserted.
        $start = strrpos(substr($html, 0, $at), '<form');

        $this->assertNotFalse($start, 'The store form is missing from the page.');

        // The form closes at its first `</form>`. The Add Question button sits inside
        // it, so cutting here cannot accidentally exclude the control under test.
        $end = strpos($html, '</form>', $at);

        $this->assertNotFalse($end, 'The store form is never closed.');

        return substr($html, $start, $end - $start);
    }

    // ── 1. THE FIELD THE BROWSER WILL SERIALISE ─────────────────────────────────

    public function test_the_question_field_is_a_source_textarea_the_editor_module_can_find(): void
    {
        $form = $this->creationForm();

        $this->assertStringContainsString(
            'name="question"',
            $form,
            'The posted field must be named `question`, because that is the name the request and the rule read.'
        );

        $this->assertStringContainsString(
            'data-piie-editor',
            $form,
            'The field must be marked `data-piie-editor`, or the sweep skips it silently — which is what happened on exam 21.'
        );

        // The module resolves each editable through the field's own shell rather than
        // by guessing at "the editor on the page", so the shell is load-bearing.
        $this->assertMatchesRegularExpression(
            '/class="piie-editor-shell"[\s\S]*?data-piie-editor/',
            $form,
            'The source textarea must sit inside a `.piie-editor-shell`, which is how its editable region is found.'
        );

        // Exactly one. Two editors for one question means two toolbars, and whichever
        // one is not the bound field silently swallows everything typed into it.
        $this->assertSame(
            1,
            substr_count($form, 'name="question"'),
            'One question must produce exactly one posted field. Two would mean two toolbars for one question.'
        );

        $this->assertSame(
            1,
            substr_count($form, 'data-piie-editor'),
            'The creation form must mount exactly one editor. A second instance for the same question is a duplicate toolbar.'
        );
    }

    // ── 2. A REAL SUBMIT, SO THE CAPTURE-PHASE SWEEP IS REACHED ────────────────

    public function test_add_question_is_a_real_submit_inside_a_post_form(): void
    {
        $form = $this->creationForm();

        $this->assertStringContainsString(
            'method="POST"',
            $form,
            'The form must be a real POST. A scripted submit would build its own FormData and read the field before the sweep.'
        );

        $this->assertMatchesRegularExpression(
            '/<button[^>]*type="submit"[^>]*>/',
            $form,
            'Add Question must be `type="submit"`, so the browser fires `submit` and the sweep runs before serialisation.'
        );

        // `@csrf` is a Blade directive; what reaches the browser is a hidden `_token` field.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="hidden"[^>]*name="_token"/',
            $form,
            'The form must carry its CSRF token, or every question save is refused for the wrong reason.'
        );
    }

    // ── 3. THE SCRIPT IS LOADED ONCE, AND IT IS THE ONE THAT SYNCS ────────────

    public function test_the_editor_module_is_loaded_exactly_once_on_the_questions_page(): void
    {
        $html = $this->asLecturer()
            ->get(route('teacher.online_exams.questions.index', $this->examId))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'js/academic-editor.js'),
            'The editor module must load once. Twice would register two submit sweeps and mount editors twice over.'
        );

        $this->assertSame(
            1,
            substr_count($html, 'summernote-lite.min.js'),
            'Summernote must load once, or a second copy re-initialises over the first and the getter stops agreeing with the DOM.'
        );

        // The page must not carry its own inline editor initialisation. That was the
        // shape of the original defect: a second editor built by page-specific script,
        // over the same field, which the field's own editor knew nothing about.
        $this->assertStringNotContainsString(
            '.summernote(',
            $html,
            'The page must not initialise Summernote itself. One editor, built by the one module, per field.'
        );
    }

    // ── 4. THE VALUE IS RENDERED INSIDE THE FIELD, NOT ONLY IN THE EDITOR ──────

    public function test_an_existing_questions_text_is_rendered_as_the_fields_own_value(): void
    {
        $statement = '<p>State the formula for compound interest.</p>';

        $this->makeQuestion($this->examId, [
            'question' => $statement,
            'type' => 'essay',
            'marks' => 5,
            'sort_order' => 1,
        ]);

        $html = $this->asLecturer()
            ->get(route('teacher.online_exams.questions.index', $this->examId))
            ->assertOk()
            ->getContent();

        // The edit form for the existing question. Progressive enhancement: a browser
        // that never runs the script shows the last saved text, so an unrelated failure
        // cannot present an empty box.
        $this->assertStringContainsString(
            $statement,
            $html,
            'A saved statement must be rendered into the field itself, so a failed script cannot present an empty box.'
        );
    }

    // ── 5. AND THE ROUND TRIP THAT FAILED ON EXAM 21 ──────────────────────────

    public function test_typed_rich_text_reaches_the_database_through_the_real_endpoint(): void
    {
        // What the fixed sweep writes into `area.value` at submit time.
        $typed = '<p>State the formula for compound interest.</p>';

        $response = $this->asLecturer()->post(
            route('teacher.online_exams.questions.store', $this->examId),
            ['question' => $typed, 'type' => 'short_answer', 'marks' => 5]
        );

        $response->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('online_exam_questions')->where('online_exam_id', $this->examId)->count());

        $stored = DB::table('online_exam_questions')->where('online_exam_id', $this->examId)->first();

        $this->assertStringContainsString(
            'State the formula for compound interest.',
            strip_tags((string) $stored->question),
            'The text the lecturer typed must be the text that is stored.'
        );
    }

    public function test_a_blank_document_is_still_refused_after_the_fix(): void
    {
        // The fix must not be a way past the rule. A genuinely empty rich-text
        // document is still refused, and still stores nothing.
        foreach (['<p><br></p>', '', '<p>&nbsp;</p>'] as $blank) {
            $this->asLecturer()->post(
                route('teacher.online_exams.questions.store', $this->examId),
                ['question' => $blank, 'type' => 'short_answer', 'marks' => 5]
            )->assertSessionHasErrors(['question' => QuestionPrompt::MESSAGE]);
        }

        $this->assertSame(
            0,
            DB::table('online_exam_questions')->where('online_exam_id', $this->examId)->count(),
            'A refused question must not be stored.'
        );
    }
}