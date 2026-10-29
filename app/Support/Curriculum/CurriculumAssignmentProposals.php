<?php

namespace App\Support\Curriculum;

use App\Models\{Curriculum, User};
use App\Support\Automation\AutomationExceptions;
use App\Support\Permissions\PermissionService;
use Illuminate\Support\Facades\{DB, Schema};

/** Human-reviewed orchestration only. Placement remains owned by the existing service. */
final class CurriculumAssignmentProposals
{
    public const NOTICE = 'Approving and applying this placement may allow existing automatic course enrolment to register and confirm eligible courses after its academic and Finance checks. No course or financial obligation is created by this action itself.';

    public function __construct(private CurriculumFoundationService $curricula, private StudentCurriculumAssignmentService $placements) {}

    public function createMapping(User $actor, array $data): int
    {
        $this->authorize($actor, 'manage');
        return $this->transaction($actor, function () use ($actor, $data): int {
            $this->requireText($data['reason'] ?? '', 'Record the Registrar policy or approval reference for this mapping.');
            $mapping = (object) array_intersect_key($data, array_flip(['programme_id', 'intake_session_id', 'curriculum_id', 'entry_academic_year_id', 'entry_curriculum_stage_id']));
            $mapping->school_id = (int) $actor->school_id;
            $this->mappingSnapshot($mapping);
            $id = DB::table('curriculum_applicability_mappings')->insertGetId((array) $mapping + [
                'reason' => trim($data['reason']), 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, 'CURRICULUM_MAPPING_DRAFTED', 'curriculum_applicability_mappings', $id, ['mapping' => (array) $mapping, 'reason' => trim($data['reason'])]);
            return $id;
        }, 'manage');
    }

    public function approveMapping(User $actor, int $id): void
    {
        $this->authorize($actor, 'approve');
        $this->guarded($actor, 'curriculum_mapping', $id, function () use ($actor, $id): void {
            $mapping = $this->mapping($actor, $id);
            if ($mapping->status !== 'draft') $this->block('mapping_not_draft', 'Only a draft mapping may be approved. Approved mappings are immutable; revoke and replace them.');
            $snapshot = $this->mappingSnapshot($mapping);
            if ($this->approvedMappings($mapping)->where('id', '<>', $id)->exists()) {
                $this->block('mapping_ambiguous', 'An approved mapping already covers this programme and intake. Revoke it before approving a replacement.');
            }
            DB::table('curriculum_applicability_mappings')->where('id', $id)->update([
                'status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(),
                'approval_fingerprint' => $this->fingerprint($snapshot), 'approved_snapshot' => $this->json($snapshot), 'updated_at' => now(),
            ]);
            $this->audit($actor, 'CURRICULUM_MAPPING_APPROVED', 'curriculum_applicability_mappings', $id, ['fingerprint' => $this->fingerprint($snapshot), 'policy_reference' => $mapping->reason]);
        });
    }

    public function revokeMapping(User $actor, int $id, string $reason): void
    {
        $this->authorize($actor, 'approve');
        $this->transaction($actor, function () use ($actor, $id, $reason): void {
            $this->requireText($reason, 'Explain the mapping revocation.');
            $mapping = $this->mapping($actor, $id);
            if ($mapping->status !== 'approved') $this->block('mapping_not_approved', 'Only an approved mapping may be revoked.');
            DB::table('curriculum_applicability_mappings')->where('id', $id)->update([
                'status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'revocation_reason' => trim($reason), 'updated_at' => now(),
            ]);
            $this->audit($actor, 'CURRICULUM_MAPPING_REVOKED', 'curriculum_applicability_mappings', $id, ['reason' => trim($reason)]);
        });
    }

    public function propose(User $actor, int $admissionId, string $ordinaryEntryEvidence): int
    {
        $this->authorize($actor, 'manage');
        return $this->guarded($actor, 'curriculum_assignment', $admissionId, function () use ($actor, $admissionId, $ordinaryEntryEvidence): int {
            $this->requireText($ordinaryEntryEvidence, 'Record the evidence reviewed for ordinary initial entry, including transfer and credit-recognition checks.');
            $admission = $this->admission($actor, $admissionId);
            $mappings = $this->approvedMappings($admission)->lockForUpdate()->get();
            if ($mappings->count() !== 1) $this->block($mappings->isEmpty() ? 'mapping_missing' : 'mapping_ambiguous', 'Exactly one explicitly approved programme/intake mapping is required.');
            $mapping = $mappings->first();
            $snapshot = $this->decisionSnapshot($actor, $admission, $mapping);
            $fingerprint = $this->fingerprint($snapshot);
            $existing = DB::table('curriculum_assignment_proposals')->where('school_id', $actor->school_id)->where('admission_id', $admissionId)->where('fingerprint', $fingerprint)->first();
            if ($existing) return (int) $existing->id; // Includes rejected decisions; retry cannot erase a rejection.
            $id = DB::table('curriculum_assignment_proposals')->insertGetId([
                'school_id' => $actor->school_id, 'mapping_id' => $mapping->id, 'admission_id' => $admissionId,
                'student_id' => $admission->automation_student_id, 'status' => 'pending', 'fingerprint' => $fingerprint,
                'snapshot' => $this->json($snapshot), 'ordinary_entry_evidence' => trim($ordinaryEntryEvidence),
                'proposed_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, 'CURRICULUM_ASSIGNMENT_PROPOSED', 'curriculum_assignment_proposals', $id, ['fingerprint' => $fingerprint, 'mapping_id' => $mapping->id, 'ordinary_entry_evidence' => trim($ordinaryEntryEvidence)]);
            return $id;
        }, 'manage');
    }

    public function review(User $actor, int $id, bool $approve, string $reason, bool $acknowledge): void
    {
        $this->authorize($actor, 'approve');
        $proposal = $this->proposal($actor, $id, false);
        $this->guarded($actor, 'curriculum_assignment', (int) $proposal->admission_id, function () use ($actor, $id, $approve, $reason, $acknowledge): void {
            $proposal = $this->proposal($actor, $id);
            if ($proposal->status !== 'pending') $this->block('proposal_not_pending', 'Only a pending proposal may be reviewed.');
            $this->requireText($reason, 'Record the Registrar review decision and reason.');
            if ($approve) {
                if (! $acknowledge) $this->block('enrolment_acknowledgement_required', self::NOTICE);
                $this->revalidate($actor, $proposal);
            }
            DB::table('curriculum_assignment_proposals')->where('id', $id)->update([
                'status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $actor->id,
                'reviewed_at' => now(), 'review_reason' => trim($reason),
                'enrolment_acknowledged_at' => $approve ? now() : null, 'updated_at' => now(),
            ]);
            $this->audit($actor, $approve ? 'CURRICULUM_PROPOSAL_APPROVED' : 'CURRICULUM_PROPOSAL_REJECTED', 'curriculum_assignment_proposals', $id, ['reason' => trim($reason), 'enrolment_notice' => $approve ? self::NOTICE : null]);
        }, 'approve', $approve);
    }

    public function apply(User $actor, int $id, bool $acknowledge): int
    {
        $this->authorize($actor, 'approve');
        abort_unless(app(PermissionService::class)->allows($actor, 'academic.programme_cohort.membership'), 403);
        $proposal = $this->proposal($actor, $id, false);
        return $this->guarded($actor, 'curriculum_assignment', (int) $proposal->admission_id, function () use ($actor, $id, $acknowledge): int {
            $freshActor = User::where('school_id', $actor->school_id)->findOrFail($actor->id);
            abort_unless(app(PermissionService::class)->allows($freshActor, 'academic.programme_cohort.membership'), 403);
            $proposal = $this->proposal($actor, $id);
            if ($proposal->status === 'applied') {
                // Idempotent replay returns only the recorded result; never recreates or corrects it.
                abort_unless(DB::table('student_curriculum_assignments')->where('school_id', $actor->school_id)->where('student_id', $proposal->student_id)->where('id', $proposal->placement_id)->exists(), 409, 'Applied placement needs historical review.');
                return (int) $proposal->placement_id;
            }
            if ($proposal->status !== 'approved' || ! $proposal->reviewed_by || ! $proposal->enrolment_acknowledged_at) $this->block('proposal_not_approved', 'A Registrar-approved proposal is required.');
            if (! $acknowledge) $this->block('enrolment_acknowledgement_required', self::NOTICE);
            $reviewer = User::where('school_id', $actor->school_id)->find($proposal->reviewed_by);
            if (! $reviewer || ! app(PermissionService::class)->allows($reviewer, 'academic.curriculum_assignment.approve')) $this->block('review_authority_changed', 'The approving Registrar no longer has authority. Obtain a fresh review.');
            $mapping = $this->revalidate($actor, $proposal);
            $placement = $this->placements->assignDirectPlacement((int) $actor->school_id, (int) $proposal->student_id,
                (int) $mapping->programme_id, (int) $mapping->curriculum_id, (int) $mapping->entry_curriculum_stage_id,
                (int) $mapping->entry_academic_year_id, (int) $actor->id);
            DB::table('curriculum_assignment_proposals')->where('id', $id)->update([
                'status' => 'applied', 'placement_id' => $placement->id, 'applied_by' => $actor->id, 'applied_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, 'CURRICULUM_PROPOSAL_APPLIED', 'curriculum_assignment_proposals', $id, [
                'placement_id' => $placement->id, 'mapping_id' => $mapping->id, 'mapping_approved_by' => $mapping->approved_by,
                'reviewed_by' => $proposal->reviewed_by, 'fingerprint' => $proposal->fingerprint, 'enrolment_notice' => self::NOTICE,
            ]);
            return (int) $placement->id;
        });
    }

    private function revalidate(User $actor, object $proposal): object
    {
        $mapping = $this->mapping($actor, (int) $proposal->mapping_id);
        $snapshot = $this->decisionSnapshot($actor, $this->admission($actor, (int) $proposal->admission_id), $mapping);
        if (! hash_equals($proposal->fingerprint, $this->fingerprint($snapshot))) $this->block('proposal_stale', 'The reviewed student or academic context changed. Generate and review a fresh proposal.');
        return $mapping;
    }

    private function decisionSnapshot(User $actor, object $admission, object $mapping): array
    {
        if ($mapping->status !== 'approved' || ! $mapping->approved_by || ! $mapping->approved_at) $this->block('mapping_inactive', 'The curriculum mapping is not approved.');
        $approver = User::where('school_id', $actor->school_id)->find($mapping->approved_by);
        if (! $approver || ! app(PermissionService::class)->allows($approver, 'academic.curriculum_assignment.approve')) $this->block('mapping_authority_changed', 'The mapping approver no longer has Registrar authority. Revoke and replace the mapping.');
        if ($this->approvedMappings($admission)->count() !== 1 || (int) $mapping->programme_id !== (int) $admission->programme_id || (int) $mapping->intake_session_id !== (int) $admission->intake_session_id) $this->block('mapping_ambiguous', 'The approved programme/intake mapping is conflicting.');
        $academic = $this->mappingSnapshot($mapping);
        if (! hash_equals((string) $mapping->approval_fingerprint, $this->fingerprint($academic))) $this->block('mapping_stale', 'Academic context changed after mapping approval. Revoke and replace the mapping.');
        if (! in_array($admission->status, ['accepted', 'enrolled'], true) || ! $admission->automation_student_id) $this->block('admission_ineligible', 'Use a linked accepted or enrolled new admission. Historical admissions require manual review.');
        $student = User::where('school_id', $actor->school_id)->where('id', $admission->automation_student_id)->lockForUpdate()->first();
        $profile = DB::table('student_profiles')->where('school_id', $actor->school_id)->where('user_id', $admission->automation_student_id)->lockForUpdate()->first();
        if (! $student || (int) $student->role_id !== 7 || $student->account_status === 'disable' || ! $profile || $profile->status !== 'active') $this->block('student_inactive', 'An enabled student and active institution profile are required.');
        if ((int) $profile->programme_id !== (int) $admission->programme_id || (int) $profile->intake_session_id !== (int) $admission->intake_session_id) $this->block('profile_conflict', 'Student programme/intake conflicts with the admission. Registrar review is required.');
        if ($profile->was_existing_at_portal_creation ?? false) $this->block('historical_student', 'Existing-student placement is outside controlled initial admission assignment.');
        if ($admission->applicant_id) {
            $applicant = DB::table('applicants')->where('school_id', $actor->school_id)->where('id', $admission->applicant_id)->lockForUpdate()->first();
            if (! $applicant || (int) $applicant->converted_user_id !== (int) $student->id) $this->block('identity_conflict', 'The applicant and established student identity disagree.');
        }
        foreach ([['student_curriculum_assignments', 'student_id'], ['course_registrations', 'student_id'], ['enrollment', 'user_id'], ['programme_cohort_memberships', 'student_id']] as [$table, $column]) {
            if (! Schema::hasTable($table)) $this->block('schema_incomplete', 'Academic history schema is incomplete; assignment is blocked.');
            if (DB::table($table)->where('school_id', $actor->school_id)->where($column, $student->id)->lockForUpdate()->first()) $this->block('academic_history_present', 'Existing placement, registration or membership history requires Registrar review through the established workflows.');
        }
        if (! Schema::hasTable('student_requests')) $this->block('schema_incomplete', 'Student request history must be available for transfer checks.');
        if (DB::table('student_requests')->where('school_id', $actor->school_id)->where('student_id', $student->id)->where('type', 'transfer')->lockForUpdate()->first()) $this->block('transfer_review', 'A transfer request exists. Resolve placement through the governed transfer workflow.');
        return ['mapping_id' => $mapping->id, 'mapping_approved_by' => $mapping->approved_by, 'mapping_approved_at' => $mapping->approved_at,
            'academic' => $academic, 'admission' => (array) $admission,
            'student' => $student->only(['id', 'school_id', 'role_id', 'account_status']),
            'profile' => array_intersect_key((array) $profile, array_flip(['id', 'user_id', 'school_id', 'programme_id', 'intake_session_id', 'status', 'year_of_study', 'was_existing_at_portal_creation']))];
    }

    private function mappingSnapshot(object $mapping): array
    {
        $school = DB::table('schools')->where('id', $mapping->school_id)->first();
        if (! $school || ! in_array(\App\Models\School::find($mapping->school_id)?->academicStructure(), ['programme_based', 'mixed'], true)) $this->block('institution_ineligible', 'Curriculum placement requires a higher-education or mixed institution.');
        $context = [];
        foreach (['programme' => ['programmes', 'programme_id'], 'intake' => ['intake_sessions', 'intake_session_id'], 'year' => ['academic_years', 'entry_academic_year_id'], 'stage' => ['curriculum_stages', 'entry_curriculum_stage_id']] as $key => [$table, $field]) {
            $row = DB::table($table)->where('school_id', $mapping->school_id)->where('id', $mapping->$field)->lockForUpdate()->first();
            if (! $row) $this->block('mapping_context_invalid', 'Every mapping reference must belong to this institution.');
            $context[$key] = (array) $row;
        }
        $curriculum = Curriculum::where('school_id', $mapping->school_id)->where('programme_id', $mapping->programme_id)->whereKey($mapping->curriculum_id)->lockForUpdate()->first();
        if (! $curriculum || $curriculum->status !== 'approved' || ! $context['programme']['is_active'] || in_array($context['year']['status'], ['cancelled', 'completed'], true) || ($context['stage']['archived_at'] ?? null) || (int) $context['stage']['curriculum_id'] !== (int) $mapping->curriculum_id) $this->block('mapping_context_inactive', 'Select an active programme, approved curriculum, available entry year and non-archived Programme Year from that curriculum.');
        $floor = DB::table('academic_years')->where('school_id', $mapping->school_id)->where('id', $curriculum->effective_academic_year_id)->lockForUpdate()->first();
        if (! $floor || $floor->start_date > $context['year']['start_date']) $this->block('curriculum_floor_invalid', 'Entry Academic Year cannot precede the approved curriculum effective year.');
        $context['curriculum'] = $curriculum->getAttributes();
        $context['effective_year'] = (array) $floor;
        foreach (['curriculum_stages', 'curriculum_memberships', 'curriculum_prerequisites'] as $table) {
            $context[$table] = DB::table($table)->where('school_id', $mapping->school_id)->where('curriculum_id', $mapping->curriculum_id)->orderBy($table === 'curriculum_prerequisites' ? 'membership_id' : 'id')->when($table === 'curriculum_prerequisites', fn ($q) => $q->orderBy('prerequisite_membership_id'))->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
        }
        $context['subjects'] = DB::table('subjects')->where('school_id', $mapping->school_id)->whereIn('id', array_column($context['curriculum_memberships'], 'subject_id'))->orderBy('id')->lockForUpdate()->get()->map(fn ($r) => (array) $r)->all();
        if ($this->curricula->approvalBlockers($curriculum)) $this->block('curriculum_incomplete', 'The curriculum no longer passes the existing approval readiness checks.');
        if (! collect($context['curriculum_memberships'])->contains('curriculum_stage_id', $mapping->entry_curriculum_stage_id)) $this->block('entry_stage_empty', 'The selected Programme Year has no curriculum units.');
        $context['calendar_pattern'] = $school->academic_calendar_pattern ?? null;
        return $context;
    }

    private function authorize(User $actor, string $action): void
    {
        $fresh = User::where('school_id', $actor->school_id)->find($actor->id);
        abort_unless($fresh && app(PermissionService::class)->allows($fresh, 'academic.curriculum_assignment.'.$action), 403);
    }

    private function transaction(User $actor, callable $callback, string $action = 'approve'): mixed
    {
        return DB::transaction(function () use ($actor, $callback, $action) {
            // Serializes mapping approvals, proposal retries and existing enrolment/preparation jobs.
            DB::table('schools')->where('id', $actor->school_id)->lockForUpdate()->firstOrFail();
            User::where('school_id', $actor->school_id)->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $action);
            return $callback();
        }, 3);
    }

    private function guarded(User $actor, string $workflow, int $subject, callable $callback, string $action = 'approve', bool $resolve = true): mixed
    {
        try {
            return $this->transaction($actor, function () use ($actor, $workflow, $subject, $callback, $resolve) {
                $result = $callback();
                if ($resolve) AutomationExceptions::resolve((int) $actor->school_id, $workflow, $subject);
                return $result;
            }, $action);
        } catch (CurriculumProposalBlocked $e) {
            AutomationExceptions::block((int) $actor->school_id, $workflow, $subject, $e->reasonCode, $e->getMessage(), 'Academic Registrar');
            throw $e;
        }
    }

    private function mapping(User $actor, int $id): object
    {
        return DB::table('curriculum_applicability_mappings')->where('school_id', $actor->school_id)->where('id', $id)->lockForUpdate()->firstOrFail();
    }

    private function proposal(User $actor, int $id, bool $lock = true): object
    {
        return DB::table('curriculum_assignment_proposals')->where('school_id', $actor->school_id)->where('id', $id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    private function admission(User $actor, int $id): object
    {
        return DB::table('admissions')->where('school_id', $actor->school_id)->where('id', $id)->lockForUpdate()->firstOrFail([
            'id', 'school_id', 'programme_id', 'intake_session_id', 'applicant_id', 'automation_student_id', 'status', 'study_mode',
        ]);
    }

    private function approvedMappings(object $context): \Illuminate\Database\Query\Builder
    {
        return DB::table('curriculum_applicability_mappings')->where('school_id', $context->school_id)->where('programme_id', $context->programme_id)->where('intake_session_id', $context->intake_session_id)->where('status', 'approved');
    }

    private function requireText(string $text, string $message): void
    {
        if (trim($text) === '' || mb_strlen($text) > 2000) $this->block('review_evidence_required', $message.' Use 1–2000 characters.');
    }

    private function block(string $code, string $message): never { throw new CurriculumProposalBlocked($code, $message); }
    private function json(array $data): string { return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
    private function fingerprint(array $data): string { return hash('sha256', $this->json($data)); }

    private function audit(User $actor, string $event, string $table, int $id, array $values): void
    {
        // Approval provenance must fail atomically, rather than relying on best-effort request logging.
        DB::table('audit_logs')->insert([
            'school_id' => $actor->school_id, 'user_id' => $actor->id, 'user_name' => $actor->name, 'role_id' => $actor->role_id,
            'action' => $event, 'event_type' => 'CURRICULUM_ASSIGNMENT', 'module' => 'Academic',
            'description' => $event, 'record_type' => $table, 'record_id' => $id,
            'new_values' => $this->json($values + ['actor_id' => $actor->id]), 'created_at' => now(),
        ]);
    }
}
