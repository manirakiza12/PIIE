<?php

namespace App\Support\CourseOffering;

use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\Curriculum;
use App\Models\CurriculumMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use DomainException;

class CourseOfferingService
{
    public function deleteAllocation(int $schoolId, int $offeringId, int $actorId, string $confirmation): void
    {
        DB::transaction(function () use ($schoolId, $offeringId, $actorId, $confirmation): void {
            $offering = $this->offering($schoolId, $offeringId, false, true);
            if (! hash_equals('DELETE', trim($confirmation)) && ! hash_equals((string) $offering->reference, trim($confirmation))) {
                throw new DomainException('Type DELETE or the exact Allocation reference to confirm deletion.');
            }

            $old = $offering->only(['subject_id', 'academic_year_id', 'academic_period_id', 'reference', 'status']);
            // Discover every row that belongs to this allocation from direct
            // allocation links and declared FK descendants. Rows are collected
            // before deletion and removed leaf-first; shared parent records
            // (Course Units, curricula, students, lecturers) are never traversed.
            $this->deleteAllocationWorkflowRows($schoolId, $offeringId);
            $this->recordRequiredAudit('COURSE_OFFERING_DELETED', $offering, $actorId, $old);
            DB::table('course_offerings')->where('school_id', $schoolId)->where('id', $offeringId)->delete();
        });
    }

    /**
     * University-facing lifecycle wording, shared by the domain messages and the
     * administrator screens so administrators never see raw status slugs.
     */
    public const STATUS_LABELS = [
        CourseOffering::STATUS_DRAFT => 'Draft',
        CourseOffering::STATUS_OPEN => 'Open',
        CourseOffering::STATUS_IN_PROGRESS => 'In progress',
        CourseOffering::STATUS_COMPLETED => 'Completed',
        CourseOffering::STATUS_CANCELLED => 'Cancelled',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUS_LABELS[(string) $status] ?? 'current';
    }

