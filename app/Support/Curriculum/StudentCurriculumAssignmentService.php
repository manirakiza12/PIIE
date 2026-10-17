<?php

namespace App\Support\Curriculum;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Programme;
use App\Models\School;
use App\Models\StudentCurriculumAssignment;
use App\Models\StudentProfile;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudentCurriculumAssignmentService
{
    public function assignInitialCurriculum(int $schoolId, int $studentId, int $programmeId, int $entryAcademicYearId, int $effectiveFromAcademicYearId, int $actorId): StudentCurriculumAssignment
    {
        return DB::transaction(function () use ($schoolId, $studentId, $programmeId, $entryAcademicYearId, $effectiveFromAcademicYearId, $actorId): StudentCurriculumAssignment {
            [$student, $programme, $entryYear, $effectiveYear, $actor] = $this->lockAndValidateContext($schoolId, $studentId, $programmeId, $entryAcademicYearId, $effectiveFromAcademicYearId, $actorId);
            $this->assertNoActiveAssignment($schoolId, $studentId);

            $eligible = Curriculum::query()->where('school_id', $schoolId)->where('programme_id', $programmeId)
                ->where('status', 'approved')->whereNotNull('effective_academic_year_id')
                ->lockForUpdate()->get();
            $eligibleYears = AcademicYear::query()->where('school_id', $schoolId)
                ->whereIn('id', $eligible->pluck('effective_academic_year_id')->unique()->all())
                ->lockForUpdate()->get()->keyBy('id');
            $eligible->each(fn (Curriculum $curriculum) => $curriculum->setRelation('effectiveAcademicYear', $eligibleYears->get($curriculum->effective_academic_year_id)));
            $eligible = $eligible
                ->filter(fn (Curriculum $curriculum): bool => $curriculum->effectiveAcademicYear
                    && $curriculum->effectiveAcademicYear->start_date->lte($entryYear->start_date));

            if ($eligible->isEmpty()) {
                throw new DomainException('No approved Curriculum is eligible for the student entry AcademicYear.');
            }

            $latestFloor = $eligible->max(fn (Curriculum $curriculum) => $curriculum->effectiveAcademicYear->start_date->format('Y-m-d'));
            $latest = $eligible->filter(fn (Curriculum $curriculum): bool => $curriculum->effectiveAcademicYear->start_date->format('Y-m-d') === $latestFloor)->values();
            if ($latest->count() !== 1) {
                throw new DomainException('The latest eligible effective-year floor is ambiguous; explicit Curriculum selection is required.');
            }

            $curriculum = $latest->first();
            $this->assertEffectiveDateNotBeforeCurriculumFloor($curriculum, $effectiveYear);
            return $this->createAssignment($schoolId, $student, $programme, $curriculum, $entryYear, $effectiveYear, $actor, null, 'STUDENT_CURRICULUM_ASSIGNED');
        });
    }

    public function assignExplicitCurriculum(int $schoolId, int $studentId, int $programmeId, int $curriculumId, int $entryAcademicYearId, int $effectiveFromAcademicYearId, int $actorId, ?string $reason = null): StudentCurriculumAssignment
    {
        return DB::transaction(function () use ($schoolId, $studentId, $programmeId, $curriculumId, $entryAcademicYearId, $effectiveFromAcademicYearId, $actorId, $reason): StudentCurriculumAssignment {
            [$student, $programme, $entryYear, $effectiveYear, $actor] = $this->lockAndValidateContext($schoolId, $studentId, $programmeId, $entryAcademicYearId, $effectiveFromAcademicYearId, $actorId);
            $this->assertNoActiveAssignment($schoolId, $studentId);
            $curriculum = $this->assignableCurriculum($schoolId, $programmeId, $curriculumId);
            $this->assertEffectiveDateNotBeforeCurriculumFloor($curriculum, $effectiveYear);

            return $this->createAssignment($schoolId, $student, $programme, $curriculum, $entryYear, $effectiveYear, $actor, $reason, 'STUDENT_CURRICULUM_ASSIGNED_EXPLICITLY');
        });
    }

    /** Place one student directly; a Programme Cohort is optional. */
    public function assignDirectPlacement(int $schoolId, int $studentId, int $programmeId, int $curriculumId, int $stageId, int $academicYearId, int $actorId): StudentCurriculumAssignment
    {
        return DB::transaction(function () use ($schoolId, $studentId, $programmeId, $curriculumId, $stageId, $academicYearId, $actorId): StudentCurriculumAssignment {
            [$student, $programme, $entryYear, $effectiveYear, $actor] = $this->lockAndValidateContext($schoolId, $studentId, $programmeId, $academicYearId, $academicYearId, $actorId);
            $this->assertNoActiveAssignment($schoolId, $studentId);
            $curriculum = $this->assignableCurriculum($schoolId, $programmeId, $curriculumId);
            $stage = \App\Models\CurriculumStage::query()->where('school_id', $schoolId)->where('curriculum_id', $curriculumId)->whereKey($stageId)->first();
            if (! $stage) {
                throw new DomainException('Choose a Study Stage from the selected approved Programme Curriculum.');
            }
            $this->assertEffectiveDateNotBeforeCurriculumFloor($curriculum, $effectiveYear);
            $profile = StudentProfile::query()->where('school_id', $schoolId)->where('user_id', $studentId)->lockForUpdate()->first();
            if (! $profile) {
                throw new DomainException('The student needs an institution profile before academic placement.');
            }

            $assignment = $this->createAssignment($schoolId, $student, $programme, $curriculum, $entryYear, $effectiveYear, $actor, 'Direct academic placement.', 'STUDENT_ACADEMIC_PLACEMENT_CREATED', $stageId);
            $profile->forceFill(['programme_id' => $programmeId, 'year_of_study' => $stage->sequence])->save();
            return $assignment;
        });
    }

    public function changeCurriculum(int $schoolId, int $studentId, int $curriculumId, int $effectiveFromAcademicYearId, int $actorId, string $reason): StudentCurriculumAssignment
    {
        return $this->changeAssignment($schoolId, $studentId, $curriculumId, null, $effectiveFromAcademicYearId, $actorId, $reason, false);
    }

    public function changeProgrammeCurriculum(int $schoolId, int $studentId, int $programmeId, int $curriculumId, int $effectiveFromAcademicYearId, int $actorId, string $reason): StudentCurriculumAssignment
    {
        return $this->changeAssignment($schoolId, $studentId, $curriculumId, $programmeId, $effectiveFromAcademicYearId, $actorId, $reason, true);
    }

    public function currentAssignment(int $schoolId, int $studentId): ?StudentCurriculumAssignment
    {
        $this->assertStudentInTenant($schoolId, $studentId);
        $active = StudentCurriculumAssignment::query()->where('school_id', $schoolId)->where('student_id', $studentId)->whereNull('ended_at')->get();
        if ($active->count() > 1) {
            throw new DomainException('Student Curriculum assignment integrity error: multiple active assignments exist.');
        }

        return $active->first();
    }

    public function assignmentForAcademicYear(int $schoolId, int $studentId, int $academicYearId, bool $lock = false): ?StudentCurriculumAssignment
    {
        $this->assertStudentInTenant($schoolId, $studentId);
        $yearQuery = AcademicYear::query()->where('school_id', $schoolId)->whereKey($academicYearId);
        if ($lock) {
            $yearQuery->lockForUpdate();
        }
        $year = $yearQuery->first();
        if (! $year) {
            throw new DomainException('Offering AcademicYear was not found in this tenant.');
        }

        $query = StudentCurriculumAssignment::query()
            ->where('student_curriculum_assignments.school_id', $schoolId)
            ->where('student_curriculum_assignments.student_id', $studentId)
            ->join('academic_years as assignment_years', function ($join): void {
                $join->on('assignment_years.id', '=', 'student_curriculum_assignments.effective_from_academic_year_id')
                    ->on('assignment_years.school_id', '=', 'student_curriculum_assignments.school_id');
            })
            ->where('assignment_years.start_date', '<=', $year->start_date->format('Y-m-d'))
            ->select('student_curriculum_assignments.*', 'assignment_years.start_date as effective_floor_start_date')
            ->orderByDesc('assignment_years.start_date');
        if ($lock) {
            $query->lockForUpdate();
        }
        $eligible = $query->get();
        if ($eligible->isEmpty()) {
            return null;
        }
        $latestDate = $eligible->first()->effective_floor_start_date;
        $latest = $eligible->filter(fn (StudentCurriculumAssignment $assignment): bool => $assignment->effective_floor_start_date === $latestDate);
        if ($latest->count() !== 1) {
            throw new DomainException('Student Curriculum assignment integrity error: multiple assignments share the latest effective AcademicYear floor.');
        }

        return $latest->first();
    }

    public function assignmentHistory(int $schoolId, int $studentId): Collection
    {
        $this->assertStudentInTenant($schoolId, $studentId);
        return StudentCurriculumAssignment::query()->where('student_curriculum_assignments.school_id', $schoolId)->where('student_curriculum_assignments.student_id', $studentId)
            ->join('academic_years as assignment_years', function ($join): void {
                $join->on('assignment_years.id', '=', 'student_curriculum_assignments.effective_from_academic_year_id')
                    ->on('assignment_years.school_id', '=', 'student_curriculum_assignments.school_id');
            })
            ->select('student_curriculum_assignments.*')
            ->orderByDesc('assignment_years.start_date')->orderByDesc('student_curriculum_assignments.id')->get();
    }

    private function changeAssignment(int $schoolId, int $studentId, int $curriculumId, ?int $programmeId, int $effectiveFromYearId, int $actorId, string $reason, bool $programmeChange): StudentCurriculumAssignment
    {
        if (trim($reason) === '') {
            throw new DomainException('A nonblank reason is required for an assignment change.');
        }

        return DB::transaction(function () use ($schoolId, $studentId, $curriculumId, $programmeId, $effectiveFromYearId, $actorId, $reason, $programmeChange): StudentCurriculumAssignment {
            $student = $this->lockStudent($schoolId, $studentId);
            $this->assertHigherEducationTenant($schoolId);
            $actor = $this->administrativeActor($schoolId, $actorId);
            $active = StudentCurriculumAssignment::query()->where('school_id', $schoolId)->where('student_id', $studentId)->whereNull('ended_at')->lockForUpdate()->get();
            if ($active->count() !== 1) {
                throw new DomainException($active->isEmpty() ? 'Student has no active Curriculum assignment to change.' : 'Student Curriculum assignment integrity error: multiple active assignments exist.');
            }
            $old = $active->first();
            $destinationProgrammeId = $programmeId ?? (int) $old->programme_id;
            if (! $programmeChange && (int) $old->programme_id !== $destinationProgrammeId) {
                throw new DomainException('Same-Programme Curriculum change must preserve the Programme.');
            }

            $programme = $this->programme($schoolId, $destinationProgrammeId);
            $curriculum = $this->assignableCurriculum($schoolId, $destinationProgrammeId, $curriculumId);
            $effectiveYear = $this->academicYear($schoolId, $effectiveFromYearId, 'effective_from_academic_year_id');
            $this->assertEffectiveDateNotBeforeCurriculumFloor($curriculum, $effectiveYear);
            if ($effectiveYear->start_date->lt($old->effectiveFromAcademicYear->start_date)) {
                throw new DomainException('A replacement assignment cannot take effect before the current assignment.');
            }
            if ($effectiveYear->start_date->eq($old->effectiveFromAcademicYear->start_date)) {
                throw new DomainException('A replacement assignment must take effect after the current assignment starts.');
            }

            $old->endThroughDomainService(now());
            $this->audit('STUDENT_CURRICULUM_ASSIGNMENT_ENDED', $old, $actor, ['ended_at' => $old->ended_at->toISOString(), 'reason' => trim($reason)]);

            if ($programmeChange) {
                $profile = StudentProfile::query()->where('school_id', $schoolId)->where('user_id', $studentId)->lockForUpdate()->first();
                if ($profile) {
                    $previousProgrammeId = $profile->programme_id;
                    $profile->programme_id = $programme->id;
                    $profile->save();
                    AuditLog::record('STUDENT_PROGRAMME_CHANGED', 'Student Profiles', "Programme changed for student #{$studentId}.", [
                        'school_id' => $schoolId,
                        'record_type' => StudentProfile::class,
                        'record_id' => $profile->id,
                        'event_type' => 'STUDENT_PROGRAMME_CHANGE',
                        'old_values' => ['programme_id' => $previousProgrammeId],
                        'new_values' => ['programme_id' => $programme->id, 'actor_id' => $actor->id, 'reason' => trim($reason)],
                    ]);
                }
            }

            return $this->createAssignment(
                $schoolId, $student, $programme, $curriculum, $old->entryAcademicYear, $effectiveYear, $actor,
                trim($reason), $programmeChange ? 'STUDENT_PROGRAMME_CURRICULUM_CHANGED' : 'STUDENT_CURRICULUM_CHANGED'
            );
        });
    }

    private function lockAndValidateContext(int $schoolId, int $studentId, int $programmeId, int $entryYearId, int $effectiveYearId, int $actorId): array
    {
        $student = $this->lockStudent($schoolId, $studentId);
        $this->assertHigherEducationTenant($schoolId);
        $programme = $this->programme($schoolId, $programmeId);
        $entryYear = $this->academicYear($schoolId, $entryYearId, 'entry_academic_year_id');
        $effectiveYear = $this->academicYear($schoolId, $effectiveYearId, 'effective_from_academic_year_id');
        $actor = $this->administrativeActor($schoolId, $actorId);
        if ($effectiveYear->start_date->lt($entryYear->start_date)) {
            throw new DomainException('Assignment effective AcademicYear cannot precede the student entry AcademicYear.');
        }

        return [$student, $programme, $entryYear, $effectiveYear, $actor];
    }

    private function lockStudent(int $schoolId, int $studentId): User
    {
        $student = User::query()->whereKey($studentId)->where('school_id', $schoolId)->lockForUpdate()->first();
        if (! $student || (int) $student->role_id !== 7 || $student->account_status === 'disable') {
            throw new DomainException('Student must be an enabled student User in this tenant.');
        }
        return $student;
    }

    private function assertStudentInTenant(int $schoolId, int $studentId): void
    {
        if (! User::query()->whereKey($studentId)->where('school_id', $schoolId)->where('role_id', 7)->exists()) {
            throw new DomainException('Student was not found in this tenant.');
        }
    }

    private function assertNoActiveAssignment(int $schoolId, int $studentId): void
    {
        $active = StudentCurriculumAssignment::query()->where('school_id', $schoolId)->where('student_id', $studentId)->whereNull('ended_at')->lockForUpdate()->get();
        if ($active->count() > 1) {
            throw new DomainException('Student Curriculum assignment integrity error: multiple active assignments exist.');
        }
        if ($active->isNotEmpty()) {
            throw new DomainException('Student already has an active governing Curriculum assignment.');
        }
    }

    private function assertHigherEducationTenant(int $schoolId): void
    {
        $school = School::query()->find($schoolId);
        if (! $school || ! in_array($school->academicStructure(), ['programme_based', 'mixed'], true)) {
            throw new DomainException('Student Curriculum assignment is available only to higher-education or mixed-structure tenants.');
        }
    }

    private function programme(int $schoolId, int $programmeId): Programme
    {
        return Programme::query()->where('school_id', $schoolId)->whereKey($programmeId)->lockForUpdate()->first()
            ?? throw new DomainException('Programme was not found in this tenant.');
    }

    private function academicYear(int $schoolId, int $yearId, string $field): AcademicYear
    {
        return AcademicYear::query()->where('school_id', $schoolId)->whereKey($yearId)->lockForUpdate()->first()
            ?? throw new DomainException("{$field} must reference an AcademicYear in this tenant.");
    }

    private function assignableCurriculum(int $schoolId, int $programmeId, int $curriculumId): Curriculum
    {
        $curriculum = Curriculum::query()->where('school_id', $schoolId)->where('programme_id', $programmeId)->whereKey($curriculumId)->lockForUpdate()->first();
        if (! $curriculum) {
            throw new DomainException('Curriculum must belong to the selected tenant and Programme.');
        }
        if ($curriculum->status !== 'approved') {
            throw new DomainException('Only an approved Curriculum may be assigned to a new governing interval.');
        }
        return $curriculum;
    }

    private function assertEffectiveDateNotBeforeCurriculumFloor(Curriculum $curriculum, AcademicYear $effectiveYear): void
    {
        if ($curriculum->effective_academic_year_id === null) {
            return;
        }
        $floor = AcademicYear::query()->where('school_id', $curriculum->school_id)->whereKey($curriculum->effective_academic_year_id)->lockForUpdate()->first();
        if (! $floor || $effectiveYear->start_date->lt($floor->start_date)) {
            throw new DomainException('Assignment effective AcademicYear cannot precede the Curriculum effective-year floor.');
        }
    }

    private function administrativeActor(int $schoolId, int $actorId): User
    {
        $actor = User::query()->whereKey($actorId)->where('school_id', $schoolId)->where('account_status', '!=', 'disable')->first();
        if (! $actor || ! in_array((int) $actor->role_id, [1, 2, 11, 12, 13, 19], true)) {
            throw new DomainException('Assignment actor must be an enabled same-tenant administrative User.');
        }
        return $actor;
    }

    private function createAssignment(int $schoolId, User $student, Programme $programme, Curriculum $curriculum, AcademicYear $entryYear, AcademicYear $effectiveYear, User $actor, ?string $reason, string $event, ?int $stageId = null): StudentCurriculumAssignment
    {
        $attributes = [
            'school_id' => $schoolId,
            'student_id' => $student->id,
            'programme_id' => $programme->id,
            'curriculum_id' => $curriculum->id,
            'entry_academic_year_id' => $entryYear->id,
            'effective_from_academic_year_id' => $effectiveYear->id,
            'assigned_by' => $actor->id,
            'reason' => $reason,
        ];
        // Keep the older assignment-only workflow compatible with deployments
        // whose schemas predate curriculum-stage provenance. Direct placement
        // passes a stage and therefore stores it on the current schema.
        if ($stageId !== null) {
            $attributes['entry_curriculum_stage_id'] = $stageId;
        }
        $assignment = StudentCurriculumAssignment::createThroughDomainService($attributes);

        $this->audit($event, $assignment, $actor, [
            'school_id' => $schoolId,
            'student_id' => $student->id,
            'programme_id' => $programme->id,
            'curriculum_id' => $curriculum->id,
            'entry_curriculum_stage_id' => $stageId,
            'entry_academic_year_id' => $entryYear->id,
            'effective_from_academic_year_id' => $effectiveYear->id,
            'assigned_by' => $actor->id,
            'reason' => $reason,
        ]);
        return $assignment;
    }

    private function audit(string $event, StudentCurriculumAssignment $assignment, User $actor, array $values): void
    {
        AuditLog::record($event, 'Student Curriculum Assignments', "{$event} for assignment #{$assignment->id}.", [
            'school_id' => $assignment->school_id,
            'record_type' => StudentCurriculumAssignment::class,
            'record_id' => $assignment->id,
            'event_type' => 'STUDENT_CURRICULUM_ASSIGNMENT',
            'new_values' => $values + ['actor_id' => $actor->id],
        ]);
    }
}
