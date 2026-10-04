<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionItem;
use App\Support\Assignments\AssignmentAccess;
use App\Support\Assignments\AssignmentDisplay;
use App\Support\Assignments\AssignmentLifecycle;
use App\Support\Assignments\AssignmentState;
use App\Support\Assignments\GradingService;
use App\Support\Assignments\SubmissionService;
use App\Support\TenantConfiguration;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The student's side of Course Offering Assignments.
 *
 * EVERY ACTION AUTHORISES THE SAME WAY
 *
 * A confirmed registration for the exact Offering, and an assignment the lecturer
 * has actually released. A draft is a 404, not a 403, so these pages cannot be
 * used to discover what has not been published.
 *
 * THE FOUR THINGS A STUDENT MUST ALWAYS BE ABLE TO ANSWER
 *
 *   What do I need to do?  the instructions, objectives and submission mode
 *   When is it due?        in their OWN clock, from one stored instant
 *   Have I submitted?      draft / submitted / returned, per attempt
 *   What happens next?     attempts remaining, whether late work is allowed,
 *                          and whether the assignment is closed
 *
 * OPENING AN ASSIGNMENT CHANGES NOTHING
 *
 * Nothing in this controller records a submission on a GET. Reading a task and
 * leaving has not submitted anything and has consumed no attempt. Only an
 * explicit `submit` — a POST carrying an idempotency key — does.
 */
class StudentCourseAssignmentController extends Controller
{
    public function __construct(
        private readonly AssignmentAccess $access,
        private readonly SubmissionService $submissions,
        private readonly GradingService $grading,
    ) {}

