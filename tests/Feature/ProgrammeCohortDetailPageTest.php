<?php

namespace Tests\Feature;

use App\Models\ProgrammeCohort;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

/** Retired cohort URLs preserve data and lead authorised staff to direct placement. */
class ProgrammeCohortDetailPageTest extends TestCase
{
    use StaffModuleTestHelper;

    private User $admin;

    private int $school;

    private int $otherSchool;

    private int $programme;

    private int $plan;

    private int $stage;

    private int $intake;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootStaffModuleTestSchema();
        $this->cohortSchema();

        // Programme Cohorts are a higher-education structure, so the tenant
        // type has to be present for the controller to reach the page at all.
        if (!Schema::hasColumn('schools', 'school_type')) {
            Schema::table('schools', function (Blueprint $table): void {
                $table->string('school_type')->default('k12');
            });
        }

        $this->school = $this->makeSchool();
        $this->otherSchool = $this->makeSchool();

        DB::table('schools')->where('id', $this->school)->update(['school_type' => 'higher_ed']);
        DB::table('schools')->where('id', $this->otherSchool)->update(['school_type' => 'higher_ed']);

        $this->admin = User::factory()->create([
            'name' => 'Cohort Admin', 'email' => 'cohort.admin@example.test', 'role_id' => 2,
            'school_id' => $this->school, 'account_status' => 'active',
        ]);

