<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use App\Models\OnlineExam;
use App\Support\OnlineExams\OnlineExamPortalNotifier;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

class OnlineExamAdminVisibilityTest extends TestCase
{
    use OnlineExamTestHelper;

    private $admin;
    private $student;
    private int $exam;
    private int $submission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();
        $this->admin = $this->makeUser(2, 1);
        $this->student = $this->makeUser(7, 1);
        $this->exam = $this->makeExam([
            'title' => 'Admin visibility test',
            'result_release_policy' => 'manual',
        ]);
        $this->submission = $this->makeSubmission([
            'online_exam_id' => $this->exam,
            'student_id' => $this->student->id,
            'school_id' => 1,
        ]);
    }

    private function createPendingManualAttempt(): void
    {
        $question = $this->makeQuestion($this->exam, [
            'type' => 'essay',
            'correct_ans' => null,
            'marks' => 5,
        ]);
        $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $this->submission), [
            'submission_id' => $this->submission,
            'question_id' => $question,
            'answer_revision' => 1,
            'answer_text' => 'Written response',
        ])->assertOk();
        $this->actingAs($this->student)->post(route('student.online_exam.submit', $this->exam), [
            'submission_id' => $this->submission,
        ])->assertRedirect();
    }

    public function test_admin_index_exposes_submission_count_pending_marking_and_results_action(): void
    {
        $this->createPendingManualAttempt();

        $this->actingAs($this->admin)->get(route('admin.online_exams.index'))
            ->assertOk()
            ->assertSee('Admin visibility test')
            ->assertSee('Manual marking required')
            ->assertSee('Results &amp; Marking', false)
            ->assertSee('1');
    }

    public function test_admin_submissions_exposes_marking_and_proctoring_routes_and_event_label(): void
    {
        $this->createPendingManualAttempt();
        DB::table('online_exam_proctoring_events')->insert([
            'submission_id' => $this->submission,
            'event_type' => 'tab_hidden',
            'event_time' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('admin.online_exams.submissions', $this->exam))
            ->assertOk()
            ->assertSee('Awaiting Marking')
            ->assertSee('Mark / Review')
            ->assertSee('Proctoring');

        $this->actingAs($this->admin)->get(route('admin.online_exams.proctoring.review', [
            'id' => $this->exam,
            'submission' => $this->submission,
        ]))->assertOk()->assertSee('Tab Hidden');
    }

    public function test_authorized_admin_publication_persists_and_is_idempotent(): void
    {
        DB::table('online_exams')->where('id', $this->exam)->update(['result_release_policy' => 'manual']);
        $question = $this->makeQuestion($this->exam, ['type' => 'mcq', 'correct_ans' => 'a', 'marks' => 5]);
        $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $this->submission), [
            'submission_id' => $this->submission, 'question_id' => $question, 'answer_revision' => 1, 'selected_option' => 'a',
        ])->assertOk();
        $this->actingAs($this->student)->post(route('student.online_exam.submit', $this->exam), ['submission_id' => $this->submission])->assertRedirect();

        // A student's submit no longer finalises. It lands on the handover gate,
        // because `finalized` is now written only by a deliberate staff act - which
        // is what closed the submission-12 dead end, where a result read "Finalized /
        // Not Released" with nothing able to action it.
        $this->assertSame('submitted', DB::table('online_exam_submissions')->where('id', $this->submission)->value('status'));

        // The handover is now performed through the REAL route rather than by
        // writing `result_review_state` straight into the table. The previous
        // version had to fake it, because no handover existed for a paper needing no
        // marking; now it does, and this exercises the same authorisation, audit and
        // state transition an administrator would.
        $this->actingAs($this->admin)
            ->post(route('admin.online_exams.submissions.finalize', $this->submission))
            ->assertRedirect();

        $this->assertSame('finalized', DB::table('online_exam_submissions')->where('id', $this->submission)->value('status'));
        $this->assertSame('pending_review', DB::table('online_exam_submissions')->where('id', $this->submission)->value('result_review_state'));

        $this->actingAs($this->admin)->post(route('admin.online_exams.submissions.publish_result', $this->submission))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('result_published', DB::table('online_exam_submissions')->where('id', $this->submission)->value('status'));
        $this->assertDatabaseHas('online_exam_user_notifications', [
            'user_id' => $this->student->id,
            'type' => 'result_published',
            'submission_id' => $this->submission,
        ]);

        $this->actingAs($this->admin)->post(route('admin.online_exams.submissions.publish_result', $this->submission))
            ->assertRedirect();
        $this->assertSame('result_published', DB::table('online_exam_submissions')->where('id', $this->submission)->value('status'));
    }

    /**
     * THE `after_exam_end` WINDOW STILL REFUSES PUBLICATION — AND NOW SAYS WHY.
     *
     * The rule is unchanged and still holds: nothing is released early. What changed is
     * the refusal itself. This used to assert a bare `422`, which is what an
     * administrator actually saw for exam 17 submission 12 and could not interpret.
     *
     * The refusal is now a redirect carrying an explanation, asserted here so the
     * rule and its presentation are both pinned.
     */
