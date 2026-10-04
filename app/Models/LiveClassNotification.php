<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LiveClassNotification extends Model
{
    use HasFactory;

    /**
     * One row per (live_class_id, type) - the table has a UNIQUE index on
     * exactly that pair, so the database is the deduplication authority and
     * concurrent publishes cannot double-notify. That index is why every
     * value below is a stable, short key rather than free text.
     *
     * `type` is varchar(30), which is the real budget: a reschedule key is
     * 'rescheduled:' + 15 characters of date/time (24 total) and a recording
     * key is 'recording:' + 8 characters of fingerprint (19 total). Both stay
     * inside it, so event-scoped deduplication needs no migration and no new
     * column.
     */
    public const TYPE_REMINDER_24H = 'reminder_24h';

    public const TYPE_REMINDER_1H = 'reminder_1h';

    /** The class became available to students (created-and-published, or published later). */
    public const TYPE_PUBLISHED = 'published';

    /** The class was called off. One per class, however many times cancel is pressed. */
    public const TYPE_CANCELLED = 'cancelled';

    /** Prefix for a per-schedule key: re-sending at the same time is suppressed, a new time is not. */
    public const TYPE_RESCHEDULED_PREFIX = 'rescheduled:';

    /** Prefix for a per-recording key: re-saving the same recording is suppressed, a changed one is not. */
    public const TYPE_RECORDING_PREFIX = 'recording:';

    /**
     * A recording became available, keyed on the EVENT rather than the file.
     *
     * One row per class, like published and cancelled. A recording URL is
     * corrected far more often than it is first published, and the per-URL key
     * made every correction look like a new recording to every confirmed
     * student - so a student who had already watched it was told to watch it
     * again, and a corrected link could produce a second notification for the
     * same single recording.
     */
    public const TYPE_RECORDING_AVAILABLE = 'recording_available';

    public static function rescheduledKey(?\DateTimeInterface $scheduledAt): ?string
    {
        if (! $scheduledAt) {
            return null;
        }

        return self::TYPE_RESCHEDULED_PREFIX.\Illuminate\Support\Carbon::instance($scheduledAt)->format('YmdHis');
    }

    public static function recordingKey(?string $recordingUrl): ?string
    {
        $url = trim((string) $recordingUrl);

        return $url === '' ? null : self::TYPE_RECORDING_PREFIX.substr(md5($url), 0, 8);
    }

    protected $fillable = [
        'school_id', 'live_class_id', 'type', 'recipient_count', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function liveClass()
    {
        return $this->belongsTo(LiveClass::class, 'live_class_id');
    }
}
