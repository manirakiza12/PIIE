<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineExamSnapshotRequest extends Model
{
    protected $table = 'online_exam_snapshot_requests';

    protected $fillable = [
        'submission_id',
        'requested_by',
        'status',
        'requested_at',
        'completed_at',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function submission()
    {
        return $this->belongsTo(OnlineExamSubmission::class, 'submission_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeForSubmission($query, int $submissionId)
    {
        return $query->where('submission_id', $submissionId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}