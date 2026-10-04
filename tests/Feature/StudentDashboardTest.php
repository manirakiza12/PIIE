<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * Covers the rebuilt student dashboard (StudentController::studentDashboard()).
 * The previous version showed school-wide "Total Teacher"/"Total Parent"/
 * "Total Staff" counts — generic trivia unrelated to the logged-in student —
 * plus a class-wise attendance chart silently querying the empty, unused
 * `enrollments` (plural) table instead of the real `enrollment` (singular)
 * one. This version is personalized: the student's own class/teachers, fee
 * balance, exam counts, attendance, next live class, and recent grades,
 * each sourced from the same tables every other module already trusts.
 */
class StudentDashboardTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->bootDashboardExtraSchema();
    }

    /**
     * addons/hostel_applications/hostel_fees/teacher_permissions moved into
     * the shared AdmissionsTestHelper (needed by more than just this file
     * now — see NotificationCenterTest) — only live_classes/online_exams
     * remain here, still unique to this file's dashboard widget coverage.
     */
    private function bootDashboardExtraSchema(): void
    {
        Schema::create('live_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('class_id')->nullable();
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->unsignedBigInteger('academic_session_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->string('platform')->default('jitsi');
            $table->string('meeting_url', 500)->nullable();
            $table->string('meeting_id', 150)->nullable();
            $table->string('meeting_password', 150)->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->date('start_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('status')->default('draft');
            $table->boolean('is_published')->default(false);
            $table->boolean('attendance_enabled')->default(false);
            $table->string('recording_url', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('started_at')->nullable();
                $table->dateTime('ended_at')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->unsignedBigInteger('started_by')->nullable();
                $table->unsignedBigInteger('ended_by')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->string('recording_status', 20)->default('none');
$table->timestamps();
        });

        Schema::create('online_exams', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('title');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('class_id')->nullable();
            $table->string('exam_type')->default('cat');
            $table->dateTime('start_datetime')->nullable();
            $table->dateTime('end_datetime')->nullable();
            $table->integer('duration_mins')->default(60);
            $table->integer('total_marks')->default(100);
            $table->integer('pass_mark')->default(50);
            $table->unsignedTinyInteger('max_attempts')->default(1);
            $table->text('instructions')->nullable();
            $table->tinyInteger('is_published')->default(0);
            $table->tinyInteger('auto_submit')->default(1);
            $table->string('workflow_state', 30)->default('draft')->index();
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->boolean('allow_previous_navigation')->default(true);
            $table->string('result_release_policy', 30)->default('immediate');
            $table->boolean('webcam_required')->default(false);
            $table->boolean('fullscreen_required')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->unsignedBigInteger('updater_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });

        // Needed by the dashboard's "My Courses" widget (added alongside
        // the sidebar/dashboard reorder to match the reference HEI portal
        // layout) — same shape as StudentCourseRegistrationTest's table.
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
            'name' => 'Dashboard Student', 'email' => $email,
            'password' => bcrypt('secret'), 'code' => student_code(), 'role_id' => 7, 'school_id' => $schoolId,
        ]);
    }

    public function test_class_track_student_sees_their_own_teachers_fees_exams_and_attendance(): void
    {
        $schoolId = $this->makeSchool();
        $classId = $this->makeClass($schoolId, ['name' => 'Grade 10']);
        $sectionId = (int) DB::table('sections')->insertGetId([
            'name' => 'A', 'class_id' => $classId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = $this->makeStudent($schoolId, 'class.dash@example.com');
        DB::table('enrollment')->insert([
            'user_id' => $student->id, 'class_id' => $classId, 'section_id' => $sectionId,
            'school_id' => $schoolId, 'department_id' => 0, 'session_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $teacher = User::create([
            'name' => 'Ms Teacher', 'email' => 'ms.teacher@example.com',
            'password' => bcrypt('secret'), 'code' => 'T1', 'role_id' => 3, 'school_id' => $schoolId,
        ]);
        DB::table('teacher_permissions')->insert([
            'class_id' => $classId, 'section_id' => $sectionId, 'school_id' => $schoolId,
            'teacher_id' => $teacher->id, 'marks' => 1, 'attendance' => 1, 'updated_at' => now(),
        ]);

        DB::table('student_fee_managers')->insert([
            'title' => 'Tuition', 'total_amount' => 500, 'amount' => 500, 'discounted_price' => 0,
            'class_id' => $classId, 'student_id' => $student->id, 'payment_method' => 'unpaid',
            'paid_amount' => 100, 'status' => 'unpaid', 'school_id' => $schoolId, 'session_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('online_exams')->insert([
            'school_id' => $schoolId, 'title' => 'Live Exam', 'class_id' => $classId,
            'workflow_state' => 'published', 'start_datetime' => now()->subMinutes(10),
            'end_datetime' => now()->addMinutes(50), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('daily_attendances')->insert([
            'class_id' => $classId, 'section_id' => $sectionId, 'student_id' => $student->id,
            'status' => 1, 'session_id' => 1, 'school_id' => $schoolId,
            'timestamp' => strtotime('today'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($student)->get(route('student.dashboard'));

        $response->assertOk();
        $response->assertSee('Grade 10');
        $response->assertSee('Ms Teacher');
        $response->assertSee('400'); // fee balance: 500 - 100
    }

    public function test_programme_track_student_with_no_class_sees_programme_and_year_instead(): void
    {
        $schoolId = $this->makeSchool();
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'Bachelor of IT']);
        $student = $this->makeStudent($schoolId, 'programme.dash@example.com');
        DB::table('student_profiles')->insert([
            'user_id' => $student->id, 'school_id' => $schoolId, 'programme_id' => $programmeId,
            'year_of_study' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('enrollment')->insert([
            'user_id' => $student->id, 'class_id' => 0, 'section_id' => 0,
            'school_id' => $schoolId, 'department_id' => 0, 'session_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($student)->get(route('student.dashboard'));

        $response->assertOk();
        $response->assertSee('Bachelor of IT');
        $response->assertSee('You have not been assigned to a class yet');
    }

    public function test_a_student_with_nothing_set_up_still_renders_without_error(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->makeStudent($schoolId, 'bare.dash@example.com');

        $response = $this->actingAs($student)->get(route('student.dashboard'));

        $response->assertOk();
        $response->assertSee('No attendance recorded yet this month');
        // "Recent Grades" was replaced by the Course/Overall Progress
        // cards in the dashboard reorder to match the reference HEI
        // portal layout — these are their empty states.
        $response->assertSee('No Course Found');
        $response->assertSee('No Progress to show');
    }
}
