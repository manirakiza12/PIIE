<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Models\OnlineExam;
use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;
use App\Support\CourseExams\CourseOfferingExamAccess;
use App\Http\Requests\OnlineExam\StoreOnlineExamRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * QUIZZES & EXAMS, INSIDE A COURSE OFFERING.
 *
 * ── WHAT THIS IS: A CONTEXT BINDER, NOT AN ENGINE ──────────────────────────
 *
 * Everything a lecturer does with an assessment after it exists - authoring
 * questions, pulling from the Question Bank, setting marks, shuffling, the
 * proctoring controls, preview, the manual marking queue, submitting marking for
 * review, publication and result release - is the EXISTING Online Exam engine, at
 * its EXISTING `teacher.online_exams.*` routes. This controller creates an exam
 * bound to a Course Offering and then hands the lecturer to those pages.
 *
 * So there is one marking queue, one publication gate, one result policy and one
 * set of proctoring rules in PIIE, and a Course Offering assessment is an ordinary
 * exam that additionally knows which course it belongs to. Building a parallel
 * engine here would have produced a second, subtly different set of all of them.
 *
 * ── WHAT IS DELIBERATELY NOT INHERITED FROM THE LEGACY FORM ────────────────
 *
 * The legacy `teacher.online_exams.create` form asks the lecturer to pick a
 * Class, a Programme and an academic Session. This one does not, and cannot:
 *
 *   - `class_id` stays NULL. Populating it would not be a convenience, it would
 *     silently enrol every student of that class into the assessment, bypassing
 *     the confirmed Course Registration that is the only legitimate
 *     higher-education eligibility test. HEI delivery is never faked through the
 *     legacy Class/Section graph.
 *   - `subject_id` is DERIVED from the Offering, not chosen. A lecturer may only
 *     reach an Offering they are allocated to, and that Offering has one Course
 *     Unit; offering a dropdown of every subject in the school would let them
 *     create an "assessment for this course" that is actually about something else.
 *   - `academic_year_id` and `academic_period_id` are not columns on the exam at
 *     all. They are read through the Offering, so a corrected date in the academic
 *     office corrects the exam too, instead of leaving two disagreeing copies.
 *
 * ── THE VALIDATION IS THE ENGINE'S ─────────────────────────────────────────
 *
 * `store()` takes the engine's own `StoreOnlineExamRequest`, so the window-fits-
 * the-duration rule, the pass-mark ceiling, the subject/tenant existence check and
 * the "a lecturer may not self-publish" governance are all the engine's, not
 * restated. `authorize('create', OnlineExam::class)` is the engine's policy too.
 */
class TeacherCourseOfferingExamController extends Controller
{
    public function __construct(
        private readonly CourseOfferingExamAccess $access,
    ) {}

    /**
     * GET /teacher/course-offerings/{id}/exams
     *
     * The Offering's assessments, with the engine's own readiness check beside
     * each one so a lecturer can see why a paper will not publish.
     */
    public function index(Request $request, $id): View
    {
        $offering = $this->access->resolveOfferingForManager($request->user(), (int) $id);

        $exams = OnlineExam::query()
            ->forOffering($offering, $offering->school_id)
            ->withCount('questions')
            ->withCount('submissions')
            ->orderByDesc('id')
            ->get();

        $rows = [];

        foreach ($exams as $exam) {
            $rows[] = [
                'exam' => $exam,
                'lifecycle_label' => $exam->lifecycleLabel(),
                'type_label' => $exam->typeLabel(),
                'window' => $exam->windowSummary(),
                'question_count' => (int) $exam->questions_count,
                'submissions' => (int) $exam->submissions_count,
                'awaiting_manual' => $this->countAwaitingManual($exam),
                'results_published' => $this->countResultsPublished($exam),
                // The engine's own gate, not a second readiness rule. A lecturer
                // sees exactly the list that decides whether publish will succeed.
                'readiness_errors' => $exam->publicationReadinessErrors(),
                'questions_locked' => $exam->questionsAreLocked(),
            ];
        }

        return view('teacher.course_offerings.exams.index', [
            'offering' => $offering,
            'rows' => $rows,
            'canCreate' => $this->canCreate($request->user()),
        ]);
    }

    /**
     * GET /teacher/course-offerings/{id}/exams/create
     *
     * A full page, never a drawer: an assessment is a document, and it carries
     * marks, a window and a governance state.
     */
    public function create(Request $request, $id): View
    {
        $offering = $this->access->resolveOfferingForManager($request->user(), (int) $id);

        $this->assertCanCreate($request->user());

        return view('teacher.course_offerings.exams.create', [
            'offering' => $offering,
        ]);
    }

