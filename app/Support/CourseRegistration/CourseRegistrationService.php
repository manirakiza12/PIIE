<?php

namespace App\Support\CourseRegistration;

use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use App\Support\Curriculum\StudentCurriculumAssignmentService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseRegistrationService
{
    public function __construct(private StudentRegistrationConfirmationEligibility $confirmationEligibility, private StudentCurriculumAssignmentService $assignments, private CourseOfferingEligibility $eligibility)
    {
    }

    public function registerStudentForOffering(int $schoolId, int $studentId, int $offeringId, ?int $membershipId = null, ?int $actorId = null): CourseRegistration
    {
        $actorId ??= auth()->id();
        return DB::transaction(function () use ($schoolId, $studentId, $offeringId, $membershipId, $actorId): CourseRegistration {
            $student = $this->student($schoolId, $studentId, true);
            $this->assertActor($student, $actorId);
            $offering = CourseOffering::where('school_id', $schoolId)->whereKey($offeringId)->first();
            if (! $offering) {
                throw new DomainException('This Course Offering could not be found in this institution.');
            }
            $membership = $this->applicableMembership($schoolId, $student, $offering, $membershipId);
            $offering = CourseOffering::where('school_id', $schoolId)->whereKey($offeringId)->lockForUpdate()->first();
            if ($offering->status !== CourseOffering::STATUS_OPEN) {
                throw new DomainException('Students may register only while the Course Offering is open.');
            }
            $existing = CourseRegistration::where('school_id', $schoolId)->where('student_id', $studentId)
                ->where('course_offering_id', $offeringId)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->curriculum_membership_id !== (int) $membership->id) {
                    throw new DomainException('This student already has a registration for this Course Offering under a different Study Plan entry. The academic office should review it.');
                }
                if ($existing->status === CourseRegistration::STATUS_DROPPED) {
                    throw new DomainException('This student already has a dropped registration for this Offering; same-Offering re-registration is not allowed.');
                }
                return $existing;
            }

            try {
                $registration = CourseRegistration::create([
                    'school_id' => $schoolId,
                    'student_id' => $studentId,
                    'course_offering_id' => $offering->id,
                    'curriculum_membership_id' => $membership->id,
                    'subject_id' => $offering->subject_id,
                    'session_id' => null,
                    'registered_credits' => $membership->credits,
                    'registered_classification' => $membership->classification,
                    'status' => CourseRegistration::STATUS_REGISTERED,
                ]);
            } catch (\Illuminate\Database\QueryException $exception) {
                if ($this->isOfferingUniqueConflict($exception)) {
                    $existing = CourseRegistration::where('school_id', $schoolId)->where('student_id', $studentId)
                        ->where('course_offering_id', $offeringId)->first();
                    if ($existing && $existing->status !== CourseRegistration::STATUS_DROPPED) {
                        return $existing;
                    }
                    throw new DomainException('A registration for this Offering already exists.');
                }
                throw $exception;
            }

            $this->audit('COURSE_REGISTRATION_CREATED', $registration, null, $actorId);
            return $registration;
        });
    }

    public function confirmRegistration(int $schoolId, int $registrationId, ?int $actorId = null): CourseRegistration
    {
        $actorId ??= auth()->id();
        return DB::transaction(function () use ($schoolId, $registrationId, $actorId): CourseRegistration {
            $registration = CourseRegistration::where('school_id', $schoolId)->whereKey($registrationId)->first();
            if (! $registration || ! $registration->course_offering_id) {
                throw new DomainException('This Course Registration could not be found in this institution. The academic office should review it.');
            }
            $student = $this->student($schoolId, (int) $registration->student_id, true);
            $this->assertActor($student, $actorId);
            $offering = CourseOffering::where('school_id', $schoolId)->whereKey($registration->course_offering_id)->first();
            if (! $offering) {
                throw new DomainException('This Course Registration no longer matches the Course Offering. The academic office should review it.');
            }
            $membership = $this->applicableMembership($schoolId, $student, $offering, (int) $registration->curriculum_membership_id, true);
            $offering = CourseOffering::where('school_id', $schoolId)->whereKey($registration->course_offering_id)->lockForUpdate()->first();
            $registration = CourseRegistration::where('school_id', $schoolId)->whereKey($registrationId)->lockForUpdate()->first();
            if (! $offering || (int) $offering->subject_id !== (int) $registration->subject_id) {
                throw new DomainException('This Course Registration no longer matches the Course Offering. The academic office should review it.');
            }
            if ((string) $registration->registered_credits !== number_format((float) $membership->credits, 2, '.', '')
                || $registration->registered_classification !== $membership->classification) {
                throw new DomainException('This Course Registration no longer matches the Course Unit it was registered for. The academic office should review it.');
            }
            if ($registration->status === CourseRegistration::STATUS_CONFIRMED) {
                return $registration;
            }
            if ($registration->status !== CourseRegistration::STATUS_REGISTERED) {
                throw new DomainException('Only registered students may confirm an Offering registration.');
            }
            if ($offering->status !== CourseOffering::STATUS_OPEN) {
                throw new DomainException('Registration confirmation is allowed only while the Course Offering is open.');
            }
            if (! $this->confirmationEligibility->allows($student)) {
                throw ValidationException::withMessages(['registration' => 'Outstanding fees must be settled before this Course Registration can be confirmed.']);
            }

            $registration->status = CourseRegistration::STATUS_CONFIRMED;
            $registration->save();
            $this->audit('COURSE_REGISTRATION_CONFIRMED', $registration, CourseRegistration::STATUS_REGISTERED, $actorId);
            return $registration;
        });
    }

    public function dropRegistration(int $schoolId, int $registrationId, ?int $actorId = null, ?string $reason = null): CourseRegistration
    {
        $actorId ??= auth()->id();
        return DB::transaction(function () use ($schoolId, $registrationId, $actorId, $reason): CourseRegistration {
            $registration = CourseRegistration::where('school_id', $schoolId)->whereKey($registrationId)->first();
            if (! $registration || ! $registration->course_offering_id) {
                throw new DomainException('This Course Registration could not be found in this institution. The academic office should review it.');
            }
            $student = $this->student($schoolId, (int) $registration->student_id, true);
            $this->assertActor($student, $actorId);
            $offering = CourseOffering::where('school_id', $schoolId)->whereKey($registration->course_offering_id)->lockForUpdate()->first();
            $registration = CourseRegistration::where('school_id', $schoolId)->whereKey($registrationId)->lockForUpdate()->first();
            if (! $offering || (int) $offering->subject_id !== (int) $registration->subject_id) {
                throw new DomainException('This Course Registration no longer matches the Course Offering. The academic office should review it.');
            }
            $this->storedMembership($schoolId, $offering, $registration);
            if ($registration->status === CourseRegistration::STATUS_DROPPED) {
                return $registration;
            }
            if (! in_array($registration->status, [CourseRegistration::STATUS_REGISTERED, CourseRegistration::STATUS_CONFIRMED], true)) {
                throw new DomainException('Only active registrations may be dropped.');
            }
            if (! in_array($offering->status, [CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true)) {
                throw new DomainException('Registration cannot be dropped for a completed or cancelled Offering.');
            }
            $before = $registration->status;
            $registration->status = CourseRegistration::STATUS_DROPPED;
            $registration->save();
            $this->audit('COURSE_REGISTRATION_DROPPED', $registration, $before, $actorId, $reason);
            return $registration;
        });
    }

    private function student(int $schoolId, int $studentId, bool $lock = false): User
    {
        $query = User::where('school_id', $schoolId)->whereKey($studentId);
        if ($lock) $query->lockForUpdate();
        $student = $query->first();
        if (! $student || (int) $student->role_id !== 7 || $student->account_status === 'disable') {
            throw new DomainException('This student account is not active in this institution.');
        }
        return $student;
    }

    private function assertActor(User $student, ?int $actorId): void
    {
        $actor = $actorId === null ? null : User::where('school_id', $student->school_id)->whereKey($actorId)->first();
        if (! $actor || $actor->account_status === 'disable') {
            throw new DomainException('Your account is not active in this institution, so this Course Registration cannot be changed.');
        }
        if ((int) $actor->role_id === 7 && (int) $actor->id !== (int) $student->id) {
            throw new DomainException('Student self-service may act only on its own registration.');
        }
    }

    private function applicableMembership(int $schoolId, User $student, CourseOffering $offering, ?int $membershipId, bool $allowRetired = false): CurriculumMembership
    {
        $result = $this->eligibility->evaluate($offering, (int) $student->id, $membershipId, true, $allowRetired);
        if (! $result->eligible || ! $result->membership || (int) $result->membership->subject_id !== (int) $offering->subject_id) {
            throw new DomainException($result->eligible ? 'This Course Offering needs academic review before students can register.' : $result->message);
        }

        return $result->membership;
    }

    private function storedMembership(int $schoolId, CourseOffering $offering, CourseRegistration $registration): CurriculumMembership
    {
        $link = DB::table('course_offering_curriculum_memberships')
            ->where('school_id', $schoolId)->where('course_offering_id', $offering->id)
            ->where('curriculum_membership_id', $registration->curriculum_membership_id)
            ->where('subject_id', $registration->subject_id)->first();
        $membership = CurriculumMembership::where('school_id', $schoolId)
            ->where('subject_id', $registration->subject_id)->whereKey($registration->curriculum_membership_id)->first();
        $curriculum = $membership ? Curriculum::where('school_id', $schoolId)->whereKey($membership->curriculum_id)->first() : null;
        if (! $link || ! $membership || ! $curriculum || (int) $link->curriculum_id !== (int) $membership->curriculum_id
            || ! in_array($curriculum->status, ['approved', 'retired'], true)
            || (string) $registration->registered_credits !== number_format((float) $membership->credits, 2, '.', '')
            || $registration->registered_classification !== $membership->classification) {
            throw new DomainException('This Course Registration no longer matches its Study Plan entry. The academic office should review it.');
        }
        return $membership;
    }

    private function isOfferingUniqueConflict(\Illuminate\Database\QueryException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'cr_school_student_offering_uq')
            || str_contains(strtolower($exception->getMessage()), 'course_registrations.school_id');
    }

    private function audit(string $action, CourseRegistration $registration, ?string $oldStatus, ?int $actorId, ?string $reason = null): void
    {
        AuditLog::record($action, 'Course Registrations', "{$action} for registration #{$registration->id}.", [
            'school_id' => $registration->school_id,
            'record_type' => CourseRegistration::class,
            'record_id' => $registration->id,
            'event_type' => 'COURSE_REGISTRATION',
            'old_values' => $oldStatus ? ['status' => $oldStatus] : null,
            'new_values' => array_filter([
                'student_id' => $registration->student_id,
                'course_offering_id' => $registration->course_offering_id,
                'curriculum_membership_id' => $registration->curriculum_membership_id,
                'status' => $registration->status,
                'actor_id' => $actorId,
                'reason' => $reason,
            ], fn ($value) => $value !== null),
        ]);
    }
}
