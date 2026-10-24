<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingAttendanceSession;
use App\Models\User;
use App\Support\Academic\StudentPlacementDirectory;
use App\Support\CourseOfferingAttendance\CourseOfferingAttendanceService;
use App\Support\CourseRegistration\CourseOfferingRoster;
use App\Support\CourseRegistration\CourseRegistrationService;
use App\Support\CourseRegistration\StudentCourseOfferingDiscovery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\Feature\Support\CourseOfferingEligibilityFixture;
use Tests\TestCase;

class ExistingStudentAcademicPlacementTest extends TestCase
{
    use AdmissionsTestHelper;
    use CourseOfferingEligibilityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
        });
        (require database_path('migrations/2026_10_21_060000_create_student_opening_payments.php'))->up();
        Schema::table('schools', function (Blueprint $table) {
            $table->string('school_type')->default('higher_ed');
            $table->unsignedBigInteger('current_academic_year_id')->nullable();
            $table->unsignedBigInteger('current_academic_period_id')->nullable();
        });
        $this->createTables();
        $this->seedAcademicStructure();
        Mail::fake();
    }

    private function addExistingStudent(): User
    {
        $intake = $this->makeIntakeSession($this->school);
        $this->actingAs($this->makeAdminUser($this->school))->post(route('admin.student.create'), [
            'name' => 'Existing Placement Student', 'email' => 'existing-placement@example.test',
            'registration_number' => 'PIIE/EXISTING/42', 'password_option' => 'auto',
            'programme_id' => $this->programme, 'intake_session_id' => $intake, 'year_of_study' => 1,
            'gender' => '', 'blood_group' => '', 'birthday' => '2000-01-01', 'phone' => '', 'address' => '',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $student = User::where('email', 'existing-placement@example.test')->firstOrFail();
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id, 'school_id' => $this->school,
            'programme_id' => $this->programme, 'intake_session_id' => $intake, 'was_existing_at_portal_creation' => 1]);
        return $student;
    }

    public function test_add_existing_student_is_discoverable_then_placed_and_registered_without_another_account(): void
    {
        $student = $this->addExistingStudent();
        $this->assertSame(0, DB::table('enrollment')->where('user_id', $student->id)->count());
        $this->assertSame(0, DB::table('student_curriculum_assignments')->where('student_id', $student->id)->count());
        $this->assertSame(0, DB::table('programme_cohort_memberships')->where('student_id', $student->id)->count());
        $this->assertSame(0, DB::table('course_registrations')->where('student_id', $student->id)->count());
        $this->assertSame(0, DB::table('student_fee_managers')->where('student_id', $student->id)->count());
        $this->get(route('admin.student.documents', $student->id))->assertOk()
            ->assertSee('Academic placement is separate')->assertSee(route('admin.academic.direct_placement', ['student_id' => $student->id]), false);
        foreach (['admin.academic.placements.index', 'admin.academic.direct_placement'] as $route) {
            $this->get(route($route, ['search' => $student->code, 'source' => 'existing']))->assertOk()
                ->assertSee($student->name)->assertSee('No active academic placement')->assertSee('Available in the placement dropdown');
        }
        $this->assertFalse(app(CourseOfferingRoster::class)->eligible(CourseOffering::findOrFail(11))->contains('id', $student->id));
        $this->post(route('admin.academic.direct_placement.store'), [
            'student_id' => $student->id, 'curriculum_id' => 1, 'entry_curriculum_stage_id' => 1, 'academic_year_id' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.academic.placements.index', ['student_id' => $student->id]))
            ->assertSessionHas('success', fn ($message) => str_contains($message, '2026-V1') && str_contains($message, 'Year 1') && str_contains($message, '2026/2027'));
        $this->assertDatabaseHas('student_curriculum_assignments', ['student_id' => $student->id, 'school_id' => $this->school,
            'programme_id' => $this->programme, 'curriculum_id' => 1, 'entry_curriculum_stage_id' => 1, 'entry_academic_year_id' => 1]);
        $page = $this->get(route('admin.academic.direct_placement', ['student_id' => $student->id]))->assertOk()
            ->assertSee('Excluded: already has an active placement')->assertSee('2026-V1')->assertSee('2026/2027');
        $this->assertFalse($page->viewData('students')->contains('id', $student->id));
        $this->assertTrue(app(\App\Support\CourseRegistration\CourseOfferingEligibility::class)->evaluate(CourseOffering::findOrFail(11), $student->id)->eligible);
        $this->assertTrue(app(CourseOfferingRoster::class)->review(CourseOffering::findOrFail(11))['ineligible']->contains('id', $student->id));
        $this->assertFalse(app(CourseOfferingRoster::class)->eligible(CourseOffering::findOrFail(21))->contains('id', $student->id));
        $this->assertFalse(app(CourseOfferingRoster::class)->eligible(CourseOffering::findOrFail(12))->contains('id', $student->id));
        // Satisfy the existing fee threshold in the isolated test database only.
        DB::table('student_fee_managers')->insert(['title' => 'Test tuition', 'total_amount' => 100, 'amount' => 100,
            'class_id' => 0, 'student_id' => $student->id, 'payment_method' => 'offline', 'paid_amount' => 20,
            'status' => 'unpaid', 'school_id' => $this->school, 'session_id' => 1]);
        $this->assertTrue(app(CourseOfferingRoster::class)->eligible(CourseOffering::findOrFail(11))->contains('id', $student->id));
        $discovery = app(StudentCourseOfferingDiscovery::class);
        $this->assertSame(111, $discovery->assignmentMembershipForOffering($student, 11));
        $registrations = app(CourseRegistrationService::class);
        $registration = $registrations->registerStudentForOffering($this->school, $student->id, 11, 111, auth()->id());
        $registrations->confirmRegistration($this->school, $registration->id, auth()->id());
        (require database_path('migrations/2026_09_28_000001_create_course_offering_attendance_tables.php'))->up();
        $attendanceSession = new CourseOfferingAttendanceSession();
        $attendanceSession->forceFill(['id' => 1, 'school_id' => $this->school, 'course_offering_id' => 11]);
        $this->assertTrue(app(CourseOfferingAttendanceService::class)->rosterFor($attendanceSession)->contains('student_id', $student->id));
        $this->assertTrue($discovery->discover($student)['confirmed']->contains('id', $registration->id));
        Schema::drop('routines');
        Schema::create('class_rooms', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->string('name');
        });
        Schema::create('routines', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('class_id')->nullable(); $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('programme_id'); $table->unsignedBigInteger('subject_id');
            $table->integer('starting_hour'); $table->integer('ending_hour'); $table->integer('starting_minute'); $table->integer('ending_minute');
            $table->string('day'); $table->unsignedBigInteger('teacher_id'); $table->unsignedBigInteger('room_id');
            $table->unsignedBigInteger('session_id'); $table->unsignedBigInteger('school_id'); $table->timestamps();
        });
        foreach ([[$this->school, $this->programme, 1, 'monday'], [$this->school, $this->otherProgramme, 1, 'tuesday'],
            [$this->foreignSchool, $this->programme, 1, 'wednesday'], [$this->school, $this->programme, 99, 'thursday']] as [$school, $programme, $session, $day]) {
            DB::table('routines')->insert(['school_id' => $school, 'programme_id' => $programme, 'session_id' => $session,
                'subject_id' => $this->units['Y1'], 'teacher_id' => auth()->id(), 'room_id' => 0, 'day' => $day,
                'starting_hour' => 9, 'starting_minute' => 0, 'ending_hour' => 10, 'ending_minute' => 0]);
        }
        // Password setup is separately covered by ExistingStudentPortalOnlyTest.
        $student->forceFill(['force_password_change' => false])->save();
        $this->actingAs($student)->get(route('student.subject_list'))->assertRedirect(route('student.my_courses'));
        $this->get(route('student.syllabus'))->assertRedirect(route('student.my_courses'));
        $this->get(route('student.my_courses'))->assertOk()->assertSee('BBIT1101');
        $routine = $this->get(route('student.routine'))->assertOk();
        $this->assertSame(['monday'], $routine->viewData('routines')->pluck('day')->all());
        $this->assertSame(1, User::where('email', $student->email)->count());
        $this->assertSame(1, DB::table('student_profiles')->where('user_id', $student->id)->count());
        $this->assertSame(0, DB::table('enrollment')->where('user_id', $student->id)->count());
    }

    public function test_directory_includes_unplaced_historical_disabled_and_incomplete_students_and_explains_exclusions(): void
    {
        $unplaced = $this->addExistingStudent();
        $placed = $this->placedStudent(stage: 1);
        $historical = $this->placedStudent(stage: 1);
        DB::table('student_curriculum_assignments')->where('student_id', $historical->id)->update(['ended_at' => now()]);
        $incomplete = $this->placedStudent(stage: 1);
        DB::table('student_curriculum_assignments')->where('student_id', $incomplete->id)->update(['entry_curriculum_stage_id' => null]);
        $disabled = User::factory()->create(['school_id' => $this->school, 'role_id' => 7, 'account_status' => 'disable']);
        $missingProfile = User::factory()->create(['school_id' => $this->school, 'role_id' => 7]);
        $foreign = User::factory()->create(['school_id' => $this->foreignSchool, 'role_id' => 7]);
        $response = $this->get(route('admin.academic.direct_placement'))->assertOk()
            ->assertSee($unplaced->name)->assertSee($placed->name)->assertSee($historical->name)->assertSee($incomplete->name)
            ->assertSee('Incomplete active placement')->assertSee('Excluded: student account is disabled')
            ->assertSee('Excluded: institution student profile is missing')->assertDontSee($foreign->email);
        $candidates = $response->viewData('students')->pluck('id')->all();
        $this->assertContains($unplaced->id, $candidates);
        $this->assertContains($historical->id, $candidates);
        foreach ([$placed, $incomplete, $disabled, $missingProfile, $foreign] as $excluded) $this->assertNotContains($excluded->id, $candidates);
        $this->get(route('admin.academic.placements.index', ['source' => 'existing']))->assertOk()->assertSee($unplaced->email)->assertDontSee($placed->email);
        $this->get(route('admin.academic.placements.index', ['search' => $unplaced->code]))->assertOk()->assertSee($unplaced->email)->assertDontSee($placed->email);
        $this->get(route('admin.academic.direct_placement', ['student_id' => $foreign->id]))->assertNotFound();
        $this->post(route('admin.academic.direct_placement.store'), ['student_id' => $foreign->id, 'curriculum_id' => 1,
            'entry_curriculum_stage_id' => 1, 'academic_year_id' => 1])->assertSessionHasErrors('student_id');
    }

    public function test_wrong_programme_year_foreign_year_and_duplicate_placement_do_not_create_records(): void
    {
        $student = $this->addExistingStudent();
        $payload = ['student_id' => $student->id, 'curriculum_id' => 1, 'entry_curriculum_stage_id' => 5, 'academic_year_id' => 1];
        $this->post(route('admin.academic.direct_placement.store'), $payload)->assertSessionHasErrors('placement');
        DB::table('academic_years')->insert(['id' => 9, 'school_id' => $this->foreignSchool, 'label' => 'Foreign year',
            'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'active']);
        $this->post(route('admin.academic.direct_placement.store'), array_replace($payload, ['entry_curriculum_stage_id' => 1, 'academic_year_id' => 9]))->assertSessionHasErrors('academic_year_id');
        $this->assertSame(0, DB::table('student_curriculum_assignments')->count());
        $payload['entry_curriculum_stage_id'] = 1;
        $this->post(route('admin.academic.direct_placement.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('admin.academic.direct_placement.store'), $payload)->assertSessionHasErrors('placement');
        $this->assertSame(1, DB::table('student_curriculum_assignments')->where('student_id', $student->id)->count());
        $this->assertSame(1, User::where('email', $student->email)->count());
        $this->assertDatabaseHas('student_profiles', ['user_id' => $student->id, 'programme_id' => $this->programme, 'year_of_study' => 1]);
    }

    public function test_directory_never_discloses_foreign_links_and_search_finds_students_beyond_first_page(): void
    {
        $student = $this->addExistingStudent();
        $foreignProgramme = $this->makeProgramme($this->foreignSchool, ['name' => 'Private foreign programme']);
        DB::table('student_profiles')->where('user_id', $student->id)->update(['programme_id' => $foreignProgramme]);
        DB::table('student_curriculum_assignments')->insert(['school_id' => $this->foreignSchool, 'student_id' => $student->id,
            'programme_id' => $foreignProgramme, 'curriculum_id' => 1, 'entry_curriculum_stage_id' => 1,
            'entry_academic_year_id' => 1, 'effective_from_academic_year_id' => 1, 'assigned_by' => auth()->id()]);
        for ($i = 0; $i < 27; $i++) User::factory()->create(['name' => 'AAA Student ' . $i, 'school_id' => $this->school, 'role_id' => 7]);
        $this->get(route('admin.academic.placements.index'))->assertOk()->assertDontSee($student->email);
        $this->get(route('admin.academic.placements.index', ['search' => $student->code]))->assertOk()
            ->assertSee($student->email)->assertSee('No active academic placement')->assertDontSee('Private foreign programme');
        $directoryStudent = app(StudentPlacementDirectory::class)->query($this->school)->where('u.id', $student->id)->first();
        $this->assertNull($directoryStudent->placement_id);
        $this->assertNull($directoryStudent->profile_programme);
        $this->actingAs($student)->get(route('admin.academic.placements.index'))->assertRedirect();
    }
}
