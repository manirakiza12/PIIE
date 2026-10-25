<?php

namespace App\Support\ProgrammeCohorts;

use App\Models\AcademicYear;
use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\CurriculumStage;
use App\Models\IntakeSession;
use App\Models\Programme;
use App\Models\ProgrammeCohort;
use App\Models\ProgrammeCohortMembership;
use App\Models\School;
use App\Models\StudentCurriculumAssignment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Curriculum\StudentCurriculumAssignmentService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProgrammeCohortService
{
    public function createDraft(User $actor, array $input): ProgrammeCohort
    {
        $this->requirePermission($actor, 'academic.programme_cohort.manage');
        $schoolId = $this->actorSchool($actor);
        $data = $this->validateCohort($schoolId, $input);
        $this->assertReferences($schoolId, $data);
        $data['code'] = trim((string) ($data['code'] ?? '')) ?: $this->suggestCode($schoolId, $data['programme_id']);

        if (ProgrammeCohort::where('school_id', $schoolId)->where('code', $data['code'])->exists()) {
            $this->fail('code', 'That Cohort Code is already in use for this institution.');
        }

        return DB::transaction(function () use ($actor, $schoolId, $data): ProgrammeCohort {
            $cohort = ProgrammeCohort::createThroughDomainService($data + [
                'school_id' => $schoolId,
                'created_by' => $actor->id,
                'status' => 'draft',
            ]);
            $this->audit('PROGRAMME_COHORT_CREATED', $cohort, [], $this->cohortValues($cohort));

            return $cohort;
        });
    }

    public function updateDraft(User $actor, int $cohortId, array $input): ProgrammeCohort
    {
        $this->requirePermission($actor, 'academic.programme_cohort.manage');
        $schoolId = $this->actorSchool($actor);
        $data = $this->validateCohort($schoolId, $input);

        return DB::transaction(function () use ($actor, $schoolId, $cohortId, $data): ProgrammeCohort {
            $cohort = ProgrammeCohort::where('school_id', $schoolId)->whereKey($cohortId)->lockForUpdate()->firstOrFail();
            if ($cohort->status !== 'draft') {
                throw new DomainException('Only draft Programme Cohorts can be edited.');
            }
            $data['code'] = trim((string) ($data['code'] ?? '')) ?: $cohort->code;
            $this->assertReferences($schoolId, $data);
            if (ProgrammeCohort::where('school_id', $schoolId)->where('code', $data['code'])->whereKeyNot($cohort->id)->exists()) {
                $this->fail('code', 'That Cohort Code is already in use for this institution.');
            }
            $before = $this->cohortValues($cohort);
            $cohort->updateThroughDomainService($data);
            $this->audit('PROGRAMME_COHORT_UPDATED', $cohort, $before, $this->cohortValues($cohort));

            return $cohort;
        });
    }

    public function transition(User $actor, int $cohortId, string $to): ProgrammeCohort
    {
        $this->requirePermission($actor, 'academic.programme_cohort.lifecycle');
        $schoolId = $this->actorSchool($actor);

        return DB::transaction(function () use ($actor, $schoolId, $cohortId, $to): ProgrammeCohort {
            $cohort = ProgrammeCohort::where('school_id', $schoolId)->whereKey($cohortId)->lockForUpdate()->firstOrFail();
            $from = $cohort->status;
            $allowed = [
                'draft' => ['active', 'cancelled'],
                'active' => ['completed', 'cancelled'],
                'completed' => [],
                'cancelled' => [],
            ];
            if (! in_array($to, $allowed[$from] ?? [], true)) {
                throw new DomainException("Programme Cohort cannot transition from {$from} to {$to}.");
            }
            if ($to === 'active') {
                $this->assertActivationReady($cohort);
            }
            if ($to === 'cancelled' && $cohort->currentMemberships()->exists()) {
                throw new DomainException('A Programme Cohort with current members cannot be cancelled. End or transfer its memberships first.');
            }
            if ($to === 'completed' && $cohort->currentMemberships()->exists()) {
                throw new DomainException('Complete or transfer all current memberships before completing the Programme Cohort.');
            }

            $cohort->updateThroughDomainService(['status' => $to]);
            $event = [
                'active' => 'PROGRAMME_COHORT_ACTIVATED',
                'completed' => 'PROGRAMME_COHORT_COMPLETED',
                'cancelled' => 'PROGRAMME_COHORT_CANCELLED',
            ][$to];
            $this->audit($event, $cohort, ['status' => $from], ['status' => $to]);

            return $cohort;
        });
    }

    public function assign(User $actor, int $cohortId, int $studentId, ?int $admissionId = null): ProgrammeCohortMembership
    {
        $this->requirePermission($actor, 'academic.programme_cohort.membership');
        $schoolId = $this->actorSchool($actor);

        return DB::transaction(function () use ($actor, $schoolId, $cohortId, $studentId, $admissionId): ProgrammeCohortMembership {
            $cohort = ProgrammeCohort::where('school_id', $schoolId)->whereKey($cohortId)->lockForUpdate()->firstOrFail();
            if ($cohort->status !== 'active') {
                throw new DomainException('Students can only be assigned to an active Programme Cohort.');
            }
            $student = User::where('school_id', $schoolId)->where('role_id', 7)->whereKey($studentId)->lockForUpdate()->first();
            if (! $student) {
                $this->fail('student_id', 'Select a student from this institution.');
            }
            $admission = $admissionId === null ? null : Admission::where('school_id', $schoolId)->whereKey($admissionId)->first();
            if ($admissionId !== null && (! $admission
                || (int) $admission->programme_id !== (int) $cohort->programme_id
                || (int) $admission->intake_session_id !== (int) $cohort->intake_session_id)) {
                $this->fail('admission_id', 'The Admission must match this institution, Programme and Admissions Intake.');
            }
            if (ProgrammeCohortMembership::where('school_id', $schoolId)->where('student_id', $studentId)->whereNull('ended_at')->exists()) {
                $this->fail('student_id', 'This student already has a current Programme Cohort membership.');
            }

            $membership = ProgrammeCohortMembership::createThroughDomainService([
                'school_id' => $schoolId,
                'student_id' => $studentId,
                'programme_cohort_id' => $cohort->id,
                'admission_id' => $admission?->id,
                'admission_reference' => $admission?->app_number,
                'status' => 'active',
                'started_at' => now(),
                'assigned_by' => $actor->id,
            ]);
            $this->auditMembership('PROGRAMME_COHORT_MEMBER_ASSIGNED', $membership, [], $this->membershipValues($membership));

            return $membership;
        });
    }

    public function defer(User $actor, int $membershipId, ?string $reason = null): ProgrammeCohortMembership
    {
        $this->requirePermission($actor, 'academic.programme_cohort.membership');
        return $this->changeCurrentMembership($actor, $membershipId, 'deferred', 'PROGRAMME_COHORT_MEMBER_DEFERRED', $reason);
    }

    public function resume(User $actor, int $membershipId): ProgrammeCohortMembership
    {
        $this->requirePermission($actor, 'academic.programme_cohort.membership');
        $schoolId = $this->actorSchool($actor);

        return DB::transaction(function () use ($actor, $schoolId, $membershipId): ProgrammeCohortMembership {
            $membership = $this->lockMembership($schoolId, $membershipId);
            if ($membership->status !== 'deferred' || $membership->ended_at !== null) {
                throw new DomainException('Only a current deferred membership can be resumed.');
            }
            $before = $this->membershipValues($membership);
            $membership->updateThroughDomainService(['status' => 'active']);
            $this->auditMembership('PROGRAMME_COHORT_MEMBER_RESUMED', $membership, $before, $this->membershipValues($membership));

            return $membership;
        });
    }

    public function withdraw(User $actor, int $membershipId, ?string $reason = null): ProgrammeCohortMembership
    {
        $this->requirePermission($actor, 'academic.programme_cohort.membership');
        return $this->endCurrentMembership($actor, $membershipId, 'withdrawn', 'PROGRAMME_COHORT_MEMBER_WITHDRAWN', $reason);
    }

    public function completeMembership(User $actor, int $membershipId, ?string $reason = null): ProgrammeCohortMembership
    {
        $this->requirePermission($actor, 'academic.programme_cohort.membership');
        return $this->endCurrentMembership($actor, $membershipId, 'completed', 'PROGRAMME_COHORT_MEMBER_COMPLETED', $reason);
    }

    public function transfer(User $actor, int $membershipId, int $destinationCohortId, ?string $reason = null): ProgrammeCohortMembership
    {
        $this->requirePermission($actor, 'academic.programme_cohort.membership');
        $schoolId = $this->actorSchool($actor);

        return DB::transaction(function () use ($actor, $schoolId, $membershipId, $destinationCohortId, $reason): ProgrammeCohortMembership {
            $old = $this->lockMembership($schoolId, $membershipId);
            if (! in_array($old->status, ProgrammeCohortMembership::CURRENT_STATUSES, true) || $old->ended_at !== null) {
                throw new DomainException('Only a current membership can be transferred.');
            }
            $destination = ProgrammeCohort::where('school_id', $schoolId)->whereKey($destinationCohortId)->lockForUpdate()->firstOrFail();
            if ($destination->status !== 'active' || (int) $destination->id === (int) $old->programme_cohort_id) {
                throw new DomainException('Select a different active Programme Cohort for the transfer.');
            }
            $before = $this->membershipValues($old);
            $old->updateThroughDomainService([
                'status' => 'transferred',
                'ended_at' => now(),
                'reason' => $reason ? trim($reason) : null,
            ]);
            $this->endActiveStudyPlanAssignment($old, 'Study Plan assignment ended due to Programme Cohort transfer.');
            $this->auditMembership('PROGRAMME_COHORT_MEMBER_TRANSFERRED', $old, $before, $this->membershipValues($old));

            $new = ProgrammeCohortMembership::createThroughDomainService([
                'school_id' => $schoolId,
                'student_id' => $old->student_id,
                'programme_cohort_id' => $destination->id,
                'admission_id' => $old->admission_id,
                'admission_reference' => $old->admission_reference,
                'status' => 'active',
                'started_at' => now(),
                'assigned_by' => $actor->id,
                'reason' => $reason ? trim($reason) : null,
            ]);
            $this->auditMembership('PROGRAMME_COHORT_MEMBER_ASSIGNED', $new, [], $this->membershipValues($new));

            return $new;
        });
    }

    public function placeStudent(User $actor, int $cohortId, int $membershipId, int $stageId, int $yearOfStudy): StudentCurriculumAssignment
    {
        $this->requirePermission($actor, 'academic.programme_cohort.membership');
        $schoolId = $this->actorSchool($actor);
        if ($yearOfStudy < 1 || $yearOfStudy > 255) {
            $this->fail('year_of_study', 'Choose a valid Year of Study explicitly.');
        }

        return DB::transaction(function () use ($actor, $schoolId, $cohortId, $membershipId, $stageId, $yearOfStudy): StudentCurriculumAssignment {
            $membership = $this->lockMembership($schoolId, $membershipId);
            if ((int) $membership->programme_cohort_id !== $cohortId) {
                $this->fail('membership_id', 'The student membership does not belong to the selected Programme Cohort.');
            }
            if ($membership->status !== 'active' || $membership->ended_at !== null) {
                throw new DomainException('Academic placement requires an active membership. Resume a deferred membership first.');
            }
            $cohort = ProgrammeCohort::where('school_id', $schoolId)->whereKey($membership->programme_cohort_id)->lockForUpdate()->firstOrFail();
            if ($cohort->status !== 'active') {
                throw new DomainException('Academic placement requires an active Programme Cohort.');
            }
            $stage = CurriculumStage::where('school_id', $schoolId)
                ->when(\Illuminate\Support\Facades\Schema::hasColumn('curriculum_stages', 'archived_at'), fn ($q) => $q->whereNull('archived_at'))
                ->where('curriculum_id', $cohort->curriculum_id)->whereKey($stageId)->first();
            if (! $stage) {
                $this->fail('entry_curriculum_stage_id', 'Select an Entry Stage from this Programme Study Plan.');
            }
            $plan = Curriculum::where('school_id', $schoolId)->where('programme_id', $cohort->programme_id)
                ->whereKey($cohort->curriculum_id)->where('status', 'approved')->first();
            if (! $plan) {
                throw new DomainException('The cohort Programme Study Plan must be approved before academic placement.');
            }
            $profile = StudentProfile::where('school_id', $schoolId)->where('user_id', $membership->student_id)->lockForUpdate()->first();
            if (! $profile) {
                $this->fail('student_id', 'The student must have an institution profile before academic placement.');
            }
            if (StudentCurriculumAssignment::where('school_id', $schoolId)->where('student_id', $membership->student_id)->whereNull('ended_at')->exists()) {
                throw new DomainException('The student already has a current Programme Study Plan assignment.');
            }

            $assignment = StudentCurriculumAssignment::createThroughDomainService([
                'school_id' => $schoolId,
                'student_id' => $membership->student_id,
                'programme_id' => $cohort->programme_id,
                'curriculum_id' => $cohort->curriculum_id,
                'entry_academic_year_id' => $cohort->entry_academic_year_id,
                'effective_from_academic_year_id' => $cohort->entry_academic_year_id,
                'programme_cohort_membership_id' => $membership->id,
                'entry_curriculum_stage_id' => $stage->id,
                'assigned_by' => $actor->id,
                'reason' => 'Initial academic placement from Programme Cohort.',
            ]);
            // Keep established profile fields intact as the legacy summary of the
            // authoritative assignment. Year of Study is submitted explicitly.
            $profile->forceFill([
                'programme_id' => $cohort->programme_id,
                'intake_session_id' => $cohort->intake_session_id,
                'year_of_study' => $yearOfStudy,
            ])->save();

            $this->auditMembership('STUDENT_ACADEMIC_PLACEMENT_CREATED', $membership, [], [
                'assignment_id' => $assignment->id,
                'programme_id' => $cohort->programme_id,
                'curriculum_id' => $cohort->curriculum_id,
                'entry_stage_id' => $stage->id,
                'entry_academic_year_id' => $cohort->entry_academic_year_id,
                'year_of_study' => $yearOfStudy,
            ]);

            return $assignment;
        });
    }

    public function assignFromAdmission(User $actor, int $cohortId, int $studentId, int $admissionId): ProgrammeCohortMembership
    {
        $schoolId = $this->actorSchool($actor);
        $cohort = ProgrammeCohort::where('school_id', $schoolId)->whereKey($cohortId)->firstOrFail();
        $admission = Admission::where('school_id', $schoolId)->whereKey($admissionId)->first();
        if (! $admission || (int) $admission->programme_id !== (int) $cohort->programme_id
            || (int) $admission->intake_session_id !== (int) $cohort->intake_session_id) {
            $this->fail('admission_id', 'Select an Admission for this Programme and Admissions Intake.');
        }

        return $this->assign($actor, $cohortId, $studentId, $admissionId);
    }

    public function suggestedCode(int $schoolId, int $programmeId): string
    {
        return $this->suggestCode($schoolId, $programmeId);
    }

    private function changeCurrentMembership(User $actor, int $id, string $status, string $event, ?string $reason): ProgrammeCohortMembership
    {
        $schoolId = $this->actorSchool($actor);

        return DB::transaction(function () use ($actor, $schoolId, $id, $status, $event, $reason): ProgrammeCohortMembership {
            $membership = $this->lockMembership($schoolId, $id);
            if ($membership->status !== 'active' || $membership->ended_at !== null) {
                throw new DomainException('Only an active current membership can be deferred.');
            }
            $before = $this->membershipValues($membership);
            $membership->updateThroughDomainService([
                'status' => $status,
                'reason' => $reason ? trim($reason) : null,
            ]);
            $this->auditMembership($event, $membership, $before, $this->membershipValues($membership));

            return $membership;
        });
    }

    private function endCurrentMembership(User $actor, int $id, string $status, string $event, ?string $reason): ProgrammeCohortMembership
    {
        $schoolId = $this->actorSchool($actor);

        return DB::transaction(function () use ($actor, $schoolId, $id, $status, $event, $reason): ProgrammeCohortMembership {
            $membership = $this->lockMembership($schoolId, $id);
            if (! in_array($membership->status, ProgrammeCohortMembership::CURRENT_STATUSES, true) || $membership->ended_at !== null) {
                throw new DomainException('Only a current membership can be ended.');
            }
            $before = $this->membershipValues($membership);
            $membership->updateThroughDomainService([
                'status' => $status,
                'ended_at' => now(),
                'reason' => $reason ? trim($reason) : null,
            ]);
            $this->endActiveStudyPlanAssignment($membership, 'Study Plan assignment ended when Programme Cohort membership ended.');
            $this->auditMembership($event, $membership, $before, $this->membershipValues($membership));

            return $membership;
        });
    }

    private function lockMembership(int $schoolId, int $id): ProgrammeCohortMembership
    {
        $membership = ProgrammeCohortMembership::where('school_id', $schoolId)->whereKey($id)->lockForUpdate()->firstOrFail();
        User::where('school_id', $schoolId)->whereKey($membership->student_id)->lockForUpdate()->firstOrFail();

        return $membership;
    }

    private function endActiveStudyPlanAssignment(ProgrammeCohortMembership $membership, string $reason): void
    {
        $assignment = StudentCurriculumAssignment::where('school_id', $membership->school_id)
            ->where('student_id', $membership->student_id)
            ->where('programme_cohort_membership_id', $membership->id)
            ->whereNull('ended_at')->lockForUpdate()->first();
        if (! $assignment) {
            return;
        }

        $before = ['ended_at' => null];
        $assignment->endThroughDomainService(now());
        AuditLog::record('STUDENT_CURRICULUM_ASSIGNMENT_ENDED_WITH_COHORT_MEMBERSHIP', 'Academic',
            "Study Plan assignment #{$assignment->id} ended with Programme Cohort membership #{$membership->id}.", [
                'school_id' => $membership->school_id,
                'event_type' => 'ACADEMIC',
                'record_type' => 'StudentCurriculumAssignment',
                'record_id' => $assignment->id,
                'old_values' => $before,
                'new_values' => ['ended_at' => $assignment->ended_at?->toISOString(), 'reason' => $reason],
            ]);
    }

    private function assertActivationReady(ProgrammeCohort $cohort): void
    {
        if (! Programme::where('school_id', $cohort->school_id)->whereKey($cohort->programme_id)->exists()
            || ! IntakeSession::where('school_id', $cohort->school_id)->whereKey($cohort->intake_session_id)->exists()
            || ! AcademicYear::where('school_id', $cohort->school_id)->whereKey($cohort->entry_academic_year_id)->exists()) {
            throw new DomainException('The Programme Cohort references must all belong to this institution.');
        }
        $plan = Curriculum::where('school_id', $cohort->school_id)->where('programme_id', $cohort->programme_id)
            ->whereKey($cohort->curriculum_id)->first();
        if (! $plan || $plan->status !== 'approved') {
            throw new DomainException('Select an approved Programme Study Plan for the cohort before activation.');
        }
        if (trim($cohort->name) === '' || trim($cohort->code) === '') {
            throw new DomainException('Cohort Name and Cohort Code are required before activation.');
        }
    }

    private function validateCohort(int $schoolId, array $input): array
    {
        return Validator::make($input, [
            'programme_id' => ['required', 'integer', 'min:1', Rule::exists('programmes', 'id')->where('school_id', $schoolId)],
            'intake_session_id' => ['required', 'integer', 'min:1', Rule::exists('intake_sessions', 'id')->where('school_id', $schoolId)],
            'entry_academic_year_id' => ['required', 'integer', 'min:1', Rule::exists('academic_years', 'id')->where('school_id', $schoolId)],
            'curriculum_id' => ['required', 'integer', 'min:1', Rule::exists('curricula', 'id')->where('school_id', $schoolId)],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'expected_completion_date' => ['nullable', 'date'],
        ])->validate();
    }

    private function assertReferences(int $schoolId, array $data): void
    {
        if (! Programme::where('school_id', $schoolId)->whereKey($data['programme_id'])->exists()) {
            $this->fail('programme_id', 'Select a Programme from this institution.');
        }
        if (! IntakeSession::where('school_id', $schoolId)->whereKey($data['intake_session_id'])->exists()) {
            $this->fail('intake_session_id', 'Select an Admissions Intake from this institution.');
        }
        if (! AcademicYear::where('school_id', $schoolId)->whereKey($data['entry_academic_year_id'])->exists()) {
            $this->fail('entry_academic_year_id', 'Select an Entry Academic Year from this institution.');
        }
        if (! Curriculum::where('school_id', $schoolId)->where('programme_id', $data['programme_id'])->whereKey($data['curriculum_id'])->exists()) {
            $this->fail('curriculum_id', 'Select a Programme Study Plan belonging to the selected Programme.');
        }
    }

    private function suggestCode(int $schoolId, int $programmeId): string
    {
        $programme = Programme::where('school_id', $schoolId)->whereKey($programmeId)->first();
        if (! $programme) {
            return 'PC-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        }
        $base = 'PC-' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $programme->code ?: $programme->name));
        $base = trim(substr($base, 0, 44), '-');
        do {
            $code = $base . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        } while (ProgrammeCohort::where('school_id', $schoolId)->where('code', $code)->exists());

        return $code;
    }

    private function actorSchool(User $actor): int
    {
        $schoolId = (int) $actor->school_id;
        if ($schoolId < 1 || ! School::whereKey($schoolId)->exists()) {
            throw new DomainException('Choose an institution context before managing Programme Cohorts.');
        }
        if ($actor->school_id === null) {
            throw new DomainException('A Programme Cohort action must be performed by an institution-scoped user.');
        }

        return $schoolId;
    }

    private function requirePermission(User $actor, string $permission): void
    {
        if (! app(\App\Support\Permissions\PermissionService::class)->allows($actor, $permission)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You do not have permission to manage Programme Cohorts.');
        }
    }

    private function cohortValues(ProgrammeCohort $cohort): array
    {
        return $cohort->only(['programme_id', 'intake_session_id', 'entry_academic_year_id', 'curriculum_id', 'name', 'code', 'status', 'expected_completion_date']);
    }

    private function membershipValues(ProgrammeCohortMembership $membership): array
    {
        return $membership->only(['student_id', 'programme_cohort_id', 'admission_id', 'admission_reference', 'status', 'started_at', 'ended_at', 'reason']);
    }

    private function audit(string $event, ProgrammeCohort $cohort, array $old, array $new): void
    {
        AuditLog::record($event, 'Academic', "{$event} for Programme Cohort #{$cohort->id}.", [
            'school_id' => $cohort->school_id,
            'event_type' => 'ACADEMIC',
            'record_type' => 'ProgrammeCohort',
            'record_id' => $cohort->id,
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }

    private function auditMembership(string $event, ProgrammeCohortMembership $membership, array $old, array $new): void
    {
        AuditLog::record($event, 'Academic', "{$event} for Programme Cohort membership #{$membership->id}.", [
            'school_id' => $membership->school_id,
            'event_type' => 'ACADEMIC',
            'record_type' => 'ProgrammeCohortMembership',
            'record_id' => $membership->id,
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
