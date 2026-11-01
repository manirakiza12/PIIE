<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use App\Models\OnlineExamSnapshot;
use App\Models\OnlineExamSnapshotRequest;
use App\Models\OnlineExamSubmission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * FOCUSED PROCTORING SNAPSHOT TESTS.
 */
class OnlineExamSnapshotProctoringTest extends TestCase
{
    use OnlineExamTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();
        Carbon::setTestNow(null);
        Config::set('app.timezone', 'UTC');
    }

    private function makeTeacherWithPermission(int $schoolId = 1): User
    {
        $teacher = $this->makeUser(3, $schoolId);

        DB::table('teacher_permissions')->insert([
            'class_id' => $this->makeClass($schoolId),
            'section_id' => 1,
            'school_id' => $schoolId,
            'teacher_id' => $teacher->id,
            'marks' => 1,
            'attendance' => 1,
            'updated_at' => now(),
        ]);

        return $teacher;
    }

    private function makeExamAndSubmission(array $examOverrides = [], array $submissionOverrides = []): array
    {
        $classId = 1;
        $subjectId = $this->makeSubject(1, $classId);

        $examId = (int) $this->makeExam(array_merge([
            'school_id' => 1,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'workflow_state' => 'published',
            'is_published' => 1,
            'webcam_required' => 1,
            'start_datetime' => now()->subMinutes(5),
            'end_datetime' => now()->addMinutes(25),
        ], $examOverrides));

        $student = $this->makeUser(7, 1);
        $submissionId = (int) $this->makeSubmission(array_merge([
            'online_exam_id' => $examId,
            'student_id' => $student->id,
            'school_id' => 1,
            'status' => 'in_progress',
        ], $submissionOverrides));

        return [$examId, $submissionId, $student];
    }

    private function validBase64Image(): string
    {
        $imageData = base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA');
        return 'data:image/webp;base64,' . base64_encode($imageData);
    }

    private function invalidBase64Image(): string
    {
        return 'data:image/webp;base64,not-valid-base64!!!';
    }

    private function oversizedBase64Image(): string
    {
        $largeData = str_repeat('A', 6 * 1024 * 1024);
        return 'data:image/webp;base64,' . base64_encode($largeData);
    }

    /** @test */
    public function valid_snapshot_upload_creates_one_record_event_and_image(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        $this->actingAs($student);

        $response = $this->postJson(route('student.online_exam.snapshot', $submissionId), [
            'submission_id' => $submissionId,
            'image' => $this->validBase64Image(),
            'capture_type' => 'automatic',
            'metadata' => ['timestamp' => now()->toIso8601String()],
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'success']);

        $this->assertDatabaseCount('online_exam_snapshots', 1);
        $snapshot = OnlineExamSnapshot::first();
        $this->assertNotNull($snapshot);
        $this->assertSame($submissionId, (int) $snapshot->submission_id);
        $this->assertSame('automatic', $snapshot->capture_type);

        $this->assertDatabaseHas('online_exam_proctoring_events', [
            'submission_id' => $submissionId,
            'event_type' => 'snapshot_captured',
        ]);
    }

    /** @test */
    public function invalid_image_is_rejected(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        $this->actingAs($student);

        $response = $this->postJson(route('student.online_exam.snapshot', $submissionId), [
            'submission_id' => $submissionId,
            'image' => $this->invalidBase64Image(),
            'capture_type' => 'automatic',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('online_exam_snapshots', 0);
    }

    /** @test */
    public function oversized_payload_is_rejected(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        $this->actingAs($student);

        $response = $this->postJson(route('student.online_exam.snapshot', $submissionId), [
            'submission_id' => $submissionId,
            'image' => $this->oversizedBase64Image(),
            'capture_type' => 'automatic',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('online_exam_snapshots', 0);
    }

    /** @test */
    public function unauthorized_user_cannot_upload_snapshot(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        $otherStudent = $this->makeUser(7, 1);
        $this->actingAs($otherStudent);

        $response = $this->postJson(route('student.online_exam.snapshot', $submissionId), [
            'submission_id' => $submissionId,
            'image' => $this->validBase64Image(),
            'capture_type' => 'automatic',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('online_exam_snapshots', 0);
    }

    /** @test */
    public function cross_school_access_is_denied(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        $otherSchoolStudent = $this->makeUser(7, 2);
        $this->actingAs($otherSchoolStudent);

        $response = $this->postJson(route('student.online_exam.snapshot', $submissionId), [
            'submission_id' => $submissionId,
            'image' => $this->validBase64Image(),
            'capture_type' => 'automatic',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('online_exam_snapshots', 0);
    }

    /** @test */
    public function invigilator_request_matches_correct_request_id(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        $teacher = $this->makeTeacherWithPermission(1);
        $request = OnlineExamSnapshotRequest::create([
            'submission_id' => $submissionId,
            'requested_by' => $teacher->id,
            'status' => 'pending',
            'requested_at' => now(),
            'expires_at' => now()->addMinutes(2),
            'metadata' => ['requested_by_name' => $teacher->name],
        ]);

        $this->actingAs($student);

        $response = $this->postJson(route('student.online_exam.snapshot', $submissionId), [
            'submission_id' => $submissionId,
            'image' => $this->validBase64Image(),
            'capture_type' => 'invigilator_requested',
            'request_id' => $request->id,
        ]);

        $response->assertOk();
        $response->assertJson(['request_completed' => true]);

        $request->refresh();
        $this->assertSame('completed', $request->status);
        $this->assertNotNull($request->completed_at);
    }

    /** @test */
    public function expired_request_is_rejected(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        $teacher = $this->makeTeacherWithPermission(1);
        $request = OnlineExamSnapshotRequest::create([
            'submission_id' => $submissionId,
            'requested_by' => $teacher->id,
            'status' => 'pending',
            'requested_at' => now()->subMinutes(5),
            'expires_at' => now()->subMinutes(3),
            'metadata' => ['requested_by_name' => $teacher->name],
        ]);

        $this->actingAs($student);

        $response = $this->postJson(route('student.online_exam.snapshot', $submissionId), [
            'submission_id' => $submissionId,
            'image' => $this->validBase64Image(),
            'capture_type' => 'invigilator_requested',
            'request_id' => $request->id,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function replayed_request_is_rejected(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        $teacher = $this->makeTeacherWithPermission(1);
        $request = OnlineExamSnapshotRequest::create([
            'submission_id' => $submissionId,
            'requested_by' => $teacher->id,
            'status' => 'completed',
            'requested_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
            'expires_at' => now()->addMinutes(2),
            'metadata' => ['requested_by_name' => $teacher->name, 'snapshot_id' => 1],
        ]);

        $this->actingAs($student);

        $response = $this->postJson(route('student.online_exam.snapshot', $submissionId), [
            'submission_id' => $submissionId,
            'image' => $this->validBase64Image(),
            'capture_type' => 'invigilator_requested',
            'request_id' => $request->id,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function duplicate_pending_request_is_prevented(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        DB::table('global_settings')->updateOrInsert(
            ['key' => 'role_perm_3'],
            ['value' => json_encode([
                'view_online_exams',
                'create_online_exams',
                'edit_own_online_exams',
                'manage_exam_questions',
                'view_exam_attempts',
                'review_exam_proctoring',
            ]), 'updated_at' => now(), 'created_at' => now()]
        );

$teacher = $this->makeTeacherWithPermission(1);

        // Ensure the teacher owns the exam for authorization
        DB::table('online_exams')->where('id', $examId)->update([
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
        ]);

        $this->actingAs($teacher);

        $response1 = $this->postJson(route('teacher.online_exams.proctoring.request_snapshot', [
            'exam' => $examId,
            'submission_id' => $submissionId,
        ]));
        $response1->assertOk();

        $response2 = $this->postJson(route('teacher.online_exams.proctoring.request_snapshot', [
            'exam' => $examId,
            'submission_id' => $submissionId,
        ]));
        $response2->assertStatus(422);
        $response2->assertJson(['status' => 'error']);
    }

    /** @test */
    public function snapshot_capture_requires_webcam_required_exam(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission(['webcam_required' => 0]);

        $this->actingAs($student);

        $response = $this->postJson(route('student.online_exam.snapshot', $submissionId), [
            'submission_id' => $submissionId,
            'image' => $this->validBase64Image(),
            'capture_type' => 'automatic',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('online_exam_snapshots', 0);
    }

    /** @test */
    public function snapshot_capture_requires_in_progress_submission(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        DB::table('online_exam_submissions')->where('id', $submissionId)->update(['status' => 'submitted']);

        $this->actingAs($student);

        $response = $this->postJson(route('student.online_exam.snapshot', $submissionId), [
            'submission_id' => $submissionId,
            'image' => $this->validBase64Image(),
            'capture_type' => 'automatic',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('online_exam_snapshots', 0);
    }

    /** @test */
    public function invigilator_cannot_request_snapshot_for_non_in_progress_submission(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        DB::table('online_exam_submissions')->where('id', $submissionId)->update(['status' => 'submitted']);

        // Set up teacher permissions
        DB::table('global_settings')->updateOrInsert(
            ['key' => 'role_perm_3'],
            ['value' => json_encode([
                'view_online_exams',
                'create_online_exams',
                'edit_own_online_exams',
                'manage_exam_questions',
                'view_exam_attempts',
                'review_exam_proctoring',
            ]), 'updated_at' => now(), 'created_at' => now()]
        );

$teacher = $this->makeTeacherWithPermission(1);

        // Ensure the teacher owns the exam for authorization
        DB::table('online_exams')->where('id', $examId)->update([
            'created_by' => $teacher->id,
            'creator_id' => $teacher->id,
        ]);

        $this->actingAs($teacher);

        $response = $this->postJson(route('teacher.online_exams.proctoring.request_snapshot', [
            'exam' => $examId,
            'submission_id' => $submissionId,
        ]));

        $response->assertStatus(422);
    }

    /** @test */
    public function image_access_denied_for_unauthorized_staff(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        \Storage::disk('local')->put('snapshots/' . $submissionId . '/test.webp', 'fake-image-data');

        $snapshot = OnlineExamSnapshot::create([
            'submission_id' => $submissionId,
            'disk' => 'local',
            'path' => 'snapshots/' . $submissionId . '/test.webp',
            'size_bytes' => 15,
            'mime_type' => 'image/webp',
            'capture_type' => 'automatic',
            'captured_at' => now(),
        ]);

        $unauthorizedTeacher = $this->makeUser(3, 1);
        $this->actingAs($unauthorizedTeacher);

        $response = $this->get(route('teacher.online_exams.snapshot_image', [
            'submission' => $submissionId,
            'snapshot' => $snapshot->id,
        ]));

        $response->assertStatus(403);
    }

    /** @test */
    public function image_access_allowed_for_student_owner(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        \Storage::disk('local')->put('snapshots/' . $submissionId . '/test.webp', 'fake-image-data');

        $snapshot = OnlineExamSnapshot::create([
            'submission_id' => $submissionId,
            'disk' => 'local',
            'path' => 'snapshots/' . $submissionId . '/test.webp',
            'size_bytes' => 15,
            'mime_type' => 'image/webp',
            'capture_type' => 'automatic',
            'captured_at' => now(),
        ]);

        $this->actingAs($student);

        $response = $this->get(route('student.online_exam.snapshot_image', [
            'submission' => $submissionId,
            'snapshot' => $snapshot->id,
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
    }

    /** @test */
    public function image_access_denied_cross_school(): void
    {
        [$examId, $submissionId, $student] = $this->makeExamAndSubmission();

        \Storage::disk('local')->put('snapshots/' . $submissionId . '/test.webp', 'fake-image-data');

        $snapshot = OnlineExamSnapshot::create([
            'submission_id' => $submissionId,
            'disk' => 'local',
            'path' => 'snapshots/' . $submissionId . '/test.webp',
            'size_bytes' => 15,
            'mime_type' => 'image/webp',
            'capture_type' => 'automatic',
            'captured_at' => now(),
        ]);

        // Create student in different school
        $otherSchoolStudent = $this->makeUser(7, 2);
        $this->actingAs($otherSchoolStudent);

        $response = $this->get(route('student.online_exam.snapshot_image', [
            'submission' => $submissionId,
            'snapshot' => $snapshot->id,
        ]));

        $response->assertStatus(403);
    }
}
