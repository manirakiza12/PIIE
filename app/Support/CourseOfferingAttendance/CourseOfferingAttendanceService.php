<?php

namespace App\Support\CourseOfferingAttendance;

use App\Models\CourseOffering;
use App\Models\CourseOfferingAttendanceRecord;
use App\Models\CourseOfferingAttendanceSession;
use App\Models\CourseRegistration;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use App\Support\CourseRegistration\CourseOfferingRoster;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The single authority for HEI Course Offering attendance.
 *
 * Authorization is never restated here: it is delegated to
 * LecturerCourseOfferingAccess, so a lecturer can only ever reach a Course
 * Offering they are genuinely allocated to, in their own institution. This
 * service adds only the attendance-specific rules on top:
 *
 *   - the roster is the Offering's CONFIRMED course registrations, never a
 *     Programme, Cohort or Class roster;
 *   - a record must belong to a registration of the same Offering, and its
 *     student_id must match that registration;
 *   - one record per registration per session, so a repeated bulk submission is
 *     idempotent rather than duplicating;
 *   - marking requires a DRAFT session, so a finalised register is not silently
 *     rewritten;
 *   - a session belongs to one Offering and one tenant, enforced by the database
 *     and re-asserted here.
 *
 * Nothing here hard-deletes: attendance is an academic record, and correction
 * happens by reopening a session.
 */
class CourseOfferingAttendanceService
{
    public function __construct(
        private readonly LecturerCourseOfferingAccess $access,
        private readonly CourseOfferingRoster $roster,
        private readonly \App\Support\CourseOffering\SystemTesterAccess $testers,
    ) {
    }

    // ------------------------------------------------------------- sessions

