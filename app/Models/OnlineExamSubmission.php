<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class OnlineExamSubmission extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_TIMED_OUT = 'timed_out';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PENDING_MANUAL = 'pending_manual_marking';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_RESULT_PUBLISHED = 'result_published';

    protected $table = 'online_exam_submissions';

    protected $fillable = [
        'online_exam_id', 'student_id', 'school_id',
        'answers', 'score', 'attempt_no', 'started_at', 'expires_at',
        'last_activity_at', 'submitted_at', 'submitted_via', 'status',
        'timeout_at', 'total_marks_snapshot', 'objective_score',
        'manual_score', 'passed', 'result_email_sent_at', 'camera_consent_at',
        'camera_permission_granted', 'camera_ready_at',
        'fullscreen_started_at', 'browser_session_token',
        'ip_address', 'user_agent', 'result_review_state',

        /**
         * PUBLICATION AUDIT.
         *
         * These must be fillable, or update() discards them SILENTLY - no exception
         * and no partial write. The release then proceeds looking successful while the
         * record of WHO released it is simply absent, which is exactly the gap these
         * columns exist to close. Caught by the test asserting the releaser.
         */
        'published_at', 'published_by',
    ];

    protected $casts = [
        'answers' => 'array',
        'attempt_no' => 'integer',
        'score' => 'decimal:2',
        'objective_score' => 'decimal:2',
        'manual_score' => 'decimal:2',
        'total_marks_snapshot' => 'integer',
        'passed' => 'boolean',
        'result_email_sent_at' => 'datetime',
        'camera_permission_granted' => 'boolean',
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'submitted_at' => 'datetime',
        // Without this cast the audit timestamp arrives as a raw string, and the
        // results screen's ->format() on it fails at render time.
        'published_at' => 'datetime',
        'timeout_at' => 'datetime',
        'camera_consent_at' => 'datetime',
        'camera_ready_at' => 'datetime',
        'fullscreen_started_at' => 'datetime',
    ];

    public function exam()
    {
        return $this->belongsTo(OnlineExam::class, 'online_exam_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function answerRows()
    {
        return $this->hasMany(OnlineExamAnswer::class, 'submission_id');
    }

    public function proctoringEvents()
    {
        return $this->hasMany(OnlineExamProctoringEvent::class, 'submission_id');
    }

    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeForStudent($query, int $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeFinalized($query)
    {
        return $query->whereIn('status', [self::STATUS_FINALIZED, self::STATUS_RESULT_PUBLISHED]);
    }

    public function isAttemptCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_TIMED_OUT, self::STATUS_PENDING_MANUAL, self::STATUS_FINALIZED, self::STATUS_RESULT_PUBLISHED], true);
    }

    public function isResultPublished(): bool
    {
        return $this->status === self::STATUS_RESULT_PUBLISHED;
    }

    public function isFinalized(): bool
    {
        return in_array($this->status, [self::STATUS_FINALIZED, self::STATUS_RESULT_PUBLISHED], true);
    }

    public function getResultTotalMarksAttribute(): float
    {
        return (float) ($this->total_marks_snapshot ?? $this->exam?->total_marks ?? 0);
    }

    public function getMarkingStateLabelAttribute(): string
    {
        if ($this->isFinalized()) {
            return $this->isResultVisible() ? 'Result published' : 'Finalized / not published';
        }
        if (!in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_TIMED_OUT, self::STATUS_PENDING_MANUAL], true)) {
            return $this->status === self::STATUS_IN_PROGRESS ? 'In progress' : 'Cancelled';
        }
        return \App\Support\OnlineExams\OnlineExamMarking::summary($this)['pending']
            ? 'Pending marking' : 'Ready to finalize';
    }

    /**
     * The review state in words a person can act on.
     *
     * ── WHY THIS EXISTS ────────────────────────────────────────────────────
     *
     * `result_review_state` is an internal workflow token, and the administrator's
     * results table printed it raw: a row read `not_ready`, which tells an
     * administrator nothing about whether marking is in progress, who owes the next
     * step, or whether anything is wrong.
     *
     * The tokens are stable and the wording is not, so the mapping lives HERE rather
     * than in a view: three screens report this state and they must not drift into
     * three different vocabularies. An unrecognised token is shown rather than
     * hidden — an unknown state is a fact an administrator needs to see, and
     * swallowing it would make a genuine inconsistency invisible.
     */
    public function getResultReviewStateLabelAttribute(): string
    {
        $state = $this->result_review_state;

        if ($state === null || $state === '') {
            return $this->isFinalized()
                ? 'With lecturer — handover not recorded'
                : 'Marking in progress';
        }

        return [
            'not_ready' => 'Marking in progress',
            'pending_review' => 'Awaiting admin review',
            'returned_for_correction' => 'Returned to lecturer for correction',
            'published' => 'Result published',
        ][$state] ?? ('Review state: '.$state);
    }

    public function computeExpiryAt(): ?Carbon
    {
        if (empty($this->started_at) || empty($this->exam)) {
            return null;
        }

        $durationExpiry = $this->started_at->copy()->addMinutes((int) $this->exam->duration_mins);

        if (!empty($this->exam->end_datetime)) {
            return $durationExpiry->lessThan($this->exam->end_datetime)
                ? $durationExpiry
                : $this->exam->end_datetime->copy();
        }

        return $durationExpiry;
    }

    public function isExpired(?Carbon $at = null): bool
    {
        $at = $at ?: now();

        // Uses the SAME effective deadline as `remainingSeconds()`, so the countdown a
        // student watches and the moment the attempt is actually closed can never
        // disagree. They previously read different columns: the timer honoured the
        // recomputed deadline while the timeout only looked at the frozen one, so an
        // attempt could be shut while the page still showed time remaining.
        $deadline = $this->effectiveExpiresAt();

        if (! $deadline) {
            return false;
        }

        return $at->gte($deadline);
    }

    public function getEffectiveScoreAttribute(): float
    {
        $objective = (float) ($this->objective_score ?? 0);
        $manual = (float) ($this->manual_score ?? 0);

        if ($objective > 0 || $manual > 0) {
            return $objective + $manual;
        }

        return (float) ($this->score ?? 0);
    }

    /**
 * THE EFFECTIVE DEADLINE FOR THIS ATTEMPT.
 *
 * ── WHY THIS EXISTS: EXAM 19 SHOWED 223 MINUTES FOR A 45-MINUTE EXAM ───────
 *
 * Submission 13 was created with `expires_at = 04:58`, which is the examination's
 * `end_datetime` to the minute. The paper was configured for 45 minutes.
 *
 * `startExam()` already computes the deadline as the EARLIER of "started plus the
 * configured duration" and "the exam's closing time", so this was not that code being
 * wrong — it was `expires_at` being FROZEN AT CREATION and never reconsidered. The
 * attempt had been opened while the exam still carried a duration of roughly 225
 * minutes; the lecturer then corrected it to 45, and the running attempt kept the
 * deadline it was born with.
 *
 * The consequence was a student given more than five times the time the institution
 * set, decided by a value nobody could see or correct.
 *
 * ── THE RULE ──────────────────────────────────────────────────────────────
 *
 * The deadline is the EARLIEST of three things, recomputed on every read:
 *
 *   1. `expires_at` — frozen at attempt start, so a refresh, a reconnect or a second
 *      tab cannot extend anything;
 *   2. `started_at` plus the exam's CURRENT `duration_mins` — so shortening the paper
 *      takes effect immediately;
 *   3. the exam's CURRENT closing time — so moving the window forward cannot extend
 *      an attempt that has already begun.
 *
 * Only ever SHORTENS. An attempt is never lengthened by any of this, because the only
 * way to give a student more time is an explicit administrative act, not a recompute.
 */
