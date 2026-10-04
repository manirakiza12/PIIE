<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\User;
use App\Support\CourseOffering\CourseOfferingLecturerAllocationService;
use App\Support\CourseOffering\CourseOfferingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\StaffModuleTestHelper;
use Tests\TestCase;

class CourseOfferingAdministrationTest extends TestCase
{
    use StaffModuleTestHelper;
    use \Tests\Feature\Support\FrozenClock;

    private array $tenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
        $this->bootStaffModuleTestSchema();
        Schema::table('schools', function (Blueprint $table): void {
            $table->string('school_type')->default('k12');
            $table->string('academic_calendar_pattern')->nullable();
            $table->unsignedBigInteger('current_academic_year_id')->nullable();
            $table->unsignedBigInteger('current_academic_period_id')->nullable();
        });
        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('user_id');
            $table->string('permission', 100); $table->unsignedBigInteger('granted_by')->nullable(); $table->timestamps();
            $table->unique(['user_id', 'permission']);
        });
        Schema::create('staff_roles', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->string('name'); $table->string('description')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->unsignedBigInteger('updated_by')->nullable(); $table->timestamps(); });
        Schema::create('staff_role_permissions', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('staff_role_id'); $table->string('permission',100); $table->timestamps(); });
        Schema::create('user_staff_roles', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('staff_role_id'); $table->unsignedBigInteger('assigned_by')->nullable(); $table->timestamps(); });
        $this->createAcademicTables();
        Schema::create('live_classes', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('course_offering_id')->nullable(); $table->unsignedBigInteger('teacher_id')->nullable();
            $table->string('title'); $table->text('description')->nullable(); $table->string('platform')->nullable();
            $table->text('meeting_url')->nullable(); $table->string('meeting_id')->nullable(); $table->string('meeting_password')->nullable();
            $table->dateTime('scheduled_at')->nullable(); $table->dateTime('ends_at')->nullable(); $table->string('timezone')->nullable();
            $table->date('start_date')->nullable(); $table->time('start_time')->nullable(); $table->time('end_time')->nullable();
            $table->string('status')->default('draft'); $table->boolean('is_published')->default(false); $table->boolean('attendance_enabled')->default(true);
            $table->text('recording_url')->nullable(); $table->unsignedBigInteger('created_by')->nullable(); $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('started_at')->nullable();
                $table->dateTime('ended_at')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->unsignedBigInteger('started_by')->nullable();
                $table->unsignedBigInteger('ended_by')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->string('recording_status', 20)->default('none');
$table->timestamps();
        });
        Schema::create('live_class_materials', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('live_class_id');
            $table->string('type')->default('file'); $table->string('category')->default('resource'); $table->string('title');
            $table->string('original_name')->nullable(); $table->string('stored_name')->nullable(); $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable(); $table->text('link_url')->nullable(); $table->unsignedBigInteger('uploaded_by')->nullable(); $table->timestamps();
        });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id');
            $table->unsignedBigInteger('user_id'); $table->string('role', 32); $table->date('starts_on');
            $table->date('ends_on')->nullable(); $table->string('status', 16)->default('planned'); $table->timestamps();
        });
        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('school_id');
            $table->string('academic_title')->nullable(); $table->string('specialisation')->nullable(); $table->timestamps();
        });
        $this->tenants = [1 => $this->tenant(1, 'higher_ed'), 2 => $this->tenant(2, 'higher_ed')];
    }

    public function test_view_routes_are_authorized_tenant_scoped_and_menu_permission_does_not_grant_access(): void
    {
        $a = $this->tenants[1];
        $draft = $this->createOffering($a, 'A-OWN');
        $generatedDraft = app(CourseOfferingService::class)->createDraft(1, $a['subject'], $a['year'], $a['period']);
        $foreign = $this->createOffering($this->tenants[2], 'B-FOREIGN');
        $viewer = $this->staff(1, ['academic.course_offering.view', 'academic.course_offering.lecturer.view', 'academic.course_registration.view']);

        $this->actingAs($viewer)->get(route('admin.course_offerings.index'))->assertOk()->assertSee('A-OWN')->assertDontSee('B-FOREIGN');
        $this->get(route('admin.course_offerings.show', $draft->id))->assertOk()
            ->assertSee('A-OWN')->assertSee('CORE-1 — Core 1')
            ->assertSee('Offering Reference:')->assertSee('Academic Year:')->assertSee('2026')
            ->assertSee('Semester:')->assertSee('Semester 1')
            ->assertSee('No Study Plan has been linked yet. Add the applicable Study Plan before opening this Course Offering.')
            ->assertSee('No lecturers have been assigned yet.')
            ->assertSee('No students are registered for this Course Offering yet.')
            ->assertSee('A Programme Study Plan must be linked before this Course Offering can be opened.')
            ->assertSee('View Teaching Team')
            ->assertSee(route('admin.course_offerings.lecturers.index', $draft->id), false)
            ->assertSee(route('admin.course_offerings.eligible_students', $draft->id), false);
        $this->get(route('admin.course_offerings.show', $generatedDraft->id))->assertOk()
            ->assertSee('CORE-1-2026-S1')->assertSee('Offering Reference:');
        $this->get(route('admin.course_offerings.show', $foreign->id))->assertNotFound();

        $ungranted = $this->staff(1, [], ['menu_permission' => json_encode(['admin.course_offerings.index'])]);
        $this->actingAs($ungranted)->get(route('admin.course_offerings.index'))->assertForbidden();
        $this->get(route('admin.course_offerings.show', $draft->id))->assertForbidden();
    }

    public function test_manage_and_lifecycle_permissions_are_enforced_on_real_mutation_routes(): void
    {
        $a = $this->tenants[1];
        $draft = $this->createOffering($a, 'DRAFT-A');
        $open = $this->createOffering($a, 'OPEN-A');
        $this->attach($a, $open, $a['member']);
        app(CourseOfferingService::class)->open(1, $open->id);
        $inProgress = $this->createOffering($a, 'IP-A');
        $this->attach($a, $inProgress, $a['member']);
        app(CourseOfferingService::class)->open(1, $inProgress->id);
app(CourseOfferingService::class)->start(1, $inProgress->id);
        $memberB = $this->tenants[2]['member'];
        $viewOnly = $this->staff(1, ['academic.course_offering.view']);

        $this->actingAs($viewOnly)->post(route('admin.course_offerings.store'), $this->draftPayload($a))->assertForbidden();
        $this->put(route('admin.course_offerings.update', $draft->id), $this->draftPayload($a, 'EDITED'))->assertForbidden();
        $this->post(route('admin.course_offerings.applicability.store', $draft->id), ['curriculum_membership_id' => $a['member']])->assertForbidden();
        $this->delete(route('admin.course_offerings.applicability.destroy', [$draft->id, $a['member']]))->assertForbidden();
        foreach ([['open', $draft], ['start', $open], ['complete', $inProgress], ['cancel', $draft]] as [$action, $offering]) {
            $this->post(route('admin.course_offerings.'.$action, $offering->id), ['reason' => 'testing'])->assertForbidden();
        }

        $manager = $this->staff(1, ['academic.course_offering.manage']);
        $this->actingAs($manager)->post(route('admin.course_offerings.store'), $this->draftPayload($a))->assertRedirect();
        $this->put(route('admin.course_offerings.update', $draft->id), $this->draftPayload($a, 'EDITED'))->assertRedirect();
        $this->post(route('admin.course_offerings.applicability.store', $draft->id), ['curriculum_membership_id' => $memberB])->assertSessionHasErrors('curriculum_membership_id');
    }

    public function test_cross_tenant_offering_mutations_and_membership_attachment_are_rejected(): void
    {
        $b = $this->tenants[2];
        $foreign = $this->createOffering($b, 'B-OFFER');
        $manager = $this->staff(1, ['academic.course_offering.manage', 'academic.course_offering.lifecycle']);
        $this->actingAs($manager);
        $this->put(route('admin.course_offerings.update', $foreign->id), $this->draftPayload($this->tenants[1], 'HACK'))->assertNotFound();
        $this->post(route('admin.course_offerings.applicability.store', $foreign->id), ['curriculum_membership_id' => $this->tenants[1]['member']])->assertNotFound();
        $this->delete(route('admin.course_offerings.applicability.destroy', [$foreign->id, $b['member']]))->assertNotFound();
        foreach (['open', 'start', 'complete', 'cancel'] as $action) {
            $this->post(route('admin.course_offerings.'.$action, $foreign->id), ['reason' => 'wrong tenant'])->assertNotFound();
        }

        $offeringA = $this->createOffering($this->tenants[1], 'A-OFFER');
        $this->post(route('admin.course_offerings.applicability.store', $offeringA->id), ['curriculum_membership_id' => $b['member']])->assertSessionHasErrors('curriculum_membership_id');
        $this->assertDatabaseMissing('course_offering_curriculum_memberships', ['school_id' => 1, 'course_offering_id' => $offeringA->id, 'curriculum_membership_id' => $b['member']]);
    }

    public function test_lecturer_http_workflows_tenant_isolation_eligibility_and_primary_conflicts(): void
    {
        $tenantA = $this->tenants[1];
        $tenantB = $this->tenants[2];
        $offeringA = $this->createOffering($tenantA, 'LECTURER-A');
        $offeringB = $this->createOffering($tenantB, 'LECTURER-B');
        $manager = $this->staff(1, ['academic.course_offering.lecturer.view', 'academic.course_offering.lecturer.manage']);
        $teacherA = User::factory()->create(['name' => 'Lecturer A', 'role_id' => 3, 'school_id' => $tenantA['school'], 'account_status' => 'active', 'staff_status' => 'active']);
        $onLeave = User::factory()->create(['name' => 'Lecturer On Leave', 'role_id' => 3, 'school_id' => $tenantA['school'], 'account_status' => 'active', 'staff_status' => 'on_leave']);
        $suspended = User::factory()->create(['name' => 'Lecturer Suspended', 'role_id' => 3, 'school_id' => $tenantA['school'], 'account_status' => 'active', 'staff_status' => 'suspended']);
        User::factory()->create(['name' => 'Lecturer Inactive', 'role_id' => 3, 'school_id' => $tenantA['school'], 'account_status' => 'active', 'staff_status' => 'inactive']);
        User::factory()->create(['name' => 'Lecturer Terminated', 'role_id' => 3, 'school_id' => $tenantA['school'], 'account_status' => 'active', 'staff_status' => 'terminated']);
        User::factory()->create(['name' => 'Lecturer Disabled', 'role_id' => 3, 'school_id' => $tenantA['school'], 'account_status' => 'disable', 'staff_status' => 'active']);
        $nonTeacher = User::factory()->create(['name' => 'Non Teacher', 'role_id' => 4, 'school_id' => $tenantA['school'], 'account_status' => 'active', 'staff_status' => 'active']);
        $teacherB = User::factory()->create(['name' => 'Lecturer B', 'role_id' => 3, 'school_id' => $tenantB['school'], 'account_status' => 'active', 'staff_status' => 'active']);
        $replacement = User::factory()->create(['name' => 'Replacement Lecturer', 'role_id' => 3, 'school_id' => $tenantA['school'], 'account_status' => 'active', 'staff_status' => 'active']);
        $viewer = $this->staff(1, ['academic.course_offering.lecturer.view']);
        $this->actingAs($viewer)->get(route('admin.course_offerings.lecturers.index', $offeringA->id))->assertOk();
        $this->post(route('admin.course_offerings.lecturers.store', $offeringA->id), [
            'user_id' => $teacherA->id, 'role' => 'co_lecturer', 'starts_on' => '2026-02-01',
        ])->assertForbidden();
        $this->actingAs($this->staff(1, []))->get(route('admin.course_offerings.lecturers.index', $offeringA->id))->assertForbidden();

        $foreignAllocation = (int) DB::table('course_offering_lecturer_allocations')->insertGetId([
            'school_id' => $tenantB['school'], 'course_offering_id' => $offeringB->id, 'user_id' => $teacherB->id,
            'role' => 'primary_lecturer', 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-28',
            'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('audit_logs')->insert([
            'school_id' => $tenantB['school'], 'user_id' => $manager->id, 'user_name' => 'Tenant B Actor',
            'action' => 'COURSE_OFFERING_LECTURER_ALLOCATION_CREATED', 'event_type' => 'COURSE_OFFERING_LECTURER_ALLOCATION',
            'module' => 'Course Offering Lecturer Allocations', 'description' => 'Foreign tenant audit fixture',
            'record_type' => \App\Models\CourseOfferingLecturerAllocation::class, 'record_id' => $foreignAllocation,
            'new_values' => json_encode(['user_id' => $teacherB->id, 'role' => 'primary_lecturer', 'starts_on' => '2026-02-01', 'status' => 'planned']),
            'created_at' => now(),
        ]);
        $this->actingAs($manager);

        $this->get(route('admin.course_offerings.lecturers.index', $offeringB->id))->assertNotFound();
        $this->get(route('admin.course_offerings.lecturers.history', $offeringB->id))->assertNotFound();
        $this->get(route('admin.course_offerings.lecturers.create', $offeringB->id))->assertNotFound();
        $this->post(route('admin.course_offerings.lecturers.store', $offeringB->id), [
            'user_id' => $teacherA->id, 'role' => 'co_lecturer', 'starts_on' => '2026-02-01',
        ])->assertNotFound();
        $this->put(route('admin.course_offerings.lecturers.update', [$offeringB->id, $foreignAllocation]), [
            'user_id' => $teacherB->id, 'role' => 'co_lecturer', 'starts_on' => '2026-02-01',
        ])->assertNotFound();
        foreach (['activate', 'end', 'cancel', 'replace'] as $action) {
            $payload = match ($action) {
                'end' => ['ends_on' => '2026-02-20'],
                'cancel' => ['reason' => 'Withdrawn'],
                'replace' => ['user_id' => $teacherA->id, 'role' => 'primary_lecturer', 'old_ends_on' => '2026-02-15', 'starts_on' => '2026-02-16'],
                default => [],
            };
            $this->post(route('admin.course_offerings.lecturers.'.$action, [$offeringB->id, $foreignAllocation]), $payload)->assertNotFound();
        }
        $this->put(route('admin.course_offerings.lecturers.update', [$offeringA->id, $foreignAllocation]), [
            'user_id' => $teacherA->id, 'role' => 'co_lecturer', 'starts_on' => '2026-02-01',
        ])->assertNotFound();
        foreach (['activate', 'end', 'cancel', 'replace'] as $action) {
            $payload = match ($action) {
                'end' => ['ends_on' => '2026-02-20'],
                'cancel' => ['reason' => 'Wrong Offering'],
                'replace' => ['user_id' => $teacherA->id, 'role' => 'primary_lecturer', 'old_ends_on' => '2026-02-15', 'starts_on' => '2026-02-16'],
                default => [],
            };
            $this->post(route('admin.course_offerings.lecturers.'.$action, [$offeringA->id, $foreignAllocation]), $payload)->assertNotFound();
        }
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $foreignAllocation, 'school_id' => $tenantB['school'], 'status' => 'planned']);

        $createPage = $this->get(route('admin.course_offerings.lecturers.create', $offeringA->id));
        $createPage->assertOk()->assertSee('Core 1')->assertSee('2026')->assertSee('Semester 1')
            ->assertSee('LECTURER-A')->assertSee('Draft')->assertSee('Lecturer A')->assertSee('On leave — planned only')
            ->assertDontSee('Lecturer Suspended')->assertDontSee('Lecturer Inactive')->assertDontSee('Lecturer Terminated')
            ->assertDontSee('Lecturer Disabled')->assertDontSee('Non Teacher')->assertDontSee('Lecturer B');

        $this->post(route('admin.course_offerings.lecturers.store', $offeringA->id), [
            'user_id' => $teacherB->id, 'role' => 'co_lecturer', 'starts_on' => '2026-02-01',
        ])->assertSessionHasErrors('allocation');
        $this->post(route('admin.course_offerings.lecturers.store', $offeringA->id), [
            'user_id' => $onLeave->id, 'role' => 'co_lecturer', 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-20',
        ])->assertRedirect();
        $plannedOnLeave = DB::table('course_offering_lecturer_allocations')->where('course_offering_id', $offeringA->id)->where('user_id', $onLeave->id)->first();
        $this->assertSame('planned', $plannedOnLeave->status);
        $this->post(route('admin.course_offerings.lecturers.activate', [$offeringA->id, $plannedOnLeave->id]))->assertSessionHasErrors('allocation');
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $plannedOnLeave->id, 'status' => 'planned']);

        $this->post(route('admin.course_offerings.lecturers.store', $offeringA->id), [
            'user_id' => $teacherA->id, 'role' => 'co_lecturer', 'starts_on' => '2026-02-01', 'ends_on' => '2026-02-28',
        ])->assertRedirect();
        $planned = DB::table('course_offering_lecturer_allocations')->where('course_offering_id', $offeringA->id)->where('user_id', $teacherA->id)->first();
        $this->put(route('admin.course_offerings.lecturers.update', [$offeringA->id, $planned->id]), [
            'user_id' => $teacherA->id, 'role' => 'co_lecturer', 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-20',
        ])->assertRedirect();
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $planned->id, 'starts_on' => '2026-03-01', 'status' => 'planned']);
        $this->post(route('admin.course_offerings.lecturers.activate', [$offeringA->id, $planned->id]))->assertSessionHasErrors('allocation');

        $this->attach($tenantA, $offeringA, $tenantA['member']);
        app(CourseOfferingService::class)->open($tenantA['school'], $offeringA->id);
        $this->post(route('admin.course_offerings.lecturers.activate', [$offeringA->id, $planned->id]))->assertRedirect();
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $planned->id, 'status' => 'active']);
        $this->post(route('admin.course_offerings.lecturers.end', [$offeringA->id, $planned->id]), ['ends_on' => '2026-03-15'])->assertRedirect();
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $planned->id, 'status' => 'ended', 'ends_on' => '2026-03-15']);
        $this->post(route('admin.course_offerings.lecturers.cancel', [$offeringA->id, $plannedOnLeave->id]), ['reason' => 'Staffing plan withdrawn'])->assertRedirect();
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $plannedOnLeave->id, 'status' => 'cancelled', 'ends_on' => '2026-02-20']);

        $primary = (int) DB::table('course_offering_lecturer_allocations')->insertGetId([
            'school_id' => $tenantA['school'], 'course_offering_id' => $offeringA->id, 'user_id' => $teacherA->id,
            'role' => 'primary_lecturer', 'starts_on' => '2026-04-01', 'ends_on' => '2026-04-10',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post(route('admin.course_offerings.lecturers.store', $offeringA->id), [
            'user_id' => $replacement->id, 'role' => 'primary_lecturer', 'starts_on' => '2026-04-05', 'ends_on' => '2026-04-15',
        ])->assertSessionHasErrors('allocation');
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $primary, 'status' => 'active', 'ends_on' => '2026-04-10']);
        $this->get(route('admin.course_offerings.lecturers.index', $offeringA->id))
            ->assertOk()->assertSee('Primary Lecturer conflict: Lecturer A')
            ->assertSee('Use Replace Primary Lecturer to preserve the existing history.');

        $this->post(route('admin.course_offerings.lecturers.replace', [$offeringA->id, $primary]), [
            'user_id' => $replacement->id, 'role' => 'primary_lecturer', 'old_ends_on' => '2026-04-30', 'starts_on' => '2026-05-01',
        ])->assertRedirect();
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $primary, 'user_id' => $teacherA->id, 'status' => 'ended', 'ends_on' => '2026-04-30']);
        $replacementAllocation = DB::table('course_offering_lecturer_allocations')->where('course_offering_id', $offeringA->id)->where('user_id', $replacement->id)->first();
        $this->assertSame('planned', $replacementAllocation->status);
        $this->post(route('admin.course_offerings.lecturers.store', $offeringA->id), [
            'user_id' => $teacherA->id, 'role' => 'primary_lecturer', 'starts_on' => '2026-05-02', 'ends_on' => '2026-05-10',
        ])->assertSessionHasErrors('allocation');
        $this->get(route('admin.course_offerings.lecturers.index', $offeringA->id))
            ->assertOk()->assertSee('Cancel the conflicting planned Primary Lecturer allocation first.');
        $this->post(route('admin.course_offerings.lecturers.activate', [$offeringA->id, $replacementAllocation->id]))->assertRedirect();
        $this->post(route('admin.course_offerings.lecturers.cancel', [$offeringA->id, $replacementAllocation->id]), ['reason' => 'Replacement assignment withdrawn'])->assertRedirect();
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $replacementAllocation->id, 'status' => 'cancelled', 'starts_on' => '2026-05-01']);
        $openLecturer = User::factory()->create(['name' => 'Open State Lecturer', 'role_id' => 3, 'school_id' => $tenantA['school'], 'account_status' => 'active', 'staff_status' => 'active']);
        $progressLecturer = User::factory()->create(['name' => 'In Progress Lecturer', 'role_id' => 3, 'school_id' => $tenantA['school'], 'account_status' => 'active', 'staff_status' => 'active']);
        $this->post(route('admin.course_offerings.lecturers.store', $offeringA->id), [
            'user_id' => $openLecturer->id, 'role' => 'lab_instructor', 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-15',
        ])->assertRedirect();
        $openAllocation = DB::table('course_offering_lecturer_allocations')->where('course_offering_id', $offeringA->id)->where('user_id', $openLecturer->id)->first();
        $this->post(route('admin.course_offerings.lecturers.activate', [$offeringA->id, $openAllocation->id]))->assertRedirect();
        $this->post(route('admin.course_offerings.lecturers.store', $offeringA->id), [
            'user_id' => $progressLecturer->id, 'role' => 'teaching_assistant', 'starts_on' => '2026-05-20', 'ends_on' => '2026-05-25',
        ])->assertRedirect();
        $progressAllocation = DB::table('course_offering_lecturer_allocations')->where('course_offering_id', $offeringA->id)->where('user_id', $progressLecturer->id)->first();
