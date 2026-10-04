<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionItem;
use App\Models\CourseOffering;
use App\Models\User;
use App\Support\CourseContent\HtmlSanitizer;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Student submission: preparing work, submitting it, and what that costs.
 *
 * THE FOUR INVARIANTS THIS CLASS EXISTS TO KEEP
 *
 * 1. OPENING AN ASSIGNMENT IS NOT A SUBMISSION.
 *    Nothing here runs on a GET. A progress row is only written by an explicit
 *    save-draft or submit, so a student who reads a task and leaves has not
 *    submitted anything and has consumed no attempt.
 *
 * 2. DRAFT WORK IS NOT A SUBMISSION.
 *    `is_draft` distinguishes the two. A draft can be saved, reopened and
 *    rewritten as many times as the student likes without touching
 *    `submitted_at` or consuming an attempt. Only `submit()` clears the flag.
 *
 * 3. ONE LOGICAL SUBMISSION IS ONE ROW, HOWEVER MANY TIMES IT IS SENT.
 *    `idempotency_key` is a UNIQUE column, so a double-click, a retried request
 *    or two racing tabs all collapse onto one attempt. The database decides, not
 *    a read-then-write check, so two concurrent requests cannot both win.
 *
 * 4. LATE IS A FACT ABOUT TWO STORED INSTANTS.
 *    It is computed from `submitted_at` against the assignment's own deadline.
 *    Nothing the submitting browser said about the time is ever read, so a
 *    student cannot make a late submission look on time by changing their clock.
 *
 * EACH ATTEMPT IS ITS OWN ROW
 *
 * Resubmission inserts the next attempt rather than overwriting the last, so
 * earlier work and any marks already given survive. The UNIQUE index on
 * (assignment, student, attempt_no) is what makes "one row per attempt" a
 * guarantee rather than a hope.
 */
class SubmissionService
{
    /** 20 MB, matching the lecturer's handout limit. */
    public const MAX_UPLOAD_KB = 20 * 1024;

// The accepted file types are NOT one list. They are per kind, on
// AssignmentSubmissionItem::KIND_EXTENSIONS, because "a photograph" and "a
// document" are different promises to a marker and a single combined list cannot
// tell them apart. There is deliberately no combined constant here to reach for.

    public function __construct(
        private readonly AssignmentAccess $access,
        private readonly HtmlSanitizer $sanitizer,
        private readonly QuestionResponseService $questionAnswers,
    ) {}

    /**
     * Is this assignment answered QUESTION BY QUESTION?
     *
     * Derived, and the same rule everywhere: an assignment with at least one
     * question is question-based. A GENERIC assignment - every K12 row, every
     * pre-existing HEI assignment, and Assignment #3 as it stands today - takes
     * the original code path below, untouched.
     */
    private function isQuestionBased(Assignment $assignment): bool
    {
        return $assignment->isQuestionBased();
    }

    // ── reading a student's own attempt history ───────────────────────────

