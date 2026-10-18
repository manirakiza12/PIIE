<?php

namespace App\Support\CourseOffering;

use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\CourseOfferingLecturerAllocation;
use App\Models\User;
use App\Support\Roles\SystemRole;
use App\Support\Staff\StaffStatus;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CourseOfferingLecturerAllocationService
{
    public function deletePlanned(int $schoolId, int $allocationId): void
    {
        DB::transaction(function () use ($schoolId, $allocationId): void {
            $initial = $this->allocation($schoolId, $allocationId);
            $offering = $this->lockedOffering($schoolId, (int)$initial->course_offering_id);
            $allocation = $this->lockedAllocation($schoolId, $allocationId);
            $this->assertOfferingWritable($offering);
            if ($allocation->status !== CourseOfferingLecturerAllocation::STATUS_PLANNED) {
                throw new DomainException('Only unused planned lecturer assignments can be deleted. End or cancel an active assignment to preserve its history.');
            }
            $this->audit('COURSE_OFFERING_LECTURER_DRAFT_DELETED', $allocation, $this->allocationValues($allocation), []);
            DB::table('course_offering_lecturer_allocations')->where('school_id', $schoolId)->where('id', $allocationId)->delete();
        });
    }

    /**
     * Users eligible to receive a planned allocation for this tenant.
     * On-leave staff remain selectable here; activation revalidates their status.
     */
    public function eligibleLecturersForOffering(int $schoolId, int $offeringId): Collection
    {
        $offering = CourseOffering::where('school_id', $schoolId)->whereKey($offeringId)->firstOrFail();

        $hasStaffProfiles = Schema::hasTable('staff_profiles');
        $relations = [
            'department' => fn ($query) => $query->where('school_id', $schoolId),
            'designationRecord' => fn ($query) => $query->where('school_id', $schoolId),
        ];
        // Staff profile details are optional enrichment; older installations may
        // not yet have the professional-records table.
        if ($hasStaffProfiles) {
            $relations['staffProfile'] = fn ($query) => $query->where('school_id', $schoolId);
        }

        $lecturers = $this->eligibleLecturersQuery((int) $offering->school_id)
            ->with($relations)
            ->orderBy('name')
            ->get();

        if (! $hasStaffProfiles) {
            // Mark the optional relationship as resolved so Blade accessors do
            // not trigger a lazy query against a table this installation lacks.
            $lecturers->each(fn (User $lecturer) => $lecturer->setRelation('staffProfile', null));
        }

        return $lecturers;
    }

    public function createPlanned(
        int $schoolId,
        int $offeringId,
        int $userId,
        string $role,
        string $startsOn,
        ?string $endsOn = null
    ): CourseOfferingLecturerAllocation {
        return DB::transaction(function () use ($schoolId, $offeringId, $userId, $role, $startsOn, $endsOn): CourseOfferingLecturerAllocation {
            $offering = $this->lockedOffering($schoolId, $offeringId);
            $this->assertOfferingWritable($offering);
            $dates = $this->validateAllocation($offering, $userId, $role, $startsOn, $endsOn, false);
            $this->assertNoConflicts($offering, $userId, $role, $dates['starts_on'], $dates['ends_on']);

            return $this->insertPlanned($offering, $userId, $role, $dates['starts_on'], $dates['ends_on']);
        });
    }

    public function updatePlanned(int $schoolId, int $allocationId, array $changes): CourseOfferingLecturerAllocation
    {
        $allowed = ['user_id', 'role', 'starts_on', 'ends_on'];
        if ($changes === [] || array_diff(array_keys($changes), $allowed) !== []) {
            throw new DomainException('Only lecturer, role, and effective dates may be changed on a planned allocation.');
        }

        return DB::transaction(function () use ($schoolId, $allocationId, $changes): CourseOfferingLecturerAllocation {
            $initial = $this->allocation($schoolId, $allocationId);
            $offering = $this->lockedOffering($schoolId, (int) $initial->course_offering_id);
            $allocation = $this->lockedAllocation($schoolId, $allocationId);
            $this->assertOfferingWritable($offering);
            if ($allocation->status !== CourseOfferingLecturerAllocation::STATUS_PLANNED) {
                throw new DomainException('Only planned lecturer allocations may be edited.');
            }

            $before = $this->allocationValues($allocation);
            $userId = (int) ($changes['user_id'] ?? $allocation->user_id);
            $role = (string) ($changes['role'] ?? $allocation->role);
            $startsOn = (string) ($changes['starts_on'] ?? $allocation->starts_on->format('Y-m-d'));
            $endsOn = array_key_exists('ends_on', $changes)
                ? ($changes['ends_on'] === null ? null : (string) $changes['ends_on'])
                : ($allocation->ends_on?->format('Y-m-d'));

            $dates = $this->validateAllocation($offering, $userId, $role, $startsOn, $endsOn, false);
            $this->assertNoConflicts($offering, $userId, $role, $dates['starts_on'], $dates['ends_on'], $allocationId);

            DB::table('course_offering_lecturer_allocations')->where('school_id', $schoolId)->where('id', $allocationId)->update([
                'user_id' => $userId,
                'role' => $role,
                'starts_on' => $dates['starts_on'],
                'ends_on' => $dates['ends_on'],
                'updated_at' => now(),
            ]);
            $updated = $this->lockedAllocation($schoolId, $allocationId);
            $this->audit('COURSE_OFFERING_LECTURER_ALLOCATION_UPDATED', $updated, $before, $this->allocationValues($updated));

            return $updated;
        });
    }

    public function activate(int $schoolId, int $allocationId): CourseOfferingLecturerAllocation
    {
        return DB::transaction(function () use ($schoolId, $allocationId): CourseOfferingLecturerAllocation {
            $initial = $this->allocation($schoolId, $allocationId);
            $offering = $this->lockedOffering($schoolId, (int) $initial->course_offering_id);
            $allocation = $this->lockedAllocation($schoolId, $allocationId);
            if (! in_array($offering->status, [CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true)) {
                throw new DomainException('Lecturer allocations may only be activated for open or in-progress Allocations.');
            }
            if ($allocation->status !== CourseOfferingLecturerAllocation::STATUS_PLANNED) {
                throw new DomainException('Only planned lecturer allocations may be activated.');
            }

            $dates = $this->validateAllocation(
                $offering,
                (int) $allocation->user_id,
                $allocation->role,
                $allocation->starts_on->format('Y-m-d'),
                $allocation->ends_on?->format('Y-m-d'),
                true
            );
            $this->assertNoConflicts($offering, (int) $allocation->user_id, $allocation->role, $dates['starts_on'], $dates['ends_on'], $allocationId);

            $before = $this->allocationValues($allocation);
            DB::table('course_offering_lecturer_allocations')->where('school_id', $schoolId)->where('id', $allocationId)->update([
                'status' => CourseOfferingLecturerAllocation::STATUS_ACTIVE,
                'updated_at' => now(),
            ]);
            $updated = $this->lockedAllocation($schoolId, $allocationId);
            $this->audit('COURSE_OFFERING_LECTURER_ALLOCATION_ACTIVATED', $updated, $before, $this->allocationValues($updated));

            return $updated;
        });
    }

    public function end(int $schoolId, int $allocationId, string $endsOn): CourseOfferingLecturerAllocation
    {
        return DB::transaction(function () use ($schoolId, $allocationId, $endsOn): CourseOfferingLecturerAllocation {
            $initial = $this->allocation($schoolId, $allocationId);
            $offering = $this->lockedOffering($schoolId, (int) $initial->course_offering_id);
            $allocation = $this->lockedAllocation($schoolId, $allocationId);
            $this->assertOfferingWritable($offering);
            if ($allocation->status !== CourseOfferingLecturerAllocation::STATUS_ACTIVE) {
                throw new DomainException('Only active lecturer allocations may be ended.');
            }

            $date = $this->parseDate($endsOn);
            $this->assertDateInPeriod($offering, $date, 'Allocation end date');
            if ($date < $allocation->starts_on->format('Y-m-d')) {
                throw new DomainException('Allocation end date cannot precede its start date.');
            }
            $this->assertNoConflicts(
                $offering,
                (int) $allocation->user_id,
                $allocation->role,
                $allocation->starts_on->format('Y-m-d'),
                $date,
                $allocationId
            );

            $before = $this->allocationValues($allocation);
            DB::table('course_offering_lecturer_allocations')->where('school_id', $schoolId)->where('id', $allocationId)->update([
                'ends_on' => $date,
                'status' => CourseOfferingLecturerAllocation::STATUS_ENDED,
                'updated_at' => now(),
            ]);
            $updated = $this->lockedAllocation($schoolId, $allocationId);
            $this->audit('COURSE_OFFERING_LECTURER_ALLOCATION_ENDED', $updated, $before, $this->allocationValues($updated));

            return $updated;
        });
    }

    public function cancel(int $schoolId, int $allocationId, string $reason): CourseOfferingLecturerAllocation
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A nonblank cancellation reason is required.');
        }

        return DB::transaction(function () use ($schoolId, $allocationId, $reason): CourseOfferingLecturerAllocation {
            $initial = $this->allocation($schoolId, $allocationId);
            $offering = $this->lockedOffering($schoolId, (int) $initial->course_offering_id);
            $allocation = $this->lockedAllocation($schoolId, $allocationId);
            $this->assertOfferingWritable($offering);
            if (! in_array($allocation->status, [CourseOfferingLecturerAllocation::STATUS_PLANNED, CourseOfferingLecturerAllocation::STATUS_ACTIVE], true)) {
                throw new DomainException('Only planned or active lecturer allocations may be cancelled.');
            }

            $before = $this->allocationValues($allocation);
            DB::table('course_offering_lecturer_allocations')->where('school_id', $schoolId)->where('id', $allocationId)->update([
                'status' => CourseOfferingLecturerAllocation::STATUS_CANCELLED,
                'updated_at' => now(),
            ]);
            $updated = $this->lockedAllocation($schoolId, $allocationId);
            $after = $this->allocationValues($updated) + ['reason' => $reason];
            $this->audit('COURSE_OFFERING_LECTURER_ALLOCATION_CANCELLED', $updated, $before, $after);

            return $updated;
        });
    }

    public function replace(
        int $schoolId,
        int $allocationId,
        int $replacementUserId,
        string $replacementRole,
        string $oldEndsOn,
        string $newStartsOn,
        ?string $newEndsOn = null
    ): CourseOfferingLecturerAllocation {
        return DB::transaction(function () use ($schoolId, $allocationId, $replacementUserId, $replacementRole, $oldEndsOn, $newStartsOn, $newEndsOn): CourseOfferingLecturerAllocation {
            $initial = $this->allocation($schoolId, $allocationId);
            $offering = $this->lockedOffering($schoolId, (int) $initial->course_offering_id);
            $allocation = $this->lockedAllocation($schoolId, $allocationId);
            $this->assertOfferingWritable($offering);
            if ($allocation->status !== CourseOfferingLecturerAllocation::STATUS_ACTIVE) {
                throw new DomainException('Only an active lecturer allocation may be replaced.');
            }

            $oldEnd = $this->parseDate($oldEndsOn);
            $newStart = $this->parseDate($newStartsOn);
            if ($oldEnd < $allocation->starts_on->format('Y-m-d')) {
                throw new DomainException('The previous lecturer end date cannot precede its start date.');
            }
            if ($oldEnd >= $newStart) {
                throw new DomainException('The previous lecturer must end before the replacement start date.');
            }
            $this->assertDateInPeriod($offering, $oldEnd, 'Previous allocation end date');
            $newDates = $this->validateAllocation($offering, $replacementUserId, $replacementRole, $newStart, $newEndsOn, false);

            $before = $this->allocationValues($allocation);
            DB::table('course_offering_lecturer_allocations')->where('school_id', $schoolId)->where('id', $allocationId)->update([
                'ends_on' => $oldEnd,
                'status' => CourseOfferingLecturerAllocation::STATUS_ENDED,
                'updated_at' => now(),
            ]);
            $endedAllocation = $this->lockedAllocation($schoolId, $allocationId);
            $this->audit('COURSE_OFFERING_LECTURER_ALLOCATION_ENDED', $endedAllocation, $before, $this->allocationValues($endedAllocation));

            $this->assertNoConflicts(
                $offering,
                $replacementUserId,
                $replacementRole,
                $newDates['starts_on'],
                $newDates['ends_on']
            );
            $newAllocation = $this->insertPlanned($offering, $replacementUserId, $replacementRole, $newDates['starts_on'], $newDates['ends_on']);
            $this->audit('COURSE_OFFERING_LECTURER_REPLACED', $newAllocation, $before, [
                'previous_allocation_id' => $allocationId,
                'previous_user_id' => $allocation->user_id,
                'previous_role' => $allocation->role,
                'previous_ends_on' => $oldEnd,
                'replacement_allocation_id' => $newAllocation->id,
                'replacement_user_id' => $replacementUserId,
                'replacement_role' => $replacementRole,
                'replacement_starts_on' => $newDates['starts_on'],
                'replacement_ends_on' => $newDates['ends_on'],
                'replacement_status' => CourseOfferingLecturerAllocation::STATUS_PLANNED,
            ]);

            return $newAllocation;
        });
    }

    private function insertPlanned(CourseOffering $offering, int $userId, string $role, string $startsOn, ?string $endsOn): CourseOfferingLecturerAllocation
    {
        $id = DB::table('course_offering_lecturer_allocations')->insertGetId([
            'school_id' => $offering->school_id,
            'course_offering_id' => $offering->id,
            'user_id' => $userId,
            'role' => $role,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => CourseOfferingLecturerAllocation::STATUS_PLANNED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $allocation = CourseOfferingLecturerAllocation::where('school_id', $offering->school_id)->findOrFail($id);
        $this->audit('COURSE_OFFERING_LECTURER_ALLOCATION_CREATED', $allocation, [], $this->allocationValues($allocation));

        return $allocation;
    }

    private function validateAllocation(CourseOffering $offering, int $userId, string $role, string $startsOn, ?string $endsOn, bool $activating): array
    {
        if (! in_array($role, CourseOfferingLecturerAllocation::ROLES, true)) {
            throw new DomainException('The lecturer allocation role is invalid.');
        }

        $user = $this->eligibleLecturersQuery((int) $offering->school_id)->whereKey($userId)->first();
        if (! $user) {
            throw new DomainException('The lecturer must be an eligible User in the Allocation tenant.');
        }
        if ($activating && $user->staff_status === StaffStatus::ON_LEAVE) {
            throw new DomainException('A staff member on leave cannot be activated into a teaching allocation.');
        }
        $start = $this->parseDate($startsOn);
        $end = $endsOn === null ? null : $this->parseDate($endsOn);
        if ($end !== null && $end < $start) {
            throw new DomainException('Allocation end date cannot precede its start date.');
        }
        $this->assertDateInPeriod($offering, $start, 'Allocation start date');
        if ($end !== null) {
            $this->assertDateInPeriod($offering, $end, 'Allocation end date');
        }

        return ['starts_on' => $start, 'ends_on' => $end];
    }

    private function assertNoConflicts(CourseOffering $offering, int $userId, string $role, string $startsOn, ?string $endsOn, ?int $exceptId = null): void
    {
        $userConflict = $this->overlapQuery($offering, $startsOn, $endsOn, $exceptId)
            ->where('user_id', $userId)
            ->first();

        if ($userConflict) {
            throw new CourseOfferingLecturerAllocationConflict(
                $this->lockedAllocation((int) $offering->school_id, (int) $userConflict->id),
                false
            );
        }

        if ($role === CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER) {
            $primaryConflict = $this->overlapQuery($offering, $startsOn, $endsOn, $exceptId)
                ->where('role', CourseOfferingLecturerAllocation::ROLE_PRIMARY_LECTURER)
                ->first();

            if ($primaryConflict) {
                throw new CourseOfferingLecturerAllocationConflict(
                    $this->lockedAllocation((int) $offering->school_id, (int) $primaryConflict->id),
                    true
                );
            }
        }
    }

    private function overlapQuery(CourseOffering $offering, string $startsOn, ?string $endsOn, ?int $exceptId): \Illuminate\Database\Query\Builder
    {
        return DB::table('course_offering_lecturer_allocations')
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->where('status', '!=', CourseOfferingLecturerAllocation::STATUS_CANCELLED)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where(function ($query) use ($startsOn): void {
                $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn);
            })
            ->when($endsOn !== null, fn ($query) => $query->whereDate('starts_on', '<=', $endsOn));
    }

    private function eligibleLecturersQuery(int $schoolId): Builder
    {
        return User::query()
            ->where('school_id', $schoolId)
            ->where('role_id', SystemRole::TEACHER)
            ->where(function ($query): void {
                $query->whereNull('account_status')->orWhere('account_status', '!=', 'disable');
            })
            ->where(function ($query): void {
                $query->whereNull('staff_status')->orWhereNotIn('staff_status', [
                    StaffStatus::SUSPENDED,
                    StaffStatus::INACTIVE,
                    StaffStatus::TERMINATED,
                ]);
            });
    }

    private function assertDateInPeriod(CourseOffering $offering, string $date, string $label): void
    {
        $period = DB::table('academic_periods')
            ->where('school_id', $offering->school_id)
            ->where('academic_year_id', $offering->academic_year_id)
            ->where('id', $offering->academic_period_id)
            ->first(['start_date', 'end_date']);

        if (! $period || ! $period->start_date || ! $period->end_date) {
            throw new DomainException('The Allocation Academic Period has no valid date bounds.');
        }
        $periodStart = $this->parseDate((string) $period->start_date);
        $periodEnd = $this->parseDate((string) $period->end_date);
        if ($periodEnd < $periodStart || $date < $periodStart || $date > $periodEnd) {
            throw new DomainException("{$label} must fall within the Allocation Academic Period.");
        }
    }

    private function parseDate(string $value): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable $exception) {
            throw new DomainException('Allocation dates must use a valid YYYY-MM-DD date.', 0, $exception);
        }
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Allocation dates must use a valid YYYY-MM-DD date.');
        }

        return $date->format('Y-m-d');
    }

    private function assertOfferingWritable(CourseOffering $offering): void
    {
        if (! in_array($offering->status, [CourseOffering::STATUS_DRAFT, CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true)) {
            throw new DomainException("Lecturer allocations cannot be changed for a {$offering->status} Allocation.");
        }
    }

    private function lockedOffering(int $schoolId, int $offeringId): CourseOffering
    {
        return CourseOffering::where('school_id', $schoolId)
            ->whereKey($offeringId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function allocation(int $schoolId, int $allocationId): CourseOfferingLecturerAllocation
    {
        return CourseOfferingLecturerAllocation::where('school_id', $schoolId)->findOrFail($allocationId);
    }

    private function lockedAllocation(int $schoolId, int $allocationId): CourseOfferingLecturerAllocation
    {
        return CourseOfferingLecturerAllocation::where('school_id', $schoolId)
            ->whereKey($allocationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function allocationValues(CourseOfferingLecturerAllocation $allocation): array
    {
        return [
            'course_offering_id' => (int) $allocation->course_offering_id,
            'user_id' => (int) $allocation->user_id,
            'role' => $allocation->role,
            'starts_on' => $allocation->starts_on?->format('Y-m-d'),
            'ends_on' => $allocation->ends_on?->format('Y-m-d'),
            'status' => $allocation->status,
        ];
    }

    private function audit(string $action, CourseOfferingLecturerAllocation $allocation, array $before, array $after): void
    {
        AuditLog::record($action, 'Course Allocation Lecturer Allocations', "{$action} for Course Allocation #{$allocation->course_offering_id}, allocation #{$allocation->id}.", [
            'school_id' => $allocation->school_id,
            'record_type' => CourseOfferingLecturerAllocation::class,
            'record_id' => $allocation->id,
            'event_type' => 'COURSE_OFFERING_LECTURER_ALLOCATION',
            'old_values' => $before ?: null,
            'new_values' => $after ?: null,
        ]);
    }
}