public function test_after_exam_end_policy_cannot_be_published_manually(): void
{
    $this->createPendingManualAttempt();

    $response = $this->actingAs($this->admin)->post(route('admin.online_exams.submissions.publish_result', $this->submission));

    // Refused, and refused with words rather than a status code.
    $response->assertRedirect();
    $this->assertNotSame(422, $response->status(), 'an ordinary workflow rejection is not an error document');
    $response->assertSessionHasErrors('result');

    // The rule itself is untouched: still not released.
    $this->assertNotSame('result_published', DB::table('online_exam_submissions')->where('id', $this->submission)->value('status'));
    $this->assertNull(DB::table('online_exam_submissions')->where('id', $this->submission)->value('published_at'));
}

    public function test_marking_completion_stays_not_ready_until_explicit_review_submission(): void
    {
        $this->createPendingManualAttempt();
        $answer = DB::table('online_exam_answers')->where('submission_id', $this->submission)->first();

        $this->actingAs($this->admin)->post(route('admin.online_exams.answers.manual_mark', $answer->id), [
            'answer_id' => $answer->id,
            'awarded_marks' => 4,
        ])->assertRedirect();

        $this->assertSame('submitted', DB::table('online_exam_submissions')->where('id', $this->submission)->value('status'));
        $this->assertSame('not_ready', DB::table('online_exam_submissions')->where('id', $this->submission)->value('result_review_state'));
        $this->assertDatabaseMissing('online_exam_user_notifications', [
            'submission_id' => $this->submission,
            'type' => 'marking_submitted_for_review',
        ]);
        // The row is `submitted` + `not_ready`: with the lecturer, not yet handed over.
        // The student is told the result is being processed, which is the truthful
        // description of that state. The old page branched on `status` and would have
        // claimed the result was finalized and awaiting publication.
        $this->actingAs($this->student)->get(route('student.online_exam.result', $this->submission))
            ->assertOk()->assertSee('being processed');
    }

    /**
     * A marking-review notification must land on THAT submission's results row.
     *
     * ── WHAT CHANGED, AND WHY THIS ASSERTION CHANGED WITH IT ────────────────
     *
     * This used to assert byte equality against
     *
     *     route('admin.online_exams.results', $exam) . '?submission=' . $id . '#submission-' . $id
     *
     * which pinned the stored value to an ABSOLUTE url rooted at `config('app.url')`.
     * That is precisely the defect: an administrator was handed
     * `http://localhost/admin/online-exams/17` while the application was being served
     * from another host and port, and clicking it left the application for a server
     * that has no document root for that path.
     *
     * So the assertion is now split, and both halves matter more than the old one:
     *
     *  - the TARGET is unchanged and still pinned - same named route, same query,
     *    same fragment. The notification still opens the exact submission's row;
     *  - the STORED value is a location inside this application, with no origin, so
     *    it cannot be resolved against the wrong host at click time.
     *
     * Dropping the origin is not a loosening of "which page": `route()` still
     * decides that, so the path cannot drift from the routing table. See
     * `OnlineExamNotificationLink`.
     */
    public function test_marking_review_notification_targets_exact_submission_results_row(): void
    {
        OnlineExamPortalNotifier::admins(
            'marking_submitted_for_review',
            'Marking Awaiting Review',
            'Review this submission.',
            OnlineExam::findOrFail($this->exam),
            3,
            'marking-review:' . $this->submission,
            $this->submission
        );

        $notification = DB::table('online_exam_user_notifications')
            ->where('type', 'marking_submitted_for_review')
            ->where('submission_id', $this->submission)
            ->first();

        $this->assertNotNull($notification);

        $expectedPath = '/'.ltrim(parse_url(route('admin.online_exams.results', $this->exam), PHP_URL_PATH), '/');
        $expected = $expectedPath
            .'?submission='.$this->submission
            .'#submission-'.$this->submission;

        $this->assertSame(
            $expected,
            $notification->action_url,
            'the stored target must be that exact route, query and fragment'
        );

        // And it must carry NO origin, which is the whole point.
        $this->assertStringStartsNotWith(
            'http',
            $notification->action_url,
            'a stored notification link must not be pinned to APP_URL, or it leaves the application when clicked'
        );

        // Resolved for a reader, it comes back onto whatever host they are on.
        $this->assertStringStartsWith(
            url()->to('/'),
            \App\Support\OnlineExams\OnlineExamNotificationLink::toAbsolute($notification->action_url),
            'the link must resolve against the current application, not a configured one'
        );
    }
}
