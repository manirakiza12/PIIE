<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lecturer attachment on an assignment.
 *
 * Files are stored OUTSIDE the web root under a generated name and are reachable
 * only through an authorising route that re-checks allocation and registration.
 * A path under public/ would make every lecturer's handout readable by anyone who
 * guessed it, which for a question paper or a marking scheme is the same failure
 * as publishing it.
 *
 * A link resource is only offered when it has a real http(s) target, so a
 * `javascript:` value can never become a clickable control.
 */
class AssignmentResource extends Model
{
    use HasFactory;

    public const TYPE_LINK = 'link';

    public const TYPE_FILE = 'file';

    protected $table = 'assignment_resources';

    protected $fillable = [
        'school_id', 'course_offering_id', 'assignment_id', 'title', 'type',
        'link_url', 'original_name', 'stored_name', 'mime_type', 'size_bytes', 'created_by',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'course_offering_id' => 'integer',
        'assignment_id' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    public function isFile(): bool
    {
        return $this->type === self::TYPE_FILE;
    }

    public function hasUsableLink(): bool
    {
        $url = trim((string) $this->link_url);
        if ($url === '') {
            return false;
        }

        return in_array(
            strtolower((string) parse_url($url, PHP_URL_SCHEME)),
            ['http', 'https'],
            true
        );
    }

    public function displayName(): string
    {
        return $this->original_name ?: ($this->title ?: 'Attachment');
    }

    public function sizeLabel(): string
    {
        $bytes = (int) ($this->size_bytes ?? 0);

        if ($bytes <= 0) {
            return '';
        }
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
