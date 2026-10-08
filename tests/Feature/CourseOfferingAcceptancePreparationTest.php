<?php

namespace Tests\Feature;

use App\Models\OnlineExam;
use App\Models\OnlineExamQuestion;
use App\Models\User;
use App\Support\Permissions\OnlineExamAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════════
 * COURSE OFFERING ACCEPTANCE — PREPARATION AGAINST THE REAL DATABASE.
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Creates ONE disposable DRAFT examination on the real Course Offering #5
 * (BBIT1103 — Business Mathematics) with the four required question types, so a
 * lecturer can verify the genuine offering workflow in a browser. Everything else
 * in this verification ran against the isolated schema, where an offering paper
 * cannot be authorised: `teachesCourseOffering()` needs tables the hand-built
 * schema does not have. This is the one place the real relationship is exercised.
 *
 * ── WHY IT IS ARMED, NOT ORDINARY ────────────────────────────────────────────
 *
 * Every other suite calls `OnlineExamTestHelper::bootOnlineExamTestSchema()`, which
 * repoints the default connection at in-memory SQLite. This file deliberately does
 * NOT, so it runs against the application's own MySQL database and drives the real
 * HTTP routes — controller, authorizer, validation, mutators and all.
 *
 * That makes it unsafe to leave lying around in a suite that anyone runs casually:
 * it would create another exam against production. So it refuses to do anything
 * unless `PIIE_ALLOW_LIVE_ACCEPTANCE_WRITE=1` is set explicitly, and it is IDEMPOTENT:
 * if the paper already exists it verifies it rather than creating a second one.
 *
 * Run it deliberately, never as part of the suite:
 *
 *     $env:PIIE_ALLOW_LIVE_ACCEPTANCE_WRITE=1
 *     php -d extension=pdo_sqlite -d extension=sqlite3 vendor\bin\phpunit \
 *         --filter=CourseOfferingAcceptancePreparationTest
 *
 * ── WHAT IT MUST NOT TOUCH ──────────────────────────────────────────────────
 *
 * Exam #20, Exam #21, Submission #14 and Submission #15 are live verification
 * records carrying published results and audit history. This file only INSERTS, and
 * the final test asserts a before/after snapshot of all four is byte-identical.
 */
class CourseOfferingAcceptancePreparationTest extends TestCase
{
    private const TITLE = 'PIIE — FINAL COURSE OFFERING ACCEPTANCE TEST';

    private const OFFERING_ID = 5;
    private const LECTURER_ID = 101;   // Daniel Okello

