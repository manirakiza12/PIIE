<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\CourseOfferingCurriculumMembership;
use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use App\Support\CourseOffering\CourseOfferingService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CourseOfferingFoundationTest extends TestCase
{
    private CourseOfferingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->schema();
        $this->service = new CourseOfferingService();
        foreach ([1, 2] as $school) {
            DB::table('schools')->insert(['id' => $school]);
            DB::table('academic_years')->insert([
                ['id' => $school * 10 + 1, 'school_id' => $school, 'label' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'planned'],
                ['id' => $school * 10 + 2, 'school_id' => $school, 'label' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'status' => 'planned'],
            ]);
            DB::table('academic_periods')->insert([
                ['id' => $school * 100 + 1, 'school_id' => $school, 'academic_year_id' => $school * 10 + 1, 'type' => 'semester', 'label' => 'Semester 1', 'sequence' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'status' => 'planned'],
                ['id' => $school * 100 + 2, 'school_id' => $school, 'academic_year_id' => $school * 10 + 1, 'type' => 'semester', 'label' => 'Semester 2', 'sequence' => 2, 'start_date' => '2026-07-01', 'end_date' => '2026-12-31', 'status' => 'planned'],
                ['id' => $school * 100 + 3, 'school_id' => $school, 'academic_year_id' => $school * 10 + 2, 'type' => 'semester', 'label' => 'Semester 1', 'sequence' => 1, 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'planned'],
            ]);
        }
    }

    public function test_creation_is_always_draft_tenant_scoped_and_parallel_deliveries_are_allowed(): void
    {
        $subject = $this->subject(1, ['class_id' => 55, 'session_id' => 91]);
        $one = $this->service->createDraft(1, $subject, 11, 101);
        $two = $this->service->createDraft(1, $subject, 11, 101);
        $otherTenantReference = $this->service->createDraft(2, $this->subject(2), 21, 201, 'REF-1');

        $this->assertSame('draft', $one->status);
        $this->assertSame('CU'.$subject.'-2026-S1', $one->reference);
        $this->assertNotSame($one->id, $two->id);
        $this->assertSame('CU'.$subject.'-2026-S1-2', $two->reference);
        $this->assertSame('REF-1', $otherTenantReference->reference);
        $this->assertSame(55, (int) DB::table('subjects')->where('id', $subject)->value('class_id'));
        $this->assertSame(91, (int) DB::table('subjects')->where('id', $subject)->value('session_id'));

        $this->expectException(DomainException::class);
        $this->service->createDraft(2, $subject, 21, 201);
    }

    public function test_generated_reference_uses_course_code_year_and_period_and_handles_parallel_collisions(): void
    {
        $subject = $this->subject(1, ['code' => 'BBIT1101']);
        $one = $this->service->createDraft(1, $subject, 11, 101);
        $two = $this->service->createDraft(1, $subject, 11, 101);

        $this->assertSame('BBIT1101-2026-S1', $one->reference);
        $this->assertSame('BBIT1101-2026-S1-2', $two->reference);

        DB::table('academic_periods')->where('id', 101)->update(['type' => 'term']);
        $term = $this->service->createDraft(1, $subject, 11, 101);
        $this->assertSame('BBIT1101-2026-T1', $term->reference);

        DB::table('academic_periods')->insert([
            'id' => 104, 'school_id' => 1, 'academic_year_id' => 11, 'type' => 'trimester', 'label' => 'Trimester 3',
            'sequence' => 3, 'start_date' => '2026-09-01', 'end_date' => '2026-12-31', 'status' => 'planned',
        ]);
        $otherType = $this->service->createDraft(1, $subject, 11, 104);
        $this->assertSame('BBIT1101-2026-TR3', $otherType->reference);

        $otherTenantSubject = $this->subject(2, ['code' => 'BBIT1101']);
        $otherTenant = $this->service->createDraft(2, $otherTenantSubject, 21, 201);
        $this->assertSame('BBIT1101-2026-S1', $otherTenant->reference);
    }

    public function test_generated_reference_normalizes_course_unit_code_punctuation_safely(): void
    {
        $subject = $this->subject(1, ['code' => '  bbit / 1101 (A) ']);
        $offering = $this->service->createDraft(1, $subject, 11, 101);

        $this->assertSame('BBIT-1101-A-2026-S1', $offering->reference);
        $this->assertLessThanOrEqual(50, strlen($offering->reference));
    }

    public function test_reference_is_tenant_unique_and_future_planned_year_and_period_are_allowed(): void
    {
        $subject = $this->subject(1);
        $offering = $this->service->createDraft(1, $subject, 12, 103, 'COMMON-101');
        $this->assertSame('draft', $offering->status);
        $this->expectException(DomainException::class);
        $this->service->createDraft(1, $subject, 11, 101, 'COMMON-101');
    }

    public function test_draft_and_retired_curricula_are_rejected_but_approved_memberships_are_accepted(): void
    {
        $subject = $this->subject(1);
        $draft = $this->curriculum(1, $this->year(1), 'draft');
        $draftMembership = $this->membership(1, $draft, $subject);
        $offering = $this->service->createDraft(1, $subject, 11, 101);
        $this->assertDomainFailure(fn () => $this->service->addApplicability(1, $offering->id, $draftMembership));

        $approved = $this->curriculum(1, $this->year(1), 'approved');
        $membership = $this->membership(1, $approved, $subject);
        $this->service->addApplicability(1, $offering->id, $membership);
        $this->assertSame(1, $offering->fresh()->applicability()->count());
        $this->assertDomainFailure(fn () => $this->service->addApplicability(1, $offering->id, $membership));

        DB::table('curricula')->where('id', $approved)->update(['status' => 'retired']);
        $this->assertSame(1, $offering->fresh()->applicability()->count(), 'Retirement preserves historical applicability.');
        $newDraft = $this->service->createDraft(1, $subject, 11, 101);
        $this->assertDomainFailure(fn () => $this->service->addApplicability(1, $newDraft->id, $membership));
    }

    public function test_applicability_checks_tenant_subject_period_placement_and_effective_year(): void
    {
        $subject = $this->subject(1);
        $approved = $this->curriculum(1, $this->year(1), 'approved');
        $offering = $this->service->createDraft(1, $subject, 11, 101);

        $foreignMembership = $this->membership(2, $this->curriculum(2, $this->year(2), 'approved'), $this->subject(2));
        $this->assertDomainFailure(fn () => $this->service->addApplicability(1, $offering->id, $foreignMembership));

        $wrongSubject = $this->membership(1, $approved, $this->subject(1));
        $this->assertDomainFailure(fn () => $this->service->addApplicability(1, $offering->id, $wrongSubject));

        $wrongSequence = $this->membership(1, $approved, $subject, 'semester', 2);
        $this->assertDomainFailure(fn () => $this->service->addApplicability(1, $offering->id, $wrongSequence));
        $wrongType = $this->membership(1, $approved, $subject, 'term', 1);
        $this->assertDomainFailure(fn () => $this->service->addApplicability(1, $offering->id, $wrongType));
        $unplaced = $this->membership(1, $approved, $subject, null, null);
        $this->assertDomainFailure(fn () => $this->service->addApplicability(1, $offering->id, $unplaced));

        $effective2027 = $this->curriculum(1, $this->year(1, 2), 'approved', 'effective-2027');
        $laterMembership = $this->membership(1, $effective2027, $subject);
        $this->assertDomainFailure(fn () => $this->service->addApplicability(1, $offering->id, $laterMembership));
        $laterOffering = $this->service->createDraft(1, $subject, 12, 103);
        $this->service->addApplicability(1, $laterOffering->id, $laterMembership);
        $this->assertSame(1, $laterOffering->fresh()->applicability()->count());
    }

    public function test_lifecycle_revalidates_freezes_identity_and_has_terminal_states(): void
    {
        $subject = $this->subject(1);
        $approved = $this->curriculum(1, $this->year(1), 'approved');
        $membership = $this->membership(1, $approved, $subject);
        $offering = $this->service->createDraft(1, $subject, 11, 101);
        $this->assertDomainFailure(fn () => $this->service->open(1, $offering->id));
        $this->service->addApplicability(1, $offering->id, $membership);
        // The Offering reference is system-generated and immutable: not accepted by updateDraft, even while draft.
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['reference' => 'UPDATED']));
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['subject_id' => $this->subject(1)]));

        $opened = $this->service->open(1, $offering->id);
        $this->assertSame('open', $opened->status);
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['reference' => 'NOPE']));
        $this->assertDomainFailure(fn () => $this->service->removeApplicability(1, $offering->id, $membership));
        $started = $this->service->start(1, $offering->id);
        $this->assertSame('in_progress', $started->status);
        $this->assertSame('completed', $this->service->complete(1, $offering->id)->status);
        $this->assertDomainFailure(fn () => $this->service->start(1, $offering->id));

        $cancelledDraft = $this->service->createDraft(1, $subject, 11, 101);
        $this->assertDomainFailure(fn () => $this->service->cancel(1, $cancelledDraft->id, '   '));
        $this->assertSame('cancelled', $this->service->cancel(1, $cancelledDraft->id, 'Not running')->status);
        $this->assertDomainFailure(fn () => $this->service->open(1, $cancelledDraft->id));

        $cancelledOpen = $this->service->createDraft(1, $subject, 11, 101);
        $this->service->addApplicability(1, $cancelledOpen->id, $membership);
        $this->service->open(1, $cancelledOpen->id);
        $this->assertSame('cancelled', $this->service->cancel(1, $cancelledOpen->id, 'Withdrawn')->status);

        $cancelledInProgress = $this->service->createDraft(1, $subject, 11, 101);
        $this->service->addApplicability(1, $cancelledInProgress->id, $membership);
        $this->service->open(1, $cancelledInProgress->id);
        $this->service->start(1, $cancelledInProgress->id);
        $this->assertSame('cancelled', $this->service->cancel(1, $cancelledInProgress->id, 'Stopped')->status);
    }

    public function test_start_and_complete_are_blocked_before_the_academic_period_begins(): void
    {
        $subject = $this->subject(1);
        $approved = $this->curriculum(1, $this->year(1), 'approved');
        $membership = $this->membership(1, $approved, $subject);
        // Academic Period 103 (Semester 1, year 12/2027) starts in the future relative to real time.
        $future = $this->service->createDraft(1, $subject, 12, 103);
        $this->service->addApplicability(1, $future->id, $membership);
        $opened = $this->service->open(1, $future->id);
        $this->assertSame('open', $opened->status);

        try {
            $this->service->start(1, $future->id);
            $this->fail('Starting before the Academic Period begins must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('cannot start before Semester 1 begins on 1 January 2027', $exception->getMessage());
        }
        $this->assertSame('open', $opened->fresh()->status);

        // complete() carries the same guard independently, even if an Offering
        // reached in_progress by another means (e.g. the Academic Period dates
        // were edited after a legitimate start).
        DB::table('course_offerings')->where('id', $future->id)->update(['status' => 'in_progress']);
        try {
            $this->service->complete(1, $future->id);
            $this->fail('Completing before the Academic Period begins must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('cannot start before Semester 1 begins on 1 January 2027', $exception->getMessage());
        }
        $this->assertSame('in_progress', DB::table('course_offerings')->where('id', $future->id)->value('status'));
    }

    public function test_open_revalidates_retired_curriculum_and_normal_deletion_is_blocked(): void
    {
        $subject = $this->subject(1);
        $curriculum = $this->curriculum(1, $this->year(1), 'approved');
        $membership = $this->membership(1, $curriculum, $subject);
        $offering = $this->service->createDraft(1, $subject, 11, 101);
        $this->service->addApplicability(1, $offering->id, $membership);
        DB::table('curricula')->where('id', $curriculum)->update(['status' => 'retired']);
        $this->assertDomainFailure(fn () => $this->service->open(1, $offering->id));
        $this->assertDomainFailure(fn () => $offering->delete());
        $this->assertDomainFailure(fn () => CourseOfferingCurriculumMembership::first()->delete());
    }

    public function test_draft_period_change_cannot_leave_incompatible_applicability_attached(): void
    {
        $subject = $this->subject(1);
        $curriculum = $this->curriculum(1, $this->year(1), 'approved');
        $membership = $this->membership(1, $curriculum, $subject, 'semester', 1);
        $offering = $this->service->createDraft(1, $subject, 11, 101);
        $this->service->addApplicability(1, $offering->id, $membership);

        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['academic_period_id' => 102]));
        $this->assertSame(101, (int) $offering->fresh()->academic_period_id);
        $this->service->removeApplicability(1, $offering->id, $membership);
        $this->assertSame(102, (int) $this->service->updateDraft(1, $offering->id, ['academic_period_id' => 102])->academic_period_id);
    }

    public function test_reference_is_server_generated_never_accepted_and_tracks_the_persisted_identity(): void
    {
        $subject = $this->subject(1, ['code' => 'BBIT1101']);
        $other = $this->subject(1, ['code' => 'BBIT1102']);
        $offering = $this->service->createDraft(1, $subject, 11, 101);
        $this->assertSame('BBIT1101-2026-S1', $offering->reference);

        // CHANGED BEHAVIOUR (was: the reference stayed frozen at BBIT1101-2026-S1).
        // Freezing it while identity was freely editable is what let production
        // Offering #5 end up as reference BBIT1103-2026-S1 against subject
        // BBIT4201. The reference is now regenerated from the persisted identity,
        // server-side, in the same statement that persists it.
        $this->service->updateDraft(1, $offering->id, ['academic_period_id' => 102]);
        $this->assertSame('BBIT1101-2026-S2', $offering->fresh()->reference, 'period change regenerates the reference');

        $this->service->updateDraft(1, $offering->id, ['subject_id' => $other]);
        $this->assertSame('BBIT1102-2026-S2', $offering->fresh()->reference, 'Course Unit change regenerates the reference');
        $this->assertDatabaseHas('course_offerings', [
            'id' => $offering->id, 'reference' => 'BBIT1102-2026-S2', 'subject_id' => $other, 'academic_period_id' => 102,
        ]);

        // A no-op save is stable: recomputation returns the same reference and
        // does not bump the offering against itself.
        $this->service->updateDraft(1, $offering->id, ['subject_id' => $other]);
        $this->assertSame('BBIT1102-2026-S2', $offering->fresh()->reference);

        // STILL TRUE: a manual reference is never accepted, so the reference is
        // meaningless to edit by hand, and a rejected edit changes nothing.
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['reference' => 'MANUAL']));
        $this->assertSame('BBIT1102-2026-S2', $offering->fresh()->reference);
    }

    public function test_accidental_duplicate_offerings_are_distinguished_by_generated_reference(): void
    {
        $subject = $this->subject(1, ['code' => 'BBIT1101']);
        $first = $this->service->createDraft(1, $subject, 11, 101);
        $second = $this->service->createDraft(1, $subject, 11, 101);
        $third = $this->service->createDraft(1, $subject, 11, 101);

        // Parallel deliveries of one Course Unit in one period are permitted, and are
        // told apart by a unique human-meaningful reference rather than a blank cell.
        $this->assertSame('BBIT1101-2026-S1', $first->reference);
        $this->assertSame('BBIT1101-2026-S1-2', $second->reference);
        $this->assertSame('BBIT1101-2026-S1-3', $third->reference);
        $this->assertCount(3, array_unique([$first->reference, $second->reference, $third->reference]));
    }

    public function test_completion_is_blocked_while_lecturer_allocations_remain_active(): void
    {
        $subject = $this->subject(1, ['code' => 'BBIT1101']);
        $membership = $this->membership(1, $this->curriculum(1, $this->year(1), 'approved'), $subject);
        $offering = $this->service->createDraft(1, $subject, 11, 101);
        $this->service->addApplicability(1, $offering->id, $membership);
        $this->service->open(1, $offering->id);
        $this->service->start(1, $offering->id);

        $lecturer = (int) DB::table('users')->insertGetId(['school_id' => 1, 'role_id' => 3, 'name' => 'Lecturer One', 'created_at' => now(), 'updated_at' => now()]);
        $allocation = (int) DB::table('course_offering_lecturer_allocations')->insertGetId([
            'school_id' => 1, 'course_offering_id' => $offering->id, 'user_id' => $lecturer,
            'role' => 'primary_lecturer', 'starts_on' => '2026-01-05', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->service->complete(1, $offering->id);
            $this->fail('Completing with an active lecturer allocation must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('1 lecturer allocation remains active', $exception->getMessage());
            $this->assertStringContainsString('Teaching Team', $exception->getMessage());
        }
        $this->assertSame('in_progress', $offering->fresh()->status);
        // The allocation is never ended automatically by the refused completion.
        $this->assertDatabaseHas('course_offering_lecturer_allocations', ['id' => $allocation, 'status' => 'active']);

        DB::table('course_offering_lecturer_allocations')->where('id', $allocation)->update(['status' => 'ended', 'ends_on' => '2026-06-30']);
        $this->assertSame('completed', $this->service->complete(1, $offering->id)->status);
    }

    public function test_completion_policy_does_not_require_the_academic_period_to_have_ended(): void
    {
        // Completion is permitted while the Academic Period is still running: no
        // early/end-date policy is invented here, so the ambiguity is left for
        // explicit product sign-off. Only the unquestionably invalid case
        // (completing before the period begins) is refused.
        $subject = $this->subject(1, ['code' => 'BBIT1101']);
        $membership = $this->membership(1, $this->curriculum(1, $this->year(1), 'approved'), $subject);
        $offering = $this->service->createDraft(1, $subject, 11, 101);
        $this->service->addApplicability(1, $offering->id, $membership);
        $this->service->open(1, $offering->id);

        DB::table('academic_periods')->where('id', 101)->update([
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);
        $this->service->start(1, $offering->id);
        $this->assertTrue(now()->lt(now()->parse(DB::table('academic_periods')->where('id', 101)->value('end_date'))));
        $this->assertSame('completed', $this->service->complete(1, $offering->id)->status);
    }

    public function test_domain_messages_use_human_university_terminology(): void
    {
        $subject = $this->subject(1, ['code' => 'BBIT1101']);
        $membership = $this->membership(1, $this->curriculum(1, $this->year(1), 'approved'), $subject);
        $offering = $this->service->createDraft(1, $subject, 11, 101);

        $this->assertDomainFailure(fn () => $this->service->open(1, $offering->id));
        $this->service->addApplicability(1, $offering->id, $membership);
        $this->service->open(1, $offering->id);
        $this->service->start(1, $offering->id);
        $this->assertDomainFailure(fn () => $this->service->open(1, $offering->id));
        $this->assertDomainFailure(fn () => $this->service->cancel(1, $offering->id, ' '));
        $this->service->cancel(1, $offering->id, 'Programme withdrawn');

        foreach ([
            fn () => $this->service->createDraft(1, $subject, 999, 101),
            fn () => $this->service->updateDraft(1, $offering->id, ['reference' => 'X']),
            fn () => $this->service->cancel(1, $offering->id, 'again'),
            fn () => $this->service->open(1, $offering->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected the Course Offering domain operation to be rejected.');
            } catch (DomainException $exception) {
                $this->assertHumanMessage($exception->getMessage());
            }
        }
    }

    public function test_legacy_course_registration_table_is_not_changed(): void
    {
        $before = Schema::getColumnListing('course_registrations');
        $this->assertContains('session_id', $before);
        $this->assertNotContains('course_offering_id', $before);
        $this->assertSame(0, DB::table('course_registrations')->count());
        $this->assertFalse((new Curriculum())->exists);
    }

    private function schema(): void
    {
        Schema::create('schools', function (Blueprint $table): void { $table->unsignedBigInteger('id')->primary(); });
        Schema::create('subjects', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->string('name'); $table->unsignedBigInteger('programme_id')->nullable();
            $table->integer('class_id')->nullable(); $table->integer('session_id')->nullable(); $table->string('code')->nullable(); $table->timestamps();
            $table->unique(['school_id', 'id']);
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->string('label'); $table->date('start_date'); $table->date('end_date'); $table->string('status'); $table->timestamps();
            $table->unique(['school_id', 'id']);
        });
        Schema::create('academic_periods', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('academic_year_id'); $table->string('type'); $table->string('label');
            $table->unsignedSmallInteger('sequence'); $table->date('start_date'); $table->date('end_date'); $table->string('status'); $table->timestamps();
        });
        Schema::create('curricula', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('programme_id'); $table->string('version');
            $table->unsignedBigInteger('effective_academic_year_id')->nullable(); $table->string('status'); $table->timestamps();
        });
        Schema::create('curriculum_memberships', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('curriculum_id'); $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('curriculum_stage_id'); $table->string('period_type')->nullable(); $table->unsignedSmallInteger('period_sequence')->nullable();
            $table->string('classification'); $table->decimal('credits', 6, 2); $table->unsignedSmallInteger('sequence')->default(0); $table->timestamps();
        });
        Schema::create('course_offerings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('academic_period_id'); $table->string('reference', 50)->nullable(); $table->string('status', 20)->default('draft'); $table->timestamps();
        });
        Schema::create('course_offering_curriculum_memberships', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id'); $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('curriculum_membership_id'); $table->unsignedBigInteger('subject_id'); $table->timestamps();
            $table->primary(['school_id', 'course_offering_id', 'curriculum_membership_id']);
        });
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('session_id')->nullable();
            $table->unsignedBigInteger('school_id'); $table->string('status');
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('role_id'); $table->string('name'); $table->timestamps();
        });
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id'); $table->unsignedBigInteger('user_id');
            $table->string('role', 32); $table->date('starts_on'); $table->date('ends_on')->nullable(); $table->string('status', 16)->default('planned'); $table->timestamps();
        });
    }

    private function subject(int $schoolId, array $extra = []): int
    {
        return (int) DB::table('subjects')->insertGetId(array_merge(['school_id' => $schoolId, 'name' => 'Course Unit', 'created_at' => now(), 'updated_at' => now()], $extra));
    }

    private function curriculum(int $schoolId, int $effectiveYearId, string $status, string $version = 'v1'): int
    {
        return (int) DB::table('curricula')->insertGetId([
            'school_id' => $schoolId, 'programme_id' => 1, 'version' => $version, 'effective_academic_year_id' => $effectiveYearId,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function membership(int $schoolId, int $curriculumId, int $subjectId, ?string $periodType = 'semester', ?int $sequence = 1): int
    {
        return (int) DB::table('curriculum_memberships')->insertGetId([
            'school_id' => $schoolId, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId, 'curriculum_stage_id' => 1,
            'period_type' => $periodType, 'period_sequence' => $sequence, 'classification' => 'compulsory', 'credits' => '3.00',
            'sequence' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function year(int $schoolId, int $n = 1): int
    {
        return $schoolId * 10 + $n;
    }

    private function assertDomainFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the Course Offering domain operation to be rejected.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    /** Administrator-facing wording only: no schema, tenant or status-slug leakage. */
    private function assertHumanMessage(string $message): void
    {
        $this->assertNotSame('', $message);
        foreach (['provenance', 'Curriculum Membership', 'Curriculum applicability', 'AcademicYear', 'tenant', 'snapshot', 'curriculum_membership', 'in_progress', 'draft_', 'Subject must', 'SQLSTATE', 'SELECT ', 'INSERT '] as $internal) {
            $this->assertStringNotContainsStringIgnoringCase($internal, $message, "Administrator-facing message exposed internal wording: {$message}");
        }
    }
}
