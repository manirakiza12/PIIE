<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One student's attempt at an Assignment.
 *
 * ONE ROW PER ATTEMPT, NOT ONE ROW OVERWRITTEN
 *
 * A UNIQUE index on (assignment, student, attempt_no) gives every attempt its
 * own durable record, so a resubmission cannot erase earlier work and grading
 * history is preserved without a separate audit table. The legacy K12 controller
 * already refused a second submission per (assignment, student) and always wrote
 * attempt 1, so this matches the data the legacy flow actually produced rather
 * than introducing a new rule - and legacy code calling ->first() still gets
 * attempt 1.
 *
 * DRAFT WORK IS NOT A SUBMISSION
 *
 * `is_draft` is the whole point of the draft state. Opening an assignment must
 * never be a submission, and neither must saving prepared work. Only an explicit
 * submit clears the flag, sets `submitted_at` and consumes an attempt.
 *
 * LATE IS DERIVED, NEVER TRUSTED FROM THE BROWSER
 *
 * `isLate()` compares the stored `submitted_at` against the assignment's
 * authoritative deadline, so the answer is a fact about two stored instants
 * rather than anything a client asserted. The legacy `status` enum is still
 * maintained for K12 compatibility, but the student and lecturer surfaces read
 * the derived value so the two can never disagree.
 *
 * MARKS ARE RELEASED, NOT MERELY RECORDED
 *
 * A lecturer may record a mark while still deciding on it. `marks_released_at`
 * is the gate: until it is set, the student sees neither the mark nor the
 * feedback. It is a timestamp rather than a boolean so "when was this returned"
 * remains answerable afterwards.
 *
 * @property int $id
 * @property int $assignment_id
 * @property int $student_id
 * @property int $attempt_no
 * @property bool $is_draft
 * @property string|null $submission
 * @property string|null $file_path
 * @property string|null $file_name
 * @property Carbon|null $submitted_at
 * @property string|null $marks_awarded
 * @property string|null $feedback
 * @property Carbon|null $marks_released_at
 */
class AssignmentSubmission extends Model
{
    use HasFactory;

    protected $table = 'assignment_submissions';

    protected $fillable = [
        // legacy shape - unchanged
        'assignment_id', 'student_id', 'submission', 'file_path', 'link',
        'submitted_at', 'marks_awarded', 'feedback', 'status',
        // Course Offering shape
        'course_offering_id', 'attempt_no', 'is_draft', 'file_name', 'file_size',
        'file_mime', 'graded_at', 'graded_by', 'returned_at', 'returned_by',
        'marks_released_at', 'idempotency_key',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'returned_at' => 'datetime',
        'marks_released_at' => 'datetime',
        'started_at' => 'datetime',
        'is_draft' => 'boolean',
        'attempt_no' => 'integer',
        'file_size' => 'integer',
        'marks_awarded' => 'float',
        'course_offering_id' => 'integer',
    ];

    public function scopeK12(Builder $query): Builder
    {
        return $query->whereNull('course_offering_id');
    }

    public function assignment()
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    /**
     * The evidence attached to THIS attempt.
     *
     * A submission is no longer one file: it can carry a written response (stored
     * on this row), a document, a photograph, a recording and a link at once. Each
     * is a row here rather than a column, so nothing overwrites anything else.
     *
     * Ordered by id, so the evidence appears in the order the student added it
     * rather than in whatever order a join happens to produce.
     */
    public function items()
    {
        return $this->hasMany(AssignmentSubmissionItem::class, 'assignment_submission_id')
            ->orderBy('id');
    }

    /**
     * This attempt's answers, one row per question.
     *
     * Present for EVERY question of a question-based assignment, whether or not
     * the student answered it - so `text_response` being null is the single
     * stored statement that a question was not answered. See
     * `AssignmentQuestionResponse` for why the row's existence carries no meaning.
     */
    public function questionResponses()
    {
        return $this->hasMany(AssignmentQuestionResponse::class, 'assignment_submission_id')
            ->orderBy('id');
    }