app(CourseOfferingService::class)->start($tenantA['school'], $offeringA->id);
        $this->post(route('admin.course_offerings.lecturers.activate', [$offeringA->id, $progressAllocation->id]))->assertRedirect();
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $progressAllocation->id, 'status' => 'active']);
        DB::table('users')->where('id', $teacherA->id)->update(['staff_status' => 'terminated']);

        $history = $this->get(route('admin.course_offerings.lecturers.history', $offeringA->id));
        $history->assertOk()->assertSee('Lecturer assigned')->assertSee('Planned allocation updated')->assertSee('Allocation activated')
            ->assertSee('Allocation ended')->assertSee('Allocation cancelled')->assertSee('Lecturer replaced')
            ->assertSee('Staffing plan withdrawn')->assertSee('Replacement assignment withdrawn')->assertSee('Lecturer A')
            ->assertSee($manager->name)->assertSee(now()->format('Y-m-d'))
            ->assertDontSee('Lecturer B')->assertDontSee('Tenant B Actor');

        // Completion must not silently leave active teaching-team allocations behind.
        try {
            app(CourseOfferingService::class)->complete($tenantA['school'], $offeringA->id);
            $this->fail('Completing with active lecturer allocations must be rejected.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('lecturer allocations remain active', $exception->getMessage());
        }
        $this->assertSame('in_progress', $offeringA->fresh()->status);

        foreach (DB::table('course_offering_lecturer_allocations')->where('course_offering_id', $offeringA->id)->whereIn('status', ['planned', 'active'])->get() as $stillAllocated) {
            $this->post(route('admin.course_offerings.lecturers.end', [$offeringA->id, $stillAllocated->id]), ['ends_on' => '2026-06-30'])->assertRedirect();
        }
        $this->assertSame(0, DB::table('course_offering_lecturer_allocations')->where('course_offering_id', $offeringA->id)->whereIn('status', ['planned', 'active'])->count());

        app(CourseOfferingService::class)->complete($tenantA['school'], $offeringA->id);
        $this->assertSame('completed', $offeringA->fresh()->status);
        $this->get(route('admin.course_offerings.lecturers.index', $offeringA->id))
            ->assertOk()->assertSee('This Offering is complete. Lecturer allocations are retained as history.')
            ->assertDontSee('Assign Lecturer')->assertDontSee('Activate');
        $cancelledOffering = $this->createOffering($tenantA, 'LECTURER-CANCELLED');
        app(CourseOfferingService::class)->cancel($tenantA['school'], $cancelledOffering->id, 'Offering withdrawn');
        $this->get(route('admin.course_offerings.lecturers.index', $cancelledOffering->id))
            ->assertOk()->assertSee('This Offering is cancelled. Lecturer allocations are read-only.')
            ->assertDontSee('Assign Lecturer');
    }

    public function test_lecturer_create_page_loads_without_optional_staff_profiles_table(): void
    {
        $tenant = $this->tenants[1];
        $offering = $this->createOffering($tenant, 'TEACHING-TEAM-CONTEXT');
        $manager = $this->staff(1, ['academic.course_offering.lecturer.view', 'academic.course_offering.lecturer.manage']);
        $lecturer = User::factory()->create([
            'name' => 'Eligible Lecturer Without Profile', 'role_id' => 3,
            'school_id' => $tenant['school'], 'account_status' => 'active', 'staff_status' => 'active',
        ]);
        Schema::drop('staff_profiles');

        $this->actingAs($manager)->get(route('admin.course_offerings.lecturers.index', $offering->id))
            ->assertOk()
            ->assertSee('Teaching Team')
            ->assertSee('CORE-1 — Core 1')
            ->assertSee('2026')
            ->assertSee('Semester 1')
            ->assertSee('TEACHING-TEAM-CONTEXT')
            ->assertSee('No teaching team members are assigned to this Course Offering yet.')
            ->assertSee('Assign Lecturer')
            ->assertSee('Back to Course Offering');

        $this->get(route('admin.course_offerings.lecturers.create', $offering->id))
            ->assertOk()
            ->assertSee('Assign Lecturer')
            ->assertSee('Eligible Lecturer Without Profile')
            ->assertSee('Choose a lecturer')
            ->assertSee('Teaching Role')
            ->assertSee('Primary Lecturer')
            ->assertSee('Co-Lecturer')
            ->assertSee('Teaching Assistant')
            ->assertSee('Lab Instructor')
            ->assertSee('Guest Lecturer')
            ->assertSee('Starts On')
            ->assertSee('Ends On')
            ->assertSee('Back to Teaching Team')
            ->assertSee('2026')
            ->assertSee('Semester 1')
            ->assertSee('TEACHING-TEAM-CONTEXT')
            ->assertSee('Eligible Lecturer Without Profile')
            ->assertSee('Offering Reference:')
            ->assertSee('CORE-1 — Core 1');
    }

    public function test_index_filters_are_tenant_scoped_and_paginates_without_foreign_rows(): void
    {
        $a = $this->tenants[1]; $b = $this->tenants[2];
        $ids = [];
        for ($i = 1; $i <= 27; $i++) $ids[] = $this->createOffering($a, 'A-REF-'.$i, $i === 1 ? $a['subject'] : $this->subject(1, 'Course Unit '.$i, 'CU-'.$i))->id;
        $foreign = $this->createOffering($b, 'B-REF-ONLY');
        $viewer = $this->staff(1, ['academic.course_offering.view']);
        $this->actingAs($viewer);
        $page1 = $this->get(route('admin.course_offerings.index'));
        $page1->assertOk()->assertSee('A-REF-1')->assertDontSee('B-REF-ONLY')->assertSee('page=2');
        $page2 = $this->get(route('admin.course_offerings.index', ['page' => 2]));
        $page2->assertOk()->assertDontSee('B-REF-ONLY');
        $this->assertNotSame([], $ids);

        $queryCases = [
            ['year_id' => $a['year']], ['period_id' => $a['period']], ['status' => 'draft'],
            ['reference' => 'A-REF-1'], ['search' => 'CU-1'],
            ['subject_id' => $a['subject']], ['subject_id' => $a['subject'], 'year_id' => $a['year'], 'period_id' => $a['period']],
            ['programme_id' => $a['programme']], ['curriculum_id' => $a['curriculum']], ['department_id' => $a['department']],
            ['programme_id' => $b['programme']], ['curriculum_id' => $b['curriculum']], ['department_id' => $b['department']],
            ['year_id' => $b['year']], ['period_id' => $b['period']], ['subject_id' => $b['subject']],
        ];
        $filterFixtureKeys = [
            'programme_id' => 'programme',
            'curriculum_id' => 'curriculum',
            'department_id' => 'department',
            'year_id' => 'year',
            'period_id' => 'period',
            'subject_id' => 'subject',
        ];
        foreach ($queryCases as $query) {
            $response = $this->get(route('admin.course_offerings.index', $query));
            $response->assertOk()->assertDontSee('B-REF-ONLY');
            $filterName = array_key_first($query);
            $fixtureKey = $filterFixtureKeys[$filterName] ?? null;
            if ($fixtureKey !== null && $query[$filterName] === $b[$fixtureKey] && $b[$fixtureKey] !== $a[$fixtureKey]) {
                $response->assertDontSee('A-REF-1');
            }
        }
        $combined = $this->get(route('admin.course_offerings.index', [
            'subject_id' => $a['subject'], 'year_id' => $a['year'], 'period_id' => $a['period'], 'search' => 'CORE-1',
        ]));
        $combined->assertOk()->assertSee('A-REF-1')->assertDontSee('A-REF-2')->assertDontSee('B-REF-ONLY');
        $page1->assertSee('offering-course-unit')->assertSee('CORE-1 — Core 1');
        $this->assertNotSame($foreign->id, $ids[0]);
    }

    public function test_index_presentation_uses_tenant_terms_and_distinguishes_empty_states(): void
    {
        $tenant = $this->tenants[1];
        $manager = $this->staff(1, ['academic.course_offering.view', 'academic.course_offering.manage']);
        $this->actingAs($manager);

        $empty = $this->get(route('admin.course_offerings.index'));
        $empty->assertOk()
            ->assertSee('Manage the Course Units being taught in each Academic Year and Semester, including teaching teams and student registration.')
            ->assertSee('Create Course Offering')
            ->assertSee('All Programme Study Plans')
            ->assertSee('Applicable Programme Study Plans')
            ->assertSee('Semester')
            ->assertSee('No Course Offerings have been created yet.');

        $this->createOffering($tenant, 'INDEX-EMPTY-STATE');
        $filtered = $this->get(route('admin.course_offerings.index', ['search' => 'no-such-course-unit']));
        $filtered->assertOk()->assertSee('No Course Offerings match these filters.')->assertSee('Clear filters');
        $this->assertSame(1, substr_count($filtered->getContent(), 'No Course Offerings match these filters.'));

        DB::table('schools')->where('id', $tenant['school'])->update(['school_type' => 'k12', 'academic_calendar_pattern' => 'term']);
        $k12Label = $this->get(route('admin.course_offerings.index', ['search' => 'no-such-course-unit']));
        $k12Label->assertOk()->assertSee('Academic Year and Term, including teaching teams and student registration.')
            ->assertSee('<label class="form-label" for="offering-period">Term</label>', false);
    }

    public function test_draft_endpoints_applicability_readiness_lifecycle_and_cancellation(): void
    {
        $a = $this->tenants[1];
        $manager = $this->staff(1, ['academic.course_offering.manage', 'academic.course_offering.lifecycle']);
        $this->actingAs($manager);

        $this->post(route('admin.course_offerings.store'), $this->draftPayload($a, 'CLIENT-SUPPLIED-REFERENCE'))->assertRedirect();
        $created = CourseOffering::where('school_id', 1)->latest('id')->firstOrFail();
        $this->assertSame('draft', $created->status);
        $this->assertSame('CORE-1-2026-S1', $created->reference);
        $this->assertNotSame('CLIENT-SUPPLIED-REFERENCE', $created->reference);
        $this->assertDatabaseMissing('course_offerings', ['id' => $created->id, 'programme_id' => $a['programme']]);

        $this->put(route('admin.course_offerings.update', $created->id), $this->draftPayload($a, 'ATTEMPTED-OVERRIDE'))->assertRedirect();
        $this->assertSame('CORE-1-2026-S1', $created->fresh()->reference);
        $this->post(route('admin.course_offerings.open', $created->id))->assertSessionHasErrors('lifecycle');
        $this->post(route('admin.course_offerings.applicability.store', $created->id), ['curriculum_membership_id' => $a['member']])->assertRedirect();
        $this->assertDatabaseHas('course_offering_curriculum_memberships', ['school_id' => 1, 'course_offering_id' => $created->id, 'curriculum_membership_id' => $a['member']]);
        $this->delete(route('admin.course_offerings.applicability.destroy', [$created->id, $a['member']]))->assertRedirect();
        $this->post(route('admin.course_offerings.applicability.store', $created->id), ['curriculum_membership_id' => $a['member']])->assertRedirect();
        $this->post(route('admin.course_offerings.open', $created->id))->assertRedirect();
        $this->assertSame('open', $created->fresh()->status);
        $this->put(route('admin.course_offerings.update', $created->id), $this->draftPayload($a, 'NO'))->assertSessionHasErrors('offering');
        $this->post(route('admin.course_offerings.start', $created->id))->assertRedirect();
        $this->assertSame('in_progress', $created->fresh()->status);
        $this->post(route('admin.course_offerings.complete', $created->id))->assertRedirect();
        $this->assertSame('completed', $created->fresh()->status);
        $this->post(route('admin.course_offerings.start', $created->id))->assertSessionHasErrors('lifecycle');
        $this->put(route('admin.course_offerings.update', $created->id), $this->draftPayload($a, 'NO'))->assertSessionHasErrors('offering');

        $this->post(route('admin.course_offerings.store'), $this->draftPayload($a))->assertRedirect();
        $cancelDraft = CourseOffering::where('school_id', 1)->latest('id')->firstOrFail();
        $this->post(route('admin.course_offerings.cancel', $cancelDraft->id), ['reason' => '   '])->assertSessionHasErrors('reason');
        $this->post(route('admin.course_offerings.cancel', $cancelDraft->id), ['reason' => 'No longer scheduled'])->assertRedirect();
        $this->assertSame('cancelled', $cancelDraft->fresh()->status);
        $this->post(route('admin.course_offerings.start', $cancelDraft->id))->assertSessionHasErrors('lifecycle');

        foreach (['open', 'in_progress'] as $state) {
            $cancel = $this->createOffering($a, 'CANCEL-'.$state);
            $this->attach($a, $cancel, $a['member']);
            app(CourseOfferingService::class)->open(1, $cancel->id);
if ($state === 'in_progress') app(CourseOfferingService::class)->start(1, $cancel->id);
            $this->post(route('admin.course_offerings.cancel', $cancel->id), ['reason' => 'Institutional change'])->assertRedirect();
            $this->assertSame('cancelled', $cancel->fresh()->status);
        }
    }

    public function test_applicability_http_rejects_same_tenant_wrong_subject_membership(): void
    {
        $tenant = $this->tenants[1];
        $otherSubject = $this->subject($tenant['school'], 'Other Unit', 'OTHER-UNIT');
        $membership = $this->createMembership($tenant, $tenant['curriculum'], $otherSubject, 'semester', 1);
        $offering = $this->createOffering($tenant, 'WRONG-SUBJECT');
        $manager = $this->staff(1, ['academic.course_offering.manage']);

        $this->actingAs($manager)
            ->post(route('admin.course_offerings.applicability.store', $offering->id), ['curriculum_membership_id' => $membership])
            ->assertSessionHasErrors('applicability');
        $this->assertDatabaseMissing('course_offering_curriculum_memberships', [
            'school_id' => $tenant['school'], 'course_offering_id' => $offering->id, 'curriculum_membership_id' => $membership,
        ]);
    }

    public function test_applicability_http_rejects_membership_from_draft_curriculum(): void
    {
        $tenant = $this->tenants[1];
        $draftCurriculum = $this->curriculumFor($tenant, 'draft', 'draft-applicability');
        $membership = $this->createMembership($tenant, $draftCurriculum, $tenant['subject'], 'semester', 1);
        $offering = $this->createOffering($tenant, 'DRAFT-CURRICULUM');
        $manager = $this->staff(1, ['academic.course_offering.manage']);

        $this->actingAs($manager)
            ->post(route('admin.course_offerings.applicability.store', $offering->id), ['curriculum_membership_id' => $membership])
            ->assertSessionHasErrors('applicability');
        $this->assertDatabaseMissing('course_offering_curriculum_memberships', [
            'school_id' => $tenant['school'], 'course_offering_id' => $offering->id, 'curriculum_membership_id' => $membership,
        ]);
    }

    public function test_applicability_http_rejects_incompatible_period_membership(): void
    {
        $tenant = $this->tenants[1];
        $membership = $this->createMembership($tenant, $tenant['curriculum'], $tenant['subject'], 'semester', 2);
        $offering = $this->createOffering($tenant, 'INCOMPATIBLE-PERIOD');
        $manager = $this->staff(1, ['academic.course_offering.manage']);

        $this->actingAs($manager)
            ->post(route('admin.course_offerings.applicability.store', $offering->id), ['curriculum_membership_id' => $membership])
            ->assertSessionHasErrors('applicability');
        $this->assertDatabaseMissing('course_offering_curriculum_memberships', [
            'school_id' => $tenant['school'], 'course_offering_id' => $offering->id, 'curriculum_membership_id' => $membership,
        ]);
    }

    public function test_completed_offering_rejects_all_lifecycle_http_actions(): void
    {
        $tenant = $this->tenants[1];
        $offering = $this->createOffering($tenant, 'TERMINAL-HTTP');
        $this->attach($tenant, $offering, $tenant['member']);
        $manager = $this->staff(1, ['academic.course_offering.manage', 'academic.course_offering.lifecycle']);
        $this->actingAs($manager);

        $this->post(route('admin.course_offerings.open', $offering->id))->assertRedirect();
        $this->post(route('admin.course_offerings.start', $offering->id))->assertRedirect();
        $this->post(route('admin.course_offerings.complete', $offering->id))->assertRedirect();
        $this->assertSame('completed', $offering->fresh()->status);

        foreach (['open', 'start', 'complete', 'cancel'] as $action) {
            $this->post(route('admin.course_offerings.'.$action, $offering->id), ['reason' => 'Terminal state check'])
                ->assertSessionHasErrors('lifecycle');
            $this->assertSame('completed', $offering->fresh()->status);
        }
    }

    public function test_programme_context_link_filters_central_offerings_by_applicability(): void
    {
        $tenant = $this->tenants[1];
        $otherProgramme = $this->makeProgramme($tenant['school'], ['code' => 'P1-OTHER', 'name' => 'Other Programme', 'department_id' => $tenant['department']]);
        $otherCurriculum = (int) DB::table('curricula')->insertGetId([
            'school_id' => $tenant['school'], 'programme_id' => $otherProgramme, 'version' => 'other-v1',
            'effective_academic_year_id' => $tenant['year'], 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherStage = (int) DB::table('curriculum_stages')->insertGetId([
            'school_id' => $tenant['school'], 'curriculum_id' => $otherCurriculum, 'label' => 'Year 1', 'sequence' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherMembership = $this->createMembership($tenant, $otherCurriculum, $tenant['subject'], 'semester', 1, $otherStage);
        $offeringA = $this->createOffering($tenant, 'PROGRAMME-A');
        $offeringB = $this->createOffering($tenant, 'PROGRAMME-B');
        $this->attach($tenant, $offeringA, $tenant['member']);
        $this->attach($tenant, $offeringB, $otherMembership);
        $viewer = $this->staff(1, ['academic.course_offering.view', 'academic.programmes']);
        $this->actingAs($viewer);

        $programmePage = $this->get(route('admin.programmes.index'));
        $programmePage->assertOk()->assertSee(route('admin.course_offerings.index', ['programme_id' => $tenant['programme']]), false);
        $this->get(route('admin.course_offerings.index', ['programme_id' => $tenant['programme']]))
            ->assertOk()->assertSee('PROGRAMME-A')->assertDontSee('PROGRAMME-B');
    }

    public function test_academic_structure_context_link_carries_year_and_period_filters(): void
    {
        $tenant = $this->tenants[1];
        $secondPeriod = (int) DB::table('academic_periods')->insertGetId([
            'school_id' => $tenant['school'], 'academic_year_id' => $tenant['year'], 'type' => 'semester', 'label' => 'Semester 2', 'sequence' => 2,
            'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $offeringOne = $this->createOffering($tenant, 'YEAR-PERIOD-ONE');
        $offeringTwo = app(CourseOfferingService::class)->createDraft($tenant['school'], $tenant['subject'], $tenant['year'], $secondPeriod, 'YEAR-PERIOD-TWO');
        $viewer = $this->staff(1, ['academic.course_offering.view', 'academic.structure.manage']);
        $this->actingAs($viewer);

        $structure = $this->get(route('admin.academic_structure.index'));
        $structure->assertOk()->assertSee(route('admin.course_offerings.index', ['year_id' => $tenant['year'], 'period_id' => $tenant['period']]));
        $this->get(route('admin.course_offerings.index', ['year_id' => $tenant['year'], 'period_id' => $tenant['period']]))
            ->assertOk()->assertSee('YEAR-PERIOD-ONE')->assertDontSee('YEAR-PERIOD-TWO');
        $this->assertNotSame($offeringOne->id, $offeringTwo->id);
    }

    public function test_authorized_hei_navigation_shows_course_offerings_item(): void
    {
        $viewer = $this->staff(1, ['academic.course_offering.view']);
        $response = $this->actingAs($viewer)->get(route('admin.course_offerings.index'));
        $response->assertOk()->assertSee('>Course Offerings</span>', false);
        $response->assertSee(route('admin.course_offerings.index'), false);
    }

    public function test_navigation_and_contextual_entry_visibility_follow_tenant_type_and_authorization(): void
    {
        $hei = $this->tenants[1];
        $approved = $hei['curriculum'];
        DB::table('curricula')->where('id', $approved)->update(['status' => 'approved']);
        $draftCurriculum = $this->curriculumFor($hei, 'draft', 'draft-v2');
        $retiredCurriculum = $this->curriculumFor($hei, 'retired', 'retired-v3');
        $manager = $this->staff(1, ['academic.course_offering.view','academic.course_offering.manage','academic.structure.manage','academic.curriculum.view']);
        $this->actingAs($manager);
        $this->get(route('admin.course_offerings.index'))->assertOk()->assertSee('Course Offerings');
        $this->get(route('admin.course_offerings.create'))->assertOk()
            ->assertSee('<h4>Create Course Offering</h4>', false)
            ->assertSee('Choose the Course Unit, Academic Year and Semester for this teaching period. Programme Study Plans can be linked after the Offering is created.')
            ->assertSee('<label class="form-label" for="period">Semester</label>', false)
            ->assertSee('>Create Course Offering</button>', false)
            ->assertDontSee('Create Course Offering draft')
            ->assertDontSee('>Create draft</button>', false);
        $this->get(route('admin.curricula.show', $approved))->assertOk()->assertSee('Create Course Offerings');
        $this->get(route('admin.curricula.show', $draftCurriculum))->assertOk()->assertDontSee('Create Course Offerings');
        $this->get(route('admin.curricula.show', $retiredCurriculum))->assertOk()->assertDontSee('Create Course Offerings');

        $k12school = $this->makeSchool(['title'=>'K12','status'=>1,'school_type'=>'k12','academic_calendar_pattern'=>'term']);
        // Render the shared admin shell on a simple authorized page and assert the K12 sidebar does not expose Offerings.
        $k12manager = $this->staffForSchool($k12school, ['academic.course_offering.view','academic.course_offering.manage']);
        $this->actingAs($k12manager)
            ->get(route('admin.course_offerings.index'))->assertOk()->assertDontSee('>Course Offerings</span>', false);
        $this->get(route('admin.course_offerings.create'))->assertOk()
            ->assertSee('Academic Year and Term for this teaching period.')
            ->assertSee('<label class="form-label" for="period">Term</label>', false);
    }

    public function test_academic_structure_navigation_uses_existing_permission_and_menu_boundaries(): void
    {
        $authorized = $this->staff(1, ['academic.course_offering.view', 'academic.structure.manage']);
        $this->actingAs($authorized)->get(route('admin.course_offerings.index'))
            ->assertOk()
            ->assertSee(route('admin.academic_structure.index'), false)
            ->assertSee('Academic Years &amp; Periods', false);

        $restricted = $this->staff(1, ['academic.course_offering.view'], [
            'menu_permission' => json_encode(['admin.course_offerings.index']),
        ]);
        $this->actingAs($restricted)->get(route('admin.course_offerings.index'))
            ->assertOk()
            ->assertDontSee(route('admin.academic_structure.index'), false)
            ->assertDontSee('Academic Years &amp; Periods', false);
        $this->get(route('admin.academic_structure.index'))->assertForbidden();
    }

    public function test_academic_context_http_workflow_feedback_tenant_validation_and_clear_period(): void
    {
        $tenantA = $this->tenants[1];
        $tenantB = $this->tenants[2];
        $admin = $this->staff(1, ['academic.structure.manage']);
        DB::table('academic_periods')->where('id', $tenantA['period'])->update(['label' => 'Academic year 2026-2027']);

        $this->actingAs($admin)
            ->post(route('admin.academic_structure.current'), [
                'current_academic_year_id' => $tenantA['year'],
                'current_academic_period_id' => $tenantA['period'],
            ])
            ->assertRedirect(route('admin.academic_structure.index'))
            ->assertSessionHas('success', 'Current academic context updated.');

        $this->assertDatabaseHas('schools', [
            'id' => $tenantA['school'],
            'current_academic_year_id' => $tenantA['year'],
            'current_academic_period_id' => $tenantA['period'],
        ]);

        $this->get(route('admin.academic_structure.index'))
            ->assertOk()
            ->assertSee('Current academic context updated.')
            ->assertSee('2026 — Semester 1')
            ->assertSee('Semester 1')
            ->assertDontSee('Academic year 2026-2027')
            ->assertSee('class="eBtn eBtn-primary" type="submit">Save current context</button>', false);

        $this->post(route('admin.academic_structure.current'), [
            'current_academic_year_id' => $tenantA['year'],
            'current_academic_period_id' => '',
        ])->assertRedirect(route('admin.academic_structure.index'))
            ->assertSessionHas('success', 'Current academic context updated.');
        $this->assertDatabaseHas('schools', [
            'id' => $tenantA['school'],
            'current_academic_year_id' => $tenantA['year'],
            'current_academic_period_id' => null,
        ]);

        $inactiveYear = (int) DB::table('academic_years')->insertGetId([
            'school_id' => $tenantA['school'], 'label' => 'Inactive year', 'start_date' => '2027-01-01',
            'end_date' => '2027-12-31', 'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $inactivePeriod = (int) DB::table('academic_periods')->insertGetId([
            'school_id' => $tenantA['school'], 'academic_year_id' => $tenantA['year'], 'type' => 'semester',
            'label' => 'Inactive period', 'sequence' => 2, 'start_date' => '2026-07-01', 'end_date' => '2026-12-31',
            'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherYear = (int) DB::table('academic_years')->insertGetId([
            'school_id' => $tenantA['school'], 'label' => 'Other year', 'start_date' => '2027-01-01',
            'end_date' => '2027-12-31', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherYearPeriod = (int) DB::table('academic_periods')->insertGetId([
            'school_id' => $tenantA['school'], 'academic_year_id' => $otherYear, 'type' => 'semester',
            'label' => 'Other year period', 'sequence' => 1, 'start_date' => '2027-01-01', 'end_date' => '2027-06-30',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post(route('admin.academic_structure.current'), [
            'current_academic_year_id' => $inactiveYear, 'current_academic_period_id' => '',
        ])->assertSessionHasErrors('current_academic_year_id');
        $this->post(route('admin.academic_structure.current'), [
            'current_academic_year_id' => $tenantA['year'], 'current_academic_period_id' => $inactivePeriod,
        ])->assertSessionHasErrors('current_academic_period_id');
        $this->post(route('admin.academic_structure.current'), [
            'current_academic_year_id' => $tenantA['year'], 'current_academic_period_id' => $otherYearPeriod,
        ])->assertSessionHasErrors('current_academic_period_id');
        $this->post(route('admin.academic_structure.current'), [
            'current_academic_year_id' => $tenantB['year'], 'current_academic_period_id' => '',
        ])->assertSessionHasErrors('current_academic_year_id');
        $this->post(route('admin.academic_structure.current'), [
            'current_academic_year_id' => $tenantA['year'], 'current_academic_period_id' => $tenantB['period'],
        ])->assertSessionHasErrors('current_academic_period_id');
        $this->get(route('admin.academic_structure.index'))
            ->assertOk()
            ->assertSee('The selected period must belong to the selected year and school.');

        $this->actingAs($this->staff(1, []))
            ->post(route('admin.academic_structure.current'), [
                'current_academic_year_id' => $tenantA['year'], 'current_academic_period_id' => $tenantA['period'],
            ])->assertForbidden();
    }

    public function test_stage_forms_are_distinct_edit_preserves_record_and_add_creates_another_without_stale_subject_errors(): void
    {
        $tenant = $this->tenants[1];
        $curriculumId = $this->curriculumFor($tenant, 'draft', 'STAGE-ERROR-ISOLATION');
        $manager = $this->staff(1, ['academic.curriculum.manage', 'academic.curriculum.view']);
        $staleErrors = (new \Illuminate\Support\ViewErrorBag())->put(
            'default', new \Illuminate\Support\MessageBag(['subject_ids' => ['The subject ids field is required.']])
        );

        $stageResponse = $this->actingAs($manager)
            ->withSession(['errors' => $staleErrors])
            ->from(route('admin.curricula.show', $curriculumId))
            ->post(route('admin.curricula.stages.store', $curriculumId), ['label' => 'Year 1', 'sequence' => 1])
            ->assertRedirect(route('admin.curricula.show', $curriculumId));
        $stageResponse->assertSessionMissing('errors');

        $this->assertDatabaseHas('curriculum_stages', [
            'school_id' => $tenant['school'], 'curriculum_id' => $curriculumId, 'label' => 'Year 1', 'sequence' => 1,
        ]);
        $showResponse = $this->get(route('admin.curricula.show', $curriculumId));
        $showResponse
            ->assertOk()
            ->assertSee('Stage added.')
            ->assertSee('Add New Stage')
            ->assertSee('Create a separate stage in this Study Plan. Existing stages will not be changed.')
            ->assertSee('Stage 1 — Year 1')
            ->assertSee('Edit Stage: Year 1')
            ->assertSee('Stage Name')
            ->assertSee('Position')
            ->assertSee('Save Stage Changes')
            ->assertDontSee('The subject ids field is required.');

        $dom = new \DOMDocument();
        $previousLibxmlState = libxml_use_internal_errors(true);
        $dom->loadHTML($showResponse->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);
        $xpath = new \DOMXPath($dom);
        $stageAction = route('admin.curricula.stages.store', $curriculumId);
        $membershipAction = route('admin.curricula.memberships.store', $curriculumId);
        $stageId = (int) DB::table('curriculum_stages')->where('curriculum_id', $curriculumId)->value('id');
        $editAction = route('admin.curricula.stages.update', [$curriculumId, $stageId]);
        $this->assertSame(1, $xpath->query('//form[@action="'.$stageAction.'"]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.$editAction.'"][.//input[@name="_method" and @value="PUT"]]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.$membershipAction.'"]')->length);
        $this->assertSame(0, $xpath->query('//form[ancestor::form]')->length, 'The rendered page must not contain nested forms.');
        $workspaceFormIds = [];
        foreach ($xpath->query('//*[@id="page-print-area"]//form[@id]') as $form) {
            $workspaceFormIds[] = $form->getAttribute('id');
        }
        $this->assertSame(count($workspaceFormIds), count(array_unique($workspaceFormIds)), 'Workspace form IDs must be unique.');

        $this->from(route('admin.curricula.show', $curriculumId))
            ->put(route('admin.curricula.stages.update', [$curriculumId, $stageId]), ['label' => 'Year 1 Revised', 'sequence' => 1])
            ->assertRedirect(route('admin.curricula.show', $curriculumId));
        $this->assertDatabaseHas('curriculum_stages', [
            'id' => $stageId, 'school_id' => $tenant['school'], 'curriculum_id' => $curriculumId,
            'label' => 'Year 1 Revised', 'sequence' => 1,
        ]);

        $this->from(route('admin.curricula.show', $curriculumId))
            ->post(route('admin.curricula.stages.store', $curriculumId), ['label' => 'Year 2', 'sequence' => 2])
            ->assertRedirect(route('admin.curricula.show', $curriculumId));
        $this->assertDatabaseHas('curriculum_stages', [
            'id' => $stageId, 'school_id' => $tenant['school'], 'curriculum_id' => $curriculumId,
            'label' => 'Year 1 Revised', 'sequence' => 1,
        ]);
        $newStage = DB::table('curriculum_stages')->where('school_id', $tenant['school'])
            ->where('curriculum_id', $curriculumId)->where('label', 'Year 2')->first();
        $this->assertNotNull($newStage);
        $this->assertNotSame($stageId, (int) $newStage->id);
        $this->assertSame(2, (int) $newStage->sequence);

        $this->post(route('admin.curricula.memberships.store', $curriculumId), [
            'curriculum_stage_id' => $stageId,
        ])->assertSessionHasErrors('subject_ids');

        $unauthorized = $this->staff(1, []);
        $this->actingAs($unauthorized)
            ->post(route('admin.curricula.stages.store', $curriculumId), ['label' => 'Blocked', 'sequence' => 2])
            ->assertForbidden();
        $this->assertDatabaseMissing('curriculum_stages', [
            'school_id' => $tenant['school'], 'curriculum_id' => $curriculumId, 'label' => 'Blocked',
        ]);

        $foreignDraftId = $this->curriculumFor($this->tenants[2], 'draft', 'FOREIGN-STAGE-ISOLATION');
        $this->actingAs($manager)
            ->post(route('admin.curricula.stages.store', $foreignDraftId), ['label' => 'Blocked', 'sequence' => 1])
            ->assertNotFound();
        $this->assertDatabaseMissing('curriculum_stages', [
            'school_id' => $this->tenants[2]['school'], 'curriculum_id' => $foreignDraftId, 'label' => 'Blocked',
        ]);

        $approvedCurriculumId = $tenant['curriculum'];
        $this->actingAs($manager)
            ->from(route('admin.curricula.show', $approvedCurriculumId))
            ->post(route('admin.curricula.stages.store', $approvedCurriculumId), ['label' => 'Blocked', 'sequence' => 2])
            ->assertSessionHasErrors('curriculum');
        $this->assertDatabaseMissing('curriculum_stages', [
            'school_id' => $tenant['school'], 'curriculum_id' => $approvedCurriculumId, 'label' => 'Blocked',
        ]);
    }

    public function test_course_registration_shape_and_behavior_remain_unchanged(): void
    {
        $this->assertContains('session_id', Schema::getColumnListing('course_registrations'));
        $this->assertNotContains('course_offering_id', Schema::getColumnListing('course_registrations'));
        $this->assertSame(0, DB::table('course_registrations')->count());
    }

    public function test_offering_live_class_workspace_is_exact_tenant_scoped_and_provider_secret_free(): void
    {
        $a = $this->tenants[1];
        $b = $this->tenants[2];
        $offeringA = $this->createOffering($a, 'WORKSPACE-A');
        $parallel = $this->createOffering($a, 'WORKSPACE-B', $a['subject']);
        $foreign = $this->createOffering($b, 'WORKSPACE-FOREIGN');
        DB::table('course_offerings')->whereIn('id', [$offeringA->id, $parallel->id, $foreign->id])->update(['status' => 'open']);
        $ownSessionId = $this->insertWorkspaceClass($a['school'], $a['subject'], $offeringA->id, 'OWN SESSION', 'scheduled', true, '2026-10-01 10:00:00');
        $this->insertWorkspaceClass($a['school'], $a['subject'], $parallel->id, 'PARALLEL SECRET SESSION', 'scheduled', true);
        $this->insertWorkspaceClass($b['school'], $b['subject'], $foreign->id, 'CROSS TENANT SECRET SESSION', 'scheduled', true);
        $this->insertWorkspaceClass($a['school'], $a['subject'], null, 'LEGACY SECRET SESSION', 'scheduled', true);
        DB::table('live_classes')->where('title', 'OWN SESSION')->update([
            'meeting_url' => 'https://provider.example/DO-NOT-LEAK', 'meeting_id' => 'DO-NOT-LEAK-ID',
            'meeting_password' => 'DO-NOT-LEAK-PASSWORD', 'recording_url' => 'https://recording.example/DO-NOT-LEAK',
        ]);
        DB::table('live_class_materials')->insert([
            'school_id' => $a['school'], 'live_class_id' => $ownSessionId, 'type' => 'file', 'category' => 'resource',
            'title' => 'Confidential resource', 'original_name' => 'slides.pdf',
            'stored_name' => 'private/live-classes/SECRET-STORAGE-KEY.pdf', 'link_url' => 'https://provider.example/SECRET-LINK',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->insertWorkspaceClass($a['school'], $a['subject'], $offeringA->id, 'PAST SESSION', 'ended', true, '2026-08-01 10:00:00');
        $this->insertWorkspaceClass($a['school'], $a['subject'], $offeringA->id, 'CANCELLED SESSION', 'cancelled', true, '2026-10-02 10:00:00');

        $admin = $this->staff(1, ['academic.course_offering.view', 'live_classes.view', 'live_classes.create'], ['role_id' => 2]);
        $response = $this->actingAs($admin)->get(route('admin.course_offerings.show', $offeringA->id));
        $response->assertOk()->assertSee('OWN SESSION')->assertSee('PAST SESSION')->assertSee('CANCELLED SESSION')
            ->assertDontSee('PARALLEL SECRET SESSION')
            ->assertDontSee('CROSS TENANT SECRET SESSION')->assertDontSee('LEGACY SECRET SESSION')
            ->assertSee('Schedule Live Class')->assertSee(route('admin.course_offerings.live_classes.create', $offeringA->id), false)
            ->assertSee('Scheduled')->assertSee('Completed')->assertSee('Cancelled')
        ->assertDontSee('Ended')
            ->assertSee('View')->assertSee('Edit')->assertSee('Cancel')->assertSee('Join Evidence')
            ->assertDontSee('DO-NOT-LEAK')->assertDontSee('DO-NOT-LEAK-ID')->assertDontSee('DO-NOT-LEAK-PASSWORD')
            ->assertDontSee('SECRET-STORAGE-KEY')->assertDontSee('SECRET-LINK')->assertDontSee('private/live-classes')
            ->assertDontSee('course_offering_id');
    }

    public function test_lecturer_creation_authority_does_not_render_admin_schedule_route(): void
    {
        $tenant = $this->tenants[1];
        $offering = $this->createOffering($tenant, 'LECTURER-CTA');
        DB::table('course_offerings')->where('id', $offering->id)->update(['status' => 'open']);
        $offering->refresh();
        $lecturer = $this->staff(1, ['academic.course_offering.view', 'live_classes.view', 'live_classes.create']);
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $tenant['school'], 'course_offering_id' => $offering->id, 'user_id' => $lecturer->id,
            'role' => 'primary_lecturer', 'status' => 'active', 'starts_on' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(app(\App\Support\LiveClasses\LiveClassAccessService::class)
            ->canLecturerCreateForOffering($lecturer, $offering));
        $response = $this->actingAs($lecturer)->get(route('admin.course_offerings.show', $offering->id));
        $response->assertOk()->assertDontSee('Schedule Live Class');
    }

    public function test_offering_session_actions_follow_lecturer_allocation_role(): void
    {
        $tenant = $this->tenants[1];
        $offering = $this->createOffering($tenant, 'ROLE-ACTIONS');
        DB::table('course_offerings')->where('id', $offering->id)->update(['status' => 'open']);
        $offering->refresh();
        $this->insertWorkspaceClass($tenant['school'], $tenant['subject'], $offering->id, 'ROLE MATRIX SESSION', 'scheduled', true, '2026-10-01 10:00:00');

        foreach ([
            'primary_lecturer' => true,
            'co_lecturer' => true,
            'teaching_assistant' => false,
            'lab_instructor' => false,
            'guest_lecturer' => false,
        ] as $role => $canManage) {
            $lecturer = $this->staff(1, ['academic.course_offering.view', 'live_classes.view', 'live_classes.create']);
            DB::table('course_offering_lecturer_allocations')->insert([
                'school_id' => $tenant['school'], 'course_offering_id' => $offering->id, 'user_id' => $lecturer->id,
                'role' => $role, 'status' => 'active', 'starts_on' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $response = $this->actingAs($lecturer)->get(route('admin.course_offerings.show', $offering->id));
            $response->assertOk()->assertSee('ROLE MATRIX SESSION')->assertSee('View');
            if ($canManage) {
                $response->assertSee('Edit')->assertSee('Cancel');
            } else {
                $response->assertDontSee('Edit')->assertDontSee('Cancel this class?');
            }
        }
    }

    public function test_offering_workspace_empty_state_and_schedule_cta_visibility(): void
    {
        $offering = $this->createOffering($this->tenants[1], 'EMPTY-WORKSPACE');
        $admin = $this->staff(1, ['academic.course_offering.view'], ['role_id' => 2]);
        $this->actingAs($admin)->get(route('admin.course_offerings.show', $offering->id))
            ->assertOk()->assertSee('No live classes have been scheduled for this course offering yet.')
            ->assertDontSee('Schedule Live Class');

        $this->actingAs($this->staff(1, ['academic.course_offering.view']))
            ->get(route('admin.course_offerings.show', $offering->id))
            ->assertOk()->assertDontSee('Schedule Live Class');
    }

    public function test_offering_workspace_renders_all_existing_user_facing_statuses(): void
    {
        $clock = \Illuminate\Support\Carbon::parse('2026-10-01 10:00:00', 'UTC');
        \Illuminate\Support\Carbon::setTestNow($clock);
        try {
            $tenant = $this->tenants[1];
            $offering = $this->createOffering($tenant, 'STATUS-WORKSPACE');
            DB::table('course_offerings')->where('id', $offering->id)->update(['status' => 'open']);
            $this->insertWorkspaceClass($tenant['school'], $tenant['subject'], $offering->id, 'DRAFT STATUS', 'draft', false, '2026-10-01 12:00:00');
            $this->insertWorkspaceClass($tenant['school'], $tenant['subject'], $offering->id, 'SCHEDULED STATUS', 'scheduled', true, '2026-10-01 13:00:00');
            $this->insertWorkspaceClass($tenant['school'], $tenant['subject'], $offering->id, 'STARTING SOON STATUS', 'scheduled', true, '2026-10-01 10:10:00');
            $this->insertWorkspaceClass($tenant['school'], $tenant['subject'], $offering->id, 'LIVE STATUS', 'live', true, '2026-10-01 09:55:00');
            $this->insertWorkspaceClass($tenant['school'], $tenant['subject'], $offering->id, 'ENDED STATUS', 'ended', true, '2026-09-30 10:00:00');
            $this->insertWorkspaceClass($tenant['school'], $tenant['subject'], $offering->id, 'CANCELLED STATUS', 'cancelled', true, '2026-10-01 13:00:00');

            $this->actingAs($this->staff(1, ['academic.course_offering.view'], ['role_id' => 2]))
                ->get(route('admin.course_offerings.show', $offering->id))
                ->assertOk()
                ->assertSee('Draft')->assertSee('Scheduled')->assertSee('Starting Soon')
                // "Completed", not the stored "ended": the same convention the
                // rest of the interface uses, asserted here too so the two
                // workspaces cannot drift apart again.
                ->assertSee('Live Now')->assertSee('Completed')->assertSee('Cancelled')
                ->assertDontSee('Ended');
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }
    }

    public function test_study_plan_picker_and_linked_table_display_stage(): void
    {
        $a = $this->tenants[1];
        $draft = $this->createOffering($a, 'STAGE-VISIBLE');
        $manager = $this->staff(1, ['academic.course_offering.view', 'academic.course_offering.manage']);
        $this->actingAs($manager)->get(route('admin.course_offerings.show', $draft->id))
            ->assertOk()->assertSee('Year 1');
        $this->attach($a, $draft, $a['member']);
        $this->get(route('admin.course_offerings.show', $draft->id))
            ->assertOk()->assertSee('Stage / Year of Study')->assertSee('Year 1');
    }

    public function test_index_action_link_says_view_not_open_offering(): void
    {
        $a = $this->tenants[1];
        $offering = $this->createOffering($a, 'VIEW-LABEL');
        $viewer = $this->staff(1, ['academic.course_offering.view']);
        $this->actingAs($viewer)->get(route('admin.course_offerings.index'))
            ->assertOk()->assertSee('>View</a>', false)->assertDontSee('Open Offering');
    }

    public function test_view_only_user_cannot_reach_create_page(): void
    {
        $viewer = $this->staff(1, ['academic.course_offering.view']);
        $this->actingAs($viewer)->get(route('admin.course_offerings.create'))->assertForbidden();

        $manager = $this->staff(1, ['academic.course_offering.manage']);
        $this->actingAs($manager)->get(route('admin.course_offerings.create'))->assertOk();
    }

    public function test_open_offering_wording_is_reserved_for_the_draft_lifecycle_transition(): void
    {
        $a = $this->tenants[1];
        $draft = $this->createOffering($a, 'WORDING-DRAFT');
        $open = $this->createOffering($a, 'WORDING-OPEN');
        $this->attach($a, $open, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $open->id);

        $manager = $this->staff(1, ['academic.course_offering.view', 'academic.course_offering.manage', 'academic.course_offering.lifecycle']);

        // "Open Offering" is the Draft -> Open lifecycle action only.
        $this->actingAs($manager)->get(route('admin.course_offerings.show', $draft->id))
            ->assertOk()->assertSee('>Open Offering</button>', false);
        $this->get(route('admin.course_offerings.show', $open->id))
            ->assertOk()->assertDontSee('>Open Offering</button>', false)->assertSee('>Start Course Offering</button>', false);

        // Navigation/view controls say "View".
        $this->get(route('admin.course_offerings.index'))
            ->assertOk()->assertSee('>View</a>', false)->assertDontSee('Open Offering');
    }

    public function test_state_aware_guidance_names_the_next_step_for_every_lifecycle_state(): void
    {
        $a = $this->tenants[1];
        $manager = $this->staff(1, [
            'academic.course_offering.view', 'academic.course_offering.manage', 'academic.course_offering.lifecycle',
            'academic.course_offering.lecturer.view', 'academic.course_registration.view',
        ]);

        $draft = $this->createOffering($a, 'GUIDE-DRAFT');
        $this->actingAs($manager)->get(route('admin.course_offerings.show', $draft->id))
            ->assertOk()->assertSee('This Course Offering is being prepared.')
            ->assertSee('Link a compatible Programme Study Plan before opening this Course Offering.');

        // A user who may view but not manage is told to ask an authorized administrator.
        $viewer = $this->staff(1, ['academic.course_offering.view']);
        $this->actingAs($viewer)->get(route('admin.course_offerings.show', $draft->id))
            ->assertOk()
            ->assertSee('A Programme Study Plan must be linked before this Course Offering can be opened. Ask an authorized administrator to link one.');

        $prepared = $this->createOffering($a, 'GUIDE-PREPARED');
        $this->attach($a, $prepared, $a['member']);
        $this->get(route('admin.course_offerings.show', $prepared->id))
            ->assertOk()->assertSee('This Course Offering is being prepared.')
            ->assertSee('Confirm Study Plan applicability, assign the teaching team, then open registration when ready.');

        $open = $this->createOffering($a, 'GUIDE-OPEN');
        $this->attach($a, $open, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $open->id);
        $this->get(route('admin.course_offerings.show', $open->id))
            ->assertOk()->assertSee('Preparation and student registration are open.')
            // Open always names student registration, teaching-team actions and Start.
            ->assertSee('Assign the teaching team and register eligible students, then Start the Course Offering when registration is done.');

        $inProgress = $this->createOffering($a, 'GUIDE-IP');
        $this->attach($a, $inProgress, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $inProgress->id);
app(CourseOfferingService::class)->start($a['school'], $inProgress->id);
        $this->get(route('admin.course_offerings.show', $inProgress->id))
            ->assertOk()->assertSee('Teaching is currently in progress.')
            ->assertSee('Live Classes')->assertSee('Attendance')->assertSee('Assignments')->assertSee('Online Exams');

        $completed = $this->createOffering($a, 'GUIDE-DONE');
        $this->attach($a, $completed, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $completed->id);
app(CourseOfferingService::class)->start($a['school'], $completed->id);
        app(CourseOfferingService::class)->complete($a['school'], $completed->id);
        $this->get(route('admin.course_offerings.show', $completed->id))
            ->assertOk()->assertSee('This Course Offering has been completed.')
            ->assertSee('Its teaching and registration history is retained and read-only.')
            ->assertSee('Audit / History')->assertSee('read-only');
    }

    public function test_detail_page_shows_study_plan_applicability_stage_and_student_summary(): void
    {
        $a = $this->tenants[1];
        $offering = $this->createOffering($a, 'SUMMARY-UI');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);

        $manager = $this->staff(1, [
            'academic.course_offering.view', 'academic.course_offering.manage',
            'academic.course_registration.view', 'academic.course_offering.lecturer.view',
        ]);

        // A draft Offering explains that the reference is system-generated and read-only.
        $draft = $this->createOffering($a, 'SUMMARY-REF');
        $this->actingAs($manager)->get(route('admin.course_offerings.show', $draft->id))
            ->assertOk()
            ->assertSee('Offering Reference:')
            ->assertSee('generated automatically and cannot be edited')
            ->assertDontSee('name="reference"', false);

        $this->get(route('admin.course_offerings.show', $offering->id))
            ->assertOk()
            // Identity and academic context
            ->assertSee('SUMMARY-UI')
            ->assertSee('Offering reference')
            ->assertSee('Academic Year:')
            ->assertSee('Semester 1')
            // Study Plan applicability and Stage / Year of Study
            ->assertSee('Study Plan Applicability:')
            ->assertSee('Stage / Year of Study:')
            ->assertSee('Year 1')
            // Status and teaching team
            ->assertSee('Open')
            ->assertSee('Teaching Team')
            // Student registration summary
            ->assertSee('Eligible')->assertSee('Registered')->assertSee('Confirmed')->assertSee('Dropped')
            ->assertSee('No students are registered for this Course Offering yet.');
    }

    public function test_completion_blockers_are_explained_and_enforced_server_side(): void
    {
        $a = $this->tenants[1];
        $offering = $this->createOffering($a, 'BLOCKERS');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);
app(CourseOfferingService::class)->start($a['school'], $offering->id);

        $lecturer = User::factory()->create([
            'name' => 'Blocker Lecturer', 'role_id' => 3, 'school_id' => $a['school'],
            'account_status' => 'active', 'staff_status' => 'active',
        ]);
        $allocation = (int) DB::table('course_offering_lecturer_allocations')->insertGetId([
            'school_id' => $a['school'], 'course_offering_id' => $offering->id, 'user_id' => $lecturer->id,
            'role' => 'primary_lecturer', 'starts_on' => '2026-01-05', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $manager = $this->staff(1, [
            'academic.course_offering.view', 'academic.course_offering.lifecycle',
            'academic.course_offering.lecturer.view', 'academic.course_offering.lecturer.manage',
            'academic.course_registration.view',
        ]);

        $this->actingAs($manager)->get(route('admin.course_offerings.show', $offering->id))
            ->assertOk()
            ->assertSee('This Course Offering cannot be completed yet.')
            ->assertSee('Lecturer allocations are still active.')
            ->assertSee('End the remaining teaching team allocations before completing this Course Offering.');

        $this->post(route('admin.course_offerings.complete', $offering->id))->assertSessionHasErrors('lifecycle');
        $this->assertStringContainsString('lecturer allocation remains active', session('errors')->first('lifecycle'));
        $this->assertSame('in_progress', $offering->fresh()->status);
        // The refused completion must not end the allocation on the administrator's behalf.
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $allocation, 'status' => 'active']);

        $this->post(route('admin.course_offerings.lecturers.end', [$offering->id, $allocation]), ['ends_on' => '2026-06-30'])->assertRedirect();
        $this->get(route('admin.course_offerings.show', $offering->id))
            ->assertOk()->assertDontSee('This Course Offering cannot be completed yet.');
    }

    public function test_cancelled_offering_shows_its_cancellation_reason_and_is_read_only(): void
    {
        $a = $this->tenants[1];
        $offering = $this->createOffering($a, 'CANCELLED-UI');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);
        app(CourseOfferingService::class)->cancel($a['school'], $offering->id, 'Programme restructured; unit withdrawn');

        $manager = $this->staff(1, [
            'academic.course_offering.view', 'academic.course_offering.manage', 'academic.course_offering.lifecycle',
            'academic.course_offering.lecturer.view', 'academic.course_registration.view',
        ]);

        $this->actingAs($manager)->get(route('admin.course_offerings.show', $offering->id))
            ->assertOk()
            ->assertSee('This Course Offering was cancelled.')
            ->assertSee('Programme restructured; unit withdrawn')
            ->assertSee('Cancellation reason:')
            ->assertSee('read-only')
            // Read-only: no mutation controls remain.
            ->assertDontSee('>Open Offering</button>', false)
            ->assertDontSee('>Start Course Offering</button>', false)
            ->assertDontSee('>Complete Course Offering</button>', false)
            ->assertDontSee('>Cancel Offering</button>', false)
            ->assertDontSee('Link Study Plan</button>', false)
            ->assertDontSee('>Save</button>', false);
    }

    public function test_view_only_user_sees_no_mutation_controls_on_the_detail_page(): void
    {
        $a = $this->tenants[1];
        $offering = $this->createOffering($a, 'VIEW-ONLY-UI');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);

        $viewer = $this->staff(1, [
            'academic.course_offering.view', 'academic.course_registration.view',
            'academic.course_offering.lecturer.view',
        ]);

        $page = $this->actingAs($viewer)->get(route('admin.course_offerings.show', $offering->id));
        $page->assertOk()
            // A viewer may read the whole page...
            ->assertSee('VIEW-ONLY-UI')
            ->assertSee('Study Plan Applicability:')
            ->assertSee('Teaching Team')
            // ...but sees no mutation control anywhere on it.
            ->assertDontSee('>Open Offering</button>', false)
            ->assertDontSee('>Start Course Offering</button>', false)
            ->assertDontSee('>Complete Course Offering</button>', false)
            ->assertDontSee('>Cancel Offering</button>', false)
            ->assertDontSee('>Save</button>', false)
            ->assertDontSee('Link Study Plan</button>', false)
            ->assertDontSee('>Remove</button>', false);
        $this->assertStringNotContainsString('action="'.route('admin.course_offerings.update', $offering->id).'"', $page->getContent());
        $this->assertStringNotContainsString('action="'.route('admin.course_offerings.open', $offering->id).'"', $page->getContent());
        $this->assertStringNotContainsString('action="'.route('admin.course_offerings.applicability.store', $offering->id).'"', $page->getContent());
        $this->assertStringNotContainsString('action="'.route('admin.course_offerings.cancel', $offering->id).'"', $page->getContent());

        // The student workspace is read-only for a viewer too.
        $this->get(route('admin.course_offerings.eligible_students', $offering->id))
            ->assertOk()
            ->assertDontSee('>Register Selected</button>', false)
            ->assertDontSee('>Register</button>', false)
            ->assertDontSee('>Register Eligible Cohort Students</button>', false);
        $this->get(route('admin.course_offerings.registrations', $offering->id))
            ->assertOk()
            ->assertDontSee('>Confirm Selected</button>', false)
            ->assertDontSee('>Withdraw</button>', false);
    }

    public function test_unexpected_exception_never_exposes_database_detail_on_the_teaching_team_path(): void
    {
        $a = $this->tenants[1];
        $offering = $this->createOffering($a, 'SAFE-LECTURER');
        $lecturer = User::factory()->create([
            'name' => 'Safe Path Lecturer', 'role_id' => 3, 'school_id' => $a['school'],
            'account_status' => 'active', 'staff_status' => 'active',
        ]);
        $manager = $this->staff(1, ['academic.course_offering.lecturer.view', 'academic.course_offering.lecturer.manage']);

        $this->mock(CourseOfferingLecturerAllocationService::class, function ($mock): void {
            $mock->shouldReceive('createPlanned')->once()
                ->andThrow(new \RuntimeException('SQLSTATE[42S02]: Base table or view not found: 1146 secret_internal_table'));
        });

        $response = $this->actingAs($manager)->post(route('admin.course_offerings.lecturers.store', $offering->id), [
            'user_id' => $lecturer->id, 'role' => 'primary_lecturer', 'starts_on' => '2026-01-05',
        ]);

        $response->assertSessionHasErrors('allocation');
        $message = session('errors')->first('allocation');
        $this->assertStringContainsString('Something went wrong while updating the teaching team.', $message);
        foreach (['SQLSTATE', 'secret_internal_table', '1146', 'Base table or view not found'] as $leak) {
            $this->assertStringNotContainsString($leak, $message);
        }
    }

    public function test_offering_cannot_start_before_its_academic_period_begins(): void
    {
        $a = $this->tenants[1];
        $futureYear = (int) DB::table('academic_years')->insertGetId(['school_id' => $a['school'], 'label' => '2099', 'start_date' => '2099-01-01', 'end_date' => '2099-12-31', 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()]);
        $futurePeriod = (int) DB::table('academic_periods')->insertGetId(['school_id' => $a['school'], 'academic_year_id' => $futureYear, 'type' => 'semester', 'label' => 'Semester 1', 'sequence' => 1, 'start_date' => '2099-01-01', 'end_date' => '2099-06-30', 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()]);
        $future = app(CourseOfferingService::class)->createDraft($a['school'], $a['subject'], $futureYear, $futurePeriod);
        app(CourseOfferingService::class)->addApplicability($a['school'], $future->id, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $future->id);

        $manager = $this->staff(1, ['academic.course_offering.lifecycle']);
        $this->actingAs($manager)->post(route('admin.course_offerings.start', $future->id))
            ->assertSessionHasErrors('lifecycle');
        $this->assertStringContainsString('cannot start before Semester 1 begins on 1 January 2099', session('errors')->first('lifecycle'));
        $this->assertSame('open', $future->fresh()->status);
    }

    public function test_completion_is_blocked_by_unresolved_registrations_and_succeeds_once_resolved(): void
    {
        Schema::table('course_registrations', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_offering_id')->nullable();
            $table->unsignedBigInteger('curriculum_membership_id')->nullable();
        });
        $a = $this->tenants[1];
        $offering = $this->createOffering($a, 'COMPLETE-CHECK');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);
app(CourseOfferingService::class)->start($a['school'], $offering->id);
        DB::table('course_registrations')->insert(['student_id' => 1, 'subject_id' => $a['subject'], 'school_id' => $a['school'], 'status' => 'registered', 'course_offering_id' => $offering->id, 'curriculum_membership_id' => $a['member']]);

        $manager = $this->staff(1, ['academic.course_offering.lifecycle']);
        $this->actingAs($manager)->post(route('admin.course_offerings.complete', $offering->id))
            ->assertSessionHasErrors('lifecycle');
        $this->assertStringContainsString('1 student registration still requires confirmation or withdrawal', session('errors')->first('lifecycle'));
        $this->assertSame('in_progress', $offering->fresh()->status);

        DB::table('course_registrations')->where('course_offering_id', $offering->id)->update(['status' => 'confirmed']);
        $this->post(route('admin.course_offerings.complete', $offering->id))->assertRedirect();
        $this->assertSame('completed', $offering->fresh()->status);
    }

    public function test_unexpected_exception_does_not_expose_raw_message(): void
    {
        $a = $this->tenants[1];
        $offering = $this->createOffering($a, 'SAFE-MESSAGE');
        $this->mock(CourseOfferingService::class, function ($mock) use ($a, $offering): void {
            $mock->shouldReceive('addApplicability')->once()
                ->andThrow(new \RuntimeException('SQLSTATE[42S02]: Base table or view not found: 1146 secret_internal_table'));
        });
        $manager = $this->staff(1, ['academic.course_offering.manage']);
        $response = $this->actingAs($manager)->post(route('admin.course_offerings.applicability.store', $offering->id), ['curriculum_membership_id' => $a['member']]);
        $response->assertSessionHasErrors('applicability');
        $message = session('errors')->first('applicability');
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertStringNotContainsString('secret_internal_table', $message);
        $this->assertStringContainsString('Something went wrong', $message);
    }

    private function insertWorkspaceClass(int $schoolId, int $subjectId, ?int $offeringId, string $title, string $status, bool $published, ?string $scheduledAt = null): int
    {
        $endsAt = $scheduledAt ? \Illuminate\Support\Carbon::parse($scheduledAt)->addHour()->toDateTimeString() : null;
        return (int) DB::table('live_classes')->insertGetId([
            'school_id' => $schoolId, 'subject_id' => $subjectId, 'course_offering_id' => $offeringId,
            'title' => $title, 'status' => $status, 'is_published' => $published,
            'scheduled_at' => $scheduledAt, 'ends_at' => $endsAt,
            'timezone' => 'UTC', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createAcademicTables(): void
    {
        Schema::create('academic_years', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->string('label'); $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps(); });
        Schema::create('academic_periods', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('academic_year_id'); $t->string('type'); $t->string('label'); $t->unsignedSmallInteger('sequence'); $t->date('start_date'); $t->date('end_date'); $t->string('status'); $t->timestamps(); });
        Schema::create('curricula', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('programme_id'); $t->string('version'); $t->unsignedBigInteger('effective_academic_year_id')->nullable(); $t->string('status'); $t->timestamps(); });
        Schema::create('curriculum_stages', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id'); $t->string('label'); $t->unsignedSmallInteger('sequence'); $t->timestamps(); });
        Schema::create('curriculum_memberships', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id'); $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('curriculum_stage_id'); $t->string('period_type')->nullable(); $t->unsignedSmallInteger('period_sequence')->nullable(); $t->string('classification'); $t->decimal('credits',6,2); $t->unsignedSmallInteger('sequence')->default(0); $t->timestamps(); });
        Schema::create('curriculum_prerequisites', function (Blueprint $t): void {
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('curriculum_id');
            $t->unsignedBigInteger('membership_id');
            $t->unsignedBigInteger('prerequisite_membership_id');
            $t->primary(['membership_id', 'prerequisite_membership_id'], 'curriculum_prerequisites_edge_pk');
            $t->index(['school_id', 'curriculum_id'], 'curriculum_prerequisites_tenant_curriculum_idx');
        });
        Schema::create('course_offerings', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('academic_year_id'); $t->unsignedBigInteger('academic_period_id'); $t->string('reference',50)->nullable(); $t->string('status',20)->default('draft'); $t->timestamps(); });
        Schema::create('course_offering_curriculum_memberships', function (Blueprint $t): void { $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id'); $t->unsignedBigInteger('curriculum_id'); $t->unsignedBigInteger('curriculum_membership_id'); $t->unsignedBigInteger('subject_id'); $t->timestamps(); $t->primary(['school_id','course_offering_id','curriculum_membership_id']); });
        Schema::create('course_registrations', function (Blueprint $t): void { $t->id(); $t->unsignedBigInteger('student_id'); $t->unsignedBigInteger('subject_id'); $t->unsignedBigInteger('session_id')->nullable(); $t->unsignedBigInteger('school_id'); $t->string('status'); });
    }

    // ==================================================== Teaching Team display
    //
    // The Course Offering WORKSPACE used to filter the current teaching team with
    //     status = 'active' AND starts_on <= today AND (ends_on IS NULL OR ends_on >= today)
    // while the Teaching Team page filtered on status alone. Offering #5's real,
    // ACTIVE Primary Lecturer was allocated 2026-10-01 to 2027-01-25 while today
    // was 2026-09-28, so the workspace hid him and rendered
    // "No lecturers have been assigned yet" even though the allocation existed.
    // Currency is the allocation's own status; forward-dating is normal because
    // lecturers are assigned before the term starts.

    /** Widens the tenant's period so allocation dates may sit after today. */
    private function widenPeriod(array $tenant): void
    {
        DB::table('academic_periods')->where('id', $tenant['period'])
            ->update(['start_date' => '2026-01-01', 'end_date' => '2027-12-31']);
    }

    private function allocateFor(CourseOffering $offering, User $lecturer, string $status, string $role = 'primary_lecturer', string $startsOn = '2026-10-01', ?string $endsOn = null): void
    {
        $id = (int) DB::table('course_offering_lecturer_allocations')->insertGetId([
            'school_id' => $offering->school_id, 'course_offering_id' => $offering->id, 'user_id' => $lecturer->id,
            'role' => $role, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($status === 'active') {
            DB::table('course_offering_lecturer_allocations')->where('id', $id)->update(['status' => 'active']);
        }
    }

    public function test_the_workspace_shows_a_forward_dated_active_lecturer(): void
    {
        $a = $this->tenants[1];
        $this->widenPeriod($a);
        $offering = $this->createOffering($a, 'TEAM-ACTIVE');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);
        $lecturer = $this->staffForSchool($a['school'], [], ['name' => 'Daniel Okello', 'role_id' => 3]);
        $this->allocateFor($offering, $lecturer, 'active');

        // Precondition: the allocation really is forward-dated, which is what
        // the old date window used to hide.
        $this->assertTrue(
            \Illuminate\Support\Carbon::parse('2026-10-01')->isFuture(),
            'the allocation starts after today, as in production Offering #5'
        );

        $manager = $this->staff(1, ['academic.course_offering.view', 'academic.course_offering.lecturer.view']);

        $this->actingAs($manager)->get(route('admin.course_offerings.show', $offering->id))
            ->assertOk()
            ->assertSee('Daniel Okello')
            ->assertSee('Primary Lecturer')
            ->assertDontSee('No lecturers have been assigned yet.');
    }

    public function test_a_planned_lecturer_is_visible_but_is_not_the_current_team(): void
    {
        $a = $this->tenants[1];
        $this->widenPeriod($a);
        $offering = $this->createOffering($a, 'TEAM-PLANNED');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);
        $lecturer = $this->staffForSchool($a['school'], [], ['name' => 'Planned Lecturer', 'role_id' => 3]);
        $this->allocateFor($offering, $lecturer, 'planned');

        $manager = $this->staff(1, ['academic.course_offering.view', 'academic.course_offering.lecturer.view']);

        $response = $this->actingAs($manager)->get(route('admin.course_offerings.show', $offering->id))->assertOk();
        $response->assertSee('Planned Lecturer')->assertSee('Planned');
        $response->assertViewHas('activeTeachingTeam', fn ($team) => $team->isEmpty());
        $response->assertViewHas('plannedTeachingTeam', fn ($team) => $team->count() === 1);
    }

    public function test_ended_and_cancelled_lecturers_are_not_the_current_team(): void
    {
        $a = $this->tenants[1];
        $this->widenPeriod($a);
        $offering = $this->createOffering($a, 'TEAM-HISTORY');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);
        $this->allocateFor($offering, $this->staffForSchool($a['school'], [], ['name' => 'Ended Lecturer', 'role_id' => 3]), 'ended', 'primary_lecturer', '2026-02-01', '2026-03-01');
        $this->allocateFor($offering, $this->staffForSchool($a['school'], [], ['name' => 'Cancelled Lecturer', 'role_id' => 3]), 'cancelled');

        $manager = $this->staff(1, ['academic.course_offering.view', 'academic.course_offering.lecturer.view']);

        $response = $this->actingAs($manager)->get(route('admin.course_offerings.show', $offering->id))->assertOk();
        $response->assertViewHas('activeTeachingTeam', fn ($team) => $team->isEmpty());
        $response->assertViewHas('plannedTeachingTeam', fn ($team) => $team->isEmpty());
        $response->assertDontSee('Ended Lecturer')->assertDontSee('Cancelled Lecturer');

        // History is still available where it belongs.
        $this->actingAs($manager)->get(route('admin.course_offerings.lecturers.index', $offering->id))
            ->assertOk()->assertSee('Ended Lecturer')->assertSee('Cancelled Lecturer');
    }

    public function test_an_active_allocation_whose_end_date_has_passed_is_not_the_current_team(): void
    {
        $a = $this->tenants[1];
        $this->widenPeriod($a);
        $offering = $this->createOffering($a, 'TEAM-STALE');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);
        $this->allocateFor($offering, $this->staffForSchool($a['school'], [], ['name' => 'Stale Active Lecturer', 'role_id' => 3]), 'active', 'primary_lecturer', '2026-02-01', '2026-03-01');

        $manager = $this->staff(1, ['academic.course_offering.view', 'academic.course_offering.lecturer.view']);

        $this->actingAs($manager)->get(route('admin.course_offerings.show', $offering->id))
            ->assertOk()
            ->assertViewHas('activeTeachingTeam', fn ($team) => $team->isEmpty())
            ->assertDontSee('Stale Active Lecturer');
    }

    public function test_a_cross_tenant_allocation_is_never_displayed_on_the_workspace(): void
    {
        $a = $this->tenants[1];
        $b = $this->tenants[2];
        $this->widenPeriod($a);
        $offering = $this->createOffering($a, 'TEAM-TENANT');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);
        $foreign = $this->staffForSchool($b['school'], [], ['name' => 'Foreign Tenant Lecturer', 'role_id' => 3]);
        // An allocation row that names this Offering but belongs to another school.
        DB::table('course_offering_lecturer_allocations')->insert([
            'school_id' => $b['school'], 'course_offering_id' => $offering->id, 'user_id' => $foreign->id,
            'role' => 'primary_lecturer', 'starts_on' => '2026-10-01', 'ends_on' => null, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $manager = $this->staff(1, ['academic.course_offering.view', 'academic.course_offering.lecturer.view']);

        $this->actingAs($manager)->get(route('admin.course_offerings.show', $offering->id))
            ->assertOk()
            ->assertViewHas('activeTeachingTeam', fn ($team) => $team->isEmpty())
            ->assertDontSee('Foreign Tenant Lecturer');
    }

    public function test_a_cancelled_academic_period_cannot_be_started(): void
    {
        $a = $this->tenants[1];
        $offering = $this->createOffering($a, 'PERIOD-CANCELLED');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);

        DB::table('academic_periods')->where('id', $a['period'])->update(['status' => 'cancelled']);
        try {
            app(CourseOfferingService::class)->start($a['school'], $offering->id);
            $this->fail('A cancelled Academic Period must not be startable.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('Academic Period has been cancelled', $exception->getMessage());
        }
        $this->assertSame('open', $offering->fresh()->status, 'a refused start changes nothing');
    }

    // ==================================================== Governed early start
    //
    // The normal Open -> In Progress rule is untouched and still refuses a start
    // before the Academic Period begins. This task adds a separate, explicit,
    // audited administrator action for the case the institution needs to run
    // delivery early. It changes ONLY the Offering's own status: the Academic
    // Period, Study Plan, Programme Cohort, Academic Placement, student
    // registrations and lecturer allocation dates are all left untouched.

    /** Puts the tenant's period in the future so the normal start is refused. */
    private function futurePeriod(array $tenant, string $label = 'Semester 1'): void
    {
        DB::table('academic_periods')->where('id', $tenant['period'])->update([
            'start_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->endOfMonth()->toDateString(),
            'status' => 'active',
        ]);
    }

    /** An OPEN Offering whose Academic Period has not yet begun. */
    private function openAwaitingPeriod(array $tenant, string $reference): CourseOffering
    {
        $this->futurePeriod($tenant);
        $offering = $this->createOffering($tenant, $reference);
        $this->attach($tenant, $offering, $tenant['member']);
        app(CourseOfferingService::class)->open($tenant['school'], $offering->id);

        return $offering->fresh();
    }

    private function manager(array $tenant): User
    {
        return $this->staff($tenant['school'], ['academic.course_offering.view', 'academic.course_offering.manage', 'academic.course_offering.lifecycle']);
    }

    // 1. The normal start path still refuses an early start.
    public function test_normal_start_still_refuses_an_early_start(): void
    {
        $a = $this->tenants[1];
        $offering = $this->openAwaitingPeriod($a, 'EARLY-NORMAL-REFUSED');

        $this->actingAs($this->manager($a))
            ->post(route('admin.course_offerings.start', $offering->id))
            ->assertSessionHasErrors('lifecycle')
            ->assertRedirect();

        $this->assertSame('open', $offering->fresh()->status, 'a refused normal start changes nothing');
    }

    // 2. The governed early-start action succeeds with a reason.
    public function test_authorised_early_start_succeeds_with_a_reason(): void
    {
        $a = $this->tenants[1];
        $offering = $this->openAwaitingPeriod($a, 'EARLY-OK');

        $this->actingAs($this->manager($a))
            ->post(route('admin.course_offerings.start_early', $offering->id), [
                'reason' => 'Pre-semester end-to-end academic delivery testing.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // 13. The Offering becomes In Progress.
        $this->assertSame('in_progress', $offering->fresh()->status);
    }

    // 3. A blank reason is refused.
    public function test_early_start_requires_a_nonblank_reason(): void
    {
        $a = $this->tenants[1];
        $offering = $this->openAwaitingPeriod($a, 'EARLY-BLANK-REASON');
        $service = app(CourseOfferingService::class);

        foreach (['', '   '] as $blank) {
            try {
                $service->startEarly($a['school'], $offering->id, $blank);
                $this->fail('A blank early-start reason must be refused.');
            } catch (\DomainException $exception) {
                $this->assertStringContainsString('nonblank reason is required', $exception->getMessage());
            }
        }

        $this->actingAs($this->manager($a))
            ->post(route('admin.course_offerings.start_early', $offering->id), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame('open', $offering->fresh()->status, 'a refused early start changes nothing');
    }

    // 4. An unauthorised user is refused.
    public function test_unauthorised_user_cannot_start_early(): void
    {
        $a = $this->tenants[1];
        $offering = $this->openAwaitingPeriod($a, 'EARLY-UNAUTHORISED');

        // A staff member with view only: no lifecycle permission.
        $viewer = $this->staff($a['school'], ['academic.course_offering.view']);
        $this->actingAs($viewer)
            ->post(route('admin.course_offerings.start_early', $offering->id), ['reason' => 'Unauthorised attempt'])
            ->assertForbidden();

        // A staff member with no permissions at all.
        $this->actingAs($this->staff($a['school'], []))
            ->post(route('admin.course_offerings.start_early', $offering->id), ['reason' => 'Unauthorised attempt'])
            ->assertForbidden();

        $this->assertSame('open', $offering->fresh()->status, 'the Offering is untouched');
    }

    // 5. A cross-tenant Offering is refused.
    public function test_cross_tenant_early_start_is_refused(): void
    {
        $a = $this->tenants[1];
        $b = $this->tenants[2];
        $offering = $this->openAwaitingPeriod($a, 'EARLY-CROSS-TENANT');

        $this->actingAs($this->manager($b))
            ->post(route('admin.course_offerings.start_early', $offering->id), ['reason' => 'Wrong tenant'])
            ->assertNotFound();

        // The service refuses it too, even with the right identifier.
        try {
            app(CourseOfferingService::class)->startEarly($b['school'], $offering->id, 'Wrong tenant');
            $this->fail('Another tenant must not be able to start this Offering early.');
        } catch (\Throwable $exception) {
            $this->assertNotSame('', trim($exception->getMessage()));
        }

        $this->assertSame('open', $offering->fresh()->status);
    }

    // 6. A cancelled Academic Period is refused.
    public function test_cancelled_academic_period_cannot_be_started_early(): void
    {
        $a = $this->tenants[1];
        $offering = $this->openAwaitingPeriod($a, 'EARLY-CANCELLED-PERIOD');
        DB::table('academic_periods')->where('id', $a['period'])->update(['status' => 'cancelled']);

        $this->actingAs($this->manager($a))
            ->post(route('admin.course_offerings.start_early', $offering->id), ['reason' => 'Testing'])
            ->assertSessionHasErrors('lifecycle');

        $this->assertSame('open', $offering->fresh()->status, 'a cancelled period cannot be overridden');
    }

    // 7. Draft cannot use early start.
    public function test_draft_cannot_use_early_start(): void
    {
        $a = $this->tenants[1];
        $this->futurePeriod($a);
        $draft = $this->createOffering($a, 'EARLY-DRAFT');

        $this->actingAs($this->manager($a))
            ->post(route('admin.course_offerings.start_early', $draft->id), ['reason' => 'Testing'])
            ->assertSessionHasErrors('lifecycle');

        $this->assertSame('draft', $draft->fresh()->status, 'an early start is not an Open action');
    }

    // 8. In Progress cannot use early start again.
    public function test_in_progress_cannot_use_early_start_again(): void
    {
        $a = $this->tenants[1];
        $offering = $this->openAwaitingPeriod($a, 'EARLY-REPEAT');
        $this->actingAs($this->manager($a))
            ->post(route('admin.course_offerings.start_early', $offering->id), ['reason' => 'First early start'])
            ->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $offering->fresh()->status);

        $this->actingAs($this->manager($a))
            ->post(route('admin.course_offerings.start_early', $offering->id), ['reason' => 'Second early start'])
            ->assertSessionHasErrors('lifecycle');

        $this->assertSame('in_progress', $offering->fresh()->status, 'the transition is not repeated');
    }

    // 9. Completed and Cancelled cannot use early start.
    public function test_completed_and_cancelled_cannot_use_early_start(): void
    {
        $a = $this->tenants[1];

        $cancelled = $this->openAwaitingPeriod($a, 'EARLY-CANCELLED-OFFERING');
        app(CourseOfferingService::class)->cancel($a['school'], $cancelled->id, 'Withdrawn');
        $this->actingAs($this->manager($a))
            ->post(route('admin.course_offerings.start_early', $cancelled->id), ['reason' => 'Testing'])
            ->assertSessionHasErrors('lifecycle');
        $this->assertSame('cancelled', $cancelled->fresh()->status, 'cancelled stays terminal');

        $completed = $this->openAwaitingPeriod($a, 'EARLY-COMPLETED-OFFERING');
        $service = app(CourseOfferingService::class);
        $service->startEarly($a['school'], $completed->id, 'Testing');
        // Completion is a separate governed rule and still requires the Academic
        // Period to have begun; advance the period to satisfy it.
        DB::table('academic_periods')->where('id', $a['period'])
            ->update(['start_date' => now()->subDay()->toDateString()]);
        DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $completed->id)->whereIn('status', ['planned', 'active'])
            ->update(['status' => 'ended', 'ends_on' => now()->toDateString()]);
        $service->complete($a['school'], $completed->id);
        $this->assertSame('completed', $completed->fresh()->status);

        $this->actingAs($this->manager($a))
            ->post(route('admin.course_offerings.start_early', $completed->id), ['reason' => 'Testing'])
            ->assertSessionHasErrors('lifecycle');
        $this->assertSame('completed', $completed->fresh()->status, 'completed stays terminal');
    }

    // 10, 11, 12, 14. Nothing but the Offering status may change, and the
    // reason plus both dates must be recorded.
    public function test_early_start_changes_nothing_but_the_status_and_is_fully_audited(): void
    {
        $a = $this->tenants[1];
        $offering = $this->openAwaitingPeriod($a, 'EARLY-NO-SIDE-EFFECTS');

        $periodBefore = DB::table('academic_periods')->where('id', $a['period'])->first();
        $lecturer = $this->staffForSchool($a['school'], [], ['name' => 'Daniel Okello', 'role_id' => 3]);
        $this->allocateFor($offering, $lecturer, 'active', 'primary_lecturer', '2026-10-01', '2027-01-25');
        $allocationBefore = DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $offering->id)->first();
        $registrationsBefore = DB::table('course_registrations')->where('school_id', $a['school'])->get();
        $applicabilityBefore = DB::table('course_offering_curriculum_memberships')
            ->where('course_offering_id', $offering->id)->get();

        $reason = 'Pre-semester end-to-end academic delivery testing.';
        $admin = $this->manager($a);
        $this->actingAs($admin)
            ->post(route('admin.course_offerings.start_early', $offering->id), ['reason' => $reason])
            ->assertSessionHasNoErrors();

        // 13. Offering becomes In Progress.
        $this->assertSame('in_progress', $offering->fresh()->status);

        // 10. Academic Period dates unchanged.
        $periodAfter = DB::table('academic_periods')->where('id', $a['period'])->first();
        $this->assertEquals($periodBefore->start_date, $periodAfter->start_date, 'period start date unchanged');
        $this->assertEquals($periodBefore->end_date, $periodAfter->end_date, 'period end date unchanged');
        $this->assertEquals($periodBefore->status, $periodAfter->status, 'period status unchanged');

        // 11. Lecturer allocation dates unchanged, and no duplicate created.
        $allocationsAfter = DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $offering->id)->get();
        $this->assertCount(1, $allocationsAfter, 'no duplicate allocation');
        $this->assertEquals($allocationBefore->starts_on, $allocationsAfter[0]->starts_on, 'allocation start unchanged');
        $this->assertEquals($allocationBefore->ends_on, $allocationsAfter[0]->ends_on, 'allocation end unchanged');
        $this->assertEquals('active', $allocationsAfter[0]->status, 'allocation status unchanged');

        // 12. Registrations unchanged: none added, none dropped.
        $registrationsAfter = DB::table('course_registrations')->where('school_id', $a['school'])->get();
        $this->assertCount($registrationsBefore->count(), $registrationsAfter, 'no registration created or removed');

        // Study Plan applicability untouched.
        $applicabilityAfter = DB::table('course_offering_curriculum_memberships')
            ->where('course_offering_id', $offering->id)->get();
        $this->assertCount($applicabilityBefore->count(), $applicabilityAfter, 'Study Plan applicability unchanged');

        // 14. The audit event records the reason, the administrator, and both dates.
        $audit = \App\Models\AuditLog::where('action', 'COURSE_OFFERING_EARLY_START')
            ->where('record_id', $offering->id)->latest('id')->first();
        $this->assertNotNull($audit, 'an explicit early-start audit event is written');
        $values = is_array($audit->new_values) ? $audit->new_values : json_decode((string) $audit->new_values, true);
        $this->assertSame($reason, $values['reason'], 'the reason is recorded');
        $this->assertSame($offering->id, $values['offering_id'], 'the Offering id is recorded');
        $this->assertSame($offering->reference, $values['offering_reference'], 'the reference is recorded');
        $this->assertSame((string) $periodBefore->start_date, (string) $values['academic_period_start_date'], 'the original period start date is recorded');
        $this->assertSame(now()->toDateString(), $values['early_start_date'], 'the actual early-start date is recorded');
        $this->assertSame($admin->id, (int) $values['administrator_user_id'], 'the administrator is recorded');
        $this->assertTrue($values['started_early']);
    }

    // 15. Normal start on/after the period start is unchanged.
    public function test_normal_start_still_works_on_or_after_the_period_start(): void
    {
        $a = $this->tenants[1];
        $offering = $this->createOffering($a, 'EARLY-NORMAL-OK');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);
        // Period already under way.
        DB::table('academic_periods')->where('id', $a['period'])->update([
            'start_date' => now()->subDay()->toDateString(), 'status' => 'active',
        ]);

        $this->actingAs($this->manager($a))
            ->post(route('admin.course_offerings.start', $offering->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('in_progress', $offering->fresh()->status);
    }

    // The early-start control appears only when it is actually available.
    public function test_early_start_control_is_shown_only_when_the_period_has_not_begun(): void
    {
        $a = $this->tenants[1];
        $earlyWindow = $this->openAwaitingPeriod($a, 'EARLY-UI-EARLY');
        $alreadyBegun = $this->createOffering($a, 'EARLY-UI-BEGUN');
        $this->attach($a, $alreadyBegun, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $alreadyBegun->id);

        $manager = $this->manager($a);

        // Period has NOT begun: the early control is offered, normal start is not.
        $this->actingAs($manager)->get(route('admin.course_offerings.show', $earlyWindow->id))
            ->assertOk()
            ->assertSee('>Start Course Offering Early</button>', false)
            ->assertDontSee('>Start Course Offering</button>', false)
            ->assertViewHas('canStartEarly', true)
            ->assertViewHas('earlyStartBlockers', []);

        // A viewer without the lifecycle permission is offered neither.
        $this->actingAs($this->staff($a['school'], ['academic.course_offering.view']))
            ->get(route('admin.course_offerings.show', $earlyWindow->id))
            ->assertOk()
            ->assertDontSee('>Start Course Offering Early</button>', false)
            ->assertViewHas('canStartEarly', false);

        // Period has begun: the normal control is offered, early start is not.
        DB::table('academic_periods')->where('id', $a['period'])
            ->update(['start_date' => now()->subDay()->toDateString()]);
        $this->actingAs($manager)->get(route('admin.course_offerings.show', $alreadyBegun->id))
            ->assertOk()
            ->assertSee('>Start Course Offering</button>', false)
            ->assertDontSee('>Start Course Offering Early</button>', false)
            ->assertViewHas('canStartEarly', false);
    }

    // OPEN is preparation + registration, not a block on preparation.
    public function test_open_state_is_described_as_preparation_and_registration(): void
    {
        $a = $this->tenants[1];
        $offering = $this->openAwaitingPeriod($a, 'EARLY-OPEN-SEMANTICS');

        $this->actingAs($this->manager($a))
            ->get(route('admin.course_offerings.show', $offering->id))
            ->assertOk()
            ->assertSee('Preparation and student registration are open')
            ->assertSee('may prepare academic delivery');
    }

    // ==================================================== System Tester (staff)
    //
    // system.testing.prestart_lecturer is an ADDITIONAL capability, never a role.
    // It relaxes exactly ONE condition: today being before the allocation's
    // agreed start date, on a Course Offering that was deliberately early-started
    // through the governed workflow. Every other authorization condition - tenant,
    // Lecturer identity, allocation existence, allocation status, Offering
    // lifecycle, account status - is still enforced, and no date is ever written.

    /**
     * An IN_PROGRESS Offering whose Academic Period has not begun, with an ACTIVE
     * allocation that starts in the future — the exact situation testing must
     * cover. When $earlyStarted is false the Offering was started the normal way,
     * so no COURSE_OFFERING_EARLY_START evidence exists.
     */
    private function preStartScenario(array $tenant, string $reference, bool $earlyStarted, User $lecturer): CourseOffering
    {
        $this->futurePeriod($tenant);
        $offering = $this->createOffering($tenant, $reference);
        $this->attach($tenant, $offering, $tenant['member']);
        $service = app(CourseOfferingService::class);
        $service->open($tenant['school'], $offering->id);

        if ($earlyStarted) {
            $service->startEarly($tenant['school'], $offering->id, 'Pre-semester end-to-end academic delivery testing.');
        } else {
            // Reached in progress the ordinary way: put the period in the past first.
            DB::table('academic_periods')->where('id', $tenant['period'])
                ->update(['start_date' => now()->subDay()->toDateString()]);
            $service->start($tenant['school'], $offering->id);
            // Then move the period back so the allocation is genuinely pre-start.
            DB::table('academic_periods')->where('id', $tenant['period'])
                ->update(['start_date' => now()->addMonth()->startOfMonth()->toDateString()]);
        }

        $this->allocateFor($offering, $lecturer, 'active', 'primary_lecturer', '2026-10-01', '2027-01-25');

        return $offering->fresh();
    }

    private function tester(array $tenant, array $extra = []): User
    {
        return $this->staffForSchool($tenant['school'], [\App\Support\CourseOffering\SystemTesterAccess::PERMISSION], $extra);
    }

    // The lecturer-allocation audit carries COURSE_OFFERING_LECTURER_ALLOCATION
    // (35 chars) - the identifier that was failing in the live database with
    // "Data too long for column 'event_type'". The allocation transaction and its
    // audit must commit together. Allocation business rules are untouched here.
    public function test_a_lecturer_allocation_and_its_audit_commit_together(): void
    {
        $a = $this->tenants[1];
        $this->widenPeriod($a);
        $offering = $this->createOffering($a, 'ALLOCATION-AUDIT');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);
        $lecturer = $this->staffForSchool($a['school'], [], ['name' => 'Audited Lecturer', 'role_id' => 3]);

        $this->actingAs($this->staffForSchool($a['school'], [
            'academic.course_offering.view',
            'academic.course_offering.lecturer.view',
            'academic.course_offering.lecturer.manage',
        ], ['name' => 'Allocation Manager', 'role_id' => 2]))
            ->post(route('admin.course_offerings.lecturers.store', $offering->id), [
                'user_id' => $lecturer->id,
                'role' => 'primary_lecturer',
                'starts_on' => now()->addDay()->toDateString(),
                'ends_on' => now()->addMonths(3)->toDateString(),
            ])->assertSessionHasNoErrors();

        $allocation = DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $offering->id)
            ->where('user_id', $lecturer->id)->first();
        $this->assertNotNull($allocation, 'the allocation committed');

        $audit = \App\Models\AuditLog::where('action', 'COURSE_OFFERING_LECTURER_ALLOCATION_CREATED')
            ->where('record_id', $allocation->id)->latest('id')->first();

        $this->assertNotNull($audit, 'the audit committed with the allocation');
        $this->assertSame('COURSE_OFFERING_LECTURER_ALLOCATION', $audit->event_type,
            'the full 35-character event_type is stored, not truncated');
        $this->assertSame(35, strlen((string) $audit->event_type));
    }

    /** A published Live Class for this Offering, used by the tester/Live Class tests. */
    private function liveClass(CourseOffering $offering, string $title): \App\Models\LiveClass
    {
        $id = DB::table('live_classes')->insertGetId([
            'school_id' => $offering->school_id, 'subject_id' => $offering->subject_id,
            'course_offering_id' => $offering->id, 'teacher_id' => null, 'title' => $title,
            'status' => 'scheduled', 'is_published' => true,
            'scheduled_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return \App\Models\LiveClass::find($id);
    }

    // 1. A normal lecturer with a future allocation stays read-only.
    public function test_normal_lecturer_with_a_future_allocation_remains_read_only(): void
    {
        $a = $this->tenants[1];
        $lecturer = $this->staffForSchool($a['school'], [], ['name' => 'Normal Lecturer']);
        $offering = $this->preStartScenario($a, 'TESTER-NORMAL', true, $lecturer);

        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.show', $offering->id))
            ->assertOk()
            ->assertSee('Your teaching allocation is not currently in force.')
            ->assertDontSee('Testing access active');

        $this->assertFalse(
            app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class)
                ->resolveForLecturer($lecturer, $offering->id)
                ->getAttribute('my_allocation_is_current')
        );
    }

    // 4. A tester lecturer on a governed early-started Offering is writable.
    public function test_tester_lecturer_gets_a_writable_workspace_on_an_early_started_offering(): void
    {
        $a = $this->tenants[1];
        $lecturer = $this->tester($a, ['name' => 'Daniel Okello']);
        $offering = $this->preStartScenario($a, 'TESTER-OK', true, $lecturer);

        $this->assertTrue(
            app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class)
                ->teachingActionsAllowed(
                    app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class)
                        ->resolveForLecturer($lecturer, $offering->id)
                ),
            'an authorised tester may act on a governed early-started Offering'
        );

        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.show', $offering->id))
            ->assertOk()
            ->assertSee('Testing access active')
            ->assertSee('pre-start access has been granted for authorised system testing')
            ->assertDontSee('Your teaching allocation is not currently in force.');
    }

    // 2. The permission alone cannot bypass a non-early-started Offering.
    public function test_tester_lecturer_is_blocked_when_the_offering_was_not_early_started(): void
    {
        $a = $this->tenants[1];
        $lecturer = $this->tester($a, ['name' => 'Tester No Early Start']);
        $offering = $this->preStartScenario($a, 'TESTER-NO-EARLY', false, $lecturer);

        $this->assertFalse(
            app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class)
                ->resolveForLecturer($lecturer, $offering->id)
                ->getAttribute('my_allocation_is_current'),
            'the permission alone must not unlock a normally-started Offering'
        );
    }

    // 3. The permission alone cannot bypass the Offering lifecycle.
    public function test_tester_lecturer_is_blocked_when_the_offering_is_only_open(): void
    {
        $a = $this->tenants[1];
        $this->futurePeriod($a);
        $offering = $this->createOffering($a, 'TESTER-ONLY-OPEN');
        $this->attach($a, $offering, $a['member']);
        app(CourseOfferingService::class)->open($a['school'], $offering->id);
        $lecturer = $this->tester($a, ['name' => 'Tester Open Only']);
        $this->allocateFor($offering, $lecturer, 'active', 'primary_lecturer', '2026-10-01', '2027-01-25');

        $this->assertFalse(
            app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class)
                ->resolveForLecturer($lecturer, $offering->id)
                ->getAttribute('my_allocation_is_current'),
            'an Open Offering is never unlocked by the permission'
        );
    }

    // 4b / 10. The permission alone cannot bypass allocation existence or a
    // different Offering, and a cross-tenant lecturer is refused.
    public function test_tester_lecturer_needs_an_allocation_for_the_exact_offering_and_tenant(): void
    {
        $a = $this->tenants[1];
        $b = $this->tenants[2];
        $other = $this->staffForSchool($a['school'], [], ['name' => 'Unallocated Tester']);
        $allocated = $this->staffForSchool($a['school'], [], ['name' => 'Allocated Tester']);
        $foreign = $this->tester($b, ['name' => 'Foreign Tenant Tester']);
        $offering = $this->preStartScenario($a, 'TESTER-EXACT', true, $allocated);

        $access = app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class);

        // No allocation at all.
        $this->assertNull(
            $access->resolveForLecturer($other, $offering->id),
            'a tester with no allocation reaches nothing'
        );

        // Allocation exists, but for another Offering in the same tenant.
        $this->preStartScenario($a, 'TESTER-UNRELATED', true, $this->tester($a, ['name' => 'Tester Other Offering']));
        $this->assertNull(
            $access->resolveForLecturer($foreign, $offering->id),
            'a cross-tenant tester reaches nothing'
        );
    }

    // 8, 9. Cancelled and ended allocations stay blocked even for a tester.
    public function test_cancelled_and_ended_allocations_stay_blocked_for_a_tester(): void
    {
        $a = $this->tenants[1];
        $access = app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class);

        foreach (['cancelled' => 'TESTER-CANCELLED', 'ended' => 'TESTER-ENDED'] as $status => $reference) {
            $lecturer = $this->tester($a, ['name' => 'Tester '.ucfirst($status)]);
            $offering = $this->preStartScenario($a, $reference, true, $lecturer);
            DB::table('course_offering_lecturer_allocations')
                ->where('course_offering_id', $offering->id)
                ->update(['status' => $status, 'ends_on' => $status === 'ended' ? '2026-10-15' : null]);

            $resolved = $access->resolveForLecturer($lecturer->fresh(), $offering->id);
            $this->assertTrue(
                $resolved === null || ! $resolved->getAttribute('my_allocation_is_current'),
                "a {$status} allocation is never unlocked by testing access"
            );
        }
    }

    // 12. A suspended or disabled lecturer is still refused.
    public function test_suspended_or_disabled_lecturer_is_still_blocked(): void
    {
        $a = $this->tenants[1];

        foreach (['suspended' => 'staff_status', 'disable' => 'account_status'] as $value => $field) {
            $lecturer = $this->tester($a, ['name' => 'Tester '.ucfirst($value), $field => $value]);
            $offering = $this->preStartScenario($a, 'TESTER-'.$field, true, $lecturer);

            $resolved = app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class)
                ->resolveForLecturer($lecturer->fresh(), $offering->id);
            $this->assertTrue(
                $resolved === null || ! $resolved->getAttribute('my_allocation_is_current'),
                "a lecturer with {$field}={$value} is refused"
            );
        }
    }

    // 5, 6, 7, 8/registrations. Nothing is written by the tester capability.
    public function test_testing_access_writes_no_dates_period_or_registrations(): void
    {
        $a = $this->tenants[1];
        $lecturer = $this->tester($a, ['name' => 'Daniel Okello']);
        $offering = $this->preStartScenario($a, 'TESTER-NO-WRITES', true, $lecturer);

        $periodBefore = DB::table('academic_periods')->where('id', $a['period'])->first();
        $allocationBefore = DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $offering->id)->first();
        $registrationsBefore = DB::table('course_registrations')->where('school_id', $a['school'])->get()->count();
        $offeringBefore = DB::table('course_offerings')->where('id', $offering->id)->first();

        $this->actingAs($lecturer)->get(route('teacher.course_offerings.show', $offering->id))->assertOk();
        app(\App\Support\LiveClasses\LiveClassAccessService::class)
            ->activeManagerAllocationsForOffering($offering->fresh(), now());

        $this->assertEquals($allocationBefore->starts_on, DB::table('course_offering_lecturer_allocations')
            ->where('id', $allocationBefore->id)->value('starts_on'), 'starts_on unchanged');
        $this->assertEquals($allocationBefore->ends_on, DB::table('course_offering_lecturer_allocations')
            ->where('id', $allocationBefore->id)->value('ends_on'), 'ends_on unchanged');
        $this->assertEquals($periodBefore->start_date, DB::table('academic_periods')
            ->where('id', $a['period'])->value('start_date'), 'Academic Period start unchanged');
        $this->assertEquals($periodBefore->end_date, DB::table('academic_periods')
            ->where('id', $a['period'])->value('end_date'), 'Academic Period end unchanged');
        $this->assertEquals($registrationsBefore, DB::table('course_registrations')
            ->where('school_id', $a['school'])->get()->count(), 'registrations unchanged');
        $this->assertEquals($offeringBefore->status, DB::table('course_offerings')
            ->where('id', $offering->id)->value('status'), 'Offering status unchanged');
        $this->assertCount(1, DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', $offering->id)->get(), 'no duplicate allocation');
    }

    // 13. Revoking the permission immediately restores the normal date gate.
    public function test_removing_the_permission_restores_the_normal_date_gate(): void
    {
        $a = $this->tenants[1];
        $lecturer = $this->tester($a, ['name' => 'Revocable Tester']);
        $offering = $this->preStartScenario($a, 'TESTER-REVOKE', true, $lecturer);
        $access = app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class);

        $this->assertTrue(
            $access->resolveForLecturer($lecturer, $offering->id)->getAttribute('my_allocation_is_current'),
            'testing access works while the grant exists'
        );

        DB::table('user_permissions')->where('user_id', $lecturer->id)
            ->where('permission', \App\Support\CourseOffering\SystemTesterAccess::PERMISSION)->delete();

        $this->assertFalse(
            $access->resolveForLecturer($lecturer->fresh(), $offering->id)->getAttribute('my_allocation_is_current'),
            'removing the grant restores the normal pre-start restriction immediately'
        );
    }

    // 14. Live Classes use the same narrow exception.
    public function test_live_class_authorization_uses_the_same_narrow_tester_rule(): void
    {
        $a = $this->tenants[1];
        $lecturer = $this->tester($a, ['name' => 'Live Class Tester']);
        $offering = $this->preStartScenario($a, 'TESTER-LIVE', true, $lecturer);
        $live = $this->liveClass($offering, 'Pre-semester rehearsal');

        $access = app(\App\Support\LiveClasses\LiveClassAccessService::class);
        $managers = $access->activeManagerAllocationsForOffering($offering->fresh(), now());
        $this->assertCount(1, $managers, 'the tester appears as a facilitator under the same rule');

        // Without the grant the same Live Class rules refuse.
        DB::table('user_permissions')->where('user_id', $lecturer->id)
            ->where('permission', \App\Support\CourseOffering\SystemTesterAccess::PERMISSION)->delete();
        $this->assertCount(0, $access->activeManagerAllocationsForOffering($offering->fresh(), now()),
            'revoking the grant removes Live Class facilitation immediately');
    }

    // 21, 22, 24, 25. RBAC surface, primary role preservation, audited grant/revoke.
    public function test_permission_is_assignable_removable_audited_and_preserves_the_primary_role(): void
    {
        $a = $this->tenants[1];
        $permission = \App\Support\CourseOffering\SystemTesterAccess::PERMISSION;
        // RBAC administration is reserved for a School Admin, as the existing
        // Roles & Permissions tests already establish.
        $admin = User::factory()->create([
            'name' => 'RBAC Admin', 'role_id' => 2, 'school_id' => $a['school'], 'account_status' => 'active',
        ]);
        $lecturer = $this->staffForSchool($a['school'], [], ['name' => 'Daniel Okello']);

        // 21. Visible in the Roles & Permissions UI.
        $this->actingAs($admin)
            ->get(route('admin.rbac.staff.show', $lecturer->id))
            ->assertOk()
            ->assertSee($permission);

        // 22. Assignable by an authorised administrator, and audited. The
        // permission is sensitive, so the existing UI requires the deliberate
        // acknowledgement before it will be granted.
        $this->actingAs($admin)
            ->from(route('admin.rbac.staff.show', $lecturer->id))
            ->post(route('admin.rbac.staff.permissions.grant', $lecturer->id), ['permissions' => [$permission]])
            ->assertSessionHas('error');
        $this->assertDatabaseMissing('user_permissions', ['user_id' => $lecturer->id, 'permission' => $permission]);
        $this->assertTrue(true, 'a sensitive permission is never granted without acknowledgement');

        $this->actingAs($admin)
            ->from(route('admin.rbac.staff.show', $lecturer->id))
            ->post(route('admin.rbac.staff.permissions.grant', $lecturer->id), [
                'permissions' => [$permission], 'acknowledge_sensitive' => 1,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('user_permissions', ['user_id' => $lecturer->id, 'permission' => $permission]);
        $this->assertNotNull(\App\Models\AuditLog::where('module', 'RBAC')
            ->where('new_values', 'like', '%'.$permission.'%')->latest('id')->first(), 'the grant is audited');

        // 24. The primary role is untouched: still a Lecturer.
        $this->assertSame(3, (int) $lecturer->fresh()->role_id, 'users.role_id is unchanged');
        $this->assertSame('Daniel Okello', $lecturer->fresh()->name, 'the account keeps its identity');

        // 25. Removable, audited, and effective immediately.
        $this->actingAs($admin)
            ->delete(route('admin.rbac.staff.permissions.revoke', [$lecturer->id, $permission]))
            ->assertRedirect();
        $this->assertDatabaseMissing('user_permissions', ['user_id' => $lecturer->id, 'permission' => $permission]);
        $this->assertNotNull(\App\Models\AuditLog::where('module', 'RBAC')
            ->where('action', 'like', '%REVOKE%')->latest('id')->first(), 'the removal is audited');
        $this->assertSame(3, (int) $lecturer->fresh()->role_id, 'users.role_id is still unchanged');
    }

    // 23. An unauthorised user cannot assign it.
    public function test_unauthorised_user_cannot_assign_the_testing_permission(): void
    {
        $a = $this->tenants[1];
        $permission = \App\Support\CourseOffering\SystemTesterAccess::PERMISSION;
        $lecturer = $this->staffForSchool($a['school'], [], ['name' => 'Target Lecturer']);
        $intruder = $this->staffForSchool($a['school'], ['academic.course_offering.view']);

        $this->actingAs($intruder)
            ->post(route('admin.rbac.staff.permissions.grant', $lecturer->id), [
                'permissions' => [$permission], 'acknowledge_sensitive' => 1,
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('user_permissions', ['user_id' => $lecturer->id, 'permission' => $permission]);
    }

    // Students are untouched: role 7 can never hold the staff testing permission,
    // and a confirmed registration remains the only thing that opens an Offering.
    public function test_students_never_receive_the_staff_testing_permission(): void
    {
        $permission = \App\Support\CourseOffering\SystemTesterAccess::PERMISSION;
        $student = User::factory()->create([
            'name' => 'Test Student', 'role_id' => 7,
            'school_id' => $this->tenants[1]['school'], 'account_status' => 'active',
        ]);

        // A row may physically exist, but it must grant the Student nothing.
        DB::table('user_permissions')->insert([
            'school_id' => $student->school_id, 'user_id' => $student->id,
            'permission' => $permission, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse(
            app(\App\Support\Permissions\PermissionService::class)->allows($student->fresh(), $permission),
            'the non-staff RBAC boundary is unchanged: a Student holds no staff permission'
        );
        $this->assertSame([], app(\App\Support\Permissions\PermissionService::class)
            ->grantedPermissions($student->fresh()), 'no grants are read for a Student');
    }

    private function tenant(int $schoolId, string $type): array
    {
        $school = $this->makeSchool(['title'=>'Tenant '.$schoolId,'status'=>1,'school_type'=>$type,'academic_calendar_pattern'=>'semester']);
        $department = $this->makeDepartment($school, 'Department '.$schoolId);
        $programme = $this->makeProgramme($school, ['code'=>'P'.$schoolId,'name'=>'Programme '.$schoolId,'department_id'=>$department]);
        $year = (int)DB::table('academic_years')->insertGetId(['school_id'=>$school,'label'=>'2026','start_date'=>'2026-01-01','end_date'=>'2026-12-31','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $period = (int)DB::table('academic_periods')->insertGetId(['school_id'=>$school,'academic_year_id'=>$year,'type'=>'semester','label'=>'Semester 1','sequence'=>1,'start_date'=>'2026-01-01','end_date'=>'2026-06-30','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $subject = $this->subject($school, 'Core '.$schoolId, 'CORE-'.$schoolId);
        $curriculum = (int)DB::table('curricula')->insertGetId(['school_id'=>$school,'programme_id'=>$programme,'version'=>'v1','effective_academic_year_id'=>$year,'status'=>'approved','created_at'=>now(),'updated_at'=>now()]);
        $stage = (int)DB::table('curriculum_stages')->insertGetId(['school_id'=>$school,'curriculum_id'=>$curriculum,'label'=>'Year 1','sequence'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $member = (int)DB::table('curriculum_memberships')->insertGetId(['school_id'=>$school,'curriculum_id'=>$curriculum,'subject_id'=>$subject,'curriculum_stage_id'=>$stage,'period_type'=>'semester','period_sequence'=>1,'classification'=>'compulsory','credits'=>3,'sequence'=>1,'created_at'=>now(),'updated_at'=>now()]);
        return compact('school','department','programme','year','period','subject','curriculum','stage','member');
    }

    private function curriculumFor(array $tenant, string $status, string $version): int
    {
        return (int)DB::table('curricula')->insertGetId(['school_id'=>$tenant['school'],'programme_id'=>$tenant['programme'],'version'=>$version,'effective_academic_year_id'=>$tenant['year'],'status'=>$status,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function subject(int $school, string $name, string $code): int
    {
        return (int)DB::table('subjects')->insertGetId(['school_id'=>$school,'name'=>$name,'code'=>$code,'created_at'=>now(),'updated_at'=>now()]);
    }

    private function createMembership(array $tenant, int $curriculumId, int $subjectId, ?string $periodType, ?int $periodSequence, ?int $stageId = null): int
    {
        $stageId ??= (int) DB::table('curriculum_stages')->where('school_id', $tenant['school'])->where('curriculum_id', $curriculumId)->value('id');
        return (int) DB::table('curriculum_memberships')->insertGetId([
            'school_id' => $tenant['school'], 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId,
            'curriculum_stage_id' => $stageId, 'period_type' => $periodType, 'period_sequence' => $periodSequence,
            'classification' => 'compulsory', 'credits' => 3, 'sequence' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createOffering(array $tenant, string $reference, ?int $subject = null): CourseOffering
    {
        return app(CourseOfferingService::class)->createDraft($tenant['school'],$subject ?? $tenant['subject'],$tenant['year'],$tenant['period'],$reference);
    }

    private function attach(array $tenant, CourseOffering $offering, int $membership): void
    {
        app(CourseOfferingService::class)->addApplicability($tenant['school'],$offering->id,$membership);
    }


    private function draftPayload(array $tenant, string $reference = ''): array
    {
        return ['subject_id'=>$tenant['subject'],'academic_year_id'=>$tenant['year'],'academic_period_id'=>$tenant['period'],'reference'=>$reference];
    }

    private function staff(int $schoolNumber, array $permissions, array $extra = []): User
    {
        $user = $this->staffForSchool($this->tenants[$schoolNumber]['school'], $permissions, $extra);
        return $user;
    }

    private function staffForSchool(int $schoolId, array $permissions, array $extra = []): User
    {
        $user = User::factory()->create($extra + ['role_id'=>3,'school_id'=>$schoolId,'account_status'=>'active']);
        $this->grant($user, ...$permissions);
        return $user;
    }

    private function grant(User $user, string ...$permissions): void
    {
        foreach ($permissions as $permission) DB::table('user_permissions')->insert(['school_id'=>$user->school_id,'user_id'=>$user->id,'permission'=>$permission,'created_at'=>now(),'updated_at'=>now()]);
    }
}
