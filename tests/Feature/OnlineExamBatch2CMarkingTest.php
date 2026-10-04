<?php

namespace Tests\Feature;

use App\Models\OnlineExamSubmission;
use App\Support\OnlineExams\OnlineExamMarking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

class OnlineExamBatch2CMarkingTest extends TestCase
{
    use OnlineExamTestHelper;

    private $teacher;
    private $student;
    private $admin;
    private int $exam;
    private int $submission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();
        // The shared minimal fixture omits this existing live-schema column.
        \Illuminate\Support\Facades\Schema::table('online_exam_submissions', function ($table) {
            $table->timestamp('result_email_sent_at')->nullable();
        });
        Mail::fake();
        $this->teacher = $this->makeUser(3, 1);
        $this->student = $this->makeUser(7, 1);
        $this->admin = $this->makeUser(2, 1);
        $this->exam = $this->makeExam(['creator_id' => $this->teacher->id, 'created_by' => $this->teacher->id]);
        $this->submission = $this->makeSubmission(['online_exam_id' => $this->exam,
            'student_id' => $this->student->id, 'school_id' => 1, 'total_marks_snapshot' => 10]);
    }

    private function answer(string $type, ?string $value = 'response', array $questionOverrides = []): int
    {
        $question = $this->makeQuestion($this->exam, $questionOverrides + ['type' => $type]);
        $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $this->submission), [
            'submission_id' => $this->submission, 'question_id' => $question, 'answer_revision' => 1,
            'selected_option' => in_array($type, ['mcq', 'true_false']) ? $value : null,
            'answer_text' => in_array($type, ['mcq', 'true_false']) ? null : $value,
        ])->assertOk();
        return (int) DB::table('online_exam_answers')->where('question_id', $question)->value('id');
    }

    private function submit(): void
    {
        $this->actingAs($this->student)->post(route('student.online_exam.submit', $this->exam),
            ['submission_id' => $this->submission])->assertRedirect();
    }

    private function mark(int $answer, $marks, array $extra = [])
    {
        return $this->actingAs($this->teacher)->postJson(route('teacher.online_exams.answers.mark', $answer),
            $extra + ['answer_id' => $answer, 'awarded_marks' => $marks]);
    }

    private function finalize()
    {
        return $this->actingAs($this->teacher)->postJson(route('teacher.online_exams.results.finalize', $this->submission));
    }

    private function record(): OnlineExamSubmission
    {
        return OnlineExamSubmission::findOrFail($this->submission);
    }

    public function test_objective_exam_auto_completes_and_still_waits_for_review(): void
    {
        $this->answer('mcq', 'a');
        $this->answer('true_false', 'false', ['correct_ans' => 'false']);
        $this->submit();

        /**
         * MARKING AUTO-COMPLETES; PUBLICATION STILL DOES NOT.
         *
         * This used to assert `finalized` straight out of a student's submit. That is
         * precisely the hole that produced exam 17 submission 12: a submission
         * announced as complete without any human involvement, wrote
         * `result_review_state = 'not_ready'` alongside it, and therefore appeared in
         * NO queue and admitted NO action - a result nobody could mark, hand over, or
         * review.
         *
         * So an all-objective paper now lands on `submitted`: marking genuinely needs
         * nobody, but the result still passes the handover and the ADMIN review gate
         * before a student can see it. That is the required chain -
         *
         *     SUBMITS -> AUTOMATIC MARKING COMPLETE -> ADMIN RESULT REVIEW/PUBLICATION
         *
         * and `finalize()` is therefore no longer a no-op here; it is the handover.
         */
        $this->assertSame('submitted', $this->record()->status);
        $this->assertEquals(10, $this->record()->score);
        $this->assertSame('not_ready', $this->record()->result_review_state);

        // The handover, then idempotence.
        $this->finalize()->assertRedirect();
        $this->assertSame('finalized', $this->record()->status);
        $this->assertSame('pending_review', $this->record()->result_review_state);

        $after = $this->record()->getAttributes();
        $this->finalize()->assertRedirect();
        $this->assertSame($after, $this->record()->getAttributes());

        // Handed over and finalized, so the marking is done and the student is told it is
        // awaiting administrative review. Still nothing released.
        $this->actingAs($this->student)->get(route('student.online_exam.result', $this->submission))
            ->assertOk()->assertSee('awaiting administrative review');
    }

    public function test_legacy_mcq_answer_text_is_normalized_for_readiness_and_auto_marking(): void
    {
        $answer = $this->answer('mcq', 'a', ['marks' => 5]);
        $questionId = DB::table('online_exam_answers')->where('id', $answer)->value('question_id');
        DB::table('online_exam_questions')->where('id', $questionId)->update([
            'correct_ans' => 'Yes', 'option_a' => 'Yes', 'option_b' => 'No',
        ]);
        $this->submit();
        // An objective paper needs no marker, so it goes to the handover gate rather
        // than straight to `finalized`. See
        // test_objective_exam_auto_completes_and_still_waits_for_review().
        $this->assertSame('submitted', $this->record()->status);
        $this->assertEquals(5, $this->record()->score);
        $this->assertDatabaseHas('online_exam_answers', ['id' => $answer, 'awarded_marks' => 5]);
        $pendingQuery = \App\Models\OnlineExamQuestion::whereKey($questionId);
        OnlineExamMarking::manualQuestions($pendingQuery);
        $this->assertFalse($pendingQuery->exists());
    }

    public function test_manual_exam_can_be_marked_ready_finalized_and_viewed(): void
    {
        $answer = $this->answer('essay');
        $this->submit();
        $this->assertSame('pending_manual_marking', $this->record()->status);
        $this->mark($answer, 4.25, ['teacher_comment' => 'Reasoned response'])->assertRedirect();
        $this->assertSame('submitted', $this->record()->status);
        $this->assertNull($this->record()->passed);
        $this->assertSame('Ready to finalize', $this->record()->marking_state_label);
        $this->actingAs($this->student)->get(route('student.online_exam.result', $this->submission))
            ->assertOk()->assertSee('being processed');
        $this->finalize()->assertRedirect();
        $this->assertEquals(4.25, $this->record()->score);
        $this->assertDatabaseHas('online_exam_answers', ['id' => $answer, 'marked_by' => $this->teacher->id, 'teacher_comment' => 'Reasoned response']);
        $this->assertNotNull(DB::table('online_exam_answers')->where('id', $answer)->value('marked_at'));
    }

    public function test_explicit_zero_is_marked_and_can_finalize(): void
    {
        $answer = $this->answer('short', '0');
        $this->submit();
        $this->mark($answer, 0)->assertRedirect();
        $this->assertSame(0, OnlineExamMarking::summary($this->record())['pending']);
        $this->finalize()->assertRedirect();
        $this->assertEquals(0, $this->record()->score);
        $this->assertSame('finalized', $this->record()->status);
    }

    public function test_numeric_legacy_mark_without_marker_and_timestamp_is_not_complete(): void
    {
        $answer = $this->answer('essay');
        $this->submit();
        DB::table('online_exam_answers')->where('id', $answer)->update(['awarded_marks' => 0]);
        $this->assertHandoverRefusedWithExplanation($this->finalize());
        $this->mark($answer, 0)->assertRedirect();
        $this->finalize()->assertRedirect();
    }

    public function test_incomplete_answered_manual_question_blocks_finalization(): void
    {
        $answer = $this->answer('essay');
        $this->answer('short');
        $this->submit();
        $this->mark($answer, 3)->assertRedirect();
        $this->assertHandoverRefusedWithExplanation($this->finalize());
        $this->assertSame('pending_manual_marking', $this->record()->status);
    }

    public function test_mixed_exam_combines_objective_and_examiner_marks(): void
    {
        $objective = $this->answer('mcq', 'a', ['marks' => 4]);
        $manual = $this->answer('essay', 'Explain', ['marks' => 6]);
        $this->submit();
        $this->assertEquals(4, $this->record()->objective_score);
        $this->mark($manual, 4.5)->assertRedirect();
        $this->finalize()->assertRedirect();
        $this->assertEquals(8.5, $this->record()->score);
        $this->assertTrue($this->record()->passed);
        $this->assertDatabaseHas('online_exam_answers', ['id' => $objective, 'awarded_marks' => 4, 'marked_by' => null]);
        $this->assertDatabaseHas('online_exam_answers', ['id' => $manual, 'awarded_marks' => 4.5]);
    }

    public function test_missing_blank_and_cleared_manual_answers_contribute_zero(): void
    {
        $this->makeQuestion($this->exam, ['type' => 'essay']);   // never answered at all
        $this->answer('short', null);                             // answered, then cleared
        $answer = $this->answer('essay');                        // answered
        $question = DB::table('online_exam_answers')->where('id', $answer)->value('question_id');
        $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $this->submission), [
            'submission_id' => $this->submission, 'question_id' => $question, 'answer_revision' => 2, 'answer_text' => '',
        ])->assertOk();

        $this->submit();

        /**
         * BLANK MANUAL QUESTIONS NO LONGER VANISH FROM THE ARITHMETIC.
         *
         * Two of these three questions have no answer content. They still contribute
         * ZERO - that is unchanged and is what this test is about - but they are now
         * still QUESTIONS, so a marker must decide them, and the attempt cannot be
         * declared complete on its own.
         *
         * Previously `summary()['pending']` ignored a question with no response, so
         * this submission reported `pending = 0` and was written straight to
         * `finalized`. That is submission 12: "Marking Complete / Finalized / Not
         * Released", ten marks nobody awarded, and an empty Actions column.
         *
         * The count is now three rather than two because an EMPTY row is created for
         * the never-answered essay. It is genuinely empty, so it still contributes
         * zero and `OnlineExamMarking::responses()` still excludes it from the read
         * queue; its only purpose is to give a marker something to record an explicit,
         * attributed zero against.
         */
        $this->assertSame('pending_manual_marking', $this->record()->status);
        $this->assertEquals(0, $this->record()->score);
        $this->assertDatabaseCount('online_exam_answers', 3);

        // And it cannot be handed over until a marker has decided each blank.
        $this->assertHandoverRefusedWithExplanation($this->finalize());

        $blankAnswerId = DB::table('online_exam_answers')
            ->where('submission_id', $this->submission)
            ->whereNull('answer_text')
            ->whereNull('selected_option')
            ->value('id');

        $this->mark($blankAnswerId, 0)->assertRedirect();
        $this->assertDatabaseHas('online_exam_answers', ['id' => $blankAnswerId, 'awarded_marks' => 0]);
    }

    public function test_repeated_mark_finalize_and_publish_do_not_duplicate_changes_or_mail(): void
    {
        $this->enableSmtpSettings();
        DB::table('online_exams')->where('id', $this->exam)->update(['result_release_policy' => 'manual']);
        $answer = $this->answer('essay');
        $this->submit();
        $this->mark($answer, 5)->assertRedirect();
        $beforeAnswer = DB::table('online_exam_answers')->where('id', $answer)->first();
        $this->travel(2)->seconds();
        $this->mark($answer, 5)->assertRedirect();
        $this->assertEquals($beforeAnswer, DB::table('online_exam_answers')->where('id', $answer)->first());
        $this->finalize()->assertRedirect();
        $before = $this->record()->getAttributes();
        $this->finalize()->assertRedirect();
        $this->assertSame($before, $this->record()->getAttributes());
        Mail::assertNothingSent();
        $url = route('teacher.online_exams.results.publish', $this->submission);
        $this->postJson($url)->assertStatus(403);
        $published = $this->record()->getAttributes();
        $this->postJson($url)->assertStatus(403);
        $this->finalize()->assertRedirect();
        $this->assertSame($published, $this->record()->getAttributes());
        Mail::assertNothingSent();

        // Once finalized, marking is refused — but the lecturer is returned to the
        // page with the reason rather than shown a bare error document, and the
        // finalized score is untouched.
        $this->mark($answer, 0)
            ->assertRedirect()
            ->assertSessionHasErrors('awarded_marks');
        $this->assertEquals(5, $this->record()->score);
    }

    public function test_marker_corrections_before_finalization_recompute_without_double_counting(): void
    {
        $answer = $this->answer('essay');
        $this->submit();
        $this->mark($answer, 4)->assertRedirect();
        $this->mark($answer, 1)->assertRedirect();
        $this->assertEquals(1, $this->record()->score);
        $this->finalize()->assertRedirect();
        $this->assertEquals(1, $this->record()->score);
    }

    public function test_marking_rejects_other_teacher_student_school_and_invalid_bounds(): void
    {
        $answer = $this->answer('essay');
        $this->submit();
        foreach ([$this->makeUser(3, 1), $this->makeUser(3, 2), $this->student] as $user) {
            $this->actingAs($user)->postJson(route('teacher.online_exams.answers.mark', $answer), ['answer_id' => $answer, 'awarded_marks' => 1])->assertStatus($user->role_id === 7 ? 302 : 403);
        }
        foreach ([-1, 6, '0.001'] as $invalid) $this->mark($answer, $invalid)->assertStatus(422);
        $this->mark($answer, 1, ['answer_id' => 99999])->assertStatus(422);
        $this->assertNull(DB::table('online_exam_answers')->where('id', $answer)->value('marked_by'));
    }

    public function test_corrupt_cross_exam_question_cannot_be_marked(): void
    {
        $answer = $this->answer('essay');
        $this->submit();
        DB::table('online_exam_answers')->where('id', $answer)->update(['question_id' => $this->makeQuestion($this->makeExam(), ['type' => 'essay'])]);
        $this->mark($answer, 1)->assertStatus(422);
    }

    public function test_student_cannot_write_marking_metadata(): void
    {
        $question = $this->makeQuestion($this->exam);
        foreach (['awarded_marks', 'is_correct', 'marked_by', 'marked_at'] as $field) {
            $this->actingAs($this->student)->postJson(route('student.online_exam.save_answer', $this->submission), [
                'submission_id' => $this->submission, 'question_id' => $question, 'answer_revision' => 1,
                'selected_option' => 'a', $field => 1,
            ])->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('online_exam_answers', 0);
    }

    public function test_manual_release_hides_result_until_authorized_publication(): void
    {
        DB::table('online_exams')->where('id', $this->exam)->update(['result_release_policy' => 'manual']);
        $this->answer('mcq', 'a');
        $this->submit();
        $result = route('student.online_exam.result', $this->submission);

        // Submitted on an objective paper: nothing outstanding, but the handover has
        // not happened, so the student is told it is being processed rather than that
        // a result is awaiting publication.
        $this->actingAs($this->student)->get($result)->assertOk()->assertSee('being processed');
        foreach ([$this->makeUser(3, 1), $this->makeUser(3, 2), $this->student] as $user) {
            $before = $this->record()->getAttributes();
            $response = $this->actingAs($user)->postJson(route('teacher.online_exams.results.publish', $this->submission));
            $this->assertContains($response->status(), [302, 403, 404]);
            // The property under test is that an UNAUTHORISED publish attempt
            // changes nothing. Asserted as a snapshot so it holds whatever the
            // current correct pre-review state is, rather than pinning a status
            // word - pinning `finalized` here is what previously blessed a
            // student's submit as "complete".
            $this->assertSame($before, $this->record()->getAttributes());
            $this->assertFalse($this->record()->is_published ?? false);
        }
        $this->actingAs($this->teacher)->postJson(route('teacher.online_exams.results.publish', $this->submission))->assertStatus(403);

        // Unchanged by the refused attempts: still with the lecturer, so still
        // "being processed" rather than awaiting a release that never happened.
        $this->actingAs($this->student)->get($result)->assertOk()->assertSee('being processed');
        foreach ([$this->makeUser(7, 1), $this->makeUser(7, 2)] as $user) $this->actingAs($user)->get($result)->assertNotFound();
    }

    public function test_after_end_release_and_active_pending_result_protection(): void
    {
        $result = route('student.online_exam.result', $this->submission);
        $this->actingAs($this->student)->get($result)->assertForbidden();
        DB::table('online_exams')->where('id', $this->exam)->update(['result_release_policy' => 'after_exam_end']);
        $answer = $this->answer('essay');
        $this->submit();
        $this->actingAs($this->student)->get($result)->assertOk()->assertSee('being processed');
        $this->mark($answer, 5)->assertRedirect();
        $this->finalize()->assertRedirect();
        $this->actingAs($this->student)->get($result)->assertOk()->assertSee('awaiting administrative review');
        $this->travel(2)->hours();
        $this->get($result)->assertOk();
    }

    public function test_objective_override_is_rejected_and_unconfigured_key_requires_human_mark(): void
    {
        $objective = $this->answer('mcq', 'a');
        $fallback = $this->answer('true_false', 'true', ['correct_ans' => null]);
        $this->submit();
        $this->mark($objective, 3, ['allow_objective_override' => true])->assertStatus(422);
        $this->assertSame('pending_manual_marking', $this->record()->status);
        $this->actingAs($this->teacher)->get(route('teacher.online_exams.marking'))->assertOk()->assertViewHas('answers', fn ($answers) => $answers->contains('id', $fallback));
        $this->mark($fallback, 2)->assertRedirect();
        $this->finalize()->assertRedirect();
        $this->assertEquals(7, $this->record()->score);
    }

    public function test_admin_and_teacher_views_show_ready_finalize_and_publish_actions(): void
    {
        DB::table('online_exams')->where('id', $this->exam)->update(['result_release_policy' => 'manual']);
        $answer = $this->answer('essay');
        $this->submit();
        $this->mark($answer, 3)->assertRedirect();
        $this->actingAs($this->teacher)->get(route('teacher.online_exams.results', $this->exam))
            ->assertOk()->assertSee('Automatic')->assertSee('Manual')
            // The lecturer's summary column is titled "Pending Decisions". The admin
            // screen keeps "Awaiting Decision" - they are two screens written at two
            // times, and only the lecturer one was relaid out here.
            ->assertSee('Pending Decisions')
            // The lecturer's handover is named for what it does. It was previously
            // "Submit Marking for Review" and, on the marking screen,
            // "Finalize Result" - both of which read as though pressing them
            // released the mark to the student. They do not, and they now say so.
            ->assertSee('Submit Marks for Admin Review')
            ->assertDontSee('Publish to Student');
        $this->actingAs($this->teacher)->get(route('teacher.online_exams.marking', ['status' => 'marked']))
            ->assertOk()->assertSee('Automatic')->assertSee('Manual')->assertSee('Total');
        // The third column is "Awaiting Decision", not "Pending". The old label
        // counted only ANSWERED-but-unmarked questions, so a paper whose written
        // question had been left blank reported zero there and read as complete —
        // which is how exam 19 submission 13 looked fully judged to everyone. The
        // column now counts every question that still needs a human decision,
        // including the ones with no answer at all.
        $this->actingAs($this->admin)->get(route('admin.online_exams.results', $this->exam))
            ->assertOk()->assertSee('Automatic')->assertSee('Manual')->assertSee('Awaiting Decision');
        $this->postJson(route('admin.online_exams.submissions.finalize', $this->submission))->assertRedirect();
        $this->get(route('admin.online_exams.results', $this->exam))->assertOk()->assertSee('Pending Admin Review');
        $this->postJson(route('admin.online_exams.submissions.publish_result', $this->submission))->assertRedirect();
        $this->get(route('admin.online_exams.results', $this->exam))->assertOk()->assertSee('Result published')->assertDontSee('Publish result');
    }

    public function test_fractional_question_maximum_is_rejected_without_rounding(): void
    {
        DB::table('online_exams')->where('id', $this->exam)->update(['is_published' => 0, 'workflow_state' => 'draft']);
        DB::table('online_exam_submissions')->where('id', $this->submission)->update(['status' => 'cancelled']);
        $this->actingAs($this->teacher)->postJson(route('teacher.online_exams.questions.store', $this->exam), [
            'question' => 'Written', 'type' => 'essay', 'marks' => 1.5,
        ])->assertStatus(422)->assertJsonValidationErrors('marks');
        $this->assertDatabaseCount('online_exam_questions', 0);
    }

    public function test_snapshot_total_and_raw_pass_mark_presentation(): void
    {
        DB::table('online_exams')->where('id', $this->exam)->update(['total_marks' => 20, 'pass_mark' => 4]);
        $this->answer('mcq', 'a');
        $this->submit();
        $this->assertEquals(10, $this->record()->result_total_marks);
        $this->assertTrue($this->record()->passed);
        $this->actingAs($this->student)->get(route('student.online_exam.result', $this->submission))->assertOk()->assertSee('being processed')->assertDontSee('4%');
    }

    public function test_fill_blank_remains_manual_even_with_a_configured_text_key(): void
    {
        $answer = $this->answer('fill_blank', 'Paris', ['correct_ans' => 'Paris']);
        $this->submit();
        $this->assertSame('pending_manual_marking', $this->record()->status);
        $this->assertNull(DB::table('online_exam_answers')->where('id', $answer)->value('awarded_marks'));
        $this->mark($answer, 5)->assertRedirect();
        $this->finalize()->assertRedirect();
        $this->assertEquals(5, $this->record()->manual_score);
    }

    public function test_queue_excludes_unanswered_and_finalized_and_recognizes_marked_zero(): void
    {
        $answered = $this->answer('essay');
        $blank = $this->answer('short', null);
        $this->submit();
        $queue = route('teacher.online_exams.marking');
        $this->actingAs($this->teacher)->get($queue)->assertOk()->assertViewHas('answers', fn ($rows) => $rows->contains('id', $answered) && !$rows->contains('id', $blank));

        /**
         * A BLANK MANUAL ANSWER MAY NOW BE MARKED — AND THE POINT OF "0" IS STILL
         * RECOGNISED.
         *
         * Marking a blank was refused outright, on the reasonable grounds that there
         * is nothing there to read. The consequence was worse: a blank manual
         * question could never acquire a marker's decision, so it stayed permanently
         * undecided, so the paper could never be honestly completed. Exam 17
         * submission 12 is that exact deadlock.
         *
         * So a marker may record an EXPLICIT ZERO against a blank, which is stored
         * with `marked_by` and `marked_at` and appears in the "marked" queue. What
         * remains refused - deliberately - is a mark ABOVE zero for an answer that
         * does not exist, because that would be fabricating a result. Asserted below.
         */
        $this->mark($blank, 0)->assertRedirect();
        $this->assertDatabaseHas('online_exam_answers', [
            'id' => $blank,
            'awarded_marks' => 0,
            'marked_by' => $this->teacher->id,
        ]);
        $this->assertNotNull(DB::table('online_exam_answers')->where('id', $blank)->value('marked_at'));

        // Above zero is still refused for a blank — with an explanation, not an error page.
        $this->mark($blank, 3)
            ->assertRedirect()
            ->assertSessionHasErrors('awarded_marks');

        $this->mark($answered, 0)->assertRedirect();
        $this->get($queue.'?status=marked')->assertOk()->assertViewHas('answers', fn ($rows) => $rows->contains('id', $answered));
        $this->finalize()->assertRedirect();
        $this->get($queue.'?status=marked')->assertOk()->assertViewHas('answers', fn ($rows) => $rows->isEmpty());
    }

    public function test_admin_marking_uses_same_rules_and_preserves_comment_on_correction(): void
    {
        $answer = $this->answer('essay');
        $this->submit();
        $url = route('admin.online_exams.answers.manual_mark', $answer);
        $this->actingAs($this->admin)->postJson($url, ['answer_id' => $answer, 'awarded_marks' => 2, 'teacher_comment' => 'Review'])->assertRedirect();
        $this->postJson($url, ['answer_id' => $answer, 'awarded_marks' => 3])->assertRedirect();
        $this->assertDatabaseHas('online_exam_answers', ['id' => $answer, 'awarded_marks' => 3, 'teacher_comment' => 'Review', 'marked_by' => $this->admin->id]);
        $this->postJson(route('admin.online_exams.submissions.finalize', $this->submission))->assertRedirect();
        $this->actingAs($this->student)->get(route('student.online_exam.result', $this->submission))->assertOk();
    }

    public function test_finalization_authorization_and_early_publication_are_enforced(): void
    {
        DB::table('online_exams')->where('id', $this->exam)->update(['result_release_policy' => 'manual']);
        $answer = $this->answer('essay');
        $this->submit();
        $this->actingAs($this->teacher)->postJson(route('teacher.online_exams.results.publish', $this->submission))->assertStatus(403);
        $this->mark($answer, 2)->assertRedirect();
        foreach ([$this->makeUser(3, 1), $this->makeUser(3, 2)] as $user) {
            $this->actingAs($user)->postJson(route('teacher.online_exams.results.finalize', $this->submission))->assertStatus($user->school_id === 2 ? 404 : 403);
        }
        $this->assertSame('submitted', $this->record()->status);
        $this->actingAs($this->teacher)->postJson(route('teacher.online_exams.results.publish', $this->submission))->assertStatus(403);
        $this->finalize()->assertRedirect();
    }

    public function test_two_marked_answers_recompute_using_fresh_rows_without_lost_totals(): void
    {
        $first = $this->answer('essay');
        $second = $this->answer('short');
        $this->submit();
        $this->mark($first, 1.25)->assertRedirect();
        $this->mark($second, 2.5)->assertRedirect();
        $this->mark($first, 0)->assertRedirect();
        $this->finalize()->assertRedirect();
        $this->assertEquals(2.5, $this->record()->manual_score);
        $this->assertEquals(2.5, $this->record()->score);
    }
}
