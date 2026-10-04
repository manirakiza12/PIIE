<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE student's answer to ONE question on ONE attempt.
 *
 * THE ROW'S EXISTENCE CARRIES NO MEANING. ITS COLUMNS DO.
 *
 * A response row is created for EVERY question of a question-based assignment the
 * moment a draft is saved or work is submitted - including questions the student
 * left blank.
 *
 * This is the one design decision in the whole feature that is easy to get
 * subtly wrong. If a row only existed once something was answered, then "not
 * answered" would have to be INFERRED from a missing row, and inferred separately
 * by the student form, the validation path, the marking screen and any future
 * gradebook. Four readers, four chances to disagree, and the most likely
 * disagreement is the worst one: a missed question silently counted as a zero in
 * the mark total while the marking screen shows it as blank.
 *
 * So the row always exists and `text_response` being null is the single,
 * unambiguous, stored statement that the question was not answered.
 *
 * ONE ROW PER (ATTEMPT, QUESTION), UNIQUELY
 *
 * A UNIQUE index on (assignment_submission_id, assignment_question_id) makes
 * saving a draft five times idempotent rather than accumulating a copy per save -
 * which is what "resume a draft" needs. The database decides, not a
 * read-then-write check, so two concurrent saves cannot both win.
 *
 * A resubmission is a NEW attempt and therefore a new set of rows. Attempt 1's
 * answers stay readable and markable, so grading history survives without a
 * separate audit table - the same property `assignment_submissions` already has.
 *
 * THE ASSIGNMENT TOTAL IS DERIVED FROM THESE MARKS
 *
 * `marks_awarded` here is bounded by the question's own `marks`. The sum across
 * a submission's responses is what gets written to
 * `assignment_submissions.marks_awarded` - the value the Gradebook reads, the
 * release gate checks, and the `released_mark` module completion rule depends on.
 *
 * A lecturer is therefore never asked to type a second, independent total that
 * could contradict the marks they just gave per question. The two cannot
 * disagree, because one is computed from the other.
 *
 * @property int $id
 * @property int $assignment_question_id
 * @property int $assignment_submission_id
 * @property string|null $text_response
 * @property float|null $marks_awarded
 * @property string|null $feedback
 */
class AssignmentQuestionResponse extends Model
{
    use HasFactory;

    protected $table = 'assignment_question_responses';

    protected $fillable = [
        'school_id', 'assignment_question_id', 'assignment_id',
        'assignment_submission_id', 'course_offering_id',
        'text_response', 'marks_awarded', 'feedback', 'graded_at', 'graded_by',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'assignment_question_id' => 'integer',
        'assignment_id' => 'integer',
        'assignment_submission_id' => 'integer',
        'course_offering_id' => 'integer',
        'marks_awarded' => 'float',
        'graded_at' => 'datetime',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(AssignmentQuestion::class, 'assignment_question_id');
    }

    /** The attempt this answer belongs to. */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmission::class, 'assignment_submission_id');
    }

    /**
     * The evidence filed against this question on this attempt.
     *
     * Scoped by BOTH keys deliberately. A question id alone would return another
     * attempt's photograph of the same question, which is exactly the mistake that
     * would make attempt 1's evidence appear as attempt 2's.
     */
    public function items(): HasMany
    {
        return $this->hasMany(AssignmentSubmissionItem::class, 'assignment_submission_id', 'assignment_submission_id')
            ->whereColumn('assignment_submission_items.assignment_question_id', 'assignment_question_responses.assignment_question_id')
            ->orderBy('id');
    }

    /** Did the student write anything for this question? */
    public function hasWrittenAnswer(): bool
    {
        $text = $this->text_response;

        return is_string($text) && trim($text) !== '';
    }

    /**
     * Does this question carry ANY answer at all - written text or evidence?
     *
     * "Not answered" is the fact a required-question check needs, and it is
     * derived here so the student form, the submit validation and the marking
     * screen all agree on what counts as an answer.
     */
    public function isAnswered(): bool
    {
        if ($this->hasWrittenAnswer()) {
            return true;
        }

        return AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $this->assignment_submission_id)
            ->where('assignment_question_id', $this->assignment_question_id)
            ->get()
            ->contains(fn (AssignmentSubmissionItem $item) => $item->isSubstantive());
    }

    public function hasMarks(): bool
    {
        return $this->marks_awarded !== null;
    }

    public function marksLabel(): string
    {
        if ($this->marks_awarded === null) {
            return 'not marked';
        }

        $value = (float) $this->marks_awarded;

        return ($value == (int) $value) ? (string) (int) $value : (string) $value;
    }
}
