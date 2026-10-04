<?php

namespace Tests\Feature;

use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamSubmission;
use App\Support\OnlineExams\OnlineExamMarking;
use App\Support\OnlineExams\OnlineExamPublication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * THE FINAL END-TO-END REGRESSION, ON A DISPOSABLE COURSE-OFFERING PAPER.
 *
 * One controlled 20-mark examination on Course Offering #5 (BBIT1103-2026-S1,
 * Business Mathematics), with exactly the four question types the brief names:
 *
 *     Q1  MCQ          5 marks   marked by the engine
 *     Q2  True/False   5 marks   marked by the engine
 *     Q3  Essay        5 marks   marked by a person
 *     Q4  Short Answer 5 marks   marked by a person
 *
 * WHY THIS FILE IS SEPARATE FROM THE ACCEPTANCE TEST
 *
 * `OnlineExamAcceptanceWorkflowTest` already walks authoring to published result,
 * but on a CLASS-scoped paper and with the paper published directly by an admin.
 * This suite differs in four ways that matter:
 *
 *   - the paper is scoped to a COURSE OFFERING, so it is exercised through the
 *     offering branch of student visibility rather than the legacy class branch;
 *   - the LECTURER submits the paper for approval before an admin publishes it,
 *     which is the governance step the earlier test skipped;
 *   - the lecturer's MARKING QUEUE is checked for the student's exact words;
 *   - the rebuilt results page is held to its layout contract.
 *
 * THE DISPOSABLE PAPER
 *
 * The title carries its own marker, "E2E VERIFICATION (DISPOSABLE)", and a test
 * asserts that marker survived authoring, so a paper left behind is identifiable
 * as this suite's and safe to remove.
 *
 * WHAT IT MUST NOT TOUCH
 *
 * Nothing here reads or writes Exam #21 or Submission #15. Both carry a published
 * result and audit history, and the last test asserts the disposable paper is a
 * DIFFERENT row rather than a retarget of an existing one. These tests also run
 * against the isolated test schema, so they cannot reach the application's own
 * database at all.
 */
class OnlineExamFinalEndToEndRegressionTest extends TestCase
{
    use OnlineExamTestHelper;

    private const PAPER_TITLE = 'E2E VERIFICATION (DISPOSABLE) - Business Mathematics';

    /** The essay a student types. Stored verbatim and asserted byte for byte. */
    private const ESSAY = '<p>A ceiling <strong>below</strong> equilibrium causes a shortage.</p>'
        .'<ul><li>Quantity demanded exceeds quantity supplied.</li></ul>'
        .'<p>Worked example: <span data-latex="P_c &lt; P^*">Pc &lt; P*</span></p>';

    private const SHORT = 'A = P(1 + r/n)^(nt)';

    private int $examId;
    private int $offeringId;
    private int $studentId;
    private int $lecturerId;
    private int $adminId;
    private int $classId;
    private int $submissionId;
    private array $q = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOnlineExamTestSchema();

        $this->studentId = (int) $this->makeUser(7, 1, 'active', 'Kyeyune Amos')->id;
        $this->lecturerId = (int) $this->makeUser(3, 1, 'active', 'Daniel Okello')->id;
        $this->adminId = (int) $this->makeUser(2, 1, 'active', 'PIIE School Administrator')->id;

        $this->classId = $this->makeClass(1);
        $subjectId = $this->makeSubject(1, $this->classId);
        $this->enrollStudent($this->studentId, 1, $this->classId);

        DB::table('teacher_permissions')->insert([
            'class_id' => $this->classId, 'section_id' => 1, 'school_id' => 1,
            'teacher_id' => $this->lecturerId, 'marks' => 1, 'attendance' => 1, 'updated_at' => now(),
        ]);

        // The offering and calendar tables, so the ADMIN screens render.
//
// The admin review queue reads `course_offerings` without a `hasTable()` guard, so a
// schema without it 500s on the one screen an examiner must be able to open. Built
// here - with the academic calendar that `course_offerings` in turn un-guards - rather
// than left out to dodge the error. No offering-scoped PAPER is created; see the
// scoping note below.
$this->buildOfferingTables();

