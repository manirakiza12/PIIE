<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\CourseContent\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;

class QuestionBank extends Model
{
    use HasFactory;

    protected $table = 'question_banks';

    protected $fillable = [
        'school_id', 'subject_id', 'programme_id', 'session_id', 'topic_id', 'subtopic_id', 'question', 'type',
        'option_a', 'option_b', 'option_c', 'option_d',
        'correct_ans', 'question_schema_version', 'question_config', 'marking_config',
        'marks', 'difficulty', 'topic', 'subtopic', 'bloom_level', 'status', 'created_by'
    ];

    protected $appends = ['normalized_type', 'correct_answer'];

    protected $casts = [
        'marks' => 'integer',
        'question_schema_version' => 'integer',
    ];

    /**
     * The sanitised prompt, for a `{!! !!}` render. A method, not an accessor, so
     * the bank tables and the CSV exporter keep escaping by default.
     */
    public function prosePrompt(): string
    {
        return app(HtmlSanitizer::class)->sanitize($this->attributes['question'] ?? '');
    }

    public function plainPrompt(int $limit = 160): string
    {
        return app(HtmlSanitizer::class)->toText($this->attributes['question'] ?? '', $limit);
    }

    /**
     * The bank's prompt is AUTHORED PROSE, filtered on the way in.
     *
     * The bank is shared - an entry authored here is copied into many exams, and is
     * rendered by the admin bank screens as well as the teacher one. A rich editor
     * on this form is therefore only safe with the filter attached, which is why
     * the filter lives on the model rather than in whichever form happened to post
     * it. `correct_ans` and the option letters are left untouched, for the reason
     * given on `OnlineExamQuestion`.
     */
    public function setQuestionAttribute($value): void
    {
        if ($value === null || trim((string) $value) === '') {
            $this->attributes['question'] = $value;

            return;
        }

        $this->attributes['question'] = app(HtmlSanitizer::class)->sanitize((string) $value);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function programme()
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    public function session()
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    public function topic() { return $this->belongsTo(QuestionTopic::class, 'topic_id'); }
    public function subtopic() { return $this->belongsTo(QuestionTopic::class, 'subtopic_id'); }
    public function tags() { return $this->belongsToMany(QuestionTag::class, 'question_bank_tag'); }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForSchool($query, int $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeVisibleToTeacher($query, int $teacherId, int $schoolId)
    {
        return $query->forSchool($schoolId)
            ->where(function ($q) use ($teacherId) {
                $q->where('created_by', $teacherId)
                    ->orWhereNull('created_by');
            });
    }

    public function getNormalizedTypeAttribute(): string
    {
        if ($this->question_schema_version !== null) {
            return \App\Support\OnlineExams\QuestionContract::normalize($this)['type'];
        }
        $map = [
            'mcq' => 'multiple_choice',
            'true_false' => 'true_false',
            'short' => 'short_answer',
            'essay' => 'essay',
            'fill_blank' => 'fill_blank',
        ];

        return $map[$this->type] ?? 'multiple_choice';
    }

    public function setTypeAttribute($value): void
    {
        $map = [
            'multiple_choice' => 'mcq',
            'mcq' => 'mcq',
            'true_false' => 'true_false',
            'short_answer' => 'short',
            'short' => 'short',
            'essay' => 'essay',
            'fill_blank' => 'fill_blank',
        ];

        $this->attributes['type'] = $map[$value] ?? $value;
    }

    public function getCorrectAnswerAttribute(): ?string
    {
        return $this->correct_ans;
    }

    public function setCorrectAnswerAttribute($value): void
    {
        $this->attributes['correct_ans'] = $value;
    }
}
