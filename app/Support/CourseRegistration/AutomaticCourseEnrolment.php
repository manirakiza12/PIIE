<?php

namespace App\Support\CourseRegistration;

use App\Models\{AuditLog, CourseOffering, CourseRegistration, User};
use Illuminate\Support\Facades\{DB, Schema};

/** Adds current-semester registrations. Never changes finance or academic history. */
final class AutomaticCourseEnrolment
{
    public function __construct(
        private CourseOfferingEligibility $academic,
        private StudentRegistrationConfirmationEligibility $finance,
    ) {}

    /** Same decisions for preview and apply; locks serialize retries and context changes. */
    public function reconcileStudent(int $schoolId, int $studentId, bool $apply = false): array
    {
        return DB::transaction(function () use ($schoolId, $studentId, $apply): array {
            $school = DB::table('schools')->where('id', $schoolId)->lockForUpdate()->first();
            $student = User::where('school_id', $schoolId)->where('role_id', 7)->whereKey($studentId)->lockForUpdate()->first();
            if (! $school || ! $student || $student->account_status === 'disable') {
                return [['result' => 'blocked', 'reason' => 'student_inactive']];
            }
            $yearId = (int) ($school->current_academic_year_id ?? 0);
            $periodId = (int) ($school->current_academic_period_id ?? 0);
            $year = DB::table('academic_years')->where('school_id', $schoolId)->where('id', $yearId)->lockForUpdate()->first();
            $period = DB::table('academic_periods')->where('school_id', $schoolId)->where('academic_year_id', $yearId)
                ->where('id', $periodId)->lockForUpdate()->first();
            if (! $year || ! $period || $year->status !== 'active' || $period->status !== 'active'
                || ! $this->withinDates($year, $school->timezone ?? null) || ! $this->withinDates($period, $school->timezone ?? null)) {
                return [['result' => 'blocked', 'reason' => 'no_active_period']];
            }
            $placement = $this->academic->placement($schoolId, $studentId, $yearId, true);
            if (! $placement->eligible) {
                $profileProgrammeId = DB::table('student_profiles')->where('school_id', $schoolId)->where('user_id', $studentId)->value('programme_id');
                $placementProgrammeId = $placement->assignment?->programme_id;
                $codes = DB::table('programmes')->where('school_id', $schoolId)
                    ->whereIn('id', array_filter([$profileProgrammeId, $placementProgrammeId]))->pluck('code', 'id');
                return [['result' => 'blocked', 'reason' => $placement->code,
                    'academic_year_id' => $yearId, 'academic_period_id' => $periodId,
                    'profile_programme_code' => $codes->get($profileProgrammeId),
                    'placement_programme_code' => $codes->get($placementProgrammeId)]];
            }
            if (Schema::hasColumn('curriculum_stages', 'archived_at') && DB::table('curriculum_stages')
                ->where('school_id', $schoolId)->where('id', $placement->assignment->entry_curriculum_stage_id)->whereNotNull('archived_at')->exists()) {
                return [['result' => 'blocked', 'reason' => 'archived_programme_year']];
            }
            if (Schema::hasColumn('sessions', 'academic_year_id')) {
                $feeYear = DB::table('sessions')->where('school_id', $schoolId)->where('id', $school->running_session)->value('academic_year_id');
                if ($feeYear !== null && (int) $feeYear !== $yearId) {
                    return [['result' => 'blocked', 'reason' => 'finance_session_year_mismatch']];
                }
            }
            // These are the existing Finance balances, after verified receipts and approved awards.
            if (! $this->finance->allows($student, (int) $placement->assignment->programme_id, true)) {
                return [['result' => 'blocked', 'reason' => 'finance_ineligible']];
            }

            $offerings = CourseOffering::where('school_id', $schoolId)->where('academic_year_id', $yearId)
                ->where('academic_period_id', $periodId)->whereIn('status', ['open', 'in_progress'])
                ->orderBy('id')->lockForUpdate()->get();
            $applicable = [];
            foreach ($offerings as $offering) {
                $eligibility = $this->academic->evaluate($offering, $studentId, null, true);
                if ($eligibility->eligible && $eligibility->membership->getAttribute('archived_at') === null) {
                    $applicable[(int) $offering->subject_id][] = [$offering, $eligibility->membership];
                }
            }
            $outcomes = [];
            foreach ($applicable as $choices) {
                // Do not guess a teaching section where parallel allocations exist.
                $ids = array_map(fn ($choice) => $choice[0]->id, $choices);
                $existingChoices = CourseRegistration::where('school_id', $schoolId)->where('student_id', $studentId)
                    ->whereIn('course_offering_id', $ids)->lockForUpdate()->get();
                foreach ($choices as [$offering, $membership]) {
                    $row = ['course_offering_id' => (int) $offering->id, 'subject_id' => (int) $offering->subject_id];
                    $existing = $existingChoices->firstWhere('course_offering_id', $offering->id);
                    if ($existing && $existing->status !== CourseRegistration::STATUS_REGISTERED) {
                        $outcomes[] = $row + ['result' => 'preserved', 'reason' => $existing->status];
                        continue;
                    }
                    if (count($choices) > 1 && ! $existing) {
                        $outcomes[] = $row + ['result' => 'blocked', 'reason' => 'parallel_allocations'];
                        continue;
                    }
                    if ($membership->classification === 'elective' && ! $existing) {
                        $outcomes[] = $row + ['result' => 'skipped', 'reason' => 'elective_requires_choice'];
                        continue;
                    }
                    if ($existing && ((int) $existing->curriculum_membership_id !== (int) $membership->id
                        || (int) $existing->subject_id !== (int) $offering->subject_id
                        || (string) $existing->registered_credits !== number_format((float) $membership->credits, 2, '.', '')
                        || $existing->registered_classification !== $membership->classification)) {
                        $outcomes[] = $row + ['result' => 'blocked', 'reason' => 'registration_needs_review'];
                        continue;
                    }
                    if ($apply) {
                        $registration = $existing ?? new CourseRegistration([
                            'school_id' => $schoolId, 'student_id' => $studentId,
                            'course_offering_id' => $offering->id, 'subject_id' => $offering->subject_id,
                            'curriculum_membership_id' => $membership->id, 'session_id' => null,
                            'registered_credits' => $membership->credits, 'registered_classification' => $membership->classification,
                        ]);
                        $registration->status = CourseRegistration::STATUS_CONFIRMED;
                        $registration->save();
                        AuditLog::record('COURSE_REGISTRATION_AUTO_CONFIRMED', 'Course Registrations', 'Eligible student automatically enrolled.', [
                            'school_id' => $schoolId, 'record_type' => CourseRegistration::class, 'record_id' => $registration->id,
                            'event_type' => 'COURSE_REGISTRATION',
                            'old_values' => $existing ? ['status' => 'registered'] : null,
                            'new_values' => $row + ['student_id' => $studentId, 'status' => 'confirmed', 'source' => 'automatic_eligibility'],
                        ]);
                    }
                    $outcomes[] = $row + ['result' => $apply ? 'enrolled' : 'would_enrol', 'reason' => $existing ? 'confirm_pending' : 'create'];
                }
            }
            return $outcomes ?: [['result' => 'blocked', 'reason' => 'no_applicable_allocation']];
        }, 3);
    }

    private function withinDates(object $record, ?string $timezone): bool
    {
        $today = now($timezone ?: config('app.timezone'))->toDateString();
        return (empty($record->start_date) || substr($record->start_date, 0, 10) <= $today)
            && (empty($record->end_date) || substr($record->end_date, 0, 10) >= $today);
    }
}
