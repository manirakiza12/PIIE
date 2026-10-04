<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * A Module of a Course Offering: one ordered group of learning items.
 *
 * Modules belong to a Course Offering and to nothing above it. A module is not a
 * curriculum stage and not a Study Plan - those are institutional, and one
 * Study Plan describes every delivery of a unit across the programme. A module is
 * what THIS lecturer prepared for THIS delivery, which is why it hangs off the
 * Offering id and is named accordingly.
 *
 * @property int $id
 * @property int $school_id
 * @property int $course_offering_id
 * @property string $title
 * @property string|null $summary
 * @property int $sequence
 * @property string $status
 * @property Carbon|null $released_at
 */
class CourseOfferingModule extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    protected $table = 'course_offering_modules';

    protected $fillable = [
        'school_id', 'course_offering_id', 'title', 'summary', 'sequence',
        'status', 'released_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'course_offering_id' => 'integer',
        'sequence' => 'integer',
        'released_at' => 'datetime',
    ];

    public function offering(): BelongsTo
    {
        return $this->belongsTo(CourseOffering::class, 'course_offering_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(CourseOfferingLesson::class, 'course_offering_module_id')
            ->orderBy('sequence')
            ->orderBy('id');
    }

    /**
     * The tasks attached to this module.
     *
     * A module may have NO task at all - that is a normal state, not an empty
     * state to be corrected - so a caller must not treat an empty result as a
     * problem. Only tasks belonging to this module are returned, and only
     * Course Offering tasks, never a legacy K12 row.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Assignment::class, 'course_offering_module_id')
            ->orderBy('id');
    }

    /**
     * The tasks that GATE this module's completion: those marked required.
     *
     * The counterpart to `optionalTasks()`. Completion is computed from this set
     * and never from all of them - see ModuleCompletion.
     */
    public function requiredTasks(): HasMany
    {
        return $this->tasks()->where('requirement_role', Assignment::ROLE_REQUIRED);
    }

    /**
     * The tasks marked supplementary. Reported for a lecturer's information and
     * for a student's reassurance, never as something outstanding.
     */
    public function optionalTasks(): HasMany
    {
        return $this->tasks()->where('requirement_role', Assignment::ROLE_OPTIONAL);
    }
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function progress(): HasManyThrough
    {
        return $this->hasManyThrough(
            CourseOfferingLessonProgress::class,
            CourseOfferingLesson::class,
            'course_offering_module_id',
            'course_offering_module_id'
        );
    }

    /**
     * Is this module's content visible to a student right now?
     *
     * Three separate questions, deliberately answered separately because
     * conflating them is how content leaks early:
     *
     *   - published, or it was never released at all
     *   - not archived, or it has been withdrawn
     *   - released, or its release moment has not arrived
     *
     * A DRAFT is never student-visible however old it is, and a module with a
     * future release date is invisible until that moment even though it is
     * published.
     */
    public function isReleasedToStudents(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->status === self::STATUS_PUBLISHED
            && ($this->released_at === null || $this->released_at->lessThanOrEqualTo($at));
    }

    /** Lessons a student may currently see in this module. */
    public function studentLessons(?Carbon $at = null): HasMany
    {
        $at ??= now();

        return $this->lessons()
            ->where('status', CourseOfferingLesson::STATUS_PUBLISHED)
            ->where(fn ($query) => $query->whereNull('released_at')->orWhere('released_at', '<=', $at))
            ->whereIn('content_type', CourseOfferingLesson::STUDENT_CONTENT_TYPES);
    }

    public function displayStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PUBLISHED => $this->released_at !== null && $this->released_at->isFuture()
                ? 'Scheduled'
                : 'Published',
            self::STATUS_ARCHIVED => 'Archived',
            default => 'Draft',
        };
    }
}
