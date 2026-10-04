<?php

namespace App\Support\LiveClasses;

use App\Models\AuditLog;
use App\Models\CourseOffering;
use App\Models\LiveClass;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Authoritative foundation operations for Offering-backed Live Classes.
 * Lecturer-allocation and participant authorization are intentionally handled
 * by a later integration phase.
 */
class LiveClassService
{
    private const MEETING_FIELDS = [
        'title', 'description', 'teacher_id', 'platform', 'meeting_url',
        'meeting_id', 'meeting_password', 'scheduled_at', 'ends_at',
        'start_date', 'start_time', 'end_time', 'timezone', 'status',
        'is_published', 'attendance_enabled', 'recording_url',
        // Google Calendar bookkeeping. Absent here, meetingAttributes() silently
        // dropped these, so an Offering-backed Google class was saved with no event
        // id and no conference state - the class existed, but PIIE had no handle on
        // the calendar entry behind it. Listed LAST because the caller only adds
        // them when it has something to record; the intersect simply skips them
        // otherwise, which keeps fixtures that do not define these columns working.
        'google_calendar_event_id', 'google_conference_status',
    ];

    private const RESERVED_CONTEXT_FIELDS = [
        'school_id', 'course_offering_id', 'programme_id', 'academic_session_id',
        'academic_year_id', 'academic_period_id', 'curriculum_id',
        'curriculum_membership_id', 'teaching_group_id',
    ];

    public function createForOffering(User $actor, int $courseOfferingId, array $attributes): LiveClass
    {
        $this->assertContextInput($attributes);
        $schoolId = (int) $actor->school_id;
        if ($schoolId <= 0) {
            throw new DomainException('An authenticated tenant context is required to create a Live Class.');
        }

        return DB::transaction(function () use ($actor, $schoolId, $courseOfferingId, $attributes): LiveClass {
            $offering = $this->lockOffering($schoolId, $courseOfferingId);
            $this->assertOperationalOffering($offering);

            if (array_key_exists('subject_id', $attributes)
                && (int) $attributes['subject_id'] !== (int) $offering->subject_id) {
                throw new DomainException('The supplied subject conflicts with the Course Offering subject.');
            }

            if (! empty($attributes['teacher_id'])) {
                $this->assertFacilitatorAllocation($offering, (int) $attributes['teacher_id'], $attributes);
            }

            $payload = $this->meetingAttributes($attributes) + [
                'school_id' => $offering->school_id,
                'course_offering_id' => $offering->id,
                'subject_id' => $offering->subject_id,
                'programme_id' => null,
                'academic_session_id' => null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ];

            $liveClass = LiveClass::create($payload);
            $this->recordAudit('create', $liveClass, null, $liveClass->only(['course_offering_id', 'subject_id']));

            return $liveClass->setRelation('courseOffering', $offering);
        });
    }

    /** Update meeting details without allowing academic-context reassignment. */
    public function updateOfferingMeeting(User $actor, int $liveClassId, array $attributes): LiveClass
    {
        $this->assertContextInput($attributes);
        $schoolId = (int) $actor->school_id;

        $outcome = DB::transaction(function () use ($actor, $schoolId, $liveClassId, $attributes): array {
            $identity = LiveClass::query()->where('school_id', $schoolId)->whereKey($liveClassId)->first();
            if (! $identity || ! $identity->course_offering_id) {
                throw new DomainException('The Offering-backed Live Class was not found for this tenant.');
            }

            $offering = $this->lockOffering($schoolId, (int) $identity->course_offering_id);
            $this->assertOperationalOffering($offering);
            if (array_key_exists('subject_id', $attributes)
                && (int) $attributes['subject_id'] !== (int) $offering->subject_id) {
                throw new DomainException('The supplied subject conflicts with the Course Offering subject.');
            }
            if (! empty($attributes['teacher_id'])) {
                $this->assertFacilitatorAllocation($offering, (int) $attributes['teacher_id'], $attributes);
            }
            $liveClass = LiveClass::query()->where('school_id', $schoolId)->whereKey($liveClassId)->lockForUpdate()->firstOrFail();
            $before = $liveClass->only(['course_offering_id', 'subject_id', 'scheduled_at', 'ends_at', 'status']);

            // Held outside the audited field list on purpose: a recording link
            // is a resource URL, and the audit trail should record that one
            // changed, not reproduce the link itself.
            $previousRecordingUrl = $liveClass->recording_url;
            $previousScheduledAt = $liveClass->scheduled_at?->copy();

            $liveClass->fill($this->meetingAttributes($attributes));
            $liveClass->updated_by = $actor->id;
            $liveClass->save();
            $this->recordAudit('update', $liveClass, $before, $liveClass->only(['course_offering_id', 'subject_id', 'scheduled_at', 'ends_at', 'status']));

            return [
                'live_class' => $liveClass->setRelation('courseOffering', $offering),
                'previous_scheduled_at' => $previousScheduledAt,
                'previous_recording_url' => $previousRecordingUrl,
            ];
        });

        $this->announceScheduleOrRecordingChange(
            $outcome['live_class'],
            $outcome['previous_scheduled_at'],
            $outcome['previous_recording_url']
        );

        return $outcome['live_class'];
    }

