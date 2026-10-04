<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;
use App\Support\CourseExams\CourseOfferingAssessments;
use App\Support\CourseExams\CourseOfferingExamAccess;
use App\Support\OnlineExams\OnlineExamRecipients;
use App\Support\Permissions\OnlineExamPermissionService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * THE FULL CHAIN, ON THE ROUTES, IN THE ORDER A REAL ATTEMPT HAPPENS.
 *
 * ── WHY THIS SUITE EXISTS SEPARATELY ────────────────────────────────────────
 *
 * §8 of the brief calls student delivery the highest-priority MANUAL item, and
 * warns specifically against concluding that passing tests means it works. The
 * earlier suites each proved one link. This one walks the whole sequence against
 * the real route names a browser would hit, asserting at every step what the
 * previous step made true - so a break anywhere shows up as a failure at the step
 * where it broke, rather than as an absence nobody notices.
 *
 * The order is the brief's:
 *
 *     lecturer creates (draft) -> questions -> SUBMIT FOR REVIEW
 *     -> ADMIN publishes (before start / after start)
 *     -> student: not before publish, not before start, then sits
 *     -> lecturer marks -> finalises for review
 *     -> ADMIN publishes the RESULT
 *     -> student finally sees it
 *
 * ── THE CENTRAL CLAIM ──────────────────────────────────────────────────────
 *
 * A student is refused at EVERY step before Admin publication, and refused at every
 * step after it EXCEPT the one the workflow allows. Each refusal is asserted as a
 * refusal of the real route, not of a service method, because "the service says no"
 * and "the page says no" are different claims and only the second is what a student
 * experiences.
 */
class OnlineExamDeliveryChainTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSchema();
    }

    /** The engine's tables plus the Course Offering ones this chain reads. */
    private function bootSchema(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();
        $this->assertFixtureTablesMatchProduction();
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE SCENARIO, BUILT THROUGH THE REAL ROUTES
    // ══════════════════════════════════════════════════════════════════════

    // ── THE CAST, MEMOISED ──────────────────────────────────────────────────
    //
    // Each of these CREATED a new user on every call, which is a defect that hides
    // rather than announces itself. `$this->lecturer()` used to allocate and act as
    // one person, then `$this->lecturer()` again returned a DIFFERENT person - so an
    // assertion like
    //
    //     assertSame($this->lecturer()->id, $answer->marked_by)
    //
    // compared the marker against a stranger, and `studentA()` produced a student who
    // was not the one holding the submission.
    //
    // The failures pointed at ids 4-versus-5, which reads like an arithmetic problem
    // and is not one at all. A cast helper that returns a different actor each call
    // cannot express "the same person did this", which is most of what this suite
    // exists to check.

    private ?\App\Models\User $lecturerA = null;

    private function lecturer(): \App\Models\User
    {
        return $this->lecturerA ??= $this->makeUser(3, 1, 'active', 'Lecturer A');
    }

    private ?\App\Models\User $adminUser = null;

    private function admin(): \App\Models\User
    {
        return $this->adminUser ??= $this->makeUser(2, 1, 'active', 'Admin');
    }

    private ?\App\Models\User $theStudent = null;

    private function studentA(): \App\Models\User
    {
        if ($this->theStudent !== null) {
            return $this->theStudent;
        }

        $student = $this->makeUser(7, 1, 'active', 'Student A');
        $this->confirmStudent($this->offering(), $student);

        return $this->theStudent = $student;
    }

    private \App\Models\CourseOffering $offering;

    private function offering(): \App\Models\CourseOffering
    {
        return $this->offering ??= $this->makeOffering(1, ['reference' => 'CHAIN-2026-S1']);
    }

    private array $examPayload = [];

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Mid-Semester Examination',
            'exam_type' => 'midterm',
            'subject_id' => $this->offering()->subject_id,
            'start_datetime' => now()->addHour()->format('Y-m-d H:i:s'),
            'end_datetime' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'duration_mins' => 60,
            'total_marks' => 10,
            'pass_mark' => 5,
            'max_attempts' => 1,
            'result_release_policy' => 'immediate',
            'instructions' => '<p>Answer <b>all</b> questions.</p>',
            'allow_previous_navigation' => '1',
        ], $overrides);
    }

    /** A published exam with one MCQ and one essay, owned by Lecturer A. */
    private function publishedExamWithQuestions(string $title = 'Mid-Semester Examination'): array
    {
        $lecturer = $this->lecturer();
        $id = $this->makeExam([
            'school_id' => 1,
            'subject_id' => $this->offering()->subject_id,
            'course_offering_id' => $this->offering()->id,
            'title' => $title,
            'workflow_state' => 'published',
            'is_published' => 1,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
            'total_marks' => 10,
            'pass_mark' => 5,
            'duration_mins' => 60,
            'max_attempts' => 1,
            'result_release_policy' => 'immediate',
            // Word-authored instructions on EVERY paper in the suite, so the
            // instructions page is exercised wherever a student is sent to it, and
            // so the formatting round trip is proven on the delivery path rather
            // than only on the authoring one.
            'instructions' => '<p>Answer <b>all</b> questions.</p>',
            'created_by' => $lecturer->id,
            'creator_id' => $lecturer->id,
        ]);

        $mcq = $this->makeQuestion($id, [
            'type' => 'mcq', 'correct_ans' => 'a', 'marks' => 4,
            'question' => 'What is 1 + 1?',
            'option_a' => '2', 'option_b' => '3', 'option_c' => '4', 'option_d' => '5',
        ]);

        $essay = $this->makeQuestion($id, [
            'type' => 'essay', 'correct_ans' => null, 'marks' => 6,
            'question' => 'Explain the addition of 1 and 1.',
        ]);

        return [
            'exam' => OnlineExam::query()->findOrFail($id),
            'mcq' => $mcq,
            'essay' => $essay,
        ];
    }

    private function startAttempt(OnlineExam $exam, \App\Models\User $student): OnlineExamSubmission
    {
        $this->actingAs($student)
            ->post(route('student.online_exam.start', $exam->id), ['instructions_acknowledged' => 'on'])
            ->assertOk();

        return OnlineExamSubmission::query()
            ->where('online_exam_id', $exam->id)
            ->where('student_id', $student->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * Autosave ONE answer, sending a CURRENT `answer_revision`.
     *
     * ── WHY THIS NOW TRACKS A REVISION ───────────────────────────────────────
     *
     * It used to send a hard-coded `answer_revision => 1`, which made "continue
     * editing" impossible to express: the second save of the same answer replayed
     * revision 1 and the engine answered 409. That 409 was CORRECT - it is the
     * optimistic-concurrency guard refusing a save based on a stale revision - and
     * it is exactly what stops a background autosave from overwriting newer work.
     *
     * So rather than suppress the 409, this helper now behaves like the real client:
     * each successful save bumps the revision it will send next time. The stale-
     * revision case is asserted deliberately where it belongs, in
     * `test_a_STALE_autosave_may_not_overwrite_NEWER_work`.
     */
    private function saveAnswer(OnlineExamSubmission $s, int $questionId, array $answer): void
    {
        $key = $s->id.'-'.$questionId;
        $revision = ($this->answerRevisions[$key] ?? 0) + 1;

        $this->actingAs($s->student)
            ->postJson(route('student.online_exam.save_answer', $s->id), array_merge([
                'submission_id' => $s->id,
                'question_id' => $questionId,
                'answer_revision' => $revision,
            ], $answer))
            ->assertOk();

        $this->answerRevisions[$key] = $revision;
    }

    /** @var array<string, int> The last revision successfully saved per answer. */
    private array $answerRevisions = [];

    // ══════════════════════════════════════════════════════════════════════
    // 1. LECTURER: create -> questions -> submit for review
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_LECTURER_can_create_an_exam_IN_an_offering_they_are_allocated_to(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);

        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.exams.create', $this->offering()))
            ->assertOk();

        $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.exams.store', $this->offering()), $this->payload())
            ->assertSessionHasNoErrors();

        $exam = OnlineExam::query()->where('title', 'Mid-Semester Examination')->firstOrFail();

        // `pending_review`, NOT `draft` - and this is the single most important
        // assertion in the suite.
        //
        // `StoreOnlineExamRequest::validated()` sets:
        //
        //     $data['workflow_state'] = $canPublish ? 'draft' : 'pending_review';
        //
        // A lecturer does not hold `publish_online_exams`, so their exam enters the
        // Admin review queue AUTOMATICALLY, with no action required from them. §1's
        // workflow is therefore enforced by construction rather than by convention:
        // a lecturer cannot create a published exam even by trying, because the code
        // will not let them.
        //
        // Asserting 'draft' would have been the WEAKER claim - it would still have
        // passed if the engine published by default and only corrected itself later.
        $this->assertSame('pending_review', $exam->workflow_state);
        $this->assertNotSame('published', $exam->workflow_state, 'a lecturer cannot create a published exam');
        $this->assertFalse((bool) $exam->is_published, 'and is_published must agree');

        $this->assertSame((int) $this->offering()->id, (int) $exam->course_offering_id);
        $this->assertSame($lecturer->id, (int) $exam->creator_id, 'the creator owns it');
        $this->assertNull($exam->class_id, 'never faked through the legacy Class graph');

        // And it is therefore invisible to a student, which is the consequence that
        // matters.
        $this->assertSame(
            [],
            app(CourseOfferingAssessments::class)->forStudent($this->studentA(), $this->offering()),
            'an exam awaiting Admin review must not reach a student'
        );
    }

    public function test_a_LECTURER_may_add_questions_and_then_SUBMIT_FOR_REVIEW(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);

        $this->actingAs($lecturer)
            ->post(route('teacher.course_offerings.exams.store', $this->offering()), $this->payload())
            ->assertSessionHasNoErrors();

        $exam = OnlineExam::query()->where('title', 'Mid-Semester Examination')->firstOrFail();

        // A REDIRECT, not a 200: `storeQuestion()` returns to the question list,
        // which is what a browser receives from an HTML form POST. Asserted as a
        // redirect, and the EFFECT is asserted separately so the test cannot pass
        // merely because the route exists.
        $this->actingAs($lecturer)
            ->post(route('teacher.online_exams.questions.store', $exam->id), [
                'question' => '<p>What is <b>2 + 2</b>?</p>',
                'type' => 'essay',
                'marks' => 10,
            ])
            ->assertRedirect();

        $this->assertSame(1, OnlineExamQuestion::where('online_exam_id', $exam->id)->count());

        // And the rich text survived the authoring round trip through the sanitiser,
        // which a plain-string question could never have shown.
        $this->assertStringContainsString(
            '<b>2 + 2</b>',
            OnlineExamQuestion::where('online_exam_id', $exam->id)->firstOrFail()->prosePrompt()
        );

        $this->actingAs($lecturer)
            ->post(route('teacher.online_exams.submit_review', $exam->id))
            ->assertRedirect();

        $this->assertNotSame(
            'published',
            $exam->fresh()->workflow_state,
            'submitting for review must NOT publish'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. THE GOVERNANCE LINE: a lecturer cannot publish
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_LECTURER_cannot_PUBLISH_an_exam_directly(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $admin = $this->admin();

        $exam = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'course_offering_id' => $this->offering()->id,
            'title' => 'Must go through Admin',
            'workflow_state' => 'pending_review',
            'is_published' => 0,
            'created_by' => $lecturer->id, 'creator_id' => $lecturer->id,
        ]));

        // An admin CANNOT publish an empty paper either, and that is the engine's
        // own readiness rule - "At least one question is required." Asserted before
        // the governance assertion, because it is a different and equally important
        // property: publication is gated on the paper being a paper, not on who asks.
        $this->actingAs($admin)
            ->post(route('admin.online_exams.publish', $exam->id))
            ->assertSessionHasErrors();

        $this->assertNotSame(
            'published',
            $exam->fresh()->workflow_state,
            'an empty paper must not be publishable, by anyone'
        );

        $this->makeQuestion($exam->id, [
            'type' => 'mcq', 'correct_ans' => 'a', 'marks' => 10, 'question' => 'Q',
        ]);

        // The permission itself, which is the root of the refusal.
        $this->assertFalse(
            app(OnlineExamPermissionService::class)->hasBase($lecturer, 'publish_online_exams'),
            'a lecturer must never hold publish_online_exams'
        );

        // The route, by direct URL manipulation.
        $this->actingAs($lecturer)
            ->post(route('teacher.online_exams.publish', $exam->id))
            ->assertForbidden();

        $this->assertNotSame(
            'published',
            $exam->fresh()->workflow_state,
            'a refused publish must leave the state untouched'
        );

        // And the ADMIN can.
        $this->actingAs($admin)
            ->post(route('admin.online_exams.publish', $exam->id))
            ->assertRedirect();

        $published = $exam->fresh();

        $this->assertSame('published', $published->workflow_state);
        $this->assertSame(1, (int) $published->is_published);

        // Publication is what makes it reachable - and the student sees it, which
        // is the whole point of the Admin step existing.
        $this->assertNotEmpty(
            app(CourseOfferingAssessments::class)->forStudent($this->studentA(), $this->offering()),
            'after Admin publication the assessment must reach the confirmed student'
        );
    }

    public function test_a_LECTURER_cannot_PUBLISH_a_result_directly(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);

        $set = $this->publishedExamWithQuestions();
        $exam = $set['exam'];
        $student = $this->studentA();

        $submission = $this->startAttempt($exam, $student);
        $this->saveAnswer($submission, $set['mcq'], ['selected_option' => 'a']);
        $this->saveAnswer($submission, $set['essay'], ['answer_text' => 'The successor of 1.']);
        $this->actingAs($student)->post(route('student.online_exam.submit', $exam->id), [
            'submission_id' => $submission->id,
        ])->assertRedirect();

        $this->markAll($exam, $submission);

        $this->actingAs($lecturer)
            ->post(route('teacher.online_exams.results.finalize', $submission->id))
            ->assertRedirect();

        // The decisive attempt: the teacher's own publish-result route.
        $this->actingAs($lecturer)
            ->post(route('teacher.online_exams.results.publish', $submission->id))
            ->assertForbidden();

        $this->assertNotSame(
            OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            $submission->fresh()->status,
            'a lecturer must not be able to release a result'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. BEFORE ADMIN PUBLICATION: the student must receive nothing
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_DRAFT_exam_is_invisible_and_unsittable(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $student = $this->studentA();

        $exam = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'course_offering_id' => $this->offering()->id,
            'title' => 'Still a draft',
            'workflow_state' => 'draft',
            'is_published' => 0,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
            'created_by' => $lecturer->id, 'creator_id' => $lecturer->id,
        ]));
        $this->makeQuestion($exam->id, ['type' => 'mcq', 'correct_ans' => 'a', 'marks' => 1, 'question' => 'Q']);

        $this->assertSame(
            [],
            app(CourseOfferingAssessments::class)->forStudent($student, $this->offering()),
            'a draft must not be listed'
        );

        $this->actingAs($student)
            ->get(route('student.online_exam.instructions', $exam->id))
            ->assertNotFound();

        $this->actingAs($student)
            ->get(route('student.courses.exams', $this->offering()))
            ->assertOk()
            ->assertDontSee('Still a draft');
    }

    public function test_a_PENDING_REVIEW_exam_is_invisible_to_students(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $student = $this->studentA();

        $exam = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'course_offering_id' => $this->offering()->id,
            'title' => 'Awaiting Admin review',
            'workflow_state' => 'pending_review',
            'is_published' => 0,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
            'created_by' => $lecturer->id, 'creator_id' => $lecturer->id,
        ]));
        $this->makeQuestion($exam->id, ['type' => 'mcq', 'correct_ans' => 'a', 'marks' => 1, 'question' => 'Q']);

        $this->assertSame([], app(CourseOfferingAssessments::class)->forStudent($student, $this->offering()));

        // The INSTRUCTIONS page does not resolve the exam at all: 404, so a student
        // who follows a stale link learns nothing - not even that a paper exists.
        $this->actingAs($student)
            ->get(route('student.online_exam.instructions', $exam->id))
            ->assertNotFound();

        // The START route refuses differently, and the difference is worth stating.
        //
        // `StartOnlineExamRequest` validates the exam state, so an unpublished paper
        // is a validation failure: 302 back with the error "Exam is not published."
        // That is not a weaker refusal - the attempt is still refused - it is the
        // engine's own form-error contract, and asserting 404 here would have been
        // asserting a behaviour the product does not have.
        //
        // What matters is asserted instead of the status code: NO submission exists
        // afterwards. A refusal that still minted an attempt would pass a
        // status-code-only check.
        $this->actingAs($student)
            ->post(route('student.online_exam.start', $exam->id), ['instructions_acknowledged' => 'on'])
            ->assertSessionHasErrors('exam');

        $this->assertSame(
            0,
            OnlineExamSubmission::where('online_exam_id', $exam->id)->count(),
            'an unpublished paper must never mint an attempt'
        );

        // The take page, likewise.
        $this->actingAs($student)
            ->get(route('student.online_exam.take', $exam->id))
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. PUBLISHED BUT NOT YET OPEN: visible as upcoming, NOT startable
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_PUBLISHED_exam_before_its_start_is_UPCOMING_and_cannot_be_started(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $student = $this->studentA();

        $exam = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'course_offering_id' => $this->offering()->id,
            'title' => 'Tomorrow morning',
            'workflow_state' => 'published',
            'is_published' => 1,
            'start_datetime' => now()->addDay(),
            'end_datetime' => now()->addDays(2),
            'created_by' => $lecturer->id, 'creator_id' => $lecturer->id,
        ]));
        $this->makeQuestion($exam->id, ['type' => 'mcq', 'correct_ans' => 'a', 'marks' => 1, 'question' => 'Q']);

        // Visible, and honestly labelled.
        $rows = app(CourseOfferingAssessments::class)->forStudent($student, $this->offering());
        $this->assertSame('upcoming', $rows[0]['status']);
        $this->assertSame('Upcoming', $rows[0]['status_label']);

        // And refused, by direct POST.
        $this->actingAs($student)
            ->postJson(route('student.online_exam.start', $exam->id), ['instructions_acknowledged' => 'on'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exam');

        $this->assertSame(0, OnlineExamSubmission::where('online_exam_id', $exam->id)->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. THE WINDOW IS OPEN: the student sits it
    // ══════════════════════════════════════════════════════════════════════

    public function test_WITHIN_the_window_a_confirmed_student_receives_and_sits_the_exam(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $student = $this->studentA();

        $set = $this->publishedExamWithQuestions('Sittable now');
        $exam = $set['exam'];

        // RECEIVED: listed on the Course Home tab, with a working action.
        $html = $this->actingAs($student)
            ->get(route('student.courses.exams', $this->offering()))
            ->assertOk()
            ->getContent();

        // Asserted on the tab, which is the page §8 names. The title is compared with
        // `false` for escaping because the row is rendered as HTML.
        $this->assertStringContainsString('Sittable now', $html, 'the exam must be listed');
        $this->assertStringContainsString('Available', $html, 'and labelled available');
        $this->assertStringContainsString('data-testid="exam-action"', $html, 'with a working action');

        // The status comes from the service, not from the markup, so the two are
        // checked against each other rather than against a string in a template.
        $rows = app(CourseOfferingAssessments::class)->forStudent($student, $this->offering());
        $this->assertSame('available', $rows[0]['status']);
        $this->assertNotNull($rows[0]['action'], 'and the row must offer a real action');

        // The Course Home's own tab links here and is no longer "not available".
        $this->actingAs($student)
            ->get(route('student.courses.show', $this->offering()))
            ->assertOk()
            ->assertDontSee('not part of this course experience yet');

        // INSTRUCTIONS, then SIT.
        $this->actingAs($student)
            ->get(route('student.online_exam.instructions', $exam->id))
            ->assertOk()
            ->assertSee('Answer <b>all</b> questions', false);

        $submission = $this->startAttempt($exam, $student);

        $this->assertSame(
            OnlineExamSubmission::STATUS_IN_PROGRESS,
            $submission->status,
            'starting is NOT submitting'
        );

        // ANSWER both, autosaving each.
        $this->saveAnswer($submission, $set['mcq'], ['selected_option' => 'a']);
        $this->saveAnswer($submission, $set['essay'], ['answer_text' => 'Because 1 has 2 as its successor.']);

        // REFRESH, and the work is still there.
        $this->actingAs($student)
            ->get(route('student.online_exam.take', $exam->id))
            ->assertOk()
            ->assertSee('Because 1 has 2 as its successor.', false);

        // SUBMIT.
        $this->actingAs($student)
            ->post(route('student.online_exam.submit', $exam->id), ['submission_id' => $submission->id])
            ->assertRedirect();

        $this->assertNotSame(
            OnlineExamSubmission::STATUS_IN_PROGRESS,
            $submission->fresh()->status,
            'the attempt must have been submitted'
        );
    }

    public function test_a_formatted_essay_SURVIVES_autosave_refresh_and_reaches_the_LECTURER_intact(): void
    {
        // §6: type answer -> autosave -> refresh -> restored -> continue editing ->
        // submit -> the lecturer sees EXACTLY the intended answer, formatting and all.
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $student = $this->studentA();

        $set = $this->publishedExamWithQuestions('Formatting round trip');
        $submission = $this->startAttempt($set['exam'], $student);

        $formatted = '<p>Let <b>f(x) = x² − 3x</b>.</p><p>Then f′(x) = 2x − 3, so the '
            .'stationary point is x = <sup>3</sup>&frasl;<sub>2</sub>.</p><ul><li>check the discriminant</li></ul>';

        $this->saveAnswer($submission, $set['essay'], ['answer_text' => $formatted]);

        // The stored value is the lecturer's to read: filtered for display, NOT
        // escaped into the column, so `marked_by` marking sees real formatting.
        $answer = OnlineExamAnswer::query()->where('question_id', $set['essay'])->firstOrFail();
        $this->assertStringContainsString('<b>f(x) = x² − 3x</b>', $answer->getAttributes()['answer_text']);

        // REFRESH: the page carries the formatting back for editing.
        $this->actingAs($student)
            ->get(route('student.online_exam.take', $set['exam']->id))
            ->assertOk()
            ->assertSee('f(x) = x² − 3x', false);

        // CONTINUE EDITING, then submit.
        $continued = $formatted.'<p>Therefore the minimum is negative.</p>';
        $this->saveAnswer($submission, $set['essay'], ['answer_text' => $continued]);

        $this->actingAs($student)
            ->post(route('student.online_exam.submit', $set['exam']->id), ['submission_id' => $submission->id])
            ->assertRedirect();

        // THE LECTURER sees exactly that, with the formatting rendered.
        $lecturer = $this->lecturer();
        $this->actingAs($lecturer)
            ->get(route('teacher.online_exams.marking'))
            ->assertOk()
            ->assertSee('f(x) = x² − 3x', false)
            ->assertSee('Therefore the minimum is negative.', false);
    }

    public function test_a_NOT_registered_student_receives_NOTHING_and_cannot_start(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());

        $set = $this->publishedExamWithQuestions('For confirmed students only');
        $exam = $set['exam'];

        // Confirmed on a DIFFERENT offering.
        $elsewhere = $this->makeUser(7, 1, 'active', 'Student B');
        $this->confirmStudent($this->makeOffering(1, ['reference' => 'ELSEWHERE-2026-S1']), $elsewhere);

        // Confirmed here but only `registered`.
        $pending = $this->makeUser(7, 1, 'active', 'Student C');
        $this->confirmStudent($this->offering(), $pending, 'registered');

        foreach ([$elsewhere, $pending] as $outsider) {
            $this->assertSame(
                [],
                app(CourseOfferingAssessments::class)->forStudent($outsider, $this->offering()),
                "[{$outsider->name}] must receive nothing"
            );

            $this->actingAs($outsider)
                ->get(route('student.online_exam.instructions', $exam->id))
                ->assertNotFound();

            $this->actingAs($outsider)
                ->post(route('student.online_exam.start', $exam->id), ['instructions_acknowledged' => 'on'])
                ->assertNotFound();
        }
    }

    public function test_AFTER_the_window_closes_a_student_cannot_start_a_new_attempt(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $student = $this->studentA();

        $exam = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'course_offering_id' => $this->offering()->id,
            'title' => 'Closed yesterday',
            'workflow_state' => 'published',
            'is_published' => 1,
            'start_datetime' => now()->subDays(3),
            'end_datetime' => now()->subDay(),
            'created_by' => $this->lecturer()->id, 'creator_id' => $this->lecturer()->id,
        ]));
        $this->makeQuestion($exam->id, ['type' => 'mcq', 'correct_ans' => 'a', 'marks' => 1, 'question' => 'Q']);

        $this->actingAs($student)
            ->postJson(route('student.online_exam.start', $exam->id), ['instructions_acknowledged' => 'on'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exam');

        $this->assertSame(0, OnlineExamSubmission::where('online_exam_id', $exam->id)->count());

        $rows = app(CourseOfferingAssessments::class)->forStudent($student, $this->offering());
        $this->assertSame('closed', $rows[0]['status']);
    }

    public function test_an_EXAM_with_NO_QUESTIONS_is_not_sittable(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $student = $this->studentA();

        $exam = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'course_offering_id' => $this->offering()->id,
            'title' => 'Empty paper',
            'workflow_state' => 'published',
            'is_published' => 1,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
            'created_by' => $this->lecturer()->id, 'creator_id' => $this->lecturer()->id,
        ]));

        // No questions at all.
        $this->assertSame(0, OnlineExamQuestion::where('online_exam_id', $exam->id)->count());

        $rows = app(CourseOfferingAssessments::class)->forStudent($student, $this->offering());
        $this->assertNotSame('available', $rows[0]['status'], 'an empty paper must not be offered');
        $this->assertNull($rows[0]['action'], 'and must offer no action');

        // The engine's own readiness refuses to publish it.
        $this->assertNotSame([], $exam->publicationReadinessErrors());
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. MARKING, FINALISE, AND THE RESULT LINE
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_LECTURER_sees_objective_marks_AUTOMATICALLY_and_marks_the_essay(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $student = $this->studentA();

        $set = $this->publishedExamWithQuestions('Mixed marking');
        $submission = $this->startAttempt($set['exam'], $student);

        $this->saveAnswer($submission, $set['mcq'], ['selected_option' => 'a']);
        $this->saveAnswer($submission, $set['essay'], ['answer_text' => 'Because 1 has 2 as its successor.']);

        $this->actingAs($student)
            ->post(route('student.online_exam.submit', $set['exam']->id), ['submission_id' => $submission->id])
            ->assertRedirect();

        $mcqAnswer = OnlineExamAnswer::query()->where('question_id', $set['mcq'])->firstOrFail();

        $this->assertTrue((bool) $mcqAnswer->is_correct, 'the MCQ is marked with no human');
        $this->assertEquals(4.0, (float) $mcqAnswer->awarded_marks);
        $this->assertNull($mcqAnswer->marked_by, 'an automatic mark has no marker');

        $essayAnswer = OnlineExamAnswer::query()->where('question_id', $set['essay'])->firstOrFail();
        $this->assertNull($essayAnswer->awarded_marks, 'the essay waits for a lecturer');

        // Now the lecturer marks it, through the engine's own route.
        $this->actingAs($this->lecturer())
            ->post(route('teacher.online_exams.answers.mark', $essayAnswer->id), [
                'answer_id' => $essayAnswer->id,
                'awarded_marks' => 5,
                'teacher_comment' => 'Correct, though terse.',
            ])
            // Redirects back to the marking queue, as a browser would see it.
            ->assertRedirect();

        $this->assertEquals(5.0, (float) $essayAnswer->fresh()->awarded_marks);
        $this->assertSame($this->lecturer()->id, (int) $essayAnswer->fresh()->marked_by);

        // The TOTAL, which is the arithmetic a lecturer and an admin actually rely
        // on: 4 for the automatic MCQ plus 5 for the essay, out of 10.
        //
        // Asserting the essay's mark against the MCQ's was my error, and asserting
        // only the per-answer marks would have missed whether the engine SUMS them.
        $submission->refresh();

        $this->assertEquals(
            9.0,
            (float) $submission->objective_score + (float) $submission->manual_score,
            'objective and manual marks must sum to the total awarded'
        );
    }

    /**
 * §4: "Lecturer A must not be able to edit, review, publish, mark or inspect
 * Lecturer B's assessment merely by changing an ID."
 *
 * ── THIS TEST FAILED BEFORE A FIX, AND THAT IS THE POINT ───────────────────
 *
 * `OnlineExamAuthorizer::canTeachExam()` had no Course Offering arm. For an
 * Offering assessment - `class_id` AND `programme_id` both NULL - it fell through
 * to the legacy subject rule, and `teacherCanUseSubject()` returns TRUE for a
 * subject linked to neither a class nor a programme. So an unallocated role-3
 * lecturer passed, `canMarkAnswer()` said yes, the route answered 302, and the
 * mark was WRITTEN. The test below failed on `assertForbidden()` having been told
 * 302.
 *
 * The fix asks `CourseContentAccess::canLecturerManage()` for Offering exams. It
 * is asserted here through the ROUTE, because the route is what a real request
 * reaches, and through the authorizer, because that is where the decision lives.
 */
public function test_a_LECTURER_cannot_mark_or_see_a_submission_for_SOMEONE_ELSE_S_offering(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $student = $this->studentA();

        $set = $this->publishedExamWithQuestions('Not yours');
        $submission = $this->startAttempt($set['exam'], $student);
        $this->saveAnswer($submission, $set['essay'], ['answer_text' => 'An answer.']);
        $this->actingAs($student)
            ->post(route('student.online_exam.submit', $set['exam']->id), ['submission_id' => $submission->id])
            ->assertRedirect();

        $answer = OnlineExamAnswer::query()->where('question_id', $set['essay'])->firstOrFail();

        // A DIFFERENT lecturer, in the same school, allocated to nothing. Same
        // tenant, same role, holds `mark_exam_answers` - so nothing but the
        // allocation distinguishes the two men.
        $intruder = $this->makeUser(3, 1, 'active', 'Lecturer B');

        $this->assertTrue(
            app(\App\Support\Permissions\OnlineExamPermissionService::class)->has($intruder, 'mark_exam_answers'),
            'precondition: the intruder HOLDS the marking permission - only the allocation stops him'
        );

        // The decision itself, where it is made.
        $this->assertFalse(
            app(\App\Support\Permissions\OnlineExamAuthorizer::class)->canTeachExam($intruder, $set['exam']),
            'an unallocated lecturer does not teach this Offering, so he does not mark it'
        );

        $this->assertFalse(
            app(\App\Support\Permissions\OnlineExamAuthorizer::class)->canAccessExamAttempts($intruder, $set['exam']),
            'and cannot reach its attempts at all'
        );

        // The route, by direct URL manipulation with a real answer id.
        $this->actingAs($intruder)
            ->post(route('teacher.online_exams.answers.mark', $answer->id), [
                'answer_id' => $answer->id,
                'awarded_marks' => 6,
            ])
            ->assertForbidden();

        $this->assertNull(
            $answer->fresh()->awarded_marks,
            'an unauthorised lecturer must not have altered the mark'
        );
    }

    /**
     * The AUTHOR must never be locked out of their own paper.
     *
     * The fix above tightened marking to "currently allocated", which would be a
     * serious over-correction if it also caught the lecturer who wrote the exam:
     * a course ends, the allocation ends with it, and the lecturer could no longer
     * mark the work their students submitted in week six.
     *
     * It does not, because `canAccessExamAttempts()` offers `ownsExam()` FIRST and
     * only asks `canTeachExam()` as the alternative route in. Asserted here by
     * actually ending the allocation and then marking.
     */
    public function test_the_AUTHOR_can_still_mark_after_their_ALLOCATION_ends(): void
    {
        $lecturer = $this->lecturer();
        $allocationId = $this->allocateLecturer($this->offering(), $lecturer);
        $student = $this->studentA();

        $set = $this->publishedExamWithQuestions('Author retains marking');
        $submission = $this->startAttempt($set['exam'], $student);
        $this->saveAnswer($submission, $set['essay'], ['answer_text' => 'An answer.']);
        $this->actingAs($student)
            ->post(route('student.online_exam.submit', $set['exam']->id), ['submission_id' => $submission->id])
            ->assertRedirect();

        $answer = OnlineExamAnswer::query()->where('question_id', $set['essay'])->firstOrFail();

        // The appointment is withdrawn. Updated through the table rather than the
        // relation, because that is how `allocateLecturer()` wrote it and a stale
        // relation cache would otherwise hide the change from the very code under
        // test.
        DB::table('course_offering_lecturer_allocations')
            ->where('id', $allocationId)
            ->update(['status' => 'ended', 'ends_on' => now()->subDay()->toDateString()]);

        $this->assertFalse(
            app(\App\Support\Permissions\OnlineExamAuthorizer::class)->canTeachExam($lecturer, $set['exam']),
            'precondition: the allocation no longer supports a teaching action'
        );

        $this->actingAs($lecturer)
            ->post(route('teacher.online_exams.answers.mark', $answer->id), [
                'answer_id' => $answer->id,
                'awarded_marks' => 4,
                'teacher_comment' => 'Marked after the allocation ended.',
            ])
            ->assertRedirect();

        $this->assertEquals(
            4.0,
            (float) $answer->fresh()->awarded_marks,
            'the author of a paper must still be able to mark it'
        );
    }

    /**
     * A LEGACY exam must keep its original marking rule.
     *
     * §13 forbids changing the meaning of the nullable legacy fields just to
     * accommodate Course Offerings. This pins the other side of that bargain: the
     * new arm is entered ONLY when `course_offering_id` is set, so a legacy paper
     * continues to be decided in the original order - `programme_id`, then
     * `class_id` / `TeacherPermission`, then `teacherCanUseSubject()`.
     *
     * ── A PREDICTION I GOT WRONG, AND WHY THE TEST NOW READS DIFFERENTLY ─────
     *
     * I expected both legacy papers to answer `false`, having measured the REAL
     * database - where `teacherCanUseSubject()` refuses these subjects. The suite
     * said otherwise for the school-wide paper, and the suite is right: in the
     * fixture the subject is linked to neither a class nor a programme, which is
     * exactly the case that method fails OPEN for.
     *
     * That fail-open is deliberate, pre-existing, and untouched - nine live exams
     * depend on it, and §13 requires exactly that. So the test now asserts what the
     * legacy arm actually does instead of what I assumed it did.
     *
     * The stronger property is asserted first, and it is the one that matters: an
     * ALLOCATION must make no difference to a legacy paper. Two lecturers, one
     * allocated to an Offering and one allocated to nothing, must get the SAME
     * answer to the SAME legacy paper. Were the new arm leaking into the legacy
     * path, the allocated lecturer's answer would change and this would fail.
     */
    public function test_a_LEGACY_exam_is_UNAFFECTED_by_a_Course_Allocation(): void
    {
        $allocated = $this->lecturer();
        $this->allocateLecturer($this->offering(), $allocated);

        $unallocated = $this->makeUser(3, 1, 'active', 'Lecturer With No Allocation');

        $subjectId = $this->offering()->subject_id;
        $auth = app(\App\Support\Permissions\OnlineExamAuthorizer::class);

        $classPaper = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'course_offering_id' => null,
            'title' => 'Legacy class paper',
            'class_id' => 40,
            'programme_id' => 2,
            'session_id' => 1,
            'subject_id' => $subjectId,
            'created_by' => 999, 'creator_id' => 999,
        ]));

        $schoolPaper = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'course_offering_id' => null,
            'title' => 'Legacy school-wide paper',
            'class_id' => null,
            'programme_id' => null,
            'session_id' => null,
            'subject_id' => $subjectId,
            'created_by' => 999, 'creator_id' => 999,
        ]));

        // The property that carries the whole §13 obligation.
        foreach (['class paper' => $classPaper, 'school-wide paper' => $schoolPaper] as $label => $legacy) {
            $this->assertSame(
                $auth->canTeachExam($unallocated, $legacy),
                $auth->canTeachExam($allocated, $legacy),
                "a Course Allocation must change nothing about a legacy {$label}"
            );
        }

        // And each legacy arm is still consulted for its own reason, so neither can
        // be quietly collapsed into the other by a future edit.
        //
        // The class paper NAMES a programme, so the programme arm decides first and
        // refuses - before class or subject is ever considered.
        $this->assertFalse(
            $auth->canTeachExam($allocated, $classPaper),
            'the programme arm still governs a legacy paper that names a programme'
        );

        // The school-wide paper names nothing, so it falls through to the SUBJECT
        // arm, which fails open for an unlinked subject. Pre-existing, unchanged.
        $this->assertTrue(
            $auth->canTeachExam($allocated, $schoolPaper),
            'the pre-existing subject fail-open for an unlinked legacy subject is preserved'
        );
    }

    /**
     * §6: a stale autosave may not overwrite newer work.
     *
     * The `answer_revision` field is the engine's optimistic-concurrency guard, and
     * it is what stops a slow background save - fired before the student typed the
     * last sentence - from clobbering what they have since written.
     *
     * This is the property that made `saveAnswer()` track revisions at all: sending
     * a hard-coded 1 twice produced a 409 on the second save, which looked like a
     * bug in the helper and was in fact the guard working. Asserted directly here so
     * the guard is pinned rather than merely accommodated.
     */
    public function test_a_STALE_autosave_may_NOT_overwrite_NEWER_work(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $student = $this->studentA();

        $set = $this->publishedExamWithQuestions('Autosave conflict');
        $submission = $this->startAttempt($set['exam'], $student);

        // Revision 1: the first save.
        $this->saveAnswer($submission, $set['essay'], ['answer_text' => 'The first save.']);

        // Revision 2: the student keeps typing.
        $this->saveAnswer($submission, $set['essay'], ['answer_text' => 'The second, longer save.']);

        // Now a DELAYED save from revision 1 arrives - the classic race.
        $this->actingAs($student)
            ->postJson(route('student.online_exam.save_answer', $submission->id), [
                'submission_id' => $submission->id,
                'question_id' => $set['essay'],
                'answer_revision' => 1,
                'answer_text' => 'A stale save from before the second one.',
            ])
            ->assertStatus(409);

        $this->assertSame(
            'The second, longer save.',
            OnlineExamAnswer::query()->where('question_id', $set['essay'])->firstOrFail()->getAttributes()['answer_text'],
            'the newer work must survive the stale save'
        );
    }

    public function test_a_LECTURER_cannot_reach_ANOTHER_offerings_exam_by_changing_the_ID(): void
    {
        $theirs = $this->otherInstitution();
        $intruder = $this->makeUser(3, 1, 'active', 'Lecturer In School One');

        $theirExam = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => $theirs['schoolId'],
            'course_offering_id' => $theirs['offering']->id,
            'title' => 'Their paper',
            'created_by' => $theirs['lecturer']->id, 'creator_id' => $theirs['lecturer']->id,
        ]));

        $this->actingAs($intruder)
            ->get(route('teacher.online_exams.questions.index', $theirExam->id))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->post(route('teacher.online_exams.submit_review', $theirExam->id))
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 7. RESULT GOVERNANCE, END TO END
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_student_sees_a_result_ONLY_after_ADMIN_publication(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $admin = $this->admin();
        $student = $this->studentA();

        $set = $this->publishedExamWithQuestions('Result governance');
        $exam = $set['exam'];

        $submission = $this->startAttempt($exam, $student);
        $this->saveAnswer($submission, $set['mcq'], ['selected_option' => 'a']);
        $this->saveAnswer($submission, $set['essay'], ['answer_text' => 'Because 1 has 2 as its successor.']);
        $this->actingAs($student)
            ->post(route('student.online_exam.submit', $exam->id), ['submission_id' => $submission->id])
            ->assertRedirect();

        $this->markAll($exam, $submission);
        $this->actingAs($this->lecturer())
            ->post(route('teacher.online_exams.results.finalize', $submission->id))
            ->assertRedirect();

        $finalized = $submission->fresh();
        $this->assertSame(OnlineExamSubmission::STATUS_FINALIZED, $finalized->status);

        // ── BEFORE ADMIN PUBLICATION: nothing is disclosed ──────────────────
        $this->assertFalse($exam->isResultVisibleFor($finalized));

        $rows = app(CourseOfferingAssessments::class)->forStudent($student, $this->offering());
        $this->assertSame('under_review', $rows[0]['status']);
        $this->assertNull($rows[0]['result_url'], 'no result URL may leak before publication');

        $this->assertNoResultDisclosed(
            $this->actingAs($student)->get(route('student.online_exam.result', $finalized->id))->getContent()
        );

        // The exam's own result page is NOT rendered either.
        $this->assertNoResultDisclosed(
            $this->actingAs($student)->get(route('student.online_exam.take', $exam->id))->getContent()
        );

        // ── ADMIN PUBLISHES ─────────────────────────────────────────────────
        $this->actingAs($admin)
            ->post(route('admin.online_exams.submissions.publish_result', $finalized->id))
            ->assertRedirect();

        $published = $finalized->fresh();
        $this->assertSame(OnlineExamSubmission::STATUS_RESULT_PUBLISHED, $published->status);
        $this->assertSame('published', $published->result_review_state);
        $this->assertTrue($exam->isResultVisibleFor($published));

        // ── NOW the student sees it ─────────────────────────────────────────
        $rows = app(CourseOfferingAssessments::class)->forStudent($student, $this->offering());
        $this->assertSame('results_available', $rows[0]['status']);
        $this->assertNotNull($rows[0]['result_url']);

        $this->actingAs($student)
            ->get(route('student.online_exam.result', $published->id))
            ->assertOk()
            ->assertSee('Score', false);

        // And the publication notification went to THIS student.
        $this->assertNotEmpty(
            DB::table('online_exam_user_notifications')
                ->where('submission_id', $published->id)
                ->where('user_id', $student->id)
                ->get()
        );
    }

    public function test_an_UNPUBLISHED_result_exposes_no_score_percentage_or_verdict(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $student = $this->studentA();

        $set = $this->publishedExamWithQuestions('Nothing disclosed');
        $submission = $this->startAttempt($set['exam'], $student);
        $this->saveAnswer($submission, $set['mcq'], ['selected_option' => 'a']);
        $this->saveAnswer($submission, $set['essay'], ['answer_text' => 'An answer.']);
        $this->actingAs($student)
            ->post(route('student.online_exam.submit', $set['exam']->id), ['submission_id' => $submission->id])
            ->assertRedirect();

        $this->markAll($set['exam'], $submission);
        $this->actingAs($this->lecturer())
            ->post(route('teacher.online_exams.results.finalize', $submission->id));

        $finalized = $submission->fresh();
        $this->assertSame(OnlineExamSubmission::STATUS_FINALIZED, $finalized->status);

        // Every disclosure the result view would otherwise make.
        $this->assertNoResultDisclosed(
            $this->actingAs($student)->get(route('student.online_exam.result', $finalized->id))->getContent()
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 8. NOTIFICATION RECIPIENTS ACROSS THE WHOLE CHAIN
    // ══════════════════════════════════════════════════════════════════════

    public function test_each_transition_notifies_the_right_people_and_nobody_else(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $admin = $this->admin();
        $studentA = $this->studentA();

        $outsider = $this->makeUser(7, 1, 'active', 'Unrelated Student');
        $otherLecturer = $this->makeUser(3, 1, 'active', 'Unrelated Lecturer');

        // A paper that is AWAITING REVIEW, so publishing it is a real transition.
        //
        // `publishedExamWithQuestions()` inserts the exam already `published`, so
        // calling the publish route on it was a no-op: the controller returned early
        // because the state had not changed, and NO notification was sent. The test
        // then asserted a notification and failed - correctly, because the
        // notification was never supposed to fire. A test that publishes an
        // already-published paper is testing nothing.
        $set = $this->publishedExamWithQuestions('Notification chain');
        $exam = $set['exam'];
        $exam->update(['workflow_state' => 'pending_review', 'is_published' => 0]);
        $exam->refresh();

        $this->assertSame('pending_review', $exam->workflow_state, 'precondition: it is NOT published');

        // 1. PUBLISH -> the confirmed students, and nobody else.
        $this->actingAs($admin)
            ->post(route('admin.online_exams.publish', $exam->id))
            ->assertRedirect();

        $this->assertSame('published', $exam->fresh()->workflow_state, 'the Admin step must publish');

        $published = DB::table('online_exam_user_notifications')
            ->where('online_exam_id', $exam->id)->pluck('user_id')->map(fn ($i) => (int) $i);

        $this->assertTrue($published->contains($studentA->id), 'the confirmed student is told');
        $this->assertFalse($published->contains($outsider->id), 'an unrelated student is NOT told');
        $this->assertFalse($published->contains($otherLecturer->id), 'an unrelated lecturer is NOT told');

        // The set is not merely "contains the right person" - it is EXACTLY two
        // people, and the second one is the AUTHOR, not a leak.
        //
        // `publish()` notifies the author ('exam_approved' - your paper went live) as
        // well as the students ('exam_published'). A lecturer who authored a paper
        // should hear that it was approved, and that is a different message to a
        // different audience. Asserting only `[$studentA->id]` would have been wrong:
        // it would have required the product to stop telling an author their own
        // paper was published.
        //
        // Asserting "contains" would have been too weak - a leak to a fifth person
        // would pass it. So the set is asserted exactly, AND the two messages are
        // asserted to be different, which is what proves the author was not
        // notified as though they were a student.
        $this->assertEqualsCanonicalizing(
            [$studentA->id, $lecturer->id],
            $published->unique()->sort()->values()->all(),
            'exactly the confirmed student and the author, and nobody else'
        );

        $byType = DB::table('online_exam_user_notifications')
            ->where('online_exam_id', $exam->id)
            ->pluck('type', 'user_id');

        $this->assertSame('exam_published', $byType[$studentA->id] ?? null, 'the student is told it is available');
        $this->assertSame('exam_approved', $byType[$lecturer->id] ?? null, 'the author is told it was approved');

        // 2. SUBMIT FOR REVIEW -> the admins.
        //
        // Built from the same helper, then flipped back to `draft`, because
        // `teacherSubmitForReview()` checks `publicationReadinessErrors()` FIRST and
        // returns the errors WITHOUT notifying anybody if the paper is incomplete.
        //
        // The earlier version of this test hand-built a bare exam with only a title,
        // so submission was correctly refused for want of marks, a schedule and a
        // question - and then the test asserted a notification that had, correctly,
        // never been sent. Asserting readiness is therefore part of the test rather
        // than an accident of it.
        $reviewSet = $this->publishedExamWithQuestions('Needs review');
        $review = $reviewSet['exam'];
        $review->update(['workflow_state' => 'draft', 'is_published' => 0]);
        $review->refresh();

        $this->assertSame(
            [],
            $review->publicationReadinessErrors(),
            'precondition: this paper IS complete enough to submit for review'
        );

        $this->actingAs($lecturer)
            ->post(route('teacher.online_exams.submit_review', $review->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'pending_review',
            $review->fresh()->workflow_state,
            'submitting for review must reach the Admin queue'
        );

        $adminsNotified = DB::table('online_exam_user_notifications')
            ->where('online_exam_id', $review->id)->pluck('user_id')->map(fn ($i) => (int) $i);

        $this->assertTrue($adminsNotified->contains($admin->id), 'the admin is told a paper awaits review');
        $this->assertFalse(
            $adminsNotified->contains($outsider->id),
            'a student is NOT told about a review that does not concern them'
        );

        // 3. FINALISE FOR REVIEW -> the admins again.
        $submission = $this->startAttempt($exam, $studentA);
        $this->saveAnswer($submission, $set['mcq'], ['selected_option' => 'a']);
        $this->saveAnswer($submission, $set['essay'], ['answer_text' => 'An answer.']);
        $this->actingAs($studentA)
            ->post(route('student.online_exam.submit', $exam->id), ['submission_id' => $submission->id]);

        $this->markAll($exam, $submission);
        $this->actingAs($lecturer)->post(route('teacher.online_exams.results.finalize', $submission->id));

        $this->assertNotEmpty(
            DB::table('online_exam_user_notifications')
                ->where('online_exam_id', $exam->id)
                ->where('user_id', $admin->id)
                ->whereNotNull('submission_id')
                ->get(),
            'the admin is told that marking awaits review'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 9. CROSS-CUTTING ISOLATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_student_from_ANOTHER_institution_receives_nothing(): void
    {
        $theirs = $this->otherInstitution();

        $set = $this->publishedExamWithQuestions('Cross tenant');
        $exam = $set['exam'];

        $this->assertNotContains(
            $theirs['student']->id,
            OnlineExamRecipients::forExam($exam)
        );

        $this->actingAs($theirs['student'])
            ->get(route('student.online_exam.instructions', $exam->id))
            ->assertNotFound();

        $this->actingAs($theirs['student'])
            ->get(route('student.courses.exams', $this->offering()))
            ->assertNotFound();
    }

    public function test_a_student_cannot_SIT_someone_elses_submission_by_changing_the_ID(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $studentA = $this->studentA();

        $set = $this->publishedExamWithQuestions('Ownership');
        $submission = $this->startAttempt($set['exam'], $studentA);

        $studentB = $this->makeUser(7, 1, 'active', 'Student B');
        $this->confirmStudent($this->offering(), $studentB);

        // Save into, resume, and submit SOMEONE ELSE'S attempt.
        $this->actingAs($studentB)
            ->postJson(route('student.online_exam.save_answer', $submission->id), [
                'submission_id' => $submission->id,
                'question_id' => $set['essay'],
                'answer_revision' => 1,
                'answer_text' => 'An answer planted by another student.',
            ])
            ->assertForbidden();

        // 404, and the distinction matters. `findStudentSubmissionOrFail()` scopes by
        // `student_id`, so a submission that is not this student's does not exist as
        // far as they are concerned. A 403 would confirm that SOMEONE ELSE'S attempt
        // exists at that id, which is itself a disclosure - so 404 is the correct
        // answer and asserting 403 would be asserting the weaker security property.
        $this->actingAs($studentB)
            ->get(route('student.online_exam.resume', $submission->id))
            ->assertNotFound();

        $this->assertNull(
            OnlineExamAnswer::query()->where('question_id', $set['essay'])->first(),
            'no answer may be written into another student\'s attempt'
        );
    }

    public function test_a_student_cannot_EXCEED_the_attempt_limit_by_repeating_Start(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $student = $this->studentA();

        $set = $this->publishedExamWithQuestions('One attempt only');
        $exam = $set['exam'];
        $this->assertSame(1, (int) $exam->max_attempts, 'precondition: a single attempt');

        $this->startAttempt($exam, $student);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($student)
                ->postJson(route('student.online_exam.start', $exam->id), ['instructions_acknowledged' => 'on'])
                ->assertStatus(422);
        }

        $this->assertSame(
            1,
            OnlineExamSubmission::where('online_exam_id', $exam->id)->count(),
            'a repeated Start must never mint a second attempt'
        );
    }

    public function test_a_LECTURER_outside_the_allocation_cannot_see_the_offerings_exams(): void
    {
        $this->allocateLecturer($this->offering(), $this->lecturer());
        $this->publishedExamWithQuestions('Not visible to strangers');

        $stranger = $this->makeUser(3, 1, 'active', 'Unallocated Lecturer');

        $this->actingAs($stranger)
            ->get(route('teacher.course_offerings.exams.index', $this->offering()))
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /** Mark every answer on a submission, so finalisation is permitted. */
    private function markAll(OnlineExam $exam, OnlineExamSubmission $submission): void
    {
        $lecturer = $this->lecturer();

        foreach (OnlineExamAnswer::where('submission_id', $submission->id)->get() as $answer) {
            if ($answer->awarded_marks !== null) {
                continue;   // already automatic
            }

            $this->actingAs($lecturer)->post(
                route('teacher.online_exams.answers.mark', $answer->id),
                [
                    'answer_id' => $answer->id,
                    'awarded_marks' => 5,
                    'teacher_comment' => 'Marked.',
                ]
            )
            // A mark REDIRECTS back to the queue, as a browser would see it.
            ->assertRedirect();
        }
    }

    /**
     * Assert that a page discloses NO part of a result.
     *
     * Every string here is something `student/online_exam/result.blade.php` renders
     * when a result IS published, so their absence is the requirement stated in §12
     * rather than a rephrasing of it.
     */
    private function assertNoResultDisclosed(string $html): void
    {
        foreach ([
            'Score' => 'a score',
            'Percent' => 'a percentage',
            'Congratulations' => 'a pass verdict',
            'Sorry, You Did Not Pass' => 'a fail verdict',
            'Automatic Marks' => 'the objective breakdown',
            'Manual Marks' => 'the manual breakdown',
        ] as $needle => $what) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "an unpublished result must not disclose {$what}"
            );
        }
    }
}
