<?php

namespace App\Support\OnlineExams;

use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;

/** Existing deterministic marking rules; future settings belong at this boundary. */
class OnlineExamMarking
{
    public static function isAutomatic(OnlineExamQuestion $question): bool
    {
        if ($question->question_schema_version !== null) {
            $type = QuestionContract::normalize($question, true)['type'];
            return in_array($type, ['multiple_select', 'numeric', 'fill_blank', 'matching', 'ordering'], true)
                && isset(QuestionContract::normalize($question, true)['marking']);
        }
        if (!in_array($question->type, ['mcq', 'multiple_choice', 'true_false'], true)) return false;
        $key = AnswerKey::forQuestion($question);
        if ($question->normalized_type === 'true_false') {
            return $key !== null;
        }
        return $question->normalized_type === 'multiple_choice'
            && $key !== null;
    }

    public static function manualQuestions($query): void
    {
        $query->where(function ($q) {
            $q->whereNotIn('type', ['mcq', 'multiple_choice', 'true_false'])
                ->orWhereNull('correct_ans')
                ->orWhere(function ($tf) {
                    $tf->where('type', 'true_false')->whereRaw("LOWER(TRIM(correct_ans)) NOT IN ('true', 'false')");
                })->orWhere(function ($mcq) {
                    $mcq->whereIn('type', ['mcq', 'multiple_choice'])->where(function ($invalid) {
                        $invalid->where(function ($notCanonical) {
                            $notCanonical->whereRaw("LOWER(TRIM(correct_ans)) NOT IN ('a', 'b', 'c', 'd')");
                            foreach (['a', 'b', 'c', 'd'] as $key) {
                                $notCanonical->orWhere(function ($option) use ($key) {
                                    $option->whereRaw('LOWER(TRIM(correct_ans)) = ?', [$key])
                                        ->whereRaw("TRIM(COALESCE(option_{$key}, '')) = ''");
                                });
                            }
                        })->where(function ($notLegacyText) {
                            foreach (['a', 'b', 'c', 'd'] as $key) {
                                $notLegacyText->whereRaw("(TRIM(COALESCE(option_{$key}, '')) = '' OR LOWER(TRIM(correct_ans)) <> LOWER(TRIM(option_{$key})))");
                            }
                        });
                    });
                });
        })->where(function ($q) {
            // Structured Batch 2 objective types are auto-marked. Structured
            // manual types are not enabled yet, so they must not enter the
            // legacy manual-marking queue.
            $q->whereNull('question_schema_version')
                ->orWhere('question_schema_version', '<>', QuestionContract::STRUCTURED_VERSION);
        });
    }

    public static function responses($query): void
    {
        $query->where(function ($q) {
            $q->whereRaw("TRIM(COALESCE(answer_text, '')) <> ''")
                ->orWhereRaw("TRIM(COALESCE(selected_option, '')) <> ''")
                ->orWhereRaw("TRIM(COALESCE(answer_payload, '')) <> ''");
        });
    }

    public static function unmarked($query): void
    {
        $query->where(function ($q) {
            $q->whereNull('awarded_marks')->orWhereNull('marked_at')->orWhereNull('marked_by');
        });
    }

    /**
 * IS THIS HTML ACTUALLY AN ANSWER, OR JUST AN EMPTY EDITOR?
 *
 * ── WHY THIS EXISTS: EXAM 19, QUESTION 3 ─────────────────────────────────
 *
 * Submission 13, qid 45, a 10-mark short answer. The student typed, the page showed a
 * green "Saved", and the row saved was:
 *
 *     answer_text = '<p><br></p>'      (11 characters)
 *
 * `<p><br></p>` is what Summernote writes for an EMPTY document. The old check was a
 * string comparison, so eleven characters of nothing counted as an answer, and:
 *
 *   - `summary()['pending']` was 0, so the paper looked fully marked;
 *   - the lecturer's "What the student wrote" column rendered an apparently empty
 *     cell, because the stored value genuinely contained no words;
 *   - `manualQuestionsAwaitingDecision()` reported the question as ANSWERED, so it
 *     never appeared as outstanding and nobody was ever asked to look at it.
 *
 * A marker had awarded 5 of 10 marks against that empty string.
 *
 * The rule is therefore "does this contain any actual content", judged by removing
 * everything an empty rich-text document is made of — tags, breaks, non-breaking
 * spaces, zero-width characters and whitespace — and seeing whether anything is left.
 *
 * This is deliberately NOT a strip_tags() call on its own: `<p> </p>` and `<p>&nbsp;</p>`
 * are the same emptiness wearing different markup, and every one of them would pass a
 * naive non-empty test.
 */
public static function isMeaningfulHtml(?string $html): bool
{
    if ($html === null) {
        return false;
    }

    // Zero-width and BOM characters carry no content but are not whitespace.
    $scrubbed = str_replace(
        ["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xEF\xBB\xBF", "\x00"],
        [' ', '', '', '', '', ''],
        $html
    );

    // Drop the markup an empty editor leaves behind, then what remains must be more
    // than punctuation an empty paragraph would contain.
    $text = html_entity_decode(strip_tags($scrubbed), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\s\x{00A0}]+/u', '', $text);

    return $text !== null && $text !== '';
}

public static function hasResponse(?OnlineExamAnswer $answer): bool
{
    if (! $answer) {
        return false;
    }

    if (self::isMeaningfulHtml($answer->answer_text)) {
        return true;
    }

    if (trim((string) $answer->selected_option) !== '') {
        return true;
    }

    $payload = $answer->answer_payload;

    if ($payload === null || trim((string) $payload) === '') {
        return false;
    }

    // A structured payload must contain an actual selection, not just a type name.
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? self::payloadHasContent($decoded) : trim($payload) !== '';
    }

