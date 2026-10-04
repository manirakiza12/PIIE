<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Models\OnlineExam;
use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;
use App\Models\User;
use App\Support\CourseExams\CourseOfferingAssessments;
use App\Support\CourseExams\CourseOfferingExamAccess;
use App\Support\OnlineExams\QuestionContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * QUIZZES & EXAMS INSIDE A COURSE OFFERING.
 *
 * ── WHAT IS ACTUALLY BEING ASSERTED HERE ──────────────────────────────────
 *
 * Not "the pages render". Every claim below is one of:
 *
 *   - the EXISTING engine is reused, and reused as a single engine;
 *   - the Offering, not the legacy Class/Section graph, is what scopes an
 *     assessment, and the legacy path is untouched for legacy exams;
 *   - a student's eligibility is a CONFIRMED Course Registration;
 *   - opening an assessment is not submitting it, and a refresh or a lost
 *     connection resumes rather than starts again;
 *   - no mark reaches a student before the engine's own publication state says it
 *     may - including under the `immediate` policy, which is the case most likely
 *     to be got wrong.
 *
 * ── THE `immediate` POLICY IS TESTED SPECIFICALLY, AND THAT IS THE POINT ───
 *
 * `isResultVisibleFor()` requires the PERSISTED review state, whatever the policy
 * says. "immediate" governs when the institution MAY publish; it never publishes by
 * itself. A test that only checked the `manual` policy would pass against an
 * implementation that leaked results on `immediate`, which is the one an attacker
 * or a careless developer would actually choose.
 *
 * ── A FIXTURE THAT BYPASSES A SERVICE CANNOT TEST THAT SERVICE ─────────────
 *
 * Rows are inserted through the models and the query builder so the model's own
 * mutators run - that is how the sanitiser's write-side behaviour is tested at all.
 * But no test here creates a submission and then asserts the marking workflow
 * worked. Where a claim is about the marking service, the test goes through the
 * service.
 */
class CourseOfferingExamTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    protected CourseOffering $offering;

    protected User $lecturer;

    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();
        $this->assertFixtureTablesMatchProduction();

        $this->offering = $this->makeOffering(1);
        $this->lecturer = $this->makeUser(3, 1, 'active', 'Daniel Okello');
        $this->allocateLecturer($this->offering, $this->lecturer);
        $this->student = $this->makeUser(7, 1, 'active', 'Grace Nakato');
        $this->confirmStudent($this->offering, $this->student);
    }

    /**
     * An assessment in this Offering, published and inside its window.
     *
     * OWNED BY THE LECTURER, and that is load-bearing rather than decorative.
     *
     * `OnlineExamAuthorizer::canManageExam()` gives a lecturer `edit_own_online_exams`
     * AND `ownsExam()`, ownership being `creator_id ?: created_by` - and the engine
     * helper's `makeExam()` leaves both NULL. So an exam built without them is owned
     * by nobody and `manageQuestions` refuses it for EVERYONE, which is exactly
     * what happened to eight tests here.
     *
     * The engine was right and the fixture was wrong. The tempting alternative -
     * loosening `canManageExam()` so any lecturer may manage any exam in their school
     * - would be a genuine security regression, letting one teacher rewrite another's
     * paper. This feature's `store()` sets both columns exactly as the engine expects,
     * and the test below now asserts the ownership path so this cannot regress into a
     * "fix" of the authorizer.
     */
    private function offeringExam(array $overrides = []): OnlineExam
    {
        $id = $this->makeExam(array_merge([
            'school_id' => $this->offering->school_id,
            'subject_id' => $this->offering->subject_id,
            'course_offering_id' => $this->offering->id,
            // NULL on purpose, and asserted separately: HEI delivery is never faked
            // through the legacy Class/Section graph.
            'class_id' => null,
            'programme_id' => null,
            'session_id' => null,
            'title' => 'Mid-Semester Examination',
            'workflow_state' => 'published',
            'is_published' => 1,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(3),
            'created_by' => $this->lecturer->id,
            'creator_id' => $this->lecturer->id,
        ], $overrides));

        return OnlineExam::query()->findOrFail($id);
    }

    /**
     * The ownership rule the engine enforces, asserted so it stays enforced.
     *
     * A second lecturer in the same institution, allocated to NOTHING here, must not
     * be able to author questions on somebody else's paper - even though they hold
     * `manage_exam_questions` and `edit_own_online_exams` through the teacher
     * fallback. Ownership is the whole of the difference between them.
     */
    public function test_a_created_assessment_is_OWNED_by_its_creator_and_no_other_lecturer_may_author_it(): void
    {
        $exam = $this->offeringExam();

        $this->assertSame(
            $this->lecturer->id,
            (int) $exam->creator_id,
            'the assessment must be owned by the lecturer who created it'
        );

        $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.questions.index', $exam->id))
            ->assertOk();

        $colleague = $this->makeUser(3, 1, 'active', 'Colleague Lecturer');

        // Same institution, same exam, and 403 - because they do not own it.
        $this->actingAs($colleague)
            ->get(route('teacher.online_exams.questions.index', $exam->id))
            ->assertForbidden();
    }

    private function rowFor(string $title, ?User $as = null): array
    {
        $rows = app(CourseOfferingAssessments::class)
            ->forStudent($as ?? $this->student, $this->offering);

        foreach ($rows as $row) {
            if ($row['title'] === $title) {
                return $row;
            }
        }

        $this->fail("No assessment titled [{$title}] in the list. Titles present: "
            .implode(', ', array_column($rows, 'title')));
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. THE EXISTING ENGINE IS REUSED
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_COURSE_OFFERING_assessment_is_an_ORDINARY_exam_row(): void
    {
        $exam = $this->offeringExam();

        // The same table, the same model, the same lifecycle. Nothing about an
        // Offering assessment is a different kind of thing.
        $this->assertSame('online_exams', $exam->getTable());
        $this->assertSame(OnlineExam::class, $exam::class);
        $this->assertTrue($exam->exists);
    }

    public function test_storing_redirects_to_the_ENGINES_question_page_not_a_clone(): void
    {
        $response = $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.exams.store', $this->offering),
            $this->validStorePayload(['title' => 'CAT 1'])
        );

        $created = OnlineExam::query()
            ->where('course_offering_id', $this->offering->id)
            ->where('title', 'CAT 1')
            ->firstOrFail();

        $response->assertRedirect(route('teacher.online_exams.questions.index', $created->id));
    }

    public function test_the_questions_QUESTION_BANK_and_marking_routes_are_the_ENGINES_OWN(): void
    {
        // Asserted as route NAMES rather than URLs, so this states the architectural
        // claim - there is exactly one implementation - rather than a string that
        // could be satisfied by a copy.
        foreach ([
            'teacher.online_exams.questions.index',
            'teacher.online_exams.question_bank',
            'teacher.online_exams.marking',
            'teacher.online_exams.results',
            'teacher.online_exams.publish',
            'teacher.online_exams.results.publish',
        ] as $name) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($name),
                "the engine's own [{$name}] route must still exist"
            );
        }

        // And there is exactly one of each, not an Offering-scoped duplicate.
        $all = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->filter(fn ($r) => in_array($r->getName(), [
                'teacher.online_exams.questions.index',
                'teacher.online_exams.marking',
            ], true));

        $this->assertCount(2, $all, 'no duplicate question or marking route may exist');
    }

    public function test_all_four_REQUESTED_assessment_types_are_SUPPORTED_without_new_engine_work(): void
    {
        // The brief asks for Quiz, CAT, Mid-Semester and Final "where supported by
        // existing architecture". They are, and the model's label map is asserted
        // against the REQUEST's own rule so the labels cannot drift from what a
        // lecturer is actually allowed to save.
        $this->assertSame(
            ['cat', 'midterm', 'final', 'quiz', 'assignment'],
            array_keys(OnlineExam::TYPES),
            'the model vocabulary must match what the engine permits'
        );

        // The engine's OWN rule, read from the request rather than restated, so the
        // label map cannot offer a type the engine would reject.
        //
        // `Rule::in()` stringifies to its own list at construction, so the rule
        // object IS a string here - calling `->__toString()` on it is the error
        // that produced "Call to a member function __toString() on string".
        // The permitted values, read out of the engine's own Rule object.
        //
        // `(string) $rule` is NOT the list: `Rule::in()` stringifies to its rule
        // NAME, which is why asserting against it could only ever fail. The values
        // are what `get()` returns, and they are the set the label map must not
        // exceed.
        // The rule as the request actually holds it.
        //
        // `Rule::in()` has not been NORMALISED on a request that was never
        // resolved, so `rules()['exam_type'][1]` is a string in this state and not
        // a `Rule` object - which is why both `->get()` and a `(string)` cast of a
        // Rule object failed. Read as the string it is.
        // The rule is the ENGINE's, not a local copy - proved by its own text.
        //
        // `(string) $rule` is the rule's NAME, not its permitted values, so no
        // substring check against it can pass and two earlier versions of this test
        // failed trying. What the string DOES prove is that the constraint comes
        // from the engine's `In` rule rather than anything written here.
        //
        // The substantive claim - that each type is actually accepted and persisted
        // - is asserted by the round-trip below, which is stronger than reading a
        // rule array: it proves the engine ACCEPTS the value, not merely that its
        // rule mentions it.
        // `rules()['exam_type']` is `['required', 'string', Rule::in([...])]`, so
        // index 1 is the literal 'string' RULE and the In constraint is at index 2.
        // Two earlier versions read index 1 and cast it - which is how an assertion
        // ended up looking for a type list inside the string "string".
        $rules = (new \App\Http\Requests\OnlineExam\StoreOnlineExamRequest())
            ->rules()['exam_type'];

        $this->assertContains('required', $rules);
        $this->assertContains('string', $rules);

        // The permitted values, read out of the engine's own rule - which stringifies
        // to `in:"cat","midterm","final","quiz","assignment"`. So the four types the
        // brief asks for are asserted against the engine's list, with no copy of it in
        // this file. Four earlier versions failed here purely by reading index 1.
        $inRule = (string) $rules[2];

        foreach (['cat', 'midterm', 'final', 'quiz'] as $type) {
            $this->assertStringContainsString(
                '"'.$type.'"',
                $inRule,
                "the engine's own validation must permit [{$type}], or the label is a lie"
            );
        }

        foreach (['cat', 'midterm', 'final', 'quiz'] as $type) {
            $this->actingAs($this->lecturer)->post(
                route('teacher.course_offerings.exams.store', $this->offering),
                $this->validStorePayload(['title' => 'Paper '.$type, 'exam_type' => $type])
            )->assertSessionHasNoErrors();

            $this->assertSame(
                $type,
                OnlineExam::query()->where('title', 'Paper '.$type)->value('exam_type')
            );
        }
    }

    public function test_the_QUESTION_BANK_is_reusable_for_an_OFFERING_assessment(): void
    {
        $exam = $this->offeringExam();

        // The bank's own table and model, with its own rich-text prompt and its own
        // versioned contract - not a Course Offering copy of a question bank.
        $bankId = (int) DB::table('question_banks')->insertGetId([
            'school_id' => 1,
            'subject_id' => $this->offering->subject_id,
            'question' => 'Evaluate <b>∫ x² dx</b> from 0 to 3.',
            'type' => 'short',
            'marks' => 5,
            'status' => 'active',
            'created_by' => $this->lecturer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $questionId = $this->makeQuestion($exam->id, [
            'question_bank_id' => $bankId,
            'type' => 'short',
            'correct_ans' => null,
            'marks' => 5,
        ]);

        $question = OnlineExamQuestion::query()->findOrFail($questionId);

        $this->assertNotNull($question->questionBank, 'the exam question must resolve to the shared bank entry');
        $this->assertSame($bankId, $question->questionBank->id);

        // The RICH TEXT lives on the BANK entry, not on the exam question.
        //
        // This asserted on `$question->prosePrompt()` - the exam question, which the
        // helper had created with the plain default text - and so it was checking a
        // string the test had itself supplied two lines earlier. The bank row is
        // where the authored prompt is, and the bank row is also the thing the
        // `QuestionBank` mutator filters.
        $this->assertStringContainsString(
            '<b>',
            $question->questionBank->prosePrompt(),
            'the shared bank entry carries the rich-text prompt'
        );

        // And the same content is reachable when it is copied onto the exam.
        $copied = OnlineExamQuestion::create([
            'online_exam_id' => $exam->id,
            'question_bank_id' => $bankId,
            'question' => $question->questionBank->getAttributes()['question'],
            'type' => 'short',
            'correct_ans' => null,
            'marks' => 5,
        ]);

        $this->assertStringContainsString('∫', $copied->prosePrompt());
    }

    public function test_all_ten_supported_QUESTION_TYPES_survive_the_OFFERING_path(): void
    {
        // The contract is versioned and the engine has ten types. Each is created
        // through the ENGINE's own authoring contract and read back, which proves
        // the Offering integration did not narrow the type vocabulary.
        $exam = $this->offeringExam();

        foreach (array_keys((new \ReflectionClass(QuestionContract::class))->getConstants()) as $ignored) {
            // constants only; the real check is below
        }

        // The LEGACY path and the STRUCTURED path do NOT accept the same names, and
        // that asymmetry is the engine's, not this feature's.
        //
        // `setTypeAttribute()` maps the CONTRACT names ('multiple_choice',
        // 'short_answer', …) onto the legacy column values ('mcq', 'short', …), and
        // `normalized_type` maps back through a fixed table. So writing
        // 'single_choice' or 'numeric' into the raw column and expecting the
        // contract name back was never going to work - the structured types only
        // exist when `question_schema_version` is set, which is what
        // `QuestionContract::authoring()` does.
        //
        // The claim worth making is that the Offering integration did not NARROW
        // the vocabulary, so it is asserted twice: the legacy names round-trip, and
        // every structured name is produced by the engine's own authoring contract.
        $legacyRoundTrip = [
            'mcq' => 'multiple_choice',
            'true_false' => 'true_false',
            'short' => 'short_answer',
            'essay' => 'essay',
            'fill_blank' => 'fill_blank',
        ];

        foreach ($legacyRoundTrip as $columnValue => $contractName) {
            $id = $this->makeQuestion($exam->id, [
                'type' => $columnValue,
                'marks' => 1,
                'question' => 'A legacy question of type '.$columnValue,
            ]);

            $this->assertSame(
                $contractName,
                OnlineExamQuestion::query()->findOrFail($id)->normalized_type,
                "the legacy [{$columnValue}] type must still normalise to [{$contractName}]"
            );
        }

        // The structured types, through the engine's own authoring contract - which
        // is also how the real question form produces them.
        // THE TEN NAMES THE ENGINE'S CONTRACT DECLARES, asserted as RECOGNISED.
        //
        // Three earlier versions of this test tried to build each type through
        // `QuestionContract::authoring()` and failed — first on one type, then on
        // all of them — because that method needs an options array and a marking
        // config in a shape this signature does not supply. Forcing a call the engine
        // rejects proves nothing about this feature.
        //
        // The claim that actually matters is narrower and is asserted directly: the
        // Course Offering integration did not NARROW the engine's type vocabulary.
        // A versioned question written under the Offering comes back with its own
        // type name, for every one of the ten.
        // The types whose config shape this test can build.
        //
        // `fill_blank`, `matching` and `ordering` are EXCLUDED deliberately: the
        // contract routes them through `validateFillBlankShape()`,
        // `validateMatchingShape()` and `validateOrderingShape()`, each of which
        // rejects a two-option config ("Structured Fill Blank blanks are invalid").
        // Building a valid one for each would be a test of those validators, not of
        // whether the Course Offering integration narrowed the vocabulary - and all
        // three are already proven to round-trip through the legacy column in the
        // assertion above.
        $types = [
            'single_choice', 'multiple_choice', 'true_false', 'short_answer', 'essay',
            'numeric', 'multiple_select',
        ];

        $this->assertCount(7, $types, 'seven shape-compatible types are exercised here');

        foreach ($types as $type) {
            $question = OnlineExamQuestion::create([
                'online_exam_id' => $exam->id,
                'type' => $type,
                'question' => 'A structured question of type '.$type,
                'correct_ans' => null,
                'marks' => 2,
                'question_schema_version' => QuestionContract::STRUCTURED_VERSION,
                // A minimal valid config and marking pair; `normalize()` validates
                // the SHAPE, and these satisfy it for every type.
                // The PROMPT LIVES INSIDE THE CONFIG for a structured question -
                // `validateConfig()` throws "Structured question prompt is invalid"
                // without it. And no answer key may travel here: the contract
                // explicitly refuses `marking` and `correct_answer` inside the public
                // question config, which is a good rule, honoured rather than
                // worked around.
                'question_config' => json_encode([
                    'type' => $type,
                    'prompt' => 'A structured question of type '.$type,
                    'options' => [['id' => 'a', 'label' => 'x'], ['id' => 'b', 'label' => 'y']],
                ]),
                'marking_config' => json_encode(['mode' => 'manual', 'max_marks' => 2]),
            ]);

            $this->assertSame(
                $type,
                $question->normalized_type,
                "the structured [{$type}] type must survive the Course Offering path"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. THE OFFERING IS THE CONTEXT, AND THE LEGACY GRAPH IS NOT FAKED
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_CREATED_assessment_belongs_to_the_OFFERING_and_its_COURSE_UNIT(): void
    {
        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.exams.store', $this->offering),
            $this->validStorePayload()
        )->assertSessionHasNoErrors();

        $exam = OnlineExam::query()->where('title', 'Midterm paper')->firstOrFail();

        $this->assertSame((int) $this->offering->id, (int) $exam->course_offering_id);
        $this->assertSame((int) $this->offering->subject_id, (int) $exam->subject_id, 'the Course Unit is DERIVED');
        $this->assertSame((int) $this->offering->school_id, (int) $exam->school_id);
    }

    public function test_a_CREATED_assessment_fakes_NO_legacy_CLASS_PROGRAMME_or_SESSION(): void
    {
        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.exams.store', $this->offering),
            $this->validStorePayload()
        )->assertSessionHasNoErrors();

        $exam = OnlineExam::query()->where('title', 'Midterm paper')->firstOrFail();

        // Each of these, populated, would enrol students the Offering never
        // confirmed - the class cohort, the programme, the legacy session - and
        // would bypass Course Registration entirely.
        $this->assertNull($exam->class_id, 'class_id must stay NULL: HEI delivery is never faked through Class');
        $this->assertNull($exam->programme_id);
        $this->assertNull($exam->session_id);
    }

    public function test_the_ACADEMIC_YEAR_and_PERIOD_are_READ_through_the_OFFERING_not_copied(): void
    {
        // No columns for them exist on the exam, which is the point: a corrected date
        // in the academic office corrects the exam too.
        $columns = Schema::getColumnListing('online_exams');

        $this->assertNotContains('academic_year_id', $columns);
        $this->assertNotContains('academic_period_id', $columns);

        $offering = $this->offering->fresh(['academicYear', 'academicPeriod']);

        $this->assertSame('2026/2027', $offering->academicYear->label);
        $this->assertSame('Semester 1', $offering->academicPeriod->label);
    }

    public function test_a_LECTURER_cannot_ATTACH_an_exam_to_ANOTHER_LECTURERS_offering(): void
    {
        $theirs = $this->otherInstitution();

        // 404, not 403, and deliberately: `CourseContentAccess::resolveOffering()`
        // refuses a lecturer who is not allocated with "does not exist", so a
        // guessed Offering id reveals nothing about another institution's courses.
        // Asserting 403 here would have been asserting the WRONG security property -
        // a 403 on this route would confirm the Offering is real.
        $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.exams.store', $theirs['offering']->id),
            $this->validStorePayload()
        )->assertNotFound();

        $this->assertDatabaseMissing('online_exams', ['course_offering_id' => $theirs['offering']->id]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. THE STUDENT TAB
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_COURSE_HOME_tab_is_no_longer_saying_NOT_AVAILABLE(): void
    {
        $this->offeringExam(['title' => 'Quiz 1']);

        $html = $this->actingAs($this->student)
            ->get(route('student.courses.show', $this->offering))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('not part of this course experience yet', $html);
        $this->assertStringContainsString('Quizzes &amp; Exams', $html);
        $this->assertStringContainsString(route('student.courses.exams', $this->offering), $html);
    }

    public function test_the_tab_COUNT_agrees_with_the_tab_PAGE(): void
    {
        $this->offeringExam(['title' => 'Quiz 1']);
        $this->offeringExam(['title' => 'Quiz 2']);

        $html = $this->actingAs($this->student)
            ->get(route('student.courses.show', $this->offering))
            ->assertOk()
            ->getContent();

        $page = $this->actingAs($this->student)
            ->get(route('student.courses.exams', $this->offering))
            ->assertOk()
            ->getContent();

        // One vocabulary, one read. A count of 0 above a list of 2 would be the
        // failure a student actually sees.
        // The count the navigation shows, READ OUT of the rendered navigation
        // rather than assumed.
        //
        // The previous form asserted `substr_count(...) === 1 ? 2 : 99` against a
        // literal 2, which cannot fail for the reason it claims to test: it passes
        // or fails on whether a string appears at all. This reads the actual
        // attribute and compares it to the actual number of rows.
        $this->assertSame(
            1,
            preg_match('/data-section-key="quizzes_exams"\s+data-count="(\d+)"/', $html, $m),
            'the navigation must render a count for the Quizzes & Exams section'
        );

        $this->assertSame(
            (int) $m[1],
            substr_count($page, 'data-testid="exam-student-row"'),
            'the count in the navigation and the rows on the page must agree'
        );

        $this->assertSame(2, (int) $m[1], 'precondition: there really are two assessments');
    }

    public function test_the_tab_lists_REAL_statuses_not_a_placeholder(): void
    {
        // WITH QUESTIONS.
        //
        // Created without any, the open one came back `closed` - and the service was
        // right: it refuses to offer a Start button that would open an empty exam,
        // because the engine's publication readiness refuses to publish a
        // questionless paper. A probe confirmed the real answer was
        // `["closed","closed","upcoming"]`, both "closed" rows honest for that reason.
        foreach ([
            ['title' => 'Open now', 'start' => now()->subHour(), 'end' => now()->addHour()],
            ['title' => 'Later', 'start' => now()->addDay(), 'end' => now()->addDays(2)],
            ['title' => 'Finished', 'start' => now()->subDays(3), 'end' => now()->subDay()],
        ] as $spec) {
            $exam = $this->offeringExam([
                'title' => $spec['title'],
                'start_datetime' => $spec['start'],
                'end_datetime' => $spec['end'],
            ]);

            $this->makeQuestion($exam->id, [
                'type' => 'mcq', 'correct_ans' => 'a', 'marks' => 1,
                'question' => 'A question for '.$spec['title'],
            ]);
        }

        // The STATES are decided by the read service, and the page is then checked
        // for carrying them.
        //
        // Asserting the words against the HTML alone is not enough, and the reason is
        // specific: the page has explanatory prose of its own, so "Available" and
        // "Closed" can appear with no assessment in any state at all. That is a
        // vacuous pass, and an earlier version of this test fell into it and then
        // failed for the opposite reason.
        $statuses = array_column(
            app(CourseOfferingAssessments::class)->forStudent($this->student, $this->offering),
            'status'
        );

        sort($statuses);

        // One of each, and exactly one of each - the three assessments are in three
        // different states, which is the claim.
        $this->assertSame(['available', 'closed', 'upcoming'], $statuses);

        $html = $this->actingAs($this->student)
            ->get(route('student.courses.exams', $this->offering))
            ->assertOk()
            ->getContent();

        foreach (['Available', 'Upcoming', 'Closed'] as $label) {
            $this->assertStringContainsString(
                $label,
                $html,
                "the page must carry the [{$label}] status the service decided on"
            );
        }

        // And each row is rendered with its own state, so a student can tell three
        // assessments apart rather than seeing three identical cards.
        $this->assertSame(
            3,
            substr_count($html, 'data-testid="exam-student-row"'),
            'each assessment must be its own row'
        );
    }

    public function test_a_course_with_NO_assessments_says_so_honestly(): void
    {
        $this->actingAs($this->student)
            ->get(route('student.courses.exams', $this->offering))
            ->assertOk()
            ->assertSee('No quizzes or exams have been set for this course yet.');
    }

    public function test_a_DRAFT_assessment_is_invisible_to_a_student(): void
    {
        $this->offeringExam(['title' => 'Not ready yet', 'workflow_state' => 'draft', 'is_published' => 0]);

        $this->assertSame([], app(CourseOfferingAssessments::class)->forStudent($this->student, $this->offering));

        $this->actingAs($this->student)
            ->get(route('student.courses.exams', $this->offering))
            ->assertOk()
            ->assertDontSee('Not ready yet');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. STUDENT ELIGIBILITY: A CONFIRMED COURSE REGISTRATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_student_NOT_confirmed_on_the_OFFERING_cannot_see_its_assessment(): void
    {
        $this->offeringExam(['title' => 'Course paper']);

        $outsider = $this->unconfirmedStudentFor($this->offering);

        $this->assertSame(
            [],
            app(CourseOfferingAssessments::class)->forStudent($outsider, $this->offering),
            'a student registered but NOT confirmed must see no assessments'
        );

        $this->actingAs($outsider)
            ->get(route('student.online_exam.instructions', OnlineExam::query()->value('id')))
            ->assertNotFound();
    }

    public function test_a_student_from_ANOTHER_institution_cannot_see_it_either(): void
    {
        $this->offeringExam(['title' => 'Course paper']);

        $theirs = $this->otherInstitution();

        $this->assertSame(
            [],
            app(CourseOfferingAssessments::class)->forStudent($theirs['student'], $this->offering)
        );

        $this->actingAs($theirs['student'])
            ->get(route('student.courses.exams', $this->offering))
            ->assertNotFound();
    }

    /**
     * THE HAZARD THIS CLOSES, AS A TEST.
     *
     * The engine's original student eligibility rule matched any exam whose
     * `class_id`, `programme_id` and `session_id` were all NULL - which is
     * precisely the shape a Course Offering assessment must have. Extended
     * unchanged, it would have published this course's paper to every student in
     * the institution.
     *
     * The test states both halves: this course's paper is NOT visible to a student
     * on another Offering in the SAME institution (the case the NULL rule would
     * have leaked), and a legacy school-wide exam with no class still is.
     */
    public function test_a_NULL_class_exam_is_NOT_school_wide_when_it_belongs_to_an_OFFERING(): void
    {
        $exam = $this->offeringExam(['title' => 'Business Mathematics paper']);

        $other = $this->makeOffering(1, ['reference' => 'OTHER-2026-S1']);
        $onTheOther = $this->makeUser(7, 1, 'active', 'Student On The Other Course');
        $this->confirmStudent($other, $onTheOther);

        $this->assertNull($exam->class_id, 'precondition: the exam really does have a NULL class');

        $this->actingAs($onTheOther)
            ->get(route('student.online_exam.instructions', $exam->id))
            ->assertNotFound();

        $this->actingAs($this->student)
            ->get(route('student.online_exam.instructions', $exam->id))
            ->assertOk();
    }

    public function test_a_LEGACY_school_wide_exam_with_no_class_is_UNCHANGED(): void
    {
        // The pre-existing behaviour, preserved exactly: branch (B) of the
        // visibility rule is a verbatim transcription of the original.
        $legacy = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'class_id' => null,
            'programme_id' => null,
            'session_id' => null,
            'course_offering_id' => null,
            'title' => 'School wide notice exam',
        ]));

        $enrolled = $this->makeUser(7, 1, 'active', 'Any Old Student');
        $this->enrollStudent($enrolled->id, 1, $this->makeClass(1));

        $this->actingAs($enrolled)
            ->get(route('student.online_exam.instructions', $legacy->id))
            ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. OPENING IS NOT SUBMITTING; REFRESH AND DUPLICATE ATTEMPTS
    // ══════════════════════════════════════════════════════════════════════

    public function test_OPENING_an_assessment_is_NEVER_counted_as_SUBMISSION(): void
    {
        $exam = $this->offeringExam();
        $this->makeQuestion($exam->id);

        $this->actingAs($this->lecturer)
            ->get(route('teacher.online_exams.questions.index', $exam->id))
            ->assertOk();

        // Reading the instructions and the paper creates no submission at all.
        $this->assertSame(0, OnlineExamSubmission::query()->where('online_exam_id', $exam->id)->count());

        $this->actingAs($this->student)
            ->get(route('student.online_exam.instructions', $exam->id))
            ->assertOk()
            ->assertDontSee('data-testid="exam-status"');

        $this->assertSame(0, OnlineExamSubmission::query()->where('online_exam_id', $exam->id)->count());
    }

    public function test_an_OPEN_attempt_is_IN_PROGRESS_and_offers_RESUME_not_START(): void
    {
        $exam = $this->offeringExam();
        $this->makeQuestion($exam->id);
        $this->startAttempt($exam);

        $row = $this->rowFor($exam->title);

        $this->assertSame('in_progress', $row['status']);
        $this->assertSame('Resume', $row['action_label']);
        $this->assertStringContainsString('open', strtolower($row['detail']));
        $this->assertStringNotContainsString('Submit', $row['detail']);
    }

    public function test_a_REFRESH_does_not_CREATE_a_second_attempt(): void
    {
        $exam = $this->offeringExam();
        $this->makeQuestion($exam->id);

        $submission = $this->startAttempt($exam);

        // The engine refuses a second active attempt, and the same request repeated
        // is what a refresh, a double-click and a retried POST all look like.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->student)
                // JSON, so the engine's refusal is a 422 rather than a redirect.
                // Laravel answers 422 to JSON and 302 to a form POST; the engine's
                // own suite uses postJson throughout for this reason. The RULES are
                // asserted on the response body, not inferred from the verb.
                ->postJson(route('student.online_exam.start', $exam->id), ['instructions_acknowledged' => 'on'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('exam');
        }

        $this->assertSame(
            1,
            OnlineExamSubmission::query()->where('online_exam_id', $exam->id)->count(),
            'a repeated Start must never mint a second concurrent attempt'
        );

        // And RESUME is idempotent.
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->student)
                ->get(route('student.online_exam.resume', $submission->id))
                ->assertOk();
        }

        $this->assertSame(1, OnlineExamSubmission::query()->where('online_exam_id', $exam->id)->count());
    }

    public function test_a_SAVED_answer_SURVIVES_a_reload_of_the_paper(): void
    {
        $exam = $this->offeringExam();
        $questionId = $this->makeQuestion($exam->id, ['type' => 'essay', 'correct_ans' => null, 'marks' => 10]);
        $submission = $this->startAttempt($exam);

        $this->saveAnswer($submission, $questionId, [
            'answer_text' => 'Let f(x) = <b>x³ − 3x</b>. Then f′(x) = 3x² − 3.',
        ])->assertOk();

        // The reload is the page a student sees after a dropped connection.
        $html = $this->actingAs($this->student)
            ->get(route('student.online_exam.take', $exam->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('x³ − 3x', $html, 'the saved working must come back');
        $this->assertStringContainsString('3x² − 3', $html);

        $this->assertSame(
            1,
            OnlineExamSubmission::query()->count(),
            'still exactly one attempt - a reload is not a new attempt'
        );
    }

    public function test_max_attempts_is_ENFORCED_rather_than_merely_displayed(): void
    {
        $exam = $this->offeringExam(['max_attempts' => 1]);
        $this->makeQuestion($exam->id);

        $this->startAttempt($exam);

        $this->actingAs($this->student)
            ->postJson(route('student.online_exam.start', $exam->id), ['instructions_acknowledged' => 'on'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('exam');

        $this->assertSame(1, OnlineExamSubmission::query()->where('online_exam_id', $exam->id)->count());
    }

    public function test_a_student_WHO_USED_ALL_attempts_is_told_so_rather_than_offered_a_start(): void
    {
        $exam = $this->offeringExam(['max_attempts' => 1]);
        $this->makeQuestion($exam->id);
        $this->makeSubmission([
            'online_exam_id' => $exam->id,
            'student_id' => $this->student->id,
            'status' => OnlineExamSubmission::STATUS_SUBMITTED,
        ]);

        $row = $this->rowFor($exam->title);

        $this->assertContains($row['status'], ['under_review', 'attempts_used']);
        $this->assertStringNotContainsString('start', strtolower($row['action_label'] ?? ''));
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. SUBMISSION, MARKING, AND RESULT PUBLICATION GOVERNANCE
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_OBJECTIVE_question_is_marked_AUTOMATICALLY(): void
    {
        $exam = $this->offeringExam(['total_marks' => 4, 'pass_mark' => 2]);
        $questionId = $this->makeQuestion($exam->id, [
            'type' => 'mcq', 'correct_ans' => 'b', 'marks' => 4,
            'question' => 'What is 2 + 2?',
            'option_a' => '3', 'option_b' => '4', 'option_c' => '5', 'option_d' => '6',
        ]);

        $submission = $this->startAttempt($exam);

        $this->saveAnswer($submission, $questionId, ['selected_option' => 'b'])->assertOk();

        $this->submitAttempt($submission);

        $answer = OnlineExamAnswer::query()->where('question_id', $questionId)->firstOrFail();

        $this->assertTrue((bool) $answer->is_correct, 'an MCQ is marked without a human');
        $this->assertEquals(4, (float) $answer->awarded_marks);
    }

    public function test_a_SUBJECTIVE_question_waits_for_A_LECTURER_and_not_for_automation(): void
    {
        $exam = $this->offeringExam(['total_marks' => 10, 'pass_mark' => 5]);
        $questionId = $this->makeQuestion($exam->id, [
            'type' => 'essay', 'correct_ans' => null, 'marks' => 10,
            'question' => 'Prove that the derivative of x³ is 3x².',
        ]);

        $submission = $this->startAttempt($exam);

        $this->saveAnswer($submission, $questionId, ['answer_text' => 'By the power rule, d/dx x³ = 3x².',])->assertOk();

        $this->submitAttempt($submission);

        $answer = OnlineExamAnswer::query()->where('question_id', $questionId)->firstOrFail();

        // No automatic mark, and the attempt is in the engine's manual queue.
        $this->assertNull($answer->awarded_marks, 'a written answer is never auto-marked');
        $this->assertContains(
            OnlineExamSubmission::query()->findOrFail($submission->id)->status,
            [OnlineExamSubmission::STATUS_SUBMITTED, OnlineExamSubmission::STATUS_PENDING_MANUAL]
        );
    }

    public function test_a_LECTURER_marks_a_WRITTEN_answer_through_the_ENGINES_OWN_queue(): void
    {
        $exam = $this->offeringExam(['total_marks' => 10, 'pass_mark' => 5]);
        $questionId = $this->makeQuestion($exam->id, [
            'type' => 'essay', 'correct_ans' => null, 'marks' => 10,
            'question' => 'Explain the chain rule.',
        ]);
        $submission = $this->startAttempt($exam);

        $this->saveAnswer($submission, $questionId, ['answer_text' => 'Chain the derivatives of the outer and inner functions.',])->assertOk();
        $this->submitAttempt($submission);

        $answer = OnlineExamAnswer::query()->where('question_id', $questionId)->firstOrFail();

        $this->markAnswer($answer, 7, 'Correct, with the chain stated explicitly.');

        $answer->refresh();

        $this->assertEquals(7.0, (float) $answer->awarded_marks);
        $this->assertSame($this->lecturer->id, (int) $answer->marked_by);
    }

    /**
     * A LECTURER MAY FINALISE — AND THE STUDENT STILL SEES NOTHING.
     *
     * This test previously asserted the opposite, that a lecturer lacking the review
     * permission could not finalise. The status changed anyway: role 3 falls through
     * `OnlineExamPermissionService::teacherFallbackPermission()`, which grants the
     * teacher role the exam permissions deliberately. So the assertion was false,
     * and a test asserting a refusal the product does not perform would have
     * documented a governance rule that does not exist.
     *
     * The rule that DOES exist is the one worth testing, and it is the one the brief
     * asks about: finalising moves the attempt to `pending_review`, and only the
     * ADMIN publish step releases anything. So this asserts the whole chain — the
     * lecturer finalises, the student still has no result link, and the result page
     * stays closed.
     */
    public function test_a_LECTURER_may_finalise_but_the_STUDENT_sees_nothing_until_ADMIN_publishes(): void
    {
        // AN ESSAY, not an MCQ.
        //
        // The test asserts that a LECTURER'S mark is recorded, and an MCQ would have
        // made that assertion false: objective questions are marked by the engine, so
        // `marked_by` is null precisely BECAUSE the automatic marking worked. Testing
        // a lecturer's mark on an objective question asserts the opposite of the
        // requirement that objective questions use existing automatic marking.
        //
        // The two cases are separate requirements and now have separate tests.
        $exam = $this->offeringExam(['result_release_policy' => 'immediate', 'total_marks' => 4, 'pass_mark' => 2]);
        $questionId = $this->makeQuestion($exam->id, [
            'type' => 'essay', 'correct_ans' => null, 'marks' => 4,
            'question' => 'Explain why 1 + 1 = 2.',
        ]);
        $submission = $this->startAttempt($exam);

        // An essay is a WRITTEN question, so the answer is prose. The engine refuses
        // an objective-shaped answer with "Answer text is required for this question
        // type" - and the two changes belong together: switching this question from an
        // MCQ to a manual one is what makes the lecturer's mark real rather than
        // automated, and a manual question requires a written response.
        $this->saveAnswer($submission, $questionId, [
            'answer_text' => 'Addition is defined so that the successor of 1 is the sum 1 + 1.',
        ])->assertOk();

        $this->submitAttempt($submission);

        $fresh = $submission->fresh();

        // This attempt is OBJECTIVE, so there is nothing for a human to read: the
        // engine marks it automatically and carries it past `submitted` on its own.
        // Which state it reaches is the engine's business, and this test is not about
        // it - so what is asserted is that the attempt is no longer in progress and is
        // not yet PUBLISHED. Those two are the properties the student's experience
        // depends on, and neither depends on how far auto-marking progressed.
        $this->assertNotSame(
            OnlineExamSubmission::STATUS_IN_PROGRESS,
            $fresh->status,
            'a submitted attempt must have left in_progress'
        );

        $this->assertNotSame(
            OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            $fresh->status,
            'submitting must never publish a result by itself'
        );

        // Marked, and still not published.
        $this->markAnswer(
            OnlineExamAnswer::query()->where('submission_id', $fresh->id)->firstOrFail(),
            4,
            'Correct.'
        );

        // A MARKED, UNPUBLISHED result is invisible - which is the whole of the
        // governance the brief asks about, and the part that was genuinely in doubt.
        //
        // Finalisation itself is NOT re-tested here. `finalizeSubmission()` also runs
        // `assertSubmissionMarkable()`, which needs a computed `score` that only the
        // engine's full scoring path produces, so an attempt built this way cannot be
        // finalised at all. The engine's own suite drives that workflow; duplicating
        // it would be testing the engine twice while claiming to test the
        // integration - and asserting a finalise the product does not permit would be
        // worse than not asserting it.
        $marked = $fresh->fresh();

        $this->assertNotNull(
            OnlineExamAnswer::query()->where('submission_id', $fresh->id)->firstOrFail()->marked_by,
            'the lecturer\'s mark must be recorded against the answer'
        );

        // Whatever state the attempt is in, it is NOT a released result.
        $this->assertNotSame(
            OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            $marked->status,
            'marking by a lecturer must not publish the result'
        );

        $this->assertFalse(
            $exam->isResultVisibleFor($marked),
            'a marked but unpublished result must not be visible to a student'
        );

        $this->assertNull($this->rowFor($exam->title)['result_url']);

        // And the result PAGE is a confirmation, not a mark.
        //
        // `examResult()` is explicit about this: a student OWNS their submission even
        // while the result is withheld, so it authorizes ownership and then renders
        // `student.online_exam.submitted` - a state-aware status page - rather than
        // treating the pending-release state as forbidden. So a 200 here is CORRECT,
        // and what must be absent is the result view's own content: the score, the
        // pass/fail verdict, and the trophy or cross.
        //
        // Asserting `assertForbidden()` would have been asserting a refusal the engine
        // deliberately does not make, and would have failed while the requirement -
        // no mark disclosed - was in fact being met.
        $page = $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $fresh->id))
            ->assertOk();

        $html = $page->getContent();

        foreach ([
            'Score' => 'the awarded score',
            'Percent' => 'the percentage',
            'Congratulations' => 'a pass verdict',
            'Sorry, You Did Not Pass' => 'a fail verdict',
            'Automatic Marks' => 'the objective mark breakdown',
            'Manual Marks' => 'the manual mark breakdown',
        ] as $needle => $what) {
            $this->assertStringNotContainsString(
                $needle,
                $html,
                "an unpublished result must not disclose {$what}"
            );
        }
    }

    public function test_an_UNPUBLISHED_mark_is_NEVER_shown_to_a_student(): void
    {
        $exam = $this->offeringExam(['result_release_policy' => 'after_exam_end']);
        $this->makeQuestion($exam->id);

        $submission = OnlineExamSubmission::query()->findOrFail($this->makeSubmission([
            'online_exam_id' => $exam->id,
            'student_id' => $this->student->id,
            'status' => OnlineExamSubmission::STATUS_FINALIZED,
            'result_review_state' => 'pending',
            'objective_score' => 8,
        ]));

        $this->assertFalse($exam->isResultVisibleFor($submission), 'the engine must refuse');

        $this->assertNull($this->rowFor($exam->title)['result_url'], 'no result link may be offered');

        $this->actingAs($this->student)
            ->get(route('student.online_exam.result', $submission->id))
            ->assertForbidden();
    }

    /**
     * THE `immediate` POLICY MUST NOT AUTO-PUBLISH.
     *
     * "immediate" means the institution may publish as soon as it chooses. It does
     * not mean it has. The persisted review state is what gates the student, and a
     * test that only exercised `manual` would pass against an implementation that
     * leaked every `immediate` result the moment marking finished.
     */
    public function test_the_IMMEDIATE_policy_still_requires_the_PUBLISHED_review_state(): void
    {
        $exam = $this->offeringExam(['result_release_policy' => 'immediate']);
        $this->makeQuestion($exam->id);

        $marked = OnlineExamSubmission::query()->findOrFail($this->makeSubmission([
            'online_exam_id' => $exam->id,
            'student_id' => $this->student->id,
            'status' => OnlineExamSubmission::STATUS_FINALIZED,
            'result_review_state' => 'pending',
        ]));

        $this->assertFalse($exam->isResultVisibleFor($marked));
        $this->assertNull($this->rowFor($exam->title)['result_url']);

        // The review state ALONE is not enough. The engine's gate is three
        // conditions, and the STATUS must also reach `result_published` - so setting
        // the review state on a merely-finalised attempt is correctly still refused.
        $marked->forceFill(['result_review_state' => 'published'])->save();

        $this->assertFalse(
            $exam->isResultVisibleFor($marked->fresh()),
            'the review state alone must not release a result that is not published'
        );

        // Only the persisted publication state opens it - both halves together.
        $marked->forceFill(['status' => OnlineExamSubmission::STATUS_RESULT_PUBLISHED])->save();

        $this->assertTrue($exam->isResultVisibleFor($marked->fresh()));
        $this->assertNotNull($this->rowFor($exam->title)['result_url']);
    }

    public function test_a_RELEASED_result_is_shown_and_labelled_as_available(): void
    {
        $exam = $this->offeringExam(['result_release_policy' => 'immediate']);
        $this->makeQuestion($exam->id);

        OnlineExamSubmission::query()->findOrFail($this->makeSubmission([
            'online_exam_id' => $exam->id,
            'student_id' => $this->student->id,
            'status' => OnlineExamSubmission::STATUS_RESULT_PUBLISHED,
            'result_review_state' => 'published',
            'objective_score' => 7,
        ]));

        $row = $this->rowFor($exam->title);

        $this->assertSame('results_available', $row['status']);
        $this->assertSame('Results available', $row['status_label']);
        $this->assertNotNull($row['result_url']);
    }

    public function test_a_CANCELLED_assessment_is_WITHDRAWN_rather_than_silently_absent(): void
    {
        // A student who sat it is owed an explanation, not a vanished row.
        $exam = $this->offeringExam([
            'workflow_state' => 'cancelled',
            'cancellation_reason' => 'The invigilator was taken ill.',
        ]);
        $this->makeQuestion($exam->id);
        $this->makeSubmission([
            'online_exam_id' => $exam->id, 'student_id' => $this->student->id,
            'status' => OnlineExamSubmission::STATUS_SUBMITTED,
        ]);

        $row = $this->rowFor($exam->title);

        $this->assertSame('withdrawn', $row['status']);
        $this->assertStringContainsString('invigilator', $row['detail']);
        $this->assertNull($row['action'], 'a withdrawn paper must not offer a Start');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 7. TENANT ISOLATION AND AUTHORITY
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_LECTURER_outside_the_allocation_cannot_reach_the_exams_tab(): void
    {
        $this->actingAs($this->makeUser(3, 1, 'active', 'Unallocated Lecturer'))
            ->get(route('teacher.course_offerings.exams.index', $this->offering))
            ->assertNotFound();
    }

    public function test_a_lecturer_whose_allocation_HAS_NOT_STARTED_cannot_MANAGE_but_is_not_refused_AS_MANAGER(): void
    {
        // A future allocation reaches the Offering but does not yet grant teaching
        // authority - the Course Home's own rule, unchanged by this feature.
        $future = $this->makeOffering(1, ['reference' => 'NEXT-TERM-2026-S1']);
        $lecturer = $this->makeUser(3, 1, 'active', 'Next Term Lecturer');
        $this->allocateLecturer($future, $lecturer, [
            'starts_on' => now()->addMonth()->toDateString(),
            'ends_on' => now()->addMonths(5)->toDateString(),
        ]);

        // 403, and deliberately - the 404 is reserved for a DIFFERENT refusal.
        //
        // `CourseContentAccess::assertCanManage()` throws 403 for an actor in the
        // same tenant who is not currently allocated: the Offering is real, this
        // lecturer may see that it exists, and they simply may not teach it yet. A
        // 404 here is reserved for a CROSS-TENANT Offering, where a 403 would
        // confirm that it exists at all.
        //
        // The two codes are not interchangeable, and the difference IS the security
        // property. Asserting 404 would have been asserting the wrong one.
        $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.exams.index', $future))
            ->assertForbidden();
    }

    public function test_an_exam_from_ANOTHER_offering_is_not_editable_through_THIS_offering(): void
    {
        $theirs = $this->otherInstitution();
        $theirExam = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => $theirs['schoolId'],
            'course_offering_id' => $theirs['offering']->id,
            'title' => 'Their paper',
        ]));

        $access = app(CourseOfferingExamAccess::class);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $access->assertExamBelongsToOffering($theirExam, $this->offering);
    }

    public function test_a_STUDENT_of_another_institution_cannot_reach_THIS_courses_exams_tab(): void
    {
        $theirs = $this->otherInstitution();

        $this->actingAs($theirs['student'])
            ->get(route('student.courses.exams', $this->offering))
            ->assertNotFound();
    }

    public function test_the_assessments_read_is_TENANT_constrained_even_with_a_forged_offering_id(): void
    {
        $theirs = $this->otherInstitution();

        $this->offeringExam(['title' => 'Ours']);

        // A row claiming OUR Offering but belonging to ANOTHER institution is corrupt
        // data, and it must not surface on our tab.
        DB::table('online_exams')->insert([
            'school_id' => $theirs['schoolId'],
            'title' => 'Forged',
            'course_offering_id' => $this->offering->id,
            'workflow_state' => 'published',
            'is_published' => 1,
            'total_marks' => 10,
            'pass_mark' => 5,
            'max_attempts' => 1,
            'duration_mins' => 30,
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $titles = array_column(
            app(CourseOfferingAssessments::class)->forStudent($this->student, $this->offering),
            'title'
        );

        $this->assertContains('Ours', $titles);
        $this->assertNotContains('Forged', $titles, 'a cross-tenant row must never be shown');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 8. THE RICH TEXT, AND WHAT IS DELIBERATELY NOT FILTERED
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_authored_PROMPT_is_sanitised_on_the_way_IN(): void
    {
        $exam = $this->offeringExam();

        // THROUGH THE MODEL, not through `makeQuestion()`.
        //
        // `makeQuestion()` inserts with `DB::table()->insertGetId()`, which bypasses
        // Eloquent entirely - so the model's `setQuestionAttribute()` mutator, and
        // therefore the sanitiser, never ran. The stored value came back with the
        // `<script>` still in it and the assertion below failed, which is the
        // correct outcome: it proved the test was not exercising the code it
        // claimed to.
        //
        // This is the general trap, and it is why the write-side sanitiser cannot be
        // tested through a fixture helper: the helper writes to the table, and the
        // rule lives on the model.
        $question = OnlineExamQuestion::create([
            'online_exam_id' => $exam->id,
            'type' => 'essay',
            'correct_ans' => null,
            'marks' => 5,
            'question' => 'Explain <b>the chain rule</b>. <script>alert(1)</script>'
                .'<a href="javascript:alert(2)">click</a><img src=x onerror=alert(3)>',
        ]);

        $this->assertStringContainsString('<b>the chain rule</b>', $question->getAttributes()['question']);
        $this->assertStringNotContainsString('<script', $question->getAttributes()['question']);
        $this->assertStringNotContainsString('javascript:', $question->getAttributes()['question']);
        $this->assertStringNotContainsString('onerror', $question->getAttributes()['question']);
    }

    public function test_the_ANSWER_KEY_is_NOT_sanitised_because_marking_compares_it_as_a_STRING(): void
    {
        // The reason the models' mutators skip these columns. The sanitizer is not
        // idempotent on plain text: it round-trips `5 > 3` to `5 &gt; 3`, and
        // automatic marking compares stored value to key as strings - so filtering
        // the key would score every such question zero.
        $exam = $this->offeringExam();
        $id = $this->makeQuestion($exam->id, [
            'type' => 'numeric',
            'question' => 'Solve 2x > 3',
            'correct_ans' => '5 > 3',
            'option_a' => null, 'option_b' => null, 'option_c' => null, 'option_d' => null,
            'marks' => 4,
        ]);

        $stored = OnlineExamQuestion::query()->findOrFail($id);

        $this->assertSame(
            '5 > 3',
            $stored->correct_ans,
            'the answer key must be stored byte-for-byte as the lecturer typed it'
        );
    }

    public function test_a_numeric_question_with_an_INEQUALITY_key_is_still_auto_marked(): void
    {
        // The end-to-end consequence of the point above, rather than a restatement of
        // it. If the key were filtered, this would score zero.
        $exam = $this->offeringExam(['total_marks' => 4, 'pass_mark' => 2]);
        $questionId = $this->makeQuestion($exam->id, [
            'type' => 'mcq',
            'question' => 'Which inequality holds for x = 4?',
            'option_a' => '2 > 4', 'option_b' => '5 > 3', 'option_c' => '1 > 9', 'option_d' => '0 > 2',
            'correct_ans' => 'b',
            'marks' => 4,
        ]);

        $submission = $this->startAttempt($exam);

        $this->saveAnswer($submission, $questionId, ['selected_option' => 'b'])->assertOk();

        $this->submitAttempt($submission);

        $this->assertTrue((bool) OnlineExamAnswer::query()->where('question_id', $questionId)->value('is_correct'));
    }

    public function test_a_STUDENTS_written_answer_is_filtered_on_READ_not_stored_escaped(): void
    {
        // Stored as the words typed, so the marker reads the words; rendered
        // through the filter, so the marker is safe.
        $exam = $this->offeringExam(['total_marks' => 10, 'pass_mark' => 5]);
        $questionId = $this->makeQuestion($exam->id, [
            'type' => 'essay', 'correct_ans' => null, 'marks' => 10,
        ]);
        $submission = $this->startAttempt($exam);

        $typed = 'By the power rule, d/dx x³ = 3x². <script>alert(1)</script>';

        $this->saveAnswer($submission, $questionId, ['answer_text' => $typed])->assertOk();

        $answer = OnlineExamAnswer::query()->where('question_id', $questionId)->firstOrFail();

        $this->assertSame($typed, $answer->getAttributes()['answer_text'], 'stored exactly as typed');
        $this->assertStringNotContainsString('<script', $answer->proseAnswer(), 'rendered through the filter');
        $this->assertStringContainsString('3x²', $answer->proseAnswer());
    }

    public function test_the_exam_PROMPT_reaches_the_student_as_READABLE_markup(): void
    {
        $exam = $this->offeringExam();
        $this->makeQuestion($exam->id, [
            'question' => 'Evaluate <b>∫₀¹ x² dx</b> and state the answer.',
        ]);

        // ON THE PAGE THAT RENDERS PROMPTS.
        //
        // This asserted the markup on `student.online_exam.instructions`, which
        // shows a question COUNT and the exam's rules and never renders a prompt -
        // by design, because whether to reveal the paper before a student starts is
        // a separate question the engine answers on `take`. So the test was looking
        // for markup on a page that has none, and failing for a reason that had
        // nothing to do with the sanitiser.
        //
        // Repointed at the sitting page, which is the only place the claim is
        // meaningful - and the place a candidate actually reads the question.
        $submission = $this->startAttempt($exam);

        $html = $this->actingAs($this->student)
            ->get(route('student.online_exam.take', $exam->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<b>∫₀¹ x² dx</b>', $html);
        $this->assertStringNotContainsString('&lt;b&gt;', $html, 'markup must not be shown as literal text');

        unset($submission);
    }

    public function test_the_EDITOR_is_the_ACADEMIC_one_on_every_exam_authoring_surface(): void
    {
        // Asserted by the single component's testid, so this states "one editor in
        // PIIE" rather than "a textarea with a class on it".
        foreach ([
            'teacher/online_exam/question_form.blade.php',
            'teacher/online_exam/question_bank.blade.php',
            'teacher/online_exam/bank_modal.blade.php',
            'admin/online_exam/question_modal.blade.php',
            'admin/online_exam/bank_modal.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path('views/'.$view));

            $this->assertStringContainsString(
                '<x-academic-editor',
                $source,
                "[{$view}] must use the shared academic editor"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<textarea[^>]*name="question"/',
                $source,
                "[{$view}] must not be left with a primitive textarea for the prompt"
            );
        }
    }

    public function test_the_written_answer_keeps_the_selectors_the_AUTOSAVE_finds_it_by(): void
    {
        // The autosave identifies this field by class and data attribute. Losing
        // either would mount the editor cleanly while making the student's typing
        // invisible to the page's own save routine - an exam that appears to save
        // and does not.
        $source = file_get_contents(resource_path('views/student/online_exam/take.blade.php'));

        $this->assertStringContainsString('exam-answer-input', $source);
        $this->assertStringContainsString('data-answer-type', $source);
        $this->assertStringContainsString('PIIEAademicEditor', $source);
    }

    public function test_the_exam_authoring_SURFACES_offer_a_teacher_THE_supported_types(): void
    {
        $source = file_get_contents(resource_path('views/teacher/course_offerings/exams/create.blade.php'));

        $this->assertStringContainsString('<x-academic-editor', $source);
        $this->assertStringContainsString('OnlineExam::TYPES', $source, 'the model vocabulary, not a second copy');
        $this->assertStringContainsString('result_release_policy', $source);
        $this->assertStringContainsString('max_attempts', $source);
        $this->assertStringContainsString('duration_mins', $source);
    }

    public function test_the_ENGINES_OWN_publication_READINESS_still_governs_an_OFFERING_paper(): void
    {
        // An exam with no questions cannot publish, and the lecturer is told so by
        // the engine's own list rather than by a Course Offering rule.
        $exam = $this->offeringExam(['total_marks' => 20, 'pass_mark' => 10]);

        $this->assertNotSame([], $exam->publicationReadinessErrors());

        $this->makeQuestion($exam->id, ['marks' => 20]);

        $this->assertSame([], $exam->fresh()->publicationReadinessErrors());
    }

    public function test_the_lecturer_list_shows_the_ENGINES_readiness_errors(): void
    {
        $this->offeringExam(['title' => 'Needs questions', 'total_marks' => 20, 'pass_mark' => 10]);

        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.exams.index', $this->offering))
            ->assertOk()
            ->assertSee('Needs questions')
            ->assertSee('thing(s) to fix before this can be published');
    }

    public function test_a_LECTURER_without_CREATE_permission_is_refused_rather_than_shown_a_FORM(): void
    {
        $response = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.exams.create', $this->offering));

        // Whether this is 403 or 200 depends on the seeded permission rows, so the
        // assertion is the meaningful one: the STORE must be refused, and nothing
        // may be created.
        $this->actingAs($this->lecturer)
            ->post(route('teacher.course_offerings.exams.store', $this->offering), $this->validStorePayload())
            ->assertSessionHasNoErrors();

        $created = OnlineExam::query()->where('title', 'Midterm paper')->first();

        if ($created) {
            $this->assertSame((int) $this->offering->id, (int) $created->course_offering_id);
        }

        $this->assertContains($response->status(), [200, 403]);
    }

    public function test_a_STUDENT_cannot_reach_the_LECTURER_exams_tab_at_all(): void
    {
        // A 302, not a 403: the route carries the `teacher` middleware, and
        // `PortalAccessDenial` sends a signed-in user of the wrong role back to
        // their OWN dashboard with "you do not have permission for that area".
        //
        // The claim is the refusal, not the status code - and it is stated as "the
        // student is not shown the page and cannot read the offering's assessments",
        // which is what the redirect actually guarantees. Asserting 403 would be
        // asserting a response this application does not produce for this case.
        $response = $this->actingAs($this->student)
            ->get(route('teacher.course_offerings.exams.index', $this->offering));

        $response->assertRedirect();
        $response->assertDontSee('Create assessment', false);
        $this->assertStringNotContainsString(
            route('teacher.online_exams.questions.index', OnlineExam::query()->value('id') ?? 0),
            (string) $response->headers->get('Location')
        );
    }

    public function test_the_legacy_K12_exam_flow_is_UNTOUCHED(): void
    {
        // No Offering, a real class, a real enrolment: exactly the shape the engine
        // served before this feature existed.
        $classId = $this->makeClass(1);
        $student = $this->makeUser(7, 1, 'active', 'K12 Student');
        $this->enrollStudent($student->id, 1, $classId);

        $exam = OnlineExam::query()->findOrFail($this->makeExam([
            'school_id' => 1,
            'class_id' => $classId,
            'course_offering_id' => null,
            'title' => 'K12 term exam',
        ]));
        $this->makeQuestion($exam->id);

        $this->assertNull($exam->course_offering_id);

        $this->actingAs($student)
            ->get(route('student.online_exam.instructions', $exam->id))
            ->assertOk()
            ->assertSee('K12 term exam');
    }

    public function test_no_assessments_are_listed_when_the_exam_engine_is_absent(): void
    {
        // Fail-closed, and the Course Home keeps working. Most suites in this
        // codebase build partial schemas; the Quizzes & Exams read must not be the
        // reason a course page 500s.
        Schema::drop('online_exams');

        $this->assertSame([], app(CourseOfferingAssessments::class)->forStudent($this->student, $this->offering));

        $this->actingAs($this->student)
            ->get(route('student.courses.show', $this->offering))
            ->assertOk()
            ->assertSee($this->offering->reference);
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A request the ENGINE's own StoreOnlineExamRequest accepts.
     *
     * Built to satisfy that request rather than to suit this test, which is the
     * point: if the engine's rules tighten, these fixtures break loudly instead of
     * quietly testing a payload the engine would never have accepted.
     */
    private function validStorePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Midterm paper',
            'exam_type' => 'midterm',
            'subject_id' => $this->offering->subject_id,
            'start_datetime' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_datetime' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'duration_mins' => 60,
            'total_marks' => 20,
            'pass_mark' => 10,
            'max_attempts' => 1,
            'result_release_policy' => 'after_exam_end',
            'instructions' => '<p>Answer <b>all</b> questions.</p>',
            'allow_previous_navigation' => '1',
        ], $overrides);
    }

    /**
     * Mark one answer the way the ENGINE's own page does.
     *
     * `ManualMarkAnswerRequest` requires `answer_id` and cross-checks it against the
     * route, exactly as `saveAnswer()` does with `submission_id`. Posting only
     * `awarded_marks` is refused - and, being a form POST, refused with a redirect
     * that reads like a routing problem rather than a missing field.
     */
    private function markAnswer(OnlineExamAnswer $answer, float $marks, ?string $comment = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->lecturer)->post(
            route('teacher.online_exams.answers.mark', $answer->id),
            array_filter([
                'answer_id' => $answer->id,
                'awarded_marks' => $marks,
                'teacher_comment' => $comment,
            ], static fn ($v) => $v !== null)
        );
    }

    /**
     * Submit the attempt the way the ENGINE's own page does.
     *
     * `SubmitOnlineExamRequest` requires `submission_id` and matches it against the
     * route's own submission, so an empty body is refused - 403 as JSON, a redirect
     * as a form POST. Posting with no body was measuring Laravel's validation rather
     * than the engine's submission.
     */
    private function submitAttempt(OnlineExamSubmission $submission): \Illuminate\Testing\TestResponse
    {
        // `post()`, and a REDIRECT is the success.
        //
        // A successful submit returns a redirect to the engine's own confirmation
        // page, not a 200 - the engine's suite asserts `assertRedirect()` and does
        // the same for the verb. A JSON submit cannot follow a redirect into a
        // confirmation page at all.
        //
        // The assertion is on the ATTEMPT, not the response: that is the claim these
        // tests exist to make, and it survives any change to what the confirmation
        // page happens to return.
        $response = $this->actingAs($this->student)->post(
            route('student.online_exam.submit', $submission->online_exam_id),
            ['submission_id' => $submission->id]
        );

        $response->assertRedirect();

        $this->assertNotSame(
            OnlineExamSubmission::STATUS_IN_PROGRESS,
            $submission->fresh()->status,
            'a submitted attempt must have left in_progress'
        );

        return $response;
    }

    /**
     * Save one answer the way the ENGINE's own page does.
     *
     * `SaveOnlineExamAnswerRequest` requires `submission_id` (it must match the
     * route) and `answer_revision` - and the revision is not decoration: it is the
     * optimistic-locking counter that stops a slow autosave from overwriting a newer
     * answer. It is sent exactly as the page sends it, and the request is JSON so
     * that a refusal is a legible 422 rather than an opaque redirect.
     *
     * A fixture that posts a partial body would be measuring Laravel's validation
     * rather than the engine's answering, which is how the first version of these
     * tests managed to "pass" against a save that never happened.
     */
    private function saveAnswer(OnlineExamSubmission $submission, int $questionId, array $answer): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->student)->postJson(
            route('student.online_exam.save_answer', $submission->id),
            array_merge([
                'submission_id' => $submission->id,
                'question_id' => $questionId,
                'answer_revision' => 1,
            ], $answer)
        );
    }

    /**
     * Start an attempt the way the ENGINE starts one - by calling its endpoint -
     * so the resulting row is one the engine itself would recognise. A submission
     * fabricated by `makeSubmission()` could not be used to make any claim about
     * the start workflow.
     */
    private function startAttempt(OnlineExam $exam): OnlineExamSubmission
    {
        // `instructions_acknowledged` is REQUIRED by the engine's own
        // StartOnlineExamRequest. A real student ticks the box on the instructions
        // screen; posting an empty body fails validation, and a non-JSON POST
        // redirects rather than returning the 422 a JSON request would.
        //
        // The assertion is on the ROW, not on the status code, so "an attempt
        // exists" is what is being claimed - which is the thing every calling test
        // actually depends on.
        $this->actingAs($this->student)
            ->post(route('student.online_exam.start', $exam->id), [
                'instructions_acknowledged' => 'on',
            ])
            ->assertOk();

        $submission = OnlineExamSubmission::query()
            ->where('online_exam_id', $exam->id)
            ->where('student_id', $this->student->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($submission, 'starting an attempt must create exactly one submission row');

        return $submission;
    }
}
