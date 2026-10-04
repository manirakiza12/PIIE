<?php

namespace App\Models;

use App\Support\CourseContent\HtmlSanitizer;
use Illuminate\Database\Eloquent\Model;

class OnlineExamQuestion extends Model
{
    public $timestamps = false;

    protected $table = 'online_exam_questions';

    protected $fillable = [
        'online_exam_id', 'question_bank_id', 'question', 'type',
        'option_a', 'option_b', 'option_c', 'option_d',
        'correct_ans', 'question_schema_version', 'question_config', 'marking_config',
        'marks', 'sort_order'
    ];

    protected $appends = ['normalized_type', 'correct_answer'];

    protected $casts = [
        'marks' => 'integer',
        'sort_order' => 'integer',
        'question_schema_version' => 'integer',
    ];

    /**
     * The sanitised prompt, for a `{!! !!}` render.
     *
     * A method, not an accessor, for the reason on `OnlineExam::proseInstructions()`
     * - the engine's existing views must keep escaping unless they ask not to.
     *
     * Filtered on READ as well as on write, which is deliberate belt-and-braces:
     * the 24 existing questions were authored before any sanitizer existed, and a
     * raw render of an unfiltered stored value is the one thing that would turn a
     * historic row into live markup. Two doors, one rule - the same principle the
     * governed-image policy already uses.
     */
    public function prosePrompt(): string
    {
        return app(HtmlSanitizer::class)->sanitize($this->attributes['question'] ?? '');
    }

    /**
     * The prompt as plain text - for a title, a CSV export or a search index,
     * where markup would be a defect rather than content.
     */
    public function plainPrompt(int $limit = 160): string
    {
        return app(HtmlSanitizer::class)->toText($this->attributes['question'] ?? '', $limit);
    }

    /**
     * Does this question carry any statement a reader could actually read?
     *
     * ── WHY A QUESTION MODEL NEEDS TO ASK THIS ──────────────────────────────
     *
     * Every one of exam 20's four questions is stored as `<p><br></p>` — Summernote's
     * empty document. Three surfaces then rendered that as if it were content:
     *
     *   - the student attempt page, which showed a card with only a number and marks,
     *     and (for the short answer) nothing to type into;
     *   - the lecturer's results page, which labelled each answer
     *     `Str::limit(strip_tags($question), 90)` — an empty string, so the marker
     *     could not tell which question they were marking;
     *   - the lecturer's marking queue, same expression, same blank.
     *
     * `hasPrompt()` gives all three ONE definition of "there is a question here", so a
     * historic row with no stored text produces an explicit, named state rather than
     * an empty cell that reads as a layout fault.
     *
     * It is a read-side question only. Nothing repairs or invents the text: the row is
     * left exactly as it is, and a marker sees that the paper is faulty.
     */
    public function hasPrompt(): bool
    {
        return ! \App\Support\OnlineExams\QuestionPrompt::isEmpty($this->attributes['question'] ?? null);
    }

    /**
     * A single-line label that is NEVER an empty string.
     *
     * For the dense marking tables, where the full prose cannot be shown but the
     * marker must still be able to identify the question. A missing prompt yields the
     * explicit empty-state label rather than `''`.
     */
    public function promptLabel(int $limit = 90): string
    {
        $text = $this->plainPrompt($limit);

        if ($text === '') {
            return \App\Support\OnlineExams\QuestionPrompt::EMPTY_LABEL;
        }

        return $text;
    }

    /**
     * The prompt for a `{!! !!}` render, with the empty state made EXPLICIT.
     *
     * ── WHY THIS IS NOT `prosePrompt() ?: $label` ─────────────────────────────
     *
     * That expression looks correct and is not. The empty rich-text document is
     * `<p><br></p>` — eleven characters of truthy markup that renders as nothing — so
     * `prosePrompt() ?: ...` never reaches the fallback and the caller gets a blank
     * element with no explanation. That is exactly the reported symptom, reproduced
     * inside the fix for it.
     *
     * The emptiness test is `hasPrompt()`, which asks the sanitizer whether there is
     * any actual TEXT, and that is the same question `HtmlSanitizer` answers for lesson
     * bodies and assignment instructions.
     *
     * The label is wrapped and coloured here so no view can invent a different one, and
     * the return is trusted markup: it is either sanitized academic content or a
     * hard-coded constant.
     */
    public function prosePromptOrEmptyLabel(): string
    {
        if (! $this->hasPrompt()) {
            return '<span class="text-danger" data-testid="question-prompt-empty">'
                .e(\App\Support\OnlineExams\QuestionPrompt::EMPTY_LABEL)
                .'</span>';
        }

        return $this->prosePrompt();
    }

    /**
     * The position this question occupies on its paper, 1-based.
     *
     * `sort_order` is the AUTHORING order. A student may see a different order (the
     * attempt deliberately shuffles questions per attempt), so the position a reader
     * must be shown comes from the attempt, not from the row. Callers that have the
     * ordered collection pass the index; callers that do not fall back to the stored
     * order, which is still better than showing nothing.
     */
    public function positionForAttempt(int $fallbackIndex): int
    {
        return max(1, $fallbackIndex);
    }

    /**
     * True when this question is marked BY HAND.
     *
     * Read from the engine's own `QuestionContract::normalize()` - the very call
     * the marking queue and the result views use - rather than from a second list
     * of type names. That matters more than it looks: the legacy path and the
     * structured path do NOT agree on which types are automatic, so a local list
     * would drift from the marking queue, and a question would be filtered as prose
     * on a surface where it is really a machine-read key.
     */
    public function isManualMarking(): bool
    {
        $contract = \App\Support\OnlineExams\QuestionContract::normalize($this, true);

        return ($contract['marking']['mode'] ?? 'manual') === 'manual';
    }

    public function exam()
    {
        return $this->belongsTo(OnlineExam::class, 'online_exam_id');
    }

    public function questionBank()
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    public function answers()
    {
        return $this->hasMany(OnlineExamAnswer::class, 'question_id');
    }

    public function scopeForExam($query, int $examId)
    {
        return $query->where('online_exam_id', $examId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The prompt is AUTHORED PROSE, so it is filtered on the way in.
     *
     * `correct_ans`, the option letters and the config/marking JSON are NOT
     * touched - see the header note. The sanitizer is not idempotent on plain
     * text (it re-encodes "5 > 3" to "5 &gt; 3") and these columns are compared as
     * strings by automatic marking, so filtering them would score every such
     * question zero.
     */
    public function setQuestionAttribute($value): void
    {
        if ($value === null || trim((string) $value) === '') {
            $this->attributes['question'] = $value;

            return;
        }

        $this->attributes['question'] = app(HtmlSanitizer::class)->sanitize((string) $value);
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