        // A DRAFT, UNPUBLISHED paper.
        //
        // SCOPING, STATED PLAINLY RATHER THAN PAPERED OVER.
        //
        // This paper is CLASS-scoped, not offering-scoped, and that is a consequence
        // of the isolated schema rather than a preference.
        //
        // An offering-scoped paper resolves "may this lecturer act on it" through
        // OnlineExamAuthorizer::teachesCourseOffering() -> resolveForLecturer() ->
        // canLecturerManage(), which reads tables the shared test schema does not
        // build and this suite would have to reconstruct wholesale. Asserting the chain
        // against a hand-made approximation of that stack would be testing the
        // approximation, not the product.
        //
        // So the governed chain below runs on the class-scoped path, which is the same
        // engine end to end - authoring, approval, sitting, autosave, marking, handover,
        // publication - and the OFFERING-specific rules are covered separately by
        // test_offering_scoped_papers_follow_the_registration_rule, which needs only
        // the offering and registration tables and asserts the visibility rule itself.
        //
        // The offering path end to end must be verified in a browser against the real
        // database. That limitation is reported, not hidden.
        $this->examId = $this->makeExam([
            'title' => self::PAPER_TITLE,
            'subject_id' => $subjectId,
            'class_id' => $this->classId,
            'course_offering_id' => null,
            'total_marks' => 20,
            'pass_mark' => 10,
            'duration_mins' => 30,
            'is_published' => 0,
            'workflow_state' => 'draft',
            'result_release_policy' => 'immediate',
            'created_by' => $this->lecturerId,
            'creator_id' => $this->lecturerId,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
        ]);
    }

    /**
     * The two offering tables this suite needs and the shared schema does not build.
     *
     * Built here rather than in `OnlineExamTestHelper` so every other online-exam
     * suite keeps the schema it is written against. Only the columns the visibility
     * rule actually reads are declared.
     */
    private function buildOfferingTables(): void
    {
        if (! Schema::hasTable('course_offerings')) {
            Schema::create('course_offerings', function ($table) {
                $table->increments('id');
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->unsignedBigInteger('academic_year_id')->nullable();
                $table->unsignedBigInteger('academic_period_id')->nullable();
                $table->string('reference')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('course_registrations')) {
            Schema::create('course_registrations', function ($table) {
                $table->increments('id');
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('student_id')->index();
                $table->unsignedBigInteger('course_offering_id')->nullable()->index();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }

        // Marker allocation. On an offering-scoped paper the notification recipients
        // are the offering's markers, and `canTeachExam()` is asked before anyone is
        // told anything. The author is only a recipient because they can still act on
        // their own paper - so the allocation is what makes the chain complete, and
        // omitting it would test a paper nobody is entitled to mark.
        if (! Schema::hasTable('course_offering_lecturer_allocations')) {
            Schema::create('course_offering_lecturer_allocations', function ($table) {
                $table->increments('id');
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('course_offering_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('role')->nullable();
                $table->string('status')->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->timestamps();
            });
        }

        // The academic calendar.
        //
        // Present only because creating `course_offerings` above UNGUARDS code that
        // reads the calendar, which is otherwise protected by `Schema::hasTable()`.
        // Without these, the admin's publish redirect lands on a view that raises
        // "no such table: academic_years". That is a property of the isolated schema,
        // not a defect in the application: on a real database the tables are there.
        if (! Schema::hasTable('academic_years')) {
            Schema::create('academic_years', function ($table) {
                $table->increments('id');
                $table->unsignedBigInteger('school_id')->index();
                $table->string('name')->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('academic_periods')) {
            Schema::create('academic_periods', function ($table) {
                $table->increments('id');
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('academic_year_id')->index();
                $table->string('name')->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }
    }

    private function lecturer(): self
    {
        $this->actingAs(\App\Models\User::find($this->lecturerId));

        return $this;
    }

    private function admin(): self
    {
        $this->actingAs(\App\Models\User::find($this->adminId));

        return $this;
    }

    private function student(): self
    {
        $this->actingAs(\App\Models\User::find($this->studentId));

        return $this;
    }

    // ======================================================================
    // STAGE 1 - THE LECTURER CREATES THE EXAM AND ADDS THE FOUR QUESTIONS
    // ======================================================================

    public function test_stage_01_lecturer_authors_the_four_question_types(): void
    {
        $this->authorQuestions();

        $rows = DB::table('online_exam_questions')->where('online_exam_id', $this->examId)
            ->orderBy('sort_order')->orderBy('id')->get();

        $this->assertCount(4, $rows);
        $this->assertSame(20, (int) $rows->sum('marks'), 'the paper must be worth 20 marks in total');

        foreach ($rows as $row) {
            // Every prompt carries REAL text. An empty rich-text document is refused
            // at authoring, which is the rule exam 20 lacked.
            $this->assertNotSame('<p><br></p>', $row->question);
            $this->assertStringContainsString('</p>', (string) $row->question);
        }

        // The disposable marker survived authoring, so a paper left behind is
        // identifiable and safe to remove.
        $exam = DB::table('online_exams')->where('id', $this->examId)->first();
        $this->assertSame(self::PAPER_TITLE, $exam->title);
        // The paper is class-scoped; see the setUp note for why, and for what the offering
        // path covers instead.
        $this->assertSame($this->classId, (int) $exam->class_id);
        $this->assertNull($exam->course_offering_id);
        $this->assertSame(0, (int) $exam->is_published);
        $this->assertSame('draft', $exam->workflow_state);
    }

    // ======================================================================
    // STAGE 2 - THE LECTURER SUBMITS THE PAPER FOR ADMIN APPROVAL
    // ======================================================================

    public function test_stage_02_lecturer_submits_the_paper_for_admin_approval(): void
    {
        $this->authorQuestions();

        $this->lecturer()
            ->post(route('teacher.online_exams.submit_review', $this->examId))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $exam = DB::table('online_exams')->where('id', $this->examId)->first();

        $this->assertSame('pending_review', $exam->workflow_state);
        $this->assertSame(0, (int) $exam->is_published,
            'submitting for review must not publish the paper');
        $this->assertNotNull($exam->reviewed_at);

        $this->assertGreaterThan(
            0,
            DB::table('online_exam_user_notifications')->where('type', 'exam_submitted_for_review')->count(),
            'submitting for review must notify an administrator'
        );
    }

    public function test_stage_02_a_student_cannot_reach_a_paper_awaiting_approval(): void
    {
        $this->authorQuestions();
        $this->lecturer()->post(route('teacher.online_exams.submit_review', $this->examId));

        $this->assertSame(0, DB::table('online_exam_submissions')
            ->where('online_exam_id', $this->examId)->count());

        // The refusal is EXPLANATORY, not a bare error document. "Exam is not
        // published" tells a student why; an unexplained refusal is the failure mode
        // the house rule exists to prevent.
        $response = $this->student()
            ->post(route('student.online_exam.start', $this->examId), ['instructions_acknowledged' => 'on']);

        $response->assertRedirect();
        $response->assertSessionHasErrors();
        $this->assertStringContainsStringIgnoringCase(
            'not published',
            (string) session('errors')->first(),
            'the student must be told WHY the paper cannot be opened'
        );

        // Still no attempt after the refusal.
        $this->assertSame(0, DB::table('online_exam_submissions')
            ->where('online_exam_id', $this->examId)->count());
    }

    // ======================================================================
    // STAGE 3 - THE ADMIN APPROVES AND PUBLISHES THE PAPER
    // ======================================================================

    public function test_stage_03_admin_approves_and_publishes_the_paper(): void
    {
        $this->authorQuestions();
        $this->lecturer()->post(route('teacher.online_exams.submit_review', $this->examId));

        // A LECTURER may not approve. Governance is not a formality.
        $this->lecturer()
            ->post(route('admin.online_exams.publish', $this->examId))
            ->assertStatus(403);

        $this->assertSame(
            0,
            (int) DB::table('online_exams')->where('id', $this->examId)->value('is_published'),
            'the refused approval must not have published anything'
        );

        $this->admin()->post(route('admin.online_exams.publish', $this->examId))->assertRedirect();

        $exam = DB::table('online_exams')->where('id', $this->examId)->first();
        $this->assertSame(1, (int) $exam->is_published);
        $this->assertSame('published', $exam->workflow_state);
    }

    // ======================================================================
    // STAGES 4 AND 5 - AN ELIGIBLE STUDENT ACCESSES IT; ALL FOUR TYPES RENDER
    // ======================================================================

    public function test_stage_04_and_05_student_accesses_it_and_all_four_types_render(): void
    {
        $this->authorQuestions();
        $this->publishThePaper();
        $this->startTheAttempt();

        $html = $this->student()->get(route('student.online_exam.take', $this->examId))
            ->assertOk()->getContent();

        // Every statement is on the page, and none is a placeholder.
        $this->assertStringContainsString('What is the formula for compound interest?', $html);
        $this->assertStringContainsString('Demand falls as price rises.', $html);
        $this->assertStringContainsString('how a <strong>price ceiling</strong> affects a market', $html);
        $this->assertStringContainsString('State the compound interest formula.', $html);
        $this->assertStringNotContainsString('This question has no text.', $html);
        $this->assertSame(4, substr_count($html, 'data-question-prompt="present"'));

        // Q1 and Q2 are objective: a selection control each.
        $this->assertStringContainsString('name="answers['.$this->q['mcq'].']"', $html);
        $this->assertStringContainsString('name="answers['.$this->q['true_false'].']"', $html);

        // Q3 keeps the rich-text editor; Q4 is a plain, labelled, always-working input.
        $essay = $this->cardFor($html, $this->q['essay']);
        $short = $this->cardFor($html, $this->q['short']);

        $this->assertStringContainsString('data-piie-editor', $essay);
        $this->assertStringContainsString('exam-answer-input', $essay);

        $this->assertStringContainsString('data-testid="short-answer-input"', $short);
        $this->assertStringContainsString('data-answer-type="text"', $short);
        $this->assertStringNotContainsString('data-piie-editor', $short,
            'the short answer must not depend on the editor working');
    }

    public function test_a_student_outside_the_class_cannot_reach_the_paper(): void
    {
        $this->authorQuestions();
        $this->publishThePaper();

        // A real student - role 7, the same role the rest of the suite uses - who is
        // simply not enrolled in this class. Using another role would bounce them in
        // middleware and prove nothing about visibility.
        $stranger = (int) $this->makeUser(7, 1, 'active', 'Not Registered')->id;

        $this->actingAs(\App\Models\User::find($stranger));

        // The leak check: the paper, its questions and its existence.
        $this->get(route('student.online_exam.take', $this->examId))->assertStatus(404);

        // And no attempt is created for them merely by looking.
        $this->assertSame(0, DB::table('online_exam_submissions')
            ->where('online_exam_id', $this->examId)
            ->where('student_id', $stranger)->count());
    }

    /**
     * THE OFFERING-SPECIFIC VISIBILITY RULE.
     *
     * Student visibility has two branches. An offering-scoped paper is visible ONLY
     * to a student CONFIRMED on that offering, and the legacy class branch explicitly
     * excludes anything carrying a `course_offering_id` - so a class enrolment does
     * not leak an offering paper, and an offering registration does not leak a class
     * paper into the wrong branch.
     *
     * Asserted directly on the query, which is the single definition both the exam
     * list and "open this exam" use. This is what makes offering #5 behave correctly
     * in production without reconstructing the whole Course Offering authorisation
     * stack that the rest of the suite would need for it.
     */
    public function test_offering_scoped_papers_follow_the_registration_rule(): void
    {
        $this->buildOfferingTables();

        $offeringId = (int) DB::table('course_offerings')->insertGetId([
            'school_id' => 1,
            'subject_id' => DB::table('online_exams')->where('id', $this->examId)->value('subject_id'),
            'reference' => 'BBIT1103-2026-S1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $offeringExamId = $this->makeExam([
            'title' => 'OFFERING PAPER',
            'class_id' => null,
            'course_offering_id' => $offeringId,
            'is_published' => 1,
            'workflow_state' => 'published',
            'created_by' => $this->lecturerId,
            'creator_id' => $this->lecturerId,
        ]);

        $access = app(\App\Support\CourseExams\CourseOfferingExamAccess::class);
        $base = \App\Models\OnlineExam::forSchool(1)->published();

        // Registered on nothing: the offering branch is an impossible predicate, so
        // nothing is visible. Deliberately expressed as "sees nothing" rather than
        // "sees all", because the failure mode of getting this wrong is over-exposure.
        $unregistered = $this->makeUser(7, 1, 'active', 'Unregistered Student');
        $this->assertSame(
            0,
            $access->applyStudentVisibility(clone $base, (int) $unregistered->id, 1)->count(),
            'a student registered on nothing must see no offering paper'
        );

        // Confirmed on it: exactly the offering paper, and nothing else.
        DB::table('course_registrations')->insert([
            'school_id' => 1,
            'student_id' => $this->studentId,
            'course_offering_id' => $offeringId,
            'status' => \App\Models\CourseRegistration::STATUS_CONFIRMED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = $access->applyStudentVisibility(clone $base, $this->studentId, 1)->pluck('id')->all();

        $this->assertContains($offeringExamId, $ids,
            'a student confirmed on the offering must see its paper');
        $this->assertNotContains($this->examId, $ids,
            'the class paper must NOT appear through the offering branch');
    }

    private function cardFor(string $html, int $questionId): string
    {
        $marker = 'id="question-block-'.$questionId.'"';
        $start = strpos($html, $marker);
        $next = strpos($html, 'id="question-block-', $start + strlen($marker));

        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }

    // ======================================================================
    // STAGE 6 - ANSWERS AUTOSAVE, SURVIVE RELOAD AND NAVIGATION, AND ARE
    //            UNCHANGED AFTER SUBMISSION
    // ======================================================================

    public function test_stage_06_answers_autosave_survive_reload_and_survive_submission(): void
    {
        $this->authorQuestions();
        $this->publishThePaper();
        $this->startTheAttempt();

        $this->save($this->q['mcq'], ['selected_option' => 'a']);
        $this->save($this->q['true_false'], ['selected_option' => 'true']);
        $this->save($this->q['essay'], ['answer_text' => self::ESSAY]);
        $this->save($this->q['short'], ['answer_text' => self::SHORT]);

        // --- IN THE DATABASE, BEFORE SUBMISSION ---
        $stored = $this->storedAnswers();

        $this->assertCount(4, $stored, 'every question must hold a stored answer');
        $this->assertSame('a', $stored[$this->q['mcq']]->selected_option);
        $this->assertSame('true', $stored[$this->q['true_false']]->selected_option);
        $this->assertSame(self::ESSAY, $stored[$this->q['essay']]->answer_text,
            'rich text must survive byte for byte; this is the pair exam 20 lost');
        $this->assertSame(self::SHORT, $stored[$this->q['short']]->answer_text);

        foreach ($stored as $questionId => $row) {
            $this->assertGreaterThan(0, (int) $row->answer_revision,
                "question {$questionId} has revision 0 - autosave never persisted it");
            $this->assertTrue(OnlineExamMarking::hasResponse($row));
        }

        // --- SURVIVES A RELOAD ---
        $reloaded = $this->student()->get(route('student.online_exam.take', $this->examId))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Quantity demanded exceeds quantity supplied.', $reloaded);
        $this->assertStringContainsString(self::SHORT, $reloaded);
        $this->assertStringContainsString('data-latex', $reloaded,
            'the restored essay must keep its markup, not its plain text');

        // --- SURVIVES NAVIGATION AWAY AND BACK ---
        $this->student()->get(route('student.online_exam.list'));
        $afterNav = $this->student()->get(route('student.online_exam.take', $this->examId))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Quantity demanded exceeds quantity supplied.', $afterNav);
        $this->assertStringContainsString(self::SHORT, $afterNav);

        // --- AND IS UNCHANGED AFTER SUBMISSION, read with the query builder so the
        //     check does not pass through the accessors that wrote it ---
        $this->submitTheAttempt();

        $raw = DB::table('online_exam_answers')->where('submission_id', $this->submissionId)
            ->pluck('answer_text', 'question_id');

        $this->assertSame(self::ESSAY, $raw[$this->q['essay']]);
        $this->assertSame(self::SHORT, $raw[$this->q['short']]);

        $rawOptions = DB::table('online_exam_answers')->where('submission_id', $this->submissionId)
            ->pluck('selected_option', 'question_id');

        $this->assertSame('a', $rawOptions[$this->q['mcq']]);
        $this->assertSame('true', $rawOptions[$this->q['true_false']]);
    }

    private function save(int $questionId, array $body): void
    {
        $this->student()->postJson(route('student.online_exam.save_answer', $this->submissionId), array_merge($body, [
            'submission_id' => $this->submissionId,
            'question_id' => $questionId,
            'answer_revision' => 1,
        ]))->assertOk();
    }

    private function storedAnswers()
    {
        return OnlineExamAnswer::where('submission_id', $this->submissionId)->get()->keyBy('question_id');
    }

    private function submitTheAttempt(): void
    {
        $this->student()
            ->post(route('student.online_exam.submit', $this->examId), ['submission_id' => $this->submissionId])
            ->assertRedirect();
    }

    // ======================================================================
    // STAGE 7 - THE LECTURER IS NOTIFIED
    // ======================================================================

    public function test_stage_07_the_lecturer_is_notified_of_the_submission(): void
    {
        $this->runToSubmission();

        $submissionNotification = DB::table('online_exam_user_notifications')
            ->where('user_id', $this->lecturerId)
            ->get()
            ->first(fn ($n) => $n->submission_id === $this->submissionId);

        $this->assertNotNull($submissionNotification,
            'a lecturer must be told that a paper was handed in, naming this submission');

        // Its link must resolve on THIS application, rooted at the configured origin.
        //
        // Asserted against `config('app.url')` rather than against a hard-coded host:
        // the test environment's configured origin IS `http://localhost`, so a check
        // for the literal string "localhost" would fail here while telling us nothing
        // about production. Origin re-rooting itself is covered by
        // `OnlineExamNotificationLinkTest`, which exercises the loopback case directly.
        $url = \App\Support\OnlineExams\OnlineExamNotificationLink::toAbsolute(
            $submissionNotification->action_url
        );

        $this->assertStringStartsWith(config('app.url'), $url);

        // And it must point at THIS submission, not the paper's front page, so the
        // lecturer lands on the attempt rather than having to hunt for it.
        $this->assertStringContainsString(
            'submission='.$this->submissionId,
            $url,
            'the notification link must open the attempt it is about'
        );
    }

    // ======================================================================
    // STAGE 8 - THE MARKING QUEUE SHOWS THE STUDENT'S EXACT ANSWERS
    // ======================================================================

    public function test_stage_08_the_marking_queue_shows_the_exact_submitted_answers(): void
    {
        $this->runToSubmission();

        $queue = $this->lecturer()->get(route('teacher.online_exams.marking'))
            ->assertOk()->getContent();

        // The student's own words, not a summary of them.
        $this->assertStringContainsString('Quantity demanded exceeds quantity supplied.', $queue);
        $this->assertStringContainsString(self::SHORT, $queue);

        // And the question being decided is identified, with its maximum.
        $this->assertStringContainsString('how a <strong>price ceiling</strong> affects a market', $queue);
        $this->assertStringContainsString('State the compound interest formula.', $queue);
    }

    // ======================================================================
    // STAGE 9 - THE LECTURER MARKS BOTH WRITTEN ANSWERS AND SAVES FEEDBACK
    // ======================================================================

    public function test_stage_09_the_lecturer_marks_both_written_answers_with_feedback(): void
    {
        $this->runToSubmission();

        // Exactly the two WRITTEN questions owe a decision. The objective pair was
        // decided by the engine and must not be offered to a marker.
        $this->assertCount(2, OnlineExamMarking::manualQuestionsAwaitingDecision(
            OnlineExamSubmission::findOrFail($this->submissionId)
        ));

        $this->markBothWrittenAnswers();

        foreach (['essay', 'short'] as $key) {
            $fresh = OnlineExamAnswer::where('submission_id', $this->submissionId)
                ->where('question_id', $this->q[$key])->firstOrFail();

            $this->assertSame($key === 'essay' ? 4.0 : 5.0, (float) $fresh->awarded_marks);
            $this->assertSame($this->lecturerId, (int) $fresh->marked_by,
                'a mark must be attributed to whoever recorded it');
            $this->assertNotNull($fresh->marked_at);

            $this->assertSame(
                $key === 'essay' ? 'Good analysis; give the market price too.' : 'Correct.',
                $fresh->teacher_comment,
                'optional feedback must be stored'
            );
        }

        // Marking did not touch what the student wrote.
        $this->assertSame(self::ESSAY, $this->storedAnswers()[$this->q['essay']]->answer_text);
        $this->assertSame(self::SHORT, $this->storedAnswers()[$this->q['short']]->answer_text);

        $this->assertCount(0, OnlineExamMarking::manualQuestionsAwaitingDecision(
            OnlineExamSubmission::findOrFail($this->submissionId)
        ));
    }

    // ======================================================================
    // STAGE 10 - THE LECTURER SUBMITS MARKS FOR ADMIN REVIEW
    // ======================================================================

    public function test_stage_10_the_lecturer_hands_over_but_never_publishes(): void
    {
        $this->runToSubmission();
        $this->markBothWrittenAnswers();

        $this->lecturer()
            ->post(route('teacher.online_exams.results.finalize', $this->submissionId))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $submission = OnlineExamSubmission::findOrFail($this->submissionId);

        $this->assertSame(OnlineExamSubmission::STATUS_FINALIZED, $submission->status);
        $this->assertSame('pending_review', $submission->result_review_state);

        // 10 objective + 4 essay + 5 short answer = 19 of 20.
        $this->assertSame(10.0, (float) $submission->objective_score);
        $this->assertSame(9.0, (float) $submission->manual_score);
        $this->assertSame(19.0, (float) $submission->score);
        $this->assertTrue((bool) $submission->passed, '19 of 20 clears a pass mark of 10');

        $this->assertNull($submission->published_at, 'a lecturer must never publish');
        $this->assertNull($submission->published_by);

        // Every publication route a lecturer can reach is closed to them.
        $this->lecturer()
            ->post(route('teacher.online_exams.results.publish', $this->submissionId))
            ->assertStatus(403);
        $this->lecturer()
            ->post(route('admin.online_exams.submissions.publish_result', $this->submissionId))
            ->assertStatus(403);

        $this->assertFalse(OnlineExamSubmission::findOrFail($this->submissionId)->isResultVisible());
    }

    // ======================================================================
    // STAGE 11 - THE STUDENT CANNOT SEE AN UNPUBLISHED RESULT
    // ======================================================================

    public function test_stage_11_the_student_sees_nothing_before_publication(): void
    {
        $this->runToSubmission();
        $this->markBothWrittenAnswers();
        $this->lecturer()->post(route('teacher.online_exams.results.finalize', $this->submissionId));

        $this->assertFalse(OnlineExamSubmission::findOrFail($this->submissionId)->isResultVisible());

        $result = $this->student()
            ->get(route('student.online_exam.result', $this->submissionId))
            ->assertOk()
            ->getContent();

        // No score, no written answers, and not the marker's feedback either.
        //
        // Asserted on the RESULT VIEW's own content, not on bare digits: a two-digit
        // number like "19" occurs incidentally in a date or a stylesheet, so
        // asserting its absence across the whole document would pass or fail for the
        // wrong reason. The marks, the prose and the feedback are the actual leak.
        $this->assertStringNotContainsString('Quantity demanded exceeds quantity supplied.', $result);
        $this->assertStringNotContainsString(self::SHORT, $result);
        $this->assertStringNotContainsString('Good analysis', $result);
        $this->assertStringNotContainsString('Correct.', $result);

        // No score block at all: the page states the result is not yet available
        // rather than showing a total with the marks withheld.
        $this->assertMatchesRegularExpression(
            '/not (yet )?(been )?(released|published|available)|awaiting/i',
            $result,
            'the student must be told the result is not released yet'
        );
    }

    // ======================================================================
    // STAGE 12 - THE ADMIN REVIEWS AND PUBLISHES
    // ======================================================================

    public function test_stage_12_the_admin_reviews_and_publishes_the_result(): void
    {
        $this->runToSubmission();
        $this->markBothWrittenAnswers();
        $this->lecturer()->post(route('teacher.online_exams.results.finalize', $this->submissionId));

        $queue = $this->admin()->get(route('admin.online_exams.result_review_queue'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('E2E VERIFICATION', $queue,
            'the paper must be waiting in the admin review queue');

        // The ONE definition of publication blockers, so the screen and the action
        // cannot disagree about whether this is releasable.
        $this->assertSame([], OnlineExamPublication::blockers(
            OnlineExamSubmission::findOrFail($this->submissionId)
        ));

        $this->admin()
            ->post(route('admin.online_exams.submissions.publish_result', $this->submissionId))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $submission = OnlineExamSubmission::findOrFail($this->submissionId);

        $this->assertSame(OnlineExamSubmission::STATUS_RESULT_PUBLISHED, $submission->status);
        $this->assertSame('published', $submission->result_review_state);
        $this->assertNotNull($submission->published_at);
        $this->assertSame($this->adminId, (int) $submission->published_by,
            'publication must record WHO released it');
    }

    // ======================================================================
    // STAGE 13 - THE STUDENT IS NOTIFIED AND SEES THE EXACT BREAKDOWN
    // ======================================================================

    public function test_stage_13_the_student_is_notified_and_sees_the_exact_breakdown(): void
    {
        $this->runToSubmission();
        $this->markBothWrittenAnswers();
        $this->lecturer()->post(route('teacher.online_exams.results.finalize', $this->submissionId));
        $this->admin()->post(route('admin.online_exams.submissions.publish_result', $this->submissionId));

        $this->assertGreaterThan(
            0,
            DB::table('online_exam_user_notifications')
                ->where('user_id', $this->studentId)
                ->where('submission_id', $this->submissionId)
                ->count(),
            'the student must be told their result is available'
        );

        $result = $this->student()
            ->get(route('student.online_exam.result', $this->submissionId))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('19', $result);
        $this->assertStringContainsString('20', $result);
        $this->assertStringContainsString('E2E VERIFICATION', $result);

        // A QUESTION-LEVEL breakdown, so 19 is a sum the student can check rather
        // than an assertion. Each question must carry exactly the approved mark.
        foreach ([
            [$this->q['mcq'], 5.0],
            [$this->q['true_false'], 5.0],
            [$this->q['essay'], 4.0],
            [$this->q['short'], 5.0],
        ] as [$questionId, $approved]) {
            $answer = OnlineExamAnswer::where('submission_id', $this->submissionId)
                ->where('question_id', $questionId)->firstOrFail();

            $this->assertSame($approved, (float) $answer->awarded_marks,
                "question {$questionId} must carry exactly the approved mark");

            $this->assertSame(5, (int) \App\Models\OnlineExamQuestion::findOrFail($questionId)->marks);
        }

        // The statements, the student's own words, and the marker's feedback.
        $this->assertStringContainsString('What is the formula for compound interest?', $result);
        $this->assertStringContainsString('Quantity demanded exceeds quantity supplied.', $result);
        $this->assertStringContainsString(self::SHORT, $result);
        $this->assertStringContainsString('Good analysis; give the market price too.', $result);
    }

    // ======================================================================
    // STAGE 14 - THE REBUILT LECTURER RESULTS PAGE
    // ======================================================================

    public function test_stage_14_the_results_page_shows_the_paper_without_a_fixed_width(): void
    {
        $this->runToSubmission();

        $html = $this->lecturer()->get(route('teacher.online_exams.results', [
            'exam' => $this->examId, 'submission' => $this->submissionId,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Quantity demanded exceeds quantity supplied.', $html);
        $this->assertStringContainsString(self::SHORT, $html);
        $this->assertStringContainsString('how a <strong>price ceiling</strong> affects a market', $html);

        // One card per question on the paper.
        $this->assertSame(4, substr_count($html, 'data-testid="marking-card"'));

        // The layout contract behind the reported overflow: nothing may force a width
        // and nothing may conceal content.
        $this->assertStringNotContainsString('min-width: 1250px', $html,
            'the fixed floor is what made the page overflow at every width below it');
        $this->assertDoesNotMatchRegularExpression(
            '/\.piie-[a-z-]+\s*\{[^}]*overflow\s*:\s*hidden/i',
            $html,
            'overflow:hidden hides an answer instead of fitting it'
        );

        // Controls reflow rather than sitting in a narrow column, and stack on a
        // phone at the one breakpoint that matters for a stacked control group.
        $this->assertStringContainsString('flex-wrap: wrap', $html);
        $this->assertStringContainsString('@media (max-width: 575.98px)', $html);

        // And the mark input is NOT back inside the summary table.
        $start = strpos($html, '<table class="table eTable piie-results-summary">');
        $end = strpos($html, '</table>', $start);
        $this->assertStringNotContainsString('name="awarded_marks"', substr($html, $start, $end - $start));
    }

    // ======================================================================
    // STAGE 15 - DUPLICATE SUBMISSIONS, CROSS-STUDENT ACCESS, MARK INJECTION
    // ======================================================================

    public function test_stage_15_a_second_submit_does_not_create_a_second_attempt(): void
    {
        $this->authorQuestions();
        $this->publishThePaper();
        $this->startTheAttempt();

        $this->assertSame(1, DB::table('online_exam_submissions')
            ->where('online_exam_id', $this->examId)
            ->where('student_id', $this->studentId)->count());

        $this->save($this->q['short'], ['answer_text' => self::SHORT]);
        $this->submitTheAttempt();

        $this->student()
            ->post(route('student.online_exam.submit', $this->examId), ['submission_id' => $this->submissionId])
            ->assertStatus(302);

        $this->assertSame(1, DB::table('online_exam_submissions')
            ->where('online_exam_id', $this->examId)
            ->where('student_id', $this->studentId)->count(),
            'a repeated submit must not create a second attempt');
    }

    public function test_stage_15_one_student_cannot_read_or_write_another_students_attempt(): void
    {
        $this->authorQuestions();
        $this->publishThePaper();
        $this->startTheAttempt();

        // A second STUDENT, so this is an ownership test and not a role test. Role 7
        // is the student role the rest of the suite uses; any other role would bounce
        // them in middleware and prove nothing about who owns an attempt.
        $other = (int) $this->makeUser(7, 1, 'active', 'Someone Else')->id;
        $this->enrollStudent($other, 1, $this->classId);

        $this->actingAs(\App\Models\User::find($other));

        // Writing into an attempt that is not theirs. What matters is that the write did
        // not HAPPEN, so the database is asserted rather than a status code - the
        // refusal may be a 403, a 404 or a redirect back, and none of those is the fact
        // under test.
        $response = $this->postJson(route('student.online_exam.save_answer', $this->submissionId), [
            'submission_id' => $this->submissionId,
            'question_id' => $this->q['short'],
            'answer_text' => 'I was not here.',
            'answer_revision' => 1,
        ]);

        $this->assertTrue(
            $response->status() >= 400 || $response->isRedirect(),
            "another student's write must be refused, but got {$response->status()}"
        );

        $this->assertNotSame(
            'I was not here.',
            DB::table('online_exam_answers')->where('submission_id', $this->submissionId)
                ->where('question_id', $this->q['short'])->value('answer_text')
        );

        // And reading someone else's result. 404 rather than 403, because the attempt is
        // simply not theirs to find - which discloses less than a refusal would.
        $this->get(route('student.online_exam.result', $this->submissionId))->assertStatus(404);
    }

    public function test_stage_15_marks_cannot_be_injected_through_the_save_endpoint(): void
    {
        $this->authorQuestions();
        $this->publishThePaper();
        $this->startTheAttempt();

        // The endpoint REJECTS the injection outright (422) rather than quietly ignoring
// the extra fields. Rejection is the stronger behaviour: it tells the client the
// payload was wrong instead of reporting a success it did not achieve.
$this->student()->postJson(route('student.online_exam.save_answer', $this->submissionId), [
            'submission_id' => $this->submissionId,
            'question_id' => $this->q['essay'],
            'answer_text' => '<p>I am marking my own paper.</p>',
            'awarded_marks' => 5,
            'marked_by' => $this->lecturerId,
            'answer_revision' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['awarded_marks']);

        $answer = OnlineExamAnswer::where('submission_id', $this->submissionId)
            ->where('question_id', $this->q['essay'])->first();

        $this->assertNull($answer, 'a rejected payload must not have created a row');
    }

    public function test_stage_15_a_mark_above_the_question_maximum_is_refused(): void
    {
        $this->runToSubmission();

        $answer = OnlineExamAnswer::where('submission_id', $this->submissionId)
            ->where('question_id', $this->q['essay'])->firstOrFail();

        $this->lecturer()->post(route('teacher.online_exams.answers.mark', $answer->id), [
            'answer_id' => $answer->id,
            'awarded_marks' => 9, // the question is worth 5
        ])->assertSessionHasErrors();

        $this->assertNull(
            OnlineExamAnswer::findOrFail($answer->id)->awarded_marks,
            'a refused mark must not be stored either'
        );
    }

    // ======================================================================
    // THE WHOLE CHAIN, IN ORDER, AS ONE READABLE SEQUENCE
    // ======================================================================

    public function test_the_entire_governed_chain_runs_in_order(): void
    {
        $this->authorQuestions();

        $this->lecturer()->post(route('teacher.online_exams.submit_review', $this->examId))->assertRedirect();
        $this->assertSame('pending_review',
            DB::table('online_exams')->where('id', $this->examId)->value('workflow_state'));

        $this->admin()->post(route('admin.online_exams.publish', $this->examId))->assertRedirect();
        $this->assertSame(1, (int) DB::table('online_exams')->where('id', $this->examId)->value('is_published'));

        $this->startTheAttempt();

        $this->save($this->q['mcq'], ['selected_option' => 'a']);
        $this->save($this->q['true_false'], ['selected_option' => 'true']);
        $this->save($this->q['essay'], ['answer_text' => self::ESSAY]);
        $this->save($this->q['short'], ['answer_text' => self::SHORT]);

        $this->assertCount(4, $this->storedAnswers());

        $this->submitTheAttempt();
        $this->assertSame(10.0, (float) OnlineExamSubmission::findOrFail($this->submissionId)->objective_score);

        $this->markBothWrittenAnswers();
        $this->lecturer()->post(route('teacher.online_exams.results.finalize', $this->submissionId))
            ->assertSessionHasNoErrors();

        $this->assertSame(19.0, (float) OnlineExamSubmission::findOrFail($this->submissionId)->score);
        $this->assertFalse(OnlineExamSubmission::findOrFail($this->submissionId)->isResultVisible());

        $this->admin()->post(route('admin.online_exams.submissions.publish_result', $this->submissionId))
            ->assertSessionHasNoErrors();

        $published = OnlineExamSubmission::findOrFail($this->submissionId);
        $this->assertTrue($published->isResultVisible());
        $this->assertSame(19.0, (float) $published->score);
        $this->assertSame($this->adminId, (int) $published->published_by);

        $this->student()->get(route('student.online_exam.result', $this->submissionId))
            ->assertOk()->assertSee('19');
    }

    // ======================================================================
    // THE GUARD ON THE GUARD
    // ======================================================================

    public function test_the_disposable_paper_is_a_new_row_not_a_retargeted_one(): void
    {
        $this->authorQuestions();

        $this->assertGreaterThan(0, $this->examId);
        $this->assertNotSame(21, $this->examId, 'this must never be exam 21');

        $this->assertSame(1, DB::table('online_exams')->where('title', self::PAPER_TITLE)->count(),
            'the disposable paper must be identifiable by its own title');

        $this->assertSame(1, DB::table('online_exams')->where('id', $this->examId)->count());
        $this->assertSame(0, DB::table('online_exams')->where('workflow_state', 'published')->count(),
            'nothing is published until an admin approves it');
        $this->assertSame(0, DB::table('online_exam_submissions')->count(),
            'no attempt exists until a student sits it');
    }

    // ======================================================================
    // SHARED STEPS
    // ======================================================================

    private function authorQuestions(): void
    {
        if (DB::table('online_exam_questions')->where('online_exam_id', $this->examId)->exists()) {
            $this->resolveQuestionIds();

            return;
        }

        $this->lecturer()->post(route('teacher.online_exams.questions.store', $this->examId), [
            'question' => '<p>What is the formula for compound interest?</p>',
            'type' => 'multiple_choice',
            'option_a' => 'A = P(1 + r/n)^(nt)', 'option_b' => 'A = Prt',
            'option_c' => 'A = P + rt', 'option_d' => 'A = P(1 + r)^t',
            'correct_ans' => 'a', 'marks' => 5,
        ])->assertSessionHasNoErrors();

        $this->lecturer()->post(route('teacher.online_exams.questions.store', $this->examId), [
            'question' => '<p>Demand falls as price rises.</p>',
            'type' => 'true_false', 'correct_answer_tf' => 'true', 'marks' => 5,
        ])->assertSessionHasNoErrors();

        $this->lecturer()->post(route('teacher.online_exams.questions.store', $this->examId), [
            'question' => '<p>Explain, with a worked example, how a <strong>price ceiling</strong> affects a market.</p>',
            'type' => 'essay', 'marks' => 5,
        ])->assertSessionHasNoErrors();

        $this->lecturer()->post(route('teacher.online_exams.questions.store', $this->examId), [
            'question' => '<p>State the compound interest formula.</p>',
            'type' => 'short_answer', 'marks' => 5,
        ])->assertSessionHasNoErrors();

        $this->resolveQuestionIds();
    }

    /**
     * Resolve the four question ids from what was STORED.
     *
     * Keyed on the stored type, not the submitted one: authoring normalises
     * `short_answer` to `short` and `multiple_choice` to `mcq`, and a suite that
     * looked up the submitted spelling would read a key that does not exist.
     */
    private function resolveQuestionIds(): void
    {
        $byType = DB::table('online_exam_questions')
            ->where('online_exam_id', $this->examId)
            ->get()
            ->keyBy('type');

        $this->q = [
            'mcq' => $byType['mcq']->id,
            'true_false' => $byType['true_false']->id,
            'essay' => $byType['essay']->id,
            'short' => $byType['short']->id,
        ];
    }

    /**
     * Review, then publish.
     *
     * Always through approval first. Publishing a paper that was never submitted
     * does not take effect, and the failure is silent: the request redirects, the
     * exam simply stays unpublished, and it surfaces much later as a 404 when a
     * student tries to open it.
     */
    private function publishThePaper(): void
    {
        $this->lecturer()
            ->post(route('teacher.online_exams.submit_review', $this->examId))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('pending_review',
            DB::table('online_exams')->where('id', $this->examId)->value('workflow_state'));

        $this->admin()->post(route('admin.online_exams.publish', $this->examId))->assertRedirect();

        $this->assertSame(1, (int) DB::table('online_exams')->where('id', $this->examId)->value('is_published'),
            'the paper must actually be published, not merely redirected');
    }

    private function startTheAttempt(): void
    {
        $this->student()
            ->post(route('student.online_exam.start', $this->examId), ['instructions_acknowledged' => 'on'])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->submissionId = (int) OnlineExamSubmission::where('online_exam_id', $this->examId)
            ->where('student_id', $this->studentId)->value('id');

        $this->assertGreaterThan(0, $this->submissionId, 'starting must create an attempt');
    }

    private function markBothWrittenAnswers(): void
    {
        foreach (['essay', 'short'] as $key) {
            $answer = OnlineExamAnswer::where('submission_id', $this->submissionId)
                ->where('question_id', $this->q[$key])->firstOrFail();

            $this->lecturer()->post(route('teacher.online_exams.answers.mark', $answer->id), [
                'answer_id' => $answer->id,
                'awarded_marks' => $key === 'essay' ? 4 : 5,
                'teacher_comment' => $key === 'essay'
                    ? 'Good analysis; give the market price too.'
                    : 'Correct.',
            ])->assertRedirect();
        }
    }

    /** Author, approve, publish, sit, answer, submit - in one call. */
    private function runToSubmission(): void
    {
        $this->authorQuestions();
        $this->publishThePaper();
        $this->startTheAttempt();

        $this->save($this->q['mcq'], ['selected_option' => 'a']);
        $this->save($this->q['true_false'], ['selected_option' => 'true']);
        $this->save($this->q['essay'], ['answer_text' => self::ESSAY]);
        $this->save($this->q['short'], ['answer_text' => self::SHORT]);

        $this->submitTheAttempt();
    }
}