    /**
     * Student-facing follow-up for a meeting edit, run AFTER the row has
     * committed.
     *
     * Deliberately outside the transaction above. A notification is a courtesy,
     * not part of the academic record: if delivery fails the Live Class must
     * still stand, and putting it inside the transaction would let one bad
     * mail address discard a lecturer's scheduled class. LiveClassNotifier
     * already contains per-recipient failures internally.
     *
     * Both events are only meaningful when the time or the recording actually
     * changed, so an unchanged re-save stays silent - which is what stops a
     * lecturer clicking Save on a form from spamming a cohort.
     */
    private function announceScheduleOrRecordingChange(
        LiveClass $liveClass,
        ?Carbon $previousScheduledAt,
        ?string $previousRecordingUrl
    ): void {
        $currentScheduledAt = $liveClass->scheduled_at?->copy();

        $scheduleChanged = $previousScheduledAt === null
            ? $currentScheduledAt !== null
            : (string) $previousScheduledAt !== (string) $currentScheduledAt;

        if ($scheduleChanged) {
            LiveClassNotifier::announceRescheduled($liveClass);
        }

        $recordingChanged = trim((string) $previousRecordingUrl) !== trim((string) $liveClass->recording_url)
            && trim((string) $liveClass->recording_url) !== '';

        if ($recordingChanged) {
            LiveClassNotifier::announceRecording($liveClass);
        }
    }

