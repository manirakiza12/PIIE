<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentQuestion;
use App\Models\AssignmentQuestionResponse;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionItem;
use App\Models\User;
use App\Support\CourseContent\HtmlSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A student's answers to an assignment's questions, question by question.
 *
 * THIS IS THE STUDENT-FACING HALF. THE ATTEMPT STILL BELONGS TO `SubmissionService`
 *
 * There is no second submission model, no second attempt counter and no second
 * "is this still open" gate. This class is handed an already-resolved
 * `AssignmentSubmission` - a real row, draft or submitted - and files answers
 * against it. The idempotency key, the late policy, the attempt limit, the final
 * closing time and the draft-does-not-consume-an-attempt rule all stay where they
 * were, in the one place that already got them right.
 *
 * WHAT A QUESTION ACCEPTS IS THE QUESTION'S BUSINESS, NOT THE ASSIGNMENT'S
 *
 * A generic assignment has one assignment-level allowlist
 * (`assignments.submission_kinds`) and shows the same fields for the whole thing.
 * A question-based assignment has one allowlist PER QUESTION, and this class
 * validates each item against the allowlist of the question it claims to answer.
 *
 * That distinction is the whole point of the feature. A marker told "Question 2:
 * upload a photograph of your working" must not silently receive a PDF, and a
 * shared assignment-level list would make that possible - the `image` slot and the
 * `document` slot would be interchangeable. The per-kind extension allowlists in
 * `AssignmentSubmissionItem::KIND_EXTENSIONS` do the second half of the check.
 *
 * A QUESTION ID FROM ANYWHERE ELSE IS REFUSED
 *
 * Every submitted `question_id` is resolved INSIDE the resolved assignment before
 * anything is read from it. An id from another assignment, another Offering or
 * another tenant is a validation error, not a silent skip - a student who
 * tampered with the form has to be told, and a lecturer must never be handed
 * evidence filed against a question they do not own.
 *
 * A QUESTION ID ON A GENERIC ASSIGNMENT IS REFUSED TOO
 *
 * The reverse direction matters just as much. A generic assignment has no
 * questions, so a `question_id` on its evidence is meaningless; accepting it would
 * create items pointing at a question that may exist on some OTHER assignment.
 *
 * SAVING IS IDEMPOTENT PER (ATTEMPT, QUESTION)
 *
 * The unique index does the work, so saving a draft five times is still one row
 * per question. A draft that is reopened and rewritten is UPDATED, never
 * duplicated, which is what makes "resume your draft" safe to offer.
 *
 * A DRAFT MAY BE INCOMPLETE; A SUBMISSION MAY NOT LEAVE A REQUIRED QUESTION BLANK
 *
 * The same requirement check runs on both paths, deliberately. A student cannot
 * prepare work that could never be handed in - being told at save time is far
 * kinder than discovering it at submit time, and it matches the rule the generic
 * form has always followed.
 */
class QuestionResponseService
{
    /** 20 MB, the same limit the generic evidence path applies. */
    public const MAX_UPLOAD_KB = 20 * 1024;

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    // â”€â”€ reading â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * An assignment's questions, in reading order.
     *
     * @return \Illuminate\Support\Collection<int, AssignmentQuestion>
     */
    public function questionsFor(Assignment $assignment)
    {
        return $assignment->questions()->inReadingOrder()->get();
    }

    /**
     * One attempt's stored evidence, keyed by question id.
     *
     * THE BASELINE FOR A MERGE, and scoped to the attempt it is handed.
     *
     * An empty map for a null attempt, which is the normal case for a SECOND
     * attempt: a resubmission starts with nothing and does not inherit the previous
     * attempt's files. That is what lets attempt 1 stay readable on its own row
     * without any of it leaking forward.
     *
     * @return array<int, \Illuminate\Support\Collection<int, AssignmentSubmissionItem>>
     */
    public function evidenceByQuestion(?AssignmentSubmission $submission): array
    {
        if (! $submission instanceof AssignmentSubmission) {
            return [];
        }

        return $this->evidenceFor($submission)
            ->whereNotNull('assignment_question_id')
            ->groupBy('assignment_question_id')
            ->all();
    }

