<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineExamProctoringEvent extends Model
{
    public const EVENT_TYPES = [
        'consent_given',
        'camera_permission_granted',
        'camera_permission_denied',
        'camera_started',
        'camera_stopped',
        'tab_hidden',
        'focus_lost',
        'focus_returned',
        'fullscreen_started',
        'fullscreen_exited',
        'connection_lost',
        'connection_restored',
        'snapshot_captured',
        'snapshot_failed',

        // Restricted-interaction events. Distinct from `focus_lost` because they are
        // different facts: a student reaching for the clipboard inside the exam is not
        // the same evidence as a window that lost focus, and an examiner reviewing the
        // record needs to be able to tell them apart.
        //
        // A BLOCKED action is recorded as ATTEMPTED-and-refused, never as "the student
        // copied the question" — the page cannot know whether the clipboard received
        // anything, and a log that claims it did would be false evidence.
        'clipboard_blocked',
        'context_menu_blocked',
        'navigation_attempted',
        'print_attempted',
    ];

    protected $table = 'online_exam_proctoring_events';

    protected $fillable = [
        'submission_id',
        'event_type',
        'event_time',
        'metadata',
        'reviewed_by',
        'review_status',
    ];

    protected $casts = [
        'event_time' => 'datetime',
        'metadata' => 'array',
    ];

    public function submission()
    {
        return $this->belongsTo(OnlineExamSubmission::class, 'submission_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeForSubmission($query, int $submissionId)
    {
        return $query->where('submission_id', $submissionId);
    }

    public function scopeChronological($query)
    {
        return $query->orderBy('event_time')->orderBy('id');
    }
}