    /**
     * GET /student/courses/{id}/assignments
     *
     * The student's assignment list for one course, each row carrying a factual
     * state rather than a colour: Upcoming, Open, Submitted, Returned, or
     * Overdue/Missing where that is factually true.
     */
    public function index(Request $request, int $id): View
    {
        $student = $request->user();
        $offering = $this->access->resolveOffering($student, $id);

        if (! $this->access->canStudentAccess($student, $offering)) {
            abort(404);
        }

        // Only released assignments. A draft or an unreleased scheduled
        // assignment is not merely hidden from the list, it is unreachable -
        // resolveForStudent refuses it too.
        $visible = Assignment::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END, due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Assignment $assignment) => AssignmentLifecycle::isOpenToStudents($assignment))
            ->values();

        $rows = $visible->map(function (Assignment $assignment) use ($student) {
            $latest = $this->submissions->latestSubmission($assignment, $student);
            $used = $this->submissions->attemptsUsed($assignment, $student);
            $draft = $this->submissions->currentDraft($assignment, $student);

            return [
                'assignment' => $assignment,
                'state' => $this->stateFor($assignment, $latest, $used, (bool) $draft),
                'submission' => $latest,
                'hasDraft' => (bool) $draft,
                'attempts_used' => $used,
                'attempts_allowed' => $assignment->attemptsAllowed(),
            ];
        });

        return view('student.course_assignments.index', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'unitName' => optional($offering->subject)->name ?: $offering->reference,
            'rows' => $rows,
            'display' => app(AssignmentDisplay::class),
        ]);
    }

    /**
     * GET one assignment.
     *
     * Records nothing. The page a student READS is not evidence that they did
     * anything, and treating it as such is how "3 of 8 completed" quietly becomes
     * a claim about page views.
     */
    public function show(Request $request, int $id, int $assignment): View
    {
        $student = $request->user();
        $target = $this->access->resolveForStudent($student, $id, $assignment);
        $offering = $this->access->resolveOffering($student, $id);

        $attempts = $this->submissions->attemptsFor($target, $student);
        $submissions = $attempts->filter(fn (AssignmentSubmission $row) => ! $row->is_draft)->values();
        $latest = $submissions->last();
        $draft = $attempts->first(fn (AssignmentSubmission $row) => $row->is_draft);
        $used = $submissions->count();

        $canSubmit = AssignmentLifecycle::mayAcceptNewSubmission($target, $used);

        // DERIVED, and the same rule the services use: an assignment with at least
        // one question is answered question by question. Everything below is
        // conditional on it, so a GENERIC assignment renders and behaves exactly as
        // it did before this feature existed.
        $questionBased = $target->isQuestionBased();

        $questions = $questionBased
            ? $this->submissions->questionsFor($target)
            : collect();

        // The attempt being EDITED is the draft when there is one, otherwise the
        // most recent submission. Answers are read from THAT row, so a resumed
        // draft shows the work in progress rather than the work already handed in.
        $editing = $draft ?? $latest;

        $questionAnswers = ($questionBased && $editing)
            ? $this->submissions->answersFor($editing, $questions)
            : [];

        // The evidence already filed, grouped by question, so each input can state
        // what is on file. A browser cannot re-populate a file input, so anything
        // it silently dropped would be lost on submit unless the page says it is
        // there.
        $evidenceByQuestion = collect();
        if ($questionBased && $editing) {
            $evidenceByQuestion = $editing->items
                ->whereNotNull('assignment_question_id')
                ->groupBy('assignment_question_id');
        }

        return view('student.course_assignments.show', [
            'questionBased' => $questionBased,
            'questions' => $questions,
            'questionAnswers' => $questionAnswers,
            'evidenceByQuestion' => $evidenceByQuestion,
            // One token per page render, carried by the submit form. The UNIQUE
            // index on assignment_submissions.idempotency_key is the actual guard:
            // a second request bearing the same token finds the attempt already
            // made and returns it, so a double-click cannot consume two attempts.
            'attemptKey' => \Illuminate\Support\Str::random(32),
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'unitName' => optional($offering->subject)->name ?: $offering->reference,
            'assignment' => $target->load('resources'),
            'display' => AssignmentDisplay::make($target, $student),
            'state' => $this->stateFor($target, $latest, $used, (bool) $draft),
            'submissions' => $submissions,
            'latest' => $latest,
            'draft' => $draft,
            'attempts_used' => $used,
            'attempts_allowed' => $target->attemptsAllowed(),
            'canSubmit' => $canSubmit,
            // Null when nothing is wrong, so the page can stay quiet rather than
            // showing a refusal that does not apply.
            'refusalReason' => $canSubmit
                ? null
                : AssignmentLifecycle::refusalReason($target, $used),
        ]);
    }

    /**
     * POST prepare work WITHOUT submitting it.
     *
     * Consumes no attempt, sets no `submitted_at`, and leaves the assignment
     * showing as not submitted. This is the difference between "I am working on
     * it" and "I have handed it in", and the brief needs both to be real.
     */
    public function saveDraft(Request $request, int $id, int $assignment): RedirectResponse
    {
        $student = $request->user();
        $target = $this->access->resolveForStudent($student, $id, $assignment);

        $this->guarded(fn () => $this->submissions->saveDraft($student, $target, [
            'text' => $request->input('text'),
            'items' => $this->evidenceFrom($request, $target),
            // The nested per-question answers. Passed straight through; the service
            // resolves every question id inside the assignment and validates each
            // item against THAT question's own accepted kinds.
            //
            // A generic assignment sends `questions` as null, so `normaliseAnswers`
            // sees nothing and the original path is unchanged.
            'questions' => $this->questionAnswersFrom($request),
        ]));

        return back()->with(
            'success',
            'Your work is saved. It is NOT submitted yet — use Submit when you are ready to hand it in.'
        );
    }

    /**
     * POST submit.
     *
     * Carries an idempotency key, so a double-click, a retried request or two
     * open tabs all collapse onto one attempt rather than consuming two.
     */
    public function submit(Request $request, int $id, int $assignment): RedirectResponse
    {
        $student = $request->user();
        $target = $this->access->resolveForStudent($student, $id, $assignment);

        $record = $this->guarded(fn () => $this->submissions->submit($student, $target, [
            'text' => $request->input('text'),
            'items' => $this->evidenceFrom($request, $target),
            'questions' => $this->questionAnswersFrom($request),
        ], (string) $request->input('idempotency_key')));

        return back()->with(
            'success',
            $record->isLate()
                ? 'Submitted. This was after the due date, so it has been recorded as late.'
                : 'Submitted. Your lecturer will mark it and return feedback here.'
        );
    }

    /**
     * GET the student's own attempt.
     *
     * Scoped to the authenticated student inside the resolved assignment, so
     * another student's submission is a 404 and cannot be read or downloaded by
     * substituting an id.
     */
    public function submission(Request $request, int $id, int $assignment, int $submission): View
    {
        $student = $request->user();
        $record = $this->grading->resolveSubmissionForStudent($student, $id, $assignment, $submission);
        $offering = $this->access->resolveOffering($student, $id);

        return view('student.course_assignments.submission', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'unitName' => optional($offering->subject)->name ?: $offering->reference,
            'assignment' => $record->assignment,
            'submission' => $record,
            'display' => AssignmentDisplay::make($record->assignment, $student),
        ]);
    }

    /**
     * Serve this Course Offering's cover image.
     *
     * A student's own course cover, on the student's own route. It is NOT served
     * from the lecturer namespace: that group redirects a student away before any
     * authorisation could run, and a redirect is not a privacy answer.
     *
     * The rule is the SAME one the lecturer route applies - a confirmed
     * registration, an allocation, or the academic office - because both call
     * `CourseCoverImage::assertMayView()`. One rule with two doors, not two rules.
     *
     * An Offering with no cover is a normal state, so this is a 404 rather than a
     * placeholder, and no caller should treat a missing cover as a fault.
     */
    public function coverImage(Request $request, int $id)
    {
        $student = $request->user();
        $offering = $this->access->resolveOffering($student, $id);

        app(\App\Support\CourseOffering\CourseCoverImage::class)
            ->assertMayView($student, $offering);

        if (! $offering->cover_image_path) {
            abort(404);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $disk->exists($offering->cover_image_path)) {
            abort(404);
        }

        return $disk->download(
            $offering->cover_image_path,
            $offering->cover_image_name ?: 'course-cover'
        );
    }
    /**
     * Open a lecturer's handout.
     *
     * Authorised on the same rule as the page that links it: a confirmed
     * registration on the exact Offering, and the assignment actually released.
     * A resource id from another tenant, another Offering, or a draft assignment
     * is a 404, so a handout (which may be a question paper or a marking scheme)
     * is not reachable by guessing.
     */
    public function resource(Request $request, int $resource)
    {
        $student = $request->user();

        $row = \App\Models\AssignmentResource::query()
            ->where('school_id', (int) $student->school_id)
            ->whereKey($resource)
            ->firstOrFail();

        $offering = $this->access->resolveOffering($student, (int) $row->course_offering_id);

        if (! $this->access->canStudentAccess($student, $offering)) {
            abort(404);
        }

        $assignment = $this->access->resolveForStudent(
            $student,
            (int) $offering->id,
            (int) $row->assignment_id
        );

        if ($assignment->id !== (int) $row->assignment_id || ! $row->isFile()) {
            abort(404);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $row->stored_name || ! $disk->exists($row->stored_name)) {
            abort(404);
        }

        return $disk->download($row->stored_name, $row->original_name ?: $row->title);
    }

    /**
     * Download ONE piece of evidence the student attached.
     *
     * Authorised by resolving the attempt for the authenticated student inside the
     * released assignment, and then the item inside THAT attempt. A student may
     * read their own evidence and nothing else; substituting an item id belonging
     * to another student, another attempt, another assignment or another tenant
     * is a 404, and the file lives outside the web root so there is no other way
     * to reach it.
     *
     * This is the route an in-browser recording's bytes would be served from, so
     * its privacy and authorisation are already right for that future case.
     */
    public function evidenceFile(Request $request, int $id, int $assignment, int $submission, int $item)
    {
        $student = $request->user();

        $record = $this->grading->resolveSubmissionForStudent($student, $id, $assignment, $submission);

        $row = AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $record->id)
            ->whereKey($item)
            ->firstOrFail();

        if (! $row->hasFile()) {
            abort(404);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $row->stored_path || ! $disk->exists($row->stored_path)) {
            abort(404);
        }

        return $disk->download($row->stored_path, $row->original_name ?: $row->kindLabel());
    }

    /**
     * Play back one of the student's OWN evidence items inside the page.
     *
     * So a recording they attached can be listened to and a demonstration watched,
     * rather than downloaded and opened in another program - which is the difference
     * between reviewing your own submission and having to find a file.
     *
     * Authorised through `resolveSubmissionForStudent()`, which proves the attempt
     * belongs to the AUTHENTICATED student inside the released assignment, so
     * another student's recording is a 404 and there is no way to discover it
     * exists. The same `EvidenceMedia` rule the lecturer's copy uses decides what
     * may be rendered; a document downloads instead.
     */
    public function evidenceMedia(Request $request, int $id, int $assignment, int $submission, int $item)
    {
        $record = $this->grading->resolveSubmissionForStudent($request->user(), $id, $assignment, $submission);

        $row = AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $record->id)
            ->whereKey($item)
            ->firstOrFail();

        return app(\App\Support\Assignments\EvidenceMedia::class)->inlineResponse($row);
    }

    /**
     * Download the student's own submitted file.
     *
     * The same ownership check as the page that links it, and the file lives
     * outside the web root, so this is the only way to reach it.
     */
    public function submissionFile(Request $request, int $id, int $assignment, int $submission)
    {
        $student = $request->user();
        $record = $this->grading->resolveSubmissionForStudent($student, $id, $assignment, $submission);

        if (! $record->hasFile()) {
            abort(404);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $record->file_path || ! $disk->exists($record->file_path)) {
            abort(404);
        }

        return $disk->download($record->file_path, $record->file_name ?: 'submission');
    }

    // ── internals ─────────────────────────────────────────────────────────
    /**
     * The state a student's work is in.
     *
     * Delegated to `AssignmentState`, which is the single vocabulary shared with
     * the Course Home card. The rule is unchanged and the wording is unchanged; it
     * simply no longer lives in a controller, where a second surface would have had
     * to copy it - and a second copy of a student-facing state is a second chance
     * to say something different about the same work.
     */
    private function stateFor(
        Assignment $assignment,
        ?AssignmentSubmission $latest,
        int $attemptsUsed,
        bool $hasDraft
    ): string {
        return AssignmentState::for($assignment, $latest, $attemptsUsed, $hasDraft);
    }

    /**
     * The per-question answers, WITH their uploaded files.
     *
     * `$request->input()` and `$request->file()` are DISJOINT views of one body, and
     * only the first of them was being read. Laravel moves every uploaded file out
     * of the input tree when it builds the request, so `input('questions')` returns
     * the text, the kinds and the URLs but no `file` key at all - and a question
     * asking for a photograph came back as "choose a file" no matter what the
     * student had actually attached.
     *
     * The generic evidence form never hit this, because it reads each file from the
     * top level with `$request->file($kind)`. The nested per-question shape has to
     * be reassembled, so this walks the file tree and writes each file back into
     * the same position in the input tree it was sent from.
     *
     * The merge is by POSITION, not by name: `questions[7][items][0][file]` goes
     * back to exactly that path, so a file can never be filed against a different
     * question or a different slot than the one its `kind` was sent with.
     *
     * @return array<string, mixed>
     */
    private function questionAnswersFrom(Request $request): array
    {
        $input = (array) $request->input('questions', []);
        $files = (array) $request->file('questions', []);

        return $this->mergeFilesInto($input, $files);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $files
     * @return array<string, mixed>
     */
    private function mergeFilesInto(array $input, array $files): array
    {
        foreach ($files as $key => $file) {
            if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $input[$key] = $file;
                continue;
            }

            if (is_array($file)) {
                // A nested slice, e.g. one question's `items` array.
                $input[$key] = $this->mergeFilesInto(
                    is_array($input[$key] ?? null) ? $input[$key] : [],
                    $file
                );
            }
        }

        return $input;
    }

    /**
     * Turn the request's per-kind inputs into the domain's list of evidence items.
     *
     * Only kinds the assignment ACCEPTS are collected, so a field the lecturer
     * never asked for cannot be used to attach something unexpected and then
     * leave the student wondering why a marker disregarded it.
     *
     * The written response is NOT built here: it is passed separately as `text`
     * by the caller, because it is stored on the submission rather than as an
     * item. "I wrote something" is one fact with one home, not two that can
     * disagree.
     *
     * @return list<array<string, mixed>>
     */
    private function evidenceFrom(Request $request, Assignment $assignment): array
    {
        $items = [];

        foreach ($assignment->acceptedEvidenceKinds() as $kind) {
            // The written response is the caller's business.
            if ($kind === AssignmentSubmissionItem::KIND_TEXT) {
                continue;
            }

            if (AssignmentSubmissionItem::isLinkKind($kind)) {
                $url = trim((string) $request->input('link_url', ''));

                if ($url !== '') {
                    $items[] = [
                        'kind' => $kind,
                        'url' => $url,
                        'label' => $request->input('link_label'),
                        'note' => $request->input('link_note'),
                    ];
                }

                continue;
            }

            // A FILE the student chose from their device. There is no capture path
            // of any kind here: this controller never requests a microphone, a
            // camera or a recording permission, and nothing simulates one. An
            // in-browser recording would feed this same file input, and the
            // storage, privacy and authorisation paths are already correct.
            $file = $request->file($kind);

            if ($file) {
                $items[] = [
                    'kind' => $kind,
                    'file' => $file,
                    'label' => $request->input($kind . '_label'),
                    'note' => $request->input($kind . '_note'),
                ];
            }
        }

        return $items;
    }
    /**
     * Turn a domain refusal into a form error the student can act on.
     *
     * @template T
     *
     * @param  callable():T  $operation
     * @return T
     */
    private function guarded(callable $operation)
    {
        try {
            return $operation();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['text' => $exception->getMessage()]);
        }
    }

    private function courseUnitLabel(\App\Models\CourseOffering $offering): string
    {
        $terminology = app(TenantConfiguration::class)->terminology(
            \App\Models\School::query()->find($offering->school_id)
        );

        return $terminology['course_unit'] ?? 'Course Unit';
    }
}
