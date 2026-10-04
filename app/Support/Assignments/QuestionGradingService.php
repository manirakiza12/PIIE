<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentQuestion;
use App\Models\AssignmentQuestionResponse;
use App\Models\AssignmentSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marking a QUESTION-BASED attempt, question by question.
 *
 * THE TOTAL IS COMPUTED, NEVER TYPED
 *
 * This is the whole point of marking per question. The lecturer gives a mark
 * against each question's OWN maximum, and the assignment total is the sum of
 * those. `assignment_submissions.marks_awarded` is then WRITTEN from that sum.
 *
 * The brief asks for "assignment total is calculated automatically from question
 * marks" and "do not require lecturer to independently type another contradictory
 * total". Enforced structurally, by never accepting a second number: there is no
 * field on the marking form for an overall mark when the assignment has
 * questions, so there is nothing to contradict the per-question marks with.
 *
 * And because the total is the SAME value the rest of the system already reads,
 * nothing downstream had to change:
 *
 *   `GradingService::release()`    gates on `isGraded()`, which is `marks_awarded`
 *   `released_mark` module rule    gates on `marks_released_at`
 *   `GradebookFeed`                reads `marks_awarded` off the submission
 *
 * So a question-based assignment keeps the existing `completion_rule` semantics
 * exactly. For Assignment #3, configured `released_mark`, a submission alone still
 * does NOT complete Module 1 - only a released result does.
 *
 * MARKS ARE BOUNDED PER QUESTION, AND THE BOUNDS CANNOT DRIFT
 *
 * A mark above the question's own maximum is REFUSED, never clamped. Clamping would
 * hide a slip, and a stored 7 against a maximum of 5 would make the total and any
 * future gradebook quietly wrong.
 *
 * The maximum is itself frozen: `QuestionService` refuses to edit a question once
 * any student has submitted, so a recorded mark can never find itself outside the
 * bounds afterwards. That is the reason the authoring freeze exists, and this is
 * where it pays off.
 *
 * MARKING IS NOT FROZEN - ONLY AUTHORING IS
 *
 * A lecturer may revise a mark, add feedback later, or re-release. Giving a
 * different mark to a question is grading, which is the second half of the
 * lifecycle and is expected to change. Changing what the question ASKED or what it
 * is WORTH is authoring, and that is what is locked.
 *
 * OVERALL FEEDBACK IS KEPT, AND IS SEPARATE
 *
 * `assignment_submissions.feedback` is untouched and still written. A total of
 * 17/20 is not feedback; per-question feedback is what tells a student which
 * question lost the marks, and only a lecturer can say. Both are shown, and both
 * are released by the same single `release()` call, so a student never sees half a
 * result.
 */
class QuestionGradingService
{
    public function __construct(
        private readonly AssignmentAccess $access,
    ) {}

