<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentQuestion;
use App\Models\AssignmentQuestionResponse;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionItem;
use App\Models\CourseOfferingModule;
use App\Support\Assignments\AssignmentLifecycle;
use App\Support\Assignments\QuestionService;
use App\Support\CourseContent\ModuleCompletion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Question-based Course Offering Assignments, end to end.
 *
 * THE THING UNDER TEST IS A SET OF PROMISES, NOT A SCREEN
 *
 * A question-based assignment makes five promises at once, and each one has a
 * way of quietly failing:
 *
 *   1. THE QUESTIONS GOVERN THE MARKS. The sum of the question marks must equal
 *      the assignment total before publication, or the paper is refused. A stored
 *      question total would be a second fact that can be wrong.
 *
 *   2. A QUESTION ACCEPTS ONLY WHAT IT ASKED FOR. Per-question kinds, per-question
 *      extension allowlists, and a question id that belongs to another assignment
 *      is refused rather than ignored.
 *
 *   3. A DRAFT IS NOT A SUBMISSION. Saving prepared work consumes no attempt,
 *      sets no `submitted_at`, and leaves the assignment showing as not submitted.
 *
 *   4. THE TOTAL IS COMPUTED FROM THE QUESTION MARKS. Not typed, not clamped, not
 *      independently entered - so a lecturer cannot record 17 against a paper of
 *      four questions worth five each and end up with 4 and 17 disagreeing.
 *
 *   5. A GENERIC ASSIGNMENT IS UNCHANGED. Every K12 row, every pre-existing HEI
 *      assignment, and Assignment #3 as it stands keep behaving exactly as they
 *      did. This is the promise that is easiest to break and the hardest to
 *      notice, because a broken generic assignment still looks like it works.
 *
 * And one rule about WHICH THINGS FREEZE, which is the sharpest boundary in the
 * feature: once a student has submitted, the QUESTIONS are frozen and MARKING is
 * not. Editing a question after work exists changes what that work is an answer
 * to; changing a mark is just marking.
 */
class CourseOfferingAssignmentQuestionsTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    private const K = AssignmentSubmissionItem::class;

    protected function setUp(): void
    {
        $this->assignmentSetUp();

        // Every question-based test uploads at least one file, because that is what
        // a real paper of this shape involves. Faking the disk ONCE HERE rather
        // than per test means a test that forgets it gets a real failure instead of
        // quietly writing evidence into the application storage directory.
        Storage::fake('local');
    }

    // ══════════════════════════════════════════════════════════════════════
    // SCENARIO
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A published, released assignment worth 20, with the brief's four questions
     * of five marks each.
     *
     * The four questions are exactly the shape the brief describes: a written
     * answer, a photograph of working, an oral explanation, and a demonstration
     * that may be recorded, uploaded or linked.
     *
     * @return array{0: Assignment, 1: list<AssignmentQuestion>}
     */
    private function paper(array $assignmentAttributes = []): array
    {
        $assignment = $this->publishedAssignment(array_merge([
            'title' => 'Module 1 Assessment',
            'max_marks' => 20,
            'due_date' => now()->addWeek(),
        ], $assignmentAttributes));

        return [$assignment, $this->questionPaper($assignment)];
    }

    private function questions(Assignment $assignment)
    {
        return AssignmentQuestion::query()
            ->where('assignment_id', $assignment->id)
            ->inReadingOrder()
            ->get();
    }

    private function completion(Assignment $assignment, \App\Models\User $student = null): array
    {
        $module = CourseOfferingModule::query()->find($assignment->course_offering_module_id)
            ?? $this->attachModule($assignment);

        return app(ModuleCompletion::class)->forModule($module, $student ?? $this->student);
    }

    private function attachModule(Assignment $assignment, string $role = Assignment::ROLE_REQUIRED): CourseOfferingModule
    {
        $module = CourseOfferingModule::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'title' => 'Introduction to math',
            'sequence' => 1,
            'status' => CourseOfferingModule::STATUS_PUBLISHED,
            'released_at' => now()->subDay(),
        ]);

        $assignment->course_offering_module_id = $module->id;
        $assignment->requirement_role = $role;
        $assignment->completion_rule = Assignment::RULE_RELEASED_MARK;
        $assignment->save();

        return $module;
    }

    /** Post one question's answer, the way the real form posts it. */
    private function answerPayload(int $questionId, array $overrides = []): array
    {
        return array_merge([
            (string) $questionId => [
                'text' => 'My working.',
                'items' => [],
            ],
        ], $overrides);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. LECTURER QUESTION CRUD AND ORDER
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_lecturer_can_build_a_whole_question_paper(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20]);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions", [
                'heading' => 'Gross profit',
                'prompt' => '<p>Calculate the gross profit.</p>',
                'marks' => 5,
                'is_required' => 1,
                'response_kinds' => [self::K::KIND_TEXT],
            ])
            ->assertRedirect();

        $this->assertSame(1, AssignmentQuestion::query()->where('assignment_id', $assignment->id)->count());

        // And the paper is readable as an ordered list of what a student will see.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('Question 1 of 1')
            ->assertSee('Calculate the gross profit');
    }

    public function test_a_question_is_created_in_reading_order_and_a_new_one_goes_last(): void
    {
        [$assignment, $questions] = $this->paper();

        $this->assertSame([1, 2, 3, 4], $questions->pluck('sequence')->all());

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions", [
                'prompt' => '<p>A fifth question.</p>',
                'marks' => 5,
                'response_kinds' => [self::K::KIND_TEXT],
            ])
            ->assertRedirect();

        $this->assertSame(5, AssignmentQuestion::query()->where('assignment_id', $assignment->id)->max('sequence'));
    }

    public function test_a_question_can_be_edited_and_removed(): void
    {
        [$assignment, $questions] = $this->paper();
        $first = $questions[0];

        $this->actingAs($this->lecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions/{$first->id}", [
                'prompt' => '<p>Calculate the NET profit instead.</p>',
                'marks' => 8,
                'response_kinds' => [self::K::KIND_DOCUMENT],
            ])
            ->assertRedirect();

        $this->assertSame(8.0, (float) $first->fresh()->marks);
        $this->assertSame([self::K::KIND_DOCUMENT], $first->fresh()->acceptedResponseKinds());

        $this->actingAs($this->lecturer)
            ->delete("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions/{$first->id}")
            ->assertRedirect();

        $this->assertNull(AssignmentQuestion::query()->find($first->id));
    }

    public function test_questions_can_be_reordered(): void
    {
        [$assignment, $questions] = $this->paper();
        $ids = $questions->pluck('id')->all();

        $reversed = array_reverse($ids);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions/order", [
                'order' => $reversed,
            ])
            ->assertRedirect();

        $this->assertSame($reversed, $this->questions($assignment)->pluck('id')->all());
        $this->assertSame([1, 2, 3, 4], $this->questions($assignment)->pluck('sequence')->all());
    }

    public function test_an_order_containing_another_questions_id_is_refused(): void
    {
        [$assignment, $questions] = $this->paper();

        $theirs = $this->otherTenantAssignment();

        DB::table('assignments')->where('id', $theirs['assignmentId'])->update([
            'course_offering_module_id' => null,
        ]);

        // An id that is a real question, on a DIFFERENT assignment.
        $other = AssignmentQuestion::query()->create([
            'school_id' => $this->school,
            'assignment_id' => $theirs['assignmentId'],
            'prompt' => '<p>Theirs.</p>',
            'sequence' => 1,
            'marks' => 1,
        ]);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions/order", [
                'order' => [$other->id, $questions[0]->id],
            ])
            ->assertSessionHasErrors('questions');

        // Nothing moved.
        $this->assertSame(
            $questions->pluck('id')->all(),
            $this->questions($assignment)->pluck('id')->all()
        );
    }

    public function test_a_lecturer_without_an_allocation_cannot_touch_questions(): void
    {
        [$assignment, $questions] = $this->paper();

        $stranger = \App\Models\User::factory()->create([
            'name' => 'Unallocated Teacher',
            'email' => 'unallocated.'.uniqid().'@other.example.test',
            'role_id' => 3,
            'school_id' => $this->school,
            'account_status' => 'active',
        ]);

        // 404, NOT 403, AND THAT IS DELIBERATE.
        //
        // `CourseContentAccess::resolveOffering` refuses an Offering the actor cannot
        // reach with a 404, so this lecturer learns nothing about which Offerings
        // exist - and, just as importantly, nothing about what a COLLEAGUE is
        // teaching. A 403 here would confirm that the id addresses something real.
        $this->actingAs($stranger)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions")
            ->assertNotFound();

        $this->actingAs($stranger)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions", [
                'prompt' => '<p>Not mine.</p>', 'marks' => 1, 'response_kinds' => [self::K::KIND_TEXT],
            ])
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. MARK INTEGRITY
    // ══════════════════════════════════════════════════════════════════════

    public function test_questions_that_total_the_assignment_are_publishable(): void
    {
        [$assignment, $questions] = $this->paper();

        $this->assertSame(20.0, $assignment->questionMarksTotal());
        $this->assertSame([], $assignment->questionReadinessProblems());
        $this->assertTrue($assignment->questionsArePublishable());
    }

    public function test_questions_that_do_not_total_the_assignment_are_refused(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20, 'instructions' => '<p>Do the work.</p>']);
        $assignment->update(['status' => Assignment::STATUS_DRAFT]);

        $this->questionPaper($assignment); // 20 marks of questions

        // The lecturer changes the assignment total instead of the questions.
        $assignment->max_marks = 25;
        $assignment->save();

        $this->assertNotEmpty($assignment->fresh()->questionReadinessProblems());

        // Publishing is refused, and the refusal names the actual numbers.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/state/published")
            ->assertSessionHasErrors(['questions', 'max_marks']);

        $this->assertSame(Assignment::STATUS_DRAFT, $assignment->fresh()->status);
    }

    public function test_a_question_worth_no_marks_blocks_publication(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 10, 'instructions' => '<p>Do the work.</p>']);
        $assignment->update(['status' => Assignment::STATUS_DRAFT]);

        $this->question($assignment, ['marks' => 10]);
        $this->question($assignment, ['marks' => 0, 'response_kinds' => self::K::KIND_TEXT]);

        $problems = $assignment->fresh()->questionReadinessProblems();

        $this->assertStringContainsString('worth no marks', implode(' ', $problems));
    }

    public function test_a_question_with_no_answer_type_blocks_publication(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 10, 'instructions' => '<p>Do the work.</p>']);
        $assignment->update(['status' => Assignment::STATUS_DRAFT]);

        $this->question($assignment, ['marks' => 5]);
        // NULL response kinds means NOT CONFIGURED, not "accepts anything".
        $this->question($assignment, ['marks' => 5, 'response_kinds' => null]);

        $problems = $assignment->fresh()->questionReadinessProblems();

        $this->assertStringContainsString('no answer type chosen', implode(' ', $problems));
    }

    public function test_a_draft_may_be_completely_incomplete(): void
    {
        // A draft with no questions at all, no instructions and no due date is a
        // normal state, and must be savable. Refusing would make it impossible to
        // build a paper up over several sittings.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments", [
                'title' => 'Half written',
                'instructions' => '',
                'max_marks' => 0,
                'submission_type' => Assignment::SUBMISSION_FILE_AND_TEXT,
                'late_policy' => Assignment::LATE_ALLOWED,
                'status' => Assignment::STATUS_DRAFT,
                'requirement_role' => Assignment::ROLE_OPTIONAL,
                'completion_rule' => Assignment::RULE_SUBMISSION,
            ])
            ->assertRedirect();

        $this->assertSame(1, Assignment::query()->where('title', 'Half written')->count());
    }

    public function test_the_question_total_can_be_adopted_in_one_click(): void
    {
        [$assignment, $questions] = $this->paper(['max_marks' => 5]);

        $this->assertSame(5, (int) $assignment->max_marks);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions/adopt-marks")
            ->assertRedirect();

        $this->assertSame(20, (int) $assignment->fresh()->max_marks);
        $this->assertSame([], $assignment->fresh()->questionReadinessProblems());
    }

    public function test_publishing_is_refused_when_the_total_disagrees_even_via_the_save_path(): void
    {
        // The OTHER door: a save carrying a status field, rather than the Publish
        // button. Both must be governed, or the guarantee depends on which control
        // the lecturer happened to reach for.
        $assignment = $this->publishedAssignment([
            'max_marks' => 20,
            'instructions' => '<p>Do the work.</p>',
        ]);
        $assignment->update(['status' => Assignment::STATUS_DRAFT]);

        $this->questionPaper($assignment);
        $assignment->max_marks = 30;

        $this->actingAs($this->lecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}", [
                'title' => $assignment->title,
                'instructions' => '<p>Do the work.</p>',
                'max_marks' => 30,
                // `due_date` IS SENT. Leaving it out would clear it, and the request
                // would then be refused for having no deadline - a real rule, and
                // not the one this test exists to check.
                'due_date' => (string) $assignment->due_date,
                'submission_type' => Assignment::SUBMISSION_FILE_AND_TEXT,
                'late_policy' => Assignment::LATE_ALLOWED,
                'status' => Assignment::STATUS_PUBLISHED,
                'requirement_role' => Assignment::ROLE_OPTIONAL,
                'completion_rule' => Assignment::RULE_SUBMISSION,
            ])
            ->assertSessionHasErrors(['questions', 'max_marks']);

        // And specifically for mark integrity, not for some other rule.
        $this->assertStringContainsString(
            'add up to',
            implode(' ', (array) session('errors')->get('questions'))
        );

        $this->assertSame(Assignment::STATUS_DRAFT, $assignment->fresh()->status);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. STUDENT QUESTION VISIBILITY - ONLY WHAT THAT QUESTION ACCEPTS
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_student_sees_only_the_answer_types_each_question_accepts(): void
    {
        [$assignment, $questions] = $this->paper();

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk();

        // Q1 written only -> a text box, no file picker at all.
        $response->assertSee('as-question-text-'.$questions[0]->id, false);
        $response->assertDontSee('as-question-file-'.self::K::KIND_DOCUMENT.'-'.$questions[0]->id, false);

        // Q2 image only -> a file picker whose accept is an image, and no text box.
        $response->assertSee('as-question-file-'.self::K::KIND_IMAGE.'-'.$questions[1]->id, false);
        $response->assertDontSee('as-question-text-'.$questions[1]->id, false);

        // Q3 audio -> a picker AND a recorder, because recording IS an audio file.
        $response->assertSee('as-question-file-'.self::K::KIND_AUDIO.'-'.$questions[2]->id, false);
        $response->assertSee('as-record-toggle-'.self::K::KIND_AUDIO.'-'.$questions[2]->id, false);

        // Q4 video or link -> a video picker, a recorder, and a URL box.
        $response->assertSee('as-question-file-'.self::K::KIND_VIDEO.'-'.$questions[3]->id, false);
        $response->assertSee('as-question-link-'.$questions[3]->id, false);

        // And the counts. Two needles rather than one, because `assertSee` matches a
        // literal substring and does NOT normalise whitespace - and this page
        // renders the sentence across two lines. A single needle spanning the break
        // would fail against a perfectly correct page, which is a test that
        // teaches you to distrust itself.
        $response->assertSee('4 questions');
        $response->assertSee('20 marks in total');
    }

    public function test_a_question_with_no_answer_type_tells_the_student_rather_than_offering_fields(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 5, 'instructions' => '<p>Do the work.</p>']);
        $question = $this->question($assignment, ['marks' => 5, 'response_kinds' => null]);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('has not finished setting up this question')
            ->assertSee('as-question-unconfigured', false);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. DRAFT: SAVED, RESUMED, AND NOT AN ATTEMPT
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_draft_saves_every_question_and_consumes_no_attempt(): void
    {
        [$assignment, $questions] = $this->paper();

        // `allAnswered()` answers each question in the terms THAT question asks
        // for. A payload that typed the same text into all four would be refused -
        // correctly - because a photograph and a demonstration are not written
        // answers.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => $this->allAnswered($questions),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // A row for EVERY question, answered or not - the design decision that
        // makes "not answered" a stored fact rather than an inference.
        $this->assertSame(4, AssignmentQuestionResponse::query()->count());

        $draft = AssignmentSubmission::query()->first();
        $this->assertTrue($draft->is_draft);
        $this->assertNull($draft->submitted_at, 'a draft has no submission instant');
        $this->assertSame(0, AssignmentSubmission::query()->where('is_draft', false)->count(), 'no attempt was consumed');
        $this->assertNull($draft->text_response, 'a question-based attempt has no single written response');
    }

    public function test_a_draft_is_resumable_and_resaving_does_not_duplicate_it(): void
    {
        [$assignment, $questions] = $this->paper();

        for ($visit = 1; $visit <= 3; $visit++) {
            $payload = $this->allAnswered($questions, 'Attempt '.$visit);

            $this->actingAs($this->student)
                ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                    'questions' => $payload,
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(1, AssignmentSubmission::query()->count(), 'one draft, not three');
        $this->assertSame(4, AssignmentQuestionResponse::query()->count(), 'one row per question, not twelve');

        // The LAST save is what is stored.
        $stored = AssignmentQuestionResponse::query()
            ->where('assignment_question_id', $questions[0]->id)
            ->first();
        $this->assertStringContainsString('Attempt 3', $stored->text_response);

        // And the page comes back with the work in it.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('Attempt 3')
            ->assertSee('Prepared, not submitted');
    }

    public function test_a_draft_that_leaves_a_required_question_blank_is_refused(): void
    {
        [$assignment, $questions] = $this->paper();

        // ONLY Q1 answered. Q2, Q3 and Q4 are all required, and each asks for
        // something other than text, so an empty text box is a blank answer.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $questions[0]->id => $this->answerFor($questions[0], 'Only the first one.'),
                ],
            ])
            ->assertSessionHasErrors('questions');

        $this->assertSame(0, AssignmentSubmission::query()->count());
    }

    public function test_an_OPTIONAL_question_may_be_left_blank(): void
    {
        [$assignment, $questions] = $this->paper();
        $questions[3]->update(['is_required' => false]);

        // Questions 1, 2 and 3 answered; Question 4 is OPTIONAL and deliberately
        // absent from the payload entirely. `allAnswered()` answers each question
        // in the terms that question asks for, because a photograph and a
        // demonstration are not written answers.
        $payload = $this->allAnswered($questions);
        unset($payload[(string) $questions[3]->id]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AssignmentSubmission::query()->where('is_draft', true)->count());

        // And its response row still exists, unanswered - which is what makes
        // "not answered" a stored fact rather than a missing row.
        $row = AssignmentQuestionResponse::query()
            ->where('assignment_question_id', $questions[3]->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->text_response);
    }

    public function test_a_required_question_is_not_satisfied_by_an_empty_answer(): void
    {
        [$assignment, $questions] = $this->paper();

        // Whitespace, and an empty paragraph of markup - neither is an answer. This
        // is the "empty" that must not be smuggled past a required-question check by
        // pasting something that merely LOOKS empty.
        $payload = $this->allAnswered($questions);
        $payload[(string) $questions[0]->id] = ['text' => '   <p></p>  ', 'items' => []];

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => $payload,
            ])
            ->assertSessionHasErrors('questions');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. EVERY RESPONSE KIND, ON THE RIGHT QUESTION
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_written_response_is_stored_against_its_question(): void
    {
        [$assignment, $questions] = $this->paper();

        $payload = $this->allAnswered($questions);
        $payload[(string) $questions[0]->id] = $this->answerFor($questions[0], 'My gross profit is 4,200.');

        $this->submitWith($payload, $assignment, $questions);

        $row = AssignmentQuestionResponse::query()
            ->where('assignment_question_id', $questions[0]->id)
            ->first();

        $this->assertStringContainsString('4,200', $row->text_response);
    }

    public function test_a_document_image_audio_video_and_link_all_reach_their_own_question(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        $this->submitWith([
            (string) $questions[0]->id => ['text' => 'Written.', 'items' => []],
            (string) $questions[1]->id => [
                'items' => [[
                    'kind' => self::K::KIND_IMAGE,
                    'file' => UploadedFile::fake()->image('working.png'),
                ]],
            ],
            (string) $questions[2]->id => [
                'items' => [[
                    'kind' => self::K::KIND_AUDIO,
                    'file' => UploadedFile::fake()->create('explanation.webm', 40, 'audio/webm'),
                ]],
            ],
            (string) $questions[3]->id => [
                'items' => [
                    ['kind' => self::K::KIND_VIDEO, 'file' => UploadedFile::fake()->create('demo.webm', 80, 'video/webm')],
                    ['kind' => self::K::KIND_LINK, 'url' => 'https://example.org/my-demo'],
                ],
            ],
        ], $assignment, $questions);

        // One evidence row per kind, each filed against the question that asked.
        $image = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_IMAGE)->first();
        $audio = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_AUDIO)->first();
        $video = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_VIDEO)->first();
        $link = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_LINK)->first();

        $this->assertSame((int) $questions[1]->id, (int) $image->assignment_question_id);
        $this->assertSame((int) $questions[2]->id, (int) $audio->assignment_question_id);
        $this->assertSame((int) $questions[3]->id, (int) $video->assignment_question_id);
        $this->assertSame((int) $questions[3]->id, (int) $link->assignment_question_id);

        // A question may hold more than one slot's worth: Q4 took BOTH the video
        // and the link, which is the indexing bug a literal items[0] would have
        // caused - one would have silently overwritten the other.
        $this->assertSame(2, AssignmentSubmissionItem::query()
            ->where('assignment_question_id', $questions[3]->id)
            ->count());

        // Bytes live OUTSIDE the web root, under a generated name.
        foreach ([$image, $audio, $video] as $item) {
            $this->assertNotNull($item->stored_path);
            $this->assertStringStartsWith('assignment-submissions/', $item->stored_path);
            $this->assertStringNotContainsString('public', $item->stored_path);
            Storage::disk('local')->assertExists($item->stored_path);
        }
    }

    public function test_a_kind_the_question_did_not_ask_for_is_refused(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        // Q1 asks for a written answer. A PDF is not one.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $questions[0]->id => [
                        'items' => [[
                            'kind' => self::K::KIND_DOCUMENT,
                            'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
                        ]],
                    ],
                    (string) $questions[1]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[2]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[3]->id => ['text' => 'ok', 'items' => []],
                ],
            ])
            ->assertSessionHasErrors('questions');

        $this->assertSame(0, AssignmentSubmission::query()->count());
    }

    public function test_a_file_of_the_right_slot_but_the_wrong_type_is_refused(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        // Q2 asks for an IMAGE. The `image` slot may not hold an arbitrary
        // document - that is exactly what a marker told to expect photographic
        // evidence must not silently receive.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $questions[0]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[1]->id => [
                        'items' => [[
                            'kind' => self::K::KIND_IMAGE,
                            'file' => UploadedFile::fake()->create('essay.pdf', 10, 'application/pdf'),
                        ]],
                    ],
                    (string) $questions[2]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[3]->id => ['text' => 'ok', 'items' => []],
                ],
            ])
            ->assertSessionHasErrors('questions');
    }

    public function test_a_question_id_from_another_assignment_is_refused(): void
    {
        [$assignment, $questions] = $this->paper();

        $theirs = $this->secondOfferingAssignment();

        $foreign = AssignmentQuestion::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $theirs['offeringId'],
            'assignment_id' => $theirs['assignmentId'],
            'prompt' => '<p>Their question.</p>',
            'sequence' => 1,
            'marks' => 5,
            'response_kinds' => self::K::KIND_TEXT,
        ]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $questions[0]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[1]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[2]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[3]->id => ['text' => 'ok', 'items' => []],
                    // One answer smuggled in against a question of another delivery.
                    (string) $foreign->id => ['text' => 'Should not be accepted', 'items' => []],
                ],
            ])
            ->assertSessionHasErrors('questions');

        $this->assertSame(0, AssignmentQuestionResponse::query()->where('assignment_question_id', $foreign->id)->count());
    }

    public function test_a_question_id_on_a_generic_assignment_is_refused(): void
    {
        // The REVERSE direction matters just as much. A generic assignment has no
        // questions, so a question id on its evidence would point at a question
        // that may belong to some other assignment.
        $assignment = $this->publishedAssignment();

        [$other, $theirQuestions] = $this->paper();

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'text' => 'My work.',
                'questions' => [
                    (string) $theirQuestions[0]->id => ['text' => 'Filed against a foreign question', 'items' => []],
                ],
            ])
            ->assertSessionHasErrors('questions');
    }

    public function test_a_link_must_be_a_real_http_address(): void
    {
        [$assignment, $questions] = $this->paper();

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $questions[0]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[1]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[2]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[3]->id => [
                        'items' => [['kind' => self::K::KIND_LINK, 'url' => 'javascript:alert(1)']],
                    ],
                ],
            ])
            ->assertSessionHasErrors('questions');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. RECORDING: PROVENANCE, FALLBACK, AND REFUSING TO PRETEND
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_browser_recording_is_stored_as_ordinary_private_evidence(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        $payload = $this->allAnswered($questions);
        $payload[(string) $questions[2]->id] = [
            'items' => [[
                'kind' => self::K::KIND_AUDIO,
                'file' => UploadedFile::fake()->create('explanation.webm', 40, 'audio/webm'),
                'capture_method' => self::K::CAPTURE_BROWSER,
            ]],
        ];

        $this->submitWith($payload, $assignment, $questions);

        $audio = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_AUDIO)->first();

        // A recording is an ordinary uploaded audio file: same storage, same
        // privacy, same authorisation path. That is WHY no new column or route
        // was needed for it.
        $this->assertTrue($audio->wasRecordedInBrowser());
        $this->assertSame('Recorded in the browser', $audio->captureLabel());
        $this->assertStringStartsWith('assignment-submissions/', $audio->stored_path);
        Storage::disk('local')->assertExists($audio->stored_path);
    }

    public function test_claiming_a_recording_while_sending_no_file_creates_nothing(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        // The question accepts audio, and the client says a recording is staged -
        // but no file is attached. The server must create NO evidence item, so a
        // marker sees an unanswered question rather than a recording that does not
        // exist.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $questions[0]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[1]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[2]->id => [
                        'text' => 'I recorded this but the upload did not send.',
                        'items' => [],
                    ],
                    (string) $questions[3]->id => ['text' => 'ok', 'items' => []],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(0, AssignmentSubmissionItem::query()->where('kind', self::K::KIND_AUDIO)->count());
    }

    public function test_a_recording_claim_on_a_kind_that_cannot_be_recorded_is_ignored(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment(['max_marks' => 5, 'instructions' => '<p>Do the work.</p>']);
        $question = $this->questionAccepting($assignment, self::K::KIND_DOCUMENT);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $question->id => [
                        'items' => [[
                            'kind' => self::K::KIND_DOCUMENT,
                            'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
                            'capture_method' => self::K::CAPTURE_BROWSER,
                        ]],
                    ],
                ],
            ])
            ->assertRedirect();

        $item = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_DOCUMENT)->first();

        $this->assertNotNull($item);
        $this->assertNull($item->capture_method, 'a "recording" of a PDF is not a claim worth storing');
        $this->assertFalse($item->wasRecordedInBrowser());
    }

    public function test_an_unknown_capture_method_is_ignored_rather_than_stored(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        $payload = $this->allAnswered($questions);
        $payload[(string) $questions[2]->id] = [
            'items' => [[
                'kind' => self::K::KIND_AUDIO,
                'file' => UploadedFile::fake()->create('clip.mp3', 20, 'audio/mpeg'),
                'capture_method' => 'telepathy',
            ]],
        ];

        $this->submitWith($payload, $assignment, $questions);

        $this->assertNull(
            AssignmentSubmissionItem::query()->where('kind', self::K::KIND_AUDIO)->value('capture_method')
        );
    }

    public function test_a_recording_is_still_bounded_by_the_size_limit(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        // A 25 MB "recording" is refused exactly as a 25 MB upload would be.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => [
                    (string) $questions[0]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[1]->id => ['text' => 'ok', 'items' => []],
                    (string) $questions[2]->id => [
                        'items' => [[
                            'kind' => self::K::KIND_AUDIO,
                            'file' => UploadedFile::fake()->create('long.webm', 25 * 1024, 'audio/webm'),
                            'capture_method' => self::K::CAPTURE_BROWSER,
                        ]],
                    ],
                    (string) $questions[3]->id => ['text' => 'ok', 'items' => []],
                ],
            ])
            ->assertSessionHasErrors('questions');
    }

    public function test_the_file_picker_is_always_offered_alongside_the_recorder(): void
    {
        [$assignment, $questions] = $this->paper();

        // The fallback is the whole answer where recording does not work, so the
        // picker must exist on the same panel as the button - with JavaScript
        // switched off, disabled, or refusing permission.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('as-question-file-'.self::K::KIND_AUDIO.'-'.$questions[2]->id, false)
            ->assertSee('as-record-toggle-'.self::K::KIND_AUDIO.'-'.$questions[2]->id, false)
            ->assertSee('Nothing is recorded until you choose to', false);
    }

    public function test_a_question_accepting_no_media_offers_no_recorder(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 5, 'instructions' => '<p>Do the work.</p>']);
        $this->questionAccepting($assignment, self::K::KIND_TEXT);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertDontSee('data-as-recorder', false)
            // And the recorder script is not even loaded.
            ->assertDontSee('assignment-recorder.js', false);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 7. SUBMIT, ATTEMPT NUMBERING, LATE POLICY, FINAL CLOSE
    // ══════════════════════════════════════════════════════════════════════

    public function test_submitting_files_every_answer_and_consumes_exactly_one_attempt(): void
    {
        [$assignment, $questions] = $this->paper(['allowed_attempts' => 2]);

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);

        $submission = AssignmentSubmission::query()->first();

        $this->assertFalse($submission->is_draft);
        $this->assertNotNull($submission->submitted_at);
        $this->assertSame(1, (int) $submission->attempt_no);
        $this->assertSame(4, AssignmentQuestionResponse::query()->where('assignment_submission_id', $submission->id)->count());
    }

    public function test_a_second_submit_is_attempt_two_and_keeps_attempt_one(): void
    {
        [$assignment, $questions] = $this->paper(['allowed_attempts' => 2]);

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);
        $this->submitWith($this->allAnswered($questions, 'Second'), $assignment, $questions);

        $attempts = AssignmentSubmission::query()->orderBy('attempt_no')->get();

        $this->assertSame([1, 2], $attempts->pluck('attempt_no')->all());
        $this->assertStringContainsString(
            'My working.',
            AssignmentQuestionResponse::query()
                ->where('assignment_submission_id', $attempts[0]->id)
                ->where('assignment_question_id', $questions[0]->id)
                ->value('text_response')
        );
        $this->assertStringContainsString(
            'Second',
            AssignmentQuestionResponse::query()
                ->where('assignment_submission_id', $attempts[1]->id)
                ->where('assignment_question_id', $questions[0]->id)
                ->value('text_response')
        );
    }

    public function test_a_resubmission_replaces_a_question_evidence_wholesale(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper(['allowed_attempts' => 2]);

        // The photograph's question is OPTIONAL, and it has to be. A REQUIRED
        // question must be answered on every attempt, so while it is required the
        // student has no way to submit a second attempt without a photograph - and
        // the question this test is asking, "does attempt 1's photograph become
        // attempt 2's evidence?", could only be posed about a question they are
        // allowed to leave blank. Which is exactly what "optional" means.
        $questions[1]->update(['is_required' => false]);

        $payload = $this->allAnswered($questions);
        $payload[(string) $questions[1]->id]['items'] = [[
            'kind' => self::K::KIND_IMAGE,
            'file' => UploadedFile::fake()->image('first.png'),
        ]];

        $this->submitWith($payload, $assignment, $questions);

        $first = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_IMAGE)->first();
        $this->assertNotNull($first);

        // Attempt 2 does NOT re-upload the photograph.
        $second = $this->allAnswered($questions, 'Second');
        unset($second[(string) $questions[1]->id]);

        $this->submitWith($second, $assignment, $questions);

        $second = AssignmentSubmission::query()->where('attempt_no', 2)->first();

        // Attempt 1 keeps its own photograph, and attempt 2 has none - the previous
        // attempt's photograph must not become the new attempt's evidence.
        //
        // Counted BY KIND, because the assertion is about the photograph. Counting
        // the whole attempt would be measuring the wrong thing: the other three
        // questions' answers are files too, and this test would then pass for the
        // wrong reason on a one-question paper.
        $this->assertSame(1, AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $first->assignment_submission_id)
            ->where('kind', self::K::KIND_IMAGE)->count());
        $this->assertSame(0, AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $second->id)
            ->where('kind', self::K::KIND_IMAGE)->count());
    }

    public function test_a_resubmissions_own_photograph_does_not_delete_the_earlier_attempts(): void
    {
        Storage::fake('local');

        // TWO ATTEMPTS, both answering Question 2 with a photograph. The question
        // stays REQUIRED, because a required question has to be answered on every
        // attempt.
        [$assignment, $questions] = $this->paper(['allowed_attempts' => 2]);

        $first = $this->allAnswered($questions);
        $first[(string) $questions[1]->id]['items'] = [[
            'kind' => self::K::KIND_IMAGE,
            'file' => UploadedFile::fake()->image('first.png'),
        ]];
        $this->submitWith($first, $assignment, $questions);

        $attempt1 = AssignmentSubmission::query()->where('attempt_no', 1)->first();
        $item1 = AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $attempt1->id)
            ->where('kind', self::K::KIND_IMAGE)->first();

        $this->assertNotNull($item1);
        Storage::disk('local')->assertExists($item1->stored_path);

        // `allAnswered()` is rebuilt for the second request because the fake file
        // objects it holds belong to the first one.
        $second = $this->allAnswered($questions);
        $second[(string) $questions[1]->id]['items'] = [[
            'kind' => self::K::KIND_IMAGE,
            'file' => UploadedFile::fake()->image('second.png'),
        ]];
        $this->submitWith($second, $assignment, $questions);

        $attempt2 = AssignmentSubmission::query()->where('attempt_no', 2)->first();
        $item2 = AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $attempt2->id)
            ->where('kind', self::K::KIND_IMAGE)->first();

        $this->assertNotNull($item2);
        $this->assertNotSame($item1->stored_path, $item2->stored_path);

        // ATTEMPT 1'S PHOTOGRAPH SURVIVES, and this is the point.
        //
        // A resubmission INSERTS the next attempt rather than overwriting the last,
        // precisely so earlier work and any marks already given survive. A
        // lecturer comparing a resubmission with the first attempt needs both, and
        // silently deleting a student's photograph of their working would be a
        // retention decision nobody made.
        Storage::disk('local')->assertExists($item1->stored_path);
        $this->assertNotNull(AssignmentSubmissionItem::query()->find($item1->id));
    }

    public function test_replacing_a_drafts_photograph_deletes_the_orphaned_bytes(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        $first = $this->allAnswered($questions);
        $first[(string) $questions[1]->id]['items'] = [[
            'kind' => self::K::KIND_IMAGE,
            'file' => UploadedFile::fake()->image('draft-one.png'),
        ]];

        // Prepared work, not handed in.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => $first,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $draft = AssignmentSubmission::query()->first();
        $this->assertTrue($draft->is_draft);

        $original = AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $draft->id)
            ->where('kind', self::K::KIND_IMAGE)->first();

        $this->assertNotNull($original);
        Storage::disk('local')->assertExists($original->stored_path);

        // The student comes back and swaps the photograph ON THE SAME DRAFT.
        $second = $this->allAnswered($questions);
        $second[(string) $questions[1]->id]['items'] = [[
            'kind' => self::K::KIND_IMAGE,
            'file' => UploadedFile::fake()->image('draft-two.png'),
        ]];

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => $second,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // ONE row for the question, holding the new photograph...
        $replacement = AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $draft->id)
            ->where('kind', self::K::KIND_IMAGE)->first();

        $this->assertNotNull($replacement);
        $this->assertNotSame($original->stored_path, $replacement->stored_path);
        Storage::disk('local')->assertExists($replacement->stored_path);

        // ...and the WITHDRAWN one is gone, because no row points at it any more.
        // A personal photograph of a student's working must not outlive the choice
        // to replace it.
        Storage::disk('local')->assertMissing($original->stored_path);
        $this->assertNull(AssignmentSubmissionItem::query()->find($original->id));
    }

    public function test_a_late_submission_is_recorded_as_late_from_two_stored_instants(): void
    {
        [$assignment, $questions] = $this->paper();
        $assignment->update(['due_date' => now()->subDay()]);

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);

        $submission = AssignmentSubmission::query()->first();

        $this->assertTrue($submission->isLate());
        $this->assertSame('late', $submission->status);
    }

    public function test_a_final_closing_time_ends_submissions_but_keeps_the_attempt(): void
    {
        [$assignment, $questions] = $this->paper();
        $assignment->update(['closes_at' => now()->subMinute()]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'questions' => $this->allAnswered($questions),
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, AssignmentSubmission::query()->count());
    }

    public function test_an_unconfirmed_student_cannot_answer_or_see_a_question(): void
    {
        [$assignment, $questions] = $this->paper();

        DB::table('course_registrations')
            ->where('student_id', $this->student->id)
            ->update(['status' => 'registered']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertNotFound();

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'questions' => $this->allAnswered($questions),
            ])
            ->assertNotFound();
    }

    public function test_another_students_evidence_is_unreachable(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        $payload = $this->allAnswered($questions);
        $payload[(string) $questions[1]->id]['items'] = [[
            'kind' => self::K::KIND_IMAGE,
            'file' => UploadedFile::fake()->image('mine.png'),
        ]];
        $this->submitWith($payload, $assignment, $questions);

        $submission = AssignmentSubmission::query()->first();
        $item = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_IMAGE)->first();

        $other = $this->secondConfirmedStudent();

        foreach ([
            "student/courses/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}",
            "student/courses/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/evidence/{$item->id}",
            "student/courses/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/media/{$item->id}",
        ] as $url) {
            $this->actingAs($other)->get("/{$url}")->assertNotFound();
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 8. LECTURER MARKING, QUESTION BY QUESTION
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_marker_sees_each_question_with_its_actual_evidence(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        $payload = $this->allAnswered($questions);
        $payload[(string) $questions[1]->id]['items'] = [[
            'kind' => self::K::KIND_IMAGE,
            'file' => UploadedFile::fake()->image('working.png'),
        ]];
        $payload[(string) $questions[2]->id]['items'] = [[
            'kind' => self::K::KIND_AUDIO,
            'file' => UploadedFile::fake()->create('why.webm', 30, 'audio/webm'),
            'capture_method' => self::K::CAPTURE_BROWSER,
        ]];
        $payload[(string) $questions[3]->id]['items'] = [
            ['kind' => self::K::KIND_LINK, 'url' => 'https://example.org/demo'],
        ];

        $this->submitWith($payload, $assignment, $questions);

        $submission = AssignmentSubmission::query()->first();

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}")
            ->assertOk()
            ->assertSee('as-mark-question-'.$questions[0]->id, false)
            ->assertSee('as-mark-evidence-'.$questions[1]->id, false)
            // The photograph and the recording get players IN the page.
            ->assertSee('as-mark-evidence-preview', false)
            ->assertSee('recorded in the browser')
            // The link is a link, not fetched by the server.
            ->assertSee('https://example.org/demo')
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_the_total_is_calculated_from_the_question_marks(): void
    {
        [$assignment, $questions] = $this->paper();

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);

        $submission = AssignmentSubmission::query()->first();

        $this->gradeQuestions($assignment, $submission, $questions, [4, 4, 4, 5]);

        // 17, from 4+4+4+5. Computed, and written to the value the rest of the
        // system already reads.
        $this->assertSame(17.0, (float) $submission->fresh()->marks_awarded);
    }

    public function test_a_question_mark_above_its_own_maximum_is_refused(): void
    {
        [$assignment, $questions] = $this->paper();

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);
        $submission = AssignmentSubmission::query()->first();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade-by-question", [
                'marks' => [
                    (string) $questions[0]->id => 9,   // out of 5
                    (string) $questions[1]->id => 5,
                    (string) $questions[2]->id => 5,
                    (string) $questions[3]->id => 5,
                ],
            ])
            ->assertSessionHasErrors('marks.'.$questions[0]->id);

        // Nothing recorded, and no total written - a refusal that clamped instead
        // would hide the slip and quietly corrupt every total built from it.
        $this->assertNull($submission->fresh()->marks_awarded);
    }

    public function test_a_negative_question_mark_is_refused(): void
    {
        [$assignment, $questions] = $this->paper();

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);
        $submission = AssignmentSubmission::query()->first();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade-by-question", [
                'marks' => [
                    (string) $questions[0]->id => -1,
                    (string) $questions[1]->id => 5,
                    (string) $questions[2]->id => 5,
                    (string) $questions[3]->id => 5,
                ],
            ])
            ->assertSessionHasErrors('marks.'.$questions[0]->id);
    }

    public function test_a_leftover_question_mark_is_left_alone_rather_than_zeroed(): void
    {
        [$assignment, $questions] = $this->paper();

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);
        $submission = AssignmentSubmission::query()->first();

        $this->gradeQuestions($assignment, $submission, $questions, [5, 5, 5, 5]);
        $this->assertSame(20.0, (float) $submission->fresh()->marks_awarded);

        // A marker marks three of four and comes back to the fourth.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade-by-question", [
                'marks' => [
                    (string) $questions[0]->id => 3,
                    (string) $questions[1]->id => 3,
                    (string) $questions[2]->id => 3,
                ],
            ])
            ->assertRedirect();

        // 9 from the three, plus the 5 already given for the fourth. Silently
        // writing 0 for the fourth would be indistinguishable from deciding the
        // student earned nothing.
        $this->assertSame(14.0, (float) $submission->fresh()->marks_awarded);
        $this->assertSame(
            5.0,
            (float) AssignmentQuestionResponse::query()
                ->where('assignment_submission_id', $submission->id)
                ->where('assignment_question_id', $questions[3]->id)
                ->value('marks_awarded')
        );
    }

    public function test_per_question_feedback_and_the_overall_feedback_are_kept_separate(): void
    {
        [$assignment, $questions] = $this->paper();

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);
        $submission = AssignmentSubmission::query()->first();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade-by-question", [
                'marks' => [
                    (string) $questions[0]->id => 5,
                    (string) $questions[1]->id => 5,
                    (string) $questions[2]->id => 5,
                    (string) $questions[3]->id => 2,
                ],
                'feedback_by_question' => [
                    (string) $questions[3]->id => 'Show the final step of the demonstration.',
                ],
                'feedback' => 'A good piece of work overall.',
            ])
            ->assertRedirect();

        $this->assertSame(
            'Show the final step of the demonstration.',
            AssignmentQuestionResponse::query()
                ->where('assignment_question_id', $questions[3]->id)
                ->value('feedback')
        );
        $this->assertSame('A good piece of work overall.', $submission->fresh()->feedback);
    }

    public function test_a_lecturer_cannot_mark_a_student_outside_the_offering(): void
    {
        [$assignment, $questions] = $this->paper();

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);
        $submission = AssignmentSubmission::query()->first();

        $stranger = \App\Models\User::factory()->create([
            'name' => 'Other Teacher',
            'email' => 'other.teacher.'.uniqid().'@example.test',
            'role_id' => 3,
            'school_id' => $this->school,
            'account_status' => 'active',
        ]);

        // 404, NOT 403, AND THAT IS DELIBERATE.
        //
        // `CourseContentAccess::resolveOffering` refuses an Offering the actor cannot
        // reach with a 404, so this lecturer learns nothing about which Offerings
        // exist - and, just as importantly, nothing about what a COLLEAGUE is
        // teaching. A 403 here would confirm that the id addresses something real.        // And the mark is refused by the SAME check, so there is no second path
        // into the grading screen that could forget it.
        $this->actingAs($stranger)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}")
            ->assertNotFound();

        $this->actingAs($stranger)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade-by-question", [
                'marks' => [(string) $questions[0]->id => 5],
            ])
            ->assertNotFound();

        $this->assertNull($submission->fresh()->marks_awarded);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 9. THE FREEZE RULE: QUESTIONS FREEZE, MARKING DOES NOT
    // ══════════════════════════════════════════════════════════════════════

    public function test_once_a_student_has_submitted_the_questions_are_frozen(): void
    {
        [$assignment, $questions] = $this->paper(['allowed_attempts' => 3]);

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);

        $service = app(QuestionService::class);
        $this->assertTrue($service->questionsAreFrozen($assignment));
        $this->assertStringContainsString('already submitted', $service->freezeReason($assignment));

        // ADD
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions", [
                'prompt' => '<p>A question they never saw.</p>',
                'marks' => 5,
                'response_kinds' => [self::K::KIND_TEXT],
            ])
            ->assertSessionHasErrors('questions');

        // EDIT
        $this->actingAs($this->lecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions/{$questions[0]->id}", [
                'prompt' => '<p>Now asking something else.</p>',
                'marks' => 5,
                'response_kinds' => [self::K::KIND_TEXT],
            ])
            ->assertSessionHasErrors('questions');

        // DELETE
        $this->actingAs($this->lecturer)
            ->delete("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions/{$questions[0]->id}")
            ->assertSessionHasErrors('questions');

        // REORDER
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions/order", [
                'order' => array_reverse($questions->pluck('id')->all()),
            ])
            ->assertSessionHasErrors('questions');

        // Nothing changed.
        $this->assertSame(4, AssignmentQuestion::query()->where('assignment_id', $assignment->id)->count());
        $this->assertStringContainsString(
            'gross profit',
            strip_tags((string) AssignmentQuestion::query()->find($questions[0]->id)->prompt)
        );
    }

    public function test_marking_still_works_after_the_questions_freeze(): void
    {
        [$assignment, $questions] = $this->paper();

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);
        $submission = AssignmentSubmission::query()->first();

        // Marking is NOT authoring. A lecturer must be able to revise a mark, add
        // feedback later and re-release; that is the second half of the lifecycle.
        $this->gradeQuestions($assignment, $submission, $questions, [5, 5, 4, 3]);
        $this->assertSame(17.0, (float) $submission->fresh()->marks_awarded);

        $this->gradeQuestions($assignment, $submission, $questions, [5, 5, 5, 5]);
        $this->assertSame(20.0, (float) $submission->fresh()->marks_awarded);
    }

    public function test_a_draft_alone_does_not_freeze_the_questions(): void
    {
        [$assignment, $questions] = $this->paper();

        // A student PREPARING work has consumed no attempt, so nothing is owed and
        // the lecturer may still correct the paper.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/draft", [
                'questions' => $this->allAnswered($questions),
            ])
            ->assertRedirect();

        $this->assertFalse(app(QuestionService::class)->questionsAreFrozen($assignment));

        $this->actingAs($this->lecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions/{$questions[0]->id}", [
                'prompt' => '<p>Corrected after all.</p>',
                'marks' => 5,
                'response_kinds' => [self::K::KIND_TEXT],
            ])
            ->assertRedirect();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 10. RELEASE, AND THE MODULE COMPLETION RULE
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_question_based_submission_alone_does_not_satisfy_a_released_mark_rule(): void
    {
        [$assignment, $questions] = $this->paper();
        $this->attachModule($assignment, Assignment::ROLE_REQUIRED);

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);

        $completion = $this->completion($assignment);

        // Submitted is NOT satisfied under `released_mark`. If this ever passed,
        // the whole gating rule would be a decoration.
        $this->assertSame(1, $completion['assessments_outstanding']);
        $this->assertFalse($completion['is_complete']);
    }

    public function test_marking_without_releasing_does_not_satisfy_a_released_mark_rule(): void
    {
        [$assignment, $questions] = $this->paper();
        $this->attachModule($assignment, Assignment::ROLE_REQUIRED);

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);
        $submission = AssignmentSubmission::query()->first();

        $this->gradeQuestions($assignment, $submission, $questions, [5, 5, 4, 3]);

        // Marked, but the student has not been told.
        $this->assertFalse($this->completion($assignment)['is_complete']);
    }

    public function test_releasing_the_result_satisfies_the_assessment_and_completes_the_module(): void
    {
        [$assignment, $questions] = $this->paper();
        $module = $this->attachModule($assignment, Assignment::ROLE_REQUIRED);

        // The two lessons, completed by the student.
        foreach (\App\Models\CourseOfferingLesson::query()
            ->where('course_offering_module_id', $module->id)->get() as $lesson) {
            $this->actingAs($this->student)
                ->post("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}/complete")
                ->assertRedirect();
        }

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);
        $submission = AssignmentSubmission::query()->first();

        $this->gradeQuestions($assignment, $submission, $questions, [5, 5, 4, 3]);
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/release")
            ->assertRedirect();

        $completion = $this->completion($assignment);

        $this->assertTrue($submission->fresh()->isReleased());
        $this->assertSame(0, $completion['assessments_outstanding']);
        $this->assertTrue($completion['assessments_complete']);
        $this->assertTrue($completion['is_complete'], 'lessons complete and the result released');
    }

    public function test_the_student_sees_the_per_question_result_only_after_release(): void
    {
        [$assignment, $questions] = $this->paper();
        $this->submitWith($this->allAnswered($questions), $assignment, $questions);
        $submission = AssignmentSubmission::query()->first();

        $this->gradeQuestions($assignment, $submission, $questions, [5, 5, 4, 3]);

        $url = "/student/courses/{$this->offering->id}/assignments/{$assignment->id}";

        // Marked, not returned: no marks visible anywhere.
        $this->actingAs($this->student)->get($url)->assertOk()
            ->assertDontSee('as-result-per-question', false)
            ->assertDontSee('Show the final step', false);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/release")
            ->assertRedirect();

        // The per-question mark and its own maximum, asserted through the test ids
        // rather than as a "3 / 5" substring - the two are separate elements in the
        // markup, and matching across a rendered line break is brittle for no gain.
        $this->actingAs($this->student)->get($url)->assertOk()
            ->assertSee('as-result-per-question', false)
            ->assertSee('as-question-mark-'.$questions[3]->id, false)
            ->assertSeeInOrder(['Question 4', '/ 5'], false);
    }

    public function test_withdrawing_a_result_un_satisfies_the_assessment_again(): void
    {
        [$assignment, $questions] = $this->paper();
        $this->attachModule($assignment, Assignment::ROLE_REQUIRED);

        $this->submitWith($this->allAnswered($questions), $assignment, $questions);
        $submission = AssignmentSubmission::query()->first();
        $this->gradeQuestions($assignment, $submission, $questions, [5, 5, 5, 5]);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/release")
            ->assertRedirect();
        $this->assertTrue($this->completion($assignment)['is_complete']);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/unrelease")
            ->assertRedirect();

        // The marks stay; the student simply does not have them, so the assessment
        // is not satisfied again.
        $this->assertSame(20.0, (float) $submission->fresh()->marks_awarded);
        $this->assertFalse($this->completion($assignment)['is_complete']);
    }

    public function test_a_submission_rule_is_satisfied_by_working_and_never_needs_a_release(): void
    {
        $assignment = $this->publishedAssignment([
            'title' => 'Module 1 Assessment',
            'max_marks' => 5,
            'due_date' => now()->addWeek(),
        ]);
        $this->attachModule($assignment, Assignment::ROLE_REQUIRED);
        $assignment->update(['completion_rule' => Assignment::RULE_SUBMISSION]);

        $question = $this->questionAccepting($assignment, self::K::KIND_TEXT);

        $this->submitWith([(string) $question->id => ['text' => 'My answer.', 'items' => []]], $assignment, collect([$question]));

        // No lecturer involvement at all, and the module is complete.
        $this->assertTrue($this->completion($assignment)['is_complete']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 11. LEGACY AND K12 COMPATIBILITY
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_generic_he_i_assignment_behaves_exactly_as_before(): void
    {
        $assignment = $this->publishedAssignment([
            'title' => 'Module 1 Assessment — Business Mathematics',
            'max_marks' => 20,
            'instructions' => '<p>Answer all five questions.</p>',
            'submission_kinds' => 'text,document,image,audio,video,link',
        ]);

        // This is Assignment #3's real shape: published, required, question-free.
        $this->assertFalse($assignment->isQuestionBased());
        $this->assertSame([], $assignment->questionReadinessProblems());

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            // The generic form, with its single written box and its own fields.
            ->assertSee('as-generic-text', false)
            ->assertSee('as-evidence-requirement')
            ->assertDontSee('as-question-count', false)
            // No recorder, because the generic form offers no recording.
            ->assertDontSee('data-as-recorder', false);

        // And the generic requirement check still applies UNCHANGED: it asks for
        // everything its allowlist names, so an attempt supplying only some of it is
        // refused. This is the pre-existing behaviour of `submission_kinds`, and the
        // question work must not have softened it.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'Only words, and this assignment also accepts five more kinds.',
            ])
            ->assertSessionHasErrors('items');
    }

    public function test_adding_a_first_question_switches_a_generic_assignment_to_a_paper(): void
    {
        $assignment = $this->publishedAssignment([
            'title' => 'Module 1 Assessment',
            'max_marks' => 5,
            'instructions' => '<p>Answer all five questions.</p>',
            // STATED, because the assertion below is that adding a question does NOT
            // change it. Left unstated the column would still be 'submission' and the
            // assertion would pass for the wrong reason - testing the fixture's
            // default rather than the thing the comment claims.
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
        ]);

        // The student is on the generic form, and nothing about their view changes
        // until the lecturer deliberately adds a question.
        // `as-generic-text` is a STABLE HOOK, asserted instead of `name="text"`.
        // Matching on a literal attribute list means a harmless reformat of that tag
        // fails a test that was really about something else - which is how a test
        // teaches you to distrust the page rather than the change.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('as-generic-text', false)
            ->assertDontSee('as-question-count', false);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/questions", [
                'prompt' => '<p>Now there is a real question.</p>',
                'marks' => 5,
                'response_kinds' => [self::K::KIND_TEXT],
            ])
            ->assertRedirect();

        $this->assertTrue($assignment->fresh()->isQuestionBased());

        // Everything else about the assignment is preserved: its title, marks,
        // dates, publication state, module relationship and completion rule.
        $after = $assignment->fresh();
        $this->assertSame('Module 1 Assessment', $after->title);
        $this->assertSame(5, (int) $after->max_marks);
        $this->assertSame(Assignment::STATUS_PUBLISHED, $after->status);
        $this->assertTrue($after->is_published);
        $this->assertNotNull($after->due_date);
        $this->assertSame(Assignment::RULE_RELEASED_MARK, $after->completion_rule);

        // The one generic box is GONE and the question's own box is in its place.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertSee('as-question-count', false)
            ->assertDontSee('as-generic-text', false)
            ->assertSee('as-question-text-1', false);
    }

    public function test_adding_questions_to_assignment_three_would_not_disturb_its_lifecycle(): void
    {
        // Assignment #3's real shape, asserted property by property, because the
        // manual test adds questions to exactly this assignment.
        $assignment = $this->publishedAssignment([
            'title' => 'Module 1 Assessment — Business Mathematics',
            'max_marks' => 20,
            'instructions' => '<p>Answer all questions.</p>',
            'submission_kinds' => 'text,document,image,audio,video,link',
            'requirement_role' => Assignment::ROLE_REQUIRED,
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'closes_at' => now()->addMonth(),
        ]);
        $module = $this->attachModule($assignment, Assignment::ROLE_REQUIRED);
        $assignment->update(['closes_at' => now()->addMonth()]);

        $before = $assignment->fresh();

        $this->questionPaper($assignment);

        $after = $assignment->fresh();

        foreach ([
            'title', 'instructions', 'max_marks', 'due_date', 'released_at',
            'closes_at', 'status', 'is_published', 'submission_type',
            'submission_kinds', 'requirement_role', 'completion_rule',
            'course_offering_id', 'course_offering_module_id',
        ] as $field) {
            $this->assertEquals(
                $before->getAttribute($field),
                $after->getAttribute($field),
                "adding questions must not change {$field}"
            );
        }

        // And its state is still "assessment pending" for the student.
        $this->assertTrue($assignment->isRequiredForModule());
        $this->assertSame(Assignment::RULE_RELEASED_MARK, $assignment->completionRuleLabel() === null
            ? $assignment->completion_rule
            : $assignment->completion_rule);
    }

    public function test_a_k12_assignment_keeps_its_own_single_form_and_gains_no_questions(): void
    {
        $k12 = $this->k12Assignment(['title' => 'K12 Homework']);

        $this->assertNull($k12->course_offering_id);
        $this->assertFalse($k12->isQuestionBased());
        $this->assertSame([], $k12->questionReadinessProblems());

        // The legacy student list still reaches it through its own route, and the
        // legacy submission path is untouched by any of this.
        $this->actingAs($this->student)
            ->get('/student/assignments')
            ->assertOk();

        // A question cannot be attached to a K12 assignment: there is no Offering
        // to check an allocation against, and the K12 screens are not question-based.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$k12->id}/questions", [
                'prompt' => '<p>Not applicable.</p>', 'marks' => 1, 'response_kinds' => [self::K::KIND_TEXT],
            ])
            ->assertNotFound();

        $this->assertSame(0, AssignmentQuestion::query()->where('assignment_id', $k12->id)->count());
    }

    public function test_evidence_on_a_generic_assignment_carries_no_question_id(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment([
            'instructions' => '<p>Do the work.</p>',
            'submission_kinds' => 'text,document',
        ]);

        // `submission_kinds` is 'text,document', and the generic check asks for
        // BOTH - so this attempt supplies a document as well as its prose. That is
        // the pre-existing rule, and it is why the prose-only attempt above was
        // refused.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'My written answer.',
                'document' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // The generic shape: prose in the CANONICAL `text_response` field, a document
        // as assignment-level evidence with no question id, and no question anywhere.
        $submission = AssignmentSubmission::query()->first();

        $this->assertStringContainsString('My written answer', $submission->writtenResponse());
        $this->assertStringContainsString('My written answer', (string) $submission->text_response);

        $item = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_DOCUMENT)->first();
        $this->assertNotNull($item);
        $this->assertNull($item->assignment_question_id, 'generic evidence is not filed against a question');

        $this->assertSame(0, AssignmentQuestionResponse::query()->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // 12. PRIVATE EVIDENCE AUTHORISATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_question_evidence_is_private_and_served_only_through_authorised_routes(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        $payload = $this->allAnswered($questions);
        $payload[(string) $questions[1]->id]['items'] = [[
            'kind' => self::K::KIND_IMAGE,
            'file' => UploadedFile::fake()->image('working.png'),
        ]];
        $this->submitWith($payload, $assignment, $questions);

        $submission = AssignmentSubmission::query()->first();
        $image = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_IMAGE)->first();

        // Nothing is reachable at a guessed path under the web root.
        $this->assertStringStartsWith('assignment-submissions/', $image->stored_path);

        // A document is NOT streamable, and says so rather than 404-ing a link a
        // marker would read as broken evidence.
        $document = $this->questionEvidence(
            $questions[1],
            $submission,
            self::K::KIND_DOCUMENT,
            ['stored_path' => 'assignment-submissions/x/doc.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10]
        );
        Storage::disk('local')->put('assignment-submissions/x/doc.pdf', 'x');

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/media/{$document->id}")
            ->assertNotFound();

        // A lecturer WITH the allocation streams the image.
        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/media/{$image->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_lecturer_cannot_stream_evidence_through_another_offering(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        $payload = $this->allAnswered($questions);
        $payload[(string) $questions[1]->id]['items'] = [[
            'kind' => self::K::KIND_IMAGE,
            'file' => UploadedFile::fake()->image('working.png'),
        ]];
        $this->submitWith($payload, $assignment, $questions);

        $submission = AssignmentSubmission::query()->first();
        $image = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_IMAGE)->first();

        $other = $this->secondOfferingAssignment();

        // A real assignment, on a real second delivery of the same course, in the
        // same tenant - so only the OFFERING differs. A second tenant alone would
        // not prove the boundary, because the school check would catch it anyway.
        //
        // 404: the lecturer holds an allocation on the FIRST Offering and not on the
        // second, and the Offering resolver refuses to resolve the one they were
        // given, so the evidence is never even looked for.
        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$other['offeringId']}/assignments/{$assignment->id}/submissions/{$submission->id}/media/{$image->id}")
            ->assertNotFound();
    }

    public function test_a_lecturer_from_another_tenant_cannot_stream_evidence(): void
    {
        Storage::fake('local');

        [$assignment, $questions] = $this->paper();

        $payload = $this->allAnswered($questions);
        $payload[(string) $questions[1]->id]['items'] = [[
            'kind' => self::K::KIND_IMAGE,
            'file' => UploadedFile::fake()->image('working.png'),
        ]];
        $this->submitWith($payload, $assignment, $questions);

        $submission = AssignmentSubmission::query()->first();
        $image = AssignmentSubmissionItem::query()->where('kind', self::K::KIND_IMAGE)->first();

        $theirs = $this->otherTenantAssignment();

        // BOTH are 404, and that is the point: a cross-tenant actor is refused by
        // the SAME Offering resolver that refuses a legitimate lecturer on the
        // wrong delivery, so there is no separate cross-tenant code path that could
        // be got wrong. The first request carries our assignment id inside THEIR
        // Offering; the second carries it inside OURS. Neither resolves.
        $this->actingAs($theirs['lecturer'])
            ->get("/teacher/course-offerings/{$theirs['offeringId']}/assignments/{$assignment->id}/submissions/{$submission->id}/media/{$image->id}")
            ->assertNotFound();

        $this->actingAs($theirs['lecturer'])
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/media/{$image->id}")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * A payload that genuinely answers ONE question, using the kinds it accepts.
     *
     * Text only when the question accepts a written response - because typing into
     * a question that asked for a photograph is not an answer, and the service
     * treats it as blank. Then the first accepted FILE kind gets a real fake file
     * of an extension that kind's allowlist permits, because a per-kind extension
     * allowlist is only exercised by something that actually tries to violate it.
     *
     * @return array{text: ?string, items: list<array<string, mixed>>}
     */
    private function answerFor(AssignmentQuestion $question, string $text = 'My working.'): array
    {
        $items = [];

        foreach ($question->acceptedFileKinds() as $kind) {
            $items[] = ['kind' => $kind, 'file' => $this->fakeFileFor($kind)];

            // ONE file kind is enough for the default answer, unless the question
            // demanded every kind it ticked - in which case all of them are sent and
            // the all-of rule is genuinely exercised.
            if (! $question->require_all) {
                break;
            }
        }

        // A question accepting only a link is answered by a link.
        if ($items === [] && $question->acceptsLink()) {
            $items[] = [
                'kind' => self::K::KIND_LINK,
                'url' => 'https://example.org/my-work/'.$question->id,
            ];
        }

        return [
            'text' => $question->acceptsWrittenResponse() ? $text : null,
            'items' => $items,
        ];
    }

    /**
     * A file of the right KIND and a permitted extension, so the happy path is not
     * accidentally also an extension test.
     */
    private function fakeFileFor(string $kind): UploadedFile
    {
        return match ($kind) {
            self::K::KIND_IMAGE => UploadedFile::fake()->image('working.png'),
            self::K::KIND_AUDIO => UploadedFile::fake()->create('explanation.webm', 20, 'audio/webm'),
            self::K::KIND_VIDEO => UploadedFile::fake()->create('demonstration.webm', 40, 'video/webm'),
            default => UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'),
        };
    }

    /**
     * A payload answering EVERY question in the paper, in the way each one asks.
     *
     * @param  iterable<int, AssignmentQuestion>  $questions
     * @return array<string, array<string, mixed>>
     */
    private function allAnswered($questions, string $text = 'My working.'): array
    {
        $payload = [];

        foreach ($questions as $question) {
            $payload[(string) $question->id] = $this->answerFor($question, $text);
        }

        return $payload;
    }

    private function submitWith(array $payload, Assignment $assignment, $questions)
    {
        // A REDIRECT IS NOT PROOF OF SUCCESS - a ValidationException redirects too.
        //
        // Asserting the absence of errors is what makes every caller of this helper
        // meaningful. Without it, a test could "pass" its assertRedirect() while
        // nothing was actually written, and then fail somewhere further on with a
        // null model and a message about a property instead of about submission.
        return $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'questions' => $payload,
                'idempotency_key' => 'key-'.uniqid(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    private function gradeQuestions(
        Assignment $assignment,
        AssignmentSubmission $submission,
        $questions,
        array $marks
    ) {
        $payload = [];

        foreach ($questions as $index => $question) {
            $payload[(string) $question->id] = $marks[$index];
        }

        return $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions/{$submission->id}/grade-by-question", [
                'marks' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }
}
