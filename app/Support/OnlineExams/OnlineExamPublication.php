<?php

namespace App\Support\OnlineExams;

use App\Models\OnlineExamSubmission;

/**
 * WHY A RESULT CANNOT BE RELEASED YET — ONE AUTHORITATIVE ANSWER.
 *
 * ── THE REPORTED FAILURE ───────────────────────────────────────────────────
 *
 * Exam 17, submission 12, admin publication returned a bare `422 Unprocessable
 * Content`. The submission was, in fact, completely eligible:
 *
 *     status              finalized
 *     result_review_state pending_review
 *     undecided marks     0        (question 39 carried an explicit recorded zero)
 *
 * The only thing standing in the way was the exam's own release policy:
 * `result_release_policy = 'after_exam_end'`, with the paper closing the following
 * day. `publishResult()` enforced that correctly — and then returned an HTTP status
 * code with no explanation, so the administrator was left staring at a 422 with no
 * idea that the only problem was a clock.
 *
 * ── WHAT WAS ACTUALLY WRONG ────────────────────────────────────────────────
 *
 * Not the rule. The rule is correct and is preserved exactly.
 *
 * The REJECTION was unstructured. A bare 422 says "no" without saying what is missing,
 * what would fix it, or when. So the same decision was made twice, in two places,
 * with no shared definition — which is how a legitimate policy check ends up looking
 * like a system fault.
 *
 * This class is that single definition. The controller asks it what is outstanding and
 * redirects with the answer; the results screen asks it the same question and shows
 * the same words before anyone clicks. They cannot disagree, because there is only one
 * implementation.
 *
 * ── NOTHING IS RELAXED ─────────────────────────────────────────────────────
 *
 * Every condition here is the same condition `publishResult()` enforced before, in the
 * same order, with the same strictness. Publication remains blocked in every case it
 * was blocked in previously. Only the presentation of the refusal changed: a redirect
 * carrying an explanation instead of an error document.
 */
final class OnlineExamPublication
{
    public const REASON_NOT_FINALIZED = 'not_finalized';
    public const REASON_NOT_AWAITING_REVIEW = 'not_awaiting_review';
    public const REASON_ALREADY_PUBLISHED = 'already_published';
    public const REASON_MARKING_INCOMPLETE = 'marking_incomplete';
    public const REASON_EXAM_NOT_ENDED = 'exam_not_ended';

    /**
     * EVERYTHING standing between this submission and release, most fundamental first.
     *
     * All reasons are returned rather than only the first, so an administrator is told
     * everything outstanding in one visit. Returning just the first would send them
     * back to fix one thing only to hit the next.
     *
     * @return list<array{code: string, message: string}>
     */
    public static function blockers(OnlineExamSubmission $submission): array
    {
        $submission->loadMissing(['exam', 'exam.questions', 'answerRows']);

        $blockers = [];

        // Already released. Not a fault — the screen should simply not offer it.
        if ($submission->status === OnlineExamSubmission::STATUS_RESULT_PUBLISHED) {
            $blockers[] = [
                'code' => self::REASON_ALREADY_PUBLISHED,
                'message' => get_phrase('This result has already been published.'),
            ];

            return $blockers;
        }

        // The two workflow-state gates. `result_review_state` falls back to
        // `pending_review` for a finalized legacy row that predates the column, which
        // is the same reading the publication path has always used.
        $reviewState = $submission->result_review_state ?: 'pending_review';

        if ($submission->status !== OnlineExamSubmission::STATUS_FINALIZED) {
            $blockers[] = [
                'code' => self::REASON_NOT_FINALIZED,
                'message' => get_phrase(
                    'This result has not been finalized by the lecturer yet. It cannot be released until the lecturer submits the completed marking for review.'
                ),
            ];
        }

        if ($reviewState !== 'pending_review') {
            $blockers[] = [
                'code' => self::REASON_NOT_AWAITING_REVIEW,
                'message' => get_phrase(
                    'This result is not awaiting administrative review. It must be handed over by the lecturer before it can be approved and released.'
                ),
            ];
        }

        /**
         * MARKING COMPLETENESS, INCLUDING ANSWERLESS QUESTIONS.
         *
         * This is the check that stops a result being released while marks no human
         * ever awarded. A manual question the student left blank counts, because the
         * marker must have made a decision about it — an unanswered question is not
         * the same as a decided one.
         */
        if ($submission->status !== OnlineExamSubmission::STATUS_RESULT_PUBLISHED
            && OnlineExamMarking::hasUndecidedManualQuestions($submission)) {
            $count = count(OnlineExamMarking::manualQuestionsAwaitingDecision($submission));
            $marks = OnlineExamMarking::undecidedManualMarks($submission);

            $blockers[] = [
                'code' => self::REASON_MARKING_INCOMPLETE,
                // ONE key string, not two arguments: `trans_choice($key, $number,
                // $replace)` takes the singular|plural forms inside the key itself.
                'message' => trans_choice(
                    '{1} :count written question still has no marking decision, worth :marks marks.|[2,*] :count written questions still have no marking decision, worth :marks marks.',
                    $count,
                    ['count' => $count, 'marks' => number_format($marks, 2)]
                ).' '.get_phrase('Ask the lecturer to complete the marking, including recording 0 for any unanswered question.'),
            ];
        }

        /**
         * THE RELEASE WINDOW — THE RULE THAT ACTUALLY BLOCKED SUBMISSION 12.
         *
         * Only applies under `after_exam_end`. `immediate` controls when an
         * administrator MAY publish; it never publishes by itself, and `manual` is
         * handled the same way once an administrator acts.
         */
        $exam = $submission->exam;

        if ($exam && ($exam->result_release_policy ?: 'immediate') === 'after_exam_end') {
            $end = $exam->scheduledEndAt();

            if ($end && now($exam->scheduleTimezone())->lt($end)) {
                $blockers[] = [
                    'code' => self::REASON_EXAM_NOT_ENDED,
                    /**
                     * THE TIME IS SUBSTITUTATED EXPLICITLY.
                     *
                     * `get_phrase()` takes a phrase and returns it; it accepts no
                     * replacement array, so passing one left the literal `:time` sitting
                     * in the sentence the administrator reads. An error message that
                     * still contains its own placeholder is worse than none.
                     */
                    'message' => str_replace(
                        ':time',
                        $end->format('d M Y H:i'),
                        get_phrase('This examination releases results only after it ends. It closes at :time; this result can be published after that.')
                    ),
                ];
            }
        }

        return $blockers;
    }

    public static function canPublish(OnlineExamSubmission $submission): bool
    {
        return self::blockers($submission) === [];
    }

    /**
     * One sentence naming everything outstanding, for a redirect flash message.
     */
    public static function summary(OnlineExamSubmission $submission): string
    {
        $blockers = self::blockers($submission);

        if ($blockers === []) {
            return '';
        }

        return implode(' ', array_column($blockers, 'message'));
    }
}