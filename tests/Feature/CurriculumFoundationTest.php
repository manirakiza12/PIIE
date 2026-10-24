<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use App\Models\CurriculumStage;
use App\Models\Subject;
use App\Support\Curriculum\CurriculumFoundationService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CurriculumFoundationTest extends TestCase
{
    private CurriculumFoundationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createFoundationSchema();
        $this->service = new CurriculumFoundationService();
    }

    public function test_curriculum_cannot_reference_another_tenants_programme_or_academic_year(): void
    {
        $programme = $this->programme(2);
        try {
            $this->service->createDraft(1, $programme, 'v1');
            $this->fail('A foreign-tenant Programme was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('programme_id', $exception->errors());
        }

        $year = $this->academicYear(2);
        try {
            $this->service->createDraft(1, $this->programme(1), 'v2', $year);
            $this->fail('A foreign-tenant Academic Year was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_academic_year_id', $exception->errors());
        }
    }

    public function test_subject_can_be_shared_across_curricula_but_not_duplicated_within_one(): void
    {
        $subject = $this->subject(1);
        $curriculumA = $this->curriculum(1);
        $curriculumB = $this->curriculum(1, 'v2');
        $stageA = $this->stage($curriculumA);
        $stageB = $this->stage($curriculumB);

        $first = $this->membership($curriculumA, $subject, $stageA);
        $second = $this->membership($curriculumB, $subject, $stageB);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, CurriculumMembership::where('subject_id', $subject)->count());
        $this->expectException(ValidationException::class);
        $this->membership($curriculumA, $subject, $stageA);
    }

    public function test_membership_validates_tenant_stage_classification_credits_and_relative_period(): void
    {
        $curriculum = $this->curriculum(1);
        $foreignCurriculum = $this->curriculum(1, 'v2');
        $stage = $this->stage($curriculum, 'Foundation');
        $foreignStage = $this->stage($foreignCurriculum);
        $subject = $this->subject(1);

        $zero = $this->membership($curriculum, $subject, $stage, ['credits' => '0.00', 'classification' => 'compulsory']);
        $this->assertSame('0.00', $zero->credits);
        $fractional = $this->membership($curriculum, $this->subject(1), $stage, ['credits' => '1.25', 'classification' => 'elective', 'period_type' => 'semester', 'period_sequence' => 1]);
        $this->assertSame('1.25', $fractional->credits);

        $this->assertRejected(fn () => $this->membership($curriculum, $this->subject(1), $stage, ['credits' => '-0.01']), 'credits');
        $this->assertRejected(fn () => $this->membership($curriculum, $this->subject(1), $stage, ['classification' => 'core']), 'classification');
        $this->assertRejected(fn () => $this->membership($curriculum, $this->subject(1), $stage, ['period_type' => 'semester']), 'period_sequence');
        $this->assertRejected(fn () => $this->membership($curriculum, $this->subject(1), $stage, ['period_type' => 'term', 'period_sequence' => 2]), 'period_type');
        $this->assertRejected(fn () => $this->membership($curriculum, $this->subject(2), $stage, []), 'subject_id');
        $this->assertRejected(fn () => $this->membership($curriculum, $this->subject(1), $foreignStage, []), 'curriculum_stage_id');
    }

    public function test_stage_order_is_unique_within_curriculum(): void
    {
        $curriculum = $this->curriculum(1);
        $this->stage($curriculum, 'Year 1', 1);
        $this->assertRejected(fn () => $this->stage($curriculum, 'Year 2', 1), 'sequence');
    }

    public function test_prerequisites_reject_self_duplicate_cross_curriculum_and_cycles(): void
    {
        $curriculum = $this->curriculum(1);
        $otherCurriculum = $this->curriculum(1, 'v2');
        $stage = $this->stage($curriculum);
        $otherStage = $this->stage($otherCurriculum);
        $a = $this->membership($curriculum, $this->subject(1), $stage);
        $b = $this->membership($curriculum, $this->subject(1), $stage);
        $c = $this->membership($curriculum, $this->subject(1), $stage);
        $foreign = $this->membership($otherCurriculum, $this->subject(1), $otherStage);

        $this->assertRejected(fn () => $this->service->addPrerequisite($curriculum, $a->id, $a->id), 'prerequisite_membership_id');
        $this->service->addPrerequisite($curriculum, $a->id, $b->id);
        $this->assertRejected(fn () => $this->service->addPrerequisite($curriculum, $a->id, $b->id), 'prerequisite_membership_id');
        $this->assertRejected(fn () => $this->service->addPrerequisite($curriculum, $a->id, $foreign->id), 'prerequisite_membership_id');
        $this->service->addPrerequisite($curriculum, $b->id, $c->id);
        $this->assertRejected(fn () => $this->service->addPrerequisite($curriculum, $c->id, $a->id), 'prerequisite_membership_id');
    }

    public function test_empty_or_yearless_curriculum_cannot_be_approved_and_valid_draft_can(): void
    {
        $emptyWithYear = $this->curriculum(1, 'empty', $this->academicYear(1));
        $this->assertRejected(fn () => $this->service->approve($emptyWithYear), 'memberships');

        $withoutYear = $this->curriculum(1, 'no-year');
        $stage = $this->stage($withoutYear);
        $this->membership($withoutYear, $this->subject(1), $stage);
        $this->assertRejected(fn () => $this->service->approve($withoutYear), 'effective_academic_year_id');
        $this->service->updateDraftMetadata($withoutYear, 'now-effective', $this->academicYear(1));
        $this->assertSame('approved', $this->service->approve($withoutYear)->status);

        $valid = $this->curriculum(1, 'valid', $this->academicYear(1));
        $validStage = $this->stage($valid);
        $this->membership($valid, $this->subject(1), $validStage);
        $this->assertSame('approved', $this->service->approve($valid)->status);
    }

    public function test_curriculum_structure_and_metadata_can_be_edited_in_every_lifecycle_status(): void
    {
        $curriculum = $this->curriculum(1, 'approved', $this->academicYear(1));
        $stage = $this->stage($curriculum);
        $membership = $this->membership($curriculum, $this->subject(1), $stage);
        $approved = $this->service->approve($curriculum);
        foreach (['draft', 'approved', 'retired'] as $status) {
            DB::table('curricula')->where('id', $approved->id)->update(['status' => $status]);
            $current = $approved->fresh();
            $this->service->updateStage($current, $stage->fresh(), 'Year '.$status, 1);
            $this->service->updateMembership($current, $membership->fresh(), [
                'curriculum_stage_id' => $stage->id, 'classification' => 'compulsory', 'credits' => '9.00', 'sequence' => 0,
            ]);
            $year = $this->academicYear(1);
            $this->service->updateDraftMetadata($current, 'version-'.$status, $year, $status === 'draft' ? null : 'Corrected programme structure.');
            $this->assertSame('Year '.$status, $stage->fresh()->label);
            $this->assertSame('9.00', $membership->fresh()->credits);
            $this->assertSame('version-'.$status, $current->fresh()->version);
        }
        DB::table('curricula')->where('id', $approved->id)->update(['status' => 'retired']);
        $this->assertRejected(fn () => $this->service->approve($approved->fresh()), 'curriculum');
    }

    public function test_typed_delete_removes_version_owned_rows_and_preserves_shared_subject_and_other_version(): void
    {
        $subject = $this->subject(1);
        $first = $this->curriculum(1, 'v1');
        $second = $this->curriculum(1, 'v2');
        $stageA = $this->stage($first);
        $stageB = $this->stage($second);
        $this->membership($first, $subject, $stageA);
        $this->membership($second, $subject, $stageB);

        $this->assertRejected(fn () => $this->service->deleteDraft($first, 'wrong'), 'confirm_deletion');
        $this->assertDatabaseHas('curricula', ['id' => $first->id]);
        $this->service->deleteDraft($first, 'DELETE', 7);
        $this->assertDatabaseMissing('curricula', ['id' => $first->id]);
        $this->assertDatabaseMissing('curriculum_stages', ['curriculum_id' => $first->id]);
        $this->assertDatabaseMissing('curriculum_memberships', ['curriculum_id' => $first->id]);
        $this->assertDatabaseHas('curricula', ['id' => $second->id]);
        $this->assertDatabaseHas('subjects', ['id' => $subject]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CURRICULUM_DELETED', 'user_id' => 7, 'record_id' => $first->id]);
    }

    public function test_version_delete_removes_keyless_workflows_linked_through_each_owned_parent(): void
    {
        DB::statement('PRAGMA foreign_keys = ON');
        $subject = $this->subject(1);
        $selected = $this->curriculum(1, 'workflow-v1');
        $other = $this->curriculum(1, 'workflow-v2');
        $selectedStage = $this->stage($selected);
        $otherStage = $this->stage($other);
        $selectedMembership = $this->membership($selected, $subject, $selectedStage);
        $otherMembership = $this->membership($other, $subject, $otherStage);
        Schema::create('curriculum_joint_workflows', function (Blueprint $table): void {
            $table->unsignedBigInteger('stage_id');
            $table->unsignedBigInteger('membership_id');
            $table->foreign('stage_id')->references('id')->on('curriculum_stages')->onDelete('restrict');
            $table->foreign('membership_id')->references('id')->on('curriculum_memberships')->onDelete('restrict');
            $table->primary(['stage_id', 'membership_id']);
        });
        DB::table('curriculum_joint_workflows')->insert([
            ['stage_id' => $selectedStage->id, 'membership_id' => $otherMembership->id],
            ['stage_id' => $otherStage->id, 'membership_id' => $selectedMembership->id],
        ]);

        $this->service->deleteDraft($selected, 'DELETE', 7);

        $this->assertDatabaseCount('curriculum_joint_workflows', 0);
        $this->assertDatabaseHas('curricula', ['id' => $other->id]);
        $this->assertDatabaseHas('curriculum_stages', ['id' => $otherStage->id]);
        $this->assertDatabaseHas('curriculum_memberships', ['id' => $otherMembership->id]);
        $this->assertDatabaseHas('subjects', ['id' => $subject]);
    }

    public function test_discovered_restrictive_curriculum_dependency_is_deleted_with_its_version(): void
    {
        DB::statement('PRAGMA foreign_keys = ON');
        Schema::create('opaque_curriculum_reference', function (Blueprint $table): void {
            $table->unsignedBigInteger('owner_curriculum_id');
            $table->foreign('owner_curriculum_id')->references('id')->on('curricula')->onDelete('restrict');
        });
        $curriculum = $this->curriculum(1, 'blocked');
        $stage = $this->stage($curriculum);
        $membership = $this->membership($curriculum, $this->subject(1), $stage);
        DB::table('opaque_curriculum_reference')->insert(['owner_curriculum_id' => $curriculum->id]);

        $this->service->deleteDraft($curriculum, 'DELETE', 7);
        $this->assertDatabaseMissing('curricula', ['id' => $curriculum->id]);
        $this->assertDatabaseMissing('curriculum_stages', ['id' => $stage->id]);
        $this->assertDatabaseMissing('curriculum_memberships', ['id' => $membership->id]);
        $this->assertDatabaseMissing('opaque_curriculum_reference', ['owner_curriculum_id' => $curriculum->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CURRICULUM_DELETED', 'record_id' => $curriculum->id]);
    }

    public function test_every_curriculum_lifecycle_status_can_be_deleted_after_explicit_confirmation(): void
    {
        foreach (['draft', 'approved', 'retired'] as $status) {
            $curriculum = $this->curriculum(1, 'delete-'.$status);
            DB::table('curricula')->where('id', $curriculum->id)->update(['status' => $status]);
            $this->service->deleteDraft($curriculum, $curriculum->version, 9);
            $this->assertDatabaseMissing('curricula', ['id' => $curriculum->id]);
            $this->assertDatabaseHas('audit_logs', ['action' => 'CURRICULUM_DELETED', 'user_id' => 9, 'record_id' => $curriculum->id]);
        }
    }

    public function test_k12_subject_usage_does_not_require_a_curriculum(): void
    {
        $subjectId = $this->subject(1, ['class_id' => 7, 'session_id' => 9]);

        $this->assertSame(7, (int) Subject::where('school_id', 1)->findOrFail($subjectId)->class_id);
        $this->assertSame(0, Curriculum::count());
        $this->assertSame(0, CurriculumMembership::count());
    }

    public function test_curriculum_membership_does_not_rewrite_subject_catalogue_fields_or_import_legacy_programme_links(): void
    {
        $programmeId = $this->programme(1);
        $subjectId = $this->subject(1, [
            'programme_id' => $programmeId,
            'credits' => '7.50',
            'course_type' => 'general',
        ]);
        $curriculum = $this->curriculum(1);

        $this->assertSame(0, $curriculum->memberships()->count(), 'Legacy Programme association is not backfilled.');
        $stage = $this->stage($curriculum);
        $membership = $this->membership($curriculum, $subjectId, $stage, ['credits' => '3.25', 'classification' => 'elective']);
        $subject = Subject::where('school_id', 1)->findOrFail($subjectId);

        $this->assertSame($subjectId, (int) $subject->id);
        $this->assertSame($programmeId, (int) $subject->programme_id);
        $this->assertSame(7.5, (float) $subject->credits);
        $this->assertSame('general', $subject->course_type);
        $this->assertSame('3.25', $membership->credits);
        $this->assertSame('elective', $membership->classification);
    }

    public function test_successor_can_start_blank_or_clone_structure_without_mutating_source(): void
    {
        $source = $this->curriculum(1, 'original', $this->academicYear(1));
        $stage = $this->stage($source, 'Foundation', 1);
        $first = $this->membership($source, $this->subject(1), $stage, [
            'classification' => 'elective', 'credits' => '2.50', 'period_type' => 'semester', 'period_sequence' => 1, 'sequence' => 4,
        ]);
        $second = $this->membership($source, $this->subject(1), $stage, [
            'credits' => '3.00', 'period_type' => 'semester', 'period_sequence' => 2, 'sequence' => 5,
        ]);
        $this->service->addPrerequisite($source, $second->id, $first->id);
        $source = $this->service->approve($source);

        $blank = $this->service->createSuccessor($source, 'blank', null, false);
        $this->assertSame('draft', $blank->status);
        $this->assertSame(0, $blank->stages()->count());
        $this->assertSame(0, $blank->memberships()->count());

        $clone = $this->service->createSuccessor($source, 'clone', null, true);
        $this->assertSame('draft', $clone->status);
        $this->assertNotSame($source->id, $clone->id);
        $this->assertSame('Foundation', $clone->stages()->first()->label);
        $clonedMemberships = $clone->memberships()->orderBy('sequence')->get();
        $this->assertCount(2, $clonedMemberships);
        $this->assertNotSame($first->id, $clonedMemberships[0]->id);
        $this->assertSame($first->subject_id, $clonedMemberships[0]->subject_id);
        $this->assertSame('elective', $clonedMemberships[0]->classification);
        $this->assertSame('2.50', $clonedMemberships[0]->credits);
        $this->assertSame(1, $clonedMemberships[0]->period_sequence);
        $this->assertSame(1, DB::table('curriculum_prerequisites')->where('curriculum_id', $clone->id)->count());
        $clonedEdge = DB::table('curriculum_prerequisites')->where('curriculum_id', $clone->id)->first();
        $this->assertSame((int) $clonedMemberships[1]->id, (int) $clonedEdge->membership_id);
        $this->assertSame((int) $clonedMemberships[0]->id, (int) $clonedEdge->prerequisite_membership_id);
        $this->assertSame('approved', $source->fresh()->status);
        $this->assertSame(2, $source->memberships()->count());
    }

    private function createFoundationSchema(): void
    {
        Schema::create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('academic_calendar_pattern')->nullable();
            $table->timestamps();
        });
        Schema::create('programmes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unique(['school_id', 'id']);
        });
        Schema::create('academic_years', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unique(['school_id', 'id']);
        });
        Schema::create('subjects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('name');
            $table->unsignedBigInteger('programme_id')->nullable();
            $table->integer('class_id')->nullable();
            $table->integer('session_id')->nullable();
            $table->string('code')->nullable();
            $table->decimal('credits', 6, 2)->nullable();
            $table->string('course_type')->nullable();
            $table->unique(['school_id', 'id']);
        });
        Schema::create('curricula', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('programme_id');
            $table->string('version'); $table->unsignedBigInteger('effective_academic_year_id')->nullable();
            $table->string('status')->default('draft'); $table->timestamps(); $table->unique(['school_id', 'id']);
        });
        Schema::create('curriculum_stages', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('curriculum_id');
            $table->string('label'); $table->unsignedSmallInteger('sequence'); $table->timestamps();
            $table->unique(['school_id', 'curriculum_id', 'id']); $table->unique(['curriculum_id', 'sequence']);
        });
        Schema::create('curriculum_memberships', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('subject_id'); $table->unsignedBigInteger('curriculum_stage_id');
            $table->string('period_type')->nullable(); $table->unsignedSmallInteger('period_sequence')->nullable();
            $table->string('classification'); $table->decimal('credits', 6, 2); $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps(); $table->unique(['school_id', 'curriculum_id', 'id']); $table->unique(['curriculum_id', 'subject_id']);
        });
        Schema::create('curriculum_prerequisites', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_id'); $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('membership_id'); $table->unsignedBigInteger('prerequisite_membership_id');
            $table->primary(['membership_id', 'prerequisite_membership_id']);
        });
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('school_id')->nullable(); $table->unsignedBigInteger('user_id');
            $table->string('user_name')->nullable(); $table->unsignedBigInteger('role_id')->nullable(); $table->string('role_name')->nullable();
            $table->string('action')->nullable(); $table->string('event_type')->nullable(); $table->string('module')->nullable();
            $table->text('description')->nullable(); $table->string('record_type')->nullable(); $table->unsignedBigInteger('record_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('schools')->insert([
            ['id' => 1, 'academic_calendar_pattern' => 'semester'],
            ['id' => 2, 'academic_calendar_pattern' => 'term'],
        ]);
    }

    private function programme(int $schoolId): int
    {
        return (int) DB::table('programmes')->insertGetId(['school_id' => $schoolId]);
    }

    private function academicYear(int $schoolId): int
    {
        return (int) DB::table('academic_years')->insertGetId(['school_id' => $schoolId]);
    }

    private function curriculum(int $schoolId, string $version = 'v1', ?int $yearId = null): Curriculum
    {
        return $this->service->createDraft($schoolId, $this->programme($schoolId), $version, $yearId);
    }

    private function stage(Curriculum $curriculum, string $label = 'Year 1', int $sequence = 1): CurriculumStage
    {
        return $this->service->addStage($curriculum, $label, $sequence);
    }

    private function subject(int $schoolId, array $overrides = []): int
    {
        return (int) DB::table('subjects')->insertGetId(array_merge([
            'school_id' => $schoolId,
            'name' => 'Subject ' . uniqid('', true),
            'class_id' => null,
            'session_id' => null,
            'code' => null,
            'credits' => '7.50',
            'course_type' => 'general',
        ], $overrides));
    }

    private function membership(Curriculum $curriculum, int $subjectId, CurriculumStage $stage, array $overrides = []): CurriculumMembership
    {
        return $this->service->addMembership($curriculum, $subjectId, $stage->id, array_merge([
            'classification' => 'compulsory',
            'credits' => '3.00',
        ], $overrides));
    }

    private function assertRejected(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail("Expected validation failure for {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
