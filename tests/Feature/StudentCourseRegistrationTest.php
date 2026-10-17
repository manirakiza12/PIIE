<?php

namespace Tests\Feature;

use App\Models\CourseRegistration;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Covers the new self-service course registration (My Courses) —
 * previously nothing in this app let a student pick specific subjects for
 * a session; Gradebook (marks) assumed a course list existed somewhere
 * else, and Subject's credits/code/course_type columns had no student-
 * facing consumer at all.
 */
class StudentCourseRegistrationTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();

        Schema::create('course_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('subject_id')->index();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('status', 20)->default('registered');
            $table->timestamps();
            $table->unique(['student_id', 'subject_id', 'session_id']);
        });
    }

    private function makeStudent(int $schoolId, string $email): User
    {
        return User::create([
            'name' => 'Course Student', 'email' => $email,
            'password' => bcrypt('secret'), 'code' => student_code(), 'role_id' => 7, 'school_id' => $schoolId,
        ]);
    }

    private function makeSubjectForProgramme(int $schoolId, int $programmeId, array $overrides = []): int
    {
        return (int) DB::table('subjects')->insertGetId(array_merge([
            'name' => 'Research Methods', 'code' => 'RM101', 'credits' => 3, 'course_type' => 'compulsory',
            'pass_mark' => 50, 'programme_id' => $programmeId, 'school_id' => $schoolId,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_student_sees_available_courses_for_their_programme(): void
    {
        $schoolId = $this->makeSchool();
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'MBA']);
        $student = $this->makeStudent($schoolId, 'course.avail@example.com');
        DB::table('student_profiles')->insert([
            'user_id' => $student->id, 'school_id' => $schoolId, 'programme_id' => $programmeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->makeSubjectForProgramme($schoolId, $programmeId, ['name' => 'Strategic Management', 'code' => 'SM201']);

        $response = $this->actingAs($student)->get(route('student.my_courses'));

        $response->assertOk();
        $response->assertSee('Strategic Management');
        $response->assertSee('SM201');
    }

    public function test_registering_a_course_creates_a_registered_row(): void
    {
        $schoolId = $this->makeSchool();
        $programmeId = $this->makeProgramme($schoolId);
        $student = $this->makeStudent($schoolId, 'course.register@example.com');
        DB::table('student_profiles')->insert([
            'user_id' => $student->id, 'school_id' => $schoolId, 'programme_id' => $programmeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $subjectId = $this->makeSubjectForProgramme($schoolId, $programmeId);

        $this->actingAs($student)->post(route('student.my_courses.register'), [
            'subject_ids' => [$subjectId],
        ])->assertRedirect();

        $this->assertDatabaseHas('course_registrations', [
            'student_id' => $student->id,
            'subject_id' => $subjectId,
            'status' => CourseRegistration::STATUS_REGISTERED,
        ]);
    }

    public function test_registering_the_same_course_twice_does_not_duplicate(): void
    {
        $schoolId = $this->makeSchool();
        $programmeId = $this->makeProgramme($schoolId);
        $student = $this->makeStudent($schoolId, 'course.dup@example.com');
        $subjectId = $this->makeSubjectForProgramme($schoolId, $programmeId);

        $this->actingAs($student)->post(route('student.my_courses.register'), ['subject_ids' => [$subjectId]]);
        $this->actingAs($student)->post(route('student.my_courses.register'), ['subject_ids' => [$subjectId]]);

        $this->assertSame(1, CourseRegistration::where('student_id', $student->id)->where('subject_id', $subjectId)->count());
    }

    public function test_confirming_a_course_is_blocked_while_fees_are_outstanding(): void
    {
        $schoolId = $this->makeSchool();
        $programmeId = $this->makeProgramme($schoolId);
        $student = $this->makeStudent($schoolId, 'course.unpaid@example.com');
        $subjectId = $this->makeSubjectForProgramme($schoolId, $programmeId);
        $registration = CourseRegistration::create([
            'student_id' => $student->id, 'subject_id' => $subjectId, 'school_id' => $schoolId,
            'status' => CourseRegistration::STATUS_REGISTERED,
        ]);
        DB::table('student_fee_managers')->insert([
            'title' => 'Tuition', 'total_amount' => 500, 'amount' => 500, 'discounted_price' => 0, 'class_id' => 0,
            'student_id' => $student->id, 'payment_method' => 'unpaid', 'paid_amount' => 99,
            'status' => 'unpaid', 'school_id' => $schoolId, 'session_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($student)->post(route('student.my_courses.confirm', $registration->id));

        $response->assertRedirect();
        $this->assertSame(CourseRegistration::STATUS_REGISTERED, $registration->fresh()->status);
    }

    public function test_confirming_a_course_succeeds_once_fees_are_settled(): void
    {
        $schoolId = $this->makeSchool();
        $programmeId = $this->makeProgramme($schoolId);
        $student = $this->makeStudent($schoolId, 'course.paid@example.com');
        $subjectId = $this->makeSubjectForProgramme($schoolId, $programmeId);
        $registration = CourseRegistration::create([
            'student_id' => $student->id, 'subject_id' => $subjectId, 'school_id' => $schoolId,
            'status' => CourseRegistration::STATUS_REGISTERED,
        ]);
        DB::table('student_fee_managers')->insert([
            'title' => 'Tuition', 'total_amount' => 500, 'amount' => 500, 'discounted_price' => 0, 'class_id' => 0,
            'student_id' => $student->id, 'payment_method' => 'paid', 'paid_amount' => 500,
            'status' => 'paid', 'school_id' => $schoolId, 'session_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($student)->post(route('student.my_courses.confirm', $registration->id))->assertRedirect();

        $this->assertSame(CourseRegistration::STATUS_CONFIRMED, $registration->fresh()->status);
    }

    public function test_dropping_a_course_marks_it_dropped_and_it_disappears_from_the_active_list(): void
    {
        $schoolId = $this->makeSchool();
        $programmeId = $this->makeProgramme($schoolId);
        $student = $this->makeStudent($schoolId, 'course.drop@example.com');
        $subjectId = $this->makeSubjectForProgramme($schoolId, $programmeId, ['name' => 'Ethics']);
        $registration = CourseRegistration::create([
            'student_id' => $student->id, 'subject_id' => $subjectId, 'school_id' => $schoolId,
            'status' => CourseRegistration::STATUS_REGISTERED,
        ]);

        $this->actingAs($student)->post(route('student.my_courses.drop', $registration->id))->assertRedirect();

        $this->assertSame(CourseRegistration::STATUS_DROPPED, $registration->fresh()->status);
        $response = $this->actingAs($student)->get(route('student.my_courses'));
        $response->assertDontSee('Ethics');
    }

    public function test_a_student_cannot_confirm_or_drop_another_students_registration(): void
    {
        $schoolId = $this->makeSchool();
        $programmeId = $this->makeProgramme($schoolId);
        $studentA = $this->makeStudent($schoolId, 'course.a@example.com');
        $studentB = $this->makeStudent($schoolId, 'course.b@example.com');
        $subjectId = $this->makeSubjectForProgramme($schoolId, $programmeId);
        $registration = CourseRegistration::create([
            'student_id' => $studentB->id, 'subject_id' => $subjectId, 'school_id' => $schoolId,
            'status' => CourseRegistration::STATUS_REGISTERED,
        ]);

        $this->actingAs($studentA)->post(route('student.my_courses.confirm', $registration->id))->assertStatus(404);
        $this->actingAs($studentA)->post(route('student.my_courses.drop', $registration->id))->assertStatus(404);
    }
}
