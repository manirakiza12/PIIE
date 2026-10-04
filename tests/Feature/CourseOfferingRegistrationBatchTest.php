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
 * Step 2 verification: the bulk Cohort Registration + Admin Confirmation
 * workflow (CourseOfferingRegistrationBatch + the registerBulk/confirmBulk/
 * confirmStudent HTTP routes) had no Feature coverage before this file,
 * even though CourseRegistrationService/CourseOfferingEligibility (which it
 * reuses) are already well tested elsewhere.
 */
class CourseOfferingRegistrationBatchTest extends TestCase
{
    use AdmissionsTestHelper;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('school_type')->default('higher_ed');
            $table->unsignedBigInteger('current_academic_year_id')->nullable();
            $table->unsignedBigInteger('current_academic_period_id')->nullable();
        });
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('user_id');
            $table->string('permission', 100); $table->unsignedBigInteger('granted_by')->nullable(); $table->timestamps();
            $table->unique(['user_id', 'permission']);
        });
        Schema::create('staff_roles', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->string('name'); $table->string('description')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->unsignedBigInteger('updated_by')->nullable(); $table->timestamps(); });
        Schema::create('staff_role_permissions', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('staff_role_id'); $table->string('permission', 100); $table->timestamps(); });
        Schema::create('user_staff_roles', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('staff_role_id'); $table->unsignedBigInteger('assigned_by')->nullable(); $table->timestamps(); });
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('course_offering_id')->nullable();
            $table->unsignedBigInteger('curriculum_membership_id')->nullable();
            $table->decimal('registered_credits', 6, 2)->unsigned()->nullable();
            $table->string('registered_classification')->nullable();
            $table->string('status', 20)->default('registered');
            $table->timestamps();
            $table->unique(['school_id', 'student_id', 'course_offering_id']);
        });
        Schema::create('academic_years', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->string('label'); $table->date('start_date'); $table->date('end_date'); $table->string('status'); $table->timestamps(); });
        Schema::create('academic_periods', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('academic_year_id'); $table->string('type'); $table->string('label'); $table->unsignedSmallInteger('sequence'); $table->date('start_date')->nullable(); $table->date('end_date')->nullable(); $table->string('status')->default('active'); $table->timestamps(); });
        Schema::create('student_curriculum_assignments', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('programme_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('entry_academic_year_id'); $table->unsignedBigInteger('effective_from_academic_year_id'); $table->unsignedBigInteger('assigned_by'); $table->timestamp('ended_at')->nullable(); $table->string('reason')->nullable(); $table->unsignedBigInteger('programme_cohort_membership_id')->nullable(); $table->unsignedBigInteger('entry_curriculum_stage_id')->nullable(); $table->timestamps(); });
        Schema::create('programme_cohorts', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('programme_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('entry_academic_year_id'); $table->string('name'); $table->string('code'); $table->string('status'); $table->timestamps(); });
        Schema::create('programme_cohort_memberships', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('programme_cohort_id'); $table->string('status'); $table->dateTime('started_at'); $table->dateTime('ended_at')->nullable(); $table->timestamps(); });
        Schema::create('course_offerings', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('academic_year_id'); $table->unsignedBigInteger('academic_period_id'); $table->string('reference')->nullable(); $table->string('status'); $table->timestamps(); });
        Schema::create('course_offering_curriculum_memberships', function (Blueprint $table): void { $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('curriculum_membership_id'); $table->unsignedBigInteger('subject_id'); $table->timestamps(); $table->primary(['school_id', 'course_offering_id', 'curriculum_membership_id']); });
        Schema::create('curricula', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('programme_id'); $table->string('version'); $table->unsignedBigInteger('effective_academic_year_id')->nullable(); $table->string('status'); $table->timestamps(); });
        Schema::create('curriculum_memberships', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('curriculum_stage_id'); $table->string('period_type')->nullable(); $table->unsignedSmallInteger('period_sequence')->nullable(); $table->string('classification'); $table->decimal('credits', 6, 2); $table->unsignedSmallInteger('sequence')->default(0); $table->timestamps(); });
        Schema::create('curriculum_stages', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('curriculum_id'); $table->string('label'); $table->unsignedSmallInteger('sequence'); $table->timestamps(); });

        $this->fixture = $this->makeFixture();
    }

    public function test_register_bulk_selected_mode_registers_eligible_skips_duplicate_and_ineligible(): void
    {
        $manager = $this->staff($this->fixture['school'], ['academic.course_registration.manage']);
        $response = $this->actingAs($manager)->post(route('admin.course_offerings.registrations.bulk', 1), [
            'mode' => 'selected',
            'student_ids' => [
                $this->fixture['studentA']->id,
                $this->fixture['studentB']->id,
                $this->fixture['studentAlready']->id,
                $this->fixture['studentIneligible']->id,
            ],
        ]);
        $response->assertRedirect(route('admin.course_offerings.registrations', 1));
        $summary = session('bulk_summary');
        $this->assertSame(4, $summary['reviewed']);
        $this->assertSame(2, $summary['registered']);
        $this->assertSame(1, $summary['already']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame('registered', CourseRegistration::where('student_id', $this->fixture['studentA']->id)->where('course_offering_id', 1)->value('status'));
        $this->assertSame('registered', CourseRegistration::where('student_id', $this->fixture['studentB']->id)->where('course_offering_id', 1)->value('status'));
        $this->assertFalse(CourseRegistration::where('student_id', $this->fixture['studentIneligible']->id)->where('course_offering_id', 1)->exists());
    }

    public function test_register_bulk_cohort_mode_registers_eligible_cohort_students(): void
    {
        $manager = $this->staff($this->fixture['school'], ['academic.course_registration.manage']);
        $response = $this->actingAs($manager)->post(route('admin.course_offerings.registrations.bulk', 1), [
            'mode' => 'cohort',
            'programme_cohort_id' => 1,
        ]);
        $response->assertRedirect(route('admin.course_offerings.registrations', 1));
        $summary = session('bulk_summary');
        // Cohort membership covers studentA, studentB and studentAlready (already registered).
        $this->assertSame(3, $summary['reviewed']);
        $this->assertSame(2, $summary['registered']);
        $this->assertSame(1, $summary['already']);
        $this->assertSame('registered', CourseRegistration::where('student_id', $this->fixture['studentA']->id)->where('course_offering_id', 1)->value('status'));
        $this->assertSame('registered', CourseRegistration::where('student_id', $this->fixture['studentB']->id)->where('course_offering_id', 1)->value('status'));
    }

    public function test_individual_and_bulk_confirmation_apply_finance_gate(): void
    {
        $confirmer = $this->staff($this->fixture['school'], ['academic.course_registration.confirm']);
        $regA = CourseRegistration::create(['school_id' => $this->fixture['school'], 'student_id' => $this->fixture['studentA']->id, 'course_offering_id' => 1, 'curriculum_membership_id' => 1, 'subject_id' => $this->fixture['subject'], 'registered_credits' => '12.50', 'registered_classification' => 'compulsory', 'status' => 'registered']);
        $regB = CourseRegistration::create(['school_id' => $this->fixture['school'], 'student_id' => $this->fixture['studentB']->id, 'course_offering_id' => 1, 'curriculum_membership_id' => 1, 'subject_id' => $this->fixture['subject'], 'registered_credits' => '12.50', 'registered_classification' => 'compulsory', 'status' => 'registered']);
        DB::table('student_fee_managers')->insert([
            'title' => 'Tuition', 'total_amount' => 100, 'amount' => 100, 'class_id' => 0,
            'student_id' => $this->fixture['studentB']->id, 'payment_method' => 'offline', 'paid_amount' => 20,
            'status' => 'unpaid', 'school_id' => $this->fixture['school'], 'session_id' => 1,
        ]);

        // Individual confirmation.
        $this->actingAs($confirmer)->post(route('admin.course_offerings.registrations.confirm', [1, $regA->id]))
            ->assertRedirect(route('admin.course_offerings.registrations', 1));
        $this->assertSame('confirmed', $regA->fresh()->status);
        $this->assertSame(1, session('bulk_summary')['confirmed']);

        // Bulk confirmation: regA already confirmed, regB blocked by outstanding fees.
        $this->actingAs($confirmer)->post(route('admin.course_offerings.registrations.confirm_bulk', 1), [
            'registration_ids' => [$regA->id, $regB->id],
        ])->assertRedirect(route('admin.course_offerings.registrations', 1));
        $summary = session('bulk_summary');
        $this->assertSame(2, $summary['reviewed']);
        $this->assertSame(0, $summary['confirmed']);
        $this->assertSame(1, $summary['already']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame('registered', $regB->fresh()->status);
        $this->assertStringContainsString('financial clearance', collect($summary['details'])->firstWhere('result', 'skipped')['reason']);
    }

    public function test_registration_and_confirmation_routes_enforce_distinct_rbac(): void
    {
        $viewer = $this->staff($this->fixture['school'], ['academic.course_registration.view']);
        $this->actingAs($viewer)
            ->post(route('admin.course_offerings.registrations.bulk', 1), ['mode' => 'selected', 'student_ids' => [$this->fixture['studentA']->id]])
            ->assertForbidden();
        $this->post(route('admin.course_offerings.registrations.confirm_bulk', 1), ['registration_ids' => [1]])->assertForbidden();

        $manageOnly = $this->staff($this->fixture['school'], ['academic.course_registration.manage']);
        $this->actingAs($manageOnly)
            ->post(route('admin.course_offerings.registrations.bulk', 1), ['mode' => 'selected', 'student_ids' => [$this->fixture['studentA']->id]])
            ->assertRedirect();
        $registration = CourseRegistration::where('student_id', $this->fixture['studentA']->id)->where('course_offering_id', 1)->firstOrFail();
        $this->post(route('admin.course_offerings.registrations.confirm', [1, $registration->id]))->assertForbidden();
        $this->post(route('admin.course_offerings.registrations.confirm_bulk', 1), ['registration_ids' => [$registration->id]])->assertForbidden();

        $confirmOnly = $this->staff($this->fixture['school'], ['academic.course_registration.confirm']);
        $this->actingAs($confirmOnly)
            ->post(route('admin.course_offerings.registrations.bulk', 1), ['mode' => 'selected', 'student_ids' => [$this->fixture['studentB']->id]])
            ->assertForbidden();
        $this->post(route('admin.course_offerings.registrations.confirm', [1, $registration->id]))->assertRedirect();
        $this->assertSame('confirmed', $registration->fresh()->status);
    }

    public function test_bulk_routes_are_tenant_scoped(): void
    {
        $manager = $this->staff($this->fixture['school'], ['academic.course_registration.manage', 'academic.course_registration.confirm']);
        $this->actingAs($manager)
            ->post(route('admin.course_offerings.registrations.bulk', 2), ['mode' => 'selected', 'student_ids' => [$this->fixture['studentA']->id]])
            ->assertNotFound();
        $this->post(route('admin.course_offerings.registrations.confirm_bulk', 2), ['registration_ids' => [1]])->assertNotFound();
    }

    private function makeFixture(): array
    {
        $school = $this->makeSchool(['school_type' => 'higher_ed']);
        $foreignSchool = $this->makeSchool(['school_type' => 'higher_ed']);
        $programme = $this->makeProgramme($school);

        DB::table('academic_years')->insert(['id' => 1, 'school_id' => $school, 'label' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('academic_periods')->insert(['id' => 1, 'school_id' => $school, 'academic_year_id' => 1, 'type' => 'semester', 'label' => 'Semester 1', 'sequence' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('schools')->where('id', $school)->update(['current_academic_year_id' => 1, 'current_academic_period_id' => 1]);

        $subject = (int) DB::table('subjects')->insertGetId(['name' => 'Research Methods', 'code' => 'R101', 'credits' => 3, 'course_type' => 'compulsory', 'pass_mark' => 50, 'programme_id' => $programme, 'school_id' => $school, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('curricula')->insert(['id' => 1, 'school_id' => $school, 'programme_id' => $programme, 'version' => '1', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('curriculum_stages')->insert(['id' => 1, 'school_id' => $school, 'curriculum_id' => 1, 'label' => 'Year 1', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('curriculum_memberships')->insert(['id' => 1, 'school_id' => $school, 'curriculum_id' => 1, 'subject_id' => $subject, 'curriculum_stage_id' => 1, 'period_type' => 'semester', 'period_sequence' => 1, 'classification' => 'compulsory', 'credits' => '12.50', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now()]);

        DB::table('course_offerings')->insert(['id' => 1, 'school_id' => $school, 'subject_id' => $subject, 'academic_year_id' => 1, 'academic_period_id' => 1, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('course_offering_curriculum_memberships')->insert(['school_id' => $school, 'course_offering_id' => 1, 'curriculum_id' => 1, 'curriculum_membership_id' => 1, 'subject_id' => $subject, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('course_offerings')->insert(['id' => 2, 'school_id' => $foreignSchool, 'subject_id' => $subject, 'academic_year_id' => 1, 'academic_period_id' => 1, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('programme_cohorts')->insert(['id' => 1, 'school_id' => $school, 'programme_id' => $programme, 'curriculum_id' => 1, 'entry_academic_year_id' => 1, 'name' => 'Cohort 2026', 'code' => 'C-2026', 'status' => 'active']);

        $studentA = User::create(['name' => 'Student A', 'email' => 'student-a@example.com', 'password' => bcrypt('secret'), 'role_id' => 7, 'school_id' => $school]);
        $studentB = User::create(['name' => 'Student B', 'email' => 'student-b@example.com', 'password' => bcrypt('secret'), 'role_id' => 7, 'school_id' => $school]);
        $studentAlready = User::create(['name' => 'Student Already', 'email' => 'student-already@example.com', 'password' => bcrypt('secret'), 'role_id' => 7, 'school_id' => $school]);
        $studentIneligible = User::create(['name' => 'Student Ineligible', 'email' => 'student-ineligible@example.com', 'password' => bcrypt('secret'), 'role_id' => 7, 'school_id' => $school]);

        foreach ([$studentA, $studentB, $studentAlready, $studentIneligible] as $student) {
            DB::table('student_profiles')->insert(['user_id' => $student->id, 'school_id' => $school, 'programme_id' => $programme, 'created_at' => now(), 'updated_at' => now()]);
        }

        $cohortMembershipA = (int) DB::table('programme_cohort_memberships')->insertGetId(['school_id' => $school, 'student_id' => $studentA->id, 'programme_cohort_id' => 1, 'status' => 'active', 'started_at' => now()]);
        $cohortMembershipB = (int) DB::table('programme_cohort_memberships')->insertGetId(['school_id' => $school, 'student_id' => $studentB->id, 'programme_cohort_id' => 1, 'status' => 'active', 'started_at' => now()]);
        $cohortMembershipAlready = (int) DB::table('programme_cohort_memberships')->insertGetId(['school_id' => $school, 'student_id' => $studentAlready->id, 'programme_cohort_id' => 1, 'status' => 'active', 'started_at' => now()]);

        foreach ([[$studentA, $cohortMembershipA], [$studentB, $cohortMembershipB], [$studentAlready, $cohortMembershipAlready]] as [$student, $cohortMembershipId]) {
            DB::table('student_curriculum_assignments')->insert(['school_id' => $school, 'student_id' => $student->id, 'programme_id' => $programme, 'curriculum_id' => 1, 'entry_academic_year_id' => 1, 'effective_from_academic_year_id' => 1, 'programme_cohort_membership_id' => $cohortMembershipId, 'entry_curriculum_stage_id' => 1, 'assigned_by' => $student->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        CourseRegistration::create([
            'school_id' => $school, 'student_id' => $studentAlready->id, 'course_offering_id' => 1,
            'curriculum_membership_id' => 1, 'subject_id' => $subject, 'registered_credits' => '12.50',
            'registered_classification' => 'compulsory', 'status' => 'registered',
        ]);

        return compact('school', 'foreignSchool', 'programme', 'subject', 'studentA', 'studentB', 'studentAlready', 'studentIneligible');
    }

    private function staff(int $schoolId, array $permissions): User
    {
        $user = User::factory()->create(['role_id' => 3, 'school_id' => $schoolId, 'account_status' => 'active']);
        foreach ($permissions as $permission) {
            DB::table('user_permissions')->insert(['school_id' => $schoolId, 'user_id' => $user->id, 'permission' => $permission, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $user;
    }
}