    /**
     * Create one teaching occurrence. The Offering must be in progress: a
     * prepared Offering may be planned for, but a register records teaching that
     * has actually happened, and a closed or cancelled Offering takes no new
     * sessions.
     */
    public function createSession(
        User $lecturer,
        int $offeringId,
        string $sessionDate,
        ?string $startsAt = null,
        ?string $endsAt = null,
        ?string $type = null,
        ?string $topic = null,
        ?int $liveClassId = null,
    ): CourseOfferingAttendanceSession {
        $offering = $this->activeOffering($lecturer, $offeringId);
        $this->assertOfferingAcceptsTeaching($offering);
        $type = CourseOfferingAttendanceRules::assertType($type ?? CourseOfferingAttendanceSessionType::LECTURE);
        CourseOfferingAttendanceRules::assertTimes($startsAt, $endsAt);
        $this->assertDateWithinPeriod($offering, $sessionDate, $lecturer);
        $liveClass = $liveClassId === null ? null : $this->assertLiveClassBelongsToOffering($offering, $liveClassId);

        return DB::transaction(function () use ($lecturer, $offering, $sessionDate, $startsAt, $endsAt, $type, $topic, $liveClass): CourseOfferingAttendanceSession {
            // Serialise session creation per Offering so the untimed duplicate
            // rule below is deterministic rather than a read-then-write race.
            DB::table('course_offerings')
                ->where('school_id', $offering->school_id)->where('id', $offering->id)
                ->lockForUpdate()->first();

            $this->assertNoDuplicateSession($offering, $sessionDate, $startsAt);

            $id = DB::table('course_offering_attendance_sessions')->insertGetId([
                'school_id' => $offering->school_id,
                'course_offering_id' => $offering->id,
                'session_date' => $sessionDate,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'type' => $type,
                'topic' => $topic === null ? null : trim($topic),
                'live_class_id' => $liveClass?->id,
                'recorded_by_user_id' => $lecturer->id,
                'status' => CourseOfferingAttendanceSessionStatus::DRAFT,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $session = CourseOfferingAttendanceSession::where('school_id', $offering->school_id)->findOrFail($id);
            $this->audit('COURSE_OFFERING_ATTENDANCE_SESSION_CREATED', $session, [], $this->sessionValues($session));

            return $session;
        });
    }

    /** Sessions of one Offering, newest first, with mark counts attached. */
    public function sessionsForOffering(CourseOffering $offering): Collection
    {
        $sessions = CourseOfferingAttendanceSession::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->orderByDesc('session_date')
            ->orderByDesc('id')
            ->get();

        $counts = DB::table('course_offering_attendance_records')
            ->where('school_id', $offering->school_id)
            ->whereIn('attendance_session_id', $sessions->pluck('id')->all() ?: [0])
            ->groupBy('attendance_session_id')
            ->selectRaw('attendance_session_id, COUNT(*) as total, SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as attended',
                [CourseOfferingAttendanceStatus::PRESENT, CourseOfferingAttendanceStatus::LATE])
            ->get()
            ->keyBy('attendance_session_id');

        return $sessions->each(function (CourseOfferingAttendanceSession $session) use ($counts): CourseOfferingAttendanceSession {
            $row = $counts->get($session->id);
            $session->setAttribute('marked_count', (int) ($row->total ?? 0));
            $session->setAttribute('attended_count', (int) ($row->attended ?? 0));

            return $session;
        });
    }

    // -------------------------------------------------------------- marking

    /**
     * Apply one student's status. Idempotent for a repeated submission of the
     * same session and registration.
     */
    public function mark(
        User $lecturer,
        CourseOfferingAttendanceSession $session,
        CourseRegistration $registration,
        int $status,
    ): CourseOfferingAttendanceRecord {
        $this->assertMarkingAllowed($lecturer, $session);
        $this->assertRegistrationMatchesSession($session, $registration);
        if (! CourseOfferingAttendanceStatus::isValid($status)) {
            throw new DomainException('Choose a valid attendance status.');
        }

        return DB::transaction(function () use ($lecturer, $session, $registration, $status): CourseOfferingAttendanceRecord {
            $existing = CourseOfferingAttendanceRecord::query()
                ->where('school_id', $session->school_id)
                ->where('attendance_session_id', $session->id)
                ->where('course_registration_id', $registration->id)
                ->lockForUpdate()
                ->first();

            $values = [
                'marked_by_user_id' => $lecturer->id,
                'marked_at' => now(),
                'updated_at' => now(),
            ];

            if ($existing) {
                if ($existing->status === $status) {
                    return $existing;
                }
                $existing->forceFill($values + ['status' => $status])->saveQuietly();

                return $existing;
            }

            $id = DB::table('course_offering_attendance_records')->insertGetId($values + [
                'school_id' => $session->school_id,
                'attendance_session_id' => $session->id,
                'course_registration_id' => $registration->id,
                'student_id' => (int) $registration->student_id,
                'status' => $status,
                'created_at' => now(),
            ]);

            return CourseOfferingAttendanceRecord::where('school_id', $session->school_id)->findOrFail($id);
        });
    }

    /**
     * Apply a whole register at once. Only the registrations supplied are
     * touched: a student who is absent from the payload is left unmarked, never
     * silently defaulted to absent.
     *
     * @param  array<int, array{course_registration_id:int, status:int}>  $marks
     */
    public function markBulk(User $lecturer, CourseOfferingAttendanceSession $session, array $marks): array
    {
        $registrations = $this->registrationsFor($session);
        $applied = 0;
        $skipped = 0;

        foreach ($marks as $mark) {
            $registrationId = (int) ($mark['course_registration_id'] ?? 0);
            $registration = $registrations->get($registrationId);
            if (! $registration) {
                $skipped++;

                continue;
            }
            $this->mark($lecturer, $session, $registration, (int) ($mark['status'] ?? CourseOfferingAttendanceStatus::ABSENT));
            $applied++;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * The teaching roster: this Offering's confirmed registrations only.
     * Programme, Cohort and Class rosters are never a substitute.
     */
    public function rosterFor(CourseOfferingAttendanceSession $session): Collection
    {
        $offering = $this->offeringForSession($session);
        $marked = $this->recordsForSession($session)->keyBy('course_registration_id');

        return $this->roster->registered($offering)
            ->where('status', CourseRegistration::STATUS_CONFIRMED)
            ->map(function ($registration) use ($marked) {
                $registration->setAttribute('marked_status', $marked->get((int) $registration->id)?->status);
                $registration->setAttribute('marked_at', $marked->get((int) $registration->id)?->marked_at);

                return $registration;
            })
            ->values();
    }

    public function recordsForSession(CourseOfferingAttendanceSession $session): Collection
    {
        return CourseOfferingAttendanceRecord::query()
            ->where('school_id', $session->school_id)
            ->where('attendance_session_id', $session->id)
            ->get();
    }

    // ---------------------------------------------------------- finalisation

    /**
     * Finalise a register. Blocked until every confirmed roster member has a
     * status, so a missing row can never be read as an absence. Finalising does
     * not end or alter the session's teaching; it only stops further marking.
     */
    public function finalise(User $lecturer, CourseOfferingAttendanceSession $session): CourseOfferingAttendanceSession
    {
        $this->assertMarkingAllowed($lecturer, $session);

        $roster = $this->rosterFor($session);
        $unmarked = $roster->filter(fn ($registration) => $registration->getAttribute('marked_status') === null)->count();
        if ($unmarked > 0) {
            $noun = \Illuminate\Support\Str::plural('student', $unmarked);

            throw new DomainException("Attendance cannot be finalised because {$unmarked} {$noun} ".($unmarked === 1 ? 'has' : 'have').' not yet been marked.');
        }

        return $this->transitionSession($lecturer, $session, CourseOfferingAttendanceSessionStatus::FINALISED, 'COURSE_OFFERING_ATTENDANCE_SESSION_FINALISED');
    }

    /**
     * Reopen a finalised register.
     *
     * RESERVED FOR FUTURE ADMIN/ACADEMIC OFFICE GOVERNANCE - NOT REACHABLE BY A
     * LECTURER. No lecturer route or view invokes this. A finalised academic
     * register is read-only to the lecturer who wrote it; correction belongs to a
     * separately governed permission and workflow that PIIE does not yet define
     * (ADMIN_ATTENDANCE_CORRECTION_GAP). Kept as a governed, audited seam for
     * that future workflow rather than a new code path.
     */
    public function reopenForGovernedCorrection(User $actor, CourseOfferingAttendanceSession $session): CourseOfferingAttendanceSession
    {
        $offering = $this->activeOffering($actor, (int) $session->course_offering_id);
        $this->assertSessionTenant($actor, $session, $offering);
        if ($session->status === CourseOfferingAttendanceSessionStatus::LOCKED) {
            throw new DomainException('A locked Attendance Session cannot be reopened.');
        }

        return $this->transitionSession($actor, $session, CourseOfferingAttendanceSessionStatus::DRAFT, 'COURSE_OFFERING_ATTENDANCE_SESSION_REOPENED');
    }

    // ------------------------------------------------------------- history

    /** Per-student summary across one Offering's sessions. Factual only. */
    public function historyForOffering(CourseOffering $offering): Collection
    {
        $sessions = CourseOfferingAttendanceSession::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->get(['id']);
        $sessionIds = $sessions->pluck('id')->all();

        $rows = $sessionIds === [] ? collect() : CourseOfferingAttendanceRecord::query()
            ->where('school_id', $offering->school_id)
            ->whereIn('attendance_session_id', $sessionIds)
            ->selectRaw('student_id, COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as present_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as late_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as absent_count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as excused_count',
                [
                    CourseOfferingAttendanceStatus::PRESENT,
                    CourseOfferingAttendanceStatus::LATE,
                    CourseOfferingAttendanceStatus::ABSENT,
                    CourseOfferingAttendanceStatus::EXCUSED,
                ])
            ->groupBy('student_id')
            ->get();

        return $rows->map(function ($row) use ($offering): object {
            $row->student_name = (string) (User::where('school_id', $offering->school_id)
                ->whereKey($row->student_id)->value('name') ?? 'Student');
            $row->student_number = (string) (User::where('school_id', $offering->school_id)
                ->whereKey($row->student_id)->value('code') ?? '');

            return $row;
        });
    }

    /** Factual per-student counts for one session, used by the register view. */
    public function summaryForSession(CourseOfferingAttendanceSession $session): array
    {
        $records = $this->recordsForSession($session);
        $summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
        foreach ($records as $record) {
            $key = strtolower(CourseOfferingAttendanceStatus::label($record->status));
            if (array_key_exists($key, $summary)) {
                $summary[$key]++;
            }
        }
        $summary['total'] = $records->count();

        return $summary;
    }

    /**
     * Live Class participation as evidence for a linked session. Never marks
     * anything: it is offered so a lecturer can see who actually joined, and
     * still decide the academic status.
     */
    public function liveClassEvidence(CourseOfferingAttendanceSession $session): Collection
    {
        if (! $session->live_class_id) {
            return collect();
        }

        return DB::table('live_class_attendances')
            ->where('school_id', $session->school_id)
            ->where('live_class_id', $session->live_class_id)
            ->orderBy('joined_at')
            ->get(['user_id', 'role_id', 'joined_at', 'left_at', 'duration_seconds']);
    }

    // ------------------------------------------------------------ internals

    /** The Offering, resolved through the Step 4 allocation boundary. */
    private function activeOffering(User $lecturer, int $offeringId): CourseOffering
    {
        $offering = $this->access->resolveForLecturer($lecturer, $offeringId);
        if (! $offering) {
            throw new DomainException('This Course Offering could not be found in this institution.');
        }

        return $offering;
    }

    /**
     * Only an in-progress Offering records teaching that has happened. A prepared
     * Offering cannot, and a closed or cancelled one takes nothing new.
     */
    private function assertOfferingAcceptsTeaching(CourseOffering $offering): void
    {
        if ($offering->status === CourseOffering::STATUS_IN_PROGRESS) {
            return;
        }
        $message = match ($offering->status) {
            CourseOffering::STATUS_DRAFT => 'Attendance can be recorded once this Course Offering is in progress.',
            CourseOffering::STATUS_OPEN => 'Attendance can be recorded once this Course Offering is in progress. Open means registration, not teaching.',
            CourseOffering::STATUS_COMPLETED => 'This Course Offering is completed; its attendance history is read-only.',
            CourseOffering::STATUS_CANCELLED => 'This Course Offering was cancelled; no attendance can be recorded.',
            default => 'Attendance cannot be recorded for this Course Offering.',
        };

        throw new DomainException($message);
    }

    private function assertSessionTenant(User $lecturer, CourseOfferingAttendanceSession $session, CourseOffering $offering): void
    {
        if ((int) $session->school_id !== (int) $lecturer->school_id
            || (int) $session->course_offering_id !== (int) $offering->id) {
            throw new DomainException('This Attendance Session does not belong to this Course Offering.');
        }
    }

    /** A session may only be marked by a current lecturer of a teaching Offering. */
    private function assertMarkingAllowed(User $lecturer, CourseOfferingAttendanceSession $session): void
    {
        $offering = $this->activeOffering($lecturer, (int) $session->course_offering_id);
        $this->assertSessionTenant($lecturer, $session, $offering);
        if (! $this->access->teachingActionsAllowed($offering)) {
            throw new DomainException('Attendance cannot be recorded for this Course Offering. A current lecturer allocation is required while the Course Offering is in progress.');
        }
        if (! $session->acceptsMarking()) {
            throw new DomainException("This Attendance Session is {$session->statusLabel()} and can no longer be changed by a lecturer.");
        }
    }

    private function assertRegistrationMatchesSession(CourseOfferingAttendanceSession $session, CourseRegistration $registration): void
    {
        if ((int) $registration->school_id !== (int) $session->school_id) {
            throw new DomainException('This Course Registration belongs to another institution.');
        }
        if (! $this->registrationsFor($session)->has((int) $registration->id)) {
            throw new DomainException('This Course Registration is not a confirmed registration for this Course Offering.');
        }
    }

    /** Confirmed registrations of the session's Offering, keyed by id. */
    private function registrationsFor(CourseOfferingAttendanceSession $session): Collection
    {
        return CourseRegistration::query()
            ->where('school_id', $session->school_id)
            ->where('course_offering_id', $session->course_offering_id)
            ->where('status', CourseRegistration::STATUS_CONFIRMED)
            ->get()
            ->keyBy('id');
    }

    private function offeringForSession(CourseOfferingAttendanceSession $session): CourseOffering
    {
        return CourseOffering::where('school_id', $session->school_id)
            ->whereKey($session->course_offering_id)
            ->firstOrFail();
    }

    private function assertNoDuplicateSession(CourseOffering $offering, string $sessionDate, ?string $startsAt): void
    {
        $query = DB::table('course_offering_attendance_sessions')
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereDate('session_date', $sessionDate);

        if ($startsAt === null) {
            // NULL is distinct under a unique index, so the untimed rule is
            // enforced here, under the row lock taken by createSession().
            $query->whereNull('starts_at');
        } else {
            $query->where('starts_at', $startsAt);
        }

        if ($query->exists()) {
            throw new DomainException($startsAt === null
                ? 'An untimed Attendance Session already exists for this Course Offering on that date.'
                : 'An Attendance Session already exists for this Course Offering at that start time on that date.');
        }
    }

    /** A session date must fall inside the Offering's Academic Period. */
    private function assertDateWithinPeriod(CourseOffering $offering, string $sessionDate, ?User $lecturer = null): void
    {
        if (! Schema::hasTable('academic_periods')) {
            return;
        }
        $period = DB::table('academic_periods')
            ->where('school_id', $offering->school_id)
            ->where('academic_year_id', $offering->academic_year_id)
            ->where('id', $offering->academic_period_id)
            ->first(['label', 'start_date', 'end_date']);

        if (! $period || ! $period->start_date || ! $period->end_date) {
            return;
        }
        $date = Carbon::parse($sessionDate)->startOfDay();
        $periodStart = Carbon::parse($period->start_date)->startOfDay();
        $periodEnd = Carbon::parse($period->end_date)->endOfDay();

        if ($date->lt($periodStart)) {
            // The ONLY bound an authorised pre-start System Tester may relax is
            // the period START, and only through the single shared testing
            // authority: the grant, this exact lecturer, this exact tenant, an
            // exact ACTIVE non-ended allocation, an IN_PROGRESS Offering with
            // durable governed early-start evidence, and a date that is not in
            // the future. The period end is never relaxed, and no date on the
            // Academic Period or on the allocation is ever written.
            if ($lecturer !== null
                && $this->testers->allowsPreStartAttendanceDate(
                    $lecturer,
                    $offering,
                    $sessionDate,
                    (string) $period->start_date
                )) {
                return;
            }

            throw new DomainException("An Attendance Session must fall within the Offering's Academic Period ({$period->label}).");
        }

        if ($date->gt($periodEnd)) {
            throw new DomainException("An Attendance Session must fall within the Offering's Academic Period ({$period->label}).");
        }
    }

    /** A linked Live Class must belong to the same tenant and the same Offering. */
    private function assertLiveClassBelongsToOffering(CourseOffering $offering, int $liveClassId): LiveClass
    {
        $liveClass = LiveClass::where('school_id', $offering->school_id)->whereKey($liveClassId)->first();
        if (! $liveClass) {
            throw new DomainException('That Live Class could not be found in this institution.');
        }
        if ((int) $liveClass->course_offering_id !== (int) $offering->id) {
            throw new DomainException('That Live Class belongs to a different Course Offering.');
        }

        return $liveClass;
    }

    private function transitionSession(
        User $lecturer,
        CourseOfferingAttendanceSession $session,
        string $to,
        string $action,
    ): CourseOfferingAttendanceSession {
        return DB::transaction(function () use ($session, $to, $action): CourseOfferingAttendanceSession {
            $locked = CourseOfferingAttendanceSession::where('school_id', $session->school_id)
                ->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $before = $locked->status;
            DB::table('course_offering_attendance_sessions')
                ->where('school_id', $locked->school_id)->where('id', $locked->id)
                ->update(['status' => $to, 'updated_at' => now()]);
            $fresh = CourseOfferingAttendanceSession::where('school_id', $locked->school_id)->findOrFail($locked->id);
            $this->audit($action, $fresh, ['status' => $before], ['status' => $to]);

            return $fresh;
        });
    }

    private function sessionValues(CourseOfferingAttendanceSession $session): array
    {
        return [
            'course_offering_id' => (int) $session->course_offering_id,
            'session_date' => $session->session_date?->format('Y-m-d'),
            'starts_at' => $session->starts_at,
            'ends_at' => $session->ends_at,
            'type' => $session->type,
            'topic' => $session->topic,
            'live_class_id' => $session->live_class_id,
            'status' => $session->status,
        ];
    }

    /**
     * Session-level auditing only. Marking can touch hundreds of rows, so each
     * individual mark is not written to the global audit log; the session
     * lifecycle that governs those rows is.
     */
    private function audit(string $action, CourseOfferingAttendanceSession $session, array $old, array $new): void
    {
        \App\Models\AuditLog::record($action, 'Course Offering Attendance', "{$action} for Attendance Session #{$session->id}.", [
            'school_id' => $session->school_id,
            'record_type' => CourseOfferingAttendanceSession::class,
            'record_id' => $session->id,
            'event_type' => 'COURSE_OFFERING_ATTENDANCE',
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
        ]);
    }
}
