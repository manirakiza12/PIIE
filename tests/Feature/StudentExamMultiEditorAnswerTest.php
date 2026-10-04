<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * AN EXAM PAPER WITH MORE THAN ONE RICH-TEXT ANSWER ON IT.
 *
 * ── THE REPORTED SYMPTOM, AND WHY THE OBVIOUS TEST MISSED IT ────────────────
 *
 * Exam 18 ("Final test", Course Offering 5, student Kyeyune Amos):
 *
 *     Q1  qid 40  short_answer  "why testing matters"                     1 mark
 *     Q2  qid 41  short_answer  "write a summarised story about yourself" 5 marks
 *     Q3  qid 42  true_false    "is it that married men report home early" 4 marks
 *
 * Two written answers, two Summernote editors, one page. The student could type
 * into Q1 and reported they could not type into Q2. The database agreed, and this
 * is the part that matters:
 *
 *     online_exam_answers for submission 10  ->  ONE row, question_id 42
 *
 * The written answers were not saved AT ALL. Both attempts closed via the timer
 * (`submitted_via = 'timeout'`), so the unsaved text was not merely un-autosaved -
 * it was gone. The page had told the student "Answers are saved automatically."
 *
 * ── THE ROOT CAUSE, AND WHY IT IS NOT VISIBLE IN A SINGLE-EDITOR TEST ──────
 *
 * `public/js/academic-editor.js` mounts Summernote OVER a source textarea. The
 * student types into the `.note-editable` div Summernote creates; the textarea
 * only holds the value. So typing fires `input` on the EDITABLE, never on the
 * textarea.
 *
 * The exam page's autosave listens on the FIELD:
 *
 *     document.querySelectorAll('.exam-answer-input')
 *         .forEach(el => el.addEventListener('input', ...));
 *
 * With one written question that page still looks and behaves fine, because the
 * objective answers are real `<input type=radio>` elements which DO raise `input`
 * on themselves - so the autosave visibly works and the paper appears to save. The
 * written answer is the one thing that never persisted, and nothing on the page
 * distinguishes the two cases.
 *
 * With a SECOND written editor, `insertHtml()` made it actively wrong. It targeted
 *
 *     $('.note-editable').last()
 *
 * so the symbol picker, the equation button, the table button and every plain-text
 * paste were inserted into the LAST editor on the page - Q2 - no matter which
 * question the student was actually in. A student answering Q1 and clicking a
 * symbol watched the text appear in Q2, which reads exactly as "Q2 won't take my
 * input" and is why the two questions behaved differently from each other.
 *
 * ── WHAT THESE TESTS PIN ────────────────────────────────────────────────────
 *
 *  1. each written question renders its OWN textarea, with a unique id and its own
 *     `data-question-id`, and it is neither readonly nor disabled;
 *  2. autosave persists against the QUESTION ID, not the DOM position;
 *  3. all three answers survive a round trip through the database, independently;
 *  4. changing one question's answer changes ONLY that question's row;
 *  5. the shared editor script contains no positional editor guess that could send
 *     one question's content into another question's field.
 */
class StudentExamMultiEditorAnswerTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    private User $student;

    private OnlineExam $exam;

    /** @var array<int,int> question id => created id */
    private array $questionIds = [];

    private int $submissionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();

        $offering = $this->makeOffering(1);
        $this->allocateLecturer($offering, $this->makeUser(3, 1, 'active', 'Daniel Okello'));

        $this->student = $this->makeUser(7, 1, 'active', 'Kyeyune Amos');
        $this->confirmStudent($offering, $this->student);

        // A paper shaped like exam 20: two written — one SHORT ANSWER, one ESSAY — and one
        // auto-marked objective question. The two written types are DIFFERENT on
        // purpose: a test with two identical short answers would pass whatever this
        // page decided a short answer is, and would never notice a regression that
        // removed the essay's editor entirely.
        $examId = $this->makeExam([
            'school_id' => 1,
            'subject_id' => $offering->subject_id,
            'course_offering_id' => $offering->id,
            'class_id' => null,
            'title' => 'Multi editor paper',
            'workflow_state' => 'published',
            'is_published' => 1,
            'total_marks' => 10,
            'pass_mark' => 5,
            'max_attempts' => 2,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
        ]);
        $this->exam = OnlineExam::query()->findOrFail($examId);

        $this->questionIds['q1'] = (int) $this->makeQuestion($examId, [
            'question' => '<p>why testing matters</p>', 'type' => 'short', 'marks' => 1, 'sort_order' => 1,
        ]);
        $this->questionIds['q2'] = (int) $this->makeQuestion($examId, [
            'question' => '<p>write a summarised story about yourself</p>', 'type' => 'essay', 'marks' => 5, 'sort_order' => 2,
        ]);
        $this->questionIds['q3'] = (int) $this->makeQuestion($examId, [
            'question' => '<p>is it that married men report home early</p>', 'type' => 'true_false',
            'correct_ans' => 'true', 'marks' => 4, 'sort_order' => 3,
        ]);

        $this->submissionId = (int) DB::table('online_exam_submissions')->insertGetId([
            'online_exam_id' => $examId,
            'student_id' => $this->student->id,
            'attempt_no' => 1,
            'school_id' => 1,
            'total_marks_snapshot' => 10,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'last_activity_at' => now(),
            'status' => OnlineExamSubmission::STATUS_IN_PROGRESS,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function takeHtml(): string
    {
        return $this->actingAs($this->student)
            ->get(route('student.online_exam.take', $this->exam->id))
            ->assertOk()
            ->getContent();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. EVERY WRITTEN ANSWER IS ITS OWN CONTROL, ADDRESSED BY ITS OWN ID
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Per-question IDENTITY is the guarantee. Which control a given type mounts is
     * asserted too, but deliberately per type: the essay is the rich-text editor, and
     * the short answer is a plain labelled textarea that has no editor dependency to
     * fail. See the note in `student/online_exam/take.blade.php` — mounting a vendor
     * editor for a one-line answer is what left exam 20's question 4 with nothing to
     * type into.
     */
    public function test_each_WRITTEN_question_renders_its_OWN_source_field_with_its_OWN_question_id(): void
    {
        $html = $this->takeHtml();

        foreach (['q1', 'q2'] as $key) {
            $qid = $this->questionIds[$key];

            $this->assertMatchesRegularExpression(
                '/<textarea\b[^>]*\bid="exam-answer-'.$qid.'"/',
                $html,
                "{$key} must have its own uniquely-identified source field"
            );

            $this->assertMatchesRegularExpression(
                '/<textarea\b[^>]*\bdata-question-id="'.$qid.'"/',
                $html,
                "{$key}'s source field must carry its OWN question id"
            );

            $this->assertMatchesRegularExpression(
                '/<textarea\b[^>]*\bdata-answer-type="text"/',
                $html,
                "{$key} must be typed as written text, not as an option"
            );

            // And it must be a real, typeable control.
            $this->assertDoesNotMatchRegularExpression(
                '/<textarea\b[^>]*\bid="exam-answer-'.$qid.'"[^>]*\breadonly/',
                $html, "{$key}'s field must not be readonly"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<textarea\b[^>]*\bid="exam-answer-'.$qid.'"[^>]*\bdisabled/',
                $html, "{$key}'s field must not be disabled"
            );

            // And LABELLED, so it is a control with an accessible name rather than a
            // box a screen reader announces as nothing.
            $this->assertMatchesRegularExpression(
                '/<label\b[^>]*\bfor="exam-answer-'.$qid.'"/',
                $html,
                "{$key} must be labelled"
            );
        }

        // q1 is the SHORT ANSWER: no editor, and the autosave contract is intact.
        $this->assertDoesNotMatchRegularExpression(
            '/<textarea\b[^>]*\bid="exam-answer-'.$this->questionIds['q1'].'"[^>]*\bdata-piie-editor\b/',
            $html,
            'a short answer must not depend on the rich-text editor mounting'
        );
        $this->assertStringContainsString('data-testid="short-answer-input"', $html);

        // q2 is the ESSAY: it keeps the rich-text editor, and it keeps the selector
        // the autosave resolves written answers by.
        $this->assertMatchesRegularExpression(
            '/<textarea\b[^>]*\bid="exam-answer-'.$this->questionIds['q2'].'"[^>]*\bdata-piie-editor\b/',
            $html,
            'the essay must keep a rich-text editor'
        );
        $this->assertMatchesRegularExpression(
            '/<textarea\b[^>]*\bid="exam-answer-'.$this->questionIds['q2'].'"[^>]*\bexam-answer-input\b/',
            $html,
            'the editor field must keep the class the autosave looks for'
        );
    }

    /**
     * THE SHARED EDITOR SCRIPT MUST NOT GUESS WHICH EDITOR IS MEANT.
     *
     * This is the assertion that pins the fix. `$('.note-editable').last()` was the
     * defect: with two written questions it sent every toolbar insertion into the
     * LAST one regardless of where the student was typing. A positional guess must
     * not reappear.
     */
    public function test_the_editor_script_contains_NO_positional_EDITOR_guess(): void
    {
        $js = (string) file_get_contents(public_path('js/academic-editor.js'));

        // Prose must not be able to fail this test. The file explains the old
        // `$('.note-editable').last()` defect in a comment, and a substring search
        // over the whole file would match that explanation and then "fix" it by
        // deleting the reason the code is written the way it is.
        $code = $this->stripJsComments($js);

        // Positional jQuery guesses. `last()` is the one that caused the reported
        // bug; `first()` and a bare `$(...)` selector are equally unsafe, because a
        // per-question operation must never depend on where an editor sits in the
        // document.
        foreach ([
            "\$('.note-editable').last()",
            "\$('.note-editable').first()",
            "\$('.note-editable')",
            "\$('.note-editable')[",
            "\$(\".note-editable\")",
        ] as $pattern) {
            $this->assertStringNotContainsString(
                $pattern,
                $code,
                "a positional editor guess ({$pattern}) would send one question's content into another"
            );
        }

        // Exactly ONE bare `document.querySelector('.note-editable')` is allowed:
        // the final fallback of activeEditable(), which returns the FIRST editor
        // and is reached only when focus, remembered focus and the selection have
        // all failed to resolve one. Counted rather than forbidden, so a second
        // one appearing somewhere else is a failure.
        $this->assertSame(
            1,
            substr_count($code, "document.querySelector('.note-editable')"),
            'a bare editor query may exist only as the single documented fallback'
        );

        // The ONLY remaining bare editor query is the documented final fallback
        // inside activeEditable(), which returns the FIRST editable and is reached
        // only when neither focus, nor remembered focus, nor the selection resolve
        // one. Asserted in place so it cannot be quietly promoted into a `.last()`
        // or moved into a per-question code path. The member is declared as an
        // object property (`activeEditable: function () {`), so the pattern matches
        // that, not a bare `function activeEditable` declaration.
        $this->assertMatchesRegularExpression(
            '/activeEditable\s*:\s*function\s*\(\s*\)[\s\S]*?document\.querySelector\(\'\.note-editable\'\)/',
            $code,
            'activeEditable() must keep a single, non-positional final fallback'
        );

        // The per-question operations must all go through the scoped resolver.
        $this->assertStringContainsString('sourceTextareaFor', $code, 'the bridge must resolve the field for ITS editable');
        $this->assertStringContainsString("editable.closest('.piie-editor-shell')", $code, 'scope must be the owning shell, never the document');
        $this->assertStringContainsString('data-piie-bridge-ready', $code, 'each editor must be bridged exactly once');

        // insertHtml / handlePaste must not resolve an editor themselves.
        $this->assertMatchesRegularExpression(
            '/insertHtml: function \(html, target\)[\s\S]*?API\.activeEditable\(\)/',
            $code,
            'insertHtml() must target the active editor, never a positional guess'
        );
        $this->assertMatchesRegularExpression(
            '/handlePaste: function \(e, cfg\)[\s\S]*?API\.activeEditable\(\)/',
            $code,
            'handlePaste() must target the editor the paste happened in'
        );

        // bridgeToSource must never reach for a global editor query.
        $this->assertMatchesRegularExpression(
            '/bridgeToSource: function \(editable\)[\s\S]*?API\.sourceTextareaFor\(editable\)/',
            $code,
            'bridgeToSource() must resolve the field belonging to the editable it was given'
        );

        // `syncAll()` intentionally touches EVERY editor - it is a form-level
        // sweep on submit, not a per-question operation, and must stay a queryAll.
        //
        // The selector previously carried `[data-piie-editor-ready="1"]`, and that
        // restriction was part of the exam 21 failure: a field whose editor mounted
        // late was skipped, so its content was never copied into `area.value` and the
        // server rejected the question as blank. The queryAll is the part that matters
        // and it is still required; the ready-flag filter is not, and is now asserted
        // as ABSENT so it cannot be reintroduced.
        $this->assertStringContainsString(
            "scope.querySelectorAll('textarea[data-piie-editor]')",
            $code,
            'syncAll() must sweep every editor field in the form'
        );

        $this->assertStringNotContainsString(
            'textarea[data-piie-editor][data-piie-editor-ready',
            $code,
            'a field must never be skipped for lack of an editor ready flag - that is what '
            . 'made exam 21 post an empty question while the editor showed the lecturer their text'
        );

        // And the sweep must actually assign the field the browser serialises.
        $this->assertMatchesRegularExpression(
            '/syncAll: function \(root\)[\s\S]*?area\.value = code/',
            $code,
            'syncAll() must write into area.value, not back into the vendor instance'
        );
    }

    /**
     * Remove block and line comments so assertions read CODE, not prose.
     *
     * A naive stripper is not good enough here, and the reason is specific: this
     * file builds HTML as JavaScript STRING LITERALS, and those contain `</span>`
     * and `<!--`. Stripping `//` to end-of-line inside a literal would corrupt
     * code and produce nonsense matches.
     *
     * So this is a state machine over three states - CODE, LINE_COMMENT,
     * BLOCK_COMMENT - that also tracks single- and double-quoted strings and
     * template literals, and leaves everything inside them untouched.
     */
    private function stripJsComments(string $js): string
    {
        $out = '';
        $len = strlen($js);
        $state = 'code';
        $quote = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $js[$i];
            $next = $i + 1 < $len ? $js[$i + 1] : '';

            if ($state === 'code') {
                if ($ch === '/' && $next === '*') { $state = 'block'; $i++; continue; }
                if ($ch === '/' && $next === '/') { $state = 'line'; $i++; continue; }
                if ($ch === '"' || $ch === "'" || $ch === '`') { $state = 'string'; $quote = $ch; }
                $out .= $ch;
                continue;
            }

            if ($state === 'block') {
                if ($ch === '*' && $next === '/') { $state = 'code'; $i++; }
                continue;
            }

            if ($state === 'line') {
                if ($ch === "\n") { $state = 'code'; $out .= "\n"; }
                continue;
            }

            // inside a string literal: pass everything through verbatim
            $out .= $ch;

            if ($ch === '\\') { $i++; if ($i < $len) { $out .= $js[$i]; } continue; }
            if ($ch === $quote) { $state = 'code'; }
        }

        return $out;
    }

    public function test_the_page_carries_NO_duplicate_element_ids(): void
    {
        $html = $this->takeHtml();

        /**
         * Scoped to the ids the answer machinery actually ADDRESSES, rather than to
         * "every id on the page".
         *
         * A whole-page uniqueness sweep looks stronger and is worse. The shared
         * student navigation is used by every screen in this application and carries
         * its own repeated markup, so a page-wide assertion would fail for reasons
         * that have nothing to do with an exam answer - and could then only be made
         * to pass by rewriting a layout this test has no business touching.
         *
         * What actually breaks this feature is a DUPLICATE ID AMONG THE ANSWER
         * CONTROLS: `getElementById('exam-answer-40')` would hit the wrong field,
         * the `label for=` pair would point at the wrong editor, and the autosave's
         * status indicator would write its "Saved" label onto another question.
         */
        $addressed = [
            'exam-answer-'.$this->questionIds['q1'],
            'exam-answer-'.$this->questionIds['q2'],
            'question-block-'.$this->questionIds['q1'],
            'question-block-'.$this->questionIds['q2'],
            'question-block-'.$this->questionIds['q3'],
            'save-status-'.$this->questionIds['q1'],
            'save-status-'.$this->questionIds['q2'],
            'save-status-'.$this->questionIds['q3'],
            'nav-dot-'.$this->questionIds['q1'],
            'nav-dot-'.$this->questionIds['q2'],
            'nav-dot-'.$this->questionIds['q3'],
            'q'.$this->questionIds['q3'].'t',
            'q'.$this->questionIds['q3'].'f',
        ];

        foreach ($addressed as $id) {
            $this->assertSame(
                1,
                substr_count($html, 'id="'.$id.'"'),
                "[{$id}] must appear exactly once - the editor bridge, the label/for pair and the autosave indicator all address it by id"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. AUTOSAVE PERSISTS AGAINST THE QUESTION ID
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The three answers the manual test types, saved the way the page's autosave
     * does - one request per question, each naming its own `question_id`.
     */
    private function autosave(string $key, ?string $selected, ?string $text = null, int $revision = 1): void
    {
        $this->actingAs($this->student)
            ->postJson(route('student.online_exam.save_answer', $this->submissionId), [
                'submission_id' => $this->submissionId,
                'question_id' => $this->questionIds[$key],
                'answer_revision' => $revision,
                'selected_option' => $selected,
                'answer_text' => $text,
            ])
            ->assertOk();
    }

    public function test_every_answer_is_persisted_against_its_OWN_question_id(): void
    {
        $this->autosave('q1', null, 'ANSWER ONE');
        $this->autosave('q2', null, 'ANSWER TWO');
        $this->autosave('q3', 'true', null);

        $rows = OnlineExamAnswer::query()
            ->where('submission_id', $this->submissionId)
            ->orderBy('question_id')
            ->get()
            ->keyBy('question_id');

        $this->assertCount(3, $rows, 'each question must get its own answer row');

        $this->assertSame('ANSWER ONE', $rows[$this->questionIds['q1']]->answer_text);
        $this->assertSame('ANSWER TWO', $rows[$this->questionIds['q2']]->answer_text);

        // The objective answer is a MACHINE value and must stay one. Turning an
        // MCQ / True-False selection into rich HTML would break automatic marking.
        $this->assertSame('true', $rows[$this->questionIds['q3']]->selected_option);
        $this->assertNull($rows[$this->questionIds['q3']]->answer_text);

        // And the two written rows must not have borrowed a selected_option.
        $this->assertNull($rows[$this->questionIds['q1']]->selected_option);
        $this->assertNull($rows[$this->questionIds['q2']]->selected_option);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. ROUND TRIP, AND ONE-QUESTION-ONLY ISOLATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_THREE_answers_return_to_their_OWN_questions_after_a_RELOAD(): void
    {
        $this->autosave('q1', null, 'ANSWER ONE');
        $this->autosave('q2', null, 'ANSWER TWO');
        $this->autosave('q3', 'true', null);

        $html = $this->takeHtml();

        // Each answer must appear inside its OWN question card, matched by the
        // block's data-question-id rather than by position.
        foreach ([
            'q1' => 'ANSWER ONE',
            'q2' => 'ANSWER TWO',
        ] as $key => $text) {
            $qid = $this->questionIds[$key];

            $this->assertMatchesRegularExpression(
                '/id="question-block-'.$qid.'"(?:(?!id="question-block-).)*?'.preg_quote($text, '/').'/s',
                $html,
                "{$key}'s own saved answer must come back into its own block"
            );
        }

        // The True/False answer must come back CHECKED on that question's radios.
        $this->assertMatchesRegularExpression(
            '/<input\b[^>]*\bvalue="true"[^>]*\bid="q'.$this->questionIds['q3'].'t"[^>]*\bchecked/',
            $html,
            'the saved objective selection must come back checked'
        );
    }

    public function test_changing_ONLY_Q2_leaves_Q1_and_Q3_untouched(): void
    {
        $this->autosave('q1', null, 'ANSWER ONE');
        $this->autosave('q2', null, 'ANSWER TWO');
        $this->autosave('q3', 'true', null);

        $before = OnlineExamAnswer::query()
            ->where('submission_id', $this->submissionId)
            ->pluck('answer_text', 'question_id')
            ->all();
        $q3Before = OnlineExamAnswer::query()
            ->where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['q3'])
            ->value('selected_option');

        $this->autosave('q2', null, 'ANSWER TWO UPDATED', 2);

        $after = OnlineExamAnswer::query()
            ->where('submission_id', $this->submissionId)
            ->pluck('answer_text', 'question_id')
            ->all();

        $this->assertSame('ANSWER ONE', $after[$this->questionIds['q1']], 'Q1 must be unchanged');
        $this->assertSame('ANSWER TWO UPDATED', $after[$this->questionIds['q2']]);
        $this->assertSame($q3Before, OnlineExamAnswer::query()
            ->where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['q3'])
            ->value('selected_option'), 'Q3 must be unchanged');

        $this->assertSame($before[$this->questionIds['q1']] ?? null, $after[$this->questionIds['q1']]);
    }

    public function test_a_student_CANNOT_save_an_answer_to_ANOTHER_exams_question(): void
    {
        $foreignExam = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->exam->subject_id,
            'title' => 'Someone else\'s paper',
        ]));
        $foreignQuestion = (int) $this->makeQuestion($foreignExam->id, [
            'question' => '<p>not yours</p>', 'type' => 'short', 'marks' => 1, 'sort_order' => 1,
        ]);

        $this->actingAs($this->student)
            ->postJson(route('student.online_exam.save_answer', $this->submissionId), [
                'submission_id' => $this->submissionId,
                'question_id' => $foreignQuestion,
                'answer_revision' => 1,
                'answer_text' => 'should not be stored',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('online_exam_answers', [
            'submission_id' => $this->submissionId,
            'question_id' => $foreignQuestion,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. SUBMIT KEEPS THE LATEST VERSION OF EVERY ANSWER
    // ══════════════════════════════════════════════════════════════════════

    public function test_SUBMIT_keeps_every_written_answer_and_scores_the_objective_one(): void
    {
        $this->autosave('q1', null, 'ANSWER ONE');
        $this->autosave('q2', null, 'ANSWER TWO UPDATED', 2);
        $this->autosave('q3', 'true', null);

        $this->actingAs($this->student)
            ->post(route('student.online_exam.submit', $this->exam->id), [
                'submission_id' => $this->submissionId,
            ])
            ->assertSessionHasNoErrors();

        $submission = OnlineExamSubmission::query()->findOrFail($this->submissionId);
        $rows = OnlineExamAnswer::query()
            ->where('submission_id', $this->submissionId)
            ->pluck('answer_text', 'question_id')
            ->all();

        $this->assertSame('ANSWER ONE', $rows[$this->questionIds['q1']]);
        $this->assertSame('ANSWER TWO UPDATED', $rows[$this->questionIds['q2']]);

        // Automatic marking survived: the True/False is worth its 4 marks.
        $this->assertSame(4.0, (float) $submission->objective_score);

        // Two answered written questions are awaiting judgement, so the attempt
        // must be sitting in the marking queue rather than finalised.
        $this->assertSame(OnlineExamSubmission::STATUS_PENDING_MANUAL, $submission->status);
        $this->assertSame('not_ready', $submission->result_review_state);
    }
}