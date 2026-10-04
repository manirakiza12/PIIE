<?php

namespace App\Models;

use App\Support\CourseContent\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;

class OnlineExamAnswer extends Model
{
    protected $table = 'online_exam_answers';

    protected $fillable = [
        'submission_id',
        'question_id',
        'answer_revision',
        'answer_schema_version',
        'answer_payload',
        'selected_option',
        'answer_text',
        'awarded_marks',
        'is_correct',
        'marked_by',
        'marked_at',
        'teacher_comment',
    ];

    protected $casts = [
        'answer_revision' => 'integer',
        'answer_schema_version' => 'integer',
        'awarded_marks' => 'decimal:2',
        'is_correct' => 'boolean',
        'marked_at' => 'datetime',
    ];

    public function submission()
    {
        return $this->belongsTo(OnlineExamSubmission::class, 'submission_id');
    }

    public function question()
    {
        return $this->belongsTo(OnlineExamQuestion::class, 'question_id');
    }

    /**
     * The student's written answer, sanitised for a `{!! !!}` render.
     *
     * ── WHY THIS IS THE ONLY PLACE AN ANSWER IS FILTERED ────────────────────
     *
     * Not on write, and that is the whole point. `answer_text` is what the
     * lecturer marking this answer will read, and it is the words the student
     * actually typed. Escaping on the way in would store "df/dx &lt; 0" and the
     * marker would read the entity rather than the inequality. The stored value is
     * a record of the attempt; this is the single point where it becomes HTML.
     *
     * No mutator is defined for `answer_text` on purpose. One must not be added
     * later without revisiting this decision.
     */
    public function proseAnswer(): string
    {
        return app(HtmlSanitizer::class)->sanitize($this->attributes['answer_text'] ?? '');
    }

    public function markedBy()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