        $this->programme = $this->makeProgramme($this->school, 'BBIT', 'Bachelor of Business Information Technology');
        $this->intake = DB::table('intake_sessions')->insertGetId([
            'school_id' => $this->school, 'name' => '2026 September Intake',
        ]);
        $this->year = DB::table('academic_years')->insertGetId([
            'school_id' => $this->school, 'label' => '2026/2027',
            'start_date' => '2026-09-30', 'end_date' => '2027-05-25', 'status' => 'active',
        ]);
        $this->plan = DB::table('curricula')->insertGetId([
            'school_id' => $this->school, 'programme_id' => $this->programme, 'version' => '2026-V1',
            'effective_academic_year_id' => $this->year, 'status' => 'approved',
        ]);
        $this->stage = DB::table('curriculum_stages')->insertGetId([
            'school_id' => $this->school, 'curriculum_id' => $this->plan, 'label' => 'Year 1', 'sequence' => 1,
        ]);
    }

    /** A ready-to-activate cohort, shaped exactly like the real BBIT September 2026 cohort. */
    private function cohort(array $overrides = [], int $schoolId = null): ProgrammeCohort
    {
        $row = array_merge([
            'school_id' => $schoolId ?? $this->school,
            'programme_id' => $this->programme,
            'intake_session_id' => $this->intake,
            'entry_academic_year_id' => $this->year,
            'curriculum_id' => $this->plan,
            'name' => 'BBIT September 2026 Cohort',
            'code' => 'BBIT-SEP-2026',
            'status' => 'active',
            'expected_completion_date' => null,
            'created_by' => $this->admin->id,
        ], $overrides);

        $id = DB::table('programme_cohorts')->insertGetId($row);

        return ProgrammeCohort::findOrFail($id);
    }

    private function addMember(ProgrammeCohort $cohort, array $overrides = []): int
    {
        $student = User::factory()->create(array_merge([
            'name' => 'Cohort Student', 'role_id' => 7, 'school_id' => $this->school,
            'account_status' => 'active',
        ], $overrides));

        DB::table('student_profiles')->insert([
            'user_id' => $student->id, 'school_id' => $this->school, 'programme_id' => $this->programme,
            'intake_session_id' => $this->intake, 'year_of_study' => 1, 'status' => 'active',
        ]);

        $membershipId = DB::table('programme_cohort_memberships')->insertGetId([
            'school_id' => $cohort->school_id, 'student_id' => $student->id,
            'programme_cohort_id' => $cohort->id, 'admission_id' => null,
            'admission_reference' => null, 'status' => 'active', 'started_at' => now(),
            'ended_at' => null, 'assigned_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $membershipId;
    }

    private function place(ProgrammeCohort $cohort, int $membershipId, int $studentId): int
    {
        return (int) DB::table('student_curriculum_assignments')->insertGetId([
            'school_id' => $cohort->school_id, 'student_id' => $studentId,
            'programme_id' => $cohort->programme_id, 'curriculum_id' => $cohort->curriculum_id,
            'entry_academic_year_id' => $this->year, 'effective_from_academic_year_id' => $this->year,
            'programme_cohort_membership_id' => $membershipId, 'entry_curriculum_stage_id' => $this->stage,
            'ended_at' => null, 'reason' => null, 'assigned_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // A. The exact page that 500'd must now return 200.
    public function test_retired_detail_redirects_to_direct_placement_without_deleting_history(): void
    {
        $cohort = $this->cohort();
        $this->actingAs($this->admin)->get(route('admin.programme_cohorts.show', $cohort->id))
            ->assertRedirect(route('admin.academic.direct_placement'));
        $this->assertDatabaseHas('programme_cohorts', ['id' => $cohort->id]);
    }

    // B. Memberships render.
    public function test_retirement_preserves_existing_memberships(): void
    {
        $cohort = $this->cohort();
        $membership = $this->addMember($cohort, ['name' => 'Kyeyune Amos', 'email' => 'kyeyune@example.test']);
        $this->actingAs($this->admin)->get(route('admin.programme_cohorts.show', $cohort->id))
            ->assertRedirect(route('admin.academic.direct_placement'));
        $this->assertDatabaseHas('programme_cohort_memberships', ['id' => $membership]);
    }

    // C. Academic placement information renders, and the banner reflects it.
    public function test_retired_group_placement_redirects_to_direct_placement(): void
    {
        $cohort = $this->cohort();
        $this->actingAs($this->admin)->get(route('admin.programme_cohorts.placement.index', $cohort->id))
            ->assertRedirect(route('admin.academic.direct_placement'));
    }

    // D. Optional / null related data must not 500.
    public function test_all_retired_lifecycle_states_redirect_safely(): void
    {
        foreach (['draft', 'active', 'completed', 'cancelled'] as $status) {
            $cohort = $this->cohort(['code' => 'RETIRED-'.$status, 'status' => $status]);
            $this->actingAs($this->admin)->get(route('admin.programme_cohorts.show', $cohort->id))
                ->assertRedirect(route('admin.academic.direct_placement'));
        }
    }

    // E. Cross-tenant isolation.
    public function test_a_cross_tenant_cohort_cannot_be_opened(): void
    {
        $cohort = $this->cohort();
        DB::table('programme_cohorts')->where('id', $cohort->id)->update(['school_id' => $this->otherSchool]);
        $this->actingAs($this->admin)->get(route('admin.programme_cohorts.show', $cohort->id))
            ->assertRedirect(route('admin.academic.direct_placement'))->assertDontSee($cohort->name);
        $this->assertDatabaseHas('programme_cohorts', ['id' => $cohort->id, 'school_id' => $this->otherSchool]);
    }

    // F. Authorisation is unchanged.
    public function test_unauthorised_users_cannot_open_the_admin_cohort_detail_page(): void
    {
        $cohort = $this->cohort();

        $teacher = User::factory()->create([
            'name' => 'Plain Teacher', 'email' => 'plain.teacher@example.test', 'role_id' => 3,
            'school_id' => $this->school, 'account_status' => 'active',
        ]);
        $this->actingAs($teacher)
            ->get(route('admin.programme_cohorts.show', $cohort->id))
            ->assertForbidden();

        $student = User::factory()->create([
            'name' => 'Plain Student', 'email' => 'plain.student@example.test', 'role_id' => 7,
            'school_id' => $this->school, 'account_status' => 'active',
        ]);
        $this->actingAs($student)
            ->get(route('admin.programme_cohorts.show', $cohort->id))
            ->assertRedirect();
    }

    // G. The list still works.
    public function test_retired_cohort_list_redirects_to_direct_placement(): void
    {
        $this->cohort();
        $this->actingAs($this->admin)->get(route('admin.programme_cohorts.index'))
            ->assertRedirect(route('admin.academic.direct_placement'));
    }

    public function test_retired_mutations_leave_history_unchanged(): void
    {
        $cohort = $this->cohort();
        $this->actingAs($this->admin)->post(route('admin.programme_cohorts.lifecycle', $cohort->id), ['status' => 'completed'])->assertStatus(410);
        $this->post(route('admin.programme_cohorts.members.store', $cohort->id), ['student_id' => 999])->assertStatus(410);
        $this->assertDatabaseHas('programme_cohorts', ['id' => $cohort->id, 'status' => 'active']);
        $this->assertSame(0, DB::table('programme_cohort_memberships')->count());
    }

    private function makeProgramme(int $schoolId, string $code, string $name): int
    {
        return (int) DB::table('programmes')->insertGetId([
            'school_id' => $schoolId, 'code' => $code, 'name' => $name,
            'level' => 'Bachelors', 'duration' => '4 Years', 'mode' => 'Full Time',
            'tuition_fee' => 0, 'department_id' => null, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The cohort / curriculum / placement tables the detail page reads. */
    private function cohortSchema(): void
    {
        if (!Schema::hasTable('academic_years')) {
            Schema::create('academic_years', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->string('label');
                $t->date('start_date'); $t->date('end_date'); $t->string('status');
                $t->timestamps(); $t->unique(['school_id', 'id']);
            });
        }
        if (!Schema::hasTable('curricula')) {
            Schema::create('curricula', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('programme_id');
                $t->string('version'); $t->unsignedBigInteger('effective_academic_year_id')->nullable();
                $t->string('status'); $t->timestamps();
                $t->unique(['school_id', 'programme_id', 'id']);
            });
        }
        if (!Schema::hasTable('curriculum_stages')) {
            Schema::create('curriculum_stages', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id');
                $t->string('label'); $t->unsignedSmallInteger('sequence'); $t->timestamps();
                $t->unique(['school_id', 'curriculum_id', 'id']);
            });
        }
        if (!Schema::hasTable('curriculum_memberships')) {
            Schema::create('curriculum_memberships', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id');
                $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('curriculum_stage_id');
                $t->string('period_type')->nullable(); $t->unsignedSmallInteger('period_sequence')->nullable();
                $t->string('classification'); $t->decimal('credits', 6, 2)->default(0);
                $t->unsignedSmallInteger('sequence')->default(0); $t->timestamps();
            });
        }
        if (!Schema::hasTable('programme_cohorts')) {
            Schema::create('programme_cohorts', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('programme_id');
                $t->unsignedBigInteger('intake_session_id'); $t->unsignedBigInteger('entry_academic_year_id');
                $t->unsignedBigInteger('curriculum_id'); $t->string('name'); $t->string('code');
                $t->string('status'); $t->date('expected_completion_date')->nullable();
                $t->unsignedBigInteger('created_by'); $t->timestamps();
                $t->unique(['school_id', 'code']); $t->unique(['school_id', 'id']);
            });
        }
        if (!Schema::hasTable('programme_cohort_memberships')) {
            Schema::create('programme_cohort_memberships', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('student_id');
                $t->unsignedBigInteger('programme_cohort_id'); $t->unsignedBigInteger('admission_id')->nullable();
                $t->string('admission_reference', 30)->nullable(); $t->string('status');
                $t->dateTime('started_at'); $t->dateTime('ended_at')->nullable(); $t->string('reason')->nullable();
                $t->unsignedBigInteger('assigned_by'); $t->unsignedBigInteger('active_student_id')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('student_curriculum_assignments')) {
            Schema::create('student_curriculum_assignments', function (Blueprint $t): void {
                $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('student_id');
                $t->unsignedBigInteger('programme_id'); $t->unsignedBigInteger('curriculum_id');
                $t->unsignedBigInteger('entry_academic_year_id'); $t->unsignedBigInteger('effective_from_academic_year_id');
                $t->unsignedBigInteger('programme_cohort_membership_id')->nullable();
                $t->unsignedBigInteger('entry_curriculum_stage_id')->nullable();
                $t->timestamp('ended_at')->nullable(); $t->string('reason')->nullable();
                $t->unsignedBigInteger('assigned_by'); $t->timestamps();
            });
        }
    }
}
