<?php

namespace App\Support\CourseRegistration;

use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\StudentCurriculumAssignment;
use App\Models\StudentFeeManager;
use App\Models\StudentFeeRegistrationWaiver;
use App\Models\User;
use App\Support\Permissions\PermissionService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CourseRegistrationFeeWaiverService
{
    private const PERMISSION = 'academic.course_registration.fee_waiver';

    public function __construct(
        private PermissionService $permissions,
    ) {
    }

    public function activeFor(User $student): ?StudentFeeRegistrationWaiver
    {
        if (! Schema::hasTable('student_fee_registration_waivers') || ! Schema::hasTable('schools')
            || ! Schema::hasColumn('schools', 'running_session')) return null;
        $sessionId = $this->currentSessionId((int) $student->school_id);
        if (! $sessionId) return null;

        return StudentFeeRegistrationWaiver::query()
            ->where('school_id', $student->school_id)->where('student_id', $student->id)
            ->where('session_id', $sessionId)->whereNull('revoked_at')->first();
    }

    public function mayWaive(User $student, CourseOffering $offering, ?StudentCurriculumAssignment $assignment): bool
    {
        if (! $assignment || (int) $student->school_id !== (int) $offering->school_id) return false;
        if (! Schema::hasTable('student_fee_managers') || ! Schema::hasTable('student_fee_registration_waivers')) return false;
        $sessionId = $this->currentSessionId((int) $student->school_id);
        if (! $sessionId || $this->activeFor($student)) return false;
        if (! StudentFeeManager::query()->where('school_id', $student->school_id)->where('student_id', $student->id)
            ->where('session_id', $sessionId)->where('total_amount', '>', 0)->exists()) return false;

        return true;
    }

    public function grant(User $actor, int $studentId, CourseOffering $offering, string $reason): StudentFeeRegistrationWaiver
    {
        $this->authorize($actor, (int) $offering->school_id);
        if (! app(CourseOfferingEligibility::class)->evaluate($offering, $studentId)->eligible) {
            throw new DomainException('The student must meet the academic requirements for this Course Allocation before a fee waiver can be granted.');
        }
        return $this->grantForStudent($actor, $studentId, (int) $offering->school_id, $reason);
    }

    public function grantForStudent(User $actor, int $studentId, int $schoolId, string $reason): StudentFeeRegistrationWaiver
    {
        $this->authorize($actor, $schoolId);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) { throw new DomainException('Provide a waiver reason between 10 and 500 characters.'); }
        $sessionId = $this->currentSessionId($schoolId);
        if (! $sessionId) throw new DomainException('The institution current fee session is not configured.');

        return DB::transaction(function () use ($actor, $studentId, $reason, $schoolId, $sessionId): StudentFeeRegistrationWaiver {
            $student = User::query()->where('school_id', $schoolId)->where('role_id', 7)->lockForUpdate()->findOrFail($studentId);
            $existing = StudentFeeRegistrationWaiver::query()->where('school_id', $schoolId)->where('student_id', $studentId)
                ->where('session_id', $sessionId)->lockForUpdate()->first();
            if ($existing && ! $existing->revoked_at) return $existing;
            if (! StudentFeeManager::query()->where('school_id', $schoolId)->where('student_id', $studentId)
                ->where('session_id', $sessionId)->where('total_amount', '>', 0)->exists()) {
                throw new DomainException('A current-session fee invoice is required before a fee waiver can be granted.');
            }
            if (app(StudentRegistrationConfirmationEligibility::class)->allows($student)) {
                throw new DomainException('The student already meets the current-session payment requirement or has an active waiver.');
            }

            $values = [
                'granted_by' => $actor->id,
                'reason' => $reason,
                'granted_at' => now(),
                'revoked_by' => null,
                'revoke_reason' => null,
                'revoked_at' => null,
            ];
            if ($existing) {
                $existing->forceFill($values)->save();
                $waiver = $existing->refresh();
            } else {
                $waiver = StudentFeeRegistrationWaiver::create($values + [
                    'school_id' => $schoolId,
                    'student_id' => $studentId,
                    'session_id' => $sessionId,
                ]);
            }

            AuditLog::record('COURSE_REGISTRATION_FEE_WAIVER_GRANTED', 'Academic', "20% study fee requirement waived for student #{$studentId} for session #{$sessionId}.", [
                'school_id' => $schoolId,
                'event_type' => 'ACADEMIC',
                'record_type' => 'StudentFeeRegistrationWaiver',
                'record_id' => $waiver->id,
                'new_values' => ['student_id' => $studentId, 'session_id' => $sessionId, 'reason' => $reason],
            ]);

            return $waiver;
        });
    }

    public function revoke(User $actor, int $studentId, int $schoolId, string $reason): void
    {
        $this->authorize($actor, $schoolId);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) { throw new DomainException('Provide a revocation reason between 10 and 500 characters.'); }
        $sessionId = $this->currentSessionId($schoolId);
        if (! $sessionId) throw new DomainException('The institution current fee session is not configured.');

        DB::transaction(function () use ($actor, $studentId, $schoolId, $sessionId, $reason): void {
            User::whereKey($studentId)->where('school_id', $schoolId)->where('role_id', 7)->lockForUpdate()->firstOrFail();
            $waiver = StudentFeeRegistrationWaiver::query()->where('school_id', $schoolId)->where('student_id', $studentId)
                ->where('session_id', $sessionId)->whereNull('revoked_at')->lockForUpdate()->firstOrFail();
            $waiver->forceFill(['revoked_by' => $actor->id, 'revoke_reason' => $reason, 'revoked_at' => now()])->save();

            AuditLog::record('COURSE_REGISTRATION_FEE_WAIVER_REVOKED', 'Academic', "Course-registration fee waiver revoked for student #{$studentId} for session #{$sessionId}.", [
                'school_id' => $schoolId,
                'event_type' => 'ACADEMIC',
                'record_type' => 'StudentFeeRegistrationWaiver',
                'record_id' => $waiver->id,
                'old_values' => ['student_id' => $studentId, 'session_id' => $sessionId, 'reason' => $waiver->reason],
                'new_values' => ['revoke_reason' => $reason],
            ]);
        });
    }

    private function currentSessionId(int $schoolId): ?int
    {
        if (! Schema::hasTable('schools') || ! Schema::hasColumn('schools', 'running_session')) return null;
        $id = DB::table('schools')->where('id', $schoolId)->value('running_session');
        return $id ? (int) $id : null;
    }

    private function authorize(User $actor, int $schoolId): void
    {
        if ((int) $actor->school_id !== $schoolId || ! $this->permissions->allows($actor, self::PERMISSION)) {
            throw new DomainException('You are not authorised to manage student study fee waivers.');
        }
    }
}
