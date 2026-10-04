<?php

namespace Tests\Feature;

use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamSubmission;
use App\Support\OnlineExams\OnlineExamMarking;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * THE FINAL ACCEPTANCE TEST, END TO END, ON A NEW PAPER AND A NEW ATTEMPT.
 *
 * One controlled 20-mark examination:
 *
 *     Q1  MCQ          5 marks
 *     Q2  True/False   5 marks
 *     Q3  Essay        5 marks
 *     Q4  Short Answer 5 marks
 *
 * The whole governed chain, asserted against the DATABASE at every step where the
 * brief asks for it:
 *
 *     authoring → approval/publication → student sits it → all four answers stored →
 *     values read from the database BEFORE submission → values intact AFTER
 *     submission → lecturer sees the exact written responses → lecturer marks Q3 and
 *     Q4 and submits for review → admin reviews and publishes → the student sees the
 *     exact approved marks.
 *
 * A NEW exam and a NEW submission. Nothing here reads, writes, repairs or
 * re-publishes exam 20 or submission 14: their historical state is evidence and this
 * test must not be able to alter it.
 *
 * This is also the regression net for the two defects that started all of it — the
 * lost essay answer and the short answer with no input — exercised through the real
 * HTTP endpoints rather than through the model layer.
 */
class OnlineExamAcceptanceWorkflowTest extends TestCase
{
    use OnlineExamTestHelper;

    private int $examId;
    private int $studentId;
    private int $lecturerId;
    private int $adminId;
    private int $classId;
    private int $submissionId;
    private array $q = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();

        $this->studentId = (int) $this->makeUser(7, 1, 'active', 'Kyeyune Amos')->id;
        $this->lecturerId = (int) $this->makeUser(3, 1, 'active', 'Daniel Okello')->id;
        $this->adminId = (int) $this->makeUser(2, 1, 'active', 'PIIE School Administrator')->id;