    public function createDraft(int $schoolId, int $subjectId, int $academicYearId, int $academicPeriodId, ?string $reference = null): CourseOffering
    {
        $reference = $this->normalizeReference($reference);
        return DB::transaction(function () use ($schoolId, $subjectId, $academicYearId, $academicPeriodId, $reference): CourseOffering {
            // Serialize reference allocation per tenant; the database also enforces
            // the existing unique (school_id, reference) constraint.
            if (! DB::table('schools')->where('id', $schoolId)->lockForUpdate()->first(['id'])) {
                throw new DomainException('The tenant School does not exist.');
            }
            $this->assertAllocationIdentity($schoolId, $subjectId, $academicYearId, $academicPeriodId, null);
            $reference ??= $this->generatedReference($schoolId, $subjectId, $academicYearId, $academicPeriodId);
            $this->assertAllocationIdentity($schoolId, $subjectId, $academicYearId, $academicPeriodId, $reference);

            $id = DB::table('course_offerings')->insertGetId([
                'school_id' => $schoolId,
                'subject_id' => $subjectId,
                'academic_year_id' => $academicYearId,
                'academic_period_id' => $academicPeriodId,
                'reference' => $reference,
                'status' => CourseOffering::STATUS_DRAFT,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $offering = CourseOffering::where('school_id', $schoolId)->findOrFail($id);
            $this->audit('COURSE_OFFERING_DRAFT_CREATED', $offering, [], $offering->only(['subject_id', 'academic_year_id', 'academic_period_id', 'reference', 'status']));

            return $offering;
        });
    }

    /**
     * Identity-only edit for any allocation status.
     *
     * Identity (Course Unit, Academic Year, Academic Period) can be corrected
     * in every lifecycle state. Linked Study Plans and registrations are checked
     * against the candidate identity, and non-draft changes require a reason.
     *
     * The reference is SERVER-AUTHORITATIVE and is regenerated here from the
     * final validated identity, in the same statement that persists that
     * identity, inside this transaction. It is never accepted from the caller —
     * 'reference' is deliberately absent from $allowed, so a posted or
     * browser-previewed reference is rejected outright.
     *
     * Previously this method left `reference` untouched while freely changing
     * subject_id / academic_year_id / academic_period_id, so a draft's reference
     * and its Course Unit could and did disagree: production Allocation #5 ended up
     * as reference BBIT1103-2026-S1 against subject BBIT4201. Regenerating from
     * the persisted identity makes that state unreachable, and it also repairs
     * any existing mismatch on the next save, because a no-op edit recomputes
     * the same reference.
     *
     * Collisions reuse the existing governed parallel-delivery rule in
     * generatedReference(), which appends -2, -3, ... exactly as it already does
     * at creation. No new allocation scheme is introduced, and the existing
     * unique (school_id, reference) constraint remains the backstop.
     */
    public function updateAllocation(int $schoolId, int $offeringId, array $changes, int $actorId, ?string $reason = null): CourseOffering
    {
        return DB::transaction(function () use ($schoolId, $offeringId, $changes, $actorId, $reason): CourseOffering {
            $offering = $this->offering($schoolId, $offeringId, false, true);
            $allowed = ['subject_id', 'academic_year_id', 'academic_period_id'];
            if (array_diff(array_keys($changes), $allowed)) {
                throw new DomainException('Only the Allocation Course Unit, Academic Year and Academic Period may be updated.');
            }

            // 'reference' is in the audited set but never in $allowed, so it is
            // computed here and can never be supplied by the request.
            $audited = array_merge($allowed, ['reference']);

            $before = $offering->only(array_merge($audited, ['status']));
            $next = array_merge($offering->only($allowed), $changes);
            $subjectId = (int) $next['subject_id'];
            $yearId = (int) $next['academic_year_id'];
            $periodId = (int) $next['academic_period_id'];

            $this->assertAllocationIdentity($schoolId, $subjectId, $yearId, $periodId, null, $offeringId);

            $candidate = clone $offering;
            $candidate->setRawAttributes(array_merge($offering->getAttributes(), [
                'subject_id' => $subjectId,
                'academic_year_id' => $yearId,
                'academic_period_id' => $periodId,
            ]), true);
            if ($offering->applicability()->exists()) {
                foreach ($offering->applicability()->get() as $link) {
                    $this->validateApplicability($candidate, (int) $link->curriculum_membership_id);
                }
            }
            $this->validateRegistrationIdentity($offering, $candidate);
            $identityChanged = $subjectId !== (int) $offering->subject_id
                || $yearId !== (int) $offering->academic_year_id
                || $periodId !== (int) $offering->academic_period_id;
            if ($identityChanged && $offering->status !== CourseOffering::STATUS_DRAFT && trim((string) $reason) === '') {
                throw new DomainException('A reason is required when changing the identity of a non-draft Course Allocation.');
            }

            // Authoritative reference for the FINAL identity, ignoring this
            // Allocation's own current row so a no-op edit does not bump itself.
            $reference = $this->generatedReference($schoolId, $subjectId, $yearId, $periodId, $offeringId);
            $this->assertAllocationIdentity($schoolId, $subjectId, $yearId, $periodId, $reference, $offeringId);

            // One statement: identity and its reference can never diverge, and a
            // rejected update above has written nothing at all.
            $this->syncDenormalizedIdentity($schoolId, $offeringId, $subjectId, $yearId, $periodId);
            DB::table('course_offerings')->where('school_id', $schoolId)->where('id', $offeringId)->update([
                'subject_id' => $subjectId,
                'academic_year_id' => $yearId,
                'academic_period_id' => $periodId,
                'reference' => $reference,
                'updated_at' => now(),
            ]);
            $offering->refresh();
            $after = $offering->only($audited);
            if ($before != $after) {
                $newValues = array_merge($after, [
                    'status' => $offering->status,
                    'actor_user_id' => $actorId,
                    'reason' => $identityChanged && $offering->status !== CourseOffering::STATUS_DRAFT ? trim((string) $reason) : null,
                ]);
                if ($offering->status === CourseOffering::STATUS_DRAFT) {
                    $this->audit('COURSE_OFFERING_DRAFT_UPDATED', $offering, $before, $newValues);
                } else {
                    $this->recordRequiredAudit('COURSE_OFFERING_IDENTITY_UPDATED', $offering, $actorId, $before, $newValues);
                }
            }

            return $offering;
        });
    }

    /** Backward-compatible service entry point; authorization remains at the HTTP boundary. */
    public function updateDraft(int $schoolId, int $offeringId, array $changes): CourseOffering
    {
        return $this->updateAllocation($schoolId, $offeringId, $changes, (int) (auth()->id() ?? 0));
    }

    /**
     * Turn the progression gate on or off for one Course Allocation.
     *
     * WHY THIS IS A SERVICE METHOD AND NOT `$offering->save()`
     *
     * `CourseOffering` is `$guarded = ['*']` and its `updating` hook refuses every
     * direct write, because an Allocation is historical record: subject, year,
     * period and reference have to move together, under audit. A controller that
     * assigns and saves looks like it works and throws a DomainException on the
     * first request - which is a far worse failure than a refusal, because the
     * switch appears to be broken rather than unimplemented.
     *
     * This one column has none of those constraints: it carries no identity, no
     * reference and no knock-on effect on applicability. So it is written with the
     * same single-statement discipline as the rest of this class, and - unlike
     * `updateDraft` - it is deliberately available on a RUNNING course. Gating is
     * about how students move through teaching that is already happening, and an
     * Allocation that could only be configured while a draft would be configured
     * months before anyone knew whether it was right.
     *
     * AUDITED, because it changes what students can reach.
     *
     * @throws DomainException
     */
    public function setProgressionGate(int $schoolId, int $offeringId, bool $enabled): CourseOffering
    {
        return DB::transaction(function () use ($schoolId, $offeringId, $enabled): CourseOffering {
            // `$draftOnly` is FALSE, deliberately. Every other mutator on this Allocation is
            // draft-only because subject, year, period and reference are identity:
            // changing them mid-course would rewrite history. This column is not
            // identity and has no knock-on effect on applicability, and gating is
            // about teaching that is ALREADY happening. A draft-only switch would
            // mean configuring it months before anyone knew whether it was right -
            // and there is no second chance once the course is running.
            $offering = $this->offering($schoolId, $offeringId, false);

            $before = (bool) $offering->gate_progression;

            // Nothing to record for a no-op, and the audit trail should mean
            // something: a row saying "gate set to off" when it was already off
            // is noise that makes the real changes harder to find.
            if ($before === $enabled) {
                return $offering;
            }

            DB::table('course_offerings')
                ->where('school_id', $schoolId)
                ->where('id', $offeringId)
                ->update([
                    'gate_progression' => $enabled,
                    'updated_at' => now(),
                ]);

            $offering->refresh();

            $this->audit(
                $enabled ? 'COURSE_OFFERING_PROGRESSION_ENABLED' : 'COURSE_OFFERING_PROGRESSION_DISABLED',
                $offering,
                ['gate_progression' => $before],
                ['gate_progression' => $enabled],
            );

            return $offering;
        });
    }

    public function addApplicability(int $schoolId, int $offeringId, int $membershipId): void
    {
        DB::transaction(function () use ($schoolId, $offeringId, $membershipId): void {
            $offering = $this->offering($schoolId, $offeringId, true, true);
            $membership = $this->validateApplicability($offering, $membershipId);
            if ($offering->applicability()->where('curriculum_membership_id', $membership->id)->exists()) {
                throw new DomainException('This Study Plan is already linked to this Course Allocation.');
            }

            DB::table('course_offering_curriculum_memberships')->insert([
                'school_id' => $schoolId,
                'course_offering_id' => $offering->id,
                'curriculum_id' => $membership->curriculum_id,
                'curriculum_membership_id' => $membership->id,
                'subject_id' => $offering->subject_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit('COURSE_OFFERING_APPLICABILITY_ADDED', $offering, [], [
                'curriculum_id' => $membership->curriculum_id,
                'curriculum_membership_id' => $membership->id,
                'subject_id' => $offering->subject_id,
            ]);
        });
    }

    public function removeApplicability(int $schoolId, int $offeringId, int $membershipId): void
    {
        DB::transaction(function () use ($schoolId, $offeringId, $membershipId): void {
            $offering = $this->offering($schoolId, $offeringId, true, true);
            $link = $offering->applicability()->where('curriculum_membership_id', $membershipId)->first();
            if (! $link) {
                throw new DomainException('This Study Plan link was not found for this Course Allocation.');
            }
            DB::table('course_offering_curriculum_memberships')->where('school_id', $schoolId)
                ->where('course_offering_id', $offeringId)->where('curriculum_membership_id', $membershipId)->delete();
            $this->audit('COURSE_OFFERING_APPLICABILITY_REMOVED', $offering, [
                'curriculum_id' => $link->curriculum_id,
                'curriculum_membership_id' => $link->curriculum_membership_id,
                'subject_id' => $link->subject_id,
            ], []);
        });
    }

    public function open(int $schoolId, int $offeringId): CourseOffering
    {
        return $this->transition($schoolId, $offeringId, CourseOffering::STATUS_DRAFT, CourseOffering::STATUS_OPEN, function (CourseOffering $offering): void {
            $links = $offering->applicability()->get();
            if ($links->isEmpty()) {
                throw new DomainException('At least one Study Plan must be linked before this Course Allocation can be opened.');
            }
            foreach ($links as $link) {
                $this->validateApplicability($offering, (int) $link->curriculum_membership_id);
            }
        }, 'COURSE_OFFERING_OPENED');
    }

    public function start(int $schoolId, int $offeringId): CourseOffering
    {
        return $this->transition($schoolId, $offeringId, CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS, function (CourseOffering $offering): void {
            // Institutional date governance stays authoritative and comes first:
            // there is deliberately no per-Allocation override, so an institution
            // that needs to teach earlier must move the Academic Period through
            // the governed Academic Years & Periods workflow instead.
            $this->assertAcademicPeriodHasBegun($offering);
            $this->assertAcademicPeriodIsUsable($offering);
        }, 'COURSE_OFFERING_STARTED');
    }

    /**
     * Governed early start: an authorised Course Allocation administrator may move
     * an OPEN Allocation to IN PROGRESS before its Academic Period begins.
     *
     * This is a deliberate, audited exception to date governance, not a removal
     * of it. The normal start() rule is untouched and still refuses an early
     * start; there is no bypass parameter on that path. What this method does
     * NOT do is the important part: it does not move the Academic Period, the
     * Study Plan, the Programme Cohort, Academic Placement, any student
     * registration, or any lecturer allocation date. Only the Allocation's own
     * status changes, so the institution's real dates are never falsified.
     *
     * A cancelled Academic Period is still refused: an administrator override
     * cannot resurrect a period the institution has withdrawn.
     */
    public function startEarly(int $schoolId, int $offeringId, string $reason, ?int $actorId = null): CourseOffering
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A nonblank reason is required to start this Course Allocation before its Academic Period begins.');
        }

        $period = $this->academicPeriod($schoolId, $offeringId);

        $result = $this->transition($schoolId, $offeringId, CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS, function (CourseOffering $offering) use ($period): void {
            $this->assertAcademicPeriodIsUsable($offering);
        }, 'COURSE_OFFERING_STARTED_EARLY');

        // Recorded separately from the generic status transition so the audit
        // trail states plainly that the Academic Period was NOT moved.
        $this->audit('COURSE_OFFERING_EARLY_START', $result, [], [
            'offering_id' => $result->id,
            'offering_reference' => $result->reference,
            'offering_status' => $result->status,
            'started_early' => true,
            'administrator_user_id' => $actorId ?? auth()->id(),
            'academic_period_id' => $result->academic_period_id,
            'academic_period_start_date' => $period->start_date,
            'early_start_date' => now()->toDateString(),
            'days_before_period_start' => $period->start_date
                ? (int) round((\Illuminate\Support\Carbon::parse($period->start_date)->startOfDay()->getTimestamp() - now()->startOfDay()->getTimestamp()) / 86400)
                : null,
            'reason' => $reason,
        ]);

        return $result;
    }

    /**
     * Read-only: whether the governed early-start action is currently available.
     *
     * Mirrors every condition the action itself enforces, so the workspace never
     * offers a button the server would refuse. It is presentation only; the
     * service remains the sole authority.
     */
    public function earlyStartBlockers(CourseOffering $offering): array
    {
        if ($offering->status !== CourseOffering::STATUS_OPEN) {
            return ['Only an Open Course Allocation can be started early.'];
        }

        $period = DB::table('academic_periods')->where('school_id', $offering->school_id)
            ->where('academic_year_id', $offering->academic_year_id)->where('id', $offering->academic_period_id)
            ->first(['label', 'start_date', 'status']);
        if (! $period) {
            return ['The Academic Period for this Course Allocation could not be found.'];
        }
        if (strtolower((string) $period->status) === 'cancelled') {
            return ['This Course Allocation cannot be started early because its Academic Period has been cancelled.'];
        }
        if (! $period->start_date) {
            return [];
        }
        if ($this->academicPeriodHasBegun($offering)) {
            return ['The Academic Period has already begun, so this Course Allocation should be started normally.'];
        }

        return [];
    }

    /**
     * Read-only: whether today is on or after the Allocation's Academic Period
     * start date. The same test assertAcademicPeriodHasBegun() enforces, exposed
     * so the workspace can decide which of the two start controls to show
     * instead of guessing and offering a button that would be refused.
     */
    public function academicPeriodHasBegun(CourseOffering $offering): bool
    {
        $period = DB::table('academic_periods')->where('school_id', $offering->school_id)
            ->where('academic_year_id', $offering->academic_year_id)->where('id', $offering->academic_period_id)
            ->value('start_date');
        if (! $period) {
            return true;
        }

        return ! now()->startOfDay()->lt(\Illuminate\Support\Carbon::parse($period)->startOfDay());
    }

    private function academicPeriod(int $schoolId, int $offeringId): object
    {
        $offering = $this->offering($schoolId, $offeringId, false, false);
        $period = DB::table('academic_periods')->where('school_id', $schoolId)
            ->where('academic_year_id', $offering->academic_year_id)->where('id', $offering->academic_period_id)
            ->first(['label', 'start_date', 'status']);
        if (! $period) {
            throw new DomainException('The Academic Period for this Course Allocation could not be found.');
        }

        return $period;
    }

    /**
     * An Allocation cannot start under a cancelled Academic Period. The period
     * itself is only ever changed through the governed Academic Years & Periods
     * workflow; an individual Course Allocation can never bypass it.
     */
    private function assertAcademicPeriodIsUsable(CourseOffering $offering): void
    {
        $status = DB::table('academic_periods')->where('school_id', $offering->school_id)
            ->where('academic_year_id', $offering->academic_year_id)
            ->where('id', $offering->academic_period_id)
            ->value('status');

        if ($status !== null && strtolower((string) $status) === 'cancelled') {
            throw new DomainException('This Course Allocation cannot start because its Academic Period has been cancelled. Update the Course Allocation to a valid Academic Period through the governed Academic Years & Periods workflow.');
        }
    }

    public function complete(int $schoolId, int $offeringId): CourseOffering
    {
        return $this->transition($schoolId, $offeringId, CourseOffering::STATUS_IN_PROGRESS, CourseOffering::STATUS_COMPLETED, function (CourseOffering $offering): void {
            // Teaching cannot be completed before it has even begun; whether completion
            // should also require the Academic Period end date to have passed is a
            // separate, ambiguous policy question left for explicit product sign-off.
            $this->assertAcademicPeriodHasBegun($offering);
            $this->assertReadyForCompletion($offering);
        }, 'COURSE_OFFERING_COMPLETED');
    }

    /** Institutional correctness: teaching cannot start or complete before its Academic Period begins. */
    private function assertAcademicPeriodHasBegun(CourseOffering $offering): void
    {
        $period = DB::table('academic_periods')->where('school_id', $offering->school_id)
            ->where('academic_year_id', $offering->academic_year_id)->where('id', $offering->academic_period_id)
            ->first(['label', 'start_date']);
        if (! $period || ! $period->start_date) {
            return;
        }
        $startDate = \Illuminate\Support\Carbon::parse($period->start_date);
        if (now()->startOfDay()->lt($startDate->startOfDay())) {
            throw new DomainException("This Course Allocation cannot start before {$period->label} begins on {$startDate->format('j F Y')}.");
        }
    }

    /** Completion must not silently leave contradictory teaching/registration state. */
    private function assertReadyForCompletion(CourseOffering $offering): void
    {
        $this->assertTeachingTeamEnded($offering);
        $this->assertRegistrationsResolved($offering);
    }

    /**
     * A Course Allocation cannot be completed while lecturers are still allocated to
     * it. Allocations are never ended automatically: the administrator ends them
     * from the Teaching Team page, so the allocation history stays an accurate
     * record of who taught the Course Unit.
     */
    private function assertTeachingTeamEnded(CourseOffering $offering): void
    {
        if (! Schema::hasTable('course_offering_lecturer_allocations')
            || ! Schema::hasColumn('course_offering_lecturer_allocations', 'course_offering_id')) {
            return;
        }
        $active = DB::table('course_offering_lecturer_allocations')->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereIn('status', ['planned', 'active'])->count();
        if ($active > 0) {
            $noun = \Illuminate\Support\Str::plural('allocation', $active);
            $verb = $active === 1 ? 'remains' : 'remain';
            throw new DomainException("This Course Allocation cannot be completed yet: {$active} lecturer {$noun} {$verb} active. End the remaining teaching team allocations first, from the Teaching Team page.");
        }
    }

    private function assertRegistrationsResolved(CourseOffering $offering): void
    {
        if (! Schema::hasTable('course_registrations') || ! Schema::hasColumn('course_registrations', 'course_offering_id')) {
            return;
        }
        $unresolved = DB::table('course_registrations')->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)->where('status', 'registered')->count();
        if ($unresolved > 0) {
            $noun = \Illuminate\Support\Str::plural('registration', $unresolved);
            $verb = $unresolved === 1 ? 'requires' : 'require';
            throw new DomainException("This Course Allocation cannot be completed yet: {$unresolved} student {$noun} still {$verb} confirmation or withdrawal.");
        }
    }

    public function cancel(int $schoolId, int $offeringId, string $reason): CourseOffering
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A nonblank cancellation reason is required.');
        }