    /**
     * This attempt's answers keyed by question id.
     *
     * Keyed rather than listed because every reader asks the same question - "what
     * did they answer to THIS question" - and answering it by searching a list
     * means each reader gets a subtly different answer when an id is missing.
     *
     * @return \Illuminate\Support\Collection<int, AssignmentQuestionResponse>
     */
    public function answersByQuestion()
    {
        return $this->questionResponses->keyBy('assignment_question_id');
    }

    /**
     * The marks awarded across this attempt's questions, or null when nothing has
     * been marked.
     *
     * DERIVED from the per-question marks. This is the value written to
     * `marks_awarded` on save, so the Gradebook, the release gate and the
     * `released_mark` module completion rule all read one number that was computed
     * rather than typed.
     */
    public function questionMarksTotal(): ?float
    {
        $marked = $this->questionResponses->filter(
            fn (AssignmentQuestionResponse $response) => $response->marks_awarded !== null
        );

        if ($marked->isEmpty()) {
            return null;
        }

        return round((float) $marked->sum(fn ($response) => (float) $response->marks_awarded), 2);
    }

    /**
     * The mark and feedback the student may read.
     *
     * Per-question feedback is shown as well as the overall, because a total of
     * 17/20 is not feedback - a student needs to know WHICH question lost the
     * marks, and only a lecturer can say. Nothing here is readable before
     * `release()`, because the whole collection is only populated from a released
     * attempt by the service.
     */
    public function releasedQuestionFeedback(): \Illuminate\Support\Collection
    {
        return $this->questionResponses
            ->filter(fn (AssignmentQuestionResponse $r) => filled($r->feedback) || $r->marks_awarded !== null)
            ->values();
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /**
     * Was this attempt recorded after the authoritative deadline?
     *
     * Computed from `submitted_at` and the assignment's own deadline on every
     * read, so it can never be stale, never disagrees with the assignment screen
     * and never depends on anything the submitting browser said. A draft has no
     * submission instant and so is never late.
     */
    public function isLate(): bool
    {
        if ($this->is_draft || ! $this->submitted_at) {
            return false;
        }

        $deadline = $this->relationLoaded('assignment')
            ? $this->assignment
            : Assignment::query()->find($this->assignment_id);

        $limit = $deadline?->effectiveDeadline();

        return $limit !== null && $this->submitted_at->greaterThan($limit);
    }

    public function isSubmitted(): bool
    {
        return ! $this->is_draft && $this->submitted_at !== null;
    }

    /**
     * Has the lecturer released the mark and feedback to the student?
     *
     * A mark that exists but is unreleased is invisible to the student, so this
     * - not the mere presence of `marks_awarded` - is what authorises reading a
     * result.
     */
    public function isReleased(): bool
    {
        return $this->marks_released_at !== null;
    }

    public function isGraded(): bool
    {
        return $this->marks_awarded !== null;
    }

    /** Percentage of the assignment maximum, or null when unmarked. */
    public function percentOfMaximum(): ?float
    {
        $max = (int) ($this->assignment?->max_marks ?? 0);

        if ($this->marks_awarded === null || $max <= 0) {
            return null;
        }

        return round(((float) $this->marks_awarded / $max) * 100, 2);
    }

    public function hasFile(): bool
    {
        return ! empty($this->file_path);
    }

    /**
     * The written response, preferring the canonical rich-text field.
     *
     * `text_response` is what a Course Offering submission writes; `submission` is
     * the legacy TEXT column the K12 screens read. The two never both carry HEI
     * content, so this is a preference rather than a merge - and nothing is
     * mirrored between them, which is what would let them drift.
     */
    public function writtenResponse(): ?string
    {
        $rich = $this->text_response;

        if (is_string($rich) && trim($rich) !== '') {
            return $rich;
        }

        $legacy = $this->submission;

        return is_string($legacy) && trim($legacy) !== '' ? $legacy : null;
    }

    /**
     * Did the student write anything?
     *
     * Asks `writtenResponse()` rather than testing one column, so it cannot
     * disagree with what the student actually sees rendered.
     */
    public function hasText(): bool
    {
        return $this->writtenResponse() !== null;
    }

    public function displayName(): string
    {
        return $this->file_name ?: 'Submitted work';
    }

    public function sizeLabel(): string
    {
        $bytes = (int) ($this->file_size ?? 0);

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