    /**
     * End a class the lecturer has finished teaching.
     *
     * Distinct from cancel and from unpublish, and this is the point of it:
     *
     *  - unpublish withdraws the class from students entirely;
     *  - cancel says the class will not run;
     *  - end says the class DID run and is now over.
     *
     * It only records the fact. It writes no attendance, awards no credit and
     * sends no notification, because official attendance is the certified
     * Course Offering Attendance workflow and stays lecturer-controlled. The row
     * and its history are preserved - nothing is deleted.
     */
    public function endMeeting(User $actor, int $liveClassId): LiveClass
    {
        $schoolId = (int) $actor->school_id;

        return DB::transaction(function () use ($schoolId, $liveClassId, $actor): LiveClass {
            $liveClass = LiveClass::query()
                ->where('school_id', $schoolId)
                ->whereKey($liveClassId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($liveClass->status === LiveClass::STATUS_CANCELLED) {
                throw new DomainException('A cancelled Live Class cannot be ended.');
            }

            // The lifecycle table decides whether the move exists, so a second
            // press cannot overwrite the first press's author and timestamp.
            if (! $liveClass->canTransitionTo(LiveClass::STATUS_ENDED)) {
                throw new DomainException('This Live Class has already been concluded.');
            }

            $before = ['status' => $liveClass->status];
            $liveClass->status = LiveClass::STATUS_ENDED;
            $liveClass->ended_at = now();
            $liveClass->ended_by = $actor->id;
            $liveClass->updated_by = $actor->id;
            // started_at / started_by are deliberately NOT filled in here. A
            // class can be concluded without anyone having opened it, and the
            // honest record of "we do not know when this was opened" is NULL -
            // not a guess derived from when it was closed.
            $liveClass->save();

            $this->recordAudit('update', $liveClass, $before, [
                'status' => $liveClass->status,
                'ended_at' => (string) $liveClass->ended_at,
                'ended_by' => $liveClass->ended_by,
            ]);

            return $liveClass;
        });
    }

    /** Cancellation preserves the Live Class row and its academic history. */
    public function cancelOfferingMeeting(User $actor, int $liveClassId): LiveClass
    {
        $schoolId = (int) $actor->school_id;

        return DB::transaction(function () use ($actor, $schoolId, $liveClassId): LiveClass {
            $identity = LiveClass::query()->where('school_id', $schoolId)->whereKey($liveClassId)->first();
            if (! $identity || ! $identity->course_offering_id) {
                throw new DomainException('The Offering-backed Live Class was not found for this tenant.');
            }

            $this->lockOffering($schoolId, (int) $identity->course_offering_id);
            $liveClass = LiveClass::query()->where('school_id', $schoolId)->whereKey($liveClassId)->lockForUpdate()->firstOrFail();
            $before = ['status' => $liveClass->status];
            $liveClass->status = LiveClass::STATUS_CANCELLED;
            $liveClass->updated_by = $actor->id;
            $liveClass->save();
            $this->recordAudit('update', $liveClass, $before, ['status' => $liveClass->status]);

            return $liveClass;
        });
    }

    private function lockOffering(int $schoolId, int $offeringId): CourseOffering
    {
        $offering = CourseOffering::query()
            ->where('school_id', $schoolId)
            ->whereKey($offeringId)
            ->lockForUpdate()
            ->first();

        if (! $offering) {
            throw new DomainException('The selected Course Offering does not belong to the authenticated tenant.');
        }

        if (! DB::table('subjects')->where('school_id', $schoolId)->where('id', $offering->subject_id)->exists()) {
            throw new DomainException('The Course Offering subject does not belong to the authenticated tenant.');
        }

        return $offering;
    }

    private function assertOperationalOffering(CourseOffering $offering): void
    {
        if (! in_array($offering->status, [CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true)) {
            throw new DomainException('Live Classes may only be scheduled for open or in-progress Course Offerings.');
        }
    }

    /**
     * The facilitator must hold a current Primary/Co allocation, judged for
     * today AND for the meeting date.
     *
     * This deliberately delegates instead of re-deriving the rule.
     * LiveClassAccessService::activeManagerAllocationsForOffering() is the
     * single authority for "is this lecturer currently allocated to this
     * Offering", and it carries the shared SystemTesterAccess pre-start
     * exception. This method used to run its own raw
     * `starts_on <= $date` query, which duplicated that rule and silently
     * denied an authorised pre-start tester lecturer who the controller had
     * already allowed through - the request was approved and then refused at
     * the last step with a raw validation error. Delegating also subsumes the
     * old separate eligible-user check: that query already requires a lecturer
     * of the same tenant whose account is not disabled.
     */
    private function assertFacilitatorAllocation(CourseOffering $offering, int $teacherId, array $attributes): void
    {
        $today = now()->toDateString();
        $meetingDate = isset($attributes['scheduled_at'])
            ? Carbon::parse($attributes['scheduled_at'])->toDateString()
            : (isset($attributes['start_date']) ? Carbon::parse($attributes['start_date'])->toDateString() : $today);

        $isFacilitatorOn = fn (string $date): bool => app(LiveClassAccessService::class)
            ->activeManagerAllocationsForOffering($offering, Carbon::parse($date))
            ->contains(fn ($allocation) => (int) $allocation->user_id === $teacherId);

        if (! $isFacilitatorOn($today) || ! $isFacilitatorOn($meetingDate)) {
            throw new DomainException('The facilitator must have a current Primary or Co Lecturer allocation for this Offering and meeting date.');
        }
    }

    private function assertContextInput(array $attributes): void
    {
        foreach (self::RESERVED_CONTEXT_FIELDS as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] !== null && $attributes[$field] !== '') {
                throw new DomainException("{$field} is derived or reserved for Offering-backed Live Classes.");
            }
        }
    }

    private function meetingAttributes(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::MEETING_FIELDS));
    }

    private function recordAudit(string $action, LiveClass $liveClass, ?array $oldValues, array $newValues): void
    {
        AuditLog::record($action, 'Live Classes', ucfirst($action) . " Offering-backed Live Class: {$liveClass->title}", [
            'school_id' => $liveClass->school_id,
            'record_type' => LiveClass::class,
            'record_id' => $liveClass->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