    /**
     * Record marks and feedback against each question, and derive the total.
     *
     * `$attributes` is the raw request shape:
     *
     *   marks[question_id]     the mark awarded against THAT question
     *   feedback[question_id]  feedback on THAT question
     *   feedback              the overall feedback, written as before
     *
     * A question absent from `marks` is left exactly as it was - not zeroed. A
     * marker working down a long paper may deliberately mark three of four
     * questions and come back, and silently writing 0 for the fourth would be
     * indistinguishable from having decided the student earned nothing.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function gradeByQuestions(
        User $actor,
        AssignmentSubmission $submission,
        array $attributes
    ): AssignmentSubmission {
        $assignment = $this->assertCanGrade($actor, $submission);

        if ($submission->is_draft) {
            throw new \DomainException('This is prepared work that was never submitted, so there is nothing to grade.');
        }

        $questions = $assignment->questions()->inReadingOrder()->get();

        if ($questions->isEmpty()) {
            // A GENERIC assignment. This is the wrong door for it, and saying so
            // plainly beats writing per-question rows against an assignment that
            // has no questions.
            throw new \DomainException(
                'This assignment has no questions, so it is marked as a single piece of work. '
                .'Use the standard mark field.'
            );
        }

        $marksIn = is_array($attributes['marks'] ?? null) ? $attributes['marks'] : [];
        $feedbackIn = is_array($attributes['feedback_by_question'] ?? null)
            ? $attributes['feedback_by_question']
            : [];

        $cleanMarks = [];
        $cleanFeedback = [];

        foreach ($questions as $question) {
            $id = (int) $question->id;

            if (array_key_exists($id, $marksIn) || array_key_exists((string) $id, $marksIn)) {
                $raw = $marksIn[$id] ?? $marksIn[(string) $id] ?? null;
                $cleanMarks[$id] = $this->normaliseQuestionMark($raw, $question);
            }

            if (array_key_exists($id, $feedbackIn) || array_key_exists((string) $id, $feedbackIn)) {
                $raw = $feedbackIn[$id] ?? $feedbackIn[(string) $id] ?? null;
                $cleanFeedback[$id] = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
            }
        }

        // A question id the lecturer's form could not have produced. Refused rather
        // than skipped, so a tampered or stale form cannot write a mark onto a
        // question that belongs to a different assignment.
        $known = $questions->map(fn (AssignmentQuestion $q) => (int) $q->id)->all();
        foreach (array_keys($marksIn) as $id) {
            if (! in_array((int) $id, $known, true)) {
                throw ValidationException::withMessages([
                    'marks' => 'That mark refers to a question which is not part of this assignment.',
                ]);
            }
        }

        return DB::transaction(function () use ($actor, $submission, $assignment, $questions, $cleanMarks, $cleanFeedback, $attributes) {
            // Lock and write ONCE, for the same reason `GradingService` does it: a
            // concurrent release must not be able to be overwritten with a stale,
            // unreleased total.
            $locked = AssignmentSubmission::query()
                ->whereKey($submission->id)
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($questions as $question) {
                $id = (int) $question->id;

                $row = AssignmentQuestionResponse::query()->firstOrNew([
                    'assignment_submission_id' => $locked->id,
                    'assignment_question_id' => $id,
                ]);

                $row->school_id = (int) $assignment->school_id;
                $row->assignment_id = (int) $assignment->id;
                $row->course_offering_id = $assignment->course_offering_id;

                if (array_key_exists($id, $cleanMarks)) {
                    $row->marks_awarded = $cleanMarks[$id];
                    $row->graded_at = now();
                    $row->graded_by = $actor->id;
                }

                if (array_key_exists($id, $cleanFeedback)) {
                    $row->feedback = $cleanFeedback[$id];
                }

                $row->save();
            }

            // Recomputed from the stored rows rather than from the request, so the
            // total cannot disagree with what is actually recorded - including when
            // a lecturer revises one question and leaves the rest.
            $locked->load('questionResponses');
            $total = $locked->questionMarksTotal();

            if ($total !== null) {
                $locked->marks_awarded = $total;
            }

            $overall = $attributes['feedback'] ?? null;
            $locked->feedback = is_string($overall) && trim($overall) !== ''
                ? trim($overall)
                : $locked->feedback;

            if ($total !== null) {
                $locked->graded_at = now();
                $locked->graded_by = $actor->id;
                $locked->status = 'graded';
            }

            $locked->save();

            return $locked->fresh(['questionResponses']);
        });
    }

    /**
     * One question's mark, bounded by that question's own maximum.
     *
     * Compared in CENTS, so a maximum of 5 and a mark of 5.00 agree and a maximum
     * of 0.1 and a mark of 0.1 do not fail on floating-point noise.
     *
     * @return float|null null when the field was left blank, which means "not
     *                     marked" rather than "marked zero"
     */
    private function normaliseQuestionMark($value, AssignmentQuestion $question): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'marks.'.$question->id => 'Enter a mark as a number.',
            ]);
        }

        $mark = round((float) $value, 2);
        $maximum = round((float) $question->marks, 2);

        if ($mark < 0) {
            throw ValidationException::withMessages([
                'marks.'.$question->id => 'A mark cannot be negative.',
            ]);
        }

        if ((int) round($mark * 100) > (int) round($maximum * 100)) {
            throw ValidationException::withMessages([
                'marks.'.$question->id => 'The mark cannot be more than this question is worth ('
                    .$question->marksLabelWithUnit().').',
            ]);
        }

        return $mark;
    }

    /**
     * Prove this actor may mark this attempt, INSIDE its own Offering.
     *
     * The PARENT ASSIGNMENT is the authority for tenant and Offering, resolved
     * through the manager path, and the attempt is then scoped to that assignment.
     * Deliberately not the other way round: `assignment_submissions` carries no
     * school_id of its own precisely so that this cannot be short-circuited by a
     * denormalised value that has drifted. A submission from another tenant or
     * another delivery is a 404, not a 403.
     */
    private function assertCanGrade(User $actor, AssignmentSubmission $submission): Assignment
    {
        $assignment = Assignment::query()->whereKey($submission->assignment_id)->first();

        if (! $assignment || $assignment->course_offering_id === null) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(404, 'Assignment not found.');
        }

        $this->access->resolveForManager(
            $actor,
            (int) $assignment->course_offering_id,
            (int) $assignment->id
        );

        return $assignment;
    }
}
