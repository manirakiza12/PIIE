<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class LiveClass extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LIVE = 'live';
    public const STATUS_ENDED = 'ended';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * DERIVED ONLY. Deliberately NOT a value of the stored `status` enum.
     *
     * A class whose scheduled end has passed but which nobody ever concluded is
     * in an unknown state: the lecturer may have taught it and forgotten to
     * close it, or the meeting may never have happened. Reporting that as
     * "Completed" would put a claim into the academic record that nobody made.
     * So the clock stops asserting anything about it, and the honest question
     * ("did this class run?") is put to the lecturer instead.
     *
     * It cannot be stored, so it can never be reached by a request; the only
     * way out is a person pressing End Class, which writes the real `ended`.
     */
    public const STATUS_NOT_CONCLUDED = 'not_concluded';

    /** Stored statuses a person may deliberately set. `not_concluded` is absent by design. */
    public const STORED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SCHEDULED,
        self::STATUS_LIVE,
        self::STATUS_ENDED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The lifecycle, and the only legal moves between stored states.
     *
     * Written out rather than implied so an illegal transition is a named,
     * testable rule instead of something a controller has to remember. Two
     * states are terminal and admit nothing further: a cancelled class will
     * not run, and an ended one is over. A class cannot be un-ended, because
     * "undo finishing" is exactly the operation that would let a completed
     * record quietly become live again.
     *
     * @var array<string, list<string>>
     */
    public const LIFECYCLE = [
        self::STATUS_DRAFT     => [self::STATUS_SCHEDULED, self::STATUS_CANCELLED],
        self::STATUS_SCHEDULED => [self::STATUS_LIVE, self::STATUS_ENDED, self::STATUS_CANCELLED],
        self::STATUS_LIVE      => [self::STATUS_ENDED, self::STATUS_CANCELLED],
        self::STATUS_ENDED     => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Recording states that mean something, as distinct from a missing URL.
     *
     * The four are genuinely different facts. "Nobody turned recording on" is
     * not "the recording is still processing" and neither is "it failed", yet
     * without this column all three render as an empty box and a student is
     * left guessing whether to wait.
     */
    public const RECORDING_NONE = 'none';
    public const RECORDING_PROCESSING = 'processing';
    public const RECORDING_AVAILABLE = 'available';
    public const RECORDING_UNAVAILABLE = 'unavailable';

    public const RECORDING_STATUSES = [
        self::RECORDING_NONE,
        self::RECORDING_PROCESSING,
        self::RECORDING_AVAILABLE,
        self::RECORDING_UNAVAILABLE,
    ];

    /**
     * @var array<string, string>
     */
    public const RECORDING_LABELS = [
        self::RECORDING_NONE => 'No recording',
        // Deliberately NOT "being processed". PIIE has no provider webhook and
        // no poll of Jitsi, Meet or Zoom, so it cannot know that any provider is
        // producing a file. This state means a person set it, and the wording has
        // to say so - otherwise a lecturer who guessed "processing" publishes a
        // claim to students that no system in PIIE is actually tracking.
        self::RECORDING_PROCESSING => 'Awaiting recording (set by your lecturer)',
        self::RECORDING_AVAILABLE => 'Recording available',
        self::RECORDING_UNAVAILABLE => 'Recording unavailable',
    ];

    /**
     * User-facing status wording.
     *
     * The stored enum says `ended`, which is correct for the database and for
     * compatibility with every existing filter, notification key and audit
     * record - and it is not what a person means. A lecturer who pressed "End
     * Class" ran a completed class; showing them the raw value leaked an internal
     * term into the interface and made the same class read differently on
     * different screens.
     *
     * ONLY `ended` is reworded. Every other state keeps the word the interface
     * already used, so this is the smallest change that fixes the reported
     * defect - renaming "Scheduled" to "Upcoming" would have been scope creep
     * that changed a term nobody complained about.
     *
     * The mapping is PRESENTATION only. computed_status, the lifecycle resolver
     * and every stored value keep their existing meaning, so nothing about the
     * lifecycle changes in order to fix a word.
     *
     * @var array<string, string>
     */
    public const DISPLAY_STATUS_LABELS = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_SCHEDULED => 'Scheduled',
        self::STATUS_LIVE => 'Live Now',
        self::STATUS_ENDED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
        self::STATUS_NOT_CONCLUDED => 'Ended without confirmation',
    ];

    /** The wording every user-facing surface should use for this class. */
    public function displayStatusLabel(): string
    {
        return self::DISPLAY_STATUS_LABELS[$this->computed_status] ?? 'Scheduled';
    }

    /**
     * Is this class permanently out of the running?
     *
     * Terminal means the lifecycle mutations are gone for good: no cancel, no
     * reschedule, no edit-schedule. Post-class work (resources, recordings) is
     * NOT part of this and stays available under its own governed authority.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_ENDED, self::STATUS_CANCELLED], true);
    }

    protected $table = 'live_classes';

    protected $fillable = [
        'school_id',
        'title',
        'description',
        'subject_id',
        'course_offering_id',
        'class_id',
        'programme_id',
        'academic_session_id',
        'teacher_id',
        'platform',
        'meeting_url',
        'meeting_id',
        'meeting_password',

        // Google Calendar bookkeeping, written only by the scheduling path.
        // NULL for every class on another platform, and for google_meet
        // classes created before this feature existed.
        'google_calendar_event_id',
        'google_conference_status',
        'scheduled_at',
        'ends_at',
        'start_date',
        'start_time',
        'end_time',
        'timezone',
        'status',
        'is_published',
        'attendance_enabled',
        'recording_url',
        // Authoritative evidence of WHO did WHAT and WHEN. Written only by the
        // governed transitions, never derived from a clock, and NULL for every
        // class that predates them - which is the honest value, because inventing
        // a start time for a class that has already happened would fabricate the
        // academic record.
        'started_at',
        'started_by',
        'ended_at',
        'ended_by',
        'cancelled_at',
        'cancelled_by',
        'recording_status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'course_offering_id' => 'integer',
        'scheduled_at' => 'datetime',
        'ends_at'      => 'datetime',
        'start_date'   => 'date',
        'start_time'   => 'datetime:H:i:s',
        'end_time'     => 'datetime:H:i:s',
        'started_at'   => 'datetime',
        'ended_at'     => 'datetime',
        'cancelled_at' => 'datetime',
        'started_by'   => 'integer',
        'ended_by'     => 'integer',
        'cancelled_by' => 'integer',
        'is_published' => 'boolean',
        'attendance_enabled' => 'boolean',
    ];

    protected $appends = [
        'computed_status',
        'can_join',
    ];

    public function toArray(): array
    {
        $data = parent::toArray();
        if ($this->course_offering_id !== null) {
            unset($data['meeting_url'], $data['meeting_id'], $data['meeting_password'], $data['recording_url']);
        }
        return $data;
    }

    protected static function booted(): void
    {
        static::saving(function (self $liveClass): void {
            $offeringId = $liveClass->course_offering_id;
            $previousOfferingId = $liveClass->getOriginal('course_offering_id');

            if ($offeringId === null || $offeringId === '') {
                if ($previousOfferingId !== null && $previousOfferingId !== '') {
                    throw new DomainException('An Offering-backed Live Class cannot be detached from its Course Offering.');
                }

                return;
            }

            if ($previousOfferingId !== null && (int) $previousOfferingId !== (int) $offeringId) {
                throw new DomainException('An Offering-backed Live Class cannot be reassigned to another Course Offering.');
            }

            $offering = CourseOffering::query()->whereKey($offeringId)->first();
            if (! $offering) {
                throw new DomainException('The selected Course Offering does not exist.');
            }

            if ((int) $liveClass->school_id !== (int) $offering->school_id) {
                throw new DomainException('The Live Class and Course Offering must belong to the same tenant.');
            }

            if (! Subject::query()->where('school_id', $offering->school_id)->whereKey($offering->subject_id)->exists()) {
                throw new DomainException('The Course Offering subject does not belong to the Offering tenant.');
            }

            if ((int) $liveClass->subject_id !== (int) $offering->subject_id) {
                throw new DomainException('The Live Class subject must match the Course Offering subject.');
            }

            if ($liveClass->isDirty('course_offering_id') && ! in_array($offering->status, [CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS], true)) {
                throw new DomainException('A new operational Live Class requires an open or in-progress Course Offering.');
            }
        });

        static::deleting(function (self $liveClass): void {
            if ($liveClass->course_offering_id !== null) {
                throw new DomainException('Offering-backed Live Classes cannot be deleted; cancel them to preserve history.');
            }
        });
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function courseOffering()
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function course()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function classRoom()
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function lecturer()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    public function academicSession()
    {
        return $this->belongsTo(Session::class, 'academic_session_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function attendances()
    {
        return $this->hasMany(LiveClassAttendance::class, 'live_class_id');
    }

    public function materials()
    {
        return $this->hasMany(LiveClassMaterial::class, 'live_class_id');
    }

    public function notifications()
    {
        return $this->hasMany(LiveClassNotification::class, 'live_class_id');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', 1);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', now());
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('status', self::STATUS_LIVE)
                ->orWhere(function ($inner) {
                    $inner->where('status', self::STATUS_SCHEDULED)
                        ->whereNotNull('scheduled_at')
                        ->where('scheduled_at', '<=', now())
                        ->where(function ($sub) {
                            $sub->whereNull('ends_at')->orWhere('ends_at', '>=', now());
                        });
                });
        });
    }

    public function scopeEnded($query)
    {
        return $query->where(function ($q) {
            $q->where('status', self::STATUS_ENDED)
                ->orWhere(function ($inner) {
                    $inner->whereNotNull('ends_at')
                        ->where('ends_at', '<', now())
                        ->where('status', '!=', self::STATUS_CANCELLED);
                });
        });
    }

    public function getComputedStatusAttribute(): string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return self::STATUS_CANCELLED;
        }

        // A lecturer who pressed "End Class" closed the meeting. That decision is
        // the ONLY thing that makes a class completed, and it outranks the clock:
        // without this branch a class finished at 09:00 with a scheduled end of
        // 11:00 would keep reporting itself as "live" for two more hours and
        // keep inviting people in.
        if ($this->status === self::STATUS_ENDED) {
            return self::STATUS_ENDED;
        }

        if (!$this->is_published) {
            return self::STATUS_DRAFT;
        }

        if (!$this->scheduled_at) {
            return $this->status ?: self::STATUS_DRAFT;
        }

        $now = now();
        $start = $this->scheduled_at;
        $end = $this->ends_at;

        // Past the scheduled end, but nobody ever concluded it.
        //
        // This used to return ENDED, which meant the passage of time alone could
        // assert that a class had been taught. It could not have: a lecturer who
        // simply forgot to press End Class produced a class that looked
        // identical to one that really ran, and the difference matters because
        // this is the same field that decides whether students can still join
        // and whether a recording is expected. The clock now reports only what it
        // knows - the time has passed - and the record stays unclaimed until a
        // person says what happened.
        if ($end && $now->greaterThan($end)) {
            return self::STATUS_NOT_CONCLUDED;
        }

        if ($now->greaterThanOrEqualTo($start) && (!$end || $now->lessThanOrEqualTo($end))) {
            return self::STATUS_LIVE;
        }

        return self::STATUS_SCHEDULED;
    }

    /**
     * May this class be moved to $target at all?
     *
     * The lifecycle table is the rule; this is the one place it is asked, so a
     * controller cannot invent a transition and a test can assert the whole
     * matrix without repeating it.
     */
    public function canTransitionTo(string $target): bool
    {
        $from = (string) $this->status;

        return in_array($target, self::LIFECYCLE[$from] ?? [], true);
    }

    /** @return list<string> */
    public function allowedTransitions(): array
    {
        return self::LIFECYCLE[(string) $this->status] ?? [];
    }

    /**
     * True when a person, rather than a clock, has asserted that this class ran.
     * This is the distinction the whole completion workflow turns on.
     */
    public function hasConclusiveOutcome(): bool
    {
        return in_array($this->status, [self::STATUS_ENDED, self::STATUS_CANCELLED], true);
    }

    /** Does authoritative evidence exist that somebody actually started this? */
    public function hasStartEvidence(): bool
    {
        return $this->started_at !== null || $this->started_by !== null;
    }

    public function hasEndEvidence(): bool
    {
        return $this->ended_at !== null || $this->ended_by !== null;
    }

    /**
     * The recording state, normalised.
     *
     * An unrecognised or absent value is read as `none` rather than trusted, and
     * a class that claims to be available without a usable URL is DOWNGRADED to
     * unavailable rather than rendered as a broken "Watch" button. The stored
     * value is deliberately not corrected here: this is a read, and rewriting
     * an academic record to tidy a display would be the wrong place to do it.
     */
    public function recordingState(): string
    {
        $state = (string) ($this->recording_status ?: self::RECORDING_NONE);
        if (! in_array($state, self::RECORDING_STATUSES, true)) {
            $state = self::RECORDING_NONE;
        }
        if ($state === self::RECORDING_AVAILABLE && ! $this->safe_recording_url) {
            return self::RECORDING_UNAVAILABLE;
        }

        return $state;
    }

    public function recordingStateLabel(): string
    {
        return self::RECORDING_LABELS[$this->recordingState()] ?? self::RECORDING_LABELS[self::RECORDING_NONE];
    }

    public function isRecordingAvailable(): bool
    {
        return $this->recordingState() === self::RECORDING_AVAILABLE;
    }

    public function shouldAllowJoin(?Carbon $now = null): bool
    {
        $now = $now ?: now();

        if (!$this->is_published || $this->computed_status === self::STATUS_CANCELLED) {
            return false;
        }

        if (empty($this->safe_meeting_url)) {
            return false;
        }

        if (in_array($this->computed_status, [self::STATUS_LIVE, self::STATUS_SCHEDULED], true)) {
            if (!$this->scheduled_at) {
                return false;
            }

            // Allow early join up to 15 minutes before start.
            return $now->greaterThanOrEqualTo($this->scheduled_at->copy()->subMinutes(15));
        }

        return false;
    }

    public function getCanJoinAttribute(): bool
    {
        return $this->shouldAllowJoin();
    }

    public function getSafeMeetingUrlAttribute(): ?string
    {
        $url = trim((string) $this->meeting_url);
        if ($url === '') {
            return null;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        if (!isset($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
            return null;
        }

        return $url;
    }

    /**
     * Scheduled length in minutes, or null when either boundary is missing
     * (e.g. a draft with no times entered yet).
     */
    public function getDurationMinutesAttribute(): ?int
    {
        if (!$this->scheduled_at || !$this->ends_at) {
            return null;
        }

        return max(0, $this->scheduled_at->diffInMinutes($this->ends_at));
    }

    /**
     * Free (non-Workspace) Google accounts cut group Meet calls off at 60
     * minutes. There is no way to lift that from this app's side — Meet's
     * API doesn't expose a "this account is on Workspace" flag — so this is
     * a scheduling-time warning, not an enforced limit: an admin who knows
     * their account is upgraded can ignore it, and staff who don't yet know
     * about the cap are warned before they find out mid-class.
     */
    public const FREE_TIER_MINUTE_LIMIT = 60;

    public function exceedsGoogleMeetFreeTierLimit(): bool
    {
        return $this->platform === 'google_meet'
            && $this->duration_minutes !== null
            && $this->duration_minutes > self::FREE_TIER_MINUTE_LIMIT;
    }

    public function getSafeRecordingUrlAttribute(): ?string
    {
        $url = trim((string) $this->recording_url);
        if ($url === '') {
            return null;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        if (!isset($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
            return null;
        }

        if ($this->course_offering_id !== null) {
            return route('live_classes.recording.access', ['liveClass' => $this->id]);
        }

        return $url;
    }
}
