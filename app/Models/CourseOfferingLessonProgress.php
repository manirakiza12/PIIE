<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one student has actually done with one lesson.
 *
 * A ROW MEANS ENGAGEMENT. "Not started" is the absence of a row, not a stored
 * value, so there is no third state to keep in step and no way for a lesson to
 * be counted as begun without somebody having genuinely begun it.
 *
 * This is the answer to "do not calculate progress merely because a page was
 * opened". Opening a lesson records `in_progress` and a `last_viewed_at` - real
 * engagement, honestly labelled. It NEVER records completion. Completion is a
 * separate, explicit act, and it is only permitted for a lesson whose
 * completion rule PIIE can actually satisfy.
 *
 * The unique index on (lesson, student) is what makes pressing "Mark Complete"
 * twice - or a retried request - update one row instead of inflating progress.
 *
 * @property int $course_offering_lesson_id
 * @property int $student_id
 * @property string $status
 * @property Carbon|null $completed_at
 */
class CourseOfferingLessonProgress extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'course_offering_lesson_progress';

    protected $fillable = [
        'school_id', 'course_offering_id', 'course_offering_lesson_id', 'student_id',
        'status', 'started_at', 'completed_at', 'last_viewed_at',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'course_offering_id' => 'integer',
        'course_offering_lesson_id' => 'integer',
        'student_id' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseOfferingLesson::class, 'course_offering_lesson_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
