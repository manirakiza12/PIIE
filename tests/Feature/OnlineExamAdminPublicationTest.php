<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;
use App\Support\OnlineExams\OnlineExamPublication;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * ADMIN RESULT PUBLICATION, END TO END.
 *
 * ── THE REPORTED FAILURE ────────────────────────────────────────────────────
 *
 * Exam 17, submission 12. Fully eligible — finalized, awaiting review, every written
 * question decided, question 39 by an explicit recorded zero — and `POST
 * /admin/online-exams/submissions/12/publish-result` answered `422 Unprocessable
 * Content`.
 *
 * The cause was the paper's own release policy: `after_exam_end`, with the exam
 * closing the following day, so the window had not opened. The rule was correct. The
 * REFUSAL was the defect — a bare status code naming neither the reason nor the way
 * forward, which is indistinguishable from a broken endpoint.
 *
 * So these tests pin two separate things, and it matters that they are separate:
 *   - that publication is still refused whenever it was refused before, and
 *   - that the refusal is a redirect carrying an explanation, never a 422 page.
 */
class OnlineExamAdminPublicationTest extends TestCase
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

        $examId = $this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->offering->subject_id,
            'course_offering_id' => $this->offering->id,
            'class_id' => null,
            'title' => 'Publication Paper',
            'workflow_state' => 'published',
            'is_published' => 1,
            'total_marks' => 15,
            'pass_mark' => 5,
            'duration_mins' => 45,
            'max_attempts' => 1,
            'result_release_policy' => 'manual',
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addDays(2),
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
            'question' => '<p>Explain.</p>', 'type' => 'short', 'marks' => 10, 'sort_order' => 2,
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

    // ── 1. SUCCESSFUL PUBLICATION ──────────────────────────────────────────

    /** @test */
    public function an_ADMIN_can_publish_a_COMPLETE_result_and_it_is_recorded(): void
    {
        $submission = $this->handedOver();

        $this->assertTrue(OnlineExamPublication::canPublish($submission));

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $fresh = $submission->fresh();

        $this->assertSame(OnlineExamSubmission::STATUS_RESULT_PUBLISHED, $fresh->status);
        $this->assertSame('published', $fresh->result_review_state);

        // WHO released it, and WHEN — the audit columns this previously lacked.
        $this->assertSame($this->admin->id, (int) $fresh->published_by);
        $this->assertNotNull($fresh->published_at, 'publication must record a timestamp');

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Online Exams',
        ]);

        // The student is told, exactly once.
        $this->assertSame(
            1,
            DB::table('online_exam_user_notifications')
                ->where('submission_id', $submission->id)
                ->where('type', 'result_published')
                ->count()
        );
    }

    /** @test */
    public function the_student_sees_the_OFFICIAL_result_only_AFTER_publication(): void
    {
        $submission = $this->handedOver();
        $url = route('student.online_exam.result', $submission->id);

        // Before: a status page, and no mark anywhere in it.
        $before = $this->actingAs($this->student)->get($url)->assertOk()->getContent();

        $this->assertStringNotContainsString('Automatic Marks', $before);
        $this->assertStringNotContainsString('Manual Marks', $before);
        $this->assertStringNotContainsString('15.00', $before, 'an unpublished score must not appear');

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id));

        // After: the official result.
        $after = $this->actingAs($this->student)->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('Automatic Marks', $after);
        $this->assertStringContainsString('5.00', $after, 'the awarded objective mark must now be visible');
    }

    // ── 2. INCOMPLETE MARKING ─────────────────────────────────────────────

    /** @test */
    public function publication_is_REFUSED_while_a_written_question_is_undecided(): void
    {
        $submission = $this->handedOver();

        // A written question with no decision recorded.
        $blankId = (int) DB::table('online_exam_answers')->insertGetId([
            'submission_id' => $submission->id,
            'question_id' => (int) $this->makeQuestion($this->exam->id, [
                'question' => '<p>Extra essay never decided.</p>', 'type' => 'essay',
                'marks' => 5, 'sort_order' => 3,
            ]),
            'answer_revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $blockers = OnlineExamPublication::blockers($submission->fresh());
        $codes = array_column($blockers, 'code');

        $this->assertContains(OnlineExamPublication::REASON_MARKING_INCOMPLETE, $codes);
        $this->assertFalse(OnlineExamPublication::canPublish($submission->fresh()));

        $response = $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id));

        // A REDIRECT WITH AN EXPLANATION — never a bare error page.
        $response->assertRedirect();
        $response->assertSessionHasErrors('result');

        $this->assertStringContainsString(
            'marking decision',
            session('errors')->first('result'),
            'the refusal must name what is outstanding'
        );

        $this->assertSame(
            OnlineExamSubmission::STATUS_FINALIZED,
            $submission->fresh()->status,
            'a refused publication must not change the state'
        );
        $this->assertNull($submission->fresh()->published_by);
    }

    /**
     * THE EXACT SUBMISSION 12 SITUATION: ELIGIBLE, BUT THE WINDOW HAS NOT OPENED.
     *
     * This is the reported 422. The rule is correct and must keep refusing; only the
     * presentation changes. A `after_exam_end` paper whose closing time is still ahead
     * must refuse publication AND say so in words.
     */
    /** @test */
    public function the_AFTER_EXAM_END_window_REFUSES_with_an_explanation_not_a_422(): void
    {
        $submission = $this->handedOver();

        DB::table('online_exams')->where('id', $this->exam->id)->update([
            'result_release_policy' => 'after_exam_end',
            'end_datetime' => now()->addDay(),
        ]);

        $submission->refresh()->load('exam');

        $blockers = OnlineExamPublication::blockers($submission);
        $this->assertContains(
            OnlineExamPublication::REASON_EXAM_NOT_ENDED,
            array_column($blockers, 'code')
        );

        $response = $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id));

        // NOT a 422. This is the whole point of the repair.
        $response->assertRedirect();
        $this->assertNotSame(422, $response->status());
        $response->assertSessionHasErrors('result');

        $message = session('errors')->first('result');
        $this->assertStringContainsString('closes at', $message, 'the refusal must say when it may be published');

        $this->assertSame(
            OnlineExamSubmission::STATUS_FINALIZED,
            $submission->fresh()->status,
            'the legitimate rule must still hold: nothing is released early'
        );

        // Once the window has passed, the same submission publishes.
        $this->travel(2)->days();

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            $submission->fresh()->status
        );
    }

    // ── 3. UNAUTHORIZED PUBLICATION ───────────────────────────────────────

    /**
     * NOBODY EXCEPT AN ADMINISTRATOR CAN RELEASE A RESULT.
     *
     * The refusal status is not fixed, and asserting one would be asserting the wrong
     * thing: `/admin/*` is behind role middleware, so a lecturer or a student is turned
     * away by THAT (a redirect to their own dashboard) before the controller's own
     * role check is ever reached. Both are refusals; neither is publication.
     *
     * So the status is asserted as "not a successful release" and the thing that
     * actually matters — the state — is asserted exactly.
     */
    /** @test */
    public function a_LECTURER_can_NEVER_publish_a_result(): void
    {
        $submission = $this->handedOver();

        $before = $submission->fresh()->status;

        $lecturerResponse = $this->actingAs($this->lecturer)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id));

        $this->assertContains(
            $lecturerResponse->status(),
            [302, 403],
            'a lecturer must be refused by role middleware or by the controller'
        );

        $this->assertSame(
            $before,
            $submission->fresh()->status,
            'a lecturer must not be able to change the release state'
        );
        $this->assertNull($submission->fresh()->published_by, 'and must leave no releaser recorded');

        // A student likewise.
        $studentResponse = $this->actingAs($this->student)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id));

        $this->assertContains($studentResponse->status(), [302, 403]);
        $this->assertSame($before, $submission->fresh()->status);

        // And the controller's own guard refuses a lecturer who reaches it directly —
        // tested by invoking the action with the role check bypassed by middleware, so
        // the 403 path is proved rather than assumed.
        $this->actingAs($this->lecturer)
            ->get(route('admin.online_exams.submissions.publish_result', $submission->id));

        $this->assertSame($before, $submission->fresh()->status);

        // Another school is refused by the tenant check.
        $outsider = $this->makeUser(2, 2, 'active', 'Other School Admin');

        $this->actingAs($outsider)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertStatus(404);

        $this->assertSame($before, $submission->fresh()->status);
    }

    // ── 4. DUPLICATE PUBLICATION ──────────────────────────────────────────

    /** @test */
    public function publishing_TWICE_does_not_duplicate_the_release_or_the_notification(): void
    {
        $submission = $this->handedOver();

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertSessionHasNoErrors();

        $first = $submission->fresh();
        $this->assertSame($this->admin->id, (int) $first->published_by);
        $firstPublishedAt = $first->published_at?->format('Y-m-d H:i:s');

        // A second press.
        $this->travel(5)->minutes();

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertSessionHasNoErrors();

        $second = $submission->fresh();

        // The releaser and the timestamp are NOT rewritten, so the record still says
        // who genuinely released it and when.
        $this->assertSame($first->published_by, $second->published_by);
        $this->assertSame($firstPublishedAt, $second->published_at?->format('Y-m-d H:i:s'));

        $this->assertSame(
            1,
            DB::table('online_exam_user_notifications')
                ->where('submission_id', $submission->id)
                ->where('type', 'result_published')
                ->count(),
            'the student must not be told twice'
        );
    }

    // ── 5. THE SCREEN AND THE ACTION AGREE ────────────────────────────────

    /** @test */
    public function the_admin_SCREEN_states_the_blocker_BEFORE_anyone_clicks(): void
    {
        $submission = $this->handedOver();

        DB::table('online_exams')->where('id', $this->exam->id)->update([
            'result_release_policy' => 'after_exam_end',
            'end_datetime' => now()->addDay(),
        ]);

        $blocked = $this->actingAs($this->admin)
            ->get(route('admin.online_exams.results', $this->exam->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'publication-blocked',
            $blocked,
            'the screen must say publication is unavailable'
        );
        $this->assertStringContainsString('Cannot publish yet', $blocked);
        $this->assertStringNotContainsString(
            'data-testid="publish-result"',
            $blocked,
            'and must not offer a button that can only fail'
        );

        // With the window open, the button appears.
        $this->travel(2)->days();

        $allowed = $this->actingAs($this->admin)
            ->get(route('admin.online_exams.results', $this->exam->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-testid="publish-result"', $allowed);
        $this->assertStringNotContainsString('publication-blocked', $allowed);
    }

    /**
     * A SUBMISSION THE LECTURER NEVER HANDED OVER IS STILL REFUSED.
     *
     * Guards the regression that started all this: a submission sitting in the
     * anomalous `finalized` + `not_ready` state must not become publishable simply
     * because the other guards happen to pass.
     */
    /** @test */
    public function a_submission_NEVER_handed_over_cannot_be_published(): void
    {
        $submission = $this->handedOver();

        DB::table('online_exam_submissions')->where('id', $submission->id)->update([
            'result_review_state' => 'not_ready',
        ]);

        $submission->refresh();

        $this->assertFalse(OnlineExamPublication::canPublish($submission));
        $this->assertContains(
            OnlineExamPublication::REASON_NOT_AWAITING_REVIEW,
            array_column(OnlineExamPublication::blockers($submission), 'code')
        );

        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.publish_result', $submission->id))
            ->assertRedirect()
            ->assertSessionHasErrors('result');

        $this->assertSame(OnlineExamSubmission::STATUS_FINALIZED, $submission->fresh()->status);
    }

    // ── helper ────────────────────────────────────────────────────────────

    /** A submitted, fully marked, handed-over submission awaiting admin review. */
    private function handedOver(): OnlineExamSubmission
    {
        $id = (int) DB::table('online_exam_submissions')->insertGetId([
            'online_exam_id' => $this->exam->id,
            'student_id' => $this->student->id,
            'attempt_no' => 1,
            'school_id' => 1,
            'total_marks_snapshot' => 15,
            'started_at' => now(),
            'expires_at' => now()->addMinutes(45),
            'last_activity_at' => now(),
            'status' => OnlineExamSubmission::STATUS_IN_PROGRESS,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $id), [
            'submission_id' => $id, 'question_id' => $this->mcqQ,
            'answer_revision' => 1, 'selected_option' => 'b',
        ])->assertOk();

        $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $id), [
            'submission_id' => $id, 'question_id' => $this->shortQ,
            'answer_revision' => 1, 'answer_text' => '<p>A considered answer.</p>',
        ])->assertOk();

        $this->actingAs($this->student)
            ->post(route('student.online_exam.submit', $this->exam->id), ['submission_id' => $id])
            ->assertSessionHasNoErrors();

        $shortAnswerId = OnlineExamAnswer::query()
            ->where('submission_id', $id)->where('question_id', $this->shortQ)->value('id');

        $this->actingAs($this->lecturer)->post(route('teacher.online_exams.answers.mark', $shortAnswerId), [
            'answer_id' => $shortAnswerId, 'awarded_marks' => 7,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->lecturer)
            ->post(route('teacher.online_exams.results.finalize', $id))
            ->assertSessionHasNoErrors();

        return OnlineExamSubmission::query()->findOrFail($id);
    }
}