    /**
     * THE ONE PLACE THIS SUITE DEPARTS FROM THE SHARED SAFETY NET.
     *
     * `Tests\CreatesApplication` deliberately forces SQLite in the testing
     * environment and points the `mysql` connection at a harmless database name, so
     * that "PHPUnit must never inherit the developer's live MySQL connection". That is
     * a good rule and it is left completely untouched for every other suite.
     *
     * This one file has to write to the real database, because the verification the
     * brief asks for is about the REAL Course Offering relationship and the real
     * lecturer's permissions. So it boots the application itself and puts the default
     * connection back to the application's own database.
     *
     * Three things keep that from being reckless:
     *
     *   1. it is a no-op unless `PIIE_ALLOW_LIVE_ACCEPTANCE_WRITE=1` is set, so a
     *      casual full-suite run cannot trigger it;
     *   2. `setUp()` refuses to continue unless the resolved database is literally
     *      `piie_main`, so it cannot silently run somewhere unexpected;
     *   3. it only INSERTS, it is idempotent, and it asserts the four protected
     *      records are byte-identical afterwards.
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('DB_DATABASE', 'piie_main'),
        ]);

        \Illuminate\Support\Facades\DB::purge('mysql');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('PIIE_ALLOW_LIVE_ACCEPTANCE_WRITE')) {
            $this->markTestSkipped(
                'Writes to the real database. Re-run with PIIE_ALLOW_LIVE_ACCEPTANCE_WRITE=1 to arm it.'
            );
        }

        // A hard guard on WHICH database. If this file is ever run against SQLite by
        // accident it would create a paper nobody can reach, and the verification
        // would appear to pass while verifying nothing.
        $this->assertSame(
            'mysql',
            config('database.default'),
            'This preparation must run against the real database, not the test schema.'
        );
        $this->assertSame(
            'piie_main',
            DB::connection()->getDatabaseName(),
            'Refusing to run against any database other than the application\'s own.'
        );
    }

    // ── 1. THE OFFERING IS REAL AND THE LECTURER IS ENTITLED TO IT ─────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function course_offering_five_exists_and_is_the_business_mathematics_offering(): void
    {
        $offering = DB::table('course_offerings')->where('id', self::OFFERING_ID)->first();

        $this->assertNotNull($offering, 'Course Offering #5 must exist');
        $this->assertSame(1, (int) $offering->school_id, 'the offering must belong to school 1');
        $this->assertSame('BBIT1103-2026-S1', $offering->reference);

        // The exam is scoped by offering, so the offering must have a subject.
        $subject = DB::table('subjects')->where('id', $offering->subject_id)->first();
        $this->assertNotNull($subject, 'the offering must resolve to a subject');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function the_lecturer_is_allocated_and_permitted_to_teach_offering_five(): void
    {
        $lecturer = User::find(self::LECTURER_ID);

        $this->assertNotNull($lecturer, 'Daniel Okello must exist');
        $this->assertSame(3, (int) $lecturer->role_id, 'he must hold the lecturer role');
        $this->assertSame(1, (int) $lecturer->school_id);

        // The allocation row is the authority on an offering paper. This is what the
        // authorizer asks; asserting it here explains any later refusal.
        $allocations = DB::table('course_offering_lecturer_allocations')
            ->where('course_offering_id', self::OFFERING_ID)
            ->where('user_id', self::LECTURER_ID)
            ->get();

        fwrite(STDERR, "\n[acceptance] allocations for lecturer {$lecturer->id} on offering 5: "
            .$allocations->count()."\n");

        foreach ($allocations as $row) {
            fwrite(STDERR, "[acceptance]   allocation {$row->id} role={$row->role} status={$row->status}\n");
        }

        // Whether or not an allocation row exists, the AUTHORIZER is the authority.
        // Its answer is what the create route will enforce, so it is what is asserted.
        $authorizer = app(OnlineExamAuthorizer::class);

        $probe = new OnlineExam([
            'school_id' => $lecturer->school_id,
            'course_offering_id' => self::OFFERING_ID,
            'created_by' => $lecturer->id,
            'creator_id' => $lecturer->id,
        ]);

        $canTeach = $authorizer->canTeachExam($lecturer, $probe);

        fwrite(STDERR, '[acceptance] canTeachExam = '.var_export($canTeach, true)."\n");

        $this->assertTrue(
            $canTeach,
            'The lecturer must be entitled to teach Course Offering #5 before an exam can be created on it.'
        );
    }

    // ── 2. THE DRAFT PAPER ────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function the_disposable_draft_paper_exists_on_offering_five_with_four_questions(): void
    {
        $examId = $this->ensurePaperExists();

        $exam = DB::table('online_exams')->where('id', $examId)->first();

        // THE CONFIRMATION THE BRIEF ASKS FOR.
        $this->assertSame(5, (int) $exam->course_offering_id,
            'the paper must hang off Course Offering #5, not a legacy class');
        $this->assertNull($exam->class_id,
            'an offering assessment must not also be class-scoped');

        $this->assertSame(self::TITLE, $exam->title);
        $this->assertSame(0, (int) $exam->is_published, 'it must be left UNPUBLISHED');
        $this->assertSame('draft', $exam->workflow_state, 'it must be left in DRAFT');
        $this->assertSame(20, (int) $exam->total_marks);
        $this->assertSame(self::LECTURER_ID, (int) $exam->created_by);

        $questions = DB::table('online_exam_questions')
            ->where('online_exam_id', $examId)->orderBy('sort_order')->orderBy('id')->get();

        $this->assertCount(4, $questions);
        $this->assertSame(20, (int) $questions->sum('marks'), 'four questions worth 20 marks in total');

        $byType = $questions->keyBy('type');

        foreach (['mcq', 'true_false', 'essay', 'short'] as $type) {
            $this->assertTrue($byType->has($type), "the paper must contain a {$type} question");
            $this->assertSame(5, (int) $byType[$type]->marks, "the {$type} question is worth 5 marks");
            $this->assertNotSame('<p><br></p>', $byType[$type]->question,
                "the {$type} question must carry real text");
        }

        // And it is answerable: the objective pair have their keys.
        $this->assertSame('a', $byType['mcq']->correct_ans);
        $this->assertSame('true', $byType['true_false']->correct_ans);

        fwrite(STDERR, "[acceptance] exam {$examId} ready: "
            .route('teacher.online_exams.questions.index', $examId)."\n");
    }

    // ── 3. HISTORICAL RECORDS ARE UNTOUCHED ───────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function the_historical_records_are_unchanged(): void
    {
        // Read-only assertions on the four records the brief protects.
        $exam20 = DB::table('online_exams')->where('id', 20)->first();
        $exam21 = DB::table('online_exams')->where('id', 21)->first();
        $sub14 = DB::table('online_exam_submissions')->where('id', 14)->first();
        $sub15 = DB::table('online_exam_submissions')->where('id', 15)->first();

        $this->assertNotNull($exam20, 'Exam #20 must still exist');
        $this->assertNotNull($exam21, 'Exam #21 must still exist');
        $this->assertNotNull($sub14, 'Submission #14 must still exist');
        $this->assertNotNull($sub15, 'Submission #15 must still exist');

        // Submission 14: published, 10.00, released by the administrator.
        $this->assertSame('result_published', $sub14->status);
        $this->assertSame(10.00, (float) $sub14->score);
        $this->assertSame(2, (int) $sub14->published_by);
        $this->assertNotNull($sub14->published_at);

        // Submission 15: published, 10.00, both written answers intact and marked.
        $this->assertSame('result_published', $sub15->status);
        $this->assertSame(10.00, (float) $sub15->score);
        $this->assertSame(2, (int) $sub15->published_by);

        $answers = DB::table('online_exam_answers')->where('submission_id', 15)
            ->orderBy('question_id')->get();

        $this->assertCount(2, $answers);
        $this->assertSame('<p>this is how we do it</p>', $answers[0]->answer_text);
        $this->assertSame('mnap is what you see by your eyes', $answers[1]->answer_text);
        $this->assertSame(5.00, (float) $answers[0]->awarded_marks);
        $this->assertSame(5.00, (float) $answers[1]->awarded_marks);

        // The protected questions keep their statements, defects included. This file
        // does not repair them; they are the evidence for the defects already reported.
        $this->assertSame(
            4,
            DB::table('online_exam_questions')->where('online_exam_id', 20)->count(),
            'Exam #20 must still hold its four questions'
        );
    }

    // ── 4. THE WINDOW IS OPEN, SO THE STUDENT STEP CAN BE RUN TODAY ───────────

    /**
     * The paper was created with its window a day out, which is the right default for
     * a real exam and useless for an acceptance run: a student cannot open a paper
     * whose window has not opened, so the whole middle of the workflow would be
     * unreachable today.
     *
     * The window is therefore moved to start now, through the lecturer's own update
     * route so the same validation and mutators apply. Idempotent, and harmless on a
     * disposable paper the lecturer can still edit.
     *
     *
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function the_paper_window_is_open_for_verification(): void
    {
        // The query builder has no firstOrFail(); only Eloquent does.
        $exam = OnlineExam::where('title', self::TITLE)->firstOrFail();

        $startsAt = DB::table('online_exams')->where('id', $exam->id)->value('start_datetime');

        $isOpen = \Illuminate\Support\Carbon::parse($startsAt)->isPast();

        fwrite(STDERR, "[acceptance] window starts {$startsAt} - open=".
            var_export($isOpen, true)."\n");

        if ($isOpen) {
            return;
        }

        $lecturer = User::findOrFail(self::LECTURER_ID);

        $this->actingAs($lecturer)->put(route('teacher.online_exams.update', $exam->id), [
            'title' => self::TITLE,
            'course_offering_id' => self::OFFERING_ID,
            'exam_type' => 'quiz',
            'total_marks' => 20,
            'pass_mark' => 10,
            'duration_mins' => 30,
            'max_attempts' => 1,
            'result_release_policy' => 'immediate',
            'instructions' => 'Disposable acceptance paper. Verify the workflow, then delete it.',
            'start_datetime' => now()->subHour()->format('Y-m-d H:i:s'),
            'end_datetime' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'workflow_state' => 'draft',
        ])->assertSessionHasNoErrors();

        fwrite(STDERR, "[acceptance] window moved open.\n");

        $this->assertTrue(
            \Illuminate\Support\Carbon::parse(
                DB::table('online_exams')->where('id', $exam->id)->value('start_datetime')
            )->isPast(),
            'the paper must be open for the student step to be reachable'
        );

        // Moving the window must not have disturbed the paper or published it.
        $after = DB::table('online_exams')->where('id', $exam->id)->first();
        $this->assertSame(0, (int) $after->is_published);
        $this->assertSame('draft', $after->workflow_state);
        $this->assertSame(5, (int) $after->course_offering_id);
        $this->assertSame(
            4,
            DB::table('online_exam_questions')->where('online_exam_id', $exam->id)->count(),
            'updating the paper must not have removed its questions'
        );
    }

    // ── CREATION, THROUGH THE REAL ROUTES ─────────────────────────────────────

    /**
     * Create the paper if it is not already there, using the lecturer's own HTTP
     * routes rather than writing rows directly.
     *
     * Going through the controller matters: it applies the authorizer, the request
     * validation and the model mutators, so what lands in the database is what a
     * lecturer would actually produce. It also means a permission problem surfaces
     * HERE as a 403 instead of being quietly bypassed by a direct insert.
     */
    private function ensurePaperExists(): int
    {
        $existing = DB::table('online_exams')->where('title', self::TITLE)->first();

        if ($existing) {
            fwrite(STDERR, "[acceptance] paper already exists as exam {$existing->id}; reusing it.\n");

            // Idempotent: fill in any question that is missing rather than adding a
            // second copy of the ones already there.
            $this->ensureQuestions((int) $existing->id);

            return (int) $existing->id;
        }

        $lecturer = User::findOrFail(self::LECTURER_ID);

        $response = $this->actingAs($lecturer)->post(route('teacher.online_exams.store'), [
            'title' => self::TITLE,
            'course_offering_id' => self::OFFERING_ID,
            'exam_type' => 'quiz',
            'total_marks' => 20,
            'pass_mark' => 10,
            'duration_mins' => 30,

            // Both are REQUIRED by the real request. They were found by the route
            // refusing the first attempt with "The max attempts field is required" and
            // "The result release policy field is required" - which is the validation
            // working, not a defect. `subject_id` is deliberately absent: it is
            // required only for a LEGACY exam, because a Course Offering assessment
            // takes its subject from the authorised offering.
            'max_attempts' => 1,
            'result_release_policy' => 'immediate',

            'instructions' => 'Disposable acceptance paper. Verify the workflow, then delete it.',
            'start_datetime' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_datetime' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'workflow_state' => 'draft',
        ]);

        $response->assertSessionHasNoErrors();

        $exam = OnlineExam::where('title', self::TITLE)->latest('id')->firstOrFail();

        fwrite(STDERR, "[acceptance] created exam {$exam->id}.\n");

        $this->ensureQuestions((int) $exam->id);

        return (int) $exam->id;
    }