    /**
     * An attempt's answers, keyed by question id, in the assignment's question order.
     *
     * @param  \Illuminate\Support\Collection<int, AssignmentQuestion>  $questions
     * @return array<int, AssignmentQuestionResponse|null>
     */
    public function answersFor(AssignmentSubmission $submission, $questions): array
    {
        $stored = $submission->relationLoaded('questionResponses')
            ? $submission->questionResponses
            : $submission->questionResponses()->get();

        $byQuestion = $stored->keyBy('assignment_question_id');

        $out = [];
        foreach ($questions as $question) {
            $out[(int) $question->id] = $byQuestion->get((int) $question->id);
        }

        return $out;
    }

    /**
     * This attempt's evidence for one question, in a stable order.
     *
     * Scoped by BOTH the attempt and the question. Scoping by the question alone
     * would return another attempt's photograph of the same question - which is
     * exactly how attempt 1's evidence becomes attempt 2's evidence.
     *
     * @return \Illuminate\Support\Collection<int, AssignmentSubmissionItem>
     */
    public function evidenceFor(AssignmentSubmission $submission, ?int $questionId = null)
    {
        return AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $submission->id)
            ->when(
                $questionId !== null,
                fn ($query) => $query->where('assignment_question_id', $questionId)
            )
            ->orderBy('id')
            ->get();
    }

    // â”€â”€ normalising the request â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Read the request's per-question answers into a clean, validated shape.
     *
     * @param  \Illuminate\Support\Collection<int, AssignmentQuestion>  $questions
     * @return array<int, array{text: ?string, items: list<array<string, mixed>>}>
     *
     * @throws ValidationException
     */
    public function normaliseAnswers(Assignment $assignment, $questions, $raw, array $baseline = []): array
    {
        if (! $assignment->isQuestionBased()) {
            // A generic assignment has no questions. A question id offered anyway is
            // refused rather than ignored: it would create evidence pointing at a
            // question that may belong to a different assignment entirely.
            if (is_array($raw) && $raw !== []) {
                throw ValidationException::withMessages([
                    'questions' => 'This assignment has no questions, so answers cannot be filed against one.',
                ]);
            }

            return [];
        }

        $rows = is_array($raw) ? $raw : [];
        $out = [];

        // ONE query for the whole request's question ids, resolved inside THIS
        // assignment. A crafted id from elsewhere is simply not in the result, and
        // the check below turns that into a refusal naming the offender.
        $asked = array_values(array_unique(array_map(
            'intval',
            array_filter(array_keys($rows), static fn ($key) => (int) $key > 0)
        )));

        $mine = $asked === [] ? collect() : AssignmentQuestion::query()
            ->where('assignment_id', $assignment->id)
            ->whereIn('id', $asked)
            ->get()
            ->keyBy('id');

        foreach ($asked as $id) {
            if (! $mine->has($id)) {
                throw ValidationException::withMessages([
                    'questions' => 'That answer refers to a question which is not part of this assignment.',
                ]);
            }
        }

        foreach ($questions as $question) {
            $id = (int) $question->id;
            $payload = is_array($rows[$id] ?? null) ? $rows[$id] : [];

            // The written answer is stored ONLY where the question accepts one.
            //
            // A question that asks for a photograph has no textarea, so a browser
            // sends no `text` at all - and a client that sent some anyway must not
            // have it stored, because `answerHasContent()` deliberately ignores text
            // on a question that does not accept it. Storing it would put prose on
            // the record of a question the student was never asked to write.
            $written = $question->acceptsWrittenResponse()
                ? $this->richTextOrNull($payload['text'] ?? null)
                : null;

            $out[$id] = [
                'text' => $written,
                'items' => $this->normaliseItems(
                    $question,
                    $payload['items'] ?? null,
                    $baseline[$id] ?? collect()
                ),
            ];
        }

        return $out;
    }

    /**
     * Validate the evidence offered for ONE question, against THAT question's kinds.
     *
     * @return list<array{kind:string,file:?\Illuminate\Http\UploadedFile,url:?string,label:?string,note:?string,capture_method:?string,remove:bool}>
     *
     * @throws ValidationException
     */
    private function normaliseItems(AssignmentQuestion $question, $raw, $baseline = null): array
    {
        $baseline = $baseline instanceof \Illuminate\Support\Collection
            ? $baseline->values()
            : collect($baseline ?: []);

        if ($raw === null) {
            // NOTHING WAS POSTED for this question. The evidence already filed is
            // the answer, so it is carried forward untouched - which is what makes
            // a Submit that re-posts only the textareas keep the photograph and
            // the recording the student already saved.
            return $baseline->map(fn (AssignmentSubmissionItem $item) => $this->baselineEntry($item))->all();
        }

        $rows = is_array($raw) ? $raw : [];
        $accepted = $question->acceptedResponseKinds();

        if ($rows !== [] && $accepted === []) {
            throw ValidationException::withMessages([
                'questions' => 'Question '.($question->sequence).' has no answer type chosen, so nothing can be attached to it.',
            ]);
        }

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kind = trim((string) ($row['kind'] ?? ''));
            $label = trim((string) ($row['label'] ?? '')) ?: null;
            $note = trim((string) ($row['note'] ?? '')) ?: null;
            $file = $row['file'] ?? null;
            $url = trim((string) ($row['url'] ?? ''));
            $slotText = $this->richTextOrNull($row['text'] ?? null);

            // â•â• AN UNUSED SLOT IS NOT AN ITEM â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
            //
            // The rendered form posts a hidden `kind` for every slot it draws, so
            // the server knows which allowlist a file must satisfy. That hidden
            // input is submitted whether or not the student used the slot, which
            // means a slot the student never touched arrives indistinguishable
            // from one they declared and failed to complete.
            //
            // Deciding EMPTINESS FIRST is the whole fix. It is what makes the
            // accepted types read as ALTERNATIVES: a slot is only ever validated
            // once it actually carries evidence, so no rule is evaluated against a
            // file that does not exist, and none is evaluated against every
            // declared kind at once.
            //
            // Before this, a question accepting [written, document] and answered by
            // TYPING was refused with "Choose a file for question 1." - the unused
            // document slot was read as a supplied document with a missing file.
            if (! $file instanceof UploadedFile && $url === '' && $slotText === null) {
                // THE SLOT IS UNUSED, WHICH MEANS "UNCHANGED" - NOT "DELETE IT".
                //
                // A file input cannot be repopulated by the browser, so on every
                // SUBMIT - and on every page reload - each slot arrives empty even
                // though the student attached something yesterday. Treating that as
                // "there is nothing here" would destroy a photograph, a document
                // and any browser recording on the press of the button they used
                // to hand the work in.
                //
                // So the stored item is carried forward. It is removed only when the
                // student ticks the REMOVE box the form renders beside an attached
                // file, because removal is something a file input cannot express
                // and the product has to offer a control that can.
                $carried = $baseline->first(
                    fn (AssignmentSubmissionItem $item) => $item->kind === $kind
                );

                if ($carried) {
                    $entry = $this->baselineEntry($carried);
                    $entry['remove'] = ! empty($row['remove']);
                    $out[] = $entry;
                } elseif (! empty($row['remove'])) {
                    // An explicit removal of something that is not there. Harmless,
                    // and accepted rather than refused so the control is idempotent.
                    $out[] = [
                        'kind' => $kind, 'file' => null, 'url' => null,
                        'label' => $label, 'note' => $note,
                        'capture_method' => null, 'remove' => true,
                    ];
                }

                continue;
            }

            // A slot whose file arrived with an upload error HAS evidence the
            // student tried to supply. Reporting that is far more useful than
            // skipping it and then telling them the question is simply blank.
            if ($file instanceof UploadedFile && ! $file->isValid()) {
                throw ValidationException::withMessages([
                    'questions' => 'The file for question '.$question->sequence
                        .' did not upload. Try again, or submit a smaller file.',
                ]);
            }

            // From here the slot CARRIES evidence, so it faces the full sequence.

            if (! AssignmentSubmissionItem::isConfigurableKind($kind)) {
                throw ValidationException::withMessages([
                    'questions' => 'That kind of answer is not accepted here.',
                ]);
            }

            // THE QUESTION'S OWN ALLOWLIST, not the assignment's. This is what stops
            // a document arriving in an "Image" slot, where a lecturer would read
            // it as photographic evidence - and it is why skipping empty slots is
            // not a loosening: a SUPPLIED file of the wrong kind is still refused.
            if (! in_array($kind, $accepted, true)) {
                throw ValidationException::withMessages([
                    'questions' => 'Question '.$question->sequence.' does not ask for '
                        .strtolower(AssignmentSubmissionItem::KIND_LABELS[$kind] ?? $kind).'.',
                ]);
            }

            // â”€â”€ Written response â”€â”€
            // The written answer lives on the RESPONSE row, not on an evidence
            // item, so "I wrote something" is one fact in one home rather than two
            // that can disagree.
            if ($kind === AssignmentSubmissionItem::KIND_TEXT) {
                $out[] = [
                    'kind' => $kind, 'file' => null, 'url' => null,
                    'label' => $label, 'note' => $note, 'capture_method' => null, 'remove' => false,
                ];

                continue;
            }

            if (AssignmentSubmissionItem::isLinkKind($kind)) {
                // A BLANK link box has already been skipped as an unused slot, so
                // reaching here means the student really did type something. A
                // present-but-malformed one is still refused.
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

                if ($url === '' || ! in_array($scheme, AssignmentSubmissionItem::LINK_SCHEMES, true)) {
                    throw ValidationException::withMessages([
                        'questions' => 'The link for question '.$question->sequence
                            .' must be a full http or https address.',
                    ]);
                }

                $out[] = [
                    'kind' => $kind, 'file' => null, 'url' => $url,
                    'label' => $label, 'note' => $note, 'capture_method' => null, 'remove' => false,
                ];

                continue;
            }

            $this->assertUploadable($file, $kind, $question);

            $out[] = [
                'kind' => $kind, 'file' => $file, 'url' => null,
                'label' => $label, 'note' => $note,
                'capture_method' => $this->normaliseCaptureMethod($row['capture_method'] ?? null, $kind),
                'remove' => false,
            ];
        }

        return $out;
    }

    /**
     * Where the bytes came from, and nothing more.
     *
     * Recorded because it is a fact about the upload, and used for NOTHING that
     * decides whether the upload is acceptable: the file itself has already been
     * validated by extension, by content sniffing and by size above. A client
     * that says `browser_recording` while sending nothing therefore produces no
     * item at all, and the marker sees an unanswered question - which is the
     * truth - rather than a claim of a recording that does not exist.
     *
     * Refused for a kind that cannot be recorded, because a "recording" of a PDF is
     * a claim about something that does not exist.
     */
    private function normaliseCaptureMethod($value, string $kind): ?string
    {
        $method = is_string($value) ? trim($value) : '';

        if ($method === '') {
            return null;
        }

        if (! in_array($method, AssignmentSubmissionItem::CAPTURE_METHODS, true)) {
            return null;
        }

        if ($method === AssignmentSubmissionItem::CAPTURE_BROWSER
            && ! in_array($kind, [
                AssignmentSubmissionItem::KIND_AUDIO,
                AssignmentSubmissionItem::KIND_VIDEO,
            ], true)) {
            return null;
        }

        return $method;
    }

    /**
     * Validate a file against ITS OWN kind's allowlist, plus PIIE's content
     * sniffing and the shared size limit.
     */
    private function assertUploadable(UploadedFile $file, string $kind, AssignmentQuestion $question): void
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $allowed = AssignmentSubmissionItem::extensionsFor($kind);

        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages([
                'questions' => 'Question '.$question->sequence.' needs a '
                    .strtolower(AssignmentSubmissionItem::KIND_LABELS[$kind] ?? $kind)
                    .' to be one of: '.implode(', ', $allowed).'.',
            ]);
        }

        if ($file->getSize() > self::MAX_UPLOAD_KB * 1024) {
            throw ValidationException::withMessages([
                'questions' => 'Files are limited to 20 MB. Compress it, or link to the material instead.',
            ]);
        }

        $blocked = AssignmentSubmissionItem::blockedExtensions();
        $detected = strtolower((string) $file->guessExtension());

        if (in_array($extension, $blocked, true) || in_array($detected, $blocked, true)) {
            throw ValidationException::withMessages([
                'questions' => 'That file type cannot be submitted.',
            ]);
        }
    }

    // â”€â”€ requirements â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Every REQUIRED question must carry an answer.
     *
     * Asked as a LIST so the student is told everything that is missing in one go,
     * rather than fixing one question and being told about the next.
     *
     * @param  array<int, array{text: ?string, items: list<array<string, mixed>>}>  $answers
     *
     * @throws ValidationException
     */
    public function assertRequiredAnswered($questions, array $answers, bool $forDraft): void
    {
        $missing = [];
        $messages = [];

        foreach ($questions as $question) {
            if (! $question->is_required) {
                continue;
            }

            $answer = $answers[(int) $question->id] ?? ['text' => null, 'items' => []];

            if ($this->answerHasContent($question, $answer)) {
                continue;
            }

            $missing[] = $question->sequence;

            // ONE SENTENCE PER QUESTION, naming what would satisfy it.
            //
            // "These are still blank: question 2, question 3" says WHICH questions
            // but not what would answer them, and it is silent about the ones
            // already answered - which is exactly the gap that let a student who
            // had answered in writing be told to choose a file. A message is only
            // useful if the student can act on it without guessing.
            $messages[] = $question->unansweredMessage();
        }

        if ($missing === []) {
            return;
        }

        $messages[] = $forDraft
            ? 'A saved draft still has to be something you could hand in.'
            : 'Answer every required question before you submit.';

        throw ValidationException::withMessages(['questions' => $messages]);
    }

    /**
     * A stored evidence item, restated in the same shape as a freshly supplied
     * one, so the merge below and the completeness check above see ONE kind of
     * value rather than two.
     *
     * A DELETION MARKER, deliberately, rather than a reference to the row. The
     * caller works from "what will be on the attempt afterwards", and a row id
     * would be a second thing to keep in step. A marker means the merge must not
     * touch a file this request did not resend, and must delete one it did.
     *
     * @return array<string, mixed>
     */
    private function baselineEntry(AssignmentSubmissionItem $item): array
    {
        return [
            'kind' => (string) $item->kind,
            'file' => null,
            'url' => $item->url,
            'label' => $item->label,
            'note' => $item->note,
            'capture_method' => $item->capture_method,
            'remove' => false,
            '__keep_id' => (int) $item->id,
            '__stored_path' => $item->stored_path,
        ];
    }

    /**
     * Does this answer carry something a marker could read?
     *
     * Deliberately STRICTER than "the field is not empty": a question that accepts
     * only an image is not answered by typing into the image slot, and a link must
     * be a real http(s) address rather than three characters of punctuation.
     */
    private function answerHasContent(AssignmentQuestion $question, array $answer): bool
    {
        // "EVERY TICKED KIND", when the lecturer asked for that.
        //
        // The authoring screen offers a checkbox reading "The student must provide
        // every type ticked above", and until now nothing checked it: any single
        // kind with evidence answered the question. That is right for the ordinary
        // any-one-of case and exactly wrong for the all-of one a lecturer can
        // explicitly request - and it is the same failure this whole exercise is
        // about, one level down. A promise the interface makes and the platform does
        // not keep is worse than not offering the choice at all.
        //
        // Each ticked kind must be present, and each is judged on its own terms: a
        // retained file counts, a written answer counts, a valid link counts.
        if ($question->require_all) {
            $satisfied = [];

            if ($question->acceptsWrittenResponse() && ! empty($answer['text'])) {
                $satisfied[AssignmentSubmissionItem::KIND_TEXT] = true;
            }

            foreach ($answer['items'] as $item) {
                if ($item['kind'] === AssignmentSubmissionItem::KIND_TEXT) {
                    continue;
                }

                // Retained on this attempt, or freshly supplied.
                $isRetained = ! empty($item['__keep_id']) && empty($item['remove'] ?? false);
                $isUploaded = $item['file'] instanceof UploadedFile
                    && (int) $item['file']->getSize() > 0;
                $isLinked = AssignmentSubmissionItem::isLinkKind($item['kind'])
                    && in_array(
                        strtolower((string) parse_url((string) $item['url'], PHP_URL_SCHEME)),
                        AssignmentSubmissionItem::LINK_SCHEMES,
                        true
                    );

                if ($isRetained || $isUploaded || $isLinked) {
                    $satisfied[$item['kind']] = true;
                }
            }

            foreach ($question->acceptedResponseKinds() as $kind) {
                if (empty($satisfied[$kind])) {
                    return false;
                }
            }

            return true;
        }

        // ── ANY ONE, which is the ordinary case ──
        if ($question->acceptsWrittenResponse() && ! empty($answer['text'])) {
            return true;
        }

        foreach ($answer['items'] as $item) {
            // The text item is already represented in `$answer['text']` above.
            if ($item['kind'] === AssignmentSubmissionItem::KIND_TEXT) {
                continue;
            }

            // EVIDENCE ALREADY FILED ON THIS ATTEMPT.
            //
            // A carried-forward item has no new `file` - there was no new upload -
            // and is not a link, so a check that only asked "is there a file" and
            // "is there a link" would read it as nothing at all, and refuse a
            // question whose photograph is sitting right there on the record.
            //
            // This is the same failure as the reported one, wearing the opposite
            // mask: an absent value read as "nothing here" when it meant "already
            // supplied". The question this method has to answer is "will this
            // question have evidence on the attempt AFTERWARDS", and for a retained
            // row the answer is yes - unless the student asked to withdraw it.
            if (! empty($item['__keep_id']) && empty($item['remove'] ?? false)) {
                return true;
            }

            if ($item['file'] instanceof UploadedFile) {
                if ((int) $item['file']->getSize() > 0) {
                    return true;
                }

                continue;
            }

            if (AssignmentSubmissionItem::isLinkKind($item['kind'])
                && in_array(
                    strtolower((string) parse_url((string) $item['url'], PHP_URL_SCHEME)),
                    AssignmentSubmissionItem::LINK_SCHEMES,
                    true
                )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this attempt carry any answer at all?
     *
     * The question-based counterpart of "an empty submission cannot be graded". An
     * attempt on a question-based assignment with every question blank is not work.
     */
    public function hasAnyAnswer($questions, array $answers): bool
    {
        foreach ($questions as $question) {
            if ($this->answerHasContent($question, $answers[(int) $question->id] ?? ['text' => null, 'items' => []])) {
                return true;
            }
        }

        return false;
    }

    // â”€â”€ writing â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * File these answers against an attempt.
     *
     * A RESPONSE ROW IS WRITTEN FOR EVERY QUESTION, answered or not.
     *
     * That is the decision the whole read model rests on: "not answered" becomes a
     * stored fact (`text_response` is null and no evidence rows exist) instead of
     * an inference from a row that is not there. Three screens and a future
     * gradebook then cannot disagree about whether a question was answered.
     *
     * Evidence is replaced WHOLESALE, and orphaned bytes are deleted. Resubmitting
     * must not leave attempt 1's photograph attached to attempt 2 as though it were
     * new evidence - and a withdrawn file is personal data that should not outlive
     * the attempt that justified keeping it.
     */
    public function store(
        AssignmentSubmission $submission,
        Assignment $assignment,
        $questions,
        array $answers
    ): void {
        DB::transaction(function () use ($submission, $assignment, $questions, $answers) {
            foreach ($questions as $question) {
                $answer = $answers[(int) $question->id] ?? ['text' => null, 'items' => []];

                $row = AssignmentQuestionResponse::query()->firstOrNew([
                    'assignment_submission_id' => $submission->id,
                    'assignment_question_id' => $question->id,
                ]);

                $row->school_id = (int) $assignment->school_id;
                $row->assignment_id = (int) $assignment->id;
                $row->course_offering_id = $assignment->course_offering_id;
                $row->text_response = $answer['text'];
                $row->save();

                $this->replaceQuestionEvidence($submission, $assignment, (int) $question->id, $answer['items']);
            }
        });
    }

    /**
     * Replace the evidence filed against one question on one attempt.
     *
     * Scoped to the question as well as the attempt. Replacing everything on the
     * attempt instead would mean re-uploading every other question's photograph to
     * keep one file, which is not an option on a slow connection and is not what
     * "I changed my answer to question 2" should cost.
     */
    private function replaceQuestionEvidence(
        AssignmentSubmission $submission,
        Assignment $assignment,
        int $questionId,
        array $items
    ): void {
        $existing = AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $submission->id)
            ->where('assignment_question_id', $questionId)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            // The written answer already lives on the response row.
            if ($item['kind'] === AssignmentSubmissionItem::KIND_TEXT) {
                continue;
            }

            $keepId = (int) ($item['__keep_id'] ?? 0);
            $isCarriedForward = $keepId > 0 && $existing->has($keepId);

            // â”€â”€ NO CHANGE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            // The slot arrived empty, so the stored file stands. Its bytes are NOT
            // deleted and its row is NOT rewritten.
            if ($isCarriedForward) {
                if (empty($item['remove'] ?? false)) {
                    continue;
                }

                // â”€â”€ EXPLICIT REMOVAL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                // The student ticked the Remove box, so the row AND its bytes go.
                $this->forget($existing->get($keepId));

                continue;
            }

            // â”€â”€ A REAL REPLACEMENT â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
            // A file arrived for this kind. Whatever was stored under this kind on
            // THIS attempt is superseded, and its bytes must not be retained.
            foreach ($existing as $row) {
                if ((string) $row->kind === (string) $item['kind']) {
                    $this->forget($row);
                }
            }

            $row = new AssignmentSubmissionItem();
            $row->school_id = (int) $assignment->school_id;
            $row->assignment_submission_id = $submission->id;
            $row->assignment_id = (int) $assignment->id;
            $row->course_offering_id = $assignment->course_offering_id;
            $row->assignment_question_id = $questionId;
            $row->kind = $item['kind'];
            $row->label = $item['label'];
            $row->note = $item['note'];
            $row->url = $item['url'];
            $row->capture_method = $item['capture_method'];

            if ($item['file'] instanceof UploadedFile) {
                $this->storeItemFile($row, $item['file']);
            }

            $row->save();
        }
    }

    /**
     * Delete one evidence row and the bytes behind it.
     *
     * A DELETION, so it is called only when a row has genuinely been superseded -
     * replaced by a new file, or removed by the student's own request. It is never
     * called for a file the request simply did not resend.
     */
    private function forget(AssignmentSubmissionItem $item): void
    {
        if ($item->stored_path) {
            Storage::disk('local')->delete($item->stored_path);
        }

        $item->delete();
    }

    /**
     * Store one evidence file OUTSIDE THE WEB ROOT under a generated name.
     *
     * The client's filename never becomes the path, and the path is namespaced by
     * offering, assignment, question and kind - so one student's recording cannot
     * be read under another's submission, and a recorded clip is not reachable at
     * a guessable location. The original name is kept only as a display label.
     *
     * A browser recording arrives here as an ordinary upload, which is the whole
     * reason this needs no separate code path: the privacy and authorisation
     * properties were already correct for an uploaded audio file.
     */
    private function storeItemFile(AssignmentSubmissionItem $item, UploadedFile $file): void
    {
        $storedName = 'assignment-submissions/'
            .$item->course_offering_id.'/'
            .$item->assignment_id.'/q'
            .$item->assignment_question_id.'/'
            .$item->kind.'/'
            .bin2hex(random_bytes(16)).'.'.strtolower((string) $file->getClientOriginalExtension());

        Storage::disk('local')->put($storedName, file_get_contents($file->getRealPath()));

        $item->stored_path = $storedName;
        $item->original_name = (string) $file->getClientOriginalName();
        $item->mime_type = (string) ($file->getClientMimeType() ?: 'application/octet-stream');
        $item->size_bytes = (int) $file->getSize();
    }

    /**
     * The written answer, sanitised on the way IN.
     *
     * The SHARED sanitizer, the same one question prompts, lesson bodies and
     * assignment instructions pass through. One rich-text store in PIIE with one
     * allowlist, because two stores with two filters is how one of them ends up
     * with an XSS hole.
     *
     * Markup that carries no visible text counts as nothing, so an "empty" answer
     * cannot be smuggled past a required-question check by pasting an empty
     * paragraph.
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
}
