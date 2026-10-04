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

/**
 * HTTP regression for GET /admin/programme-cohorts/{id}.
 *
 * That page returned 500 for every visitor. The cause was a Blade COMPILE
 * error, not the data: "eligible students@if(...)" in
 * admin/programme_cohorts/show.blade.php. Blade's statement pattern begins with
 * \B@, so a directive glued to a preceding word character is emitted as literal
 * text, while its @endif still compiles. The surplus endif closed the enclosing
 * @if early and orphaned the next @elseif, so the compiled template was invalid
 * PHP and the page failed to render no matter what the cohort contained.
 *
 * These tests exercise the page end to end so the same class of break is caught
 * here, and they pin the tenant, authorisation and academic-placement behaviour
 * the page depends on. BladeTemplateIntegrityTest covers the compile step itself.
 */
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
    public function test_authorised_admin_can_open_a_programme_cohort_detail_page(): void
    {
        $cohort = $this->cohort();

        $this->actingAs($this->admin)
            ->get(route('admin.programme_cohorts.show', $cohort->id))
            ->assertOk()
            ->assertSee('BBIT September 2026 Cohort')
            ->assertSee('BBIT-SEP-2026')
            ->assertSee('BBIT')
            ->assertSee('2026 September Intake')
            ->assertSee('2026/2027')
            ->assertSee('2026-V1');
    }

    // B. Memberships render.
    public function test_existing_cohort_memberships_render(): void
    {
        $cohort = $this->cohort();
        $membership = $this->addMember($cohort, ['name' => 'Kyeyune Amos', 'email' => 'kyeyune@example.test']);

        $this->actingAs($this->admin)
            ->get(route('admin.programme_cohorts.show', $cohort->id))
            ->assertOk()
            ->assertSee('Kyeyune Amos')
            ->assertSee('Students / Members')
            ->assertViewHas('memberships', fn ($paginator) => $paginator->total() === 1);

        $this->assertSame(1, DB::table('programme_cohort_memberships')->where('id', $membership)->count());
    }

    // C. Academic placement information renders, and the banner reflects it.
    public function test_academic_placement_information_renders(): void
    {
        $cohort = $this->cohort();
        $student = User::factory()->create([
            'name' => 'Placed Student', 'email' => 'placed@example.test', 'role_id' => 7,
            'school_id' => $this->school, 'account_status' => 'active',
        ]);
        DB::table('student_profiles')->insert([
            'user_id' => $student->id, 'school_id' => $this->school, 'programme_id' => $this->programme,
            'intake_session_id' => $this->intake, 'year_of_study' => 1, 'status' => 'active',
        ]);
        $membership = DB::table('programme_cohort_memberships')->insertGetId([
            'school_id' => $this->school, 'student_id' => $student->id, 'programme_cohort_id' => $cohort->id,
            'admission_id' => null, 'admission_reference' => null, 'status' => 'active',
            'started_at' => now(), 'ended_at' => null, 'assigned_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->place($cohort, $membership, (int) $student->id);

        $this->actingAs($this->admin)
            ->get(route('admin.programme_cohorts.show', $cohort->id))
            ->assertOk()
            ->assertSee('All current students have Academic Placement recorded.')
            ->assertDontSee('Academic Placement Pending');
    }

    // D. Optional / null related data must not 500.
    public function test_optional_and_null_related_data_does_not_produce_a_500(): void
    {
        $cohort = $this->cohort([
            'expected_completion_date' => null,   // no expected completion
            'created_by' => $this->admin->id,
        ]);
        // A member with no Academic Placement, no admission provenance, and a
        // null year of study: every optional value the view touches is absent.
        $student = User::factory()->create([
            'name' => 'Bare Minimum Student', 'email' => 'bare@example.test', 'role_id' => 7,
            'school_id' => $this->school, 'account_status' => 'active',
        ]);
        DB::table('student_profiles')->insert([
            'user_id' => $student->id, 'school_id' => $this->school, 'programme_id' => null,
            'intake_session_id' => null, 'year_of_study' => null, 'status' => 'active',
        ]);
        DB::table('programme_cohort_memberships')->insert([
            'school_id' => $this->school, 'student_id' => $student->id, 'programme_cohort_id' => $cohort->id,
            'admission_id' => null, 'admission_reference' => null, 'status' => 'active',
            'started_at' => now(), 'ended_at' => null, 'assigned_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.programme_cohorts.show', $cohort->id))
            ->assertOk()
            // The pending-placement state is stated in words, not crashed on.
            ->assertSee('Academic Placement Pending')
            ->assertSee('Bare Minimum Student');

        // And a draft cohort, which renders a different branch of the same chain.
        $draft = $this->cohort(['code' => 'BBIT-DRAFT', 'status' => 'draft']);
        $this->actingAs($this->admin)
            ->get(route('admin.programme_cohorts.show', $draft->id))
            ->assertOk()
            ->assertSee('A draft is not yet operational.');
    }

    // E. Cross-tenant isolation.
    public function test_a_cross_tenant_cohort_cannot_be_opened(): void
    {
        $foreignProgramme = $this->makeProgramme($this->otherSchool, 'P2', 'Other Programme');
        $foreign = DB::table('programme_cohorts')->insertGetId([
            'school_id' => $this->otherSchool, 'programme_id' => $foreignProgramme,
            'intake_session_id' => $this->intake, 'entry_academic_year_id' => $this->year,
            'curriculum_id' => $this->plan, 'name' => 'Foreign Cohort', 'code' => 'FOR-1',
            'status' => 'active', 'expected_completion_date' => null, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.programme_cohorts.show', $foreign))
            ->assertNotFound();

        $this->assertSame(1, DB::table('programme_cohorts')->where('id', $foreign)->count(),
            'the foreign cohort is untouched');
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
    public function test_the_programme_cohort_list_still_works(): void
    {
        $cohort = $this->cohort();

        $this->actingAs($this->admin)
            ->get(route('admin.programme_cohorts.index'))
            ->assertOk()
            ->assertSee('BBIT September 2026 Cohort')
            ->assertSee('BBIT-SEP-2026');
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