    /**
     * Every attempt this student has made, oldest first.
     *
     * Drafts are included so the editor can reopen prepared work; the caller
     * decides what to show.
     */
    public function attemptsFor(Assignment $assignment, User $student): \Illuminate\Support\Collection
    {
        return AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $student->id)
            ->orderBy('attempt_no')
            ->get();
    }

    /**
     * The evidence attached to one attempt, in a stable order.
     *
     * @return \Illuminate\Support\Collection<int, AssignmentSubmissionItem>
     */
    public function evidenceFor(AssignmentSubmission $submission)
    {
        return AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $submission->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * The written response.
     *
     * A DELEGATION, not a second implementation. The rule - canonical field first,
     * legacy column as a fallback - is a fact about a submission row, so it lives
     * on the model. Two copies of it would eventually disagree about what a student
     * wrote, and a template would end up asking one of them.
     */
    public function writtenResponse(AssignmentSubmission $submission): ?string
    {
        return $submission->writtenResponse();
    }

    /**
     * Has this attempt handed in anything at all?
     *
     * Used when deciding whether work is worth showing to a marker, and whether a
     * required task is satisfied. An attempt with neither text nor any
     * substantive item is not a submission of work.
     */
    public function hasWork(AssignmentSubmission $submission): bool
    {
        if ($this->writtenResponse($submission) !== null) {
            return true;
        }

        return $this->evidenceFor($submission)->contains(
            fn (AssignmentSubmissionItem $item) => $item->isSubstantive()
        );
    }

    /**
     * This assignment's questions, in reading order.
     *
     * A DELEGATION. "What questions does this assignment have" is a fact about the
     * assignment, so it is asked of the question service rather than re-derived
     * here, and a controller that needs it does not have to know whether the
     * assignment is question-based before asking.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\AssignmentQuestion>
     */
    public function questionsFor(Assignment $assignment)
    {
        return $this->questionAnswers->questionsFor($assignment);
    }

    /**
     * An attempt's answers, keyed by question id.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\AssignmentQuestion>  $questions
     * @return array<int, \App\Models\AssignmentQuestionResponse|null>
     */
    public function answersFor(AssignmentSubmission $submission, $questions): array
    {
        return $this->questionAnswers->answersFor($submission, $questions);
    }

    /** Attempts that actually consumed an attempt and count as submissions. */
    public function attemptsUsed(Assignment $assignment, User $student): int
    {
        return AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $student->id)
            ->where('is_draft', false)
            ->count();
    }

    /** The most recent REAL submission (drafts excluded), or null. */
    public function latestSubmission(Assignment $assignment, User $student): ?AssignmentSubmission
    {
        return AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $student->id)
            ->where('is_draft', false)
            ->orderByDesc('attempt_no')
            ->first();
    }

    /** The draft this student is currently working on, if any. */
    public function currentDraft(Assignment $assignment, User $student): ?AssignmentSubmission
    {
        return AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $student->id)
            ->where('is_draft', true)
            ->orderByDesc('attempt_no')
            ->first();
    }

    // ── writing ───────────────────────────────────────────────────────────

    /**
     * Save or update PREPARED work. This is not a submission.
     *
     * Refuses once the assignment has closed, so a student cannot keep working
     * after the door has shut, but it deliberately consumes no attempt.
     */
    public function saveDraft(User $student, Assignment $assignment, array $attributes): AssignmentSubmission
    {
        $this->assertRegistered($student, $assignment);

        // The same gate as submitting, so "save my work" can never be offered for
        // an assignment the student will not be allowed to hand in.
        $this->assertMayStillPrepare($assignment, $this->attemptsUsed($assignment, $student));

        // ONE decision, made once, used by every path below. A generic assignment
        // carries on with the assignment-level evidence exactly as before; a
        // question-based one takes the per-question path.
        $questionBased = $this->isQuestionBased($assignment);
        $questions = $questionBased ? $this->questionAnswers->questionsFor($assignment) : collect();

        // On a question-based assignment there is no assignment-level evidence:
        // everything belongs to a question, and an item with no question on it would
        // be evidence a lecturer could not attribute to anything they asked.
        $items = $questionBased ? [] : $this->normaliseItems($assignment, $attributes['items'] ?? null);

        // CALLED UNCONDITIONALLY, and that is the point.
        //
        // For a question-based assignment it does the real work. For a GENERIC one it
        // receives an empty question list and the raw payload - and refuses a
        // non-empty payload, because a question id on an assignment with no questions
        // would point at a question belonging to some other assignment.
        //
        // It used to be called only when `$questionBased`, which left that documented
        // rule as dead code: a student could post `questions[...]` to a generic
        // assignment and nothing looked at it. The guard existed in the service and
        // was never reached, which is the worst place for a rule to be wrong.
        $questionAnswers = $this->questionAnswers->normaliseAnswers(
            $assignment,
            $questions,
            $attributes['questions'] ?? null,
            // THE EVIDENCE ALREADY ON THIS ATTEMPT, and only this attempt.
            //
            // A browser cannot repopulate a file input, so every file slot arrives
            // empty on every page load and on every SUBMIT - and a browser
            // recording is worse, because the Blob that held it died with the page.
            // Passing the attempt's stored evidence as the BASELINE is what lets
            // `normaliseAnswers` treat an empty slot as "unchanged" rather than as
            // "delete it", so pressing the button used to hand work in cannot
            // destroy the work itself.
            //
            // Scoped to `currentDraft()`, so a SECOND attempt - which has no draft -
            // starts empty and never inherits the first attempt's files. Attempt 1
            // stays readable on its own row, and nothing leaks forward.
            $this->questionAnswers->evidenceByQuestion(
                $this->currentDraft($assignment, $student)
            )
        );

        // A prepared draft must still satisfy what the assignment asks for, so
        // the same requirement check runs on the path that does not submit. A
        // student cannot prepare work that could never be handed in.
        $this->requiredCheck(
            $assignment,
            $items,
            $questionBased ? null : ($attributes['text'] ?? null),
            forDraft: true,
            questionAnswers: $questionAnswers
        );

        return DB::transaction(function () use ($student, $assignment, $attributes, $items, $questionBased, $questions, $questionAnswers) {
            $draft = $this->currentDraft($assignment, $student);

            if (! $draft) {
                $draft = new AssignmentSubmission();
                $draft->course_offering_id = $assignment->course_offering_id;
                $draft->attempt_no = $this->attemptsUsed($assignment, $student) + 1;
            }

            $draft->assignment_id = $assignment->id;
            $draft->student_id = $student->id;
            $draft->is_draft = true;

            if ($questionBased) {
                // The written answer to each question lives on that question's
                // RESPONSE row, so there is no single assignment-level text
                // response to keep in step. `text_response` stays null: a
                // question-based attempt's prose is spread across its answers by
                // design, and mirroring it here would be a second copy that could
                // disagree with them.
                $draft->text_response = null;
            } else {
                // The canonical rich-text field. A draft has no `submitted_at`, so
                // preparing work cannot become a submission by any route.
                $draft->text_response = $this->richTextOrNull($attributes['text'] ?? null);
            }

            // The legacy column is left for K12 rows only; an HEI row never
            // writes it, so the two can never disagree about HEI content.
            $draft->submission = null;

            $draft->save();

            if ($questionBased) {
                $this->questionAnswers->store($draft, $assignment, $questions, $questionAnswers);
            } else {
                $this->replaceEvidence($draft, $items);
            }

            return $draft->fresh();
        });
    }

    /**
     * SUBMIT the work.
     *
     * $idempotencyKey is supplied by the form. The same key submitted twice
     * returns the SAME attempt rather than creating a second one, which is what
     * makes a double-click, a refresh after a slow save, or two open tabs safe.
     */
    public function submit(User $student, Assignment $assignment, array $attributes, ?string $idempotencyKey = null): AssignmentSubmission
    {
        $this->assertRegistered($student, $assignment);

        $attemptsUsed = $this->attemptsUsed($assignment, $student);

        $refusal = AssignmentLifecycle::refusalReason($assignment, $attemptsUsed);
        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        $idempotencyKey = $this->normaliseKey($idempotencyKey);

        // Fast path: this exact submission already landed. Returning the
        // existing attempt is the correct outcome of a retry, not an error.
        if ($idempotencyKey !== null) {
            $existing = AssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_id', $student->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $questionBased = $this->isQuestionBased($assignment);
        $questions = $questionBased ? $this->questionAnswers->questionsFor($assignment) : collect();

        $items = $questionBased ? [] : $this->normaliseItems($assignment, $attributes['items'] ?? null);
        $text = $questionBased ? null : $this->richTextOrNull($attributes['text'] ?? null);

        // Unconditional for the same reason as the draft path: a question id posted
        // to a generic assignment must be refused, not ignored.
        $questionAnswers = $this->questionAnswers->normaliseAnswers(
            $assignment,
            $questions,
            $attributes['questions'] ?? null,
            // THE EVIDENCE ALREADY ON THIS ATTEMPT, and only this attempt.
            //
            // A browser cannot repopulate a file input, so every file slot arrives
            // empty on every page load and on every SUBMIT - and a browser
            // recording is worse, because the Blob that held it died with the page.
            // Passing the attempt's stored evidence as the BASELINE is what lets
            // `normaliseAnswers` treat an empty slot as "unchanged" rather than as
            // "delete it", so pressing the button used to hand work in cannot
            // destroy the work itself.
            //
            // Scoped to `currentDraft()`, so a SECOND attempt - which has no draft -
            // starts empty and never inherits the first attempt's files. Attempt 1
            // stays readable on its own row, and nothing leaks forward.
            $this->questionAnswers->evidenceByQuestion(
                $this->currentDraft($assignment, $student)
            )
        );

        $this->requiredCheck($assignment, $items, $text, forDraft: false, questionAnswers: $questionAnswers);

        // "Empty" means different things for the two models, and each is asked in
        // its own terms: a generic attempt is empty with no text and no evidence,
        // while a question-based one is empty with every question blank. An attempt
        // with a single answered OPTIONAL question and nothing else is not empty -
        // it is work.
        if ($questionBased) {
            if (! $this->questionAnswers->hasAnyAnswer($questions, $questionAnswers)) {
                throw ValidationException::withMessages([
                    'questions' => 'Answer at least one question before submitting. An empty submission cannot be graded.',
                ]);
            }
        } else {
            $this->assertHasWork($items, $text);
        }

        // Re-read the attempt count INSIDE the transaction and take a row lock on
        // the assignment, so two concurrent submits cannot both decide they are
        // attempt N. The unique index is the backstop; this is what stops the
        // pair from colliding at all.
        return DB::transaction(function () use ($student, $assignment, $items, $text, $idempotencyKey, $questionBased, $questions, $questionAnswers) {
            Assignment::query()
                ->whereKey($assignment->id)
                ->lockForUpdate()
                ->first();

            $used = AssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->where('student_id', $student->id)
                ->where('is_draft', false)
                ->count();

            if ($used >= $assignment->attemptsAllowed()) {
                throw new DomainException(
                    $assignment->attemptsAllowed() === 1
                        ? 'You have already submitted this assignment.'
                        : 'You have used all '.$assignment->attemptsAllowed().' attempts for this assignment.'
                );
            }

            $attemptNo = $used + 1;

            // The prepared draft, if there is one, is promoted into this attempt
            // rather than copied, so the student's work is submitted exactly
            // once and no orphan draft row is left behind. A file already
            // attached to that draft is simply kept: with no new upload, nothing
            // overwrites `file_path`.
            $submission = $this->currentDraft($assignment, $student);

            if (! $submission) {
                $submission = new AssignmentSubmission();
                $submission->course_offering_id = $assignment->course_offering_id;
            }

            $submission->assignment_id = $assignment->id;
            $submission->student_id = $student->id;
            $submission->attempt_no = $attemptNo;
            $submission->is_draft = false;
            // The AUTHORITATIVE instant, taken here on the server. The browser's
            // clock is never read, so "was this late" cannot be argued with.
            $submission->submitted_at = now();
            $submission->text_response = $text;
            $submission->submission = null;

            $submission->idempotency_key = $idempotencyKey;

            // The legacy status enum, maintained for the K12 screens, computed
            // from the stored instant and the assignment's own deadline.
            $submission->status = $submission->isLate() ? 'late' : 'submitted';

            $submission->save();

            if ($questionBased) {
                // The promoted draft's per-question answers are replaced with the
                // ones just sent, the same way a generic attempt's items are. A
                // question answered in attempt 1 therefore cannot survive as
                // attempt 2's evidence.
                $this->questionAnswers->store($submission, $assignment, $questions, $questionAnswers);
            } else {
                // The evidence of THIS attempt. A promoted draft's items are
                // replaced wholesale, so resubmitting cannot leave the previous
                // attempt's photograph attached to the new one as if it were new
                // evidence.
                $this->replaceEvidence($submission, $items);
            }

            return $submission->fresh();
        });
    }

    // ── guards ────────────────────────────────────────────────────────────

    private function assertRegistered(User $student, Assignment $assignment): void
    {
        $offering = CourseOffering::query()
            ->where('school_id', (int) $assignment->school_id)
            ->whereKey((int) $assignment->course_offering_id)
            ->firstOrFail();

        // A confirmed registration on this exact Offering. Not "is a student",
        // not "is in the same cohort": the registration is the authority, the
        // same test Course Content and Live Classes use.
        if (! $this->access->canStudentAccess($student, $offering)) {
            throw new DomainException('You are not registered for this course.');
        }
    }

    /**
     * May this student still be preparing work for this assignment?
     *
     * Gated on EXACTLY the same question as submitting, via one call, so the two
     * can never disagree. A student must not be able to save a draft for an
     * assignment they will never be allowed to submit - that is work they would
     * reasonably believe counted.
     *
     * $attemptsUsed is the count of REAL submissions, because a draft consumes no
     * attempt and so must not count itself here.
     *
     * @throws DomainException with the same wording the submit path would use
     */
    private function assertMayStillPrepare(Assignment $assignment, int $attemptsUsed): void
    {
        $reason = AssignmentLifecycle::refusalReason($assignment, $attemptsUsed);

        if ($reason !== null) {
            throw new DomainException($reason);
        }
    }

    /**
     * Does this submission satisfy what the assignment asked for?
     *
     * The assignment's EVIDENCE ALLOWLIST is the promise, not the legacy
     * submission_type: "a photograph OR a recording" cannot be expressed by that
     * single enum value, which is why the allowlist exists.
     *
     * The mode is a promise to the student about what will be collected, so a
     * submission that does not satisfy it is refused rather than half-accepted.
     * The same check runs on the draft path, so work can never be prepared that
     * could not be handed in.
     */
    private function assertMatchesSubmissionType(
        Assignment $assignment,
        array $items,
        ?string $text,
        bool $forDraft
    ): void {
        $accepted = $assignment->acceptedEvidenceKinds();

        $supplied = array_values(array_unique(array_map(
            fn (array $item) => (string) $item['kind'],
            $items
        )));

        // The written response is NOT an item: it is passed separately and stored
        // on the submission, so that "I wrote something" is one fact in one home
        // rather than two that can disagree. It still SATISFIES the text
        // requirement, so it is counted here explicitly. Omitting this is what
        // made a text-only submission look like nothing at all had been supplied.
        if ($text !== null
            && in_array(AssignmentSubmissionItem::KIND_TEXT, $accepted, true)) {
            $supplied[] = AssignmentSubmissionItem::KIND_TEXT;
        }

        $missing = array_values(array_diff($accepted, array_unique($supplied)));

        if ($missing === []) {
            return;
        }

        $labels = array_map(
            fn (string $kind) => AssignmentSubmissionItem::KIND_LABELS[$kind] ?? $kind,
            $missing
        );
        $expected = count($labels) === 1
            ? $labels[0]
            : implode(', ', array_slice($labels, 0, -1)).' and '.end($labels);

        throw ValidationException::withMessages([
            'items' => $forDraft
                ? 'This task asks for '.$expected.'. Add it, or the submission will be refused when you hand it in.'
                : 'This task asks for '.$expected.', which your submission is missing.',
        ]);
    }

    private function assertHasWork(array $items, ?string $text): void
    {
        if ($text === null && $items === []) {
            throw ValidationException::withMessages([
                'items' => 'Add your work before submitting. An empty submission cannot be graded.',
            ]);
        }
    }

    /**
     * THE requirement check, called from BOTH the draft and submit paths.
     *
     * One rule, two implementations, and only one is ever reached:
     *
     *   generic assignment        ->  every kind its `submission_kinds` asked for
     *   question-based assignment ->  every REQUIRED question actually answered
     *
     * Delegating rather than reimplementing is the point. The generic wording a
     * student sees today is unchanged, and the question wording cannot slowly
     * become a different promise from it.
     */
    private function requiredCheck(
        Assignment $assignment,
        array $items,
        ?string $text,
        bool $forDraft,
        array $questionAnswers = []
    ): void {
        if ($this->isQuestionBased($assignment)) {
            $this->questionAnswers->assertRequiredAnswered(
                $this->questionAnswers->questionsFor($assignment),
                $questionAnswers,
                $forDraft
            );

            return;
        }

        $this->assertMatchesSubmissionType($assignment, $items, $text, $forDraft);
    }

    /**
     * Normalise and validate the submitted evidence into a clean list.
     *
     * Each item is checked against ITS OWN kind's allowlist, not one combined
     * list. That is what stops a student handing an arbitrary document in the
     * "Image" slot, where a lecturer would read it as photographic evidence.
     * PIIE's existing content sniffing is applied as a second, content-based
     * check, so an executable renamed to .pdf is refused on what it IS.
     *
     * @return list<array{kind:string,file:?UploadedFile,url:?string,label:?string,note:?string}>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function normaliseItems(Assignment $assignment, $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $rows = is_array($raw) ? $raw : [];
        $out = [];
        $accepted = $assignment->acceptedEvidenceKinds();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kind = trim((string) ($row['kind'] ?? ''));

            if (! AssignmentSubmissionItem::isConfigurableKind($kind)) {
                throw ValidationException::withMessages([
                    'items' => 'That kind of evidence is not accepted here.',
                ]);
            }

            // A kind the ASSIGNMENT did not ask for is refused rather than
            // accepted-and-ignored: a marker told to expect a photograph must not
            // silently receive something else.
            if (! in_array($kind, $accepted, true)) {
                throw ValidationException::withMessages([
                    'items' => 'This task does not ask for '
                        .(AssignmentSubmissionItem::KIND_LABELS[$kind] ?? $kind).'.',
                ]);
            }

            $label = trim((string) ($row['label'] ?? '')) ?: null;
            $note = trim((string) ($row['note'] ?? '')) ?: null;

            // ── Written response ──
            // Stored on the SUBMISSION, not here, so "I wrote something" is one
            // fact in one place rather than two that could disagree.
            if ($kind === AssignmentSubmissionItem::KIND_TEXT) {
                $text = $this->richTextOrNull($row['text'] ?? null);
                if ($text !== null) {
                    $out[] = ['kind' => $kind, 'file' => null, 'url' => null, 'label' => $label, 'note' => $note];
                }
                continue;
            }

            // ── Link ──
            if (AssignmentSubmissionItem::isLinkKind($kind)) {
                $url = trim((string) ($row['url'] ?? ''));
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

                if ($url === '' || ! in_array($scheme, AssignmentSubmissionItem::LINK_SCHEMES, true)) {
                    throw ValidationException::withMessages([
                        'items' => 'A link must be a full http or https address.',
                    ]);
                }

                $out[] = ['kind' => $kind, 'file' => null, 'url' => $url, 'label' => $label, 'note' => $note];
                continue;
            }

            // ── File of a declared kind ──
            $file = $row['file'] ?? null;
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'items' => 'Choose a file for the '
                        .strtolower(AssignmentSubmissionItem::KIND_LABELS[$kind] ?? $kind).'.',
                ]);
            }

            $this->assertItemUploadable($file, $kind);

            $out[] = ['kind' => $kind, 'file' => $file, 'url' => null, 'label' => $label, 'note' => $note];
        }

        return $out;
    }

    /**
     * Validate a file against ITS OWN kind's allowlist, plus PIIE's content
     * sniffing and the shared size limit.
     */
    private function assertItemUploadable(UploadedFile $file, string $kind): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $allowed = AssignmentSubmissionItem::extensionsFor($kind);

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'items' => 'A '.strtolower(AssignmentSubmissionItem::KIND_LABELS[$kind] ?? $kind)
                    .' must be one of: '.implode(', ', $allowed).'.',
            ]);
        }

        if ($file->getSize() > self::MAX_UPLOAD_KB * 1024) {
            throw ValidationException::withMessages([
                'items' => 'Files are limited to 20 MB. Compress it, or link to the material instead.',
            ]);
        }

        $blocked = AssignmentSubmissionItem::blockedExtensions();
        $detected = strtolower((string) $file->guessExtension());

        if (in_array($extension, $blocked, true) || in_array($detected, $blocked, true)) {
            throw ValidationException::withMessages([
                'items' => 'That file type cannot be submitted.',
            ]);
        }
    }

    /**
     * Replace the evidence attached to an attempt.
     *
     * Wholesale replacement, not a merge: resubmitting must not leave the previous
     * attempt's photograph attached to the new attempt as though it were new
     * evidence. Files that are no longer referenced are deleted, so the stored
     * bytes cannot outlive the attempt that justified keeping them.
     */
    private function replaceEvidence(AssignmentSubmission $submission, array $items): void
    {
        $existing = AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $submission->id)
            ->get();

        $existing->each(fn (AssignmentSubmissionItem $item) => $this->deleteItemFile($item));
        AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $submission->id)
            ->delete();

        foreach ($items as $item) {
            // The written response is already on the submission itself.
            if ($item['kind'] === AssignmentSubmissionItem::KIND_TEXT) {
                continue;
            }

            $row = new AssignmentSubmissionItem();
            $row->school_id = $submission->assignment?->school_id ?? \Auth::user()?->school_id;
            $row->assignment_submission_id = $submission->id;
            $row->assignment_id = $submission->assignment_id;
            $row->course_offering_id = $submission->course_offering_id;
            $row->kind = $item['kind'];
            $row->label = $item['label'];
            $row->note = $item['note'];
            $row->url = $item['url'];

            if ($item['file'] instanceof UploadedFile) {
                $this->storeItemFile($row, $item['file']);
            }

            $row->save();
        }
    }
    /**
     * Store one evidence file OUTSIDE THE WEB ROOT under a generated name.
     *
     * The client's filename never becomes the path, so an uploaded "work.mp3"
     * cannot be requested at a guessable location and cannot collide with anything
     * else in storage. The path is namespaced by offering, assignment and kind, so
     * one student's recording can never be read under another's submission.
     */
    private function storeItemFile(AssignmentSubmissionItem $item, UploadedFile $file): void
    {
        $storedName = 'assignment-submissions/'
            .$item->course_offering_id.'/'
            .$item->assignment_id.'/'
            .$item->kind.'/'
            .bin2hex(random_bytes(16)).'.'.strtolower((string) $file->getClientOriginalExtension());

        Storage::disk('local')->put($storedName, file_get_contents($file->getRealPath()));

        $item->stored_path = $storedName;
        $item->original_name = (string) $file->getClientOriginalName();
        $item->mime_type = (string) ($file->getClientMimeType() ?: 'application/octet-stream');
        $item->size_bytes = (int) $file->getSize();
    }

    /**
     * Delete the bytes behind an evidence item.
     *
     * Called when an attempt's evidence is replaced, so stored media cannot
     * outlive the attempt that justified keeping it. A student's photograph or
     * recording is personal; retaining it after they withdrew the attempt would be
     * a retention decision nobody made.
     */
    private function deleteItemFile(AssignmentSubmissionItem $item): void
    {
        if ($item->stored_path) {
            Storage::disk('local')->delete($item->stored_path);
        }
    }

    /**
     * The written response, sanitised on the way IN.
     *
     * A written response is rich text from an editor, so it goes through the same
     * HtmlSanitizer as lesson bodies and assignment instructions. One allowlist
     * for the whole product is the point: two rich-text stores with two filters is
     * how one of them ends up with an XSS hole.
     *
     * Markup that carries no visible text - an empty paragraph, a bare image - is
     * treated as nothing, so an "empty" submission cannot be smuggled through as
     * one that merely looks blank.
     */
    private function richTextOrNull($text): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $clean = $this->sanitizer->sanitize($text);

        // The one canonical emptiness answer - see HtmlSanitizer::hasMeaningfulText().
        if (! $this->sanitizer->hasMeaningfulText($clean)) {
            return null;
        }

        return $clean;
    }

    /**
     * The idempotency token, trimmed to fit its column.
     *
     * A blank token is treated as no token, so a form that forgot to send one is
     * not given a meaningless value that two unrelated submissions would then
     * share.
     */
    private function normaliseKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : Str::limit($key, 64, '');
    }
}