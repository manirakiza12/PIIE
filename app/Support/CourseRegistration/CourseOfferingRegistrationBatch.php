<?php

namespace App\Support\CourseRegistration;

use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Administrator bulk registration and confirmation for one Course Offering.
 *
 * Every student is processed on its own through CourseRegistrationService,
 * which re-applies CourseOfferingEligibility, locking and the unique
 * registration key at execution time. One refused student never aborts the
 * batch; each outcome is summarised with an administrator-readable reason.
 */
class CourseOfferingRegistrationBatch
{
    public const REGISTERED = 'registered';
    public const CONFIRMED = 'confirmed';
    public const ALREADY = 'already';
    public const SKIPPED = 'skipped';

    public function __construct(private CourseRegistrationService $registrations)
    {
    }

    /** Current (active or deferred) members of a cohort relevant to this Offering. */
    public function cohortStudentIds(CourseOffering $offering, int $cohortId): Collection
    {
        $cohort = $this->relevantCohorts($offering)->firstWhere('id', $cohortId);
        if (! $cohort) {
            throw ValidationException::withMessages(['programme_cohort_id' => 'Choose an active Programme Cohort whose Study Plan is linked to this Course Offering.']);
        }

        return DB::table('programme_cohort_memberships')
            ->where('school_id', $offering->school_id)->where('programme_cohort_id', $cohortId)
            ->whereNull('ended_at')->whereIn('status', ['active', 'deferred'])
            ->pluck('student_id')->map(fn ($id) => (int) $id)->unique()->values();
    }

    /** Active Programme Cohorts in this tenant following a Study Plan linked to the Offering. */
    public function relevantCohorts(CourseOffering $offering): Collection
    {
        $studyPlans = DB::table('course_offering_curriculum_memberships')
            ->where('school_id', $offering->school_id)->where('course_offering_id', $offering->id)->distinct()->pluck('curriculum_id');

        return DB::table('programme_cohorts')->where('school_id', $offering->school_id)->where('status', 'active')
            ->whereIn('curriculum_id', $studyPlans)->orderBy('name')->get(['id', 'name', 'code']);
    }

    public function register(CourseOffering $offering, iterable $studentIds, int $actorId): array
    {
        $schoolId = (int) $offering->school_id;
        $outcomes = collect($studentIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()
            ->map(function (int $studentId) use ($offering, $schoolId, $actorId): array {
                $name = $this->studentName($schoolId, $studentId);
                if ($name === null) {
                    return $this->outcome('A selected student', self::SKIPPED, 'This student was not found in this institution.');
                }
                $existing = CourseRegistration::query()->where('school_id', $schoolId)
                    ->where('course_offering_id', $offering->id)->where('student_id', $studentId)->first();
                if ($existing) {
                    return $existing->status === CourseRegistration::STATUS_DROPPED
                        ? $this->outcome($name, self::SKIPPED, 'Previously withdrawn from this Course Offering; re-registration is not allowed.')
                        : $this->outcome($name, self::ALREADY, 'Already registered.');
                }
                try {
                    $registration = $this->registrations->registerStudentForOffering($schoolId, $studentId, (int) $offering->id, null, $actorId);
                } catch (DomainException $exception) {
                    return $this->outcome($name, self::SKIPPED, $this->readable($exception, 'This student could not be registered; the academic office should review the record.'));
                } catch (Throwable $exception) {
                    report($exception);
                    return $this->outcome($name, self::SKIPPED, 'This student could not be registered. Please try again.');
                }

                return $registration->wasRecentlyCreated
                    ? $this->outcome($name, self::REGISTERED, 'Registered.')
                    : $this->outcome($name, self::ALREADY, 'Already registered.');
            });

        return $this->summary('registration', $outcomes);
    }

    public function confirm(CourseOffering $offering, iterable $registrationIds, int $actorId): array
    {
        $schoolId = (int) $offering->school_id;
        $outcomes = collect($registrationIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values()
            ->map(function (int $registrationId) use ($offering, $schoolId, $actorId): array {
                $registration = CourseRegistration::query()->where('school_id', $schoolId)
                    ->where('course_offering_id', $offering->id)->whereKey($registrationId)->first();
                if (! $registration) {
                    return $this->outcome('A selected registration', self::SKIPPED, 'This registration was not found for this Course Offering.');
                }
                $name = $this->studentName($schoolId, (int) $registration->student_id) ?? 'A selected student';
                if ($registration->status === CourseRegistration::STATUS_CONFIRMED) {
                    return $this->outcome($name, self::ALREADY, 'Already confirmed.');
                }
                if ($registration->status === CourseRegistration::STATUS_DROPPED) {
                    return $this->outcome($name, self::SKIPPED, 'Dropped registrations cannot be confirmed.');
                }
                try {
                    $confirmed = $this->registrations->confirmRegistration($schoolId, $registrationId, $actorId);
                } catch (ValidationException) {
                    return $this->outcome($name, self::SKIPPED, 'Registration cannot be confirmed because the student\'s financial clearance is incomplete.');
                } catch (DomainException $exception) {
                    return $this->outcome($name, self::SKIPPED, $this->readable($exception, 'This registration needs academic review before it can be confirmed.'));
                } catch (Throwable $exception) {
                    report($exception);
                    return $this->outcome($name, self::SKIPPED, 'This registration could not be confirmed. Please try again.');
                }

                return $confirmed->status === CourseRegistration::STATUS_CONFIRMED
                    ? $this->outcome($name, self::CONFIRMED, 'Confirmed.')
                    : $this->outcome($name, self::SKIPPED, 'This registration could not be confirmed.');
            });

        return $this->summary('confirmation', $outcomes);
    }

    /** Eligibility messages are already administrator-facing; older service messages may name internals. */
    private function readable(DomainException $exception, string $fallback): string
    {
        $message = $exception->getMessage();
        foreach (['provenance', 'snapshot', 'tenant', 'Membership', 'Curriculum', 'Subject', 'AcademicYear', 'User', ' id', '#'] as $internal) {
            if (str_contains($message, $internal)) {
                return $fallback;
            }
        }

        return $message;
    }

    private function studentName(int $schoolId, int $studentId): ?string
    {
        return DB::table('users')->where('school_id', $schoolId)->where('id', $studentId)->where('role_id', 7)->value('name');
    }

    private function outcome(string $student, string $result, string $reason): array
    {
        return ['student' => $student, 'result' => $result, 'reason' => $reason];
    }

    private function summary(string $action, Collection $outcomes): array
    {
        return [
            'action' => $action,
            'reviewed' => $outcomes->count(),
            'registered' => $outcomes->where('result', self::REGISTERED)->count(),
            'confirmed' => $outcomes->where('result', self::CONFIRMED)->count(),
            'already' => $outcomes->where('result', self::ALREADY)->count(),
            'skipped' => $outcomes->where('result', self::SKIPPED)->count(),
            'details' => $outcomes->where('result', '!=', self::REGISTERED)->where('result', '!=', self::CONFIRMED)->values()->all(),
        ];
    }
}
