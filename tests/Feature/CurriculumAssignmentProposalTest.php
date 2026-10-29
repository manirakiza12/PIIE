<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CourseRegistration\{AutomaticCourseEnrolment, CourseOfferingEligibility};
use App\Support\Curriculum\{CurriculumAssignmentProposals, CurriculumProposalBlocked};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\{AdmissionsTestHelper, CourseOfferingEligibilityFixture, FrozenClock};
use Tests\TestCase;

class CurriculumAssignmentProposalTest extends TestCase
{
    use AdmissionsTestHelper, CourseOfferingEligibilityFixture, FrozenClock;

    protected User $registrar;
    protected User $student;
    protected int $admission;
    protected int $intake;
    protected CurriculumAssignmentProposals $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock('2026-10-29 12:00:00');
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', function (Blueprint $t): void {
            $t->string('school_type')->default('higher_ed');
            $t->string('academic_calendar_pattern')->default('semester');
            $t->unsignedBigInteger('current_academic_year_id')->nullable();
            $t->unsignedBigInteger('current_academic_period_id')->nullable();
        });
        $this->createTables();
        if (! Schema::hasColumn('curricula', 'effective_academic_year_id')) Schema::table('curricula', fn (Blueprint $t) => $t->unsignedBigInteger('effective_academic_year_id')->nullable());
        Schema::table('curriculum_stages', fn (Blueprint $t) => $t->timestamp('archived_at')->nullable());
        Schema::create('curriculum_prerequisites', function (Blueprint $t): void {
            $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('curriculum_id');
            $t->unsignedBigInteger('membership_id'); $t->unsignedBigInteger('prerequisite_membership_id');
            $t->primary(['membership_id', 'prerequisite_membership_id']);
        });
        Schema::create('student_requests', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('student_id'); $t->string('type'); $t->string('status');
        });
        $this->seedAcademicStructure();
        DB::table('curricula')->update(['effective_academic_year_id' => 1]);
        if (! Schema::hasColumn('student_profiles', 'was_existing_at_portal_creation')) Schema::table('student_profiles', fn (Blueprint $t) => $t->boolean('was_existing_at_portal_creation')->default(false));
        if (! Schema::hasColumn('student_profiles', 'year_of_study')) Schema::table('student_profiles', fn (Blueprint $t) => $t->unsignedSmallInteger('year_of_study')->nullable());
        (require database_path('migrations/2026_10_28_190000_create_safe_automation_state.php'))->up();
        (require database_path('migrations/2026_10_29_090000_create_curriculum_assignment_proposals.php'))->up();
        (require database_path('migrations/2026_09_23_000003_create_rbac_tables.php'))->up();
        $this->registrar = $this->makeAdminUser($this->school);
        $this->student = User::factory()->create(['role_id' => 7, 'school_id' => $this->school, 'account_status' => 'active']);
        $this->intake = $this->makeIntakeSession($this->school);
        DB::table('student_profiles')->insert(['user_id' => $this->student->id, 'school_id' => $this->school, 'programme_id' => $this->programme, 'intake_session_id' => $this->intake, 'status' => 'active']);
        $this->admission = $this->makeAdmission($this->school, ['programme_id' => $this->programme, 'intake_session_id' => $this->intake, 'automation_student_id' => $this->student->id, 'status' => 'accepted']);
        $this->service = app(CurriculumAssignmentProposals::class);
    }

    protected function mappingData(array $changes = []): array
    {
        return $changes + ['programme_id' => $this->programme, 'intake_session_id' => $this->intake, 'curriculum_id' => 1,
            'entry_academic_year_id' => 1, 'entry_curriculum_stage_id' => 1, 'reason' => 'Registrar resolution TEST-01: ordinary entry for this programme and intake.'];
    }

    protected function approvedMapping(): int
    {
        $id = $this->service->createMapping($this->registrar, $this->mappingData());
        $this->service->approveMapping($this->registrar, $id);
        return $id;
    }

    protected function approvedProposal(): int
    {
        $this->approvedMapping();
        $id = $this->service->propose($this->registrar, $this->admission, 'Reviewed ordinary initial entry; no transfer, exemption or prior-credit claim.');
        $this->service->review($this->registrar, $id, true, 'Registrar checked the governing curriculum and entry stage.', true);
        return $id;
    }

    protected function blocked(callable $fn, string $code): void
    {
        try { $fn(); $this->fail('Expected block '.$code); }
        catch (CurriculumProposalBlocked $e) { $this->assertSame($code, $e->reasonCode); }
    }

    public function test_mapping_and_proposal_approval_never_apply_placement_until_explicit_apply(): void
    {
        $mapping = $this->service->createMapping($this->registrar, $this->mappingData());
        $this->assertDatabaseHas('curriculum_applicability_mappings', ['id' => $mapping, 'status' => 'draft', 'approved_by' => null]);
        $this->blocked(fn () => $this->service->propose($this->registrar, $this->admission, 'Ordinary entry checked'), 'mapping_missing');
        $this->service->approveMapping($this->registrar, $mapping);
        $proposal = $this->service->propose($this->registrar, $this->admission, 'Ordinary entry checked');
        $this->blocked(fn () => $this->service->apply($this->registrar, $proposal, true), 'proposal_not_approved');
        $this->blocked(fn () => $this->service->review($this->registrar, $proposal, true, 'Reviewed', false), 'enrolment_acknowledgement_required');
        $this->service->review($this->registrar, $proposal, true, 'Reviewed', true);
        $this->assertDatabaseCount('student_curriculum_assignments', 0);
        $this->assertDatabaseCount('course_registrations', 0);
        $placement = $this->service->apply($this->registrar, $proposal, true);
        $this->assertDatabaseHas('student_curriculum_assignments', ['id' => $placement, 'student_id' => $this->student->id, 'curriculum_id' => 1, 'entry_curriculum_stage_id' => 1, 'entry_academic_year_id' => 1]);
        $this->assertDatabaseHas('student_profiles', ['user_id' => $this->student->id, 'year_of_study' => 1]);
        $this->assertDatabaseHas('curriculum_assignment_proposals', ['id' => $proposal, 'placement_id' => $placement, 'status' => 'applied', 'reviewed_by' => $this->registrar->id, 'applied_by' => $this->registrar->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CURRICULUM_PROPOSAL_APPLIED', 'record_id' => $proposal, 'user_id' => $this->registrar->id]);
        $this->assertDatabaseCount('course_registrations', 0);
    }

    public function test_retries_return_one_proposal_and_one_placement_without_duplicate_audits(): void
    {
        $this->approvedMapping();
        $id = $this->service->propose($this->registrar, $this->admission, 'Ordinary entry reviewed');
        $this->assertSame($id, $this->service->propose($this->registrar, $this->admission, 'Retry'));
        $this->service->review($this->registrar, $id, true, 'Reviewed', true);
        $placement = $this->service->apply($this->registrar, $id, true);
        $this->assertSame($placement, $this->service->apply($this->registrar, $id, true));
        $this->assertDatabaseCount('curriculum_assignment_proposals', 1);
        $this->assertDatabaseCount('student_curriculum_assignments', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'CURRICULUM_PROPOSAL_APPLIED')->count());
    }

    public function test_rejected_proposal_cannot_be_approved_or_applied_by_retry(): void
    {
        $this->approvedMapping();
        $id = $this->service->propose($this->registrar, $this->admission, 'Entry reviewed');
        $this->service->review($this->registrar, $id, false, 'Evidence insufficient', false);
        $this->assertSame($id, $this->service->propose($this->registrar, $this->admission, 'Retry'));
        $this->blocked(fn () => $this->service->apply($this->registrar, $id, true), 'proposal_not_approved');
        $this->blocked(fn () => $this->service->review($this->registrar, $id, true, 'Retry', true), 'proposal_not_pending');
        $this->assertDatabaseCount('student_curriculum_assignments', 0);
    }

    public static function contextChanges(): array
    {
        return [
            'curriculum version' => ['curricula', 1, ['version' => 'Changed']],
            'unit credits' => ['curriculum_memberships', 111, ['credits' => 4]],
            'stage' => ['curriculum_stages', 1, ['label' => 'Changed']],
            'intake' => ['intake_sessions', null, ['name' => 'Changed']],
            'academic year' => ['academic_years', 1, ['start_date' => '2026-08-01']],
        ];
    }

    #[DataProvider('contextChanges')]
    public function test_academic_changes_invalidate_mapping_and_previously_approved_proposal(string $table, ?int $id, array $changes): void
    {
        $proposal = $this->approvedProposal();
        DB::table($table)->where('id', $id ?? $this->intake)->update($changes);
        $this->blocked(fn () => $this->service->apply($this->registrar, $proposal, true), 'mapping_stale');
        $this->assertDatabaseCount('student_curriculum_assignments', 0);
        $this->assertDatabaseHas('automation_exceptions', ['workflow' => 'curriculum_assignment', 'subject_id' => $this->admission, 'code' => 'mapping_stale', 'resolved_at' => null]);
    }

    public function test_mapping_approval_is_explicit_unique_and_revocation_blocks_application(): void
    {
        $first = $this->approvedMapping();
        $second = $this->service->createMapping($this->registrar, $this->mappingData(['curriculum_id' => 2, 'entry_curriculum_stage_id' => 4]));
        $this->blocked(fn () => $this->service->approveMapping($this->registrar, $second), 'mapping_ambiguous');
        $id = $this->service->propose($this->registrar, $this->admission, 'Reviewed entry');
        $this->service->review($this->registrar, $id, true, 'Reviewed', true);
        $this->service->revokeMapping($this->registrar, $first, 'Policy withdrawn');
        $this->blocked(fn () => $this->service->apply($this->registrar, $id, true), 'mapping_inactive');
        $this->service->approveMapping($this->registrar, $second);
        $this->assertDatabaseHas('curriculum_applicability_mappings', ['id' => $second, 'status' => 'approved']);
    }

    public function test_corrupt_multiple_approved_mappings_are_blocked_not_guessed(): void
    {
        $first = $this->approvedMapping();
        $copy = (array) DB::table('curriculum_applicability_mappings')->find($first);
        unset($copy['id']); DB::table('curriculum_applicability_mappings')->insert($copy);
        $this->blocked(fn () => $this->service->propose($this->registrar, $this->admission, 'Reviewed'), 'mapping_ambiguous');
    }

    public function test_student_context_changes_are_revalidated_at_approval_and_application(): void
    {
        $this->approvedMapping();
        $proposal = $this->service->propose($this->registrar, $this->admission, 'Reviewed');
        DB::table('student_profiles')->where('user_id', $this->student->id)->update(['year_of_study' => 2]);
        $this->blocked(fn () => $this->service->review($this->registrar, $proposal, true, 'Reviewed', true), 'proposal_stale');
        $new = $this->service->propose($this->registrar, $this->admission, 'Reviewed updated evidence');
        $this->service->review($this->registrar, $new, true, 'Reviewed', true);
        DB::table('student_profiles')->where('user_id', $this->student->id)->update(['programme_id' => $this->otherProgramme]);
        $this->blocked(fn () => $this->service->apply($this->registrar, $new, true), 'profile_conflict');
    }

    public static function historyCases(): array
    {
        return [['placement'], ['ended placement'], ['registration'], ['legacy enrollment'], ['cohort'], ['transfer'], ['existing student']];
    }

    #[DataProvider('historyCases')]
    public function test_history_and_transfer_cases_are_preserved_and_routed_to_registrar(string $kind): void
    {
        $proposal = $this->approvedProposal();
        switch ($kind) {
            case 'placement': case 'ended placement':
                DB::table('student_curriculum_assignments')->insert(['school_id' => $this->school, 'student_id' => $this->student->id, 'programme_id' => $this->programme, 'curriculum_id' => 1, 'entry_academic_year_id' => 1, 'effective_from_academic_year_id' => 1, 'assigned_by' => $this->registrar->id, 'ended_at' => $kind === 'ended placement' ? now() : null]); break;
            case 'registration':
                DB::table('course_registrations')->insert(['school_id' => $this->school, 'student_id' => $this->student->id, 'subject_id' => $this->units['Y1'], 'course_offering_id' => 11, 'status' => 'withdrawn']); break;
            case 'legacy enrollment': DB::table('enrollment')->insert(['school_id' => $this->school, 'user_id' => $this->student->id]); break;
            case 'cohort': DB::table('programme_cohort_memberships')->insert(['school_id' => $this->school, 'student_id' => $this->student->id, 'programme_cohort_id' => 1, 'status' => 'withdrawn', 'started_at' => now()]); break;
            case 'transfer': DB::table('student_requests')->insert(['school_id' => $this->school, 'student_id' => $this->student->id, 'type' => 'transfer', 'status' => 'approved']); break;
            case 'existing student': DB::table('student_profiles')->where('user_id', $this->student->id)->update(['was_existing_at_portal_creation' => true]); break;
        }
        $tables = ['student_curriculum_assignments', 'course_registrations', 'enrollment', 'programme_cohort_memberships', 'student_requests', 'student_profiles'];
        $before = array_map(fn ($t) => DB::table($t)->get()->toJson(), $tables);
        $this->blocked(fn () => $this->service->apply($this->registrar, $proposal, true), match ($kind) { 'transfer' => 'transfer_review', 'existing student' => 'historical_student', default => 'academic_history_present' });
        $this->assertSame($before, array_map(fn ($t) => DB::table($t)->get()->toJson(), $tables));
    }

    public function test_inactive_student_and_unlinked_historical_admission_are_blocked(): void
    {
        $this->approvedMapping();
        DB::table('users')->where('id', $this->student->id)->update(['account_status' => 'disable']);
        $this->blocked(fn () => $this->service->propose($this->registrar, $this->admission, 'Reviewed'), 'student_inactive');
        DB::table('admissions')->where('id', $this->admission)->update(['automation_student_id' => null, 'status' => 'enrolled']);
        $this->blocked(fn () => $this->service->propose($this->registrar, $this->admission, 'Reviewed'), 'admission_ineligible');
    }

    public function test_exception_retries_deduplicate_and_resolve_only_after_revalidation(): void
    {
        for ($i = 0; $i < 2; $i++) $this->blocked(fn () => $this->service->propose($this->registrar, $this->admission, 'Reviewed'), 'mapping_missing');
        $this->assertDatabaseCount('automation_exceptions', 1);
        $this->assertDatabaseHas('automation_exceptions', ['occurrences' => 2, 'resolved_at' => null]);
        $this->approvedMapping();
        $this->service->propose($this->registrar, $this->admission, 'Reviewed');
        $this->assertNotNull(DB::table('automation_exceptions')->first()->resolved_at);
        $this->assertDatabaseCount('student_curriculum_assignments', 0);
    }

    public function test_failed_audit_write_rolls_back_placement_profile_and_application_atomically(): void
    {
        $proposal = $this->approvedProposal();
        DB::statement("CREATE TRIGGER refuse_application_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'CURRICULUM_PROPOSAL_APPLIED' BEGIN SELECT RAISE(ABORT, 'injected audit failure'); END");
        try { $this->service->apply($this->registrar, $proposal, true); $this->fail('Expected rollback'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('injected audit failure', $e->getMessage()); }
        $this->assertDatabaseCount('student_curriculum_assignments', 0);
        $this->assertDatabaseHas('curriculum_assignment_proposals', ['id' => $proposal, 'status' => 'approved', 'placement_id' => null]);
        $this->assertNull(DB::table('student_profiles')->where('user_id', $this->student->id)->value('year_of_study'));
    }

    public function test_placement_preserves_finance_and_admission_and_downstream_finance_gate(): void
    {
        DB::table('student_fee_managers')->insert(['school_id' => $this->school, 'student_id' => $this->student->id, 'session_id' => 1, 'title' => 'Existing tuition', 'payment_method' => 'offline', 'total_amount' => 100, 'amount' => 100, 'paid_amount' => 0, 'class_id' => 0, 'status' => 'unpaid']);
        $before = [DB::table('student_fee_managers')->get()->toJson(), DB::table('admissions')->get()->toJson()];
        $id = $this->approvedProposal();
        $this->service->apply($this->registrar, $id, true);
        $this->assertSame($before, [DB::table('student_fee_managers')->get()->toJson(), DB::table('admissions')->get()->toJson()]);
        $this->assertDatabaseCount('course_registrations', 0);
        $decisions = app(AutomaticCourseEnrolment::class)->reconcileStudent($this->school, $this->student->id, true);
        $this->assertSame('finance_ineligible', $decisions[0]['reason']);
        $this->assertDatabaseCount('course_registrations', 0);
        $this->assertSame($before[0], DB::table('student_fee_managers')->get()->toJson());
        $academic = app(CourseOfferingEligibility::class);
        $this->assertSame('progression_not_recorded', $academic->placement($this->school, $this->student->id, 2)->code);
    }

    public function test_explicit_enrolment_acknowledgement_required_on_application(): void
    {
        $id = $this->approvedProposal();
        $this->blocked(fn () => $this->service->apply($this->registrar, $id, false), 'enrolment_acknowledgement_required');
        $this->assertDatabaseCount('student_curriculum_assignments', 0);
    }

    public function test_foreign_institution_records_and_actor_are_refused(): void
    {
        $id = $this->approvedProposal();
        $foreign = $this->makeAdminUser($this->foreignSchool);
        $this->actingAs($foreign)->post(route('admin.curriculum_assignment.apply', $id), ['acknowledge_enrolment' => 1])->assertNotFound();
        $this->actingAs($foreign)->post(route('admin.curriculum_assignment.propose'), ['admission_id' => $this->admission, 'ordinary_entry_evidence' => 'Reviewed'])->assertNotFound();
        $foreignIntake = $this->makeIntakeSession($this->foreignSchool);
        $this->blocked(fn () => $this->service->createMapping($this->registrar, $this->mappingData(['intake_session_id' => $foreignIntake])), 'mapping_context_invalid');
        $this->assertDatabaseCount('student_curriculum_assignments', 0);
    }

    public function test_students_and_ungranted_staff_cannot_use_new_routes_or_service(): void
    {
        $staff = User::factory()->create(['school_id' => $this->school, 'role_id' => 13, 'account_status' => 'active']);
        foreach ([$this->student, $staff] as $actor) {
            $response = $this->actingAs($actor)->post(route('admin.curriculum_assignment.mapping.store'), $this->mappingData());
            if ((int) $actor->role_id === 7) $response->assertRedirect();
            else $response->assertForbidden();
            try { $this->service->createMapping($actor, $this->mappingData()); $this->fail('Expected service authorization'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        }
        $this->assertDatabaseCount('curriculum_applicability_mappings', 0);
    }

    public function test_delegated_registrar_permissions_and_placement_authority_are_separate(): void
    {
        $registrar = User::factory()->create(['school_id' => $this->school, 'role_id' => 20, 'account_status' => 'active']);
        foreach (['view', 'manage'] as $action) DB::table('user_permissions')->insert(['school_id' => $this->school, 'user_id' => $registrar->id, 'permission' => 'academic.curriculum_assignment.'.$action]);
        $mapping = $this->service->createMapping($registrar, $this->mappingData());
        $this->actingAs($registrar)->post(route('admin.curriculum_assignment.mapping.approve', $mapping))->assertRedirect();
        $this->assertDatabaseHas('curriculum_applicability_mappings', ['id' => $mapping, 'status' => 'draft']);
        try { $this->service->approveMapping($registrar, $mapping); $this->fail('Expected Registrar permission'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        DB::table('user_permissions')->insert(['school_id' => $this->school, 'user_id' => $registrar->id, 'permission' => 'academic.curriculum_assignment.approve']);
        $this->service->approveMapping($registrar, $mapping);
        $proposal = $this->service->propose($registrar, $this->admission, 'Registrar ordinary-entry evidence');
        $this->service->review($registrar, $proposal, true, 'Reviewed by delegated Registrar', true);
        $this->actingAs($registrar)->post(route('admin.curriculum_assignment.apply', $proposal), ['acknowledge_enrolment' => 1])->assertForbidden();
        DB::table('user_permissions')->insert(['school_id' => $this->school, 'user_id' => $registrar->id, 'permission' => 'academic.programme_cohort.membership']);
        $this->actingAs($registrar)->post(route('admin.curriculum_assignment.apply', $proposal), ['acknowledge_enrolment' => 1])->assertRedirect();
        $this->assertDatabaseHas('student_curriculum_assignments', ['student_id' => $this->student->id, 'assigned_by' => $registrar->id]);
    }

    public function test_curriculum_deletion_cannot_remove_approval_provenance(): void
    {
        $this->approvedMapping();
        try {
            app(\App\Support\Curriculum\CurriculumFoundationService::class)->deleteDraft(\App\Models\Curriculum::findOrFail(1), 'DELETE', $this->registrar->id);
            $this->fail('Expected provenance protection');
        } catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('approval history', $e->getMessage()); }
        $this->assertDatabaseHas('curricula', ['id' => 1]);
        $this->assertDatabaseCount('curriculum_applicability_mappings', 1);
    }

    public function test_disabled_approver_authority_is_rechecked_before_application(): void
    {
        $id = $this->approvedProposal();
        $other = $this->makeAdminUser($this->school);
        DB::table('users')->where('id', $this->registrar->id)->update(['account_status' => 'disable']);
        $this->blocked(fn () => $this->service->apply($other, $id, true), 'review_authority_changed');
    }

    public function test_review_screen_is_read_only_and_shows_enrolment_consequence(): void
    {
        $this->approvedProposal();
        $before = DB::table('curriculum_assignment_proposals')->get()->toJson();
        $this->actingAs($this->registrar)->get(route('admin.curriculum_assignment.index'))->assertOk()->assertSee('automatic course enrolment')->assertSee('Apply approved placement');
        $this->assertSame($before, DB::table('curriculum_assignment_proposals')->get()->toJson());
        $this->assertDatabaseCount('student_curriculum_assignments', 0);
    }
}
