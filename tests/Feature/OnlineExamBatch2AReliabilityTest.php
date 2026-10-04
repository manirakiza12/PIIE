<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

class OnlineExamBatch2AReliabilityTest extends TestCase
{
    use OnlineExamTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();
    }

    public function test_expired_resume_finalizes_the_attempt_and_cannot_reopen_it(): void
    {
        $student = $this->makeUser(7, 1);
        $exam = $this->makeExam();
        $this->makeQuestion($exam);
        $submission = $this->makeSubmission([
            'online_exam_id' => $exam,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'in_progress',
            'expires_at' => now()->subSecond(),
        ]);

        $this->actingAs($student)->get(route('student.online_exam.resume', $submission))->assertStatus(422);

        /**
         * THE GUARANTEE IS "CLOSED", NOT "finalized".
         *
         * This used to assert the literal word `finalized`. A student's own expiry
         * or submit must never produce `finalized`: that state is now written ONLY by
         * `finalizeSubmission()`, which is a deliberate act by staff handing marking
         * over. Letting a student's submit reach it is what produced submission 12 -
         * a 10-mark written question with no human involvement, a result reading
         * "Marking Complete / Finalized / Not Released", and an Actions column with
         * nothing in it, because `finalized` + `not_ready` is in no queue and admits
         * no action.
         *
         * This paper is one auto-marked MCQ, so there is nothing for a marker to
         * decide and the correct state is `submitted`: closed, with the lecturer, and
         * still requiring the handover gate before any administrator sees it. What the
         * student must never be able to do is reopen the attempt - which is what
         * "not in_progress" guarantees.
         */
        $this->assertDatabaseMissing('online_exam_submissions', [
            'id' => $submission,
            'status' => 'in_progress',
        ]);
        $this->assertDatabaseHas('online_exam_submissions', ['id' => $submission, 'status' => 'submitted']);
    }

    public function test_expired_heartbeat_finalizes_without_granting_more_time(): void
    {
        $student = $this->makeUser(7, 1);
        $exam = $this->makeExam();
        $submission = $this->makeSubmission([
            'online_exam_id' => $exam,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'in_progress',
            'expires_at' => now()->subSecond(),
        ]);

        $response = $this->actingAs($student)->postJson(route('student.online_exam.heartbeat', $submission));
        $response->assertOk()->assertJsonPath('expired', true);
        $this->assertDatabaseMissing('online_exam_submissions', ['id' => $submission, 'status' => 'in_progress']);
    }

    public function test_refresh_resume_uses_the_same_attempt_and_server_expiry(): void
    {
        $student = $this->makeUser(7, 1);
        $exam = $this->makeExam();
        $question = $this->makeQuestion($exam, ['correct_ans' => 'a']);
        $expiresAt = now()->addMinutes(12);
        $submission = $this->makeSubmission([
            'online_exam_id' => $exam,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'in_progress',
            'expires_at' => $expiresAt,
        ]);
        DB::table('online_exam_answers')->insert([
            'submission_id' => $submission,
            'question_id' => $question,
            'selected_option' => 'a',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $first = $this->actingAs($student)->get(route('student.online_exam.take', $exam));
        $second = $this->actingAs($student)->get(route('student.online_exam.take', $exam));
        $first->assertOk()->assertSee('checked', false);
        $second->assertOk()->assertSee('checked', false);
        $this->assertDatabaseCount('online_exam_submissions', 1);
        $this->assertSame($expiresAt->timestamp, DB::table('online_exam_submissions')->where('id', $submission)->value('expires_at') ? strtotime((string) DB::table('online_exam_submissions')->where('id', $submission)->value('expires_at')) : null);
    }

    public function test_duplicate_start_does_not_create_a_second_active_attempt(): void
    {
        $student = $this->makeUser(7, 1);
        $exam = $this->makeExam();
        $this->makeSubmission(['online_exam_id' => $exam, 'student_id' => $student->id, 'school_id' => 1, 'status' => 'in_progress']);

        $this->actingAs($student)->postJson(route('student.online_exam.start', $exam), [])->assertStatus(422);
        $this->assertDatabaseCount('online_exam_submissions', 1);
    }

    public function test_answer_save_returns_server_timestamp_and_enforces_attempt_ownership(): void
    {
        $student = $this->makeUser(7, 1);
        $otherStudent = $this->makeUser(7, 1);
        $exam = $this->makeExam();
        $question = $this->makeQuestion($exam);
        $submission = $this->makeSubmission(['online_exam_id' => $exam, 'student_id' => $student->id, 'school_id' => 1]);

        $this->actingAs($student)->postJson(route('student.online_exam.save_answer', $submission), [
            'answer_revision' => 1,
            'submission_id' => $submission, 'question_id' => $question, 'selected_option' => 'a',
        ])->assertOk()->assertJsonStructure(['answer_updated_at']);

        $this->actingAs($otherStudent)->postJson(route('student.online_exam.save_answer', $submission), [
            'answer_revision' => 1,
            'submission_id' => $submission, 'question_id' => $question, 'selected_option' => 'b',
        ])->assertForbidden();
    }

    public function test_submit_and_timeout_retries_do_not_change_a_finalized_attempt(): void
    {
        $student = $this->makeUser(7, 1);
        $exam = $this->makeExam();
        $question = $this->makeQuestion($exam, ['correct_ans' => 'a']);
        $submission = $this->makeSubmission(['online_exam_id' => $exam, 'student_id' => $student->id, 'school_id' => 1]);
        DB::table('online_exam_answers')->insert(['submission_id' => $submission, 'question_id' => $question, 'selected_option' => 'a', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($student)->post(route('student.online_exam.submit', $exam), ['submission_id' => $submission])->assertRedirect();
        $afterFirstSubmit = DB::table('online_exam_submissions')->where('id', $submission)->first();

        // A RETRIED submit, or the timeout path firing afterwards, must change
        // NOTHING. Asserted as a snapshot comparison rather than a fixed status,
        // because the status itself is now derived from the paper rather than
        // assumed: this is one auto-marked MCQ, so `submitted` is correct, and the
        // property that matters is idempotence.
        $this->actingAs($student)->post(route('student.online_exam.timeout_submit', $submission))->assertRedirect();

        $this->assertEquals(
            $afterFirstSubmit,
            DB::table('online_exam_submissions')->where('id', $submission)->first(),
            'a retried submit or a late timeout must not alter a closed attempt'
        );

        $this->assertDatabaseHas('online_exam_submissions', ['id' => $submission, 'score' => 5]);
    }

    public function test_recovery_contract_is_scoped_and_reconciles_against_server_revision(): void
    {
        $script = file_get_contents(resource_path('views/student/online_exam/take.blade.php'));
        $this->assertStringContainsString('piie.exam.recovery.v1.', $script);
        $this->assertStringContainsString('student_id', $script);
        $this->assertStringContainsString('server_updated_at', $script);
        $this->assertStringContainsString('acknowledged_revision', $script);
        $this->assertStringContainsString('localStorage', $script);
        $this->assertStringContainsString('Another exam tab is open', $script);
    }
}
