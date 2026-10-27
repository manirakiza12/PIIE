<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

class OnlineExamControllerSecurityTest extends TestCase
{
    use OnlineExamTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSecondaryOnlineExamTestSchema();
    }

    public function test_student_cannot_inject_score_or_passed_fields_on_submit(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
        ]);

        $submissionId = $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($student)
            ->postJson(route('student.online_exam.submit', $examId), [
                'submission_id' => $submissionId,
                'score' => 999,
                'passed' => true,
            ]);

        $response->assertStatus(422);

        $row = DB::table('online_exam_submissions')->where('id', $submissionId)->first();
        $this->assertNull($row->score);
        $this->assertNull($row->passed);
    }

    public function test_student_cannot_save_answer_after_expiry(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
        ]);

        $questionId = $this->makeQuestion($examId, ['correct_ans' => 'SECRETANSWER']);

        $submissionId = $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'in_progress',
            'expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($student)
            ->postJson(route('student.online_exam.save_answer', $submissionId), [
                'answer_revision' => 1,
                'submission_id' => $submissionId,
                'question_id' => $questionId,
                'selected_option' => 'A',
            ])
            ->assertStatus(422);
    }

    public function test_question_must_belong_to_submission_exam(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);
        $this->enrollStudent($student->id, 1, $classId);

        $examA = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
        ]);
        $examB = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
        ]);

        $questionFromExamB = $this->makeQuestion($examB);

        $submissionId = $this->makeSubmission([
            'online_exam_id' => $examA,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'in_progress',
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($student)
            ->postJson(route('student.online_exam.save_answer', $submissionId), [
                'answer_revision' => 1,
                'submission_id' => $submissionId,
                'question_id' => $questionFromExamB,
                'selected_option' => 'A',
            ])
            ->assertStatus(422);
    }

    public function test_take_and_save_responses_do_not_expose_correct_answer(): void
    {
        $student = $this->makeUser(7, 1);
        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
        ]);

        $questionId = $this->makeQuestion($examId, [
            'correct_ans' => 'SECRETANSWER',
            'option_a' => 'SECRETANSWER',
            'option_b' => 'OTHER',
        ]);

        $takeResponse = $this->actingAs($student)->get(route('student.online_exam.take', $examId));
        $takeResponse->assertRedirect(route('student.online_exam.instructions', $examId));
        $takeResponse->assertDontSee('correct_ans', false);

        $submissionId = $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'in_progress',
            'expires_at' => now()->addHour(),
        ]);

        $saveResponse = $this->actingAs($student)
            ->postJson(route('student.online_exam.save_answer', $submissionId), [
                'answer_revision' => 1,
                'submission_id' => $submissionId,
                'question_id' => $questionId,
                'selected_option' => 'A',
            ]);

        $saveResponse->assertOk();
        $saveResponse->assertJsonMissing(['correct_ans' => 'SECRETANSWER']);
    }
}
