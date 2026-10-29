<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\User;
use App\Support\CourseOffering\CourseOfferingLecturerAllocationService;
use App\Support\CourseOffering\CourseOfferingService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseOfferingLecturerAllocationFoundationTest extends TestCase
{
    private CourseOfferingLecturerAllocationService $allocations;

    private array $offerings;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->schema();
        $this->allocations = new CourseOfferingLecturerAllocationService();

        foreach ([1, 2] as $schoolId) {
            DB::table('schools')->insert(['id' => $schoolId]);
            DB::table('academic_years')->insert([
                'id' => $schoolId * 10,
                'school_id' => $schoolId,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
            ]);
            DB::table('academic_periods')->insert([
                'id' => $schoolId * 100,
                'school_id' => $schoolId,
                'academic_year_id' => $schoolId * 10,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
            ]);
            DB::table('course_offerings')->insert([
                'id' => $schoolId * 1000,
                'school_id' => $schoolId,
                'subject_id' => $schoolId * 100,
                'academic_year_id' => $schoolId * 10,
                'academic_period_id' => $schoolId * 100,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->offerings = [1 => 1000, 2 => 2000];
        $this->actingAs($this->user(1, 1, ['role_id' => '2']));
    }

    public function test_only_unused_planned_lecturer_assignments_can_be_deleted(): void
    {
        $lecturer = $this->user(1, 91);
        $planned = $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', '2026-01-01');
        $this->allocations->deletePlanned(1, $planned->id);
        $this->assertDatabaseMissing('course_offering_lecturer_allocations', ['id' => $planned->id]);
        $active = $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', '2026-01-01');
        DB::table('course_offerings')->where('id', $this->offerings[1])->update(['status' => 'open']);
        $this->allocations->activate(1, $active->id);
        $this->assertDomainFailure(fn () => $this->allocations->deletePlanned(1, $active->id));
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $active->id, 'status' => 'active']);
    }

    public function test_assignment_automatically_activates_for_an_operational_course(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-06-15'));
        DB::table('course_offerings')->where('id', $this->offerings[1])->update(['status' => 'open']);
        $lecturer = $this->user(1, 501);
        $allocation = $this->allocations->assign(1, $this->offerings[1], $lecturer->id, 'primary_lecturer', '2026-01-01');
        $this->assertSame('active', $allocation->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'COURSE_OFFERING_LECTURER_ALLOCATION_ACTIVATED']);
        $this->assertDomainFailure(fn () => $this->allocations->assign(2, $this->offerings[2], $lecturer->id, 'co_lecturer', '2026-01-01'));
    }

    public function test_automatic_activation_defers_draft_courses_on_leave_staff_and_expired_assignments(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-06-15'));
        $draft = $this->allocations->assign(1, $this->offerings[1], $this->user(1, 502)->id, 'co_lecturer', '2026-01-01');
        $this->assertSame('planned', $draft->status);
        DB::table('course_offerings')->where('id', $this->offerings[1])->update(['status' => 'open']);
        $leave = $this->allocations->assign(1, $this->offerings[1], $this->user(1, 503, ['staff_status' => 'on_leave'])->id, 'co_lecturer', '2026-01-01');
        $expired = $this->allocations->assign(1, $this->offerings[1], $this->user(1, 504)->id, 'co_lecturer', '2026-01-01', '2026-01-02');
        $this->assertSame('planned', $leave->status);
        $this->assertSame('planned', $expired->status);
        $this->assertFalse($this->allocations->canActivateAutomatically(1, $leave->id));
        $this->assertFalse($this->allocations->canActivateAutomatically(1, $expired->id));
        $this->artisan('academic:activate-teaching-allocations', ['--school' => 1])->assertSuccessful();
        $this->assertSame('active', $draft->fresh()->status, 'Opening the course makes its existing plan eligible automatically');
        $this->assertSame('planned', $leave->fresh()->status);
        $this->assertSame('planned', $expired->fresh()->status);
    }

    public function test_scheduled_activation_repairs_existing_plans_with_tenant_scope_dry_run_and_idempotence(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-06-15'));
        foreach ($this->offerings as $id) {
            DB::table('course_offerings')->where('id', $id)->update(['status' => 'open']);
        }
        $one = $this->allocations->createPlanned(1, $this->offerings[1], $this->user(1, 505)->id, 'co_lecturer', '2026-01-01');
        $two = $this->allocations->createPlanned(2, $this->offerings[2], $this->user(2, 506)->id, 'co_lecturer', '2026-01-01');
        $auditCount = DB::table('audit_logs')->count();
        $this->artisan('academic:activate-teaching-allocations', ['--school' => 1, '--dry-run' => true])->assertSuccessful();
        $this->assertSame('planned', $one->fresh()->status);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->artisan('academic:activate-teaching-allocations', ['--school' => 1])->assertSuccessful();
        $this->assertSame('active', $one->fresh()->status);
        $this->assertSame('planned', $two->fresh()->status);
        $auditCount = DB::table('audit_logs')->count();
        $this->artisan('academic:activate-teaching-allocations', ['--school' => 1])->assertSuccessful();
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_same_tenant_teacher_can_receive_each_controlled_role_and_profile_is_optional(): void
    {
        foreach (CourseOfferingLecturerAllocation::ROLES as $index => $role) {
            $lecturer = $this->user(1, 1);
            $allocation = $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, $role, '2026-01-10', '2026-06-30');
            $this->assertSame($role, $allocation->role);
            $this->assertSame('planned', $allocation->status);
        }

        $this->assertSame(count(CourseOfferingLecturerAllocation::ROLES), DB::table('course_offering_lecturer_allocations')->count());
        $this->assertSame(count(CourseOfferingLecturerAllocation::ROLES), $this->offeringsModel(1)->lecturerAllocations()->count());
    }

    public function test_cross_tenant_null_school_central_and_non_teacher_users_are_rejected(): void
    {
        $foreign = $this->user(2, 2);
        $central = $this->user(null, 1);
        $student = $this->user(1, 1, ['role_id' => '7']);

        foreach ([$foreign, $central, $student] as $user) {
            $this->assertDomainFailure(fn () => $this->allocations->createPlanned(1, $this->offerings[1], (int) $user->id, 'co_lecturer', '2026-01-01'));
        }
        $this->assertSame(0, DB::table('course_offering_lecturer_allocations')->count());
    }

    public function test_disabled_and_blocked_staff_are_rejected_but_legacy_null_status_is_accepted(): void
    {
        foreach ([['disable', 'active'], ['active', 'suspended'], ['active', 'inactive'], ['active', 'terminated']] as [$account, $staffStatus]) {
            $user = $this->user(1, 1, ['account_status' => $account, 'staff_status' => $staffStatus]);
            $this->assertDomainFailure(fn () => $this->allocations->createPlanned(1, $this->offerings[1], (int) $user->id, 'co_lecturer', '2026-01-01'));
        }

        $legacy = $this->user(1, 1, ['staff_status' => null]);
        $allocation = $this->allocations->createPlanned(1, $this->offerings[1], (int) $legacy->id, 'co_lecturer', '2026-01-01');
        $this->assertSame('planned', $allocation->status);
    }

    public function test_on_leave_user_may_be_planned_but_cannot_be_activated(): void
    {
        $lecturer = $this->user(1, 1, ['staff_status' => 'on_leave']);
        $allocation = $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'primary_lecturer', '2026-01-01');
        DB::table('course_offerings')->where('id', $this->offerings[1])->update(['status' => 'open']);

        $this->assertDomainFailure(fn () => $this->allocations->activate(1, (int) $allocation->id));
        $this->assertSame('planned', $allocation->fresh()->status);
    }

    public function test_unknown_role_and_invalid_date_ranges_are_rejected(): void
    {
        $lecturer = $this->user(1, 1);
        $this->assertDomainFailure(fn () => $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'observer', '2026-01-01'));
        $this->assertDomainFailure(fn () => $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', '2026-04-01', '2026-03-31'));
        $this->assertDomainFailure(fn () => $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', 'not-a-date'));
    }

    public function test_allocation_dates_must_fit_academic_period_bounds(): void
    {
        $lecturer = $this->user(1, 1);
        $this->assertDomainFailure(fn () => $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', '2025-12-31'));
        $this->assertDomainFailure(fn () => $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', '2026-01-01', '2027-01-01'));

        DB::table('academic_periods')->where('id', 100)->update(['end_date' => null]);
        $this->assertDomainFailure(fn () => $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', '2026-01-01'));
    }

    public function test_different_lecturers_can_teach_concurrently_and_multiple_co_lecturers_are_allowed(): void
    {
        $primary = $this->user(1, 1);
        $coOne = $this->user(1, 1);
        $coTwo = $this->user(1, 1);

        $this->allocations->createPlanned(1, $this->offerings[1], (int) $primary->id, 'primary_lecturer', '2026-01-01', '2026-06-30');
        $this->allocations->createPlanned(1, $this->offerings[1], (int) $coOne->id, 'co_lecturer', '2026-01-01', '2026-06-30');
        $this->allocations->createPlanned(1, $this->offerings[1], (int) $coTwo->id, 'co_lecturer', '2026-01-01', '2026-06-30');

        $this->assertSame(3, DB::table('course_offering_lecturer_allocations')->count());
    }


    public function test_one_lecturer_can_be_primary_on_different_allocations_with_overlapping_dates(): void
    {
        $lecturer = $this->user(1, 77);
        $second = (array) DB::table('course_offerings')->where('id', $this->offerings[1])->first();
        $second['id'] = 1001;
        DB::table('course_offerings')->insert($second);
        DB::table('course_offerings')->whereIn('id', [$this->offerings[1], 1001])->update(['status' => 'open']);
        foreach ([$this->offerings[1], 1001] as $offeringId) {
            $assignment = $this->allocations->createPlanned(1, $offeringId, (int) $lecturer->id, 'primary_lecturer', '2026-01-01', '2026-06-30');
            $this->allocations->activate(1, $assignment->id);
        }
        $this->assertSame(2, DB::table('course_offering_lecturer_allocations')->where('user_id', $lecturer->id)->where('status', 'active')->count());
    }

    public function test_overlapping_primary_and_same_user_allocations_are_rejected_inclusively(): void
    {
        $first = $this->user(1, 1);
        $second = $this->user(1, 1);
        $this->allocations->createPlanned(1, $this->offerings[1], (int) $first->id, 'primary_lecturer', '2026-01-10', '2026-03-16');

        $this->assertDomainFailure(fn () => $this->allocations->createPlanned(1, $this->offerings[1], (int) $second->id, 'primary_lecturer', '2026-03-16', '2026-06-30'));
        $this->assertDomainFailure(fn () => $this->allocations->createPlanned(1, $this->offerings[1], (int) $first->id, 'lab_instructor', '2026-03-01', '2026-04-01'));
    }

    public function test_non_overlapping_primary_replacement_preserves_old_row_and_creates_planned_successor(): void
    {
        $first = $this->user(1, 1);
        $second = $this->user(1, 1);
        DB::table('course_offerings')->where('id', $this->offerings[1])->update(['status' => 'in_progress']);
        $old = $this->allocations->createPlanned(1, $this->offerings[1], (int) $first->id, 'primary_lecturer', '2026-01-10', '2026-12-31');
        $this->allocations->activate(1, (int) $old->id);

        $replacement = $this->allocations->replace(1, (int) $old->id, (int) $second->id, 'primary_lecturer', '2026-03-15', '2026-03-16', '2026-06-30');

        $this->assertSame('ended', $old->fresh()->status);
        $this->assertSame('2026-03-15', $old->fresh()->ends_on->format('Y-m-d'));
        $this->assertSame('planned', $replacement->status);
        $this->assertSame((int) $second->id, (int) $replacement->user_id);
        $this->assertSame(2, DB::table('course_offering_lecturer_allocations')->count());
    }

    public function test_planned_update_revalidates_identity_and_overlap_and_active_rows_are_not_editable(): void
    {
        $first = $this->user(1, 1);
        $second = $this->user(1, 1);
        $allocation = $this->allocations->createPlanned(1, $this->offerings[1], (int) $first->id, 'co_lecturer', '2026-01-01');
        $updated = $this->allocations->updatePlanned(1, (int) $allocation->id, ['user_id' => $second->id, 'role' => 'lab_instructor']);
        $this->assertSame((int) $second->id, (int) $updated->user_id);
        $this->assertSame('lab_instructor', $updated->role);

        DB::table('course_offerings')->where('id', $this->offerings[1])->update(['status' => 'open']);
        $this->allocations->activate(1, (int) $updated->id);
        $this->assertDomainFailure(fn () => $this->allocations->updatePlanned(1, (int) $updated->id, ['role' => 'co_lecturer']));
    }

    public function test_status_transitions_are_explicit_and_terminal_states_cannot_be_reopened(): void
    {
        $lecturer = $this->user(1, 1);
        $plannedCancel = $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', '2026-01-01');
        $this->assertSame('cancelled', $this->allocations->cancel(1, (int) $plannedCancel->id, 'No longer required')->status);
        $this->assertDomainFailure(fn () => $this->allocations->activate(1, (int) $plannedCancel->id));

        $activeLecturer = $this->user(1, 2);
        $active = $this->allocations->createPlanned(1, $this->offerings[1], (int) $activeLecturer->id, 'co_lecturer', '2026-01-01');
        DB::table('course_offerings')->where('id', $this->offerings[1])->update(['status' => 'open']);
        $this->assertSame('active', $this->allocations->activate(1, (int) $active->id)->status);
        $ended = $this->allocations->end(1, (int) $active->id, '2026-03-31');
        $this->assertSame('ended', $ended->status);
        $this->assertSame('2026-03-31', $ended->ends_on->format('Y-m-d'));
        $this->assertDomainFailure(fn () => $this->allocations->cancel(1, (int) $ended->id, 'Too late'));
        $this->assertDomainFailure(fn () => $this->allocations->activate(1, (int) $ended->id));

        $cancelLecturer = $this->user(1, 3);
        $activeCancel = $this->allocations->createPlanned(1, $this->offerings[1], (int) $cancelLecturer->id, 'lab_instructor', '2026-01-01');
        $this->allocations->activate(1, (int) $activeCancel->id);
        $cancelled = $this->allocations->cancel(1, (int) $activeCancel->id, 'Teaching plan changed');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNull($cancelled->ends_on);
        $this->assertDomainFailure(fn () => $this->allocations->end(1, (int) $cancelled->id, '2026-03-31'));
    }

    public function test_completed_and_cancelled_offerings_reject_ordinary_allocation_mutations(): void
    {
        $lecturer = $this->user(1, 1);
        foreach (['completed', 'cancelled'] as $status) {
            DB::table('course_offerings')->where('id', $this->offerings[1])->update(['status' => $status]);
            $this->assertDomainFailure(fn () => $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', '2026-01-01'));
            DB::table('course_offerings')->where('id', $this->offerings[1])->update(['status' => 'draft']);
        }
    }

    public function test_offering_can_open_without_any_lecturer_allocation(): void
    {
        $this->insertApplicableMembership();
        $offering = app(CourseOfferingService::class)->open(1, $this->offerings[1]);
        $this->assertSame('open', $offering->status);
        $this->assertSame(0, DB::table('course_offering_lecturer_allocations')->count());
    }

    public function test_all_mutation_paths_write_existing_audit_records(): void
    {
        $lecturer = $this->user(1, 1);
        $otherLecturer = $this->user(1, 1);
        $allocation = $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', '2026-01-01');
        $this->allocations->updatePlanned(1, (int) $allocation->id, ['role' => 'lab_instructor']);
        DB::table('course_offerings')->where('id', $this->offerings[1])->update(['status' => 'in_progress']);
        $this->allocations->activate(1, (int) $allocation->id);
        $this->allocations->end(1, (int) $allocation->id, '2026-03-15');

        $replaceable = $this->allocations->createPlanned(1, $this->offerings[1], (int) $otherLecturer->id, 'primary_lecturer', '2026-01-01', '2026-12-31');
        $this->allocations->activate(1, (int) $replaceable->id);
        $replacementLecturer = $this->user(1, 4);
        $this->allocations->replace(1, (int) $replaceable->id, (int) $replacementLecturer->id, 'primary_lecturer', '2026-03-15', '2026-03-16');
        $cancelled = $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', '2026-03-16');
        $this->allocations->cancel(1, (int) $cancelled->id, 'No longer required');

        $actions = DB::table('audit_logs')->pluck('action')->all();
        foreach ([
            'COURSE_OFFERING_LECTURER_ALLOCATION_CREATED',
            'COURSE_OFFERING_LECTURER_ALLOCATION_UPDATED',
            'COURSE_OFFERING_LECTURER_ALLOCATION_ACTIVATED',
            'COURSE_OFFERING_LECTURER_ALLOCATION_ENDED',
            'COURSE_OFFERING_LECTURER_ALLOCATION_CANCELLED',
            'COURSE_OFFERING_LECTURER_REPLACED',
        ] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function test_model_blocks_direct_create_update_and_delete_and_compatibility_tables_are_untouched(): void
    {
        Schema::create('teacher_permissions', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('teacher_id'); $table->unsignedBigInteger('school_id'); });
        Schema::create('teacher_programme_assignments', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('teacher_id'); $table->unsignedBigInteger('programme_id'); $table->unsignedBigInteger('school_id'); });
        Schema::create('course_registrations', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('session_id')->nullable(); });
        DB::table('teacher_permissions')->insert(['teacher_id' => 1, 'school_id' => 1]);
        DB::table('teacher_programme_assignments')->insert(['teacher_id' => 1, 'programme_id' => 1, 'school_id' => 1]);
        DB::table('course_registrations')->insert(['student_id' => 1, 'subject_id' => 1, 'session_id' => 1]);

        $lecturer = $this->user(1, 1);
        try {
            CourseOfferingLecturerAllocation::create([
            'school_id' => 1, 'course_offering_id' => $this->offerings[1], 'user_id' => $lecturer->id,
            'role' => 'co_lecturer', 'starts_on' => '2026-01-01', 'status' => 'planned',
            ]);
            $this->fail('Direct allocation creation should be blocked.');
        } catch (MassAssignmentException) {
            $this->assertTrue(true);
        }

        $allocation = $this->allocations->createPlanned(1, $this->offerings[1], (int) $lecturer->id, 'co_lecturer', '2026-01-01');
        try {
            $allocation->forceFill(['role' => 'lab_instructor'])->save();
            $this->fail('Direct allocation updates should be blocked.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
        try {
            $allocation->delete();
            $this->fail('Direct allocation deletion should be blocked.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, DB::table('teacher_permissions')->count());
        $this->assertSame(1, DB::table('teacher_programme_assignments')->count());
        $this->assertSame(1, DB::table('course_registrations')->count());
    }

    private function schema(): void
    {
        Schema::create('schools', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); });
        Schema::create('users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->default('test');
            $table->string('role_id')->nullable();
            $table->integer('school_id')->nullable();
            $table->string('account_status', 20)->default('active');
            $table->string('staff_status', 20)->nullable();
            $table->timestamps();
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('school_id');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
        });
        Schema::create('academic_periods', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->string('type')->default('semester');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('academic_period_id');
            $table->string('reference', 50)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
        });
        Schema::create('curricula', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('effective_academic_year_id')->nullable();
            $table->string('status');
        });
        Schema::create('curriculum_memberships', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('subject_id');
            $table->string('period_type')->nullable();
            $table->unsignedSmallInteger('period_sequence')->nullable();
        });
        Schema::create('course_offering_curriculum_memberships', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('course_offering_id');
            $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('curriculum_membership_id');
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->string('role_id')->nullable();
            $table->string('role_name')->nullable();
            $table->string('action');
            $table->string('event_type')->nullable();
            $table->string('module');
            $table->string('route_name')->nullable();
            $table->text('url')->nullable();
            $table->string('method')->nullable();
            $table->text('description');
            $table->string('record_type')->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->longText('old_values')->nullable();
            $table->longText('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_type')->nullable();
            $table->string('browser')->nullable();
            $table->string('platform')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('course_offering_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role', 32);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 16)->default('planned');
            $table->timestamps();
        });
    }

    private function user(?int $schoolId, int $sequence, array $overrides = []): User
    {
        $id = DB::table('users')->insertGetId($overrides + [
            'name' => 'User '.$sequence.'-'.uniqid(),
            'email' => 'user'.$sequence.'-'.uniqid().'@example.test',
            'role_id' => '3',
            'school_id' => $schoolId,
            'account_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    private function offeringsModel(int $schoolId): CourseOffering
    {
        return CourseOffering::where('school_id', $schoolId)->firstOrFail();
    }

    private function insertApplicableMembership(): void
    {
        DB::table('curricula')->insert([
            'id' => 1, 'school_id' => 1, 'effective_academic_year_id' => 10, 'status' => 'approved',
        ]);
        DB::table('curriculum_memberships')->insert([
            'id' => 1, 'school_id' => 1, 'curriculum_id' => 1, 'subject_id' => 100, 'period_type' => 'semester', 'period_sequence' => 1,
        ]);
        DB::table('course_offering_curriculum_memberships')->insert([
            'school_id' => 1, 'course_offering_id' => $this->offerings[1], 'curriculum_id' => 1, 'curriculum_membership_id' => 1,
        ]);
    }

    private function assertDomainFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a domain validation failure.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }
    }
}
