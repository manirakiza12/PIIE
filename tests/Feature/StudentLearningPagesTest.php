<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class StudentLearningPagesTest extends TestCase
{
    use AdmissionsTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', fn (Blueprint $table) => $table->string('school_type')->default('k12'));
        Schema::table('routines', function (Blueprint $table): void {
            $table->unsignedBigInteger('programme_id')->nullable();
        });
        Schema::create('class_rooms', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->unsignedBigInteger('school_id'); $table->timestamps();
        });
    }

    private function student(int $schoolId, string $email): User
    {
        return User::create([
            'name' => 'Portal Student', 'email' => $email, 'password' => bcrypt('secret'),
            'role_id' => 7, 'school_id' => $schoolId, 'code' => 'STU-'.substr(sha1($email), 0, 8),
        ]);
    }

    private function currentClassEnrollment(User $student, int $schoolId): array
    {
        $classId = $this->makeClass($schoolId, ['name' => 'Year 2']);
        $sectionId = $this->makeSection($classId, ['name' => 'Section B']);
        DB::table('enrollment')->insert([
            'user_id' => $student->id, 'class_id' => $classId, 'section_id' => $sectionId,
            'school_id' => $schoolId, 'department_id' => 0, 'session_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('schools')->where('id', $schoolId)->update(['running_session' => 1]);
        return [$classId, $sectionId];
    }

    public function test_class_student_attendance_is_own_school_session_class_and_section_only(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->student($schoolId, 'attendance.class@example.test');
        [$classId, $sectionId] = $this->currentClassEnrollment($student, $schoolId);
        $other = $this->student($schoolId, 'attendance.other@example.test');
        $foreignSchoolId = $this->makeSchool();
        $foreignClassId = $this->makeClass($foreignSchoolId);
        $foreignSectionId = $this->makeSection($foreignClassId);
        $timestamp = now()->startOfMonth()->addDays(3)->timestamp;

        DB::table('daily_attendances')->insert([
            ['class_id' => $classId, 'section_id' => $sectionId, 'student_id' => $student->id, 'school_id' => $schoolId, 'session_id' => 1, 'timestamp' => $timestamp, 'status' => 0],
            ['class_id' => $classId, 'section_id' => $sectionId, 'student_id' => $other->id, 'school_id' => $schoolId, 'session_id' => 1, 'timestamp' => $timestamp, 'status' => 1],
            ['class_id' => $classId, 'section_id' => $sectionId, 'student_id' => $student->id, 'school_id' => $schoolId, 'session_id' => 99, 'timestamp' => $timestamp, 'status' => 1],
            ['class_id' => $foreignClassId, 'section_id' => $foreignSectionId, 'student_id' => $student->id, 'school_id' => $foreignSchoolId, 'session_id' => 1, 'timestamp' => $timestamp, 'status' => 1],
        ]);

        $response = $this->actingAs($student)->get(route('student.daily_attendance'));

        $response->assertOk()->assertSee('Year 2 / Section B')->assertSee('Absent')->assertDontSee('Present');
    }

    public function test_class_attendance_csv_accepts_named_month_and_year_filters(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->student($schoolId, 'attendance.csv@example.test');
        $student->update(['name' => 'Student One']);
        [$classId, $sectionId] = $this->currentClassEnrollment($student, $schoolId);
        $other = $this->student($schoolId, 'attendance.csv.other@example.test');
        $other->update(['name' => 'Student Two']);
        $timestamp = now()->startOfMonth()->timestamp;
        DB::table('daily_attendances')->insert([
            ['class_id' => $classId, 'section_id' => $sectionId, 'student_id' => $student->id, 'school_id' => $schoolId, 'session_id' => 1, 'timestamp' => $timestamp, 'status' => 1],
            ['class_id' => $classId, 'section_id' => $sectionId, 'student_id' => $other->id, 'school_id' => $schoolId, 'session_id' => 1, 'timestamp' => $timestamp, 'status' => 0],
        ]);

        $this->actingAs($student)->get(route('student.dailyAttendanceFilter_csv', [
            'month' => now()->format('M'), 'year' => now()->format('Y'),
        ]))->assertOk()->assertHeader('content-type', 'text/csv')->assertSee('Student One')->assertSee('P')->assertDontSee('Student Two');
    }

    public function test_class_student_course_units_and_syllabus_use_current_class_session(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->student($schoolId, 'learning.class@example.test');
        [$classId, $sectionId] = $this->currentClassEnrollment($student, $schoolId);
        $subjectId = DB::table('subjects')->insertGetId([
            'name' => 'Biology', 'code' => 'BIO2', 'class_id' => $classId, 'school_id' => $schoolId,
            'session_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subjects')->insert([
            'name' => 'Old Biology', 'code' => 'BIO1', 'class_id' => $classId, 'school_id' => $schoolId,
            'session_id' => 9, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('syllabuses')->insert([
            'title' => 'Cell Biology', 'class_id' => $classId, 'section_id' => $sectionId,
            'subject_id' => $subjectId, 'file' => 'cell-biology.pdf', 'school_id' => $schoolId,
            'session_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignSchoolId = $this->makeSchool();
        $foreignClassId = $this->makeClass($foreignSchoolId);
        $foreignSectionId = $this->makeSection($foreignClassId);
        $foreignSubjectId = DB::table('subjects')->insertGetId([
            'name' => 'Foreign Biology', 'code' => 'FBIO', 'class_id' => $foreignClassId,
            'school_id' => $foreignSchoolId, 'session_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('syllabuses')->insert([
            'title' => 'Foreign Syllabus', 'class_id' => $foreignClassId, 'section_id' => $foreignSectionId,
            'subject_id' => $foreignSubjectId, 'file' => 'foreign.pdf', 'school_id' => $foreignSchoolId,
            'session_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($student)->get(route('student.subject_list'))
            ->assertOk()->assertSee('BIO2')->assertSee('Biology')->assertDontSee('Old Biology')->assertDontSee('Foreign Biology');
        $this->actingAs($student)->get(route('student.syllabus'))
            ->assertOk()->assertSee('Cell Biology')->assertSee('Biology')->assertSee('cell-biology.pdf')->assertDontSee('Foreign Syllabus');
    }

    public function test_programme_student_sees_only_finalised_attendance_for_confirmed_own_registrations(): void
    {
        $schoolId = $this->makeSchool();
        DB::table('schools')->where('id', $schoolId)->update(['school_type' => 'higher_ed', 'running_session' => 1]);
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('course_offering_id');
            $table->string('status'); $table->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('subject_id'); $table->timestamps();
        });
        Schema::create('course_offering_attendance_sessions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id');
            $table->date('session_date'); $table->time('starts_at')->nullable(); $table->time('ends_at')->nullable();
            $table->string('topic')->nullable(); $table->string('status'); $table->timestamps();
        });
        Schema::create('course_offering_attendance_records', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('attendance_session_id');
            $table->unsignedBigInteger('course_registration_id'); $table->unsignedBigInteger('student_id');
            $table->unsignedTinyInteger('status'); $table->timestamps();
        });

        $student = $this->student($schoolId, 'attendance.hei@example.test');
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'Bachelor of Science']);
        DB::table('student_profiles')->insert([
            'user_id' => $student->id, 'school_id' => $schoolId, 'programme_id' => $programmeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $subjectId = DB::table('subjects')->insertGetId([
            'name' => 'Chemistry', 'class_id' => null, 'programme_id' => $programmeId, 'school_id' => $schoolId,
            'session_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $offeringId = DB::table('course_offerings')->insertGetId(['school_id' => $schoolId, 'subject_id' => $subjectId, 'created_at' => now(), 'updated_at' => now()]);
        $registrationId = DB::table('course_registrations')->insertGetId([
            'school_id' => $schoolId, 'student_id' => $student->id, 'subject_id' => $subjectId,
            'course_offering_id' => $offeringId, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $today = now()->startOfMonth()->addDays(2)->toDateString();
        $finalisedId = DB::table('course_offering_attendance_sessions')->insertGetId([
            'school_id' => $schoolId, 'course_offering_id' => $offeringId, 'session_date' => $today,
            'topic' => 'Lab 1', 'status' => 'finalised', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $draftId = DB::table('course_offering_attendance_sessions')->insertGetId([
            'school_id' => $schoolId, 'course_offering_id' => $offeringId, 'session_date' => $today,
            'topic' => 'Draft session', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('course_offering_attendance_records')->insert([
            ['school_id' => $schoolId, 'attendance_session_id' => $finalisedId, 'course_registration_id' => $registrationId, 'student_id' => $student->id, 'status' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => $schoolId, 'attendance_session_id' => $draftId, 'course_registration_id' => $registrationId, 'student_id' => $student->id, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $foreignSchoolId = $this->makeSchool();
        $foreignSubjectId = DB::table('subjects')->insertGetId([
            'name' => 'Foreign Physics', 'class_id' => null, 'school_id' => $foreignSchoolId,
            'session_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignOfferingId = DB::table('course_offerings')->insertGetId([
            'school_id' => $foreignSchoolId, 'subject_id' => $foreignSubjectId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignRegistrationId = DB::table('course_registrations')->insertGetId([
            'school_id' => $foreignSchoolId, 'student_id' => $student->id, 'subject_id' => $foreignSubjectId,
            'course_offering_id' => $foreignOfferingId, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignSessionId = DB::table('course_offering_attendance_sessions')->insertGetId([
            'school_id' => $foreignSchoolId, 'course_offering_id' => $foreignOfferingId, 'session_date' => $today,
            'topic' => 'Foreign session', 'status' => 'finalised', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('course_offering_attendance_records')->insert([
            'school_id' => $foreignSchoolId, 'attendance_session_id' => $foreignSessionId,
            'course_registration_id' => $foreignRegistrationId, 'student_id' => $student->id, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($student)->get(route('student.daily_attendance'))
            ->assertOk()->assertSee('Course Attendance')->assertSee('Chemistry')->assertSee('Lab 1')->assertSee('Late')
            ->assertDontSee('Draft session')->assertDontSee('Foreign Physics')->assertDontSee('Foreign session');
    }

    public function test_programme_attendance_reports_unavailable_when_attendance_tables_are_not_installed(): void
    {
        $schoolId = $this->makeSchool();
        DB::table('schools')->where('id', $schoolId)->update(['school_type' => 'higher_ed']);
        $student = $this->student($schoolId, 'attendance.unavailable@example.test');

        $this->actingAs($student)->get(route('student.daily_attendance'))
            ->assertOk()->assertSee('Course attendance is not available for this institution yet.');
    }

    public function test_class_routine_shows_an_explanatory_empty_state_without_schedule_rows(): void
    {
        $schoolId = $this->makeSchool();
        $student = $this->student($schoolId, 'routine.empty@example.test');
        $this->currentClassEnrollment($student, $schoolId);

        $this->actingAs($student)->get(route('student.routine'))
            ->assertOk()->assertSee('No timetable has been published for your class and section this academic session.');
    }

    public function test_programme_student_routine_uses_only_current_school_programme_rows(): void
    {
        $schoolId = $this->makeSchool();
        DB::table('schools')->where('id', $schoolId)->update(['school_type' => 'higher_ed', 'running_session' => 1]);
        $student = $this->student($schoolId, 'routine.hei@example.test');
        $programmeId = $this->makeProgramme($schoolId, ['name' => 'Bachelor of Science']);
        DB::table('student_profiles')->insert([
            'user_id' => $student->id, 'school_id' => $schoolId, 'programme_id' => $programmeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The production schema allows programme timetable rows without class or section IDs.
        Schema::drop('routines');
        Schema::create('routines', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('class_id')->nullable(); $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable(); $table->unsignedBigInteger('programme_id')->nullable();
            $table->integer('starting_hour'); $table->integer('ending_hour'); $table->integer('starting_minute'); $table->integer('ending_minute');
            $table->string('day'); $table->unsignedBigInteger('teacher_id'); $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('session_id'); $table->unsignedBigInteger('school_id'); $table->timestamps();
        });
        $subjectId = DB::table('subjects')->insertGetId([
            'name' => 'Organic Chemistry', 'programme_id' => $programmeId, 'school_id' => $schoolId,
            'session_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lecturer = $this->student($schoolId, 'routine.lecturer@example.test');
        $lecturer->update(['role_id' => 6, 'name' => 'Dr Lecturer']);
        $roomId = DB::table('class_rooms')->insertGetId(['name' => 'Lab 3', 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('routines')->insert([
            'class_id' => null, 'section_id' => null, 'subject_id' => $subjectId, 'programme_id' => $programmeId,
            'starting_hour' => 9, 'starting_minute' => 30, 'ending_hour' => 11, 'ending_minute' => 0,
            'day' => 'tuesday', 'teacher_id' => $lecturer->id, 'room_id' => $roomId,
            'session_id' => 1, 'school_id' => $schoolId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($student)->get(route('student.routine'))
            ->assertOk()->assertSee('Tuesday')->assertSee('09:30–11:00')->assertSee('Organic Chemistry')
            ->assertSee('Dr Lecturer')->assertSee('Lab 3');
    }

    public function test_programme_student_syllabus_route_preserves_bookmark_and_points_to_course_materials(): void
    {
        $schoolId = $this->makeSchool();
        DB::table('schools')->where('id', $schoolId)->update(['school_type' => 'higher_ed']);
        $student = $this->student($schoolId, 'syllabus.hei@example.test');

        $this->actingAs($student)->get(route('student.syllabus'))
            ->assertRedirect(route('student.my_courses'))
            ->assertSessionHas('info', 'Published course materials for your registered Course Allocations are available under My Courses.');
    }
}