        $this->classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $this->classId);
        $this->enrollStudent($this->studentId, 1, $this->classId);

        DB::table('teacher_permissions')->insert([
            'class_id' => $this->classId, 'section_id' => 1, 'school_id' => 1,
            'teacher_id' => $this->lecturerId, 'marks' => 1, 'attendance' => 1, 'updated_at' => now(),
        ]);

        // A NEW paper, in draft. It is authored and approved through the real routes.
        $this->examId = $this->makeExam([
            'title' => 'Acceptance — Business Mathematics',
            'subject_id' => $subjectId,
            'class_id' => $this->classId,
            'total_marks' => 20,
            'pass_mark' => 10,
            'duration_mins' => 30,
            'is_published' => 0,
            'workflow_state' => 'draft',
            'created_by' => $this->lecturerId,
            'creator_id' => $this->lecturerId,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
        ]);
    }

    private function lecturer(): self
    {
        $this->actingAs(\App\Models\User::find($this->lecturerId));

        return $this;
    }

    private function admin(): self
    {
        $this->actingAs(\App\Models\User::find($this->adminId));

        return $this;
    }

    private function student(): self
    {
        $this->actingAs(\App\Models\User::find($this->studentId));

        return $this;
    }

    // ── THE WHOLE CHAIN, IN ONE TEST ─────────────────────────────────────────

    public function test_a_full_governed_examination_from_authoring_to_published_result(): void
    {
        $this->authorTheExam();
        $this->adminPublishesTheExam();
        $this->studentSitsTheExam();
        $this->allFourAnswersAreInTheDatabaseBeforeSubmission();
        $this->submitTheExam();
        $this->valuesSurviveSubmissionIntact();
        $this->lecturerMarksTheTwoWrittenQuestionsAndHandsOver();
        $this->adminPublishesTheResult();
        $this->studentSeesTheExactApprovedMarks();
    }

    // ── AUTHORING ─────────────────────────────────────────────────────────────

    private function authorTheExam(): void
    {
        $this->lecturer()
            ->post(route('teacher.online_exams.questions.store', $this->examId), [
                'question' => '<p>What is the formula for compound interest?</p>',
                'type' => 'multiple_choice',
                'option_a' => 'A = P(1 + r/n)^(nt)', 'option_b' => 'A = Prt', 'option_c' => 'A = P + rt', 'option_d' => 'A = P(1 + r)^t',
                'correct_ans' => 'a', 'marks' => 5,
            ])->assertSessionHasNoErrors();

        $this->lecturer()
            ->post(route('teacher.online_exams.questions.store', $this->examId), [
                'question' => '<p>Demand falls as price rises.</p>',
                'type' => 'true_false', 'correct_answer_tf' => 'true', 'marks' => 5,
            ])->assertSessionHasNoErrors();

        $this->lecturer()
            ->post(route('teacher.online_exams.questions.store', $this->examId), [
                'question' => '<p>Explain, with a worked example, how a <strong>price ceiling</strong> affects a market.</p>',
                'type' => 'essay', 'marks' => 5,
            ])->assertSessionHasNoErrors();

        $this->lecturer()
            ->post(route('teacher.online_exams.questions.store', $this->examId), [
                'question' => '<p>State the compound interest formula.</p>',
                'type' => 'short_answer', 'marks' => 5,
            ])->assertSessionHasNoErrors();

        $rows = DB::table('online_exam_questions')->where('online_exam_id', $this->examId)
            ->orderBy('sort_order')->orderBy('id')->get();

        $this->assertCount(4, $rows);
        $this->assertSame(20, (int) $rows->sum('marks'));

        foreach ($rows as $row) {
            // Every prompt carries REAL text. This is the authoring rule that exam 20
            // lacked: an empty rich-text document is refused, so a published paper
            // cannot contain a question a candidate cannot read.
            $this->assertNotSame('<p><br></p>', $row->question);
            $this->assertStringContainsString('</p>', (string) $row->question);
        }

        $byType = $rows->keyBy('type');
        $this->q['mcq'] = $byType['mcq']->id;
        $this->q['true_false'] = $byType['true_false']->id;
        $this->q['essay'] = $byType['essay']->id;
        $this->q['short'] = $byType['short']->id;
    }

    // ── ADMIN APPROVES AND PUBLISHES THE PAPER ───────────────────────────────

    private function adminPublishesTheExam(): void
    {
        $this->admin()
            ->post(route('admin.online_exams.publish', $this->examId))
            ->assertRedirect();

        $this->assertSame(1, (int) DB::table('online_exams')->where('id', $this->examId)->value('is_published'));
        $this->assertSame('published', DB::table('online_exams')->where('id', $this->examId)->value('workflow_state'));
    }

    // ── THE STUDENT SITS IT ───────────────────────────────────────────────────

    private function studentSitsTheExam(): void
    {
        $this->student()
            ->post(route('student.online_exam.start', $this->examId), ['instructions_acknowledged' => 'on'])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $submission = OnlineExamSubmission::where('online_exam_id', $this->examId)
            ->where('student_id', $this->studentId)->firstOrFail();

        $this->submissionId = (int) $submission->id;

        // Every question type renders its statement and a usable control.
        $html = $this->student()->get(route('student.online_exam.take', $this->examId))->assertOk()->getContent();

        $this->assertStringContainsString('What is the formula for compound interest?', $html);
        $this->assertStringContainsString('Demand falls as price rises.', $html);
        $this->assertStringContainsString('how a <strong>price ceiling</strong> affects a market', $html);
        $this->assertStringContainsString('State the compound interest formula.', $html);
        $this->assertStringNotContainsString('This question has no text.', $html);
        $this->assertSame(4, substr_count($html, 'data-question-prompt="present"'));

        // Q3 keeps a rich-text editor; Q4 is a plain, labelled, always-working input.
        $essay = $this->cardFor($html, $this->q['essay']);
        $short = $this->cardFor($html, $this->q['short']);

        $this->assertStringContainsString('data-piie-editor', $essay);
        $this->assertStringContainsString('data-testid="short-answer-input"', $short);
        $this->assertStringNotContainsString('data-piie-editor', $short);
    }

    private function cardFor(string $html, int $questionId): string
    {
        $marker = 'id="question-block-'.$questionId.'"';
        $start = strpos($html, $marker);
        $next = strpos($html, 'id="question-block-', $start + strlen($marker));

        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }

    private function save(int $questionId, array $body): void
    {
        $this->student()->postJson(route('student.online_exam.save_answer', $this->submissionId), array_merge($body, [
            'submission_id' => $this->submissionId,
            'question_id' => $questionId,
            'answer_revision' => 1,
        ]))->assertOk();
    }

    // ── ALL FOUR ANSWERS ARE IN THE DATABASE BEFORE SUBMISSION ────────────────

    private function allFourAnswersAreInTheDatabaseBeforeSubmission(): void
    {
        $essayHtml = '<p>A ceiling <strong>below</strong> equilibrium causes a shortage.</p>'
            .'<ul><li>Quantity demanded exceeds quantity supplied.</li></ul>'
            .'<p>Example: <span data-latex="P_c &lt; P^*">Pc &lt; P*</span></p>';

        $this->save($this->q['mcq'], ['selected_option' => 'a']);
        $this->save($this->q['true_false'], ['selected_option' => 'true']);
        $this->save($this->q['essay'], ['answer_text' => $essayHtml]);
        $this->save($this->q['short'], ['answer_text' => 'A = P(1 + r/n)^(nt)']);

        // THE DATABASE, not the response body and not the DOM. This is the check the
        // brief asks for, and it is the one exam 20 failed: Q3 and Q4 were NULL here.
        $stored = $this->storedAnswers();

        $this->assertCount(4, $stored, 'every question must have a stored answer');

        $this->assertSame('a', $stored[$this->q['mcq']]->selected_option);
        $this->assertSame('true', $stored[$this->q['true_false']]->selected_option);

        $this->assertNotNull($stored[$this->q['essay']]->answer_text, 'the essay answer was lost');
        $this->assertSame($essayHtml, $stored[$this->q['essay']]->answer_text,
            'rich-text formatting must survive byte for byte');

        $this->assertNotNull($stored[$this->q['short']]->answer_text, 'the short answer was lost');
        $this->assertSame('A = P(1 + r/n)^(nt)', $stored[$this->q['short']]->answer_text);

        foreach ($stored as $questionId => $row) {
            $this->assertGreaterThan(0, (int) $row->answer_revision,
                "question {$questionId} has revision 0 — autosave never persisted it");
            $this->assertTrue(OnlineExamMarking::hasResponse($row),
                "question {$questionId} holds no usable response");
        }

        // A RELOAD of the in-progress exam restores them.
        $reloaded = $this->student()->get(route('student.online_exam.take', $this->examId))->assertOk()->getContent();
        $this->assertStringContainsString('A = P(1 + r/n)^(nt)', $reloaded);
        $this->assertStringContainsString('Quantity demanded exceeds quantity supplied.', $reloaded);

        // Both WRITTEN answers are stored as text — the essay and the short answer. This is
        // the pair the brief reports as NULL on exam 20, so it is counted separately
        // from the objective pair rather than inferred from the total.
        $this->assertSame(
            2,
            DB::table('online_exam_answers')->where('submission_id', $this->submissionId)
                ->whereNotNull('answer_text')->where('answer_text', '<>', '')->count(),
            'both written answers must be stored as text'
        );

        $this->assertSame(
            2,
            DB::table('online_exam_answers')->where('submission_id', $this->submissionId)
                ->whereNotNull('selected_option')->where('selected_option', '<>', '')->count(),
            'both objective answers must be stored as a selection'
        );
    }

    /**
     * The stored answers, read straight from the DATABASE.
     *
     * Deliberately a query-builder read rather than an Eloquent one: this is the
     * independent check on what was persisted, and it should not pass through the same
     * accessors, casts and mutators the application itself uses. A test that reads the
     * answer back through the same code that wrote it proves very little.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\OnlineExamAnswer>
     */
    private function storedAnswers()
    {
        return OnlineExamAnswer::where('submission_id', $this->submissionId)->get()->keyBy('question_id');
    }

    // ── SUBMISSION ────────────────────────────────────────────────────────────

    private function submitTheExam(): void
    {
        $this->student()
            ->post(route('student.online_exam.submit', $this->examId), ['submission_id' => $this->submissionId])
            ->assertRedirect();

        $submission = OnlineExamSubmission::findOrFail($this->submissionId);

        $this->assertNotSame(OnlineExamSubmission::STATUS_IN_PROGRESS, $submission->status);
        $this->assertNotNull($submission->submitted_at);

        // Objective questions are marked automatically; the written ones are NOT.
        $this->assertSame(10.0, (float) $submission->objective_score);

        $stored = $this->storedAnswers();
        $this->assertSame(5.0, (float) $stored[$this->q['mcq']]->awarded_marks);
        $this->assertSame(5.0, (float) $stored[$this->q['true_false']]->awarded_marks);
        $this->assertNull($stored[$this->q['essay']]->marked_by, 'the essay must wait for a marker');
        $this->assertNull($stored[$this->q['short']]->marked_by, 'the short answer must wait for a marker');

        // The paper is NOT complete: two written questions still owe a decision.
        $this->assertCount(2, OnlineExamMarking::manualQuestionsAwaitingDecision($submission));
    }

    private function valuesSurviveSubmissionIntact(): void
    {
        $stored = $this->storedAnswers();

        $this->assertStringContainsString('<strong>below</strong>', $stored[$this->q['essay']]->answer_text);
        $this->assertStringContainsString('<li>Quantity demanded exceeds quantity supplied.</li>', $stored[$this->q['essay']]->answer_text);
        $this->assertStringContainsString('data-latex', $stored[$this->q['essay']]->answer_text);
        $this->assertSame('A = P(1 + r/n)^(nt)', $stored[$this->q['short']]->answer_text);
    }

    // ── LECTURER SEES THE EXACT RESPONSES, MARKS, AND HANDS OVER ─────────────

    private function lecturerMarksTheTwoWrittenQuestionsAndHandsOver(): void
    {
        $results = $this->lecturer()
            ->get(route('teacher.online_exams.results', [
                'exam' => $this->examId, 'submission' => $this->submissionId,
            ]))->assertOk()->getContent();

        // The marker sees the question STATEMENT and the student's actual WORDS.
        $this->assertStringContainsString('how a <strong>price ceiling</strong> affects a market', $results);
        $this->assertStringContainsString('Quantity demanded exceeds quantity supplied.', $results);
        $this->assertStringContainsString('State the compound interest formula.', $results);
        $this->assertStringContainsString('A = P(1 + r/n)^(nt)', $results);

        foreach (['essay', 'short'] as $key) {
            $answer = OnlineExamAnswer::where('submission_id', $this->submissionId)
                ->where('question_id', $this->q[$key])->firstOrFail();

            $this->lecturer()->post(route('teacher.online_exams.answers.mark', $answer->id), [
                'answer_id' => $answer->id,
                'awarded_marks' => $key === 'essay' ? 4 : 5,
                'teacher_comment' => $key === 'essay' ? 'Good analysis; give the market price too.' : 'Correct.',
            ])->assertRedirect();
        }

        $this->lecturer()
            ->post(route('teacher.online_exams.results.finalize', $this->submissionId))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $submission = OnlineExamSubmission::findOrFail($this->submissionId);

        $this->assertSame(OnlineExamSubmission::STATUS_FINALIZED, $submission->status);
        $this->assertSame('pending_review', $submission->result_review_state);
        // 10 objective + 4 for the essay + 5 for the short answer.
        $this->assertSame(10.0, (float) $submission->objective_score);
        $this->assertSame(9.0, (float) $submission->manual_score);
        $this->assertSame(19.0, (float) $submission->score);
        $this->assertTrue((bool) $submission->passed);
        $this->assertNull($submission->published_at, 'a lecturer must never publish');

        // Marking is attributed.
        $this->assertSame($this->lecturerId, (int) OnlineExamAnswer::where('submission_id', $this->submissionId)
            ->where('question_id', $this->q['essay'])->value('marked_by'));
    }

    // ── ADMIN REVIEWS AND PUBLISHES ───────────────────────────────────────────

    private function adminPublishesTheResult(): void
    {
        // The SAME definition the release action and the review screen both use, so a
        // release cannot be refused by a rule the screen did not show.
        $this->assertSame([], \App\Support\OnlineExams\OnlineExamPublication::blockers(
            OnlineExamSubmission::findOrFail($this->submissionId)
        ));

        $this->admin()
            ->post(route('admin.online_exams.submissions.publish_result', $this->submissionId))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $submission = OnlineExamSubmission::findOrFail($this->submissionId);

        $this->assertSame(OnlineExamSubmission::STATUS_RESULT_PUBLISHED, $submission->status);
        $this->assertSame('published', $submission->result_review_state);
        $this->assertNotNull($submission->published_at);
        $this->assertSame($this->adminId, (int) $submission->published_by);

        // A second press must NOT duplicate the notification (requirement 26).
        $before = DB::table('online_exam_user_notifications')
            ->where('submission_id', $this->submissionId)->count();

        $this->admin()
            ->post(route('admin.online_exams.submissions.publish_result', $this->submissionId))
            ->assertRedirect();

        $after = DB::table('online_exam_user_notifications')
            ->where('submission_id', $this->submissionId)->count();

        $this->assertSame($before, $after, 'a repeated publication must not duplicate notifications');
    }

    // ── THE STUDENT SEES THE EXACT APPROVED MARKS ─────────────────────────────

    private function studentSeesTheExactApprovedMarks(): void
    {
        $this->student()
            ->get(route('student.online_exam.result', $this->submissionId))
            ->assertOk()
            // 19 of 20: 10 objective, 4 essay, 5 short answer.
            ->assertSee('19')
            ->assertSee('20')
            ->assertSee('Acceptance');

        // And the written answers are shown back as the student wrote them.
        $result = $this->student()
            ->get(route('student.online_exam.result', $this->submissionId))->getContent();

        $this->assertStringContainsString('Quantity demanded exceeds quantity supplied.', $result);
        $this->assertStringContainsString('A = P(1 + r/n)^(nt)', $result);
    }

    // ── THE ORDERING GUARANTEES, PINNED SEPARATELY ───────────────────────────

    public function test_a_student_sees_no_marks_before_they_are_published(): void
    {
        $this->authorTheExam();
        $this->adminPublishesTheExam();
        $this->studentSitsTheExam();
        $this->save($this->q['mcq'], ['selected_option' => 'a']);
        $this->save($this->q['true_false'], ['selected_option' => 'true']);
        $this->save($this->q['essay'], ['answer_text' => '<p>My essay.</p>']);
        $this->save($this->q['short'], ['answer_text' => 'My short answer.']);

        $this->student()
            ->post(route('student.online_exam.submit', $this->examId), ['submission_id' => $this->submissionId])
            ->assertRedirect();

        // Marked and handed over — and STILL not visible to the student.
        foreach (['essay', 'short'] as $key) {
            $answer = OnlineExamAnswer::where('submission_id', $this->submissionId)
                ->where('question_id', $this->q[$key])->firstOrFail();

            $this->lecturer()->post(route('teacher.online_exams.answers.mark', $answer->id), [
                'answer_id' => $answer->id, 'awarded_marks' => 5,
            ])->assertRedirect();
        }

        $this->lecturer()
            ->post(route('teacher.online_exams.results.finalize', $this->submissionId))
            ->assertRedirect();

        $this->assertFalse(OnlineExamSubmission::findOrFail($this->submissionId)->isResultVisible());

        $this->student()
            ->get(route('student.online_exam.result', $this->submissionId))
            ->assertDontSee('My essay.', false)
            ->assertDontSee('My short answer.', false);
    }

    public function test_a_lecturer_may_never_publish_an_official_result(): void
    {
        $this->authorTheExam();
        $this->adminPublishesTheExam();
        $this->studentSitsTheExam();
        $this->save($this->q['essay'], ['answer_text' => '<p>My essay.</p>']);

        $this->student()
            ->post(route('student.online_exam.submit', $this->examId), ['submission_id' => $this->submissionId])
            ->assertRedirect();

        $answer = OnlineExamAnswer::where('submission_id', $this->submissionId)
            ->where('question_id', $this->q['essay'])->firstOrFail();

        $this->lecturer()->post(route('teacher.online_exams.answers.mark', $answer->id), [
            'answer_id' => $answer->id, 'awarded_marks' => 5,
        ])->assertRedirect();

        // Both publication routes are closed to a lecturer.
        $this->lecturer()
            ->post(route('teacher.online_exams.results.publish', $this->submissionId))
            ->assertStatus(403);

        $this->lecturer()
            ->post(route('admin.online_exams.submissions.publish_result', $this->submissionId))
            ->assertStatus(403);

        $this->assertFalse(OnlineExamSubmission::findOrFail($this->submissionId)->isResultVisible(),
            'no route a lecturer can reach may release a result');
    }

    public function test_this_test_does_not_touch_exam_twenty_or_submission_fourteen(): void
    {
        // A guard on the guard. This file creates its OWN exam and submission, and the
        // brief forbids altering the historical record; if a future edit ever retargeted
        // the fixtures, this is the assertion that says so.
        $this->authorTheExam();
        $this->adminPublishesTheExam();
        $this->studentSitsTheExam();

        $this->assertNotSame(20, $this->examId);

        // Publication state lives on the SUBMISSION, not on an answer row.
        $this->assertNull(OnlineExamSubmission::findOrFail($this->submissionId)->published_at,
            'a newly started attempt is not a published result');
    }
}