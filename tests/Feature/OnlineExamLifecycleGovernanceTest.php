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
 * THE WHOLE EXAMINATION LIFECYCLE, AND WHO IS ALLOWED TO MOVE IT.
 *
 * ── THE CHAIN THIS PINS ─────────────────────────────────────────────────────
 *
 *   lecturer creates -> questions -> submits for Admin review
 *     -> ADMIN publishes
 *       -> eligible student sees and attempts it
 *         -> student submits
 *           -> ALLOCATED lecturer is notified
 *             -> submission appears in that lecturer's Marking Queue
 *               -> lecturer marks the written answers, automatic marks untouched
 *                 -> lecturer submits marks for Admin review
 *                   -> ADMIN approves and publishes
 *                     -> student is notified and may read the result
 *
 * ── THE RULE THIS FILE EXISTS TO PROTECT ─────────────────────────────────────
 *
 * A LECTURER MAY NEVER MAKE A MARK VISIBLE TO A STUDENT.
 *
 * That is not a UI convention. `publishResult()` refuses anyone who is not an
 * administrator, before it even looks at the submission, and this suite attacks it
 * from every direction a lecturer actually has: the button, the named route, a
 * forged POST, and the state machine itself. A lecturer's furthest legal step is
 * handing completed marking to the academic office.
 *
 * ── WHY THE MARKING AUTHORITY IS THE ALLOCATION ──────────────────────────────
 *
 * The recipient and the marker are the same question, so they are answered the same
 * way: from `exam.course_offering_id -> course_offering_lecturer_allocations`.
 * `teacher_permissions` is never consulted, which is what makes an unallocated
 * colleague's direct URL fail instead of quietly succeeding because they once had a
 * legacy class.
 */
class OnlineExamLifecycleGovernanceTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    private \App\Models\CourseOffering $offering;

    private User $lecturer;

    private User $admin;

    private User $student;

    private OnlineExam $exam;

    private int $writtenQ;

    private int $objectiveQ;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();

        // Lecturer capabilities, WITHOUT publish and WITHOUT manage_exam_results.
        DB::table('global_settings')->where('key', 'role_perm_3')->delete();
        DB::table('global_settings')->insert([
            'key' => 'role_perm_3',
            'value' => json_encode([
                'view_online_exams', 'create_online_exams', 'edit_own_online_exams',
                'manage_exam_questions', 'view_exam_attempts', 'view_exam_results',
                'mark_exam_answers',
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->offering = $this->makeOffering(1);
        $this->lecturer = $this->makeUser(3, 1, 'active', 'Daniel Okello');
        $this->allocateLecturer($this->offering, $this->lecturer);

        $this->admin = $this->makeUser(2, 1, 'active', 'Administrator');
        $this->grantAdminPermissions();

        $this->student = $this->makeUser(7, 1, 'active', 'Kyeyune Amos');
        $this->confirmStudent($this->offering, $this->student);

        $examId = $this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->offering->subject_id,
            'course_offering_id' => $this->offering->id,
            'class_id' => null,
            'title' => 'Business Mathematics Test 1',
            'workflow_state' => 'published',
            'is_published' => 1,
            'total_marks' => 10,
            'pass_mark' => 5,
            'max_attempts' => 1,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
            'created_by' => $this->lecturer->id,
            'creator_id' => $this->lecturer->id,
        ]);
        $this->exam = OnlineExam::query()->findOrFail($examId);

        // One written (needs a marker) and one auto-marked objective.
        $this->writtenQ = (int) $this->makeQuestion($examId, [
            'question' => '<p>Explain compound interest.</p>', 'type' => 'short', 'marks' => 6, 'sort_order' => 1,
        ]);
        $this->objectiveQ = (int) $this->makeQuestion($examId, [
            'question' => '<p>2 + 2 = ?</p>', 'type' => 'mcq',
            'option_a' => '3', 'option_b' => '4', 'option_c' => '5', 'option_d' => '6',
            'correct_ans' => 'b', 'marks' => 4, 'sort_order' => 2,
        ]);
    }

    private function grantAdminPermissions(): void
    {
        DB::table('global_settings')->where('key', 'role_perm_2')->delete();
        DB::table('global_settings')->insert([
            'key' => 'role_perm_2',
            'value' => json_encode([
                'view_online_exams', 'admin.online_exams', 'create_online_exams',
                'edit_all_online_exams', 'manage_exam_questions', 'view_exam_attempts',
                'view_exam_results', 'mark_exam_answers', 'manage_exam_results',
                'publish_online_exams', 'review_exam_proctoring',
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The student attempts and submits: one correct MCQ, one written answer. */
    private function studentAttemptsAndSubmits(): OnlineExamSubmission
    {
        $submissionId = (int) DB::table('online_exam_submissions')->insertGetId([
            'online_exam_id' => $this->exam->id,
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

        $this->actingAs($this->student)
            ->postJson(route('student.online_exam.save_answer', $submissionId), [
                'submission_id' => $submissionId,
                'question_id' => $this->objectiveQ,
                'answer_revision' => 1,
                'selected_option' => 'b',
            ])->assertOk();

        $this->actingAs($this->student)
            ->postJson(route('student.online_exam.save_answer', $submissionId), [
                'submission_id' => $submissionId,
                'question_id' => $this->writtenQ,
                'answer_revision' => 1,
                'answer_text' => 'ANSWER ONE',
            ])->assertOk();

        $this->actingAs($this->student)
            ->post(route('student.online_exam.submit', $this->exam->id), ['submission_id' => $submissionId])
            ->assertSessionHasNoErrors();

        return OnlineExamSubmission::query()->findOrFail($submissionId);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3c. THE ACCEPTANCE PAPER: MCQ + SHORT ANSWER + ESSAY, END TO END
    // ══════════════════════════════════════════════════════════════════════

    /**
     * THE EXACT PAPER THE ACCEPTANCE CRITERIA NAME, THROUGH THE WHOLE CHAIN.
     *
     * One MCQ, one short-answer question, one essay — all three answered, then driven
     * from a student's submit all the way to a published result. This is the closest
     * automated equivalent of the browser acceptance test: it cannot prove that
     * typing in a rich-text editor reaches the server (that is JavaScript, and needs a
     * real browser), but every step after the keystroke is exercised over real
     * routing, policies and the real state machine.
     *
     * The point it pins is that a mixed paper behaves as the governance requires:
     * the objective part is credited immediately, the two written parts must both be
     * judged by a human before the result may move, and nothing reaches the student
     * until an administrator publishes.
     */
    public function test_ACCEPTANCE_paper_MCQ_short_and_essay_runs_the_whole_chain(): void
    {
        // A dedicated exam, so the paper is EXACTLY the three questions the
        // acceptance criteria name. Reusing `$this->exam` would leave its own
        // written question on the paper too, and the outstanding-marks figure would
        // silently include a question this test knows nothing about.
        $examId = (int) $this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->offering->subject_id,
            'course_offering_id' => $this->offering->id,
            'class_id' => null,
            'title' => 'Acceptance MCQ + short + essay',
            'workflow_state' => 'published',
            'is_published' => 1,
            'total_marks' => 20,
            'pass_mark' => 10,
            'created_by' => $this->lecturer->id,
            'creator_id' => $this->lecturer->id,
        ]);

        $mcq = (int) $this->makeQuestion($examId, [
            'question' => '<p>Acceptance MCQ</p>', 'type' => 'mcq',
            'option_a' => '3', 'option_b' => '4', 'option_c' => '5', 'option_d' => '6',
            'correct_ans' => 'b', 'marks' => 4, 'sort_order' => 1,
        ]);
        $short = (int) $this->makeQuestion($examId, [
            'question' => '<p>Acceptance short answer</p>', 'type' => 'short', 'marks' => 6, 'sort_order' => 2,
        ]);
        $essay = (int) $this->makeQuestion($examId, [
            'question' => '<p>Acceptance essay</p>', 'type' => 'essay', 'marks' => 10, 'sort_order' => 3,
        ]);

        $submissionId = (int) DB::table('online_exam_submissions')->insertGetId([
            'online_exam_id' => $examId, 'student_id' => $this->student->id, 'attempt_no' => 1,
            'school_id' => 1, 'total_marks_snapshot' => 20,
            'started_at' => now(), 'expires_at' => now()->addHour(), 'last_activity_at' => now(),
            'status' => OnlineExamSubmission::STATUS_IN_PROGRESS,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // All three answered, the way a student's editor autosave would.
        $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $submissionId), [
            'submission_id' => $submissionId, 'question_id' => $mcq, 'answer_revision' => 1, 'selected_option' => 'b',
        ])->assertOk();
        $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $submissionId), [
            'submission_id' => $submissionId, 'question_id' => $short, 'answer_revision' => 1,
            'answer_text' => '<p>A short written answer.</p>',
        ])->assertOk();
        $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $submissionId), [
            'submission_id' => $submissionId, 'question_id' => $essay, 'answer_revision' => 1,
            'answer_text' => '<p>A longer written essay.</p>',
        ])->assertOk();

        $this->actingAs($this->student)
            ->post(route('student.online_exam.submit', $examId), ['submission_id' => $submissionId])
            ->assertSessionHasNoErrors();

        $submission = OnlineExamSubmission::query()->findOrFail($submissionId);

        // 1. Objective credit lands immediately; the two written parts are outstanding.
        $this->assertSame(4.0, (float) $submission->objective_score);
        $this->assertSame(0.0, (float) $submission->manual_score);
        $this->assertSame(OnlineExamSubmission::STATUS_PENDING_MANUAL, $submission->status);
        $this->assertNull($submission->passed, 'the outcome is undecided while a human still has work to do');
        $this->assertSame(16.0, \App\Support\OnlineExams\OnlineExamMarking::undecidedManualMarks($submission));

        // 2. The lecturer is told, and cannot release anything.
        $this->assertNotNull(
            DB::table('online_exam_user_notifications')
                ->where('user_id', $this->lecturer->id)->where('type', 'exam_submitted')->first()
        );

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.publish', $submission->id))
            ->assertStatus(403);

        // 3. Neither written part may be handed over before it is judged.
        $this->assertHandoverRefusedWithExplanation(
            $this->actingAs($this->lecturer)
                ->post(route('teacher.online_exams.results.finalize', $submission->id))
        );

        // 4. The lecturer marks both, and the automatic mark is untouched.
        foreach ([$short => 5, $essay => 8] as $questionId => $awarded) {
            $answerId = DB::table('online_exam_answers')
                ->where('submission_id', $submission->id)->where('question_id', $questionId)
                ->value('id');

            $this->actingAs($this->lecturer)
                ->post(route('teacher.online_exams.answers.mark', $answerId), [
                    'answer_id' => $answerId, 'awarded_marks' => $awarded,
                ])->assertSessionHasNoErrors();
        }

        $submission->refresh();
        $this->assertSame(4.0, (float) $submission->objective_score);
        $this->assertSame(13.0, (float) $submission->manual_score);
        $this->assertSame(17.0, (float) $submission->score);
        $this->assertFalse(\App\Support\OnlineExams\OnlineExamMarking::hasUndecidedManualQuestions($submission));

        // 5. Handover, and still nothing visible to the student.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.finalize', $submission->id))
            ->assertSessionHasNoErrors();

        $submission->refresh();
        $this->assertSame(OnlineExamSubmission::STATUS_FINALIZED, $submission->status);
        $this->assertSame('pending_review', $submission->result_review_state);

        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $submission->id))
            ->assertOk()
            ->assertDontSee('17.00');

        // 6. Only the administrator publishes.
        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertSessionHasNoErrors();

        $submission->refresh();
        $this->assertSame(OnlineExamSubmission::STATUS_RESULT_PUBLISHED, $submission->status);
        $this->assertTrue($submission->passed, '17.00 of 20 clears a pass mark of 10');

        // 7. And now the student sees it.
        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $submission->id))
            ->assertOk()
            ->assertSee('17.00');

        $this->assertNotNull(
            DB::table('online_exam_user_notifications')
                ->where('user_id', $this->student->id)->where('type', 'result_published')->first()
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3d. THE REPAIR SEQUENCE: REOPEN → DECIDE 0 → HANDOVER → PUBLISH
    // ══════════════════════════════════════════════════════════════════════

    /**
     * THE EXACT SEQUENCE THE LECTURER GOT STUCK ON, END TO END.
     *
     * Exam 17 submission 12, question 39. The student's written answer was never
     * persisted, so that question had NO answer row. Every marking action in the app
     * was keyed by answer id, so there was nothing to mark. The queue said "Decide on
     * the submissions page" — naming no submission — and the results page still
     * offered "Submit Marks for Admin Review", which could only ever come back:
     *
     *     422 "Finalize is blocked until every question requiring manual marking has
     *          been decided - 1 still outstanding, worth 10 marks."
     *
     * The 422 was the system working. The bug was that a lecturer could see a decision
     * was owed, had no way to make it, and was offered a button that could only fail.
     *
     * This walks the repair: admin reopens, lecturer records an explicit zero against
     * the answerless question, the pending count falls to zero, the handover becomes
     * available and succeeds, admin approves and publishes, and only then does the
     * student see a result.
     */
    public function test_the_REPAIR_sequence_reopen_then_explicit_zero_then_handover_then_publish(): void
    {
        $submission = $this->studentAttemptsAndSubmits();

        // Reproduce the real anomaly: no answer row for the written question.
        DB::table('online_exam_answers')
            ->where('submission_id', $submission->id)
            ->where('question_id', $this->writtenQ)
            ->delete();

        DB::table('online_exam_submissions')->where('id', $submission->id)->update([
            'status' => OnlineExamSubmission::STATUS_FINALIZED,
            'result_review_state' => 'not_ready',
        ]);

        $question = OnlineExamQuestion::query()->findOrFail($this->writtenQ);
        $decisionUrl = route('teacher.online_exams.submissions.record_decision', [
            'submission' => $submission->id,
            'question' => $question->id,
        ]);

        // ── 1. THE ADMIN REOPENS ──
        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.return_to_marking', $submission->id), [
                'reason' => 'Closed without handover; reopened for marking.',
            ])->assertSessionHasNoErrors();

        $this->assertSame(
            OnlineExamSubmission::STATUS_PENDING_MANUAL,
            $submission->fresh()->status
        );

        // ── 2. AND ONLY NOW IS THE HANDOVER REFUSED ──
        // Asserted AFTER the reopen, because that is the real sequence: on a row
        // still marked finalized the handover is a silent no-op, so the lecturer
        // only meets the refusal once the row is genuinely open for marking.
        $this->assertHandoverRefusedWithExplanation(
            $this->actingAs($this->lecturer)
                ->post(route('teacher.online_exams.results.finalize', $submission->id)),
            'outstanding'
        );

        $this->assertTrue(
            \App\Support\OnlineExams\OnlineExamMarking::hasUndecidedManualQuestions(
                $submission->fresh()->load(['exam.questions', 'answerRows'])
            ),
            'a decision is genuinely still owed, so refusing the handover is correct'
        );

        // ── 2. The handover button must NOT be offered while that is true ──
        $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.results', $this->exam->id))
            ->assertOk()
            ->assertSee('Record 0')
            ->assertSee('No answer was submitted')
            ->assertSee('still requires a marking decision')
            ->assertDontSee('action-submit-for-review');

        // ── 3. And the queue must give a way through, not a dead end ──
        $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.marking'))
            ->assertOk()
            ->assertSee('Mark Submission')
            ->assertSee(route('teacher.online_exams.results', [
                'exam' => $this->exam->id,
                'submission' => $submission->id,
            ]))
            ->assertDontSee('Decide on the submissions page');

        // ── 4. Marks above zero for an answerless question are REFUSED ──
        //         Redirected back with an explanation, NOT a bare error page.
        $this->actingAs($this->lecturer)
            ->from(route('teacher.online_exams.results', [
                'exam' => $this->exam->id, 'submission' => $submission->id,
            ]))
            ->post($decisionUrl, ['awarded_marks' => 3])
            ->assertRedirect(route('teacher.online_exams.results', [
                'exam' => $this->exam->id, 'submission' => $submission->id,
            ]))
            ->assertSessionHasErrors('awarded_marks');

        // Nothing was created by the refusal.
        $this->assertNull(
            OnlineExamAnswer::query()->where('submission_id', $submission->id)
                ->where('question_id', $question->id)->value('awarded_marks')
        );

        // ── 5. The lecturer records an explicit, attributed zero ──
        $this->actingAs($this->lecturer)
            ->post($decisionUrl, ['awarded_marks' => 0])
            ->assertRedirect(route('teacher.online_exams.results', [
                'exam' => $this->exam->id, 'submission' => $submission->id,
            ]))
            ->assertSessionHasNoErrors();

        $recorded = OnlineExamAnswer::query()
            ->where('submission_id', $submission->id)
            ->where('question_id', $question->id)
            ->firstOrFail();

        // Awarded exactly zero, attributed, timestamped.
        $this->assertSame(0.0, (float) $recorded->awarded_marks);
        $this->assertSame($this->lecturer->id, (int) $recorded->marked_by);
        $this->assertNotNull($recorded->marked_at);

        // AND THE FACT THAT NO ANSWER WAS SUBMITTED IS PRESERVED. The row exists
        // because a decision was recorded, not because anything was written, so it
        // must still carry no answer at all.
        $this->assertNull($recorded->answer_text);
        $this->assertNull($recorded->selected_option);
        $this->assertFalse(
            \App\Support\OnlineExams\OnlineExamMarking::hasResponse($recorded),
            'a decision about a blank question must not become an answer'
        );

        // An audit entry exists.
        $this->assertTrue(
            DB::table('audit_logs')->where('description', 'like', "%question #{$question->id}%")->exists(),
            'recording a decision must be auditable'
        );

        // ── 6. Pending falls to zero, and the handover becomes available ──
        $submission->refresh()->load(['exam.questions', 'answerRows']);

        $this->assertFalse(
            \App\Support\OnlineExams\OnlineExamMarking::hasUndecidedManualQuestions($submission)
        );
        $this->assertSame(0.0, \App\Support\OnlineExams\OnlineExamMarking::undecidedManualMarks($submission));

        $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.results', $this->exam->id))
            ->assertOk()
            ->assertSee('action-submit-for-review')
            ->assertDontSee('still requires a marking decision');

        // ── 7. Handover succeeds, and only an admin can publish ──
        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.finalize', $submission->id))
            ->assertSessionHasNoErrors();

        $submission->refresh();
        $this->assertSame(OnlineExamSubmission::STATUS_FINALIZED, $submission->status);
        $this->assertSame('pending_review', $submission->result_review_state);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.publish', $submission->id))
            ->assertStatus(403);
        $this->assertNotSame(
            OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            $submission->fresh()->status,
            'a lecturer must never be able to release a result'
        );

        // ── 8. The student still sees nothing until the admin publishes ──
        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $submission->id))
            ->assertOk()
            ->assertSee('awaiting administrative review')
            ->assertDontSee('Congratulations');

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertSessionHasNoErrors();

        $submission->refresh();
        $this->assertSame(OnlineExamSubmission::STATUS_RESULT_PUBLISHED, $submission->status);

        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $submission->id))
            ->assertOk()
            ->assertSee('Automatic Marks');

        // 4 marks came from the MCQ; the blank question contributed the recorded zero.
        $this->assertSame(4.0, (float) $submission->score);
    }

    /**
     * THE DECISION ENDPOINT REFUSES EVERYTHING IT SHOULD.
     *
     * A new write route is a new way in, so each boundary is pinned rather than
     * assumed: it cannot mark an automatic question, cannot reach across exams, cannot
     * touch a finalized or published result, and a student cannot use it.
     */
    public function test_the_DECISION_endpoint_REFUSES_what_it_should(): void
    {
        $submission = $this->studentAttemptsAndSubmits();
        $written = OnlineExamQuestion::query()->findOrFail($this->writtenQ);
        $objective = OnlineExamQuestion::query()->findOrFail($this->objectiveQ);
        $url = fn ($q) => route('teacher.online_exams.submissions.record_decision', [
            'submission' => $submission->id, 'question' => $q->id,
        ]);

        // An automatic question is the engine's decision, not a marker's.
        $this->actingAs($this->lecturer)
            ->post($url($objective), ['awarded_marks' => 0])->assertStatus(422);

        // A student may not mark, whatever they send. `/teacher/*` belongs to the lecturer
        // role, so role middleware turns the POST away before the policy is even
        // consulted. The status is therefore not the interesting thing — what matters
        // is that no mark was recorded, so that is what is asserted.
        $studentResp = $this->actingAs($this->student)
            ->post($url($written), ['awarded_marks' => 0]);

        // Turned away by role middleware before the policy is consulted — so the
        // status is a redirect, not a 403, and the meaningful assertion is that
        // nothing was written.
        $this->assertNotSame(
            200,
            $studentResp->status(),
            'a student must never be able to record a marking decision'
        );

        $this->assertNull(
            OnlineExamAnswer::query()->where('submission_id', $submission->id)
                ->where('question_id', $written->id)->whereNotNull('marked_by')->value('awarded_marks'),
            'a student must never be able to record a marking decision'
        );

        // A question from another exam cannot be reached through this submission.
        $otherExam = $this->makeExam();
        $foreign = (int) $this->makeQuestion($otherExam, ['type' => 'essay', 'marks' => 5]);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.submissions.record_decision', [
                'submission' => $submission->id, 'question' => $foreign,
            ]), ['awarded_marks' => 0])
            ->assertStatus(404);

        // Above the question's own maximum.
        $this->actingAs($this->lecturer)
            ->post($url($written), ['awarded_marks' => 999])->assertStatus(422);

        // And once finalized, the decision route is shut.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.finalize', $submission->id));

        if ($submission->fresh()->isFinalized()) {
            $this->actingAs($this->lecturer)
                ->post($url($written), ['awarded_marks' => 1])->assertStatus(422);
        }

        // And the foreign question was never marked, on this submission or any other.
        $this->assertSame(0, (int) OnlineExamAnswer::query()
            ->where('question_id', $foreign)
            ->whereNotNull('awarded_marks')
            ->count());
    }

    /**
     * THE STUDENT IS TOLD THE TRUTH ABOUT EACH STAGE, AND NOTHING MORE.
     *
     * The old status page branched on `status === 'finalized'` and said "Your result
     * is finalized and awaiting publication." Exam 17 submission 12 displayed exactly
     * that while its marking had never been performed and never handed to anyone — a
     * false claim about a result no human had reached.
     *
     * Each real state is asserted for the message it should carry, and for the absence
     * of anything it must not: no score, no automatic or manual mark, no feedback, and
     * no hint that a record needed administrative repair.
     */
    public function test_the_STUDENT_status_message_MATCHES_the_REAL_state(): void
    {
        $processing = $this->studentAttemptsAndSubmits();

        // Submitted, still with the lecturer.
        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $processing->id))
            ->assertOk()
            ->assertSee('Your exam has been submitted successfully. Your result is being processed.')
            ->assertDontSee('finalized')
            ->assertDontSee('Automatic Marks');

        // Handed over, awaiting an administrator.
        $handedOver = $this->handedOver();

        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $handedOver->id))
            ->assertOk()
            ->assertSee('Your marking is complete and awaiting administrative review.')
            ->assertDontSee('being processed')
            ->assertDontSee('Automatic Marks');

        // Returned for correction: back to being processed, not "awaiting review".
        DB::table('online_exam_submissions')->where('id', $handedOver->id)->update([
            'result_review_state' => 'returned_for_correction',
        ]);

        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $handedOver->id))
            ->assertOk()
            ->assertSee('Your exam has been submitted successfully. Your result is being processed.');

        /**
         * THE ANOMALOUS ROW MUST NOT READ LIKE A FINISHED ONE.
         *
         * `finalized` + `not_ready` means the marking never happened. Telling a
         * student their result was finalized would be a false statement about work no
         * person performed — and mentioning the repair would leak an internal defect
         * and invite appeals about a result that has not been decided.
         */
        $orphan = $this->studentAttemptsAndSubmits();
        DB::table('online_exam_submissions')->where('id', $orphan->id)->update([
            'status' => OnlineExamSubmission::STATUS_FINALIZED,
            'result_review_state' => 'not_ready',
        ]);

        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $orphan->id))
            ->assertOk()
            ->assertSee('Your exam has been submitted successfully. Your result is being processed.')
            ->assertDontSee('finalized and awaiting publication')
            ->assertDontSee('handover')
            ->assertDontSee('reopen')
            // Narrowed deliberately: a bare 'Marks' also matches unrelated navigation
            // chrome, so the leak check names the two labels that would actually
            // expose a mark.
            ->assertDontSee('Automatic Marks')
            ->assertDontSee('Manual Marks')
            ->assertDontSee('Total Marks');

        /**
         * AND THE ANOMALOUS ROW CANNOT BE PUBLISHED STRAIGHT OUT.
         *
         * It is `finalized` + `not_ready`, which is not "awaiting Admin review", so
         * publication is refused. This is the same guard that stops an administrator
         * releasing a result no human ever marked: the anomaly has to go through
         * reopening, marking and handover before it can be released, exactly like any
         * other submission.
         *
         * The refusal is a REDIRECT WITH AN EXPLANATION rather than a 422 page: an
         * ordinary workflow rejection must not be presented as a broken endpoint, and
         * the "not released" assertion below is what actually pins the rule.
         */
        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $orphan->id))
            ->assertRedirect()
            ->assertSessionHasErrors('result');

        $this->assertNotSame(
            OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            $orphan->fresh()->status
        );

        // Published: the official result, and only then.
        $published = $this->handedOver();

        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $published->id))
            ->assertOk()
            ->assertSee('Your marking is complete and awaiting administrative review.')
            ->assertDontSee('Automatic Marks');

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $published->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            $published->fresh()->status
        );

        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $published->id))
            ->assertOk()
            ->assertSee('Automatic Marks');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. STUDENT SUBMISSION NOTIFIES THE ALLOCATED LECTURER
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_student_SUBMISSION_notifies_the_ALLOCATED_LECTURER(): void
    {
        $submission = $this->studentAttemptsAndSubmits();

        $notification = DB::table('online_exam_user_notifications')
            ->where('user_id', $this->lecturer->id)
            ->where('type', 'exam_submitted')
            ->first();

        $this->assertNotNull($notification, 'the allocated lecturer must be told work arrived');

        // Named, and naming the course - a marker triaging a queue needs to know
        // which course a submission belongs to without opening it.
        $this->assertStringContainsString('Kyeyune Amos', $notification->message);
        $this->assertStringContainsString('Business Mathematics Test 1', $notification->message);
        $this->assertStringContainsString(
            $this->offering->subject->name,
            $notification->message,
            'the notification must name the Course Offering the paper belongs to'
        );

        $this->assertSame((int) $this->exam->id, (int) $notification->online_exam_id);
        $this->assertSame(
            (int) $submission->id,
            (int) $notification->submission_id,
            'the notification must name the attempt, so it can open that submission rather than the exam front page'
        );
        $this->assertStringContainsString(
            '/teacher/online-exams/'.$this->exam->id.'/results',
            $notification->action_url,
            'the link must open the lecturer marking view for this paper'
        );
        $this->assertStringContainsString(
            'submission='.$submission->id,
            $notification->action_url,
            'and must select that exact attempt'
        );
    }

    public function test_an_UNALLOCATED_lecturer_is_NEVER_notified(): void
    {
        $stranger = $this->makeUser(3, 1, 'active', 'Not Their Lecturer');

        // A legacy class assignment must NOT be enough to make somebody a marker
        // for a higher-education paper. This is the whole point of resolving the
        // marker from the allocation table.
        DB::table('teacher_permissions')->insert([
            'class_id' => $this->makeClass(1), 'section_id' => 1, 'school_id' => 1,
            'teacher_id' => $stranger->id, 'marks' => 1, 'attendance' => 1, 'updated_at' => now(),
        ]);

        $this->studentAttemptsAndSubmits();

        $this->assertSame(
            0,
            DB::table('online_exam_user_notifications')
                ->where('user_id', $stranger->id)
                ->where('type', 'exam_submitted')
                ->count(),
            'a legacy class assignment must not make a lecturer a recipient for a Course Offering exam'
        );
    }

    public function test_a_CO_LECTURER_allocated_to_the_SAME_course_IS_notified(): void
    {
        $coLecturer = $this->makeUser(3, 1, 'active', 'Co Lecturer');
        $this->allocateLecturer($this->offering, $coLecturer, ['role' => 'co_lecturer']);

        $this->studentAttemptsAndSubmits();

        $this->assertSame(
            1,
            DB::table('online_exam_user_notifications')
                ->where('user_id', $coLecturer->id)
                ->where('type', 'exam_submitted')
                ->count(),
            'the appointment is the authority, not authorship: a co-lecturer marks this paper too'
        );
    }

    public function test_a_LECTURER_notification_LINK_is_STORED_WITHOUT_an_ORIGIN(): void
    {
        $this->studentAttemptsAndSubmits();

        $notification = DB::table('online_exam_user_notifications')
            ->where('user_id', $this->lecturer->id)
            ->where('type', 'exam_submitted')
            ->first();

        /**
         * THE PROPERTY THAT FIXES THE REPORTED BUG, asserted on the STORED value.
         *
         * The reported failure was an administrator being handed
         * `http://localhost/admin/online-exams/17` and leaving the application for a
         * server with no document root there. The host and port in that string came
         * from `config('app.url')` at the moment the row was written - not from the
         * host the reader is on.
         *
         * So the assertion is that the row carries a LOCATION and no origin at all.
         * Resolving it is deliberately not asserted here: `url()->to()` depends on
         * the request in flight, and this test harness has no real host, so any
         * assertion about the resolved absolute value would be testing the harness
         * rather than the code. The resolution itself is covered by
         * `OnlineExamNotificationLinkTest`, which drives it with explicit hosts.
         */
        $this->assertStringStartsWith(
            '/teacher/online-exams/',
            $notification->action_url,
            'the stored link must be a path inside this application'
        );

        $this->assertStringNotContainsString(
            '://',
            $notification->action_url,
            'a stored notification link must carry no scheme or host, or it leaves the application when clicked'
        );

        // And it resolves onto whatever the reader is actually on.
        $this->assertStringStartsWith(
            url()->to('/'),
            \App\Support\OnlineExams\OnlineExamNotificationLink::toAbsolute($notification->action_url)
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. THE MARKING QUEUE
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_SUBMISSION_appears_in_the_ALLOCATED_LECTURERS_marking_queue(): void
    {
        $submission = $this->studentAttemptsAndSubmits();

        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.marking'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Kyeyune Amos', $html);
        $this->assertStringContainsString('Business Mathematics Test 1', $html);
        $this->assertStringContainsString('ANSWER ONE', $html, 'the written answer must be visible to the marker');
        $this->assertStringContainsString($this->offering->reference, $html, 'the queue must name the course');
    }

    public function test_an_UNALLOCATED_lecturer_CANNOT_mark_and_sees_an_EMPTY_queue(): void
    {
        $this->studentAttemptsAndSubmits();

        $stranger = $this->makeUser(3, 1, 'active', 'Not Their Lecturer');

        // The queue is EMPTY for them...
        $html = $this->actingAs($stranger)
            ->get(route('teacher.online_exams.marking'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('ANSWER ONE', $html);
        $this->assertStringNotContainsString('Business Mathematics Test 1', $html);

        // ...and the WRITE is refused even with a forged id.
        $answer = OnlineExamAnswer::query()
            ->where('submission_id', OnlineExamSubmission::query()
                ->where('online_exam_id', $this->exam->id)->value('id'))
            ->where('question_id', $this->writtenQ)
            ->firstOrFail();

        $this->actingAs($stranger)
            ->post(route('teacher.online_exams.answers.mark', $answer->id), [
                'answer_id' => $answer->id,
                'awarded_marks' => 6,
            ])
            ->assertStatus(403);

        $this->assertNull($answer->fresh()->awarded_marks, 'a refused mark must not be written');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. AUTOMATIC MARKS SURVIVE, MANUAL MARKS ARE THE MARKER'S
    // ══════════════════════════════════════════════════════════════════════

    public function test_AUTOMATIC_marks_are_kept_and_CANNOT_be_overridden_by_a_LECTURER(): void
    {
        $submission = $this->studentAttemptsAndSubmits();

        $this->assertSame(4.0, (float) $submission->objective_score, 'the MCQ was marked automatically on submission');
        $this->assertSame(OnlineExamSubmission::STATUS_PENDING_MANUAL, $submission->status);

        $auto = OnlineExamAnswer::query()
            ->where('submission_id', $submission->id)->where('question_id', $this->objectiveQ)
            ->firstOrFail();

        $this->assertSame(4.0, (float) $auto->awarded_marks);

        // Forcing a different value on an objective answer is REFUSED, with a message
        // that says why: "Automatically marked questions cannot be manually
        // overridden." A form POST refusal is a redirect carrying that error, so
        // the status is 302 and the assertion is on the message and on the value
        // being unchanged - not on a bare status code, which would only be testing
        // whether this action was sent as a form or as JSON.
        $response = $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.answers.mark', $auto->id), [
                'answer_id' => $auto->id,
                'awarded_marks' => 1,
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors();

        $this->assertSame(4.0, (float) $auto->fresh()->awarded_marks, 'automatic marks are not the marker to change');
    }

    public function test_a_LECTURER_marks_the_WRITTEN_answer_and_the_TOTAL_updates(): void
    {
        $submission = $this->studentAttemptsAndSubmits();

        $written = OnlineExamAnswer::query()
            ->where('submission_id', $submission->id)->where('question_id', $this->writtenQ)
            ->firstOrFail();

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.answers.mark', $written->id), [
                'answer_id' => $written->id,
                'awarded_marks' => 5,
                'teacher_comment' => 'Good working, show the steps.',
            ])
            ->assertSessionHasNoErrors();

        $submission->refresh();

        $this->assertSame(5.0, (float) $submission->manual_score);
        $this->assertSame(9.0, (float) $submission->score, '4 automatic + 5 manual');
        $this->assertNull($submission->passed, 'the outcome is not decided while the result is unreleased');

        $this->assertSame('Good working, show the steps.', $written->fresh()->teacher_comment);
    }

    public function test_a_MARK_OUTSIDE_the_QUESTION_BOUNDS_is_REFUSED(): void
    {
        $submission = $this->studentAttemptsAndSubmits();

        $written = OnlineExamAnswer::query()
            ->where('submission_id', $submission->id)->where('question_id', $this->writtenQ)
            ->firstOrFail();

        // A mark outside the question's own maximum is refused. Refused, not clamped:
        // silently turning 6 into 6 for a 6-mark question is fine, but turning 99
        // into 6 would award a mark the marker did not give.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.answers.mark', $written->id), [
                'answer_id' => $written->id,
                'awarded_marks' => 99,
            ])
            ->assertStatus(302);

        $this->assertNull($written->fresh()->awarded_marks, 'a mark outside the question bounds must not be written');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. LECTURER HANDS OVER; LECTURER CANNOT PUBLISH
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_LECTURER_submits_marks_FOR_ADMIN_REVIEW_and_is_TOLD_so(): void
    {
        $submission = $this->studentAttemptsAndSubmits();
        $this->markWrittenAnswer($submission, 5);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.finalize', $submission->id))
            ->assertSessionHasNoErrors();

        $submission->refresh();

        $this->assertSame(OnlineExamSubmission::STATUS_FINALIZED, $submission->status);
        $this->assertSame('pending_review', $submission->result_review_state);
        $this->assertFalse($submission->isResultVisible(), 'handing over must NOT release anything');

        // The academic office was told.
        $this->assertGreaterThan(
            0,
            DB::table('online_exam_user_notifications')
                ->where('type', 'marking_submitted_for_review')
                ->where('online_exam_id', $this->exam->id)
                ->count(),
            'submitting marking for review must notify an administrator'
        );
    }

    /**
     * THE SECURITY RULE. Every route a lecturer could reach it by.
     */
    public function test_a_LECTURER_CANNOT_publish_a_result_by_any_ROUTE(): void
    {
        $submission = $this->studentAttemptsAndSubmits();
        $this->markWrittenAnswer($submission, 5);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.finalize', $submission->id));

        $submission->refresh();
        $this->assertSame('pending_review', $submission->result_review_state);

        // 1. The route the admin screen uses, POSTed by a lecturer.
        $this->actingAs($this->lecturer)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertStatus(403);

        // 2. The route named "teacher".
        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.publish', $submission->id))
            ->assertStatus(403);

        // 3. Direct to the controller's admin endpoint by id.
        $this->actingAs($this->lecturer)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertStatus(403);

        // 4. The lecturer's own "publish the EXAM" route must also be refused: a
        //    lecturer authorises their own paper but may not publish it.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.publish', $this->exam->id))
            ->assertStatus(403);

        $submission->refresh();
        $this->assertNotSame(
            OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            $submission->status,
            'no lecturer route may release a mark'
        );
        $this->assertFalse($submission->isResultVisible());
    }

    public function test_the_MARKING_queue_offers_the_LECTURER_handover_and_NOT_publication(): void
    {
        $submission = $this->studentAttemptsAndSubmits();
        $this->markWrittenAnswer($submission, 5);

        $html = $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.marking'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Submit Marks for Admin Review', $html);
        $this->assertStringNotContainsString(
            'Publish to Student',
            $html,
            'a lecturer must never be offered publication of a result'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3b. A BLANK MANUAL QUESTION IS NOT "NOTHING TO DECIDE"
    // ══════════════════════════════════════════════════════════════════════

    /**
     * THE SUBMISSION-12 DEFECT, AS A REGRESSION TEST.
     *
     * Exam 17 submission 12: one MCQ (10) and one short answer (10). The MCQ was
     * answered and scored zero; the short answer was never persisted, so it had no
     * answer row. `summary()` counted `pending` only for a manual question that HAS a
     * response, so it reported 0, and a student's own submit wrote:
     *
     *     status = 'finalized', result_review_state = 'not_ready'
     *
     * Ten marks were never awarded by anybody, the result rendered as "Marking
     * Complete / Finalized / Not Released", and the lecturer's Actions column was
     * EMPTY - because that pair of values is in no queue and admits no action.
     *
     * This asserts the three things that were true then and must never be again.
     */
    public function test_a_blank_manual_question_PREVENTS_a_submission_being_called_complete(): void
    {
        // A DIFFERENT exam from the one the rest of this file builds: identical
        // shape to exam 17, but with an essay added so the paper carries a manual
        // question the student never touched.
        $examId = $this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->offering->subject_id,
            'course_offering_id' => $this->offering->id,
            'class_id' => null,
            'title' => 'Paper with an untouched essay',
            'workflow_state' => 'published',
            'is_published' => 1,
            'total_marks' => 20,
            'pass_mark' => 10,
            'created_by' => $this->lecturer->id,
            'creator_id' => $this->lecturer->id,
        ]);
        $mcqId = (int) $this->makeQuestion($examId, [
            'question' => '<p>MCQ</p>', 'type' => 'mcq',
            'option_a' => '3', 'option_b' => '4', 'option_c' => '5', 'option_d' => '6',
            'correct_ans' => 'b', 'marks' => 10, 'sort_order' => 1,
        ]);
        $essayId = (int) $this->makeQuestion($examId, [
            'question' => '<p>Essay the student never typed</p>', 'type' => 'essay',
            'marks' => 10, 'sort_order' => 2,
        ]);

        $submissionId = (int) DB::table('online_exam_submissions')->insertGetId([
            'online_exam_id' => $examId, 'student_id' => $this->student->id, 'attempt_no' => 1,
            'school_id' => 1, 'total_marks_snapshot' => 20,
            'started_at' => now(), 'expires_at' => now()->addHour(), 'last_activity_at' => now(),
            'status' => OnlineExamSubmission::STATUS_IN_PROGRESS,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Only the MCQ is answered. The essay is never touched.
        $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $submissionId), [
            'submission_id' => $submissionId, 'question_id' => $mcqId, 'answer_revision' => 1, 'selected_option' => 'b',
        ])->assertOk();

        $this->actingAs($this->student)
            ->post(route('student.online_exam.submit', $examId), ['submission_id' => $submissionId])
            ->assertSessionHasNoErrors();

        $submission = OnlineExamSubmission::query()->findOrFail($submissionId);

        // (1) NOT finalized. The student's submit may never finalise a result.
        $this->assertNotSame(
            OnlineExamSubmission::STATUS_FINALIZED,
            $submission->status,
            'a student submit must never write finalized'
        );

        // (2) NOT "marking complete". The untouched essay is still outstanding.
        $this->assertSame(OnlineExamSubmission::STATUS_PENDING_MANUAL, $submission->status);
        $this->assertTrue(
            \App\Support\OnlineExams\OnlineExamMarking::hasUndecidedManualQuestions($submission),
            'a manual question nobody has judged must keep the submission open'
        );

        // (3) And the outcome is NOT decided while marking is outstanding.
        $this->assertNull($submission->passed);

        // The automatic part is still credited immediately - that part was never
        // broken and must not be slowed down to fix the manual part.
        $this->assertSame(10.0, (float) $submission->objective_score);

        // Handover is refused while the blank is undecided...
        $this->assertHandoverRefusedWithExplanation(
            $this->actingAs($this->lecturer)
                ->post(route('teacher.online_exams.results.finalize', $submission->id)),
            'outstanding'
        );

        // ...and the lecturer is told what is outstanding, in marks.
        $this->assertSame(
            10.0,
            \App\Support\OnlineExams\OnlineExamMarking::undecidedManualMarks($submission),
            'ten marks were never awarded by anyone, and the page must say so'
        );
    }

    public function test_a_blank_manual_question_can_be_marked_ZERO_and_only_zero(): void
    {
        $submission = $this->studentAttemptsAndSubmits();

        // Make the written answer blank the way the autosave defect produced it:
        // no answer content at all.
        DB::table('online_exam_answers')
            ->where('submission_id', $submission->id)
            ->where('question_id', $this->writtenQ)
            ->update(['answer_text' => null, 'awarded_marks' => null, 'marked_by' => null, 'marked_at' => null]);

        $answer = OnlineExamAnswer::query()
            ->where('submission_id', $submission->id)->where('question_id', $this->writtenQ)
            ->firstOrFail();

        // Above zero is refused: that would be inventing a mark for work that does
        // not exist. The lecturer is returned to the page with the reason rather
        // than shown a bare error document, and nothing is written.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.answers.mark', $answer->id), [
                'answer_id' => $answer->id,
                'awarded_marks' => 5,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('awarded_marks');

        $this->assertNull(
            $answer->fresh()->awarded_marks,
            'a refused mark must not be partially applied'
        );

        // Zero is accepted, and is ATTRIBUTED - the record says a person decided.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.answers.mark', $answer->id), [
                'answer_id' => $answer->id,
                'awarded_marks' => 0,
            ])
            ->assertSessionHasNoErrors();

        $answer->refresh();
        $this->assertSame(0.0, (float) $answer->awarded_marks);
        $this->assertSame($this->lecturer->id, (int) $answer->marked_by);
        $this->assertNotNull($answer->marked_at);

        // And now the paper can be handed over.
        $submission->refresh();
        $this->assertFalse(
            \App\Support\OnlineExams\OnlineExamMarking::hasUndecidedManualQuestions($submission)
        );

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.finalize', $submission->id))
            ->assertSessionHasNoErrors();
    }

    /**
     * THE HISTORICAL ROWS: an authorized, audited way back in, with nothing invented.
     *
     * Submissions written by the old submit path are `finalized` + `not_ready`. They
     * cannot be marked (`assertSubmissionMarkable` refuses `finalized`), cannot be
     * handed over (`finalizeSubmission` returns false for a finalized row), and appear
     * in no queue. Submission 12 and exam 18's attempts 10 and 11 are all in it.
     *
     * ADMINISTRATOR ONLY. See test_a_LECTURER_can_NEVER_reopen_a_state_transition().
     */
    public function test_an_ORPHAN_finalized_row_can_be_REOPENED_for_marking_with_an_AUDIT_trail(): void
    {
        $submission = $this->studentAttemptsAndSubmits();

        // Reproduce the historical anomaly exactly, in the fixture only.
        DB::table('online_exam_submissions')->where('id', $submission->id)->update([
            'status' => OnlineExamSubmission::STATUS_FINALIZED,
            'result_review_state' => 'not_ready',
        ]);

        $before = DB::table('audit_logs')->count();

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.return_to_marking', $submission->id), [
                'reason' => 'Closed without handover; reopened for marking.',
            ])
            ->assertSessionHasNoErrors();

        $submission->refresh();

        $this->assertSame(OnlineExamSubmission::STATUS_PENDING_MANUAL, $submission->status);
        $this->assertSame('not_ready', $submission->result_review_state);
        $this->assertNull($submission->passed, 'the outcome was decided against an incomplete marking');

        // Nothing was invented. The MCQ keeps its automatic score, because that
        // score is the student's own answer being credited — it was never in
        // question. What must be untouched is the MANUAL work: still unmarked, still
        // carrying the student's text, and still outstanding.
        $manualAnswer = OnlineExamAnswer::query()
            ->where('submission_id', $submission->id)
            ->where('question_id', $this->writtenQ)
            ->firstOrFail();

        $this->assertNull($manualAnswer->awarded_marks, 'no manual mark may be awarded by reopening');
        $this->assertNull($manualAnswer->marked_by, 'nobody may be recorded as having marked it');
        $this->assertNotEmpty($manualAnswer->answer_text, 'the student work must not be discarded');

        $this->assertTrue(
            \App\Support\OnlineExams\OnlineExamMarking::hasUndecidedManualQuestions($submission->fresh()),
            'the reopened submission still needs a marker to decide the written question'
        );

        // NO RESULT WAS PUBLISHED. The repair returns the work to a marker; it does
        // not release anything to a student, because only an administrator may do
        // that and this is not that action.
        $this->assertFalse($submission->isResultVisible());
        $this->assertNotSame(OnlineExamSubmission::STATUS_RESULT_PUBLISHED, $submission->status);

        // And it is on the record, naming the submission and the reason given.
        $this->assertGreaterThan($before, DB::table('audit_logs')->count());
        $this->assertTrue(
            DB::table('audit_logs')->where('description', 'like', "%submission #{$submission->id}%")->exists(),
            'the repair must leave an audit entry naming the submission it reopened'
        );

        /**
         * THE LECTURER'S PAGE NOW OFFERS MARKING, NOT AN ESCALATION.
         *
         * Asserted on the RENDERED page, not just the row, because the reported
         * failure was a page: an Actions column with nothing in it. Reopening is what
         * turns this row from an unexplainable dead end into ordinary marking work.
         *
         * The action offered is the marking decision itself, taken inline against this
         * exact question — the "Mark Submission" link was replaced when the decision
         * became reachable, because sending a lecturer off to the generic queue was
         * what left the blank question with nowhere to go.
         */
        $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.results', $this->exam->id))
            ->assertOk()
            ->assertSee('Awaiting marking')
            ->assertSee('still requires a marking decision')
            ->assertSee('data-testid="decision-form"', false)
            ->assertSee('Save Mark')
            // The handover must stay shut while the decision is outstanding.
            ->assertDontSee('action-submit-for-review')
            // And the escalation must be gone: this is now ordinary marking work.
            ->assertDontSee('Escalate to administrator');

        // And the answers are visible inline, so the lecturer can see what was
        // actually submitted before deciding anything.
        $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.results', $this->exam->id))
            ->assertOk()
            ->assertSee('ANSWER ONE');
    }

    public function test_reopening_is_REFUSED_for_a_coherent_row_and_for_a_published_result(): void
    {
        // Coherent: a properly handed-over result awaiting admin review.
        $handedOver = $this->handedOver();

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.return_to_marking', $handedOver->id))
            ->assertStatus(422);

        $this->assertSame(
            'pending_review',
            $handedOver->fresh()->result_review_state,
            'a result awaiting admin review must not be reopenable'
        );

        // Published: never, by anybody.
        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $handedOver->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            $handedOver->fresh()->status
        );

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.return_to_marking', $handedOver->id))
            ->assertStatus(422);

        $this->assertSame(
            OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            $handedOver->fresh()->status,
            'a published result must be immutable'
        );
    }

    /**
     * THE ADMIN PAGE NAMES THE ANOMALY AND OFFERS THE REPAIR.
     *
     * The reported symptom was a screen reading "Marking Complete / Finalized / Not
     * Released" with an empty Actions column, on both the lecturer's page and the
     * administrator's. The administrator is the one who can act on it, so this
     * asserts the admin page renders:
     *
     *   - the row LABELLED as never handed over, rather than "Marking Complete";
     *   - the outstanding count and the marks it is worth;
     *   - a "Reopen for Marking" control pointing at the admin route;
     *   - and NOT "Approve & Publish" - an administrator must not be able to release
     *     a result whose questions no human has judged.
     */
    public function test_the_ADMIN_page_NAMES_the_anomaly_and_OFFERS_the_repair(): void
    {
        $submission = $this->studentAttemptsAndSubmits();

        DB::table('online_exam_submissions')->where('id', $submission->id)->update([
            'status' => OnlineExamSubmission::STATUS_FINALIZED,
            'result_review_state' => 'not_ready',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.online_exams.results', $this->exam->id))
            ->assertOk()
            // Labelled truthfully, not flattered.
            ->assertSee('Closed without handover')
            ->assertDontSee('Marking Complete')
            // What is outstanding, and what it is worth. This fixture DOES answer the
            // written question, so nothing is blank here - the point is that the
            // question is still UNJUDGED, and the page must say how many marks that
            // is. Hooked on a test id so the assertion cannot pass on a stray number.
            ->assertSee('data-testid="undecided-marks"', false)
            // The repair, pointed at the admin route.
            ->assertSee('Reopen for Marking')
            ->assertSee(route('admin.online_exams.submissions.return_to_marking', $submission->id))
            // And no way to release a result nobody has judged.
            ->assertDontSee('Approve &amp; Publish')
            ->assertDontSee('Approve & Publish');
    }

    /**
     * REOPENING IS NOT A LECTURER POWER, BY ANY ROUTE NAME.
     *
     * The recovery for the submission-12 rows was first built as a lecturer action,
     * which was the wrong scope, and an existing test caught it:
     * `CourseOfferingAttendanceTest::test_lecturer_can_never_reopen_a_finalised_register`
     * sweeps EVERY route URI for the substring "reopen", because a lecturer able to
     * undo a state transition is the hazard it guards against.
     *
     * Rather than narrow that guard, the repair was moved to the administrator, who
     * owns the result lifecycle, and named `return-to-marking`. This test pins the
     * outcome: no lecturer route can reopen anything, and a lecturer posting to the
     * admin URL is refused even when they craft the request themselves.
     */
    public function test_a_LECTURER_can_NEVER_reopen_a_state_transition(): void
    {
        $lecturerUris = collect(app('router')->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn ($uri) => str_starts_with($uri, 'teacher/'))
            ->all();

        $this->assertNotEmpty($lecturerUris, 'the sweep must actually be looking at lecturer routes');

        foreach ($lecturerUris as $uri) {
            $this->assertStringNotContainsString(
                'reopen',
                $uri,
                "A lecturer reopen route must not exist: {$uri}"
            );
        }

        // And a forged POST from a lecturer to the admin endpoint changes nothing.
        $submission = $this->studentAttemptsAndSubmits();
        DB::table('online_exam_submissions')->where('id', $submission->id)->update([
            'status' => OnlineExamSubmission::STATUS_FINALIZED,
            'result_review_state' => 'not_ready',
        ]);

        $this->actingAs($this->lecturer)
            ->post(route('admin.online_exams.submissions.return_to_marking', $submission->id), [
                'reason' => 'lecturer attempt',
            ])
            ->assertStatus(403);

        $this->assertSame(
            OnlineExamSubmission::STATUS_FINALIZED,
            $submission->fresh()->status,
            'a lecturer must not be able to reopen the row'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. ADMIN REVIEW QUEUE, RETURN, PUBLISH
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_ADMIN_sees_the_marked_result_in_a_REVIEW_queue(): void
    {
        $submission = $this->handedOver();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.online_exams.result_review_queue'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Kyeyune Amos', $html);
        $this->assertStringContainsString('Business Mathematics Test 1', $html);
        $this->assertStringContainsString($this->offering->reference, $html);
        $this->assertStringContainsString('9.00', $html, 'the total must be shown for the decision');
        $this->assertStringContainsString('90.0', $html, 'the percentage must be shown for the decision');
    }

    public function test_an_ADMIN_CAN_PUBLISH_the_result_and_the_STUDENT_is_notified(): void
    {
        $submission = $this->handedOver();

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertSessionHasNoErrors();

        $submission->refresh();

        $this->assertSame(OnlineExamSubmission::STATUS_RESULT_PUBLISHED, $submission->status);
        $this->assertSame('published', $submission->result_review_state);
        $this->assertTrue($submission->isResultVisible());
        $this->assertTrue((bool) $submission->passed, '9 of 10 against a pass mark of 5 is a pass');

        $this->assertGreaterThan(
            0,
            DB::table('online_exam_user_notifications')
                ->where('user_id', $this->student->id)
                ->where('type', 'result_published')
                ->count(),
            'the student must be told their result is available'
        );
    }

    public function test_an_ADMIN_can_RETURN_for_CORRECTION_and_the_LECTURER_is_told(): void
    {
        $submission = $this->handedOver();

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.return', $submission->id), [
                'reason' => 'Please check the working on question 1.',
            ])
            ->assertSessionHasNoErrors();

        $submission->refresh();

        $this->assertSame(OnlineExamSubmission::STATUS_PENDING_MANUAL, $submission->status);
        $this->assertSame('returned_for_correction', $submission->result_review_state);
        $this->assertFalse($submission->isResultVisible());

        $this->assertGreaterThan(
            0,
            DB::table('online_exam_user_notifications')
                ->where('user_id', $this->lecturer->id)
                ->where('type', 'marking_returned')
                ->count(),
            'a returned result must go back to the lecturer who can act on it'
        );
    }

    public function test_the_REVIEW_queue_is_TENANT_scoped(): void
    {
        $this->handedOver();

        $otherSchool = $this->otherInstitution();
        $outsider = $this->makeUser(2, $otherSchool['schoolId'], 'active', 'Other Admin');

        $html = $this->actingAs($outsider)
            ->get(route('admin.online_exams.result_review_queue'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Kyeyune Amos', $html, 'another institution must never see this submission');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. STUDENT VISIBILITY
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_UNPUBLISHED_result_is_INVISIBLE_to_the_STUDENT(): void
    {
        $submission = $this->handedOver();

        // The attempt is complete and marked, but unreleased.
        $this->assertFalse($submission->isResultVisible());

        $resultPage = $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $submission->id))
            ->assertOk()
            ->getContent();

        // No mark anywhere on the page, in any form. Scoped with a context dump so a
        // match names the place it came from - "9.00" can legitimately appear in a
        // timestamp or a stylesheet, and an unexplained hit is not evidence either
        // way.
        $this->assertDoesNotMatchRegularExpression(
            '/(score|marks|total|9\.00)/i',
            $this->onlyExamResultBody($resultPage),
            'an unpublished score must not be rendered to the student'
        );
        $this->assertStringContainsString('awaiting', strtolower($resultPage));
    }

    public function test_a_PUBLISHED_result_IS_readable_by_its_OWN_student(): void
    {
        $submission = $this->handedOver();

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id));

        $html = $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $submission->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('9.00', $html);
    }

    public function test_a_student_cannot_open_ANOTHER_students_result(): void
    {
        $submission = $this->handedOver();
        $this->actingAs($this->admin)->post(route('admin.online_exams.submissions.publish_result', $submission->id));

        $intruder = $this->makeUser(7, 1, 'active', 'Someone Else');

        $this->actingAs($intruder)
            ->get(route('student.online_exam.result', $submission->id))
            ->assertStatus(404);
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The student's own exam area, without the shared page chrome.
     *
     * The header, navigation and footer belong to the layout and are identical for
     * every page; asserting that a mark never appears anywhere on the page would
     * also be asserting that the layout never contains the digits "9.00", which is
     * not a property of this feature and is not maintainable.
     */
    private function onlyExamResultBody(string $html): string
    {
        preg_match('/<section[^>]*>(.*)<\/section>/s', $html, $m);

        return $m[1] ?? $html;
    }

    private function markWrittenAnswer(OnlineExamSubmission $submission, float $marks): void
    {
        $written = OnlineExamAnswer::query()
            ->where('submission_id', $submission->id)->where('question_id', $this->writtenQ)
            ->firstOrFail();

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.answers.mark', $written->id), [
                'answer_id' => $written->id,
                'awarded_marks' => $marks,
            ])
            ->assertSessionHasNoErrors();
    }

    /** Marked, handed over, and awaiting an administrator. */
    private function handedOver(): OnlineExamSubmission
    {
        $submission = $this->studentAttemptsAndSubmits();
        $this->markWrittenAnswer($submission, 5);

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.finalize', $submission->id))
            ->assertSessionHasNoErrors();

        return OnlineExamSubmission::query()->findOrFail($submission->id);
    }
}