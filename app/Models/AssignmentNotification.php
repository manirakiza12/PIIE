<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per announced assignment event.
 *
 * This is the dedup ledger, not a log the UI reads. A UNIQUE index on
 * `dedup_key` is claimed with insertOrIgnore BEFORE anything is sent, so the
 * DATABASE - not a read-then-write check - decides whether an event has already
 * been announced. Two lecturers publishing the same assignment, or a retried
 * request, cannot both notify.
 *
 * A string key rather than the (assignment_id, type) pair used by
 * live_class_notifications, because assignment events include PER-SUBMISSION
 * ones: "graded" for student A is a different event from "graded" for student B,
 * and a MySQL UNIQUE index does not constrain NULLs, so a nullable
 * submission_id column could not have carried the guarantee on its own.
 */
class AssignmentNotification extends Model
{
    use HasFactory;

    protected $table = 'assignment_notifications';

    public const TYPE_PUBLISHED = 'published';

    public const TYPE_DUE_REMINDER = 'due_reminder';

    public const TYPE_GRADED = 'graded';

    public const TYPE_RETURNED = 'returned';

    protected $fillable = [
        'school_id', 'assignment_id', 'submission_id', 'dedup_key', 'type',
        'recipient_count', 'sent_at',
    ];

    protected $casts = [
        'assignment_id' => 'integer',
        'submission_id' => 'integer',
        'recipient_count' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    /** Whole-assignment events key on the assignment alone. */
    public static function assignmentKey(int $assignmentId, string $type): string
    {
        return 'assignment:'.$assignmentId.':'.$type;
    }

    /**
     * Per-submission events key on the attempt, so a resubmission that is graded
     * again is a genuinely new event rather than being suppressed because
     * "graded" was already announced for that student.
     */
    public static function submissionKey(int $assignmentId, int $submissionId, string $type): string
    {
        return 'assignment:'.$assignmentId.':submission:'.$submissionId.':'.$type;
    }
}
