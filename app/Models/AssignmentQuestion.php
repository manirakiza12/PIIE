<?php

namespace App\Models;

use App\Support\CourseContent\HtmlSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE question inside a Course Offering assignment.
 *
 * A question is a row on the EXISTING `assignments` record. There is no second
 * assignment product, no join table and no separate submission model - a
 * question-based assignment is an assignment that happens to have questions, and
 * it keeps its own lifecycle, dates, module relationship, requirement role and
 * completion rule exactly as before.
 *
 * AN ASSIGNMENT IS QUESTION-BASED WHEN IT HAS AT LEAST ONE QUESTION
 *
 * There is no `is_question_based` column, deliberately. A flag has to be kept
 * true, and every path that creates or deletes a question would have to remember
 * to maintain it; get it wrong and students see the wrong form. The derived rule
 * cannot drift, because there is nothing to keep in step:
 *
 *   zero questions  ->  a GENERIC assignment. The existing evidence form, the
 *                          existing `submission_kinds` allowlist, the existing
 *                          rules. Unchanged behaviour.
 *   one or more     ->  question-based. The ordered question experience, evidence
 *                          per question, marking per question, and the gate that
 *                          the questions must total the assignment maximum.
 *
 * Assignment #3 is in the first case today, and stays in it until a lecturer
 * deliberately gives it questions.
 *
 * MARKS ARE GOVERNED BY THE QUESTIONS, NOT BY A SECOND TYPED TOTAL
 *
 * `marks` is the question's own maximum. The assignment's `max_marks` must equal
 * the sum of these before publication - checked in `AssignmentService`, refused
 * with a plain message otherwise. The lecturer can be handed a one-click "adopt
 * the question total" rather than being asked to do arithmetic that can
 * contradict the questions they just wrote.
 *
 * RESPONSE KINDS ARE A SET, AND "ONE OF" IS THE DEFAULT
 *
 * "Record audio OR upload audio" is a real requirement and no single value can
 * express it, so `response_kinds` is a comma-separated set - the same shape, and
 * for the same reason, as `Assignment::submissionKinds`.
 *
 * `require_all` is the deliberate-combination control, and it defaults to FALSE
 * because that is what an oral or demonstration question means: record it, or
 * upload it, or link to it. Requiring EVERY listed kind is legitimate but
 * unusual, so a lecturer has to choose it.
 *
 * THE EXTENSION ALLOWLISTS ARE NOT DUPLICATED HERE
 *
 * This model reuses `AssignmentSubmissionItem::KIND_EXTENSIONS` unchanged. A
 * per-question copy of those lists would be a second place for a file type to be
 * declared acceptable, and the validator and the form would eventually disagree
 * about what a question accepts.
 *
 * PERMISSION IS NEVER ASKED FOR ON LOAD
 *
 * A question may permit audio or video, and that is a statement about what the
 * student MAY hand in. It is not a reason to request a microphone or a camera:
 * the recorder control asks for permission only when a person presses it, and
 * asks for nothing at all on a page that has no recorder.
 *
 * @property int $id
 * @property int $school_id
 * @property int $assignment_id
 * @property string $prompt
 * @property string|null $heading
 * @property int $sequence
 * @property float $marks
 * @property bool $is_required
 * @property string|null $response_kinds
 * @property bool $require_all
 */
class AssignmentQuestion extends Model
{
    use HasFactory;

    protected $table = 'assignment_questions';

    protected $fillable = [
        'school_id', 'course_offering_id', 'assignment_id', 'prompt', 'heading',
        'sequence', 'marks', 'is_required', 'response_kinds', 'require_all',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'school_id' => 'integer',
        'course_offering_id' => 'integer',
        'assignment_id' => 'integer',
        'sequence' => 'integer',
        'marks' => 'float',
        'is_required' => 'boolean',
        'require_all' => 'boolean',
    ];

    // ── relations ──────────────────────────────────────────────────────────

    /** The EXISTING assignment. Reused, never replaced. */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    /**
     * The student's answers to this question, across every attempt.
     */
    public function responses(): HasMany
    {
        return $this->hasMany(AssignmentQuestionResponse::class, 'assignment_question_id');
    }

