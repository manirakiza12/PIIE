<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineExamSnapshot extends Model
{
    protected $table = 'online_exam_snapshots';

    protected $fillable = [
        'submission_id',
        'disk',
        'path',
        'size_bytes',
        'mime_type',
        'capture_type',
        'metadata',
        'captured_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function submission()
    {
        return $this->belongsTo(OnlineExamSubmission::class, 'submission_id');
    }

    public function scopeForSubmission($query, int $submissionId)
    {
        return $query->where('submission_id', $submissionId);
    }

    public function scopeChronological($query)
    {
        return $query->orderBy('captured_at')->orderBy('id');
    }

    public function getUrlAttribute(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return \Storage::disk($this->disk)->url($this->path);
    }

    public function getTemporaryUrlAttribute(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return \Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            now()->addMinutes(15)
        );
    }
}