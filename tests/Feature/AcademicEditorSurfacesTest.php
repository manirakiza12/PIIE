<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentQuestion;
use App\Models\CourseOffering;
use App\Support\Assignments\QuestionResponseService;
use App\Support\Assignments\QuestionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * THE ACADEMIC EDITOR, END TO END.
 *
 * `AcademicRichTextSanitizerTest` covers the filter in isolation. This suite covers
 * the two things that test cannot: that the editor is actually MOUNTED on the
 * surfaces it claims, and that what an author formats survives the whole journey
 * from the toolbar through storage to the reader.
 *
 * ── WHY THE MOUNTING MATTERS AS MUCH AS THE FILTER ─────────────────────────
 *
 * A component can be perfect and used nowhere. Every one of these assertions is
 * about the editor being PRESENT on a page - the field, the `data-piie-editor`
 * marker the script looks for, and the accessible name - because a sanitiser that
 * is fed plain text has nothing to sanitise and no user ever learns they could
 * have had a table.
 *
 * ── AND WHY THE SERVER SIDE IS ASSERTED AGAINST THESE PAYLOADS ─────────────
 *
 * A lecturer typing a question and a student typing an answer produce the same
 * class of markup: a heading, a table, an equation, a superscript. The same filter
 * has to keep the legitimate parts and drop the dangerous ones on BOTH paths, and
 * a rule that held for questions but not for answers would mean a student could put
 * something in their work that a lecturer could not put in a question - which is a
 * strange asymmetry to ship.
 */
class AcademicEditorSurfacesTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    protected function setUp(): void
    {
        $this->assignmentSetUp();
        Storage::fake('local');
    }

    private const K = \App\Models\AssignmentSubmissionItem::class;


    // ══════════════════════════════════════════════════════════════════════
    // 1. THE EDITOR IS MOUNTED WHERE IT IS SUPPOSED TO BE
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_LECTURER_question_builder_mounts_the_editor(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 10]);

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions")
            ->assertOk()
            // The marker the script looks for, so the editor is actually mounted
            // rather than merely described.
            ->assertSee('data-piie-editor', false)
            // And the field it will write into.
            ->assertSee('name="prompt"', false);
    }

    public function test_the_student_answer_field_mounts_the_editor(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 10, 'due_date' => now()->addWeek()]);
        $question = $this->questionAcceptingAnyOf($assignment, [self::K::KIND_TEXT, self::K::KIND_DOCUMENT], [
            'marks' => 10,
            'prompt' => '<p>Explain your working.</p>',
        ]);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('data-piie-editor', false)
            ->assertSee('data-testid="as-question-text-'.$question->id.'"', false);
    }

    public function test_the_LECTURER_marking_view_mounts_the_editor_on_BOTH_feedback_fields(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 10, 'due_date' => now()->addWeek()]);
        $question = $this->questionAcceptingAnyOf($assignment, [self::K::KIND_TEXT, self::K::KIND_DOCUMENT], [
            'marks' => 10,
            'prompt' => '<p>Explain your working.</p>',
        ]);
        $submission = $this->submission($assignment, $this->student);

        $html = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}")
            ->assertOk()
            ->getContent();

        // The per-question note AND the overall comment. Leaving either as a plain
        // textarea would mean a marker has two different tools in one screen for
        // the same act.
        $this->assertStringContainsString('data-testid="as-mark-feedback-'.$question->id.'"', $html);
        $this->assertStringContainsString('data-testid="as-feedback-overall"', $html);
        $this->assertStringContainsString('data-piie-editor', $html);
    }

    public function test_the_editor_ships_its_OWN_assets_rather_than_depending_on_a_layout(): void
    {
        // The student layout loads jQuery and NOT Summernote. A component that
        // assumed its page had already loaded the editor would silently fall back
        // to a plain textarea for every student - which is the whole feature
        // missing, with nothing on the page to say so.
        $this->assertStringNotContainsString(
            'summernote',
            strtolower((string) file_get_contents(resource_path('views/student/navigation.blade.php'))),
            'the student layout has started loading Summernote itself - worth re-checking whether the component still needs to'
        );

        $component = (string) file_get_contents(resource_path('views/components/academic-editor.blade.php'));

        $this->assertStringContainsString('summernote-lite.min.js', $component);
        $this->assertStringContainsString('summernote-lite.min.css', $component);
        $this->assertStringContainsString('summernote-ext-specialchars.js', $component);
        $this->assertStringContainsString('academic-editor.js', $component);
    }

    public function test_the_assets_are_emitted_ONCE_per_page_however_many_editors_it_has(): void
    {
        $component = (string) file_get_contents(resource_path('views/components/academic-editor.blade.php'));

        // `@once`, not a per-instance guard. An instance guard written as a plain
        // variable in a Blade component view does not survive between component
        // instances, because each is rendered in its own data scope - so the assets
        // would be downloaded once per editor.
        $this->assertStringContainsString("@once('piie-academic-editor-assets')", $component);
    }

    public function test_the_editor_offers_every_capability_the_toolbar_needs(): void
    {
        $js = (string) file_get_contents(public_path('js/academic-editor.js'));

        // The toolbar, read from the source rather than from a rendered page: this
        // is a claim about what the editor can do, and the rendered toolbar only
        // exists once Summernote has executed in a browser.
        foreach ([
            'bold', 'italic', 'underline', 'style', 'fontname', 'ul', 'ol',
            'left', 'center', 'right', 'justify', 'link', 'table',
            'piieSymbol', 'piieEquation', 'specialchars',
            'outdent', 'indent', 'blockquote', 'undo', 'redo', 'fullscreen',
            'superscript', 'subscript',
        ] as $capability) {
            $this->assertStringContainsString(
                "'".$capability."'",
                $js,
                'the toolbar does not offer '.$capability
            );
        }
    }

    public function test_mathematical_symbols_and_equations_are_offered_by_the_editor_itself(): void
    {
        $js = (string) file_get_contents(public_path('js/academic-editor.js'));

        // The vendored special-characters plugin is the HTML ENTITY set: quotation
        // marks, currency, accented letters. It contains no mathematics at all.
        // A Business Mathematics lecturer writing a question about inequalities,
        // and a student answering it, had no way to type a sign without a system
        // dialog the page does not control.
        foreach (['&le;', '&ge;', '&ne;', '&times;', '&divide;', '&plusmn;',
            '&radic;', '&infin;', '&sum;', '&int;', '&isin;', '&forall;',
            '&alpha;', '&beta;', '&pi;', '&theta;', '&Sigma;', '&rarr;', '&rArr;'] as $entity) {
            $this->assertStringContainsString($entity, $js, 'missing mathematical symbol '.$entity);
        }

        // And equations are STORED as notation with a plain-text fallback, not
        // rendered - so nothing executes and the notation cannot become an
        // injection point.
        $this->assertStringContainsString('data-latex', $js);
    }

    public function test_images_are_removed_from_the_toolbar_and_from_pastes_when_not_authorised(): void
    {
        $js = (string) file_get_contents(public_path('js/academic-editor.js'));

        // An image is a request the reader's browser makes to a third-party host,
        // on the reader's connection, and it tells that host who opened the page.
        // So the button AND the paste path both have to respect the decision -
        // otherwise copying an image out of a browser bypasses the toolbar.
        $this->assertStringContainsString("if (cfg.allowImages) {", $js);
        $this->assertStringContainsString("if (!cfg || !cfg.allowImages) {", $js);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. A FORMATTED QUESTION SURVIVES STORAGE
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_lecturers_FORMATTED_question_is_stored_formatted(): void
    {
        $rich = '<h3>Set out your working</h3>'
            .'<p>Revenue was <strong>9,000</strong> and cost <em>4,800</em>. Given '
            .'y = 2<sup>3</sup> and 3 ≤ 4:</p>'
            .'<ol><li>Calculate the margin.</li><li>Round to 2 decimal places.</li></ol>'
            .'<table><tbody><tr><th scope="col">Item</th><th scope="col">Value</th></tr>'
            .'<tr><td>Revenue</td><td>9,000</td></tr></tbody></table>'
            .'<p>Use <span data-latex="C_0 + \\sum F_t">C0 + the sum of the flows</span>.</p>';

        $assignment = $this->publishedAssignment(['max_marks' => 10]);
        $question = $this->questionAccepting($assignment, self::K::KIND_TEXT, [
            'marks' => 10,
            'prompt' => $rich,
        ]);

        $fresh = AssignmentQuestion::query()->findOrFail($question->id);

        foreach ([
            '<h3>Set out your working</h3>' => 'heading',
            '<strong>9,000</strong>' => 'bold',
            '<em>4,800</em>' => 'italic',
            '<sup>3</sup>' => 'superscript',
            '<ol>' => 'numbered list',
            '<th scope="col">' => 'table header scope',
            'data-latex=' => 'equation notation',
            '≤' => 'mathematical symbol',
        ] as $needle => $what) {
            $this->assertStringContainsString($needle, $fresh->prompt, "the $what did not survive");
        }
    }

    public function test_a_lecturers_DANGEROUS_question_markup_is_stripped_on_the_way_in(): void
    {
        // THROUGH THE SERVICE, not through a fixture.
        //
        // `questionAccepting()` writes a row directly, because a fixture that went
        // through `QuestionService` would have to satisfy its authority checks and
        // its whole validation contract. That is the right default for a fixture -
        // and it means a test claiming to check the SERVICE must not use it, or it
        // ends up testing the fixture. This one did, and was asserting nothing.
        $assignment = $this->publishedAssignment(['max_marks' => 10]);

        $question = app(QuestionService::class)->add($this->lecturer, $assignment, [
            'prompt' => '<p onclick="steal()">Legitimate question</p><script>alert(1)</script>'
                .'<img src="javascript:alert(1)" alt="x">',
            'marks' => 10,
            'response_kinds' => self::K::KIND_TEXT,
        ]);

        $fresh = AssignmentQuestion::query()->findOrFail($question->id);

        $this->assertStringContainsString('Legitimate question', $fresh->prompt);
        $this->assertStringNotContainsString('<script', $fresh->prompt);
        $this->assertStringNotContainsString('onclick', $fresh->prompt);
        $this->assertStringNotContainsString('javascript:', $fresh->prompt);
    }

    public function test_a_FORMATTED_question_renders_formatted_to_the_student(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 10, 'due_date' => now()->addWeek()]);
        $this->questionAccepting($assignment, self::K::KIND_TEXT, [
            'marks' => 10,
            'prompt' => '<h3>Working</h3><p>Given 3 ≤ 4, show that <strong>2 <sup>3</sup> &gt; 7</strong>.</p>',
        ]);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('<h3>Working</h3>', false)
            ->assertSee('<strong>', false)
            ->assertSee('≤', false);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. A FORMATTED STUDENT ANSWER SURVIVES STORAGE
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_students_FORMATTED_answer_is_stored_formatted(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 10, 'due_date' => now()->addWeek()]);
        $question = $this->questionAccepting($assignment, self::K::KIND_TEXT, [
            'marks' => 10,
            'prompt' => '<p>Explain your working.</p>',
        ]);

        $rich = '<h3>Method</h3>'
            .'<p>Margin is <strong>revenue − cost</strong>, so 4,200 / 9,000.</p>'
            .'<ol><li>Identify the figures.</li><li>Divide.</li></ol>'
            .'<table><tbody><tr><td>Revenue</td><td>9,000</td></tr></tbody></table>'
            .'<p>Note that 3 ≤ 4 and H<sub>2</sub>O is a liquid.</p>';

        $this->submitAnswer($question, $rich)->assertSessionHasNoErrors();

        $stored = (string) \App\Models\AssignmentQuestionResponse::query()
            ->where('assignment_question_id', $question->id)
            ->value('text_response');

        foreach ([
            '<h3>Method</h3>' => 'heading',
            '<strong>' => 'bold',
            '<ol>' => 'numbered list',
            '<table>' => 'table',
            '<sub>2</sub>' => 'subscript',
            '≤' => 'mathematical symbol',
        ] as $needle => $what) {
            $this->assertStringContainsString($needle, $stored, "the $what did not survive");
        }
    }

    public function test_a_students_DANGEROUS_answer_markup_is_stripped(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 10, 'due_date' => now()->addWeek()]);
        $question = $this->questionAccepting($assignment, self::K::KIND_TEXT, [
            'marks' => 10,
            'prompt' => '<p>Explain your working.</p>',
        ]);

        $this->submitAnswer(
            $question,
            '<p onmouseover="steal()">My genuine work</p><script>alert(1)</script>'
            .'<a href="javascript:alert(1)">a link</a>'
        )->assertSessionHasNoErrors();

        $stored = (string) \App\Models\AssignmentQuestionResponse::query()
            ->where('assignment_question_id', $question->id)
            ->value('text_response');

        $this->assertStringContainsString('My genuine work', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onmouseover', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
    }

    public function test_a_FORMATTED_answer_still_COUNTS_as_answered(): void
    {
        // The completeness check reads TEXT, so formatting must not make an answer
        // look empty. A student who answered in a table would otherwise be told
        // they had not answered - and told it by the very check that is supposed
        // to be fair about empty markup.
        $assignment = $this->publishedAssignment(['max_marks' => 10, 'due_date' => now()->addWeek()]);
        $question = $this->questionAccepting($assignment, self::K::KIND_TEXT, [
            'marks' => 10,
            'prompt' => '<p>Explain your working.</p>',
        ]);

        $this->submitAnswer(
            $question,
            '<table><tbody><tr><td>Revenue</td><td>9,000</td></tr></tbody></table>'
        )->assertSessionHasNoErrors();

        $this->assertSame(1, \App\Models\AssignmentSubmission::query()->where('is_draft', false)->count());
    }

    public function test_an_ANSWER_OF_ONLY_EMPTY_FORMATTING_is_still_blank(): void
    {
        // The opposite direction, and the one a richness check most easily gets
        // wrong: markup that LOOKS like an answer and contains no words is not one.
        $assignment = $this->publishedAssignment(['max_marks' => 10, 'due_date' => now()->addWeek()]);
        $question = $this->questionAccepting($assignment, self::K::KIND_TEXT, [
            'marks' => 10,
            'prompt' => '<p>Explain your working.</p>',
        ]);

        $this->submitAnswer($question, '<p><br></p><p>&nbsp;</p><p><strong></strong></p>')
            ->assertSessionHasErrors('questions');

        $this->assertSame(0, \App\Models\AssignmentSubmission::query()->where('is_draft', false)->count());
    }

    public function test_a_DRAFT_of_a_FORMATTED_answer_survives_a_reload(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 10, 'due_date' => now()->addWeek()]);
        $question = $this->questionAccepting($assignment, self::K::KIND_TEXT, [
            'marks' => 10,
            'prompt' => '<p>Explain your working.</p>',
        ]);

        $rich = '<h3>Method</h3><p>Margin is <strong>revenue − cost</strong>.</p>';

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $question->id => [
                        'text' => $rich,
                        'items' => [['kind' => self::K::KIND_TEXT]],
                    ],
                ],
            ])->assertSessionHasNoErrors();

        $this->assertSame(1, \App\Models\AssignmentSubmission::query()->where('is_draft', true)->count());

        // STORAGE holds markup, and that is asserted as markup.
        $stored = (string) \App\Models\AssignmentQuestionResponse::query()
            ->where('assignment_question_id', $question->id)
            ->value('text_response');

        $this->assertStringContainsString('<h3>Method</h3>', $stored);
        $this->assertStringContainsString('<strong>', $stored);

        // THE PAGE carries it ESCAPED, inside the source textarea, and that is
        // correct: a textarea's content is text, and a saved document put into it
        // unescaped would be the injection point. Summernote reads `.value` - which
        // the HTML parser has already unescaped - and parses it as markup.
        //
        // So what the page must contain is the WORDS, because that is what a
        // student reads, and asserting on raw tags here would be asserting on a
        // representation this page deliberately does not produce.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('Method')
            ->assertSee('revenue')
            ->assertSee('&lt;h3&gt;Method', false);
    }

    public function test_FORMATTED_feedback_survives_from_the_marker_to_the_student(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 10, 'due_date' => now()->addWeek()]);
        $question = $this->questionAccepting($assignment, self::K::KIND_TEXT, [
            'marks' => 10,
            'prompt' => '<p>Explain your working.</p>',
        ]);
        $this->submitAnswer($question, '<p>My working.</p>');

        // The attempt being marked, resolved the way the marking page resolves it.
        $submission = \App\Models\AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $this->student->id)
            ->where('is_draft', false)
            ->firstOrFail();

        $rich = '<p>Your rearrangement is right, but <strong>4,200 × (1 + 0.05)<sup>2</sup></strong> '
            .'is not 4,200 × 1.05, and remember 3 ≤ 4.</p>';

        // The MARKING route, which is grade-by-question. Posting to the
        // submission's own path hit a GET-only route and returned 405 - which
        // carries no session errors, so an ssertSessionHasNoErrors() after it
        // passed VACUOUSLY. Worth naming: a 405 is the easiest way to write a test
        // that appears to work and asserts nothing.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade-by-question", [
                'marks' => ['1' => 7],
                'feedback_by_question' => ['1' => $rich],
                'feedback' => '<p>Good method overall. See <em>the note on question 1</em>.</p>',
            ])
            ->assertSessionHasNoErrors();

        // The row was resolved BEFORE the marking POST, so its in-memory copy is
        // stale by definition. Reading a property off it without a refresh is the
        // quietest way to assert on the value the submission started with - and it
        // reports an empty feedback for a post that plainly succeeded.
        $submission->refresh();

        $submission->update(['marks_released_at' => now()]);
        $submission->refresh();

        // STORAGE first, as markup - the same rule the question and the answer
        // are held to, and the claim the filter is actually responsible for.
        //
        // The OVERALL comment, which is where the italic summary lives.
        $this->assertStringContainsString('<em>', (string) $submission->feedback);

        // And the PER-QUESTION note, which is where the mathematics lives. A
        // marker writing "4,200 x (1 + 0.05)^2" needs a real superscript and a
        // real inequality sign; both are words once they are HTML tags, so both
        // have to survive.
        //
        // `answersByQuestion()` is the reader that exists; `questionFeedback()`
        // is a name I reached for. The per-question note is held on the RESPONSE
        // row, and a response exists for every question whether or not it
        // carries feedback - the same rule that makes "not answered" a stored
        // fact rather than an inference four screens would each make separately.
        $perQuestion = $submission->answersByQuestion();
        $perQuestionNote = (string) (($perQuestion[1] ?? null)?->feedback);

        $this->assertStringContainsString('<strong>', $perQuestionNote);
        $this->assertStringContainsString('<sup>2</sup>', $perQuestionNote);
        $this->assertStringContainsString('≤', $perQuestionNote);
        $this->assertStringNotContainsString('<script', $perQuestionNote);
        $this->assertStringContainsString('<strong>', (string) ($perQuestion[1] ?? null)?->feedback);

        // And then the WORDS, as the student receives them. A marker's note is
        // mathematics, so the symbols are the part that has to survive: a
        // superscript and an inequality written as words would be a worse answer.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('rearrangement is right')
            ->assertSee('note on question 1');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. AN AUTHOR'S OWN CLASSES SURVIVE
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_lecturers_own_class_names_are_kept_while_office_junk_is_not(): void
    {
        // Through the service, for the same reason as the test above.
        $assignment = $this->publishedAssignment(['max_marks' => 10]);

        // On a tag that CARRIES a class in this vocabulary. `p` does not: `class`
        // is deliberately permitted only on a small named set, because a blanket
        // class allowlist on <p> would let an author borrow the application's own
        // utility classes and restyle the surrounding page.
        $question = app(QuestionService::class)->add($this->lecturer, $assignment, [
            'prompt' => '<ul class="as-example"><li>A worked example</li></ul>'
                .'<ol class="MsoListParagraph"><li>Pasted from Word</li></ol>',
            'marks' => 10,
            'response_kinds' => self::K::KIND_TEXT,
        ]);

        $fresh = AssignmentQuestion::query()->findOrFail($question->id);

        $this->assertStringContainsString('as-example', $fresh->prompt, 'a real class must survive');
        $this->assertStringNotContainsString('MsoListParagraph', $fresh->prompt, "Word's class must not");
        $this->assertStringContainsString('A worked example', $fresh->prompt, 'the words survive either way');
        $this->assertStringContainsString('Pasted from Word', $fresh->prompt, 'the words survive either way');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. THE SERVICE CONTRACT THE EDITOR DEPENDS ON
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_service_sanitises_a_prompt_even_when_it_is_called_directly(): void
    {
        // The controller is not the only caller. `QuestionService` sanitises, so a
        // caller that bypasses the controller still cannot store a script - which
        // is the property that makes the filter a control rather than a
        // view's decoration.
        $assignment = $this->publishedAssignment(['max_marks' => 10]);
        $module = $this->module();

        $question = app(QuestionService::class)->add($this->lecturer, $assignment, [
            'prompt' => '<p>Legitimate</p><script>alert(1)</script>',
            'marks' => 5,
            'response_kinds' => self::K::KIND_TEXT,
        ]);

        $fresh = AssignmentQuestion::query()->findOrFail($question->id);

        $this->assertStringContainsString('Legitimate', $fresh->prompt);
        $this->assertStringNotContainsString('<script', $fresh->prompt);
    }

    public function test_an_equation_is_STORED_not_executed_which_is_why_it_is_safe(): void
    {
        // The whole safety argument for equations in one assertion: PIIE stores a
        // string and a plain-text fallback, and runs nothing. There is no
        // interpreter here to abuse, and the allowlist permits exactly
        // `span[data-latex]` and `span[data-equation]` and nothing else.
        $assignment = $this->publishedAssignment(['max_marks' => 10]);
        $question = $this->questionAccepting($assignment, self::K::KIND_TEXT, [
            'marks' => 10,
            'prompt' => '<p><span data-latex="\\frac{a}{b}">a divided by b</span></p>',
        ]);

        $fresh = AssignmentQuestion::query()->findOrFail($question->id);

        $this->assertStringContainsString('a divided by b', $fresh->prompt, 'the fallback is what a reader without a renderer sees');

        // And the attribute cannot smuggle a handler through the allowlist.
        $this->assertStringNotContainsString('onclick', $fresh->prompt);
    }

    /**
     * Submit one written answer through the real route.
     */
    private function submitAnswer(AssignmentQuestion $question, string $html): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->student)
            ->post(
                "/student/courses/{$this->offering->id}/assignments/{$question->assignment_id}/submit",
                [
                    'questions' => [
                        (string) $question->id => [
                            'text' => $html,
                            'items' => [['kind' => self::K::KIND_TEXT]],
                        ],
                    ],
                    'idempotency_key' => 'k-'.substr(md5($html), 0, 8),
                ]
            );
    }
}