    /** Every attempt this student has made at this question. */
    public function responsesForAttempt(AssignmentSubmission $submission)
    {
        return $this->responses()->where('assignment_submission_id', $submission->id);
    }

    // ── ordering ───────────────────────────────────────────────────────────

    public function scopeInReadingOrder(Builder $query): Builder
    {
        return $query->orderBy('sequence')->orderBy('id');
    }

    // ── response kinds ────────────────────────────────────────────────────

    /**
     * The evidence this question accepts.
     *
     * NULL - the value on a question a lecturer has not finished configuring - is
     * returned as an EMPTY array, not as "everything". A question that accepts
     * anything is not a question, and reading NULL that way would quietly let a
     * half-written question accept whatever a student sent.
     *
     * Only kinds PIIE accepts, in the canonical order and deduplicated, so a
     * hand-edited stored value cannot introduce a kind the validator would refuse.
     *
     * @return list<string>
     */
    public function acceptedResponseKinds(): array
    {
        $stored = trim((string) $this->response_kinds);

        if ($stored === '') {
            return [];
        }

        return array_values(array_intersect(
            AssignmentSubmissionItem::CONFIGURABLE_KINDS,
            array_filter(array_map('trim', explode(',', $stored)))
        ));
    }

    public function acceptsResponseKind(?string $kind): bool
    {
        return in_array($kind, $this->acceptedResponseKinds(), true);
    }

    public function acceptsWrittenResponse(): bool
    {
        return $this->acceptsResponseKind(AssignmentSubmissionItem::KIND_TEXT);
    }

    /**
     * The file kinds this question accepts. Recording is not one of them: a
     * recording IS an uploaded audio or video file, so the recorder feeds the
     * same slot and the same validation.
     *
     * @return list<string>
     */
    public function acceptedFileKinds(): array
    {
        return array_values(array_filter(
            $this->acceptedResponseKinds(),
            fn (string $kind) => AssignmentSubmissionItem::isFileKind($kind)
        ));
    }

    public function acceptsLink(): bool
    {
        return $this->acceptsResponseKind(AssignmentSubmissionItem::KIND_LINK);
    }

    /**
     * Does this question accept a browser recording, as well as an upload?
     *
     * TRUE whenever audio or video is accepted, because a recording is simply
     * that file captured in the browser rather than chosen from disk. It is asked
     * as a question about the ALLOWED KINDS, not stored as a separate permission:
     * a lecturer who says "audio" has said both, and a separate flag would let
     * them say "audio upload only" in a way the storage model cannot honour.
     */
    public function allowsRecording(): bool
    {
        return $this->acceptsResponseKind(AssignmentSubmissionItem::KIND_AUDIO)
            || $this->acceptsResponseKind(AssignmentSubmissionItem::KIND_VIDEO);
    }

    /** The per-kind file allowlist, from the shared source. */
    public function extensionsFor(string $kind): array
    {
        return AssignmentSubmissionItem::extensionsFor($kind);
    }

    // ── marks ──────────────────────────────────────────────────────────────

    /** Is this question worth anything? A zero-mark question is refused at save. */
    public function hasPositiveMarks(): bool
    {
        return (float) $this->marks > 0;
    }

    /** Marks a marker may award this question, in the question's own words. */
    public function marksLabel(): string
    {
        $marks = (float) $this->marks;

        return ($marks == (int) $marks) ? (string) (int) $marks : (string) $marks;
    }

    public function marksLabelWithUnit(): string
    {
        return $this->marksLabel().' '.($this->marksLabel() === '1' ? 'mark' : 'marks');
    }

    // ── labels ─────────────────────────────────────────────────────────────

    /**
     * What a student is asked to produce, in plain words.
     *
     * "OR" for any-one-of and "and" for all-of, because that is the difference
     * between "record it or upload it" and "do all three", and a student must be
     * able to read which promise they are being given.
     */
    public function responseRequirementLabel(): string
    {
        $kinds = $this->acceptedResponseKinds();

        if ($kinds === []) {
            return 'Not configured yet';
        }

        $labels = array_map(
            fn (string $kind) => AssignmentSubmissionItem::KIND_LABELS[$kind] ?? $kind,
            $kinds
        );

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);
        $joiner = $this->require_all ? ' and ' : ' or ';

