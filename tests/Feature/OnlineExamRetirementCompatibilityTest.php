<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use App\Models\OnlineExamSubmission;
use App\Support\CourseExams\CourseOfferingExamAccess;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

class OnlineExamRetirementCompatibilityTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();
    }

    private function legacyAttempt(array $overrides = []): array
    {
        $student = $this->makeUser(7, 1);
        $teacher = $this->makeUser(3, 1);
        $class = $this->makeClass(1);
        $this->enrollStudent($student->id, 1, $class);
        $exam = $this->makeExam(['class_id' => $class, 'subject_id' => $this->makeSubject(1, $class),
            'created_by' => $teacher->id, 'creator_id' => $teacher->id, 'title' => 'Historical class paper']);
        $question = $this->makeQuestion($exam);
        $submission = $this->makeSubmission($overrides + ['online_exam_id' => $exam, 'student_id' => $student->id]);

        return [$student, $teacher, $exam, $question, $submission];
    }

    public function test_existing_tertiary_attempt_can_resume_save_and_finish_without_class_membership(): void
    {
        [$student, , $exam, $question, $submission] = $this->legacyAttempt();
        $expiry = DB::table('online_exam_submissions')->where('id', $submission)->value('expires_at');
        DB::table('enrollment')->where('user_id', $student->id)->delete();
        $this->actingAs($student)->get(route('student.online_exam.instructions', $exam))
            ->assertRedirect(route('student.online_exam.take', $exam));
        $this->get(route('student.online_exam.take', $exam))->assertOk();
        $this->postJson(route('student.online_exam.save_answer', $submission), [
            'submission_id' => $submission, 'question_id' => $question, 'selected_option' => 'a', 'answer_revision' => 1,
        ])->assertOk();
        $this->assertDatabaseHas('online_exam_answers', ['submission_id' => $submission, 'question_id' => $question, 'selected_option' => 'a']);
        $this->post(route('student.online_exam.submit', $exam), ['submission_id' => $submission])->assertRedirect();
        $this->assertNotSame('in_progress', OnlineExamSubmission::findOrFail($submission)->status);
        $this->assertSame($expiry, DB::table('online_exam_submissions')->where('id', $submission)->value('expires_at'));
        $this->assertSame(1, DB::table('online_exam_submissions')->where('online_exam_id', $exam)->count());
    }

    public function test_expired_legacy_attempt_is_finalized_without_extending_its_deadline(): void
    {
        [$student, , $exam, , $submission] = $this->legacyAttempt(['started_at' => now()->subHour(), 'expires_at' => now()->subMinute()]);
        $this->actingAs($student)->get(route('student.online_exam.take', $exam))->assertRedirect();
        $this->assertNotSame('in_progress', OnlineExamSubmission::findOrFail($submission)->status);
        $this->assertTrue(OnlineExamSubmission::findOrFail($submission)->expires_at->isPast());
    }

    public function test_historical_legacy_attempt_remains_listed_after_enrollment_is_removed(): void
    {
        [$student, , $exam, , $submission] = $this->legacyAttempt(['status' => 'finalized', 'result_review_state' => 'not_ready', 'submitted_at' => now()]);
        DB::table('enrollment')->where('user_id', $student->id)->delete();
        $this->actingAs($student)->get(route('student.online_exam.list'))->assertOk()->assertSee('Historical class paper');
        $this->get(route('student.online_exam.result', $submission))->assertOk()
            ->assertSee('Your result is being processed.')->assertDontSee('Score:');
        $this->assertTrue(app(CourseOfferingExamAccess::class)->studentMaySeeExam($student, OnlineExam::findOrFail($exam)));
    }

    public function test_retirement_does_not_admit_new_candidates_or_allow_an_extra_legacy_attempt(): void
    {
        [$student, , $exam, , $submission] = $this->legacyAttempt(['status' => 'submitted', 'submitted_at' => now()]);
        DB::table('online_exams')->where('id', $exam)->update(['max_attempts' => 3]);
        $this->actingAs($student)->postJson(route('student.online_exam.start', $exam), ['instructions_acknowledged' => 'on'])->assertStatus(410);
        $newStudent = $this->makeUser(7, 1);
        $this->enrollStudent($newStudent->id, 1, OnlineExam::findOrFail($exam)->class_id);
        $this->actingAs($newStudent)->get(route('student.online_exam.instructions', $exam))->assertNotFound();
        $this->postJson(route('student.online_exam.start', $exam), ['instructions_acknowledged' => 'on'])->assertNotFound();
        $this->assertSame(1, DB::table('online_exam_submissions')->where('online_exam_id', $exam)->count());
    }

    public function test_another_students_or_tenants_submission_never_grants_legacy_access(): void
    {
        [, , $exam, , $submission] = $this->legacyAttempt();
        $stranger = $this->makeUser(7, 1);
        $this->actingAs($stranger)->get(route('student.online_exam.take', $exam))->assertNotFound();
        $this->get(route('student.online_exam.result', $submission))->assertNotFound();
        // A corrupt cross-tenant row still cannot satisfy the history exception.
        $this->makeSubmission(['student_id' => $stranger->id, 'online_exam_id' => $exam, 'school_id' => 2]);
        $this->get(route('student.online_exam.take', $exam))->assertNotFound();
    }

    public function test_published_historical_results_remain_available_only_to_their_owner(): void
    {
        [$student, , , , $submission] = $this->legacyAttempt(['status' => 'result_published', 'result_review_state' => 'published',
            'submitted_at' => now(), 'published_at' => now(), 'score' => 5, 'total_marks_snapshot' => 10]);
        DB::table('enrollment')->where('user_id', $student->id)->delete();
        $this->actingAs($student)->get(route('student.online_exam.result', $submission))->assertOk()
            ->assertViewIs('student.online_exam.result')
            ->assertViewHas('submission', fn ($row) => (float) $row->score === 5.0);
        $this->actingAs($this->makeUser(7, 1))->get(route('student.online_exam.result', $submission))->assertNotFound();
    }

    public function test_retired_class_target_cannot_be_cleared_to_turn_a_historical_paper_into_school_wide_delivery(): void
    {
        [, $teacher, $exam] = $this->legacyAttempt();
        $before = OnlineExam::findOrFail($exam)->getAttributes();
        $this->actingAs($teacher)->put(route('teacher.online_exams.update', $exam), [
            'title' => 'Changed historical paper', 'subject_id' => $before['subject_id'], 'class_id' => null,
            'exam_type' => 'quiz', 'start_datetime' => now()->subHour()->toDateTimeString(),
            'end_datetime' => now()->addHour()->toDateTimeString(), 'duration_mins' => 30,
            'total_marks' => 10, 'pass_mark' => 5, 'max_attempts' => 3, 'result_release_policy' => 'immediate',
        ])->assertRedirect()->assertSessionHasErrors('exam');
        $this->assertSame($before, OnlineExam::findOrFail($exam)->getAttributes());
    }

    public function test_new_tertiary_class_targeting_is_rejected_for_both_admin_and_teacher(): void
    {
        $class = $this->makeClass(1);
        $subject = $this->makeSubject(1, $class);
        foreach ([2 => 'admin', 3 => 'teacher'] as $role => $prefix) {
            $this->actingAs($this->makeUser($role, 1))->post(route($prefix.'.online_exams.store'), [
                'title' => 'Forbidden new class paper', 'subject_id' => $subject, 'class_id' => $class,
                'exam_type' => 'quiz', 'start_datetime' => now()->addHour()->toDateTimeString(),
                'end_datetime' => now()->addHours(2)->toDateTimeString(), 'duration_mins' => 30,
                'total_marks' => 10, 'pass_mark' => 5, 'max_attempts' => 1, 'result_release_policy' => 'immediate',
            ])->assertRedirect()->assertSessionHasErrors('class_id');
        }
        $this->assertSame(0, DB::table('online_exams')->count());
    }

    public function test_history_exception_never_bypasses_course_registration(): void
    {
        $student = $this->makeUser(7, 1);
        $offering = $this->makeOffering();
        $exam = $this->makeExam(['course_offering_id' => $offering->id]);
        $this->makeSubmission(['student_id' => $student->id, 'online_exam_id' => $exam]);
        $this->actingAs($student)->get(route('student.online_exam.take', $exam))->assertNotFound();
        $this->confirmStudent($offering, $student);
        $this->get(route('student.online_exam.take', $exam))->assertOk();
        DB::table('course_registrations')->update(['status' => 'cancelled']);
        $this->get(route('student.online_exam.take', $exam))->assertNotFound();
    }

    public function test_class_history_exception_does_not_admit_an_ineligible_programme_candidate(): void
    {
        $student = $this->makeUser(7, 1);
        $exam = $this->makeExam(['programme_id' => 99, 'max_attempts' => 3]);
        $this->makeSubmission(['student_id' => $student->id, 'online_exam_id' => $exam, 'status' => 'submitted']);
        $this->actingAs($student)->get(route('student.online_exam.take', $exam))->assertNotFound();
        $this->postJson(route('student.online_exam.start', $exam), ['instructions_acknowledged' => 'on'])->assertNotFound();
        $this->assertSame(1, DB::table('online_exam_submissions')->where('online_exam_id', $exam)->count());
    }

    public function test_authorized_staff_retain_legacy_history_and_marking_without_admitting_unassigned_staff(): void
    {
        [, $teacher, $exam] = $this->legacyAttempt(['status' => 'submitted']);
        $this->actingAs($teacher)->get(route('teacher.online_exams.index'))->assertOk()->assertSee('Historical class paper');
        $this->get(route('teacher.online_exams.attempts', $exam))->assertOk();
        $stranger = $this->makeUser(3, 1);
        $this->actingAs($stranger)->get(route('teacher.online_exams.index'))->assertOk()->assertDontSee('Historical class paper');
        $this->get(route('teacher.online_exams.attempts', $exam))->assertForbidden();
        $foreign = $this->makeUser(3, 2);
        $this->actingAs($foreign)->get(route('teacher.online_exams.attempts', $exam))->assertForbidden();
    }

    public function test_assigned_historical_marker_keeps_access_to_existing_answers(): void
    {
        [$student, , $exam, $question, $submission] = $this->legacyAttempt(['status' => 'submitted', 'submitted_at' => now()]);
        DB::table('online_exam_questions')->where('id', $question)->update(['type' => 'essay', 'correct_ans' => null]);
        $answer = DB::table('online_exam_answers')->insertGetId([
            'submission_id' => $submission, 'question_id' => $question, 'answer_text' => 'Preserved historical response',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $marker = $this->makeUser(3, 1);
        DB::table('teacher_permissions')->insert(['school_id' => 1, 'teacher_id' => $marker->id,
            'class_id' => OnlineExam::findOrFail($exam)->class_id, 'marks' => 1]);
        $this->actingAs($marker)->get(route('teacher.online_exams.attempts', $exam))->assertOk();
        $this->get(route('teacher.online_exams.marking'))->assertOk()
            ->assertViewHas('answers', fn ($answers) => $answers->contains('id', $answer));
        $this->post(route('teacher.online_exams.answers.mark', $answer), ['answer_id' => $answer, 'awarded_marks' => 3])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('online_exam_answers', ['id' => $answer, 'awarded_marks' => 3]);
        $this->actingAs($student)->get(route('student.online_exam.result', $submission))->assertOk()
            ->assertViewIs('student.online_exam.submitted');
    }

    public function test_tertiary_form_requires_allocation_while_secondary_form_retains_legacy_fields(): void
    {
        $teacher = $this->makeUser(3, 1);
        $this->actingAs($teacher)->get(route('teacher.online_exams.create'))->assertOk()
            ->assertSee('No Course Allocations are assigned to you');
        $this->allocateLecturer($this->makeOffering(), $teacher);
        $this->get(route('teacher.online_exams.create'))->assertOk()
            ->assertSee('name="course_offering_id"', false)->assertSee('Select Course Allocation')
            ->assertDontSee('name="class_id"', false);
        $this->useSecondarySchool();
        $this->actingAs($this->makeUser(3, 1));
        $this->get(route('teacher.online_exams.index'))->assertOk()->assertSee('/teacher/gradebook', false);
        $this->get(route('teacher.online_exams.create'))->assertOk()->assertSee('name="class_id"', false)
            ->assertSee('No subjects are assigned to this teacher');
    }

    public function test_tertiary_shared_bank_subjects_come_from_lecturer_allocations(): void
    {
        $teacher = $this->makeUser(3, 1);
        $admin = $this->makeUser(2, 1);
        $offering = $this->makeOffering();
        $allocation = $this->allocateLecturer($offering, $teacher);
        DB::table('question_banks')->insert(['school_id' => 1, 'subject_id' => $offering->subject_id,
            'question' => 'Allocated shared bank question', 'type' => 'essay', 'marks' => 5, 'created_by' => $admin->id]);
        $this->actingAs($teacher)->get(route('teacher.online_exams.question_bank'))->assertOk()->assertSee('Allocated shared bank question');
        DB::table('course_offering_lecturer_allocations')->where('id', $allocation)->update(['status' => 'cancelled']);
        $this->get(route('teacher.online_exams.question_bank'))->assertOk()->assertDontSee('Allocated shared bank question');
    }
}