        return DB::transaction(function () use ($schoolId, $offeringId, $reason): CourseOffering {
            $offering = $this->offering($schoolId, $offeringId, false, true);
            if (! in_array($offering->status, [CourseOffering::STATUS_DRAFT, CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true)) {
                throw new DomainException('A Course Allocation in this state cannot be cancelled.');
            }
            $before = ['status' => $offering->status];
            DB::table('course_offerings')->where('school_id', $schoolId)->where('id', $offeringId)
                ->update(['status' => CourseOffering::STATUS_CANCELLED, 'updated_at' => now()]);
            $offering->refresh();
            $this->audit('COURSE_OFFERING_CANCELLED', $offering, $before, ['status' => $offering->status, 'reason' => $reason]);

            return $offering;
        });
    }

    private function transition(int $schoolId, int $offeringId, string $from, string $to, ?callable $beforeTransition, string $event): CourseOffering
    {
        return DB::transaction(function () use ($schoolId, $offeringId, $from, $to, $beforeTransition, $event): CourseOffering {
            $offering = $this->offering($schoolId, $offeringId, false, true);
            if ($offering->status !== $from) {
                throw new DomainException('Only a Course Allocation in the '.self::statusLabel($from).' state can be moved to '.self::statusLabel($to).'.');
            }
            if ($beforeTransition) {
                $beforeTransition($offering);
            }
            DB::table('course_offerings')->where('school_id', $schoolId)->where('id', $offeringId)
                ->update(['status' => $to, 'updated_at' => now()]);
            $offering->refresh();
            $this->audit($event, $offering, ['status' => $from], ['status' => $to]);

            return $offering;
        });
    }