        return implode(', ', $labels).$joiner.$last;
    }

    /** The question as a marker and a student both refer to it. */
    public function positionLabel(int $index): string
    {
        return 'Question '.($index + 1);
    }

    // ── sentences for a student who has not answered yet ───────────────────

    /**
     * Each accepted kind as it reads INSIDE A SENTENCE.
     *
     * Deliberately NOT `KIND_LABELS`. Those are form labels - capitalised and
     * noun-shaped, sitting beside a checkbox on the lecturer's screen. A refusal
     * message has to read as English, so it needs lowercase and an article:
     * reusing the labels would produce "Provide a Audio file for question 3",
     * which a student stops reading rather than acts on.
     *
     * Audio and video are named as a RESPONSE rather than as a file, so a question
     * that accepts them does not read as though only an upload would do. Recording
     * in the browser satisfies such a question equally, and a student who has just
     * recorded should not be told they needed a file. The distinction is recorded
     * factually on the evidence item's `capture_method` regardless.
     */
    public const KIND_NOUNS = [
        AssignmentSubmissionItem::KIND_TEXT => 'a written response',
        AssignmentSubmissionItem::KIND_DOCUMENT => 'a document',
        AssignmentSubmissionItem::KIND_IMAGE => 'an image',
        AssignmentSubmissionItem::KIND_AUDIO => 'an audio response',
        AssignmentSubmissionItem::KIND_VIDEO => 'a video response',
        AssignmentSubmissionItem::KIND_LINK => 'a web link',
    ];

    /**
     * What this question is waiting for, as one phrase.
     *
     * Joined with "or", and that one word IS the contract. "Provide an image or a
     * written response" promises that either will do; a message that read "and"
     * would contradict what the validator actually enforces - which is exactly how
     * the reported failure looked from the student's side.
     *
     * @return string empty when the question is not configured yet
     */
    public function answerRequirementPhrase(): string
    {
        $nouns = array_map(
            fn (string $kind) => self::KIND_NOUNS[$kind] ?? $kind,
            $this->acceptedResponseKinds()
        );

        if ($nouns === []) {
            return '';
        }

        if (count($nouns) === 1) {
            return $nouns[0];
        }

        $last = array_pop($nouns);

        return implode(', ', $nouns).' or '.$last;
    }

    /**
     * The single sentence a student sees for an unanswered required question.
     *
     * It names the question they can SEE and everything that would satisfy it, so
     * the fix is obvious from the message alone. A refusal that says only "choose a
     * file" about a question the student has already answered in writing is worse
     * than no message at all: it teaches them the page is wrong.
     */
    public function unansweredMessage(): string
    {
        $phrase = $this->answerRequirementPhrase();

        if ($phrase === '') {
            return 'Question '.$this->sequence
                .' has not been set up yet. Tell your lecturer if you need it.';
        }

        return 'Provide '.$phrase.' for question '.$this->sequence.'.';
    }

    // ══════════════════════════════════════════════════════════════════════
    // RENDERING A QUESTION
    //
    // ── WHY THESE EXIST, AND WHY THERE IS NO MUTATOR ──────────────────────────
    //
    // The reported defect was a Word-formatted question displayed as literal
    // `<h1><span style="font-family: Arial">…</span></h1>`. Reading the column
    // showed the HTML was stored CORRECTLY - so this is not a storage problem and
    // never was one. The two templates that showed the problem were each right
    // about half of the answer and wrong about the other half:
    //
    //     questions.blade.php:162       {{  $question->prompt }}   ESCAPED -> raw tags
    //     _question_marking.blade:99    {!! $question->prompt !!}  RAW     -> no filter
    //     _question_answer.blade:74     {!! $question->prompt !!}  RAW     -> no filter
    //
    // One field rendered three ways, and the two unsafe ones were unfiltered
    // database HTML going straight into the browser. Escaping everything would fix
    // the first line and break the Word-like editor the owner asked for; unescaping
    // would fix the other two and leave a stored-XSS hole reachable by any student.
    //
    // So the answer is a NAME for the sanitised read, exactly as the exam engine
    // already has (`OnlineExamQuestion::prosePrompt()`), and templates call that
    // instead of choosing an escape mode per line. The exam module had eleven such
    // call sites and no defect; Assignments had a reader for nobody.
    //
    // ── WHY THERE IS NO `setPromptAttribute()` HERE ──────────────────────────
    //
    // Deliberately. `QuestionService` ALREADY sanitises the prompt on the way in:
    //
    //     $prompt = $this->sanitizer->sanitize($attributes['prompt'] ?? null);
    //     if ($this->sanitizer->toText($prompt) === '') { throw ... }
    //     $question->prompt = $prompt;
    //
    // That is the write half, done with this same `HtmlSanitizer`, and it also
    // refuses a question with no text in it. Adding a mutator would filter the
    // same value a second time at every write path, including the ones that do not
    // go through the model - which is duplication, not defence. The column is
    // already clean; what was missing was the read.
    //
    // (Measured while checking this: `HtmlSanitizer` IS idempotent - nine cases,
    // including the bare `&` and `<` cases, all unchanged on a second pass - so a
    // second filter would not have corrupted anything. It would still be a second
    // opinion on a value one service already owns.)
    //
    // ── AND WHY AUTOMATIC MARKING IS UNTOUCHED ──────────────────────────────
    //
    // `assignment_questions` has no answer-key column at all - verified against the
    // live schema: id, school_id, course_offering_id, assignment_id, prompt,
    // heading, sequence, marks, is_required, response_kinds, require_all,
    // created_by, updated_by, created_at, updated_at. Every assignment question is
    // marked by a human. `prompt` is display-only and is never compared as a
    // string anywhere in the codebase, so filtering it cannot alter a mark.
    // =================================================================═════

    /**
     * The prompt as sanitised HTML, for rendering.
     *
     * Named rather than reached through an accessor on purpose. An accessor named
     * after the column would have silently re-rendered every one of the ~150
     * existing `{{ $question->prompt }}` echoes application-wide, most of which
     * deliberately want escaped text. A separate method makes the change at every
     * site explicit and reviewable - which is the same reasoning behind
     * `proseInstructions()` and `plainInstructions()` on the exam side.
     */
    public function prosePrompt(): string
    {
        return app(HtmlSanitizer::class)->sanitize($this->attributes['prompt'] ?? '');
    }

    /**
     * The prompt as plain text - for a heading, a summary, a search box or any
     * other place where markup would be a defect rather than content.
     */
    public function plainPrompt(int $limit = 160): string
    {
        return app(HtmlSanitizer::class)->toText($this->attributes['prompt'] ?? '', $limit);
    }

    /**
     * Is this prompt only formatting - no words a student could answer?
     *
     * The editor can submit a document that is a heading and a font choice with
     * nothing typed into it, which is exactly what reached the database for the
     * reported question: `<h1><span style="font-family: Arial">﻿</span></h1>`.
     * `QuestionService` rejects a prompt whose text is entirely empty, but a bare
     * formatting wrapper is not empty - it is just useless, and it reaches a
     * published assignment as an unanswerable question.
     *
     * So this reports the case the write-time rule cannot see. It is a READER, not
     * a new write rule: tightening `QuestionService` to reject it would change what
     * existing rows mean and is a product decision, not a rendering fix. Templates
     * can use it to say so honestly instead of rendering an empty heading.
     */
    public function promptIsUnanswered(?string $fallback = 'This question has no text yet.'): ?string
    {
        $text = (string) app(HtmlSanitizer::class)->toText($this->attributes['prompt'] ?? '');

        // `trim()` alone is NOT enough, and the reason is this method's whole reason
        // for existing. The value stored for the reported question is:
        //
        //     <h1><span style="font-family: Arial">﻿</span></h1>
        //
        // - a heading and a font choice around U+FEFF. `toText()` returns that byte,
        // and `trim()` does not remove it, because U+FEFF is a zero-width NO-BREAK
        // SPACE and not one of the characters PHP's default character list strips.
        // The first version of this reader therefore returned null for precisely the
        // row it was written to catch, which is the failure mode a test written
        // against expectation rather than against the data produces.
        //
        // So the zero-width characters are named explicitly.
        $blank = ["\u{FEFF}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{00A0}"];

        return trim(str_replace($blank, '', $text)) === ''
            ? $fallback
            : null;
    }
}
