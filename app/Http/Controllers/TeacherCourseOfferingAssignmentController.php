<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AssignmentResource;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentQuestion;
use App\Models\AssignmentSubmissionItem;
use App\Support\Assignments\AssignmentAccess;
use App\Support\Assignments\AssignmentDisplay;
use App\Support\Assignments\AssignmentLifecycle;
use App\Support\Assignments\AssignmentNotifier;
use App\Support\Assignments\AssignmentService;
use App\Support\Assignments\GradingService;
use App\Support\Assignments\QuestionGradingService;
use App\Support\Assignments\QuestionService;
use App\Support\CourseContent\HtmlSanitizer;
use App\Support\TenantConfiguration;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The lecturer's Course Offering Assignments workspace.
 *
 * AUTHORING IS A FULL PAGE, NOT A DRAWER
 *
 * An assignment is a document - instructions, objectives, marks, a deadline, a
 * submission mode and handouts - and a document does not belong in a modal. The
 * create and edit screens have their own URL, so they can be bookmarked,
 * reloaded, and left open in a second tab without losing work.
 *
 * THE OFFERING IS CONTEXT, NEVER A FIELD
 *
 * Every route carries the Offering id, and every write re-resolves the assignment
 * by id INSIDE that Offering before touching it. A substituted id cannot move an
 * assignment between Offerings or tenants, and a lecturer who has no allocation
 * learns nothing about Offerings they were not given.
 *
 * EVERY MUTATION RE-CHECKS AUTHORITY
 *
 * The route middleware proves a lecturer; `AssignmentAccess` proves the
 * allocation. Both are re-evaluated on every action rather than trusted from the
 * page that linked to it, so a stale tab or a guessed URL cannot author, publish
 * or mark.
 */
class TeacherCourseOfferingAssignmentController extends Controller
{
    public function __construct(
        private readonly AssignmentAccess $access,
        private readonly AssignmentService $assignments,
        private readonly GradingService $grading,
        private readonly HtmlSanitizer $sanitizer,
        private readonly QuestionService $questions,
        private readonly QuestionGradingService $questionGrading,
    ) {}

    /**
     * GET /teacher/course-offerings/{id}/assignments
     *
     * The list a lecturer opens from the Course Offering. It answers, per
     * assignment: what state is it in, when is it due, out of how many marks,
     * how many have submitted, and how many of those are marked.
     */
    public function index(Request $request, int $id): View
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $canManage = $this->access->canLecturerManage($request->user(), $offering);

        $assignments = Assignment::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->with('submissions:id,assignment_id,is_draft,marks_awarded,marks_released_at,submitted_at')
            // Eager-loaded so a list row can show its module's title without a
            // query PER ROW. A lecturer with twenty assignments in one Offering
            // would otherwise fire twenty extra queries to render a label.
            ->with('module:id,course_offering_id,title,sequence')
            // Lifecycle order, not alphabetical. A standard CASE rather than
            // MySQL's FIELD(), because FIELD() does not exist on SQLite - so the
            // ordering would pass on piie_main and fail in every test, which is
            // the worst way for a query to be wrong.
            ->orderByRaw(implode(', ', [
                "CASE status WHEN 'draft' THEN 0 WHEN 'scheduled' THEN 1 WHEN 'published' THEN 2 ELSE 3 END",
            ]))
            ->orderByDesc('id')
            ->get();

        // One eager-loaded set, summarised per row: the counts on the list and
        // the counts on any single assignment can then never disagree.
        $summary = $assignments->mapWithKeys(function (Assignment $assignment) {
            $submitted = $assignment->submissions->where('is_draft', false);
            $graded = $submitted->filter(
                fn (AssignmentSubmission $submission) => $submission->marks_awarded !== null
            );
            $released = $submitted->filter(
                fn (AssignmentSubmission $submission) => $submission->marks_released_at !== null
            );

            return [$assignment->id => [
                'submitted' => $submitted->count(),
                'graded' => $graded->count(),
                'released' => $released->count(),
                'label' => AssignmentLifecycle::lecturerLabel($assignment),
            ]];
        });

