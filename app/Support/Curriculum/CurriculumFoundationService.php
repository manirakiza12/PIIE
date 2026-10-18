<?php

namespace App\Support\Curriculum;

use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use App\Models\CurriculumPrerequisite;
use App\Models\CurriculumStage;
use App\Models\AuditLog;
use App\Models\Programme;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CurriculumFoundationService
{
    public function ownerChangeStatus(Curriculum $curriculum, string $status, string $reason): Curriculum
    {
        abort_unless(CurriculumOwnerAccess::allows(auth()->user(), (int) $curriculum->school_id), 403);
        $this->validate(compact('status', 'reason'), [
            'status' => ['required', 'in:draft,approved'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        if (trim($reason) === '') $this->fail('reason', 'Explain the owner correction.');

        return DB::transaction(function () use ($curriculum, $status, $reason): Curriculum {
            $locked = Curriculum::where('school_id', $curriculum->school_id)->whereKey($curriculum->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->status;
            if (!in_array($previous, ['approved', 'retired'], true) || $previous === $status) {
                $this->fail('status', 'Choose an approved or retired curriculum for owner correction.');
            }
            if ($status === 'approved') {
                foreach ($this->approvalBlockers($locked) as $field => $message) $this->fail($field, $message);
            }
            $snapshot = $locked->toArray();
            $snapshot['years'] = $locked->stages()->get()->toArray();
            $snapshot['course_units'] = $locked->memberships()->with('prerequisites')->get()->toArray();
            DB::table('curricula')->where('school_id', $locked->school_id)->where('id', $locked->id)->update(['status' => $status, 'updated_at' => now()]);
            $this->audit('CURRICULUM_OWNER_STATUS_CHANGED', $locked, $snapshot, ['status' => $status, 'reason' => trim($reason)]);
            return $locked->refresh();
        });
    }

    public function deleteDraft(Curriculum $curriculum): void
    {
        DB::transaction(function () use ($curriculum): void {
            $locked = Curriculum::where('school_id', $curriculum->school_id)->whereKey($curriculum->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);
            foreach (['student_curriculum_assignments', 'programme_cohorts', 'course_offering_curriculum_memberships'] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table)
                    && DB::table($table)->where('curriculum_id', $locked->id)->exists()) {
                    $this->fail('curriculum', 'This curriculum is already referenced and cannot be deleted.');
                }
            }
            foreach ($locked->memberships()->get() as $membership) {
                $this->removeMembership($locked, $membership);
            }
            foreach ($locked->stages()->get() as $stage) {
                $this->removeStage($locked, $stage);
            }
            $this->audit('CURRICULUM_DRAFT_DELETED', $locked, $locked->only(['programme_id', 'version', 'status']), []);
            $locked->delete();
        });
    }

    public function createDraft(int $schoolId, int $programmeId, string $version, ?int $effectiveAcademicYearId = null): Curriculum
    {
        $this->validate([
            'version' => $version,
            'effective_academic_year_id' => $effectiveAcademicYearId,
        ], [
            'version' => ['required', 'string', 'max:50'],
            'effective_academic_year_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! Programme::where('school_id', $schoolId)->whereKey($programmeId)->exists()) {
            $this->fail('programme_id', 'The Programme must belong to this tenant.');
        }

        if ($effectiveAcademicYearId !== null && ! DB::table('academic_years')->where('school_id', $schoolId)->where('id', $effectiveAcademicYearId)->exists()) {
            $this->fail('effective_academic_year_id', 'The Academic Year must belong to this tenant.');
        }

        if (Curriculum::where('school_id', $schoolId)->where('programme_id', $programmeId)->where('version', $version)->exists()) {
            $this->fail('version', 'That version already exists for this Programme.');
        }

        $curriculum = new Curriculum();
        $curriculum->forceFill([
            'school_id' => $schoolId,
            'programme_id' => $programmeId,
            'version' => $version,
            'effective_academic_year_id' => $effectiveAcademicYearId,
            'status' => 'draft',
        ])->save();

        $this->audit('CURRICULUM_DRAFT_CREATED', $curriculum, [], $curriculum->only(['programme_id', 'version', 'effective_academic_year_id', 'status']));

        return $curriculum;
    }

    public function updateDraftMetadata(Curriculum $curriculum, string $version, ?int $effectiveAcademicYearId): Curriculum
    {
        $this->assertDraft($curriculum);
        $this->validate([
            'version' => $version,
            'effective_academic_year_id' => $effectiveAcademicYearId,
        ], [
            'version' => ['required', 'string', 'max:50'],
            'effective_academic_year_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($effectiveAcademicYearId !== null && ! DB::table('academic_years')->where('school_id', $curriculum->school_id)->where('id', $effectiveAcademicYearId)->exists()) {
            $this->fail('effective_academic_year_id', 'The Academic Year must belong to this tenant.');
        }

        if (Curriculum::where('school_id', $curriculum->school_id)
            ->where('programme_id', $curriculum->programme_id)
            ->where('version', $version)
            ->where('id', '<>', $curriculum->id)
            ->exists()) {
            $this->fail('version', 'That version already exists for this Programme.');
        }

        $before = $curriculum->only(['version', 'effective_academic_year_id']);
        DB::table('curricula')->where('school_id', $curriculum->school_id)->where('id', $curriculum->id)->update([
            'version' => $version,
            'effective_academic_year_id' => $effectiveAcademicYearId,
            'updated_at' => now(),
        ]);

        $curriculum->refresh();
        if ($before !== $curriculum->only(['version', 'effective_academic_year_id'])) {
            $this->audit('CURRICULUM_METADATA_UPDATED', $curriculum, $before, $curriculum->only(['version', 'effective_academic_year_id']));
        }

        return $curriculum;
    }

    public function addStage(Curriculum $curriculum, string $label, int $sequence): CurriculumStage
    {
        $this->assertDraft($curriculum);
        $this->validate(['label' => $label, 'sequence' => $sequence], [
            'label' => ['required', 'string', 'max:100'],
            'sequence' => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        if (CurriculumStage::where('curriculum_id', $curriculum->id)->where('sequence', $sequence)->exists()) {
            $this->fail('sequence', 'That year position is already used in this Curriculum.');
        }

        $stage = new CurriculumStage();
        $stage->forceFill([
            'school_id' => $curriculum->school_id,
            'curriculum_id' => $curriculum->id,
            'label' => $label,
            'sequence' => $sequence,
        ])->save();

        $this->audit('CURRICULUM_STAGE_CREATED', $curriculum, [], $stage->only(['id', 'label', 'sequence']));

        return $stage;
    }

    public function updateStage(Curriculum $curriculum, CurriculumStage $stage, string $label, int $sequence): CurriculumStage
    {
        $this->assertDraft($curriculum);
        $this->assertStageInCurriculum($curriculum, $stage);
        $this->validate(['label' => $label, 'sequence' => $sequence], [
            'label' => ['required', 'string', 'max:100'],
            'sequence' => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        if (CurriculumStage::where('curriculum_id', $curriculum->id)->where('sequence', $sequence)->where('id', '<>', $stage->id)->exists()) {
            $this->fail('sequence', 'That year position is already used in this Curriculum.');
        }

        $before = $stage->only(['label', 'sequence']);
        $stage->label = $label;
        $stage->sequence = $sequence;
        $stage->save();

        $this->audit('CURRICULUM_STAGE_UPDATED', $curriculum, ['id' => $stage->id] + $before, $stage->only(['id', 'label', 'sequence']));

        return $stage;
    }

    public function removeStage(Curriculum $curriculum, CurriculumStage $stage): void
    {
        $this->assertDraft($curriculum);
        $this->assertStageInCurriculum($curriculum, $stage);
        if ($stage->memberships()->exists()) {
            $this->fail('stage', 'Move or remove this Programme Year’s Course Units before removing the Programme Year.');
        }
        $before = $stage->only(['id', 'label', 'sequence']);
        $stage->delete();
        $this->audit('CURRICULUM_STAGE_REMOVED', $curriculum, $before, []);
    }

    public function moveStage(Curriculum $curriculum, CurriculumStage $stage, string $direction): void
    {
        $this->assertDraft($curriculum);
        $this->assertStageInCurriculum($curriculum, $stage);
        if (! in_array($direction, ['up', 'down'], true)) {
            $this->fail('direction', 'Choose up or down.');
        }

        DB::transaction(function () use ($curriculum, $stage, $direction): void {
            $stages = CurriculumStage::where('school_id', $curriculum->school_id)
                ->where('curriculum_id', $curriculum->id)->orderBy('sequence')->orderBy('id')->lockForUpdate()->get();
            $ordered = $stages->values();
            $index = $ordered->search(fn ($item) => (int) $item->id === (int) $stage->id);
            $otherIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || ! $ordered->has($otherIndex)) {
                return;
            }
            $current = $ordered[$index];
            $ordered[$index] = $ordered[$otherIndex];
            $ordered[$otherIndex] = $current;
            foreach ($ordered as $position => $item) {
                $nextSequence = $position + 1;
                if ((int) $item->sequence !== $nextSequence) {
                    DB::table('curriculum_stages')->where('id', $item->id)->where('school_id', $curriculum->school_id)->update(['sequence' => $nextSequence, 'updated_at' => now()]);
                    $this->audit('CURRICULUM_STAGE_REORDERED', $curriculum, ['id' => $item->id, 'sequence' => $item->sequence], ['id' => $item->id, 'sequence' => $nextSequence]);
                }
            }
        });
    }

    public function addMembership(Curriculum $curriculum, int $subjectId, int $stageId, array $attributes): CurriculumMembership
    {
        $this->assertDraft($curriculum);
        $subject = Subject::where('school_id', $curriculum->school_id)->whereKey($subjectId)->first();
        if (! $subject) {
            $this->fail('subject_id', 'The Subject must belong to this tenant.');
        }

        $stage = CurriculumStage::where('school_id', $curriculum->school_id)
            ->where('curriculum_id', $curriculum->id)
            ->whereKey($stageId)
            ->first();
        if (! $stage) {
            $this->fail('curriculum_stage_id', 'The Programme Year must belong to this Curriculum and tenant.');
        }

        $data = $this->validatedMembershipAttributes($curriculum, $attributes);
        if (CurriculumMembership::where('curriculum_id', $curriculum->id)->where('subject_id', $subject->id)->exists()) {
            $this->fail('subject_id', 'A Subject may appear only once in a Curriculum.');
        }
        $membership = new CurriculumMembership();
        $membership->forceFill($data + [
            'school_id' => $curriculum->school_id,
            'curriculum_id' => $curriculum->id,
            'subject_id' => $subject->id,
            'curriculum_stage_id' => $stage->id,
        ])->save();

        $this->audit('CURRICULUM_MEMBERSHIP_ADDED', $curriculum, [], $membership->only(['id', 'subject_id', 'curriculum_stage_id', 'period_type', 'period_sequence', 'classification', 'credits', 'sequence']));

        return $membership;
    }

    public function updateMembership(Curriculum $curriculum, CurriculumMembership $membership, array $attributes): CurriculumMembership
    {
        $this->assertDraft($curriculum);
        $this->assertMembershipInCurriculum($curriculum, $membership);

        $subjectId = (int) ($attributes['subject_id'] ?? $membership->subject_id);
        if (! Subject::where('school_id', $curriculum->school_id)->whereKey($subjectId)->exists()) {
            $this->fail('subject_id', 'The Subject must belong to this tenant.');
        }

        $stageId = (int) ($attributes['curriculum_stage_id'] ?? $membership->curriculum_stage_id);
        $this->assertStageIdInCurriculum($curriculum, $stageId);
        $data = $this->validatedMembershipAttributes($curriculum, array_merge($membership->only([
            'period_type', 'period_sequence', 'classification', 'credits', 'sequence',
        ]), $attributes));
        if (CurriculumMembership::where('curriculum_id', $curriculum->id)->where('subject_id', $subjectId)->where('id', '<>', $membership->id)->exists()) {
            $this->fail('subject_id', 'A Subject may appear only once in a Curriculum.');
        }

        $before = $membership->only(['subject_id', 'curriculum_stage_id', 'period_type', 'period_sequence', 'classification', 'credits', 'sequence']);
        $membership->forceFill($data + [
            'subject_id' => $subjectId,
            'curriculum_stage_id' => $stageId,
        ])->save();

        $after = $membership->only(['subject_id', 'curriculum_stage_id', 'period_type', 'period_sequence', 'classification', 'credits', 'sequence']);
        if ($before !== $after) {
            $this->audit('CURRICULUM_MEMBERSHIP_UPDATED', $curriculum, ['id' => $membership->id] + $before, ['id' => $membership->id] + $after);
        }

        return $membership;
    }

    public function removeMembership(Curriculum $curriculum, CurriculumMembership $membership): void
    {
        $this->assertDraft($curriculum);
        $this->assertMembershipInCurriculum($curriculum, $membership);
        $before = $membership->only(['id', 'subject_id', 'curriculum_stage_id', 'period_type', 'period_sequence', 'classification', 'credits', 'sequence']);
        DB::transaction(function () use ($curriculum, $membership): void {
            $edges = DB::table('curriculum_prerequisites')->where('school_id', $curriculum->school_id)
                ->where('curriculum_id', $curriculum->id)
                ->where(fn ($query) => $query->where('membership_id', $membership->id)->orWhere('prerequisite_membership_id', $membership->id))
                ->get();
            foreach ($edges as $edge) {
                $this->audit('CURRICULUM_PREREQUISITE_REMOVED', $curriculum, ['membership_id' => $edge->membership_id, 'prerequisite_membership_id' => $edge->prerequisite_membership_id], []);
            }
            DB::table('curriculum_prerequisites')->where('school_id', $curriculum->school_id)
                ->where('curriculum_id', $curriculum->id)
                ->where(fn ($query) => $query->where('membership_id', $membership->id)->orWhere('prerequisite_membership_id', $membership->id))
                ->delete();
            $membership->delete();
        });
        $this->audit('CURRICULUM_MEMBERSHIP_REMOVED', $curriculum, $before, []);
    }

    public function addPrerequisite(Curriculum $curriculum, int $membershipId, int $prerequisiteMembershipId): CurriculumPrerequisite
    {
        $this->assertDraft($curriculum);
        if ($membershipId === $prerequisiteMembershipId) {
            $this->fail('prerequisite_membership_id', 'A membership cannot be its own prerequisite.');
        }

        $membership = $this->membershipInCurriculum($curriculum, $membershipId, 'membership_id');
        $prerequisite = $this->membershipInCurriculum($curriculum, $prerequisiteMembershipId, 'prerequisite_membership_id');

        if (DB::table('curriculum_prerequisites')->where('school_id', $curriculum->school_id)->where('curriculum_id', $curriculum->id)->where('membership_id', $membership->id)->where('prerequisite_membership_id', $prerequisite->id)->exists()) {
            $this->fail('prerequisite_membership_id', 'That prerequisite relationship already exists.');
        }

        if ($this->pathExists($curriculum, (int) $prerequisite->id, (int) $membership->id)) {
            $this->fail('prerequisite_membership_id', 'Prerequisite relationships cannot create a cycle.');
        }

        $edge = new CurriculumPrerequisite();
        $edge->setRawAttributes([
            'school_id' => $curriculum->school_id,
            'curriculum_id' => $curriculum->id,
            'membership_id' => $membership->id,
            'prerequisite_membership_id' => $prerequisite->id,
        ]);
        $edge->save();

        $this->audit('CURRICULUM_PREREQUISITE_ADDED', $curriculum, [], ['membership_id' => $membership->id, 'prerequisite_membership_id' => $prerequisite->id]);

        return $edge;
    }

    public function removePrerequisite(Curriculum $curriculum, int $membershipId, int $prerequisiteMembershipId): void
    {
        $this->assertDraft($curriculum);
        $this->membershipInCurriculum($curriculum, $membershipId, 'membership_id');
        $this->membershipInCurriculum($curriculum, $prerequisiteMembershipId, 'prerequisite_membership_id');
        $deleted = DB::table('curriculum_prerequisites')->where('school_id', $curriculum->school_id)
            ->where('curriculum_id', $curriculum->id)->where('membership_id', $membershipId)
            ->where('prerequisite_membership_id', $prerequisiteMembershipId)->delete();
        if ($deleted) {
            $this->audit('CURRICULUM_PREREQUISITE_REMOVED', $curriculum, ['membership_id' => $membershipId, 'prerequisite_membership_id' => $prerequisiteMembershipId], []);
        }
    }

    public function createSuccessor(Curriculum $source, string $version, ?int $effectiveAcademicYearId, bool $clone): Curriculum
    {
        if (! in_array($source->status, ['approved', 'retired'], true)) {
            $this->fail('curriculum', 'A successor can only be created from an approved or retired Curriculum.');
        }

        return DB::transaction(function () use ($source, $version, $effectiveAcademicYearId, $clone): Curriculum {
            $successor = $this->createDraft((int) $source->school_id, (int) $source->programme_id, $version, $effectiveAcademicYearId);
            if ($clone) {
                $membershipMap = [];
                foreach ($source->stages()->with('memberships.subject')->orderBy('sequence')->get() as $stage) {
                    $newStage = $this->addStage($successor, $stage->label, (int) $stage->sequence);
                    foreach ($stage->memberships as $membership) {
                        $newMembership = $this->addMembership($successor, (int) $membership->subject_id, (int) $newStage->id, [
                            'period_type' => $membership->period_type,
                            'period_sequence' => $membership->period_sequence,
                            'classification' => $membership->classification,
                            'credits' => $membership->credits,
                            'sequence' => $membership->sequence,
                        ]);
                        $membershipMap[$membership->id] = $newMembership->id;
                    }
                }
                foreach (DB::table('curriculum_prerequisites')->where('school_id', $source->school_id)->where('curriculum_id', $source->id)->get() as $edge) {
                    if (isset($membershipMap[$edge->membership_id], $membershipMap[$edge->prerequisite_membership_id])) {
                        $this->addPrerequisite($successor, (int) $membershipMap[$edge->membership_id], (int) $membershipMap[$edge->prerequisite_membership_id]);
                    }
                }
            }
            $this->audit('CURRICULUM_SUCCESSOR_DRAFT_CREATED', $successor, ['source_curriculum_id' => $source->id, 'clone' => $clone], ['curriculum_id' => $successor->id, 'version' => $successor->version, 'status' => $successor->status]);
            return $successor;
        });
    }

    public function approve(Curriculum $curriculum): Curriculum
    {
        return DB::transaction(function () use ($curriculum): Curriculum {
            $locked = Curriculum::whereKey($curriculum->id)->where('school_id', $curriculum->school_id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);

            foreach ($this->approvalBlockers($locked) as $field => $message) {
                $this->fail($field, $message);
            }

            DB::table('curricula')->where('id', $locked->id)->where('school_id', $locked->school_id)->update([
                'status' => 'approved',
                'updated_at' => now(),
            ]);

            $this->audit('CURRICULUM_APPROVED', $locked, ['status' => 'draft'], ['status' => 'approved', 'effective_academic_year_id' => $locked->effective_academic_year_id]);

            return $locked->refresh();
        });
    }

    /** Approval readiness shared by the review UI and the authoritative transition. */
    public function approvalBlockers(Curriculum $curriculum): array
    {
        $blockers = [];
        $schoolId = (int) $curriculum->school_id;
        $curriculumId = (int) $curriculum->id;

        if (! DB::table('schools')->where('id', $schoolId)->exists()) {
            $blockers['school_id'] = 'The Curriculum tenant does not exist.';
        }
        if (! Programme::where('school_id', $schoolId)->whereKey($curriculum->programme_id)->exists()) {
            $blockers['programme_id'] = 'The Programme must belong to this tenant.';
        }
        if (Curriculum::where('school_id', $schoolId)->where('programme_id', $curriculum->programme_id)
            ->where('version', $curriculum->version)->where('id', '<>', $curriculumId)->exists()) {
            $blockers['version'] = 'The Curriculum version is not unique for this Programme.';
        }
        if (! $curriculum->effective_academic_year_id || ! DB::table('academic_years')->where('school_id', $schoolId)
            ->where('id', $curriculum->effective_academic_year_id)->exists()) {
            $blockers['effective_academic_year_id'] = 'An effective Academic Year belonging to this tenant is required for approval.';
        }

        $memberships = CurriculumMembership::where('school_id', $schoolId)->where('curriculum_id', $curriculumId)->get();
        $stageIds = CurriculumStage::where('school_id', $schoolId)->where('curriculum_id', $curriculumId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($memberships->isEmpty()) {
            $blockers['memberships'] = 'An empty Curriculum cannot be approved.';
        }

        $pattern = DB::table('schools')->where('id', $schoolId)->value('academic_calendar_pattern');
        foreach ($memberships as $membership) {
            if (! in_array((int) $membership->curriculum_stage_id, $stageIds, true)) {
                $blockers['stage_' . $membership->id] = 'Every Course Unit must be assigned to a Programme Year in this Curriculum.';
            }
            if (! Subject::where('school_id', $schoolId)->whereKey($membership->subject_id)->exists()) {
                $blockers['subject_' . $membership->id] = 'Every Course Unit Subject must belong to this tenant.';
            }
            if (! in_array($membership->classification, CurriculumMembership::CLASSIFICATIONS, true)) {
                $blockers['classification_' . $membership->id] = 'A Course Unit has an invalid classification.';
            }
            if (! is_numeric($membership->credits) || (float) $membership->credits < 0) {
                $blockers['credits_' . $membership->id] = 'A Course Unit has invalid negative or nonnumeric Curriculum credits.';
            }
            if (($membership->period_type === null) !== ($membership->period_sequence === null)
                || ($membership->period_sequence !== null && (int) $membership->period_sequence < 1)
                || ($membership->period_type !== null && ! in_array($membership->period_type, CurriculumMembership::PERIOD_TYPES, true))
                || ($membership->period_type !== null && $pattern && $membership->period_type !== $pattern)) {
                $blockers['period_' . $membership->id] = 'A relative period is incomplete, invalid, or inconsistent with the tenant calendar pattern.';
            }
        }

        if (CurriculumMembership::where('school_id', $schoolId)->where('curriculum_id', $curriculumId)
            ->select('subject_id')->groupBy('subject_id')->havingRaw('COUNT(*) > 1')->exists()) {
            $blockers['duplicate_subjects'] = 'A Subject appears more than once in this Curriculum.';
        }

        $membershipIds = $memberships->pluck('id')->map(fn ($id) => (int) $id)->all();
        $edges = DB::table('curriculum_prerequisites')->where('school_id', $schoolId)->where('curriculum_id', $curriculumId)->get();
        $graph = [];
        foreach ($edges as $edge) {
            $from = (int) $edge->membership_id;
            $to = (int) $edge->prerequisite_membership_id;
            if ($from === $to) {
                $blockers['prerequisite_self'] = 'A Course Unit cannot be its own prerequisite.';
            }
            if (! in_array($from, $membershipIds, true) || ! in_array($to, $membershipIds, true)) {
                $blockers['prerequisite_membership'] = 'Prerequisites must connect memberships inside this Curriculum and tenant.';
            }
            $graph[$from][] = $to;
        }

        $states = [];
        $hasCycle = function (int $node) use (&$hasCycle, &$states, $graph): bool {
            if (($states[$node] ?? 0) === 1) return true;
            if (($states[$node] ?? 0) === 2) return false;
            $states[$node] = 1;
            foreach ($graph[$node] ?? [] as $next) {
                if ($hasCycle((int) $next)) return true;
            }
            $states[$node] = 2;
            return false;
        };
        foreach (array_keys($graph) as $node) {
            if ($hasCycle((int) $node)) {
                $blockers['prerequisite_cycle'] = 'Prerequisite relationships cannot contain a cycle.';
                break;
            }
        }

        return $blockers;
    }

    public function retire(Curriculum $curriculum): Curriculum
    {
        return DB::transaction(function () use ($curriculum): Curriculum {
            $locked = Curriculum::whereKey($curriculum->id)->where('school_id', $curriculum->school_id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'approved') {
                $this->fail('status', 'Only an approved Curriculum may be retired.');
            }

            DB::table('curricula')->where('id', $locked->id)->where('school_id', $locked->school_id)->update([
                'status' => 'retired',
                'updated_at' => now(),
            ]);

            $this->audit('CURRICULUM_RETIRED', $locked, ['status' => 'approved'], ['status' => 'retired']);

            return $locked->refresh();
        });
    }

    private function validatedMembershipAttributes(Curriculum $curriculum, array $attributes): array
    {
        $data = array_merge([
            'period_type' => null,
            'period_sequence' => null,
            'sequence' => 0,
        ], $attributes);

        $this->validate($data, [
            'period_type' => ['nullable', 'string', 'in:semester,term'],
            'period_sequence' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'classification' => ['required', 'string', 'in:compulsory,elective'],
            'credits' => ['required', 'numeric', 'min:0', 'max:9999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'sequence' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        if (($data['period_type'] === null) !== ($data['period_sequence'] === null)) {
            $this->fail('period_sequence', 'Relative period type and sequence must either both be set or both be empty.');
        }

        if ($data['period_type'] !== null) {
            $pattern = DB::table('schools')->where('id', $curriculum->school_id)->value('academic_calendar_pattern');
            if ($pattern && $pattern !== $data['period_type']) {
                $this->fail('period_type', 'Relative period type must match this tenant’s academic calendar pattern.');
            }
        }

        return [
            'period_type' => $data['period_type'],
            'period_sequence' => $data['period_sequence'],
            'classification' => $data['classification'],
            'credits' => $data['credits'],
            'sequence' => $data['sequence'],
        ];
    }

    private function membershipInCurriculum(Curriculum $curriculum, int $id, string $field): CurriculumMembership
    {
        $membership = CurriculumMembership::where('school_id', $curriculum->school_id)
            ->where('curriculum_id', $curriculum->id)
            ->whereKey($id)
            ->first();

        if (! $membership) {
            $this->fail($field, 'Both prerequisite memberships must belong to this Curriculum and tenant.');
        }

        return $membership;
    }

    private function pathExists(Curriculum $curriculum, int $from, int $target): bool
    {
        $pending = [$from];
        $visited = [];

        while ($pending) {
            $current = array_pop($pending);
            if ($current === $target) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            $next = DB::table('curriculum_prerequisites')
                ->where('school_id', $curriculum->school_id)
                ->where('curriculum_id', $curriculum->id)
                ->where('membership_id', $current)
                ->pluck('prerequisite_membership_id');
            foreach ($next as $id) {
                $pending[] = (int) $id;
            }
        }

        return false;
    }

    private function assertDraft(Curriculum $curriculum): void
    {
        $status = Curriculum::where('school_id', $curriculum->school_id)->whereKey($curriculum->id)->value('status');
        if ($status !== 'draft') {
            $this->fail('curriculum', 'Only draft Curricula may be structurally changed.');
        }
    }

    private function assertStageInCurriculum(Curriculum $curriculum, CurriculumStage $stage): void
    {
        $this->assertStageIdInCurriculum($curriculum, (int) $stage->id);
    }

    private function assertStageIdInCurriculum(Curriculum $curriculum, int $stageId): void
    {
        if (! CurriculumStage::where('school_id', $curriculum->school_id)->where('curriculum_id', $curriculum->id)->whereKey($stageId)->exists()) {
            $this->fail('curriculum_stage_id', 'The Programme Year must belong to this Curriculum and tenant.');
        }
    }

    private function assertMembershipInCurriculum(Curriculum $curriculum, CurriculumMembership $membership): void
    {
        if ((int) $membership->school_id !== (int) $curriculum->school_id || (int) $membership->curriculum_id !== (int) $curriculum->id) {
            $this->fail('membership', 'The membership must belong to this Curriculum and tenant.');
        }
    }

    private function validate(array $data, array $rules): void
    {
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function audit(string $action, Curriculum $curriculum, array $before, array $after): void
    {
        AuditLog::record($action, 'Curriculum', "{$action} for Curriculum #{$curriculum->id} (Programme #{$curriculum->programme_id}, version {$curriculum->version}).", [
            'event_type' => 'DATA',
            'school_id' => $curriculum->school_id,
            'record_type' => Curriculum::class,
            'record_id' => $curriculum->id,
            'old_values' => $before ?: null,
            'new_values' => $after ?: null,
        ]);
    }
}
