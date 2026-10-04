<?php

namespace Tests\Feature;

use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamSubmission;
use App\Support\OnlineExams\QuestionPrompt;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * EXAM 20 — ANSWER PERSISTENCE AND QUESTION RENDERING.
 *
 * The measured defects this file pins:
 *
 *   1. All four of exam 20's questions are stored as `<p><br></p>`. An empty
 *      rich-text document passed `['required', 'string']`, so four unanswerable
 *      questions were published.
 *   2. Question 4 (Short Answer) rendered with no usable input at all.
 *   3. Question 3 (Essay) was typed into and reported "Saved", and was stored NULL
 *      with `answer_revision = 0` — i.e. the row was created at submission time by
 *      `submitBySubmission()`, never by autosave.
 *
 * These tests assert the SERVER contract for each of those, against the real
 * requests and the real rendered HTML. Browser-level behaviour — the editor's
 * bridge, the autosave state machine, the restricted-interaction scope — is covered
 * by `tests/js/`, which drives the actual `public/js` files.
 */
class OnlineExamAnswerPersistenceTest extends TestCase
{
    use OnlineExamTestHelper;

    private int $examId;
    private int $studentId;
    private int $lecturerId;
    private int $classId;
    private int $submissionId;
    private array $questionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();

        $this->studentId = (int) $this->makeUser(7, 1, 'active', 'Kyeyune Amos')->id;
        $this->classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $this->classId);
        $this->enrollStudent($this->studentId, 1, $this->classId);

        // A lecturer assigned to this class, so the marking assertions below exercise
        // persistence rather than the permission engine.
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
            'title' => 'Answer Persistence Verification',
            'subject_id' => $subjectId,
            'class_id' => $this->classId,
            'total_marks' => 20,
            'pass_mark' => 10,
            'duration_mins' => 30,
            'created_by' => $this->lecturerId,
            'creator_id' => $this->lecturerId,
        ]);

        // The exact paper from the brief: MCQ, True/False, Essay, Short Answer.
        $this->questionIds['mcq'] = $this->makeQuestion($this->examId, [
            'question' => '<p>What is 2 + 2?</p>',
            'type' => 'mcq', 'option_a' => '3', 'option_b' => '4',
            'correct_ans' => 'b', 'marks' => 5, 'sort_order' => 1,
        ]);
        $this->questionIds['true_false'] = $this->makeQuestion($this->examId, [
            'question' => '<p>Revenue rises when price falls.</p>',
            'type' => 'true_false', 'option_a' => null, 'option_b' => null,
            'option_c' => null, 'option_d' => null,
            'correct_ans' => 'true', 'marks' => 5, 'sort_order' => 2,
        ]);
        $this->questionIds['essay'] = $this->makeQuestion($this->examId, [
            'question' => '<p>Explain the law of demand.</p>',
            'type' => 'essay', 'option_a' => null, 'option_b' => null,
            'option_c' => null, 'option_d' => null,
            'correct_ans' => null, 'marks' => 5, 'sort_order' => 3,
        ]);
        $this->questionIds['short'] = $this->makeQuestion($this->examId, [
            'question' => '<p>State the compound interest formula.</p>',
            'type' => 'short', 'option_a' => null, 'option_b' => null,
            'option_c' => null, 'option_d' => null,
            'correct_ans' => null, 'marks' => 5, 'sort_order' => 4,
        ]);

        $this->submissionId = $this->makeSubmission([
            'online_exam_id' => $this->examId,
            'student_id' => $this->studentId,
            'status' => OnlineExamSubmission::STATUS_IN_PROGRESS,
        ]);
    }

    private function takeUrl(): string
    {
        return route('student.online_exam.take', $this->examId);
    }

    private function saveUrl(): string
    {
        return route('student.online_exam.save_answer', $this->submissionId);
    }

    /**
     * One question's card, cut out of the rendered page.
     *
     * Asserting on the whole page proves the essay editor exists somewhere; it does
     * not prove the SHORT ANSWER is not using one. Each of the four question-type
     * tests below reads only its own card, which is the only way the requirement
     * "all four types render" becomes checkable.
     */
    private function cardFor(string $html, int $questionId): string
    {
        $marker = 'id="question-block-'.$questionId.'"';
        $start = strpos($html, $marker);

        $this->assertNotFalse($start, "Question {$questionId} has no card on the page.");

        // The card ends where the NEXT question card begins, or at the end of the
        // question column for the last one.
        $next = strpos($html, 'id="question-block-', $start + strlen($marker));

        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }

    private function asStudent(): self
    {
        $this->actingAs(\App\Models\User::find($this->studentId));

        return $this;
    }

    /**
     * A lecturer who owns this exam's class.
     *
     * Ownership is what the authorization checks, and a teacher with no class
     * assignment is refused at the door — which is correct, and would make every
     * marking assertion in this file a test of the permission engine instead of a test
     * of persistence.
     */
    private function asLecturer(): self
    {
        $this->actingAs(\App\Models\User::find($this->lecturerId));

        return $this;
    }

    // ── 1. Question authoring refuses an empty rich-text document ─────────────

    /** @dataProvider emptyPromptProvider */
    public function test_empty_rich_text_document_is_not_accepted_as_a_question(string $emptyDocument): void
    {
        $draftExamId = $this->makeAuthoringExam();

        $response = $this->asLecturer()
            ->post(route('teacher.online_exams.questions.store', $draftExamId), [
                'question' => $emptyDocument,
                'type' => 'essay',
                'marks' => 5,
            ]);

        // Asserted on the MESSAGE, not merely on "there is an error". The structural
        // lock also produces an error against `question`, so asserting only presence
        // would let this pass while an empty document was still being accepted on any
        // exam where the lock does not apply.
        $response->assertSessionHasErrors([
            'question' => \App\Support\OnlineExams\QuestionPrompt::MESSAGE,
        ]);

        $this->assertSame(0, DB::table('online_exam_questions')->where('online_exam_id', $draftExamId)->count(),
            'A rejected question must not be stored.');
    }

    /**
     * An exam with no attempts on it, so question authoring is unlocked.
     *
     * Questions are correctly immutable once a student has started. That rule would
     * otherwise mask every authoring assertion in this file behind a generic
     * "cannot be modified" error, so authoring is exercised on its own exam.
     */
    private function makeAuthoringExam(): int
    {
        return $this->makeExam([
            'title' => 'Authoring Verification',
            'class_id' => $this->classId,
            'created_by' => $this->lecturerId,
            'creator_id' => $this->lecturerId,
        ]);
    }

    public static function emptyPromptProvider(): array
    {
        return [
            'summernote empty document' => ['<p><br></p>'],
            'empty paragraph' => ['<p></p>'],
            'non breaking space' => ['<p>&nbsp;</p>'],
            'styled but empty' => ['<h1><span style="font-family: Arial">&#65279;</span></h1>'],
            'empty div with break' => ['<div><br></div>'],
            'empty string' => [''],
        ];
    }

    public function test_a_question_with_real_text_is_accepted_and_sanitized(): void
    {
        // A SECOND exam: the paper under test already has a submission, and questions
        // are correctly locked once attempts have started.
        $draftExamId = $this->makeAuthoringExam();

        $response = $this->asLecturer()
            ->post(route('teacher.online_exams.questions.store', $draftExamId), [
                'question' => '<p>Explain <strong>opportunity cost</strong>.</p><script>alert(1)</script>',
                'type' => 'essay',
                'marks' => 5,
            ]);

        $response->assertSessionHasNoErrors();

        $stored = DB::table('online_exam_questions')
            ->where('online_exam_id', $draftExamId)
            ->where('type', 'essay')
            ->value('question');

        $this->assertStringContainsString('opportunity cost', $stored);
        $this->assertStringNotContainsString('<script', $stored);
    }

    public function test_editing_a_question_to_an_empty_editor_is_refused(): void
    {
        $draftExamId = $this->makeAuthoringExam();
        $draftQuestionId = $this->makeQuestion($draftExamId, [
            'question' => '<p>A question with real text.</p>',
            'type' => 'essay',
            'option_a' => null, 'option_b' => null, 'option_c' => null, 'option_d' => null,
            'correct_ans' => null,
        ]);

        $response = $this->asLecturer()
            ->put(route('teacher.online_exams.questions.update', $draftQuestionId), [
                'question' => '<p><br></p>',
                'type' => 'essay',
                'marks' => 5,
            ]);

        $response->assertSessionHasErrors([
            'question' => \App\Support\OnlineExams\QuestionPrompt::MESSAGE,
        ]);

        $this->assertSame('<p>A question with real text.</p>',
            DB::table('online_exam_questions')->where('id', $draftQuestionId)->value('question'),
            'A refused edit must leave the existing question text untouched.');
    }

    // ── 2. The stored empty prompt is surfaced, never silently blank ──────────

    public function test_student_sees_an_explicit_notice_when_a_question_has_no_stored_text(): void
    {
        // Reproduce exam 20 exactly: an empty editor document in the prompt column.
        DB::table('online_exam_questions')->where('id', $this->questionIds['short'])
            ->update(['question' => '<p><br></p>']);

        $this->asStudent()->get($this->takeUrl())
            ->assertOk()
            ->assertSee('This question has no text.')
            ->assertSee('Tell your invigilator now.', false)
            // The readable questions are still rendered normally.
            ->assertSee('What is 2 + 2?', false)
            ->assertSee('Explain the law of demand.', false);
    }

    public function test_a_question_with_stored_text_never_shows_the_missing_text_notice(): void
    {
        $this->asStudent()->get($this->takeUrl())
            ->assertOk()
            ->assertDontSee('This question has no text.');
    }

    public function test_prompt_label_is_never_an_empty_string_for_a_blank_question(): void
    {
        DB::table('online_exam_questions')->where('id', $this->questionIds['short'])
            ->update(['question' => '<p><br></p>']);

        $question = \App\Models\OnlineExamQuestion::find($this->questionIds['short']);

        $this->assertFalse($question->hasPrompt());
        $this->assertSame(QuestionPrompt::EMPTY_LABEL, $question->promptLabel());
        $this->assertNotSame('', $question->promptLabel(),
            'A blank label is what the lecturer saw on exam 20; it must be impossible.');
    }

    // ── 3. All four question types render a usable control ───────────────────

    public function test_all_four_question_types_render_their_statement_and_a_usable_control(): void
    {
        $html = $this->asStudent()->get($this->takeUrl())->assertOk()->getContent();

        // Statements.
        $this->assertStringContainsString('What is 2 + 2?', $html);
        $this->assertStringContainsString('Revenue rises when price falls.', $html);
        $this->assertStringContainsString('Explain the law of demand.', $html);
        $this->assertStringContainsString('State the compound interest formula.', $html);

        // Every question on the paper is declared readable.
        $this->assertSame(4, substr_count($html, 'data-question-prompt="present"'));
        $this->assertSame(0, substr_count($html, 'data-question-prompt="empty"'));

        // Controls: MCQ radios, True/False radios, an essay editor, and a SHORT
        // ANSWER text input that is a real, labelled <textarea>.
        $this->assertStringContainsString('type="radio"', $html);
        $this->assertStringContainsString('data-piie-editor', $html, 'The essay keeps its rich-text editor.');
        $this->assertStringContainsString('data-testid="short-answer-input"', $html);
        // The label must point at the field by the SAME id the autosave addresses, so the
        // accessible name and the saved value can never belong to different fields.
        $this->assertStringContainsString(
            '<label class="form-label" for="exam-answer-'.$this->questionIds['short'].'">',
            $html
        );
        $this->assertStringContainsString('id="exam-answer-'.$this->questionIds['short'].'"', $html);

        foreach ($this->questionIds as $key => $id) {
            $this->assertStringContainsString('id="question-block-'.$id.'"', $html, "Question $key has no card.");
        }
    }

    public function test_short_answer_is_a_plain_textarea_not_a_rich_text_editor(): void
    {
        $card = $this->cardFor($this->asStudent()->get($this->takeUrl())->assertOk()->getContent(), $this->questionIds['short']);

        $this->assertStringContainsString('exam-answer-input', $card);
        $this->assertStringContainsString('data-answer-type="text"', $card);
        // The rich-text editor must NOT be the short answer's control. Its failure
        // modes are exactly what cost exam 20 its Q4 answer.
        $this->assertStringNotContainsString('data-piie-editor', $card);
    }

    public function test_essay_keeps_a_rich_text_editor(): void
    {
        $card = $this->cardFor($this->asStudent()->get($this->takeUrl())->assertOk()->getContent(), $this->questionIds['essay']);

        $this->assertStringContainsString('data-piie-editor', $card);
        $this->assertStringContainsString('exam-answer-input', $card,
            'The editor field must keep the autosave selectors.');
    }

    public function test_mark_allocation_is_shown_for_every_question(): void
    {
        $html = $this->asStudent()->get($this->takeUrl())->assertOk()->getContent();

        foreach ($this->questionIds as $key => $id) {
            $this->assertStringContainsString('5 mark(s)', $this->cardFor($html, $id),
                "Question $key shows no mark allocation.");
        }
    }

    // ── 4. Answers persist, verified in the DATABASE ────────────────────────

    public function test_mcq_and_true_false_answers_are_stored_and_auto_marked(): void
    {
        $this->asStudent()->postJson($this->saveUrl(), [
            'submission_id' => $this->submissionId,
            'question_id' => $this->questionIds['mcq'],
            'answer_revision' => 1,
            'selected_option' => 'b',
        ])->assertOk()->assertJson(['status' => 'success']);

        $this->asStudent()->postJson($this->saveUrl(), [
            'submission_id' => $this->submissionId,
            'question_id' => $this->questionIds['true_false'],
            'answer_revision' => 1,
            'selected_option' => 'true',
        ])->assertOk();

        // Read the DATABASE, not the response.
        $mcq = OnlineExamAnswer::where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['mcq'])->firstOrFail();
        $tf = OnlineExamAnswer::where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['true_false'])->firstOrFail();

        $this->assertSame('b', $mcq->selected_option);
        $this->assertGreaterThan(0, (int) $mcq->answer_revision,
            'A revision of 0 means autosave never persisted this answer.');
        $this->assertSame('true', $tf->selected_option);

        // Objective marks are calculated at FINALIZATION, not while the attempt is
        // still open — a marker must not be able to change a live attempt's score by
        // editing an objective answer. So the marks are asserted after submission.
        $this->asStudent()->post(route('student.online_exam.submit', $this->examId), [
            'submission_id' => $this->submissionId,
        ])->assertRedirect();

        $mcq->refresh();
        $tf->refresh();

        $this->assertTrue((bool) $mcq->is_correct, 'Option b is the keyed answer.');
        $this->assertSame(5.0, (float) $mcq->awarded_marks);
        $this->assertSame(5.0, (float) $tf->awarded_marks);
        $this->assertSame(10.0, (float) OnlineExamSubmission::find($this->submissionId)->objective_score);
    }

    public function test_objective_marks_are_not_awarded_while_the_attempt_is_still_open(): void
    {
        $this->asStudent()->postJson($this->saveUrl(), [
            'submission_id' => $this->submissionId,
            'question_id' => $this->questionIds['mcq'],
            'answer_revision' => 1,
            'selected_option' => 'b',
        ])->assertOk();

        $mcq = OnlineExamAnswer::where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['mcq'])->firstOrFail();

        $this->assertNull($mcq->awarded_marks);
        $this->assertNull($mcq->is_correct);
    }

    public function test_essay_answer_is_persisted_with_its_formatting_intact(): void
    {
        $markup = '<p>Demand <strong>falls</strong> as price rises.</p><ul><li>ceteris paribus</li></ul><p>See <span data-latex="P = a - bQ">P = a - bQ</span></p>';

        $this->asStudent()->postJson($this->saveUrl(), [
            'submission_id' => $this->submissionId,
            'question_id' => $this->questionIds['essay'],
            'answer_revision' => 1,
            'answer_text' => $markup,
        ])->assertOk()->assertJson(['status' => 'success']);

        // THE DATABASE VALUE, not the response body.
        $stored = DB::table('online_exam_answers')
            ->where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['essay'])
            ->value('answer_text');

        $this->assertNotNull($stored, 'Exam 20 Q3 stored NULL after the student typed.');
        $this->assertStringContainsString('<strong>falls</strong>', $stored, 'Formatting was destroyed.');
        $this->assertStringContainsString('<li>ceteris paribus</li>', $stored, 'A list was destroyed.');
        $this->assertStringContainsString('data-latex', $stored, 'Mathematical notation was destroyed.');
        $this->assertTrue(\App\Support\OnlineExams\OnlineExamMarking::hasResponse(
            OnlineExamAnswer::where('submission_id', $this->submissionId)
                ->where('question_id', $this->questionIds['essay'])->firstOrFail()
        ));
    }

    public function test_short_answer_is_persisted_and_restored_exactly(): void
    {
        $this->asStudent()->postJson($this->saveUrl(), [
            'submission_id' => $this->submissionId,
            'question_id' => $this->questionIds['short'],
            'answer_revision' => 1,
            'answer_text' => 'A = P(1 + r/n)^(nt)',
        ])->assertOk();

        $stored = DB::table('online_exam_answers')
            ->where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['short'])
            ->value('answer_text');

        $this->assertSame('A = P(1 + r/n)^(nt)', $stored,
            'The short answer must survive the round trip byte for byte.');

        // A RELOAD of the in-progress exam restores it into the field.
        $this->asStudent()->get($this->takeUrl())
            ->assertOk()
            ->assertSee('A = P(1 + r/n)^(nt)', false);
    }

    public function test_all_four_answers_survive_final_submission_unchanged(): void
    {
        $payloads = [
            [$this->questionIds['mcq'], ['selected_option' => 'b']],
            [$this->questionIds['true_false'], ['selected_option' => 'true']],
            [$this->questionIds['essay'], ['answer_text' => '<p>A worked derivation.</p>']],
            [$this->questionIds['short'], ['answer_text' => 'The compound interest formula.']],
        ];

        foreach ($payloads as [$questionId, $body]) {
            $this->asStudent()->postJson($this->saveUrl(), array_merge($body, [
                'submission_id' => $this->submissionId,
                'question_id' => $questionId,
                'answer_revision' => 1,
            ]))->assertOk();
        }

        $this->asStudent()->post(route('student.online_exam.submit', $this->examId), [
            'submission_id' => $this->submissionId,
        ])->assertRedirect();

        $this->assertNotSame(
            OnlineExamSubmission::STATUS_IN_PROGRESS,
            OnlineExamSubmission::find($this->submissionId)->status
        );

        $essay = DB::table('online_exam_answers')->where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['essay'])->value('answer_text');
        $short = DB::table('online_exam_answers')->where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['short'])->value('answer_text');

        $this->assertSame('<p>A worked derivation.</p>', $essay,
            'Submission must not rewrite a saved written answer.');
        $this->assertSame('The compound interest formula.', $short);
    }

    // ── 5. Ownership and isolation ───────────────────────────────────────────

    public function test_a_student_cannot_save_into_another_students_attempt(): void
    {
        $otherStudentId = (int) $this->makeUser(7, 1, 'active', 'Someone Else')->id;

        $this->asStudent()->postJson($this->saveUrl(), [
            'submission_id' => $this->submissionId,
            'question_id' => $this->questionIds['essay'],
            'answer_revision' => 1,
            'answer_text' => '<p>A worked derivation.</p>',
        ])->assertOk();

        $response = $this->actingAs(\App\Models\User::find($otherStudentId))
            ->postJson($this->saveUrl(), [
                'submission_id' => $this->submissionId,
                'question_id' => $this->questionIds['essay'],
                'answer_revision' => 2,
                'answer_text' => 'hijacked',
            ]);

        $response->assertStatus(403);

        // The refusal must leave the owner's answer exactly as it was. Reading the OWNER's
        // most recent stored value for this question is the assertion that matters:
        // a hijack that merely returned 403 while still writing would pass a status
        // check alone.
        $this->assertSame('<p>A worked derivation.</p>', DB::table('online_exam_answers')
            ->where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['essay'])
            ->value('answer_text'));
        $this->assertSame(1, DB::table('online_exam_answers')
            ->where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['essay'])
            ->count(), 'A refused save must not create a row.');
    }

    public function test_a_student_cannot_open_another_students_attempt(): void
    {
        $otherStudentId = (int) $this->makeUser(7, 1, 'active', 'Someone Else')->id;

        // `findStudentSubmissionOrFail()` answers 404 rather than 403 for an attempt
        // that is not the caller's, so a submission's existence is not itself
        // disclosed. Either way the attempt must not be served.
        $this->actingAs(\App\Models\User::find($otherStudentId))
            ->get(route('student.online_exam.resume', $this->submissionId))
            ->assertStatus(404);

        // The attempt PAGE for an in-progress submission is the dangerous one: it is
        // the page that renders another student's questions and, if the isolation
        // failed, their answers in progress. It must not be served either.
        $this->actingAs(\App\Models\User::find($otherStudentId))
            ->get(route('student.online_exam.take', $this->examId))
            ->assertStatus(404);
    }

    public function test_marks_cannot_be_injected_through_the_save_endpoint(): void
    {
        $this->asStudent()->postJson($this->saveUrl(), [
            'submission_id' => $this->submissionId,
            'question_id' => $this->questionIds['essay'],
            'answer_revision' => 1,
            'answer_text' => 'work',
            'awarded_marks' => 5,
        ])->assertStatus(422);

        $this->assertNull(DB::table('online_exam_answers')
            ->where('submission_id', $this->submissionId)->value('awarded_marks'));
    }

    // ── 6. Marking: explicit zero vs. persistence failure ───────────────────

    public function test_a_blank_manual_question_is_decided_explicitly_and_a_failed_save_is_not(): void
    {
        // Nothing saved at all: two manual questions, no responses.
        $submission = OnlineExamSubmission::find($this->submissionId);
        $rows = \App\Support\OnlineExams\OnlineExamMarking::manualQuestionsAwaitingDecision($submission);

        $this->assertCount(2, $rows, 'The essay and the short answer both need a decision.');
        foreach ($rows as $row) {
            $this->assertFalse($row['answered']);
        }
    }

    public function test_a_genuine_blank_is_markable_only_as_zero(): void
    {
        // The paper is handed in with the two written questions untouched.
        $this->asStudent()->post(route('student.online_exam.submit', $this->examId), [
            'submission_id' => $this->submissionId,
        ])->assertRedirect();

        // Finalizing while two manual questions are undecided must be REFUSED, and the
        // refusal must be an actionable sentence on the screen the lecturer is already
        // looking at — not a bare 422 error page. This is the reported defect.
        $response = $this->asLecturer()
            ->post(route('teacher.online_exams.results.finalize', $this->submissionId));

        $response->assertRedirect();
        $response->assertSessionHasErrors('result');

        $message = session('errors')->first('result');
        $this->assertStringContainsString('outstanding', $message);
        $this->assertStringContainsString('2', $message);

        $this->assertNotSame(
            \App\Models\OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            OnlineExamSubmission::find($this->submissionId)->status,
            'A refused handover must not change the submission state.'
        );

        // The two blanks are surfaced as ANSWER ROWS WITH NO MARKER ON THEM — not as
        // silently omitted questions. `awarded_marks` is 0 because the row exists, but
        // `marked_by` is NULL, so the handover is still blocked: a genuine blank is
        // settled by a NAMED marker recording an explicit zero, and by nothing else.
        foreach (['essay', 'short'] as $key) {
            $answer = OnlineExamAnswer::where('submission_id', $this->submissionId)
                ->where('question_id', $this->questionIds[$key])->firstOrFail();

            $this->assertSame(0.0, (float) $answer->awarded_marks);
            $this->assertNull($answer->marked_by, 'A blank is settled by a NAMED marker, not by silence.');
            $this->assertNull($answer->marked_at);
        }

        // Recording that explicit zero settles it and unblocks the handover.
        foreach (['essay', 'short'] as $key) {
            $answer = OnlineExamAnswer::where('submission_id', $this->submissionId)
                ->where('question_id', $this->questionIds[$key])->firstOrFail();

            $this->asLecturer()->post(route('teacher.online_exams.answers.mark', $answer->id), [
                'answer_id' => $answer->id,
                'awarded_marks' => 0,
                'teacher_comment' => 'No answer submitted.',
            ])->assertRedirect();

            $fresh = $answer->fresh();
            $this->assertSame(0.0, (float) $fresh->awarded_marks);
            $this->assertSame($this->lecturerId, (int) $fresh->marked_by);
            $this->assertNotNull($fresh->marked_at);
        }

        $this->asLecturer()
            ->post(route('teacher.online_exams.results.finalize', $this->submissionId))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            \App\Models\OnlineExamSubmission::STATUS_FINALIZED,
            OnlineExamSubmission::find($this->submissionId)->status
        );
    }

    public function test_an_automatic_question_cannot_have_its_marks_overridden(): void
    {
        $this->asStudent()->postJson($this->saveUrl(), [
            'submission_id' => $this->submissionId,
            'question_id' => $this->questionIds['mcq'],
            'answer_revision' => 1,
            'selected_option' => 'b',
        ])->assertOk();

        $this->asStudent()->post(route('student.online_exam.submit', $this->examId), [
            'submission_id' => $this->submissionId,
        ])->assertRedirect();

        $mcq = OnlineExamAnswer::where('submission_id', $this->submissionId)
            ->where('question_id', $this->questionIds['mcq'])->firstOrFail();

        $response = $this->asLecturer()->post(route('teacher.online_exams.answers.mark', $mcq->id), [
            'answer_id' => $mcq->id,
            'awarded_marks' => 1,
            'teacher_comment' => 'should not be allowed',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();

        $this->assertSame(5.0, (float) $mcq->fresh()->awarded_marks,
            'An objective mark must be the calculated one, whatever a marker submits.');
    }

    public function test_marker_sees_the_full_question_statement_and_the_exact_response(): void
    {
        $this->asStudent()->postJson($this->saveUrl(), [
            'submission_id' => $this->submissionId,
            'question_id' => $this->questionIds['essay'],
            'answer_revision' => 1,
            'answer_text' => '<p>Because <em>price</em> and quantity move inversely.</p>',
        ])->assertOk();


        $this->asLecturer()
            ->get(route('teacher.online_exams.results', ['exam' => $this->examId, 'submission' => $this->submissionId]))
            ->assertOk()
            ->assertSee('Explain the law of demand.', false)
            ->assertSee('Because', false);
    }

    public function test_marker_sees_an_explicit_label_not_a_blank_for_a_question_with_no_stored_text(): void
    {
        DB::table('online_exam_questions')->where('id', $this->questionIds['short'])
            ->update(['question' => '<p><br></p>']);

        // The paper is handed in first: a marker reviewing results is looking at a
        // SUBMITTED paper, and this is the situation the blank label was reported in.
        $this->asStudent()->post(route('student.online_exam.submit', $this->examId), [
            'submission_id' => $this->submissionId,
        ])->assertRedirect();

        $this->asLecturer()
            ->get(route('teacher.online_exams.results', ['exam' => $this->examId, 'submission' => $this->submissionId]))
            ->assertOk()
            // The blank-label failure on exam 20 was
            // `Str::limit(strip_tags($question), 90)` rendering to an empty string, so
            // the marker could not tell which question they were deciding.
            ->assertSee(QuestionPrompt::EMPTY_LABEL)
            // ...and the OTHER questions are still rendered normally, so the label
            // distinguishes a fault rather than blanking the whole paper.
            ->assertSee('Explain the law of demand.', false);
    }
}