        return view('teacher.course_offerings.assignments.index', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'assignments' => $assignments,
            'summary' => $summary,
            'canManage' => $canManage,
            'confirmedStudents' => $this->access->eligibleRegistrations(
                (new Assignment)->forceFill([
                    'school_id' => $offering->school_id,
                    'course_offering_id' => $offering->id,
                ])
            )->count(),
        ]);
    }

    // ── authoring ─────────────────────────────────────────────────────────

    public function create(Request $request, int $id): View
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);

        $assignment = new Assignment();
        $assignment->course_offering_id = $offering->id;
        $assignment->status = Assignment::STATUS_DRAFT;
        $assignment->submission_type = Assignment::SUBMISSION_FILE_AND_TEXT;
        $assignment->late_policy = Assignment::LATE_ALLOWED;
        $assignment->max_marks = 100;
        // Sensible defaults so the common case needs no thought: open now, due
        // in a week, one attempt. Both remain editable.
        $assignment->released_at = now();
        $assignment->due_date = now()->addWeek();

        // STARTING MODULE, from the module page's "Create assignment" link.
        //
        // `assignments.course_offering_module_id` has existed all along, with a
        // selector on the form, a validation rule and `resolveModule()` in the
        // service - so a lecturer could already attach an assignment to a module, but
        // only by choosing it from a dropdown that lists every module on the page.
        // Arriving from a module and having to re-pick it is the friction.
        //
        // The id is taken from the query string, so it is NOT trusted: it is matched
        // against this Offering's own module options and silently ignored if it is
        // not one of them. That keeps `resolveModule()`'s own tenant and ownership
        // check as the authority rather than adding a second one here.
        $requestedModule = (int) $request->query('course_offering_module_id');
        if ($requestedModule > 0) {
            $options = $this->moduleOptions($offering);

            $belongs = false;
            foreach ($options as $option) {
                if ((int) ($option->id ?? 0) === $requestedModule) {
                    $belongs = true;
                    break;
                }
            }

            if ($belongs) {
                $assignment->course_offering_module_id = $requestedModule;
            }
        }

        return view('teacher.course_offerings.assignments.form', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'assignment' => $assignment,
            'mode' => 'create',
            'modules' => $this->moduleOptions($offering),
            // The evidence kinds a lecturer may configure, and the rules PIIE can
            // actually evaluate. The view reads these rather than restating them,
            // so the form can never offer a kind or a rule the service would
            // refuse.
            'evidenceKinds' => \App\Models\AssignmentSubmissionItem::CONFIGURABLE_KINDS,
            'implementedRules' => Assignment::IMPLEMENTED_COMPLETION_RULES,
        ]);
    }

    public function store(Request $request, int $id): RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);

        $validated = $request->validate($this->rules($offering, existing: null));

        $assignment = $this->guarded(fn () => $this->assignments->create(
            $request->user(),
            $offering,
            $this->payload($request, $validated)
        ));

        return redirect()
            ->route('teacher.course_offerings.assignments.show', [$offering->id, $assignment->id])
            ->with('success', 'Assignment saved as a draft. Students cannot see it until you publish it.');
    }

    public function edit(Request $request, int $id, int $assignment): View
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);

        $offering = $this->access->resolveOffering($request->user(), $id);

        return view('teacher.course_offerings.assignments.form', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'assignment' => $target->load('resources'),
            'mode' => 'edit',
            'modules' => $this->moduleOptions($offering),
            'evidenceKinds' => \App\Models\AssignmentSubmissionItem::CONFIGURABLE_KINDS,
            'implementedRules' => Assignment::IMPLEMENTED_COMPLETION_RULES,
        ]);
    }

    public function update(Request $request, int $id, int $assignment): RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);

        $validated = $request->validate($this->rules($offering, existing: $target));

        $this->guarded(fn () => $this->assignments->update(
            $request->user(),
            $target,
            $this->payload($request, $validated)
        ));

        return redirect()
            ->route('teacher.course_offerings.assignments.show', [$id, $target->id])
            ->with('success', 'Assignment saved.');
    }

    /**
     * GET /teacher/course-offerings/{id}/assignments/{assignment}
     *
     * The lecturer's view of one assignment, and the entry point to marking.
     */
    public function show(Request $request, int $id, int $assignment): View
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);
        $offering = $this->access->resolveOffering($request->user(), $id);

        return view('teacher.course_offerings.assignments.show', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'assignment' => $target->load(['resources', 'module']),
            // The module, named explicitly. The view reads `$module` rather than
            // reaching through `$assignment->module`, so the page states once, at
            // the edge, what it needs - and a missing variable is a 500 rather than
            // a silently absent panel.
            'module' => $target->module,
            'markingList' => $this->grading->markingList($target),
            'display' => AssignmentDisplay::make($target, $request->user()),
            'moduleTasks' => $target->isModuleTask()
                ? $target->module?->tasks()->orderBy('id')->get() ?? collect()
                : collect(),
        ]);
    }

    /**
     * GET preview: exactly what a student will read, rendered before it is
     * published. This is the same markup as the student page, so "Preview"
     * answers a real question rather than showing a private approximation.
     */
    public function preview(Request $request, int $id, int $assignment): View
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);
        $offering = $this->access->resolveOffering($request->user(), $id);

        // Unsaved form input wins, so a lecturer can check wording before
        // committing. Sanitised through the same filter as a save, so a preview
        // can never become a way to render unfiltered HTML.
        $preview = clone $target;
        if ($request->boolean('preview_draft')) {
            $preview->title = (string) $request->input('title', $target->title);
            $preview->instructions = $this->sanitizer->sanitize(
                $request->input('instructions', (string) $target->instructions)
            );
            $preview->learning_objectives = $request->input(
                'learning_objectives',
                $target->learning_objectives
            );
            $preview->max_marks = $request->input('max_marks', $target->max_marks);
        }

        return view('teacher.course_offerings.assignments.preview', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'assignment' => $preview,
            'display' => AssignmentDisplay::make($preview, $request->user()),
            'isUnsavedPreview' => $request->boolean('preview_draft'),
            // An unsaved preview must not inherit the stored attachment, or the
            // lecturer would be shown the module a SAVED task belongs to while
            // reading the wording of one they have not saved yet.
            'module' => $request->boolean('preview_draft') ? null : $target->module,
        ]);
    }

    // ── lifecycle ─────────────────────────────────────────────────────────

    /**
     * POST a lifecycle move.
     *
     * The announcement decision is made by the service, not here, so a publish on
     * one path cannot forget to notify while another remembers.
     */
    public function transition(Request $request, int $id, int $assignment, string $to): RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);

        if (! in_array($to, Assignment::STATUSES, true)) {
            throw new HttpException(404, 'Unknown assignment action.');
        }

        $result = $this->guarded(fn () => $this->assignments->transition($request->user(), $target, $to));
        /** @var Assignment $updated */
        $updated = $result['assignment'];

        if ($result['became_visible']) {
            AssignmentNotifier::announcePublished($updated);
        }

        return redirect()
            ->route('teacher.course_offerings.assignments.show', [$id, $updated->id])
            ->with('success', $this->transitionMessage($to, $updated));
    }

    public function destroy(Request $request, int $id, int $assignment): RedirectResponse
    {
        $offering = $this->access->resolveOffering($request->user(), $id);
        $this->access->assertCanManage($request->user(), $offering);
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);

        $this->guarded(fn () => $this->assignments->delete($request->user(), $target));

        return redirect()
            ->route('teacher.course_offerings.assignments.index', $id)
            ->with('success', 'Draft assignment deleted.');
    }

    // ── questions ─────────────────────────────────────────────────────────

    /**
     * GET the question builder for one assignment.
     *
     * Its own page rather than a panel, for the same reason authoring is: a
     * question paper is a document, and a document does not belong in a modal.
     *
     * It renders the MARK INTEGRITY panel straight from
     * `questionReadinessProblems()` - the same list that refuses publication - so
     * a lecturer can see what is wrong and fix it before pressing Publish, rather
     * than discovering it as an error afterwards.
     */
    public function questions(Request $request, int $id, int $assignment): View
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);
        $offering = $this->access->resolveOffering($request->user(), $id);

        $rows = $this->questions->questionsFor($target);

        // `load()` so the readiness methods read the already-loaded relation rather
        // than issuing one query per question.
        $target->load('questions');

        return view('teacher.course_offerings.assignments.questions', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'assignment' => $target,
            'module' => $target->module,
            'questions' => $rows,
            'readiness' => $target->questionReadinessProblems(),
            'questionsTotal' => $target->questionMarksTotal(),
            'frozen' => $this->questions->questionsAreFrozen($target),
            'freezeReason' => $this->questions->freezeReason($target),
            'configurableKinds' => \App\Models\AssignmentSubmissionItem::CONFIGURABLE_KINDS,
        ]);
    }

    public function storeQuestion(Request $request, int $id, int $assignment): RedirectResponse
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);

        $this->guardedQuestion(fn () => $this->questions->add(
            $request->user(),
            $target,
            $this->questionPayload($request)
        ));

        return back()->with('success', 'Question added. Check the marks total before you publish.');
    }

    public function updateQuestion(Request $request, int $id, int $assignment, int $question): RedirectResponse
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);

        $row = AssignmentQuestion::query()->whereKey($question)->firstOrFail();

        $this->guardedQuestion(fn () => $this->questions->update(
            $request->user(),
            $target,
            $row,
            $this->questionPayload($request)
        ));

        return back()->with('success', 'Question updated.');
    }

    public function destroyQuestion(Request $request, int $id, int $assignment, int $question): RedirectResponse
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);

        $row = AssignmentQuestion::query()->whereKey($question)->firstOrFail();

        $this->guardedQuestion(fn () => $this->questions->delete($request->user(), $target, $row));

        return back()->with('success', 'Question removed. The marks total has changed - check it before you publish.');
    }

    /**
     * POST a new reading order, as an ordered list of question ids.
     *
     * A POST and not a drag-and-drop PUT, because a form of hidden inputs holding
     * the new order works with JavaScript switched off and degrades to "leave them
     * where they are" - which is a far better failure than a broken drag.
     */
    public function reorderQuestions(Request $request, int $id, int $assignment): RedirectResponse
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);

        $this->guardedQuestion(fn () => $this->questions->reorder(
            $request->user(),
            $target,
            (array) $request->input('order', [])
        ));

        return back()->with('success', 'Question order updated.');
    }

    /**
     * POST: set the assignment's total to the sum of its questions.
     *
     * The one-click that makes mark integrity achievable without a lecturer adding
     * up the questions they just wrote and typing the same number into another box.
     */
    public function adoptQuestionMarks(Request $request, int $id, int $assignment): RedirectResponse
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);

        $this->guardedQuestion(fn () => $this->questions->adoptMarksTotal($request->user(), $target));

        return back()->with('success', 'The assignment total now matches the questions.');
    }

    // ── grading ───────────────────────────────────────────────────────────

    /**
     * Record marks and feedback against each question of an attempt.
     *
     * NO `marks_awarded` INPUT EXISTS ON THIS ROUTE, BY DESIGN. The total is the
     * sum of the per-question marks, so there is nothing for a lecturer to type
     * that could contradict them.
     */
    public function gradeByQuestion(Request $request, int $id, int $assignment, int $submission): RedirectResponse
    {
        $this->access->resolveForManager($request->user(), $id, $assignment);
        $record = $this->grading->resolveSubmissionForManager($request->user(), $id, $assignment, $submission);

        $this->guardedQuestion(fn () => $this->questionGrading->gradeByQuestions(
            $request->user(),
            $record,
            [
                'marks' => $request->input('marks', []),
                // Per-question feedback is a separate input from the overall one, so
                // the two cannot overwrite each other when both are filled in.
                'feedback_by_question' => $request->input('feedback_by_question', []),
                'feedback' => $request->input('feedback'),
            ]
        ));

        return back()->with(
            'success',
            'Marks recorded. The total was calculated from the questions. '
            .'The student cannot see any of it until you return it.'
        );
    }


    /**
     * GET the marking list for one assignment.
     */
    public function submissions(Request $request, int $id, int $assignment): View
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);
        $offering = $this->access->resolveOffering($request->user(), $id);

        return view('teacher.course_offerings.assignments.submissions', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'assignment' => $target,
            'markingList' => $this->grading->markingList($target),
            'display' => AssignmentDisplay::make($target, $request->user()),
        ]);
    }

    /**
     * GET one student's work, ready to mark.
     */
    public function submission(Request $request, int $id, int $assignment, int $submission): View
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);
        $record = $this->grading->resolveSubmissionForManager($request->user(), $id, $assignment, $submission);
        $offering = $this->access->resolveOffering($request->user(), $id);

        // DERIVED, and the same rule the services use: an assignment with at least
        // one question is marked question by question. A GENERIC assignment reaches
        // the original single-mark form untouched.
        $questionBased = $target->isQuestionBased();
        $questions = $questionBased ? $this->questions->questionsFor($target) : collect();

        return view('teacher.course_offerings.assignments.submission', [
            'questionBased' => $questionBased,
            'questions' => $questions,
            // This attempt's stored answers, keyed by question id. `load()` so the
            // view reads one already-fetched collection rather than a query per
            // question - a four-question paper with thirty students would otherwise
            // fire a hundred of them.
            'questionAnswers' => $questionBased
                ? app(\App\Support\Assignments\QuestionResponseService::class)
                    ->answersFor($record->load('questionResponses'), $questions)
                : [],
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'assignment' => $target,
            'submission' => $record->load('student'),
            'display' => AssignmentDisplay::make($target, $request->user()),
            // Every attempt, so a resubmitted student can be compared with what
            // they sent first rather than only seeing the latest.
            'attempts' => AssignmentSubmission::query()
                ->where('assignment_id', $target->id)
                ->where('student_id', $record->student_id)
                ->where('is_draft', false)
                ->orderBy('attempt_no')
                ->get(),
        ]);
    }

    /**
     * Open the file a student submitted, for marking.
     *
     * Authorised by resolving the assignment in the Offering in the URL and the
     * submission inside THAT assignment - so a submission id from another tenant,
     * another Offering, or another student on the same assignment cannot be
     * substituted here to read work the lecturer should not see.
     */
    public function submissionFile(Request $request, int $id, int $assignment, int $submission)
    {
        $record = $this->grading->resolveSubmissionForManager($request->user(), $id, $assignment, $submission);

        if (! $record->hasFile()) {
            abort(404);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $record->file_path || ! $disk->exists($record->file_path)) {
            abort(404);
        }

        return $disk->download($record->file_path, $record->file_name ?: 'submission');
    }
    /**
     * Open ONE piece of a student's evidence, for marking.
     *
     * The attempt is resolved through the manager path first, which proves a
     * current allocation on the Offering in the URL and that the submission
     * belongs to the assignment in that Offering. The item is then scoped to THAT
     * attempt, so an item id belonging to a different student, attempt,
     * assignment or tenant is a 404.
     *
     * This is the only way to reach the bytes: stored paths are generated and
     * live outside the web root.
     */
    public function evidenceFile(Request $request, int $id, int $assignment, int $submission, int $item)
    {
        $record = $this->grading->resolveSubmissionForManager($request->user(), $id, $assignment, $submission);

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
     * Render ONE piece of a student's evidence INSIDE the marking page.
     *
     * A photograph of working, an audio explanation, a video demonstration -
     * these have to be visible in the page for a marker to assess them, and
     * downloading and reopening each one would make question-by-question marking
     * unusable in practice.
     *
     * Authorised through the manager resolver, which proves a current allocation on
     * the Offering in the URL and that the attempt belongs to the assignment in that
     * Offering. A document is NOT streamable - it downloads through `evidence`,
     * because a PDF rendered in a page is a document execution surface and reading
     * one costs a lecturer nothing.
     */
    public function evidenceMedia(Request $request, int $id, int $assignment, int $submission, int $item)
    {
        $record = $this->grading->resolveSubmissionForManager($request->user(), $id, $assignment, $submission);

        $row = AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $record->id)
            ->whereKey($item)
            ->firstOrFail();

        return app(\App\Support\Assignments\EvidenceMedia::class)->inlineResponse($row);
    }

    /**
     * Record a mark and feedback. This does NOT show it to the student; the
     * separate `release` action does that.
     */
    public function grade(Request $request, int $id, int $assignment, int $submission): RedirectResponse
    {
        $this->access->resolveForManager($request->user(), $id, $assignment);
        $record = $this->grading->resolveSubmissionForManager($request->user(), $id, $assignment, $submission);

        $validated = $request->validate([
            'marks_awarded' => ['nullable', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->guarded(fn () => $this->grading->grade($request->user(), $record, $validated));

        return back()->with(
            'success',
            'Mark recorded. The student cannot see it until you return it.'
        );
    }

    /**
     * Return the mark and feedback to the student.
     */
    public function release(Request $request, int $id, int $assignment, int $submission): RedirectResponse
    {
        $this->access->resolveForManager($request->user(), $id, $assignment);
        $record = $this->grading->resolveSubmissionForManager($request->user(), $id, $assignment, $submission);

        $this->guarded(fn () => $this->grading->release($request->user(), $record));

        // Announced only after the release has actually been recorded, and
        // keyed on the attempt, so a second attempt that is graded again is a new
        // event rather than being suppressed.
        AssignmentNotifier::announceGraded($record->fresh());

        return back()->with('success', 'Returned to the student. They can now see the mark and your feedback.');
    }

    /**
     * Withdraw a released result so it can be reconsidered.
     */
    public function unrelease(Request $request, int $id, int $assignment, int $submission): RedirectResponse
    {
        $this->access->resolveForManager($request->user(), $id, $assignment);
        $record = $this->grading->resolveSubmissionForManager($request->user(), $id, $assignment, $submission);

        $this->guarded(fn () => $this->grading->unrelease($request->user(), $record));

        return back()->with('success', 'The result is hidden from the student again. The mark is kept.');
    }

    // ── resources ─────────────────────────────────────────────────────────

    public function storeResource(Request $request, int $id, int $assignment): RedirectResponse
    {
        $target = $this->access->resolveForManager($request->user(), $id, $assignment);

        $validated = $request->validate([
            'type' => ['required', 'in:link,file'],
            'title' => ['nullable', 'string', 'max:191'],
            'link_url' => ['nullable', 'required_if:type,link', 'url:http,https', 'max:500'],
            'file' => ['nullable', 'required_if:type,file', 'file', 'max:20480'],
        ]);

        if ($validated['type'] === 'file') {
            $this->guarded(fn () => $this->assignments->attachFile(
                $request->user(),
                $target,
                $request->file('file')
            ));
        } else {
            $this->guarded(fn () => $this->assignments->attachLink(
                $request->user(),
                $target,
                (string) ($validated['title'] ?? $validated['link_url']),
                (string) $validated['link_url']
            ));
        }

        return back()->with('success', 'Resource added to this assignment.');
    }

    public function destroyResource(Request $request, int $resource): RedirectResponse
    {
        $this->guarded(fn () => $this->assignments->deleteResource($request->user(), $resource));

        return back()->with('success', 'Resource removed.');
    }

    /**
     * Serve a lecturer's handout.
     *
     * Authorised on the same rule as the page that links it - a current
     * allocation on the Offering - so a copied resource id is worth nothing to
     * anybody else. Files live outside the web root, so this is the only way to
     * read one.
     */
    public function resourceFile(Request $request, int $resource)
    {
        $row = AssignmentResource::query()
            ->where('school_id', (int) $request->user()->school_id)
            ->whereKey($resource)
            ->firstOrFail();

        $this->access->resolveForManager(
            $request->user(),
            (int) $row->course_offering_id,
            (int) $row->assignment_id
        );

        if (! $row->isFile() || ! $row->stored_name) {
            abort(404);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $disk->exists($row->stored_name)) {
            abort(404);
        }

        return $disk->download($row->stored_name, $row->original_name ?: $row->title);
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(\App\Models\CourseOffering $offering, ?Assignment $existing): array
    {
        return [
            'title' => ['required', 'string', 'max:191'],
            // The body is rich text from the editor. No length ceiling: an
            // assignment is a document, and the sanitizer is the real boundary.
            'instructions' => ['nullable', 'string'],
            'learning_objectives' => ['nullable', 'string', 'max:5000'],
            'max_marks' => ['required', 'integer', 'min:0', 'max:100000'],
            // 'link' is deliberately not offered: PIIE has no external
            // submission integration, so advertising one would be a promise
            // PIIE cannot keep.
            'submission_type' => ['required', Rule::in(Assignment::HEI_SUBMISSION_TYPES)],
            'allowed_attempts' => ['nullable', 'integer', 'min:1', 'max:99'],
            'late_policy' => ['required', Rule::in(Assignment::LATE_POLICIES)],
            'due_date' => ['nullable', 'date'],
            'released_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(Assignment::STATUSES)],

            // ── Module/chapter task ──
            // Optional. Scoped to this Offering AND this tenant, so a module id
            // from elsewhere is refused at validation rather than written.
            'course_offering_module_id' => [
                'nullable',
                'integer',
                Rule::exists('course_offering_modules', 'id')
                    ->where('course_offering_id', $offering->id)
                    ->where('school_id', $offering->school_id),
            ],
            'requirement_role' => ['required', Rule::in(Assignment::REQUIREMENT_ROLES)],
            // ONLY the observable rules. The declared-but-unimplemented ones are
            // deliberately absent - see the class docblock.
            'completion_rule' => ['required', Rule::in(Assignment::IMPLEMENTED_COMPLETION_RULES)],
            'submission_kinds' => ['nullable', 'array'],
            'submission_kinds.*' => ['string', Rule::in(AssignmentSubmissionItem::CONFIGURABLE_KINDS)],
        ];
    }

    /**
     * Reduce a question form post to the fields the service owns.
     *
     * `response_kinds` arrives as a checkbox ARRAY and is passed through as one.
     * An empty selection is passed as null rather than as an empty array, because
     * "not configured" and "configured to accept nothing" must stay
     * distinguishable - the publication check reports them differently.
     *
     * @return array<string, mixed>
     */
    private function questionPayload(Request $request): array
    {
        $kinds = (array) $request->input('response_kinds', []);

        return [
            'heading' => $request->input('heading'),
            'prompt' => $request->input('prompt'),
            'marks' => $request->input('marks'),
            'is_required' => $request->input('is_required', true),
            'response_kinds' => $kinds === [] ? null : $kinds,
            'require_all' => $request->boolean('require_all'),
        ];
    }

    /**
     * Run a question-domain call, turning a rule a lecturer can fix into a form
     * error.
     *
     * Separate from `guarded()` only because it files the message under `questions`
     * rather than `status` - so a refusal about the QUESTIONS lands on the questions
     * panel, which is where the lecturer can act on it. The exception handling is
     * identical: a domain rule becomes a 422, and anything else still surfaces.
     *
     * @template T
     *
     * @param  callable():T  $operation
     * @return T
     */
    private function guardedQuestion(callable $operation)
    {
        try {
            return $operation();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['questions' => $exception->getMessage()]);
        }
    }

    /**
     * Reduce a validated payload to the fields the service owns.
     *
     * A create has no transition to check, so it is always created as a Draft and
     * any requested status other than draft is refused rather than silently
     * publishing a half-written assignment.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(Request $request, array $validated): array
    {
        $payload = [
            'title' => $validated['title'],
            'instructions' => $validated['instructions'] ?? null,
            'learning_objectives' => $validated['learning_objectives'] ?? null,
            'max_marks' => $validated['max_marks'],
            'submission_type' => $validated['submission_type'],
            'late_policy' => $validated['late_policy'],
            'allowed_attempts' => $validated['allowed_attempts'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'released_at' => $validated['released_at'] ?? null,
            'closes_at' => $validated['closes_at'] ?? null,

            // ── Module/chapter task ──
            'course_offering_module_id' => $validated['course_offering_module_id'] ?? null,
            'requirement_role' => $validated['requirement_role'] ?? Assignment::ROLE_OPTIONAL,
            'completion_rule' => $validated['completion_rule'] ?? Assignment::RULE_SUBMISSION,
            'submission_kinds' => $validated['submission_kinds'] ?? null,
        ];

        // A preview is not a save, so it must not be held to the publish rules -
        // otherwise a lecturer could not preview the empty first assignment they
        // are about to write.
        if ($request->boolean('preview_draft')) {
            return $payload;
        }

        $payload['status'] = (string) ($validated['status'] ?? Assignment::STATUS_DRAFT);

        return $payload;
    }

    /**
     * The modules of THIS Offering, for the module picker.
     *
     * Scoped to the Offering AND the tenant, in the order a lecturer reads them.
     * Archived modules are excluded: attaching a task to a retired module would
     * place it somewhere no student will ever navigate.
     *
     * A module with no task is a normal state. The list is of MODULES, not of
     * tasks, so one with no task simply appears with nothing chosen - which is
     * what keeps the association genuinely optional rather than something every
     * module is expected to have.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\CourseOfferingModule>
     */
    private function moduleOptions(\App\Models\CourseOffering $offering)
    {
        return \App\Models\CourseOfferingModule::query()
            ->where('school_id', (int) $offering->school_id)
            ->where('course_offering_id', (int) $offering->id)
            ->where('status', '!=', \App\Models\CourseOfferingModule::STATUS_ARCHIVED)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }
    /**
     * Run a domain call, turning a rule a lecturer can fix into a form error.
     *
     * Without this the exception reaches the handler and a lecturer who ticks the
     * wrong box gets a 500. Anything that is not a domain rule is still reported.
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
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }
    }

    private function transitionMessage(string $to, Assignment $assignment): string
    {
        return match ($to) {
            Assignment::STATUS_PUBLISHED => 'Published. Confirmed students can see it now.',
            Assignment::STATUS_SCHEDULED => 'Scheduled. Students will see it from '
                .($assignment->released_at?->format('j M Y, H:i') ?? 'the release time').'.',
            Assignment::STATUS_CLOSED => 'Closed. No new submissions are accepted, and every existing submission is kept.',
            Assignment::STATUS_DRAFT => 'Returned to Draft. It is hidden from students again.',
            default => 'Assignment updated.',
        };
    }

    private function courseUnitLabel(\App\Models\CourseOffering $offering): string
    {
        $terminology = app(TenantConfiguration::class)->terminology(
            \App\Models\School::query()->find($offering->school_id)
        );

        return $terminology['course_unit'] ?? 'Course Unit';
    }
}