    return self::payloadHasContent((array) $payload);
}

private static function payloadHasContent(array $payload): bool
{
    foreach ($payload as $key => $value) {
        if ($key === 'type') {
            continue;
        }

        if (is_array($value)) {
            if ($value !== []) {
                return true;
            }

            continue;
        }

        if ($value !== null && trim((string) $value) !== '') {
            return true;
        }
    }

    return false;
}

    public static function isManuallyMarked(OnlineExamAnswer $answer): bool
    {
        return $answer->awarded_marks !== null && $answer->marked_at !== null && $answer->marked_by !== null;
    }

    /**
     * MANUAL QUESTIONS ON THIS PAPER THAT STILL NEED A MARKER'S DECISION.
     *
     * ── WHY THIS EXISTS, AND WHAT IT CAUSED ─────────────────────────────────
     *
     * Exam 17, submission 12, Kyeyune Amos. The paper was one MCQ worth 10 and one
     * short-answer worth 10. She answered the MCQ. The written answer was never
     * persisted, so there was no answer row for it.
     *
     * `summary()` then reported `pending = 0`, because `summary()` only counts a
     * manual question that HAS a response and is not yet marked. A manual question
     * with NO response was skipped entirely. `submitBySubmission()` read `pending`
     * and wrote:
     *
     *     status = pending   ? 'pending_manual_marking' : 'finalized'
     *
     * So a student who answered nothing that needed judgement produced a submission
     * that announced MARKING COMPLETE. Ten marks were never awarded by anybody, the
     * result showed 0.00/20.00, and the lecturer's Actions column was empty because
     * `finalized` + `not_ready` is not a state any screen has an action for.
     *
     * The defect is that "no response" was treated as "nothing to decide". A manual
     * question is a question a MARKER must decide, whether or not the student
     * answered it - and where the student left it blank, the honest decision is an
     * explicit zero recorded by a named marker, not a silent omission.
     *
     * ── WHY THE QUEUE STILL IGNORES BLANK ANSWERS ──────────────────────────
     *
     * `responses()` deliberately filters to answers with content, and
     * `OnlineExamBatch2CMarkingTest` pins that: a blank question must not appear as
     * a row to mark, or a forty-question paper becomes forty clicks. Both are right
     * and they are not in conflict:
     *
     *  - `responses()` answers "what is there to READ?" — a blank answer has nothing
     *    to read, so it is not a queue row;
     *  - THIS method answers "what must a MARKER DECIDE before this result can be
     *    handed over?" — which includes every blank manual question.
     *
     * The screen shows both: the answered work in the queue, and a separate, explicit
     * panel listing the blanks so the marker can record the decision deliberately.
     *
     * @return list<array{question: OnlineExamQuestion, answer: OnlineExamAnswer|null, answered: bool}>
     */
    public static function manualQuestionsAwaitingDecision(OnlineExamSubmission $submission): array
    {
        $submission->loadMissing(['exam.questions', 'answerRows']);
        $answers = $submission->answerRows->keyBy('question_id');

        $out = [];

        foreach ($submission->exam->questions as $question) {
            if (self::isAutomatic($question)) {
                continue;
            }

            $answer = $answers->get($question->id);

            // Already judged by a named marker, with or without content: decided.
            if ($answer && self::isManuallyMarked($answer)) {
                continue;
            }

            $out[] = [
                'question' => $question,
                'answer' => $answer,
                'answered' => self::hasResponse($answer),
            ];
        }

        return $out;
    }

    /**
     * How many marks on this paper no marker has decided yet.
     *
     * Blank manual questions included. This is the number that must reach zero
     * before a result may be handed over - NOT `summary()['pending']`, which counts
     * only answered-but-unmarked work and is the wrong number for a completion test.
     */
    public static function undecidedManualMarks(OnlineExamSubmission $submission): float
    {
        return (float) array_sum(array_map(
            static fn (array $row) => (float) ($row['question']->marks ?? 0),
            self::manualQuestionsAwaitingDecision($submission)
        ));
    }

    public static function hasUndecidedManualQuestions(OnlineExamSubmission $submission): bool
    {
        return self::manualQuestionsAwaitingDecision($submission) !== [];
    }

    /** Read-only summary. Callers performing writes must hold the submission lock. */
    public static function summary(OnlineExamSubmission $submission): array
    {
        $submission->loadMissing(['exam.questions', 'answerRows']);
        $answers = $submission->answerRows->keyBy('question_id');
        $objectiveCents = 0;
        $manualCents = 0;
        $pending = 0;
        foreach ($submission->exam->questions as $question) {
            $answer = $answers->get($question->id);
            if (!self::hasResponse($answer)) {
                continue;
            }
            if (self::isAutomatic($question)) {
                $objectiveCents += (int) round((float) $answer->awarded_marks * 100);
            } elseif (self::isManuallyMarked($answer)) {
                $manualCents += (int) round((float) $answer->awarded_marks * 100);
            } else {
                $pending++;
            }
        }
        return ['objective_score' => $objectiveCents / 100, 'manual_score' => $manualCents / 100,
            'score' => ($objectiveCents + $manualCents) / 100, 'pending' => $pending];
    }
}
