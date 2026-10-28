<?php

namespace App\Support;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\Session;
use App\Models\StudentFeeManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Validated tenant-local mutations for canonical current academic pointers. */
class AcademicContextManager
{
    public function mapLegacySession(int $schoolId, int $sessionId, ?int $yearId): void
    {
        $session = Session::query()->where('school_id', $schoolId)->findOrFail($sessionId);
        $year = $yearId ? AcademicYear::query()->where('school_id', $schoolId)->find($yearId) : null;
        if ($yearId && ! $year) {
            throw ValidationException::withMessages(['academic_year_id' => 'The selected year is not available in this school.']);
        }

        if ((int) ($session->academic_year_id ?? 0) !== (int) ($year?->id ?? 0)
            && Schema::hasTable('student_fee_managers')
            && StudentFeeManager::query()->where('school_id', $schoolId)->where('session_id', $session->id)->exists()) {
            throw ValidationException::withMessages([
                'academic_year_id' => 'This Finance Session is already used by invoices. Its Academic Year cannot be changed without changing existing invoice and reporting context.',
            ]);
        }

        $session->academic_year_id = $year?->id;
        $session->save();
    }

    public function setCurrent(int $schoolId, ?int $yearId, ?int $periodId): void
    {
        DB::transaction(function () use ($schoolId, $yearId, $periodId) {
            School::query()->whereKey($schoolId)->lockForUpdate()->firstOrFail();
            $year = $yearId ? AcademicYear::query()->where('school_id', $schoolId)->find($yearId) : null;
            if ($yearId && ! $year) {
                throw ValidationException::withMessages(['current_academic_year_id' => 'The selected year is not available in this school.']);
            }
            if ($year && $year->status !== 'active') {
                throw ValidationException::withMessages(['current_academic_year_id' => 'Activate this academic year before selecting it as current.']);
            }

            $period = $periodId ? AcademicPeriod::query()->where('school_id', $schoolId)->find($periodId) : null;
            if ($periodId && (! $period || ! $year || (int) $period->academic_year_id !== (int) $year->id)) {
                throw ValidationException::withMessages(['current_academic_period_id' => 'The selected period must belong to the selected year and school.']);
            }
            if ($period && $period->status !== 'active') {
                throw ValidationException::withMessages(['current_academic_period_id' => 'Activate this academic period before selecting it as current.']);
            }

            School::query()->whereKey($schoolId)->update([
                'current_academic_year_id' => $year?->id,
                'current_academic_period_id' => $period?->id,
            ]);
            if ($period) {
                app(\App\Support\CourseOffering\SemesterCourseOfferings::class)->reconcile($schoolId, (int) $period->id, true);
            }
        });
    }

    public function transitionYear(int $schoolId, int $yearId, string $toStatus): void
    {
        DB::transaction(function () use ($schoolId, $yearId, $toStatus) {
            $school = School::query()->whereKey($schoolId)->lockForUpdate()->firstOrFail();
            $year = AcademicYear::query()->where('school_id', $schoolId)->whereKey($yearId)->lockForUpdate()->firstOrFail();

            $this->assertTransition($year->status, $toStatus, 'status');

            if (in_array($toStatus, ['completed', 'cancelled'], true)
                && (int) $school->current_academic_year_id === (int) $year->id) {
                throw ValidationException::withMessages(['status' => 'Clear or replace this school’s current academic context before completing or cancelling the current year.']);
            }

            if ($toStatus === 'completed' && $year->periods()->whereIn('status', ['planned', 'active'])->exists()) {
                throw ValidationException::withMessages(['status' => 'Complete or cancel all planned and active periods before completing this academic year.']);
            }

            $year->status = $toStatus;
            $year->save();
        });
    }

    public function transitionPeriod(int $schoolId, int $periodId, string $toStatus): void
    {
        DB::transaction(function () use ($schoolId, $periodId, $toStatus) {
            $school = School::query()->whereKey($schoolId)->lockForUpdate()->firstOrFail();
            $period = AcademicPeriod::query()->where('school_id', $schoolId)->whereKey($periodId)->lockForUpdate()->firstOrFail();

            $this->assertTransition($period->status, $toStatus, 'status');

            if (in_array($toStatus, ['completed', 'cancelled'], true)
                && (int) $school->current_academic_period_id === (int) $period->id) {
                throw ValidationException::withMessages(['status' => 'Clear or replace this school’s current period before completing or cancelling it.']);
            }

            $period->status = $toStatus;
            $period->save();
            if ($toStatus === 'active') {
                app(\App\Support\CourseOffering\SemesterCourseOfferings::class)->reconcile($schoolId, $periodId, true);
            }
        });
    }

    private function assertTransition(string $fromStatus, string $toStatus, string $field): void
    {
        $allowed = [
            'planned' => ['active', 'cancelled'],
            'active' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
        ];

        if (! in_array($toStatus, $allowed[$fromStatus] ?? [], true)) {
            throw ValidationException::withMessages([$field => "The academic lifecycle transition from {$fromStatus} to {$toStatus} is not allowed."]);
        }
    }
}