public function effectiveExpiresAt(): ?Carbon
{
    if (empty($this->expires_at)) {
        return null;
    }

    // ── COMPUTED IN UNIX SECONDS, DELIBERATELY ──────────────────────────────
    //
    // These three instants can each arrive in a different timezone: `expires_at` and
    // `started_at` are read in the application's zone, while `scheduledEndAt()`
    // converts the exam's window from its own `schedule_timezone`. Comparing those
    // values as Carbon objects proved unreliable here — an identity comparison on two
    // Carbons that plainly differ in wall-clock time returned "not earlier", so the
    // 45-minute deadline was discarded in favour of the exam's closing time and the
    // 223-minute bug survived the first attempt at this fix.
    //
    // Timestamps have no timezone ambiguity: each value is converted to a single
    // absolute instant, compared as an integer, and only the winner is turned back
    // into a Carbon at the end.
    $deadlineTs = $this->expires_at->getTimestamp();

    $exam = $this->exam;

    if ($exam) {
        $durationMins = (int) $exam->duration_mins;

        if ($durationMins > 0 && ! empty($this->started_at)) {
            $durationTs = $this->started_at->getTimestamp() + ($durationMins * 60);

            if ($durationTs < $deadlineTs) {
                $deadlineTs = $durationTs;
            }
        }

        $scheduledEnd = $exam->scheduledEndAt();

        if ($scheduledEnd) {
            $scheduledTs = $scheduledEnd->getTimestamp();

            if ($scheduledTs < $deadlineTs) {
                $deadlineTs = $scheduledTs;
            }
        }
    }

    return Carbon::createFromTimestamp($deadlineTs, $this->expires_at->getTimezone());
}

public function remainingSeconds(?Carbon $at = null): int
{
    $at = $at ?: now();

    $deadline = $this->effectiveExpiresAt();

    if (! $deadline) {
        return 0;
    }

    $seconds = $deadline->diffInSeconds($at, false);

    return (int) max(0, -$seconds);
}

/**
 * PERSIST A SHORTENED DEADLINE, so the browser and the server cannot disagree.
 *
 * Writes only when the recomputed deadline is EARLIER than the stored one. This is
 * what makes the correction durable rather than merely computed: after a refresh the
 * student sees the same number the server will enforce.
 */
public function clampExpiresAtToEffectiveDeadline(): bool
{
    $deadline = $this->effectiveExpiresAt();

    if (! $deadline || ! $this->expires_at) {
        return false;
    }

    // Same integer comparison as above, for the same reason.
    if ($deadline->getTimestamp() >= $this->expires_at->getTimestamp()) {
        return false;
    }

    $this->forceFill(['expires_at' => $deadline])->save();

    return true;
}

    public function isResultVisible(): bool
    {
        if (empty($this->exam)) {
            return false;
        }

        return $this->exam->isResultVisibleFor($this);
    }
}
