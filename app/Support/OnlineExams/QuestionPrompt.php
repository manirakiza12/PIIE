<?php

namespace App\Support\OnlineExams;

use App\Support\CourseContent\HtmlSanitizer;

/**
 * ONE ANSWER TO "DOES THIS QUESTION HAVE ANY TEXT?"
 *
 * ── WHY THIS EXISTS: EXAM 20 ──────────────────────────────────────────────
 *
 * All four questions of exam 20 are stored, in `online_exam_questions.question`, as
 * exactly `<p><br></p>`:
 *
 *     id 47  mcq        <p><br></p>
 *     id 48  true_false <p><br></p>
 *     id 49  essay      <p><br></p>
 *     id 50  short      <p><br></p>
 *
 * `<p><br></p>` is what Summernote writes for an EMPTY document. It passed
 * `['required', 'string']`, so four unanswerable questions were published, the student
 * was shown cards with no question text, and the lecturer's marking page rendered the
 * label `strip_tags('<p><br></p>')` — an empty string — so those questions were
 * unidentifiable at the point of marking.
 *
 * The content was never lost in transit and the sanitizer was never at fault. It was
 * never in the request: the rich-text field's own value was empty when the form posted,
 * and nothing on the authoring path asked whether an "empty rich-text document" counts
 * as a question. `HtmlSanitizer::hasMeaningfulText()` already answers exactly that
 * question for lesson bodies and assignment instructions; this class is the same
 * answer, named for the exam question prompt, so the authoring rule and the
 * read-side "is there anything to show?" rule cannot drift apart.
 */
final class QuestionPrompt
{
    /**
     * Normalise an incoming prompt so an EMPTY EDITOR is rejected by `required`
     * rather than passing as a non-empty string.
     *
     * `<p><br></p>`, `<p></p>`, `&nbsp;`, `<div><br></div>` and the empty string
     * are the same fact - a document with nothing in it - wearing different markup.
     * Each becomes `''` here, and `required` then refuses it with a message that
     * names the problem.
     *
     * Anything a person actually WROTE is passed through untouched. This is not a
     * cleanup pass: the model's mutator and the sanitizer already own markup, and a
     * second rewriting stage here would be a third set of rules to keep in step.
     */
    public static function normaliseForAuthoring($raw): string
    {
        $value = is_string($raw) ? $raw : '';

        if (app(HtmlSanitizer::class)->hasMeaningfulText($value)) {
            return $value;
        }

        return '';
    }

    /**
     * Is there a question statement a reader could actually read?
     *
     * Used on the read side so a historic row with an empty prompt produces an
     * explicit, visible state on every surface - student attempt, lecturer marking,
     * administrator review - rather than a silent blank that looks like a layout
     * fault. It is deliberately NOT used to invent or repair content.
     */
    public static function isEmpty(?string $stored): bool
    {
        return ! app(HtmlSanitizer::class)->hasMeaningfulText($stored);
    }

    /**
     * The validation message.
     *
     * Deliberately identical in both question requests, so a lecturer who fixes it on
     * the create form and hits it again on the edit form sees the same sentence.
     */
    public const MESSAGE = 'Write the question. A question with no text is not a question.';

    /** Label used wherever a surface must say a stored prompt is empty. */
    public const EMPTY_LABEL = 'No question text was stored for this question.';
}