    /**
     * POST /teacher/course-offerings/{id}/exams
     *
     * The only write this controller performs. Everything after this is the
     * engine's own screen.
     */
    public function store(StoreOnlineExamRequest $request, $id)
    {
        $offering = $this->access->resolveOfferingForManager($request->user(), (int) $id);

        $this->assertCanCreate($request->user());

        $validated = $request->validated();

        // The engine refuses to create an already-published paper: questions have
        // to exist and be validated first. Preserved rather than relaxed, because
        // "publish" here is a governance state with an Admin review behind it.
        if (($validated['workflow_state'] ?? 'draft') === 'published') {
            throw new HttpException(422, 'Add and validate questions before publishing an assessment.');
        }

        $payload = [
            'title' => $validated['title'],
            // Sanitised by the model's mutator, so the admin form and this form
            // cannot store different kinds of content in the same column.
            'instructions' => $validated['instructions'] ?? null,
            'exam_type' => $validated['exam_type'],
            'start_datetime' => $validated['start_datetime'] ?? null,
            'end_datetime' => $validated['end_datetime'] ?? null,
            'duration_mins' => (int) ($validated['duration_mins'] ?? 0),
            'total_marks' => (int) $validated['total_marks'],
            'pass_mark' => (int) $validated['pass_mark'],
            'max_attempts' => (int) ($validated['max_attempts'] ?? 1),
            'shuffle_questions' => (bool) ($validated['shuffle_questions'] ?? false),
            'shuffle_options' => (bool) ($validated['shuffle_options'] ?? false),
            'allow_previous_navigation' => (bool) ($validated['allow_previous_navigation'] ?? true),
            'result_release_policy' => $validated['result_release_policy'] ?? 'immediate',
            'webcam_required' => (bool) ($validated['webcam_required'] ?? false),
            'fullscreen_required' => (bool) ($validated['fullscreen_required'] ?? false),
            'auto_submit' => (bool) ($validated['auto_submit'] ?? true),
            'workflow_state' => $validated['workflow_state'] ?? 'draft',
            'is_published' => ($validated['workflow_state'] ?? 'draft') === 'published',
            'created_by' => $request->user()->id,
            'creator_id' => $request->user()->id,
            'updater_id' => $request->user()->id,
        ];

        // The authoritative academic fields, from the same method the generic create
        // page uses. Stated once so the two entrances to a Course Offering exam cannot
        // disagree about the Course Unit, the tenant, or the deliberate NULLs. Nothing
        // below reads them from `$validated`.
        $payload = array_merge($payload, $this->access->authoritativeFieldsFor($offering));

        // The engine's own defensive style: a deployment that has not yet run the
        // Course Offering migration still has to be able to create a legacy exam.
        if (! Schema::hasColumn('online_exams', 'course_offering_id')) {
            unset($payload['course_offering_id']);
        }

        $exam = DB::transaction(fn () => OnlineExam::create($payload));

        // Straight to the ENGINE's question page, not to a Course Offering clone of
        // it. A lecturer authors questions in exactly one place in PIIE.
        return redirect()
            ->route('teacher.online_exams.questions.index', $exam->id)
            ->with('success', 'Assessment created. Add its questions next - it cannot be published until it has them.');
    }

    /**
     * The engine's own create gate, asked before the Offering screens are drawn.
     *
     * `StoreOnlineExamRequest::authorize()` already refuses a student and a user
     * without `create_online_exams`; this is the same question asked early enough
     * that a lecturer who cannot create an assessment is not shown a form whose
     * submission is guaranteed to fail.
     */
    private function canCreate($actor): bool
    {
        return (int) $actor->role_id !== 7
            && app(\App\Support\Permissions\OnlineExamPermissionService::class)->has($actor, 'create_online_exams');
    }

    private function assertCanCreate($actor): void
    {
        if (! $this->canCreate($actor)) {
            throw new HttpException(403, 'You are not authorized to create online assessments.');
        }
    }

    /**
     * Submissions still waiting for a lecturer to read and award marks by hand.
     *
     * The engine's own status vocabulary, not a new one. `is_finalized()` and
     * `isResultPublished()` are its predicates.
     */
    private function countAwaitingManual(OnlineExam $exam): int
    {
        if (! Schema::hasTable('online_exam_submissions')) {
            return 0;
        }

        return OnlineExamSubmission::query()
            ->where('online_exam_id', $exam->id)
            ->whereIn('status', [
                OnlineExamSubmission::STATUS_SUBMITTED,
                OnlineExamSubmission::STATUS_PENDING_MANUAL,
            ])
            ->count();
    }

    private function countResultsPublished(OnlineExam $exam): int
    {
        if (! Schema::hasTable('online_exam_submissions')) {
            return 0;
        }

        return OnlineExamSubmission::query()
            ->where('online_exam_id', $exam->id)
            ->where('status', OnlineExamSubmission::STATUS_RESULT_PUBLISHED)
            ->count();
    }
}
