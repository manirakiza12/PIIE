<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamProctoringEvent;
use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;
use App\Support\OnlineExams\OnlineExamMarking;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * THE COORDINATED REPAIR, PINNED AS ONE SYSTEM.
 *
 * Each test here corresponds to a confirmed browser failure, and each states the root
 * cause it closes rather than only the behaviour it expects.
 */
class OnlineExamCoordinatedRepairTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    protected $offering;
    protected $lecturer;
    protected $admin;
    protected $student;
    protected $exam;
    protected $mcqQ;
    protected $shortQ;
    protected $essayQ;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();

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

        // EXAM 19'S SHAPE: 5 + 10 + 4 written against a 20-mark paper.
        $examId = $this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->offering->subject_id,
            'course_offering_id' => $this->offering->id,
            'class_id' => null,
            'title' => 'Coordinated Repair Paper',
            'workflow_state' => 'published',
            'is_published' => 1,
            'total_marks' => 20,
            'pass_mark' => 10,
            'duration_mins' => 45,
            'max_attempts' => 1,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
            'created_by' => $this->lecturer->id,
            'creator_id' => $this->lecturer->id,
        ]);
        $this->exam = OnlineExam::query()->findOrFail($examId);

        $this->mcqQ = (int) $this->makeQuestion($examId, [
            'question' => '<p>MCQ</p>', 'type' => 'mcq',
            'option_a' => '8', 'option_b' => '10', 'option_c' => '12', 'option_d' => '15',
            'correct_ans' => 'b', 'marks' => 5, 'sort_order' => 1,
        ]);
        $this->shortQ = (int) $this->makeQuestion($examId, [
            'question' => '<p>Explain simple and compound interest.</p>',
            'type' => 'short', 'marks' => 10, 'sort_order' => 2,
        ]);
        $this->essayQ = (int) $this->makeQuestion($examId, [
            'question' => '<p>Written answer four.</p>', 'type' => 'essay',
            'marks' => 4, 'sort_order' => 3,
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

    /**
     * AN "EMPTY" SUMMERNOTE DOCUMENT IS NOT AN ANSWER.
     *
     * Exam 19, submission 13, question 3: the student typed, the page showed a green
     * "Saved", and the server stored `<p><br></p>` — eleven characters of nothing.
     *
     * The old check compared strings, so that counted as an answer. The paper then
     * reported zero pending, the question never appeared as outstanding, and the
     * lecturer's "What the student wrote" column rendered an apparently empty cell.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_an_EMPTY_rich_text_document_is_NOT_an_answer(): void
    {
        $submission = $this->attemptAndSubmit([
            $this->mcqQ => ['selected_option' => 'b'],
            $this->shortQ => ['answer_text' => '<p><br></p>'],
        ]);

        $this->assertSame(
            OnlineExamSubmission::STATUS_PENDING_MANUAL,
            $submission->status,
            'a paper with an untouched written question must not be called complete'
        );

        $this->assertFalse(
            OnlineExamMarking::hasResponse($this->answerFor($submission, $this->shortQ)),
            'Summernote\'s empty document must read as "no answer"'
        );

        $this->assertSame(
            14.0,
            OnlineExamMarking::undecidedManualMarks($submission),
            'both written questions — 10 and 4 — must be outstanding'
        );

        $this->assertTrue(
            OnlineExamMarking::hasUndecidedManualQuestions($submission),
            'an answerless written question must keep the paper open for marking'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function REAL_written_content_is_still_treated_as_an_answer(): void
    {
        $submission = $this->attemptAndSubmit([
            $this->mcqQ => ['selected_option' => 'b'],
            $this->shortQ => ['answer_text' => '<p>Simple interest pays interest on the principal only.</p>'],
            $this->essayQ => ['answer_text' => '<p>Compound interest pays interest on principal and accrued interest.</p>'],
        ]);

        $this->assertTrue(OnlineExamMarking::hasResponse($this->answerFor($submission, $this->shortQ)));
        $this->assertTrue(OnlineExamMarking::hasResponse($this->answerFor($submission, $this->essayQ)));

        /**
         * STILL OUTSTANDING — and that is the correct state, not a failure.
         *
         * Being answered is not the same as being judged. Both written questions are
         * marked `pending_manual_marking` and both still need a marker, so this asserts
         * that all 14 written marks are outstanding. It would be a bug if a submitted
         * but unmarked answer counted as complete.
         */
        $this->assertSame(OnlineExamSubmission::STATUS_PENDING_MANUAL, $submission->status);
        $this->assertSame(
            14.0,
            OnlineExamMarking::undecidedManualMarks($submission),
            'answered is not judged: both written questions remain outstanding'
        );
    }

    /**
     * THE TIMER MUST USE THE SHORTER OF THE TWO DEADLINES.
     *
     * Exam 19 showed roughly 223 minutes for a 45-minute paper. `expires_at` had been
     * frozen when the attempt was opened — while the exam still carried a duration of
     * about 225 minutes — and never reconsidered after the duration was corrected.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function the_timer_uses_the_EARLIER_of_duration_and_closing_time(): void
    {
        $submission = $this->openAttempt();

        // An attempt frozen with a stale, far-future deadline — the exam 19 shape.
        DB::table('online_exam_submissions')->where('id', $submission->id)->update([
            'expires_at' => now()->addHours(4),
        ]);

        $submission->refresh()->load('exam');

        $this->assertSame(
            $submission->started_at->copy()->addMinutes(45)->getTimestamp(),
            $submission->effectiveExpiresAt()->getTimestamp(),
            'the deadline must be the start plus the CURRENT duration, not the stale stored value'
        );

        $this->assertLessThanOrEqual(
            45 * 60 + 5,
            $submission->remainingSeconds(),
            'a student must not be handed hours beyond the configured duration'
        );
    }

    /**
 * A NEW ATTEMPT GETS THE DURATION, NOT THE WHOLE REMAINING WINDOW.
 *
 * Exam 19 is a 45-minute paper whose window runs to 04:58. Starting at 03:08, the
 * duration deadline is 03:53 — but `startExam()` asked
 * `$durationExpiry->gt($scheduledEnd)`, and that comparison returned true for
 * 03:53 against 04:58, handing the student until 04:58 instead: 110 minutes on a
 * 45-minute exam.
 *
 * That is the 223-minute defect arriving by a second route, and it is worse than the
 * first because it would still have reached every NEW attempt after the timer fix.
 * `started_at` and `scheduledEndAt()` live in different timezones, so the two Carbons
 * disagree about ordering even when their wall-clock times plainly do not.
 */
public function a_NEW_attempt_gets_the_DURATION_and_not_the_remaining_window(): void
{
    // A window with far more time left in it than the paper's duration.
    DB::table('online_exams')->where('id', $this->exam->id)->update([
        'duration_mins' => 45,
        'end_datetime' => now()->addHours(4),
    ]);

    $this->travelTo(now()->setTime(9, 0));

    $this->actingAs($this->student)
        ->post(route('student.online_exam.start', $this->exam->id), ['instructions_acknowledged' => 1])
        ->assertRedirect();

    $submission = OnlineExamSubmission::query()
        ->where('online_exam_id', $this->exam->id)
        ->where('student_id', $this->student->id)
        ->firstOrFail();

    $allowedMinutes = ($submission->expires_at->getTimestamp() - $submission->started_at->getTimestamp()) / 60;

    $this->assertLessThanOrEqual(
        45,
        $allowedMinutes,
        'an attempt must be bounded by the configured duration, not by the closing time'
    );

    $this->assertGreaterThan(
        44,
        $allowedMinutes,
        'and it must actually be given its full 45 minutes'
    );
}

#[\PHPUnit\Framework\Attributes\Test]
    public function a_NEW_attempt_is_CAPPED_by_a_closing_time_sooner_than_the_duration(): void
{
    DB::table('online_exams')->where('id', $this->exam->id)->update([
        'duration_mins' => 45,
        'end_datetime' => now()->addMinutes(15),
    ]);

    $startResponse = $this->actingAs($this->student)
        ->post(route('student.online_exam.start', $this->exam->id), ['instructions_acknowledged' => 1]);

    $startResponse->assertSessionHasNoErrors();

    $submission = OnlineExamSubmission::query()
        ->where('online_exam_id', $this->exam->id)
        ->where('student_id', $this->student->id)
        ->firstOrFail();

    $this->assertLessThanOrEqual(
        15 * 60 + 5,
        $submission->remainingSeconds(),
        'the closing time must cap a new attempt'
    );
}

#[\PHPUnit\Framework\Attributes\Test]
    public function the_timer_also_respects_a_CLOSING_time_earlier_than_the_duration(): void
    {
        DB::table('online_exams')->where('id', $this->exam->id)->update([
            'end_datetime' => now()->addMinutes(20),
        ]);

        $submission = $this->openAttempt();
        $submission->refresh()->load('exam');

        $this->assertLessThanOrEqual(
            20 * 60 + 5,
            $submission->remainingSeconds(),
            'the exam closing time must cap the attempt'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_refresh_or_second_tab_cannot_EXTEND_the_deadline(): void
    {
        $submission = $this->openAttempt();
        $submission->refresh()->load('exam');

        $before = $submission->effectiveExpiresAt()->getTimestamp();

        // The heartbeat is what the page calls on a timer, and again after a refresh.
        $this->actingAs($this->student)
            ->postJson(route('student.online_exam.heartbeat', $submission->id))
            ->assertOk();

        // Revisiting the attempt, which is what a refresh or a second tab does. The exact
        // redirect target is not what this test is about — the deadline is.
        $this->actingAs($this->student)->get(route('student.online_exam.resume', $submission->id));

        $submission->refresh()->load('exam');

        $this->assertSame(
            $before,
            $submission->effectiveExpiresAt()->getTimestamp(),
            'revisiting the exam must never lengthen an attempt'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_SHORTENED_deadline_is_PERSISTED_so_the_browser_agrees_with_the_server(): void
    {
        $submission = $this->openAttempt();

        DB::table('online_exam_submissions')->where('id', $submission->id)->update([
            'expires_at' => now()->addHours(4),
        ]);
        $submission->refresh()->load('exam');

        $this->actingAs($this->student)
            ->postJson(route('student.online_exam.heartbeat', $submission->id))
            ->assertOk();

        $stored = DB::table('online_exam_submissions')->where('id', $submission->id)->value('expires_at');

        $this->assertLessThan(
            strtotime('+3 hours'),
            strtotime($stored),
            'the corrected deadline must be written down, not merely computed'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function the_handover_is_REFUSED_while_a_written_question_is_undecided(): void
    {
        $submission = $this->attemptAndSubmit([
            $this->mcqQ => ['selected_option' => 'b'],
            $this->shortQ => ['answer_text' => '<p>A real answer.</p>'],
            // The essay is never answered - the exam 19 question 4 case.
        ]);

        // Refused, and the lecturer is told WHY on the screen they came from.
        $this->assertHandoverRefusedWithExplanation(
            $this->actingAs($this->lecturer)
                ->post(route('teacher.online_exams.results.finalize', $submission->id)),
            'outstanding'
        );

        // And the button is not offered.
        $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.results', $this->exam->id))
            ->assertOk()
            ->assertDontSee('action-submit-for-review');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function an_UNANSWERED_question_can_be_settled_with_an_attributed_ZERO_and_then_handed_over(): void
    {
        $submission = $this->attemptAndSubmit([
            $this->mcqQ => ['selected_option' => 'b'],
            $this->shortQ => ['answer_text' => '<p>A real answer.</p>'],
        ]);

        // The essay has a row (created on submit) but nothing in it.
        $blankId = OnlineExamAnswer::query()
            ->where('submission_id', $submission->id)->where('question_id', $this->essayQ)
            ->value('id');

        $decisionUrl = route('teacher.online_exams.submissions.record_decision', [
            'submission' => $submission->id, 'question' => $this->essayQ,
        ]);

        // Above zero is refused for an answer that does not exist.
        $this->actingAs($this->lecturer)
            ->post($decisionUrl, ['awarded_marks' => 2])
            ->assertRedirect()
            ->assertSessionHasErrors('awarded_marks');

        // The OTHER written question is answered, so it still needs a human judgement —
        // "answered" is not "marked". Marking it is what clears the rest of the paper.
        $answeredId = $this->answerFor($submission, $this->shortQ)->id;

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.answers.mark', $answeredId), [
                'answer_id' => $answeredId, 'awarded_marks' => 8,
            ])->assertSessionHasNoErrors();

        // Zero is accepted and attributed.
        $this->actingAs($this->lecturer)
            ->post($decisionUrl, ['awarded_marks' => 0])
            ->assertSessionHasNoErrors();

        $recorded = OnlineExamAnswer::query()->findOrFail($blankId);

        $this->assertSame(0.0, (float) $recorded->awarded_marks);
        $this->assertSame($this->lecturer->id, (int) $recorded->marked_by);
        $this->assertNotNull($recorded->marked_at);

        // The absence of an answer is preserved — a decision is not an answer.
        $this->assertNull($recorded->answer_text);
        $this->assertNull($recorded->selected_option);

        // Pending is now zero, so the handover works and moves to Admin review.
        $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.results', $this->exam->id))
            ->assertOk()
            ->assertSee('action-submit-for-review');

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.finalize', $submission->id))
            ->assertSessionHasNoErrors();

        $submission->refresh();
        $this->assertSame(OnlineExamSubmission::STATUS_FINALIZED, $submission->status);
        $this->assertSame('pending_review', $submission->result_review_state);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_lecturer_can_NEVER_finalize_another_students_attempt_by_id(): void
    {
        $mine = $this->attemptAndSubmit([
            $this->mcqQ => ['selected_option' => 'b'],
            $this->shortQ => ['answer_text' => '<p>Mine.</p>'],
            $this->essayQ => ['answer_text' => '<p>Also mine.</p>'],
        ]);

        $other = $this->makeUser(7, 1, 'active', 'Someone Else');

        $theirs = (int) DB::table('online_exam_submissions')->insertGetId([
            'online_exam_id' => $this->exam->id, 'student_id' => $other->id, 'attempt_no' => 1,
            'school_id' => 1, 'total_marks_snapshot' => 20,
            'started_at' => now(), 'expires_at' => now()->addMinutes(45), 'last_activity_at' => now(),
            'status' => OnlineExamSubmission::STATUS_IN_PROGRESS,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A different school is the clearest IDOR probe: the tenant check must refuse it
        // before anything else happens.
        $outsider = $this->makeUser(3, 2, 'active', 'Other School Teacher');

        $this->actingAs($outsider)
            ->post(route('teacher.online_exams.results.finalize', $theirs))
            ->assertStatus(404);

        $this->assertSame(
            OnlineExamSubmission::STATUS_IN_PROGRESS,
            OnlineExamSubmission::query()->findOrFail($theirs)->status,
            'another tenant must not be able to move this attempt'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function INCIDENTS_are_recorded_against_the_students_OWN_attempt(): void
    {
        $submission = $this->openAttempt();

        $this->actingAs($this->student)
            ->postJson(route('student.online_exam.incident', $submission->id), [
                'event_type' => 'focus_lost',
                'detail' => ['reason' => 'window-blur'],
            ])
            ->assertOk()
            ->assertJsonPath('recorded', true);

        $this->assertDatabaseHas('online_exam_proctoring_events', [
            'submission_id' => $submission->id,
            'event_type' => 'focus_lost',
        ]);

        // Recorded as evidence only. No automatic consequence of any kind.
        $this->assertSame(
            OnlineExamSubmission::STATUS_IN_PROGRESS,
            $submission->fresh()->status,
            'an incident must never end the attempt by itself'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function ONE_focus_loss_is_NOT_counted_as_two(): void
    {
        $submission = $this->openAttempt();

        // A single Alt+Tab produces a blur and a visibilitychange in the same tick.
        foreach (['focus_lost', 'tab_hidden'] as $type) {
            $this->actingAs($this->student)
                ->postJson(route('student.online_exam.incident', $submission->id), [
                    'event_type' => $type,
                ])->assertOk();
        }

        // The server refuses an immediate repeat of the same type, so a page that
        // reports the pair anyway cannot inflate the count on its own record.
        $this->actingAs($this->student)
            ->postJson(route('student.online_exam.incident', $submission->id), [
                'event_type' => 'focus_lost',
            ])
            ->assertOk()
            ->assertJsonPath('recorded', false)
            ->assertJsonPath('reason', 'duplicate');

        $this->assertSame(
            1,
            OnlineExamProctoringEvent::query()
                ->where('submission_id', $submission->id)
                ->where('event_type', 'focus_lost')
                ->count(),
            'one interruption must not become two records of the same kind'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_student_cannot_report_an_incident_against_ANOTHER_students_attempt(): void
    {
        $mine = $this->openAttempt();

        $other = $this->makeUser(7, 1, 'active', 'Someone Else');
        $theirs = (int) DB::table('online_exam_submissions')->insertGetId([
            'online_exam_id' => $this->exam->id, 'student_id' => $other->id, 'attempt_no' => 1,
            'school_id' => 1, 'total_marks_snapshot' => 20,
            'started_at' => now(), 'expires_at' => now()->addMinutes(45), 'last_activity_at' => now(),
            'status' => OnlineExamSubmission::STATUS_IN_PROGRESS,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->student)
            ->postJson(route('student.online_exam.incident', $theirs), [
                'event_type' => 'focus_lost',
            ])
            ->assertStatus(404);

        $this->assertSame(
            0,
            OnlineExamProctoringEvent::query()->where('submission_id', $theirs)->count(),
            'no incident may be written against an attempt the student does not own'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function an_UNKNOWN_incident_type_is_rejected(): void
    {
        $submission = $this->openAttempt();

        $this->actingAs($this->student)
            ->postJson(route('student.online_exam.incident', $submission->id), [
                'event_type' => 'exam_passed_by_magic',
            ])
            ->assertStatus(422);
    }

    /**
 * AN ANSWER ROW THAT STORED NOTHING IS SHOWN AS A FAILURE, NOT AS A BLANK CELL.
 *
 * Exam 19 submission 13, question 3: the row exists, the student opened and saved the
 * question, and what was stored is `<p><br></p>`. Rendered plainly that is an empty
 * cell, which reads as a layout fault rather than as the autosave failure it is — and a
 * marker looking at a blank cell has no way to know the student's work was lost rather
 * than never typed.
 *
 * So the stored value is shown verbatim alongside an explicit diagnosis, and a mark
 * already awarded against that empty answer is called out so it can be corrected.
 */
#[\PHPUnit\Framework\Attributes\Test]
public function the_lecturer_is_SHOWN_that_a_stored_answer_was_empty(): void
{
    $submission = $this->attemptAndSubmit([
        $this->mcqQ => ['selected_option' => 'b'],
        // Saved, acknowledged with a green tick, and empty — the exam 19 shape.
        $this->shortQ => ['answer_text' => '<p><br></p>'],
    ]);

    $html = $this->actingAs($this->lecturer)
        ->get(route('teacher.online_exams.results', $this->exam->id))
        ->assertOk()
        ->getContent();

    $this->assertStringContainsString(
        'stored-empty-answer',
        $html,
        'an answer row holding nothing must be labelled, not rendered as a blank cell'
    );
    $this->assertStringContainsString(
        'autosave failure',
        $html,
        'the page must name the actual fault rather than implying the student typed nothing'
    );

    /**
     * The exact stored bytes, because that is what an administrator needs.
     *
     * Compared against the ESCAPED form: the view prints the value through `{{ }}`,
     * so `<p><br></p>` reaches the page as `&lt;p&gt;&lt;br&gt;&lt;/p&gt;`. Asserting the
     * raw markup would fail against a correct page.
     */
    $this->assertStringContainsString(
        '&lt;p&gt;&lt;br&gt;&lt;/p&gt;',
        $html,
        'the stored value must be shown verbatim, escaped, so the failure can be diagnosed'
    );

    /**
     * AND A DECISION AGAINST AN EMPTY ANSWER IS FLAGGED FOR CORRECTION.
     *
     * Zero is used rather than a positive mark, because a positive mark on an answer
     * that does not exist is refused outright — which is itself worth pinning here, so
     * the fixture reaches the flagged state through a path the application actually
     * permits.
     */
    $this->actingAs($this->lecturer)
        ->post(route('teacher.online_exams.submissions.record_decision', [
            'submission' => $submission->id, 'question' => $this->shortQ,
        ]), ['awarded_marks' => 0])
        ->assertSessionHasNoErrors();

    $this->assertSame(
        0.0,
        (float) $this->answerFor($submission, $this->shortQ)->fresh()->awarded_marks,
        'zero must be stored as a decision, not treated as "no decision"'
    );

    $page = $this->actingAs($this->lecturer)
        ->get(route('teacher.online_exams.results', $this->exam->id))
        ->assertOk()
        ->getContent();

    $this->assertStringContainsString(
        'mark-against-empty-answer',
        $page,
        'a decision taken against an empty answer must be called out so it can be corrected'
    );
}

    // ── helpers ──────────────────────────────────────────────────────────────

    private function openAttempt(): OnlineExamSubmission
    {
        $id = (int) DB::table('online_exam_submissions')->insertGetId([
            'online_exam_id' => $this->exam->id,
            'student_id' => $this->student->id,
            'attempt_no' => 1,
            'school_id' => 1,
            'total_marks_snapshot' => 20,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(45),
            'last_activity_at' => now(),
            'status' => OnlineExamSubmission::STATUS_IN_PROGRESS,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return OnlineExamSubmission::query()->findOrFail($id);
    }

    /**
     * Saves each answer, then submits.
     *
     * @param array<int, array<string, mixed>> $answers questionId => fields
     */
    private function attemptAndSubmit(array $answers): OnlineExamSubmission
    {
        $submission = $this->openAttempt();

        foreach ($answers as $questionId => $fields) {
            $this->actingAs($this->student)
                ->postJson(route('student.online_exam.save_answer', $submission->id), array_merge([
                    'submission_id' => $submission->id,
                    'question_id' => $questionId,
                    'answer_revision' => 1,
                ], $fields))
                ->assertOk();
        }

        $this->actingAs($this->student)
            ->post(route('student.online_exam.submit', $this->exam->id), ['submission_id' => $submission->id])
            ->assertSessionHasNoErrors();

        return $submission->fresh()->load(['exam.questions', 'answerRows']);
    }

    private function answerFor(OnlineExamSubmission $submission, int $questionId): OnlineExamAnswer
    {
        return OnlineExamAnswer::query()
            ->where('submission_id', $submission->id)
            ->where('question_id', $questionId)
            ->firstOrFail();
    }
}