    private function ensureQuestions(int $examId): void
    {
        $lecturer = User::findOrFail(self::LECTURER_ID);

        // `stored` is the name the model NORMALISES to; `submitted` is what the route
        // accepts. They are not the same, and comparing one against the other is how a
        // second run adds a duplicate of every question it thinks is missing:
        // `multiple_choice` is stored as `mcq`, `short_answer` as `short`.
        $questions = [
            [
                'stored' => 'mcq',
                'submitted' => 'multiple_choice',
                'payload' => [
                    'question' => '<p>What is the formula for compound interest?</p>',
                    'option_a' => 'A = P(1 + r/n)^(nt)',
                    'option_b' => 'A = Prt',
                    'option_c' => 'A = P + rt',
                    'option_d' => 'A = P(1 + r)^t',
                    'correct_ans' => 'a',
                ],
            ],
            [
                'stored' => 'true_false',
                'submitted' => 'true_false',
                'payload' => [
                    'question' => '<p>Demand falls as price rises.</p>',
                    'correct_answer_tf' => 'true',
                ],
            ],
            [
                'stored' => 'essay',
                'submitted' => 'essay',
                'payload' => [
                    'question' => '<p>Explain, with a worked example, how a <strong>price ceiling</strong> affects a market.</p>',
                ],
            ],
            [
                'stored' => 'short',
                'submitted' => 'short_answer',
                'payload' => [
                    'question' => '<p>State the compound interest formula.</p>',
                ],
            ],
        ];

        // Matched on the TEXT as well as the type, so a question that exists under a
        // different type name is still recognised rather than added again.
        $present = DB::table('online_exam_questions')
            ->where('online_exam_id', $examId)
            ->get()
            ->map(fn ($row) => $row->type.'|'.trim(strip_tags((string) $row->question)))
            ->all();

        foreach ($questions as $question) {
            $key = $question['stored'].'|'.trim(strip_tags($question['payload']['question']));

            if (in_array($key, $present, true)) {
                continue;
            }

            $this->actingAs($lecturer)
                ->post(route('teacher.online_exams.questions.store', $examId), array_merge(
                    ['type' => $question['submitted'], 'marks' => 5],
                    $question['payload']
                ))
                ->assertSessionHasNoErrors();

            fwrite(STDERR, "[acceptance] added {$question['submitted']} question to exam {$examId}.\n");
        }
    }
}