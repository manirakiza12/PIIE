<?php

namespace App\Models;

use App\Support\CourseContent\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Lesson: one piece of learning content inside a module of a Course Offering.
 *
 * `course_offering_id` is carried on the row as well as being reachable through
 * the module, so every read can be scoped "this Offering, this tenant" with one
 * indexed predicate. The booted hook below is what keeps the two honest: a
 * lesson can never be saved pointing at a module from a different Offering or a
 * different institution, which is the mistake that would let content be read or
 * moved across a tenant boundary.
 *
 * The body is sanitised HTML written in the rich text editor. Sanitising happens
 * ON THE WAY IN (a mutator, so no code path can store raw HTML) and the value is
 * rendered with those characters intact afterwards. Filtering at render time
 * instead would mean trusting the database, and would break legitimately authored
 * markup the moment a style changed.
 *
 * @property int $id
 * @property int $school_id
 * @property int $course_offering_id
 * @property int $course_offering_module_id
 * @property string $title
 * @property string|null $summary
 * @property string|null $learning_objectives
 * @property string|null $body
 * @property string $content_type
 * @property int|null $estimated_minutes
 * @property int $sequence
 * @property string $status
 * @property Carbon|null $released_at
 * @property string $completion_rule
 */
class CourseOfferingLesson extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    /** Implemented today. 'reading' and 'video' are reserved, not built. */
    public const CONTENT_TYPE_LESSON = 'lesson';

    public const STUDENT_CONTENT_TYPES = [self::CONTENT_TYPE_LESSON];

    public const CONTENT_TYPES = [
        self::CONTENT_TYPE_LESSON, 'reading', 'video', 'quiz', 'assignment',
    ];

    /** Reserved vocabulary. Only 'manual' can actually be satisfied today. */
    public const RULE_MANUAL = 'manual';

    public const IMPLEMENTED_COMPLETION_RULES = [self::RULE_MANUAL];

    public const COMPLETION_RULES = [
        self::RULE_MANUAL, 'view_percentage', 'quiz', 'assignment', 'teacher_verification',
    ];

    protected $table = 'course_offering_lessons';

    protected $fillable = [
        'school_id', 'course_offering_id', 'course_offering_module_id', 'title', 'summary',
        'learning_objectives', 'body', 'content_type', 'estimated_minutes', 'sequence',
        'status', 'released_at', 'completion_rule', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'course_offering_id' => 'integer',
        'course_offering_module_id' => 'integer',
        'sequence' => 'integer',
        'estimated_minutes' => 'integer',
        'released_at' => 'datetime',
    ];

    /**
     * Sanitise the body on the way IN.
     *
     * A mutator rather than a call the controller remembers, so a lesson created
     * by a future service, a seeder or a console command is filtered too. Storing
     * unsanitised HTML "temporarily" is how a stored XSS hole is born.
     */
    public function setBodyAttribute($value): void
    {
        $this->attributes['body'] = app(HtmlSanitizer::class)->sanitize($value);
    }

    /** The body is already sanitised at rest; this is the plain-text form. */
    public function getExcerptAttribute(int $limit = 160): string
    {
        return app(HtmlSanitizer::class)->toText($this->attributes['body'] ?? '', $limit);
    }

    public function estimatedDurationLabel(): string
    {
        $minutes = (int) ($this->estimated_minutes ?: 0);

        if ($minutes <= 0) {
            return '';
        }

        if ($minutes < 60) {
            return $minutes === 1
                ? '1 minute'
                : $minutes.' minutes';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0
            ? $hours.($hours === 1 ? ' hour' : ' hours')
            : $hours.($hours === 1 ? ' hour ' : ' hours ').$rest.' minutes';
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseOfferingModule::class, 'course_offering_module_id');
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(CourseOfferingLessonResource::class, 'course_offering_lesson_id');
    }

    public function progressRecords(): HasMany
    {
        return $this->hasMany(CourseOfferingLessonProgress::class, 'course_offering_lesson_id');
    }

    public function isReleasedToStudents(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->status === self::STATUS_PUBLISHED
            && in_array($this->content_type, self::STUDENT_CONTENT_TYPES, true)
            && ($this->released_at === null || $this->released_at->lessThanOrEqualTo($at));
    }

    /**
     * Can a student satisfy this lesson's completion rule today?
     *
     * Only 'manual' is honoured. A lesson that declares a rule PIIE cannot
     * observe - 'quiz' or 'teacher_verification' - reports itself as NOT
     * completable rather than quietly letting a "Mark Complete" button mark
     * something the rule was supposed to gate. That is the difference between
     * reserved architecture and a false claim about learning.
     */
    public function supportsStudentCompletion(): bool
    {
        return $this->completion_rule === self::RULE_MANUAL;
    }

    protected static function booted(): void
    {
        static::saving(function (self $lesson): void {
            $moduleId = $lesson->course_offering_module_id;
            if (empty($moduleId)) {
                return;
            }

            $module = CourseOfferingModule::query()->find($moduleId);

            if (! $module) {
                throw new \DomainException('The selected module does not exist.');
            }

            if ((int) $lesson->course_offering_id !== (int) $module->course_offering_id) {
                throw new \DomainException('A lesson cannot be moved to a module belonging to a different Course Offering.');
            }

            if ((int) $lesson->school_id !== (int) $module->school_id) {
                throw new \DomainException('A lesson cannot be moved to a module belonging to a different institution.');
            }
        });
    }
}