    private function assertAllocationIdentity(int $schoolId, int $subjectId, int $yearId, int $periodId, ?string $reference, ?int $ignoreOfferingId = null): void
    {
        if (! DB::table('subjects')->where('school_id', $schoolId)->where('id', $subjectId)->exists()) {
            throw new DomainException('The selected Course Unit does not belong to this institution.');
        }
        $year = DB::table('academic_years')->where('school_id', $schoolId)->where('id', $yearId)->first();
        if (! $year) {
            throw new DomainException('The selected Academic Year does not belong to this institution.');
        }
        $period = DB::table('academic_periods')->where('school_id', $schoolId)->where('academic_year_id', $yearId)->where('id', $periodId)->first();
        if (! $period) {
            throw new DomainException('The selected Academic Period does not belong to this institution or the chosen Academic Year.');
        }
        if ($reference !== null && (mb_strlen($reference) > 50 || DB::table('course_offerings')->where('school_id', $schoolId)->where('reference', $reference)->when($ignoreOfferingId, fn ($query) => $query->where('id', '<>', $ignoreOfferingId))->exists())) {
            throw new DomainException('The optional Allocation reference must be at most 50 characters and unique within the tenant.');
        }
    }

    private function normalizeReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }
        $reference = trim($reference);
        return $reference === '' ? null : $reference;
    }

    /**
     * The server-side, authoritative reference for an identity.
     *
     * $ignoreOfferingId excludes an Allocation from the collision check so an
     * update can recompute its own reference without colliding with the row it
     * is replacing. Collisions are resolved with the established parallel-
     * delivery suffix (-2, -3, ...), not by overwriting or rejecting.
     */
    private function generatedReference(int $schoolId, int $subjectId, int $yearId, int $periodId, ?int $ignoreOfferingId = null): string
    {
        $subject = DB::table('subjects')->where('school_id', $schoolId)->where('id', $subjectId)->first(['code']);
        $year = DB::table('academic_years')->where('school_id', $schoolId)->where('id', $yearId)->first(['start_date']);
        $period = DB::table('academic_periods')->where('school_id', $schoolId)->where('academic_year_id', $yearId)->where('id', $periodId)->first(['type', 'sequence']);

        if (! $subject || ! $year || ! $period) {
            throw new DomainException('A valid Course Unit, Academic Year and Academic Period are required to generate the Course Allocation reference.');
        }

        $code = strtoupper(trim((string) $subject->code));
        $code = trim((string) preg_replace('/[^A-Z0-9]+/', '-', $code), '-');
        if ($code === '') {
            $code = 'CU'.$subjectId;
        }
        $startYear = date('Y', strtotime((string) $year->start_date));
        $type = strtolower((string) $period->type);
        $abbreviation = match ($type) {
            'semester' => 'S',
            'term' => 'T',
            default => strtoupper(substr((string) preg_replace('/[^A-Z0-9]/i', '', $type), 0, 2)) ?: 'P',
        };
        $periodPart = $abbreviation.(int) $period->sequence;
        $tail = '-'.$startYear.'-'.$periodPart;

        for ($ordinal = 1; ; $ordinal++) {
            $suffix = $ordinal === 1 ? '' : '-'.$ordinal;
            $availableCodeLength = max(1, 50 - strlen($tail) - strlen($suffix));
            $candidate = substr($code, 0, $availableCodeLength).$tail.$suffix;
            if (! DB::table('course_offerings')
                ->where('school_id', $schoolId)
                ->where('reference', $candidate)
                ->when($ignoreOfferingId, fn ($query) => $query->where('id', '<>', $ignoreOfferingId))
                ->exists()) {
                return $candidate;
            }
        }
    }

    private function validateApplicability(CourseOffering $offering, int $membershipId): CurriculumMembership
    {
        $membership = CurriculumMembership::where('school_id', $offering->school_id)->whereKey($membershipId)->first();
        if (! $membership) {
            throw new DomainException('This Study Plan entry does not belong to this institution.');
        }
        if ((int) $membership->subject_id !== (int) $offering->subject_id) {
            throw new DomainException('This Study Plan entry is for a different Course Unit.');
        }
        $curriculum = Curriculum::where('school_id', $offering->school_id)->whereKey($membership->curriculum_id)->first();
        if (! $curriculum || $curriculum->status !== 'approved') {
            throw new DomainException('Only an approved Study Plan may be linked to this Course Allocation.');
        }
        $academicYear = DB::table('academic_years')->where('school_id', $offering->school_id)->where('id', $offering->academic_year_id)->first();
        if (! $academicYear) {
            throw new DomainException('This Course Allocation has an invalid Academic Year. The academic office should review it.');
        }
        if ($curriculum->effective_academic_year_id !== null) {
            $effectiveYear = DB::table('academic_years')->where('school_id', $offering->school_id)->where('id', $curriculum->effective_academic_year_id)->first();
            if (! $effectiveYear || $academicYear->start_date < $effectiveYear->start_date) {
                throw new DomainException('This Study Plan is not yet effective for the Allocation Academic Year.');
            }
        }
        $period = DB::table('academic_periods')->where('school_id', $offering->school_id)
            ->where('academic_year_id', $offering->academic_year_id)->where('id', $offering->academic_period_id)->first();
        if (! $period || $membership->period_type === null || $membership->period_sequence === null) {
            throw new DomainException('This Study Plan entry must have a placement, and the Allocation must have a valid Academic Period.');
        }
        if ($membership->period_type !== $period->type || (int) $membership->period_sequence !== (int) $period->sequence) {
            throw new DomainException('This Study Plan entry is not scheduled for the Allocation Academic Period.');
        }

        return $membership;
    }

    private function offering(int $schoolId, int $offeringId, bool $draftOnly, bool $lock = false): CourseOffering
    {
        $query = CourseOffering::where('school_id', $schoolId)->whereKey($offeringId);
        if ($lock) {
            $query->lockForUpdate();
        }
        $offering = $query->first();
        if (! $offering) {
            throw new DomainException('This Course Allocation could not be found in this institution.');
        }
        if ($draftOnly && $offering->status !== CourseOffering::STATUS_DRAFT) {
            throw new DomainException('Only a draft Course Allocation can be changed in this way.');
        }

        return $offering;
    }

    private function audit(string $action, CourseOffering $offering, array $old, array $new): void
    {
        AuditLog::record($action, 'Course Allocations', "{$action} for Course Allocation #{$offering->id}.", [
            'school_id' => $offering->school_id,
            'record_type' => CourseOffering::class,
            'record_id' => $offering->id,
            'event_type' => 'COURSE_OFFERING',
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
        ]);
    }

    private function validateRegistrationIdentity(CourseOffering $offering, CourseOffering $candidate): void
    {
        if (! Schema::hasTable('course_registrations') || ! Schema::hasColumn('course_registrations', 'course_offering_id')) {
            return;
        }
        $registrations = DB::table('course_registrations')->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)->get();
        foreach ($registrations as $registration) {
            if (isset($registration->curriculum_membership_id) && $registration->curriculum_membership_id !== null) {
                $membership = $this->validateApplicability($candidate, (int) $registration->curriculum_membership_id);
                $updates = [];
                if (Schema::hasColumn('course_registrations', 'subject_id')) $updates['subject_id'] = $candidate->subject_id;
                if (Schema::hasColumn('course_registrations', 'registered_classification')) $updates['registered_classification'] = $membership->classification;
                if (Schema::hasColumn('course_registrations', 'registered_credits')) $updates['registered_credits'] = $membership->credits;
                if ($updates) {
                    DB::table('course_registrations')->where('school_id', $offering->school_id)->where('id', $registration->id)->update($updates);
                }
            } elseif ((int) ($registration->subject_id ?? 0) !== (int) $candidate->subject_id) {
                throw new DomainException('This identity change would make an existing registration inconsistent. Resolve its Study Plan placement before editing the Allocation.');
            }
        }
    }

    private function syncDenormalizedIdentity(int $schoolId, int $offeringId, int $subjectId, int $yearId, int $periodId): void
    {
        foreach (Schema::getTableListing() as $table) {
            if ($table === 'course_offerings' || $table === 'course_registrations'
                || ! Schema::hasColumn($table, 'course_offering_id') || ! Schema::hasColumn($table, 'subject_id')) continue;
            $updates = ['subject_id' => $subjectId];
            if (Schema::hasColumn($table, 'academic_year_id')) $updates['academic_year_id'] = $yearId;
            if (Schema::hasColumn($table, 'academic_period_id')) $updates['academic_period_id'] = $periodId;
            $query = DB::table($table)->where('course_offering_id', $offeringId);
            if (Schema::hasColumn($table, 'school_id')) $query->where('school_id', $schoolId);
            $query->update($updates);
        }
    }

    /** Delete allocation-owned rows and FK descendants, leaf-first, in caller transaction. */
    private function deleteAllocationWorkflowRows(int $schoolId, int $offeringId): void
    {
        $tables = array_map(static fn (string $table): string => substr(strrchr('.'.$table, '.'), 1), Schema::getTableListing());
        $rowsByTable = [];
        $dependencies = [];
        foreach ($tables as $table) {
            if ($table === 'course_offerings' || ! Schema::hasColumn($table, 'course_offering_id')) continue;
            $query = DB::table($table)->where('course_offering_id', $offeringId);
            if (Schema::hasColumn($table, 'school_id')) $query->where('school_id', $schoolId);
            $rows = $query->get();
            if ($rows->isNotEmpty()) $rowsByTable[$table] = $rows->all();
        }

        // Legacy workflow tables intentionally do not all declare constraints.
        // Keep those allocation-owned relationships explicit so SQLite test
        // schemas and older production schemas receive the same safe cleanup.
        $knownChildren = [
            'course_offering_attendance_records' => [['course_offering_attendance_sessions', 'attendance_session_id'], ['course_registrations', 'course_registration_id']],
            'live_class_materials' => [['live_classes', 'live_class_id']],
            'live_class_attendances' => [['live_classes', 'live_class_id']],
            'live_class_notifications' => [['live_classes', 'live_class_id']],
            'live_class_meet_guests' => [],
            'course_offering_lesson_resources' => [['course_offering_lessons', 'course_offering_lesson_id']],
            'course_offering_lesson_progress' => [['course_offering_lessons', 'course_offering_lesson_id']],
            'assignment_submissions' => [['assignments', 'assignment_id']],
            'assignment_resources' => [['assignments', 'assignment_id']],
            'assignment_notifications' => [['assignments', 'assignment_id']],
            'assignment_submission_items' => [['assignment_submissions', 'assignment_submission_id'], ['assignments', 'assignment_id']],
            'assignment_questions' => [['assignments', 'assignment_id']],
            'assignment_question_responses' => [['assignment_questions', 'assignment_question_id'], ['assignment_submissions', 'assignment_submission_id'], ['assignments', 'assignment_id']],
            'online_exam_questions' => [['online_exams', 'online_exam_id']],
            'online_exam_submissions' => [['online_exams', 'online_exam_id']],
            'online_exam_notifications' => [['online_exams', 'online_exam_id']],
            'online_exam_user_notifications' => [['online_exams', 'online_exam_id'], ['online_exam_submissions', 'submission_id']],
        ];

        // Follow only child -> owned parent relationships. This reaches workflow
        // rows such as attendance records and submission evidence which do not
        // carry course_offering_id themselves, while never walking upward into
        // shared Course Units, curricula, students, staff or schools.
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($tables as $childTable) {
                if ($childTable === 'course_offerings') continue;
                $relations = [];
                foreach (Schema::getForeignKeys($childTable) as $foreignKey) {
                    $relations[] = [$foreignKey['foreign_table'] ?? null, $foreignKey['columns'] ?? [], $foreignKey['foreign_columns'] ?? []];
                }
                foreach ($knownChildren[$childTable] ?? [] as [$parent, $column]) {
                    $relations[] = [$parent, [$column], ['id']];
                }
                foreach ($relations as [$parent, $localColumns, $foreignColumns]) {
                    if (! $parent || ! isset($rowsByTable[$parent])) continue;
                    if (! $localColumns || count($localColumns) !== count($foreignColumns)) continue;
                    $query = DB::table($childTable)->where(function ($where) use ($rowsByTable, $parent, $localColumns, $foreignColumns): void {
                        foreach ($rowsByTable[$parent] as $parentRow) {
                            $values = [];
                            foreach ($foreignColumns as $index => $column) $values[$localColumns[$index]] = $parentRow->{$column};
                            $where->orWhere(function ($match) use ($values): void {
                                foreach ($values as $column => $value) $match->where($column, $value);
                            });
                        }
                    });
                    if (Schema::hasColumn($childTable, 'school_id')) $query->where('school_id', $schoolId);
                    $rows = $query->get();
                    if ($rows->isNotEmpty()) {
                        $dependencies[$parent][] = $childTable;
                        $fingerprint = static fn ($row): string => isset($row->id) ? 'id:'.$row->id : 'row:'.hash('sha256', serialize($row));
                        $knownRows = collect($rowsByTable[$childTable] ?? [])->keyBy($fingerprint);
                        foreach ($rows as $row) {
                            $key = $fingerprint($row);
                            if (! $knownRows->has($key)) {
                                $knownRows->put($key, $row);
                                $changed = true;
                            }
                        }
                        $rowsByTable[$childTable] = $knownRows->values()->all();
                    }
                }
            }
        }

        // A parent may itself have been discovered before its child, or both
        // may have a direct offering_id. Topologically order the collected graph
        // so every child is removed before each parent in either case.
        $orderedTables = [];
        $visited = [];
        $visit = function (string $table) use (&$visit, &$orderedTables, &$visited, $dependencies, $rowsByTable): void {
            if (isset($visited[$table])) return;
            $visited[$table] = true;
            foreach (array_unique($dependencies[$table] ?? []) as $child) {
                if (isset($rowsByTable[$child])) $visit($child);
            }
            $orderedTables[] = $table;
        };
        foreach (array_keys($rowsByTable) as $table) $visit($table);

        foreach ($orderedTables as $table) {
            if (! Schema::hasColumn($table, 'id')) {
                $columns = Schema::getColumnListing($table);
                $query = DB::table($table);
                if (Schema::hasColumn($table, 'school_id')) $query->where('school_id', $schoolId);
                $query->where(function ($or) use ($rowsByTable, $table, $columns): void {
                    foreach ($rowsByTable[$table] as $row) $or->orWhere(function ($and) use ($row, $columns): void {
                        foreach ($columns as $column) {
                            $value = $row->{$column} ?? null;
                            $value === null ? $and->whereNull($column) : $and->where($column, $value);
                        }
                    });
                });
                $query->delete();
                continue;
            }
            $ids = array_values(array_unique(array_map(fn ($row) => $row->id, $rowsByTable[$table])));
            $query = DB::table($table)->whereIn('id', $ids);
            if (Schema::hasColumn($table, 'school_id')) $query->where('school_id', $schoolId);
            $query->delete();
        }
    }

    /** A deletion/official identity audit is mandatory and rolls back the mutation if it cannot be stored. */
    private function recordRequiredAudit(string $action, CourseOffering $offering, int $actorId, array $old, array $new = []): void
    {
        $actor = \App\Models\User::query()->find($actorId);
        AuditLog::create([
            'school_id' => $offering->school_id,
            'user_id' => $actorId,
            'user_name' => $actor?->name,
            'role_id' => $actor?->role_id,
            'role_name' => AuditLog::roleName($actor?->role_id),
            'action' => $action,
            'event_type' => 'COURSE_OFFERING',
            'module' => 'Course Allocations',
            'route_name' => \Illuminate\Support\Facades\Route::currentRouteName(),
            'url' => request()->fullUrl(),
            'method' => request()->method(),
            'description' => "{$action} for Course Allocation #{$offering->id}.",
            'record_type' => CourseOffering::class,
            'record_id' => $offering->id,
            'old_values' => $old,
            'new_values' => $new ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'status' => 'success',
            'created_at' => now(),
        ]);
    }
}
