<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One additional picture in a Programme's public gallery.
 *
 * ── SCOPE, STATED PLAINLY BECAUSE IT IS A HARD RULE ──────────────────────────
 *
 * This model belongs to PROGRAMMES and to nothing else. There is deliberately no
 * relationship from here to a course unit, a lesson, a lesson resource, an
 * examination or a question, and no shared image component takes it as input.
 * Programme pictures are public marketing material; lesson and exam media are
 * private teaching material with their own authorisation rules, and sharing one
 * pipeline between them is how private material ends up world-readable.
 */
class ProgrammeGalleryImage extends Model
{
    use HasFactory;

    protected $table = 'programme_gallery_images';

    protected $fillable = [
        'school_id', 'programme_id', 'sort_order',
        'path', 'original_name', 'mime', 'size', 'width', 'height',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'size'      => 'integer',
        'width'     => 'integer',
        'height'    => 'integer',
    ];

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }
}