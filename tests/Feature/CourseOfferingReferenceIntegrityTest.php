<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Support\CourseOffering\CourseOfferingService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression: a Course Offering's reference must always correspond to its
 * PERSISTED Course Unit / Academic Year / Academic Period.
 *
 * The defect, found on production Offering #5: it ended up as
 *
 *     reference = BBIT1103-2026-S1   (the ORIGINAL Course Unit)
 *     subject_id = 97 = BBIT4201    (the Course Unit saved afterwards)
 *
 * because CourseOfferingService::updateDraft() changed subject_id /
 * academic_year_id / academic_period_id but never touched `reference`. Its own
 * docblock stated the reference "is not recomputed when identity changes", so
 * a draft's reference and identity could drift apart permanently — and the
 * index and show pages then faithfully displayed the disagreement.
 *
 * The fix makes the reference server-authoritative: it is regenerated from the
 * final validated identity inside the same transaction, in the same UPDATE
 * statement, and can never be supplied by the caller.
 *
 * The scenario below reproduces the production shape exactly: a draft created
 * for BBIT1103 and then saved as BBIT4201 on AY 2026/2027 Semester 1.
 */
class CourseOfferingReferenceIntegrityTest extends TestCase
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

    /** The production shape: draft created for BBIT1103, later saved as BBIT4201. */
    private function productionShape(): array
    {
        $bbit1103 = $this->subject(1, ['code' => 'BBIT1103', 'name' => 'Business Mathematics']);
        $bbit4201 = $this->subject(1, ['code' => 'BBIT4201', 'name' => 'Artificial Intelligence for Business']);

        $offering = $this->service->createDraft(1, $bbit1103, 11, 101);

        return [$offering, $bbit1103, $bbit4201];
    }

    // A + B: the Course Unit change persists AND the reference follows it.
    public function test_draft_course_unit_change_persists_the_subject_and_regenerates_the_reference(): void
    {
        [$offering, $bbit1103, $bbit4201] = $this->productionShape();
        $this->assertSame('BBIT1103-2026-S1', $offering->reference);

        $updated = $this->service->updateDraft(1, $offering->id, [
            'subject_id' => $bbit4201, 'academic_year_id' => 11, 'academic_period_id' => 101,
        ]);

        $this->assertSame($bbit4201, (int) $updated->subject_id, 'the new Course Unit is persisted');
        $this->assertNotSame($bbit1103, (int) $updated->fresh()->subject_id);

        // THE INVARIANT: the reference describes the Course Unit that is stored.
        $this->assertSame('BBIT4201-2026-S1', $updated->fresh()->reference);
        $this->assertStringStartsWith(
            $this->subjectCode($updated->fresh()->subject_id).'-',
            $updated->fresh()->reference,
            'the reference prefix must be the persisted Course Unit code'
        );
    }

    // C: a reloaded row agrees with itself.
    public function test_reloaded_offering_never_disagrees_with_its_own_reference(): void
    {
        [$offering, , $bbit4201] = $this->productionShape();
        $this->service->updateDraft(1, $offering->id, ['subject_id' => $bbit4201]);

        $reloaded = CourseOffering::findOrFail($offering->id);
        $this->assertSame(
            $this->subjectCode($reloaded->subject_id).'-2026-S1',
            $reloaded->reference,
            'reference and Course Unit must agree after reload'
        );
    }

    // E: Academic Year change persists and regenerates.
    public function test_draft_academic_year_change_persists_and_regenerates_the_reference(): void
    {
        $subject = $this->subject(1, ['code' => 'BBIT1101']);
        $offering = $this->service->createDraft(1, $subject, 11, 101);
        $this->assertSame('BBIT1101-2026-S1', $offering->reference);

        // Year 12 starts in 2027 and its Semester 1 is period 103, so the pair
        // moves together — a period from another year is refused.
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['academic_year_id' => 12]));

        $updated = $this->service->updateDraft(1, $offering->id, ['academic_year_id' => 12, 'academic_period_id' => 103]);

        $this->assertSame(12, (int) $updated->fresh()->academic_year_id);
        $this->assertSame(103, (int) $updated->fresh()->academic_period_id);
        $this->assertSame('BBIT1101-2027-S1', $updated->fresh()->reference, 'the year in the reference follows the persisted year');
    }

    // F: Academic Period change persists and regenerates.
    public function test_draft_academic_period_change_persists_and_regenerates_the_reference(): void
    {
        $subject = $this->subject(1, ['code' => 'BBIT1101']);
        $offering = $this->service->createDraft(1, $subject, 11, 101);

        $updated = $this->service->updateDraft(1, $offering->id, ['academic_period_id' => 102]);

        $this->assertSame(102, (int) $updated->fresh()->academic_period_id);
        $this->assertSame('BBIT1101-2026-S2', $updated->fresh()->reference, 'the period in the reference follows the persisted period');
    }

    // G: a rejected update leaves no partial reference/identity state.
    public function test_a_failed_update_leaves_no_partial_reference_or_identity_state(): void
    {
        [$offering, , $bbit4201] = $this->productionShape();
        $before = $offering->fresh()->only(['subject_id', 'academic_year_id', 'academic_period_id', 'reference']);

        // A caller-supplied reference is rejected outright.
        try {
            $this->service->updateDraft(1, $offering->id, [
                'subject_id' => $bbit4201, 'reference' => 'TAMPERED',
            ]);
            $this->fail('A caller must never be able to set the reference.');
        } catch (DomainException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        // A cross-tenant Course Unit is rejected before anything is written.
        $foreign = $this->subject(2, ['code' => 'FOREIGN1']);
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['subject_id' => $foreign]));

        $this->assertSame(
            $before,
            $offering->fresh()->only(['subject_id', 'academic_year_id', 'academic_period_id', 'reference']),
            'a failed update changes nothing at all'
        );
    }

    // H: collision reuses the existing parallel-delivery rule, never corrupts.
    public function test_reference_collision_uses_the_existing_parallel_delivery_rule(): void
    {
        $subject = $this->subject(1, ['code' => 'BBIT1101']);
        $first = $this->service->createDraft(1, $subject, 11, 101);
        $second = $this->service->createDraft(1, $subject, 11, 101);
        $this->assertSame('BBIT1101-2026-S1', $first->reference);
        $this->assertSame('BBIT1101-2026-S1-2', $second->reference);

        // Moving the second onto the first's identity must not overwrite it.
        $this->service->updateDraft(1, $second->id, ['subject_id' => $subject]);
        $this->assertSame('BBIT1101-2026-S1-2', $second->fresh()->reference, 'the collision is resolved, not overwriting');
        $this->assertSame('BBIT1101-2026-S1', $first->fresh()->reference, 'the other Offering is untouched');
        $this->assertNotSame($first->reference, $second->fresh()->reference, 'references stay distinct');
    }

    // I, J, K: the lifecycle freeze is unchanged and still rejects identity edits.
    public function test_open_in_progress_and_completed_offerings_keep_identity_frozen(): void
    {
        $subject = $this->subject(1, ['code' => 'BBIT1101']);
        $other = $this->subject(1, ['code' => 'BBIT1102']);
        $curriculum = $this->curriculum(1, 11, 'approved', '2026-V1');
        $membership = $this->membership(1, $curriculum, $subject, 'semester', 1);

        // Opening requires a linked Study Plan, so each Offering is linked first.
        $open = $this->service->createDraft(1, $subject, 11, 101);
        $this->service->addApplicability(1, $open->id, $membership);
        $this->service->open(1, $open->id);
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $open->id, ['subject_id' => $other]));
        $this->assertSame('BBIT1101-2026-S1', $open->fresh()->reference, 'a frozen Offering cannot be re-identified');

        $inProgress = $this->service->createDraft(1, $subject, 11, 101);
        $this->service->addApplicability(1, $inProgress->id, $membership);
        $this->service->open(1, $inProgress->id);
        $this->service->start(1, $inProgress->id);
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $inProgress->id, ['subject_id' => $other]));
        $this->assertSame('BBIT1101-2026-S1-2', $inProgress->fresh()->reference);

        $completed = $this->service->createDraft(1, $subject, 11, 101);
        $this->service->addApplicability(1, $completed->id, $membership);
        $this->service->open(1, $completed->id);
        $this->service->start(1, $completed->id);
        $this->service->complete(1, $completed->id);
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $completed->id, ['subject_id' => $other]));
        $this->assertSame($subject, (int) $completed->fresh()->subject_id);
        $this->assertSame('BBIT1101-2026-S1-3', $completed->fresh()->reference);
    }

    // L: compatibility is computed from the FINAL persisted Course Unit.
    public function test_study_plan_compatibility_follows_the_persisted_course_unit(): void
    {
        $bbit1103 = $this->subject(1, ['code' => 'BBIT1103']);
        $bbit4201 = $this->subject(1, ['code' => 'BBIT4201']);
        $curriculum = $this->curriculum(1, 11, 'approved', '2026-V1');
        $membership1103 = $this->membership(1, $curriculum, $bbit1103, 'semester', 1);

        $offering = $this->service->createDraft(1, $bbit1103, 11, 101);

        // The approved 2026-V1 entry for BBIT1103, Year 1 Semester 1, is linkable.
        $this->service->addApplicability(1, $offering->id, $membership1103);
        $this->assertSame(1, $offering->fresh()->applicability()->count());

        // Changing the Course Unit must first require the link to be removed,
        // so the link can never be left describing a different Course Unit.
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['subject_id' => $bbit4201]));
        $this->service->removeApplicability(1, $offering->id, $membership1103);
        $this->service->updateDraft(1, $offering->id, ['subject_id' => $bbit4201]);
        $this->assertSame(0, $offering->fresh()->applicability()->count());
        $this->assertSame('BBIT4201-2026-S1', $offering->fresh()->reference);
    }

    // M, N: cross-tenant identity cannot be assigned.
    public function test_cross_tenant_course_unit_year_and_period_cannot_be_assigned(): void
    {
        $subject = $this->subject(1, ['code' => 'BBIT1101']);
        $offering = $this->service->createDraft(1, $subject, 11, 101);
        $before = $offering->fresh()->only(['subject_id', 'academic_year_id', 'academic_period_id', 'reference']);

        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['subject_id' => $this->subject(2, ['code' => 'FOREIGN'])]));
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['academic_year_id' => 22]));
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['academic_period_id' => 201]));
        // A period from another Academic Year of the same tenant is also refused.
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $offering->id, ['academic_period_id' => 103]));

        $this->assertSame(
            $before,
            $offering->fresh()->only(['subject_id', 'academic_year_id', 'academic_period_id', 'reference'])
        );

        // Another tenant's Offering is not reachable at all.
        $foreign = $this->service->createDraft(2, $this->subject(2, ['code' => 'FOREIGN']), 21, 201);
        $this->assertDomainFailure(fn () => $this->service->updateDraft(1, $foreign->id, ['subject_id' => $subject]));
    }

    /** An existing inconsistent row is repaired on its next save, not on load. */
    public function test_a_previously_inconsistent_row_self_heals_on_the_next_save(): void
    {
        [$offering, , $bbit4201] = $this->productionShape();

        // Reproduce the historical corruption directly in the fixture.
        DB::table('course_offerings')->where('id', $offering->id)->update(['subject_id' => $bbit4201]);
        $corrupt = $offering->fresh();
        $this->assertSame('BBIT1103-2026-S1', $corrupt->reference);
        $this->assertSame($bbit4201, (int) $corrupt->subject_id);

        // Re-saving the same identity recomputes the authoritative reference.
        $this->service->updateDraft(1, $offering->id, [
            'subject_id' => $bbit4201, 'academic_year_id' => 11, 'academic_period_id' => 101,
        ]);

        $this->assertSame('BBIT4201-2026-S1', $offering->fresh()->reference, 'the mismatch is repaired');
    }


    private function subjectCode(int $subjectId): string
    {
        return (string) DB::table('subjects')->where('id', $subjectId)->value('code');
    }

    private function assertDomainFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the Course Offering domain operation to be rejected.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', trim($exception->getMessage()), 'a human-readable reason is given');
        }
    }

    private function subject(int $schoolId, array $extra = []): int
    {
        return (int) DB::table('subjects')->insertGetId(array_merge(
            ['school_id' => $schoolId, 'name' => 'Course Unit', 'created_at' => now(), 'updated_at' => now()],
            $extra
        ));
    }

    private function curriculum(int $schoolId, int $effectiveYearId, string $status, string $version = 'v1'): int
    {
        return (int) DB::table('curricula')->insertGetId([
            'school_id' => $schoolId, 'programme_id' => 1, 'version' => $version,
            'effective_academic_year_id' => $effectiveYearId, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function membership(int $schoolId, int $curriculumId, int $subjectId, ?string $periodType = 'semester', ?int $sequence = 1): int
    {
        return (int) DB::table('curriculum_memberships')->insertGetId([
            'school_id' => $schoolId, 'curriculum_id' => $curriculumId, 'subject_id' => $subjectId,
            'curriculum_stage_id' => 1, 'period_type' => $periodType, 'period_sequence' => $sequence,
            'classification' => 'compulsory', 'credits' => '3.00', 'sequence' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
        Schema::create('curriculum_stages', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('curriculum_id'); $table->string('label');
            $table->unsignedSmallInteger('sequence'); $table->timestamps();
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
        Schema::create('course_offering_lecturer_allocations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id'); $table->unsignedBigInteger('user_id');
            $table->string('role', 32); $table->date('starts_on'); $table->date('ends_on')->nullable(); $table->string('status', 16)->default('planned'); $table->timestamps();
        });
        Schema::create('course_offering_attendance_sessions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id'); $table->date('session_date');
            $table->string('status', 16)->default('planned'); $table->timestamps();
        });
        Schema::create('course_offering_attendance_records', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('course_offering_id'); $table->unsignedBigInteger('student_id');
            $table->string('status', 16)->nullable(); $table->timestamps();
        });
        Schema::create('course_registrations', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('student_id'); $table->unsignedBigInteger('subject_id'); $table->integer('session_id')->nullable();
                        $table->unsignedBigInteger('school_id'); $table->string('status');
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('role_id'); $table->string('name'); $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            foreach (['school_id', 'user_id', 'user_name', 'role_id', 'role_name', 'action', 'event_type', 'module', 'route_name', 'url', 'method', 'description', 'record_type', 'record_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'device_type', 'browser', 'platform', 'status'] as $field) {
                $table->text($field)->nullable();
            }
            $table->timestamp('created_at')->nullable();
        });
    }
}
