<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

class OnlineExamAuthorizationTest extends TestCase
{
    use OnlineExamTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSecondaryOnlineExamTestSchema();
    }

    public function test_admin_cannot_access_another_school_exam(): void
    {
        $admin = $this->makeUser(2, 1);

        $class2 = $this->makeClass(2);
        $subject2 = $this->makeSubject(2, $class2);
        $examInSchool2 = $this->makeExam([
            'school_id' => 2,
            'class_id' => $class2,
            'subject_id' => $subject2,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.online_exams.questions', $examInSchool2));

        $response->assertStatus(404);
    }

    public function test_policy_update_blocks_locked_exam_changes(): void
    {
        $admin = $this->makeUser(2, 1);
        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);
        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'created_by' => $admin->id,
            'creator_id' => $admin->id,
            'updater_id' => $admin->id,
        ]);

        $student = $this->makeUser(7, 1);
        $this->enrollStudent($student->id, 1, $classId);
        $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($admin)->from('/admin/online-exams')
            ->post(route('admin.online_exams.update', $examId), [
                'title' => 'Updated',
                'subject_id' => $subjectId,
                'class_id' => $classId,
                'exam_type' => 'quiz',
                'start_datetime' => now()->subHour()->toDateTimeString(),
                'end_datetime' => now()->addHour()->toDateTimeString(),
                'duration_mins' => 30,
                'total_marks' => 25,
                'pass_mark' => 10,
                'max_attempts' => 1,
                'result_release_policy' => 'immediate',
            ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['total_marks']);
    }

    public function test_unauthorized_staff_cannot_review_proctoring_events(): void
    {
        $admin = $this->makeUser(2, 1);
        $unauthorizedStaff = $this->makeUser(4, 1);
        $student = $this->makeUser(7, 1);

        $classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $classId);
        $this->enrollStudent($student->id, 1, $classId);

        $examId = $this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'created_by' => $admin->id,
            'creator_id' => $admin->id,
            'updater_id' => $admin->id,
        ]);

        $submissionId = $this->makeSubmission([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'in_progress',
        ]);

        DB::table('online_exam_proctoring_events')->insert([
            'submission_id' => $submissionId,
            'event_type' => 'tab_hidden',
            'event_time' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($unauthorizedStaff)
            ->get(route('admin.online_exams.proctoring.review', ['id' => $examId, 'submission' => $submissionId]));

        $response->assertStatus(403);
    }

    public function test_existing_admin_and_student_routes_still_resolve(): void
    {
        $this->assertTrue(Route::has('admin.online_exams.index'));
        $this->assertTrue(Route::has('student.online_exam.list'));
        $this->assertTrue(Route::has('student.online_exam.take'));
        $this->assertTrue(Route::has('student.online_exam.result'));
    }
}
