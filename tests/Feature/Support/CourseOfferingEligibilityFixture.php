<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HEI academic structure for Course Offering eligibility tests: one BBIT Study
 * Plan with Year 1-3 stages, open Semester 1 Offerings 11 (Year 1 unit),
 * 21 (Year 2), 31 (Year 3) and 12 (Year 1 unit in 2027/2028), plus cohorts
 * 1 (BBIT 2026-V1), 2 (BBIT 2026-V2) and 3 (another Programme).
 * Requires AdmissionsTestHelper tables.
 */
trait CourseOfferingEligibilityFixture
{
    protected int $school;
    protected int $foreignSchool;
    protected int $programme;
    protected int $otherProgramme;
    protected array $units = [];
    protected int $studentCounter = 0;

    protected function placedStudent(int $stage, int $curriculum = 1, ?int $programme = null, int $cohort = 1): User
    {
        $programme ??= $this->programme;
        $n = ++$this->studentCounter;
        $student = User::create(['name' => "Student {$n}", 'email' => "student{$n}@example.com", 'password' => bcrypt('x'), 'role_id' => 7, 'school_id' => $this->school]);
        DB::table('student_profiles')->insert(['user_id' => $student->id, 'school_id' => $this->school, 'programme_id' => $programme, 'created_at' => now(), 'updated_at' => now()]);
        $membership = DB::table('programme_cohort_memberships')->insertGetId(['school_id' => $this->school, 'student_id' => $student->id, 'programme_cohort_id' => $cohort, 'status' => 'active', 'started_at' => now()]);
        DB::table('student_curriculum_assignments')->insert([
            'school_id' => $this->school, 'student_id' => $student->id, 'programme_id' => $programme, 'curriculum_id' => $curriculum,
            'entry_academic_year_id' => 1, 'effective_from_academic_year_id' => 1, 'programme_cohort_membership_id' => $membership,
            'entry_curriculum_stage_id' => $stage, 'assigned_by' => $student->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $student;
    }

    protected function seedAcademicStructure(): void
    {
        $this->school = $this->makeSchool();
        $this->foreignSchool = $this->makeSchool();
        $this->programme = $this->makeProgramme($this->school, ['name' => 'Bachelor of Business Information Technology']);
        $this->otherProgramme = $this->makeProgramme($this->school, ['name' => 'Other Programme']);
        DB::table('academic_years')->insert([
            ['id' => 1, 'school_id' => $this->school, 'label' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-06-30', 'status' => 'active'],
            ['id' => 2, 'school_id' => $this->school, 'label' => '2027/2028', 'start_date' => '2027-09-01', 'end_date' => '2028-06-30', 'status' => 'active'],
        ]);
        DB::table('academic_periods')->insert([
            ['id' => 1, 'school_id' => $this->school, 'academic_year_id' => 1, 'type' => 'semester', 'label' => 'Semester 1', 'sequence' => 1, 'status' => 'active'],
            ['id' => 2, 'school_id' => $this->school, 'academic_year_id' => 2, 'type' => 'semester', 'label' => 'Semester 1', 'sequence' => 1, 'status' => 'active'],
        ]);
        DB::table('schools')->where('id', $this->school)->update(['current_academic_year_id' => 1, 'current_academic_period_id' => 1]);

        foreach (['Y1' => 'BBIT1101', 'Y2' => 'BBIT2101', 'Y3' => 'BBIT3102'] as $key => $code) {
            $this->units[$key] = (int) DB::table('subjects')->insertGetId(['name' => $code, 'code' => $code, 'credits' => 3, 'course_type' => 'compulsory', 'pass_mark' => 50, 'programme_id' => $this->programme, 'school_id' => $this->school]);
        }
        DB::table('curricula')->insert([
            ['id' => 1, 'school_id' => $this->school, 'programme_id' => $this->programme, 'version' => '2026-V1', 'status' => 'approved'],
            ['id' => 2, 'school_id' => $this->school, 'programme_id' => $this->programme, 'version' => '2026-V2', 'status' => 'approved'],
            ['id' => 3, 'school_id' => $this->school, 'programme_id' => $this->otherProgramme, 'version' => 'OP-V1', 'status' => 'approved'],
        ]);
        DB::table('curriculum_stages')->insert([
            ['id' => 1, 'school_id' => $this->school, 'curriculum_id' => 1, 'label' => 'Year 1', 'sequence' => 1],
            ['id' => 2, 'school_id' => $this->school, 'curriculum_id' => 1, 'label' => 'Year 2', 'sequence' => 2],
            ['id' => 3, 'school_id' => $this->school, 'curriculum_id' => 1, 'label' => 'Year 3', 'sequence' => 3],
            ['id' => 4, 'school_id' => $this->school, 'curriculum_id' => 2, 'label' => 'Year 1', 'sequence' => 1],
            ['id' => 5, 'school_id' => $this->school, 'curriculum_id' => 3, 'label' => 'Year 1', 'sequence' => 1],
        ]);
        $membership = fn (int $id, int $curriculum, string $unit, int $stage) => ['id' => $id, 'school_id' => $this->school, 'curriculum_id' => $curriculum, 'subject_id' => $this->units[$unit], 'curriculum_stage_id' => $stage, 'period_type' => 'semester', 'period_sequence' => 1, 'classification' => 'compulsory', 'credits' => '3.00', 'sequence' => 1];
        DB::table('curriculum_memberships')->insert([
            $membership(111, 1, 'Y1', 1), $membership(121, 1, 'Y2', 2), $membership(131, 1, 'Y3', 3),
            $membership(211, 2, 'Y1', 4), $membership(311, 3, 'Y1', 5),
        ]);
        foreach ([[11, 'Y1', 1, 1, 111], [21, 'Y2', 1, 1, 121], [31, 'Y3', 1, 1, 131], [12, 'Y1', 2, 2, 111]] as [$id, $unit, $year, $period, $link]) {
            DB::table('course_offerings')->insert(['id' => $id, 'school_id' => $this->school, 'subject_id' => $this->units[$unit], 'academic_year_id' => $year, 'academic_period_id' => $period, 'reference' => "REF-{$id}", 'status' => 'open']);
            DB::table('course_offering_curriculum_memberships')->insert(['school_id' => $this->school, 'course_offering_id' => $id, 'curriculum_id' => 1, 'curriculum_membership_id' => $link, 'subject_id' => $this->units[$unit]]);
        }
        DB::table('programme_cohorts')->insert([
            ['id' => 1, 'school_id' => $this->school, 'programme_id' => $this->programme, 'curriculum_id' => 1, 'entry_academic_year_id' => 1, 'name' => 'BBIT September 2026 Cohort', 'code' => 'BBIT-SEP-2026', 'status' => 'active'],
            ['id' => 2, 'school_id' => $this->school, 'programme_id' => $this->programme, 'curriculum_id' => 2, 'entry_academic_year_id' => 1, 'name' => 'BBIT V2 Cohort', 'code' => 'BBIT-V2', 'status' => 'active'],
            ['id' => 3, 'school_id' => $this->school, 'programme_id' => $this->otherProgramme, 'curriculum_id' => 3, 'entry_academic_year_id' => 1, 'name' => 'Other Cohort', 'code' => 'OP-2026', 'status' => 'active'],
        ]);
    }

    protected function createTables(): void
    {
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('session_id')->nullable(); $table->unsignedBigInteger('course_offering_id')->nullable();
            $table->unsignedBigInteger('curriculum_membership_id')->nullable(); $table->decimal('registered_credits', 6, 2)->nullable();
            $table->string('registered_classification')->nullable(); $table->string('status', 20)->default('registered'); $table->timestamps();
            $table->unique(['school_id', 'student_id', 'course_offering_id']);
        });
        Schema::create('academic_years', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->string('label'); $table->date('start_date'); $table->date('end_date'); $table->string('status'); $table->timestamps(); });
        Schema::create('academic_periods', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('academic_year_id'); $table->string('type'); $table->string('label'); $table->unsignedSmallInteger('sequence'); $table->date('start_date')->nullable(); $table->date('end_date')->nullable(); $table->string('status')->default('active'); $table->timestamps(); });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id'); $table->unsignedBigInteger('user_id'); $table->string('role'); $table->date('starts_on'); $table->date('ends_on')->nullable(); $table->string('status'); $table->timestamps(); });
        Schema::create('student_curriculum_assignments', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('programme_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('entry_academic_year_id'); $table->unsignedBigInteger('effective_from_academic_year_id'); $table->unsignedBigInteger('assigned_by'); $table->timestamp('ended_at')->nullable(); $table->string('reason')->nullable(); $table->unsignedBigInteger('programme_cohort_membership_id')->nullable(); $table->unsignedBigInteger('entry_curriculum_stage_id')->nullable(); $table->timestamps(); });
        Schema::create('programme_cohorts', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('programme_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('entry_academic_year_id'); $table->string('name'); $table->string('code'); $table->string('status'); $table->timestamps(); });
        Schema::create('programme_cohort_memberships', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('programme_cohort_id'); $table->string('status'); $table->dateTime('started_at'); $table->dateTime('ended_at')->nullable(); $table->timestamps(); });
        Schema::create('course_offerings', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('academic_year_id'); $table->unsignedBigInteger('academic_period_id'); $table->string('reference')->nullable(); $table->string('status'); $table->timestamps(); });
        Schema::create('course_offering_curriculum_memberships', function (Blueprint $table): void { $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('curriculum_membership_id'); $table->unsignedBigInteger('subject_id'); $table->timestamps(); $table->primary(['school_id', 'course_offering_id', 'curriculum_membership_id']); });
        Schema::create('curricula', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('programme_id'); $table->string('version'); $table->unsignedBigInteger('effective_academic_year_id')->nullable(); $table->string('status'); $table->timestamps(); });
        Schema::create('curriculum_memberships', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('curriculum_stage_id'); $table->string('period_type')->nullable(); $table->unsignedSmallInteger('period_sequence')->nullable(); $table->string('classification'); $table->decimal('credits', 6, 2); $table->unsignedSmallInteger('sequence')->default(0); $table->timestamps(); });
        Schema::create('curriculum_stages', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('curriculum_id'); $table->string('label'); $table->unsignedSmallInteger('sequence'); $table->timestamps(); });
    }
}
