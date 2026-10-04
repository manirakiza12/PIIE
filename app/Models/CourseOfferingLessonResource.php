<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attachment on a lesson: a link, or an uploaded file.
 *
 * Files are stored OUTSIDE the web root under a generated name and served only
 * through the authorised access route, exactly as Live Class materials are. A
 * file path under public/ would make every attachment readable by anyone who
 * guessed or enumerated it, which for a lecturer's slide deck or a marked
 * assignment is the same failure as publishing it.
 *
 * @property int $id
 * @property int $course_offering_id
 * @property int $course_offering_lesson_id
 * @property string $title
 * @property string $type
 * @property string|null $link_url
 * @property string|null $stored_name
 */
class CourseOfferingLessonResource extends Model
{
    public const TYPE_LINK = 'link';

    public const TYPE_FILE = 'file';

    protected $table = 'course_offering_lesson_resources';

    protected $fillable = [
        'school_id', 'course_offering_id', 'course_offering_lesson_id', 'title', 'type',
        'link_url', 'original_name', 'stored_name', 'mime_type', 'size_bytes', 'created_by',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'course_offering_id' => 'integer',
        'course_offering_lesson_id' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseOfferingLesson::class, 'course_offering_lesson_id');
    }

    public function isFile(): bool
    {
        return $this->type === self::TYPE_FILE;
    }

    /** A link resource is only usable when it has a real http(s) target. */
    public function hasUsableLink(): bool
    {
        $url = trim((string) $this->link_url);
        if ($url === '') {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    public function displayName(): string
    {
        return $this->original_name ?: ($this->title ?: 'Attachment');
    }

    public function sizeLabel(): string
    {
        $bytes = (int) ($this->size_bytes ?: 0);
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
