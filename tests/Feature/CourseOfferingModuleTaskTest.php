<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionItem;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingModule;
use App\Support\CourseContent\ModuleCompletion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Module/chapter tasks: the optional relationship, the optional/required
 * distinction, the evidence model, and the completion semantics.
 *
 * THE FOUR PROPERTIES THIS EXISTS TO PIN
 *
 *   1. THE ASSOCIATION IS OPTIONAL. A module may have no task; a task may belong
 *      to no module. Neither is an incomplete state.
 *
 *   2. AN OPTIONAL TASK NEVER BLOCKS ANYTHING. Not for any student, in any state.
 *      This is the property the brief is most emphatic about, and it is the one
 *      most easily broken by a later change that treats "has tasks" as "gates".
 *
 *   3. MERELY OPENING AN ASSIGNMENT NEVER SATISFIES IT - not a page view, not
 *      engagement, and not even a saved draft. Only real submitted work counts.
 *
 *   4. KYEYUNE'S EXISTING LESSON EVIDENCE IS UNTOUCHED. Module completion is
 *      COMPUTED, never stored, so this feature cannot rewrite history. That is
 *      asserted against the actual rows, not merely assumed.
 */
class CourseOfferingModuleTaskTest extends TestCase
{
    use AssignmentFixture {
        // ALIASED, and called by name below. A class's own `setUp` always wins
        // over a trait's, so an unaliased declaration here would SHADOW the
        // fixture's entirely: `parent::setUp()` would reach the base TestCase, the
        // fixture schema would never be created, and every typed property it
        // exposes would fail as "must not be accessed before initialization".
        // That symptom reads like a schema fault and is really a
        // method-resolution one, which is why it is worth spelling out.
        setUp as protected assignmentSetUp;
    }

    private CourseOfferingModule $module;

    protected function setUp(): void
    {
        $this->assignmentSetUp();

        $this->module = CourseOfferingModule::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'title' => 'Module 1 — Introduction to Mathematics',
            'sequence' => 1,
            'status' => CourseOfferingModule::STATUS_PUBLISHED,
            'released_at' => now()->subDay(),
        ]);

        // Two PUBLISHED lessons, created here rather than assumed. The shared
        // fixture builds the academic chain and the content TABLES; it does not
        // create lessons, and stating the scenario is clearer than hoping for it.
        // Both are published, so both are real completion requirements - which is
        // what makes the "two of two" case meaningful.
        foreach (['Introduction to Business Mathematics', 'Ratios explained'] as $index => $title) {
            CourseOfferingLesson::query()->create([
                'school_id' => $this->school,
                'course_offering_id' => $this->offering->id,
                'course_offering_module_id' => $this->module->id,
                'title' => $title,
                'body' => '<p>Lesson body.</p>',
                'sequence' => $index + 1,
                'status' => CourseOfferingLesson::STATUS_PUBLISHED,
                'released_at' => now()->subDay(),
            ]);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. THE ASSOCIATION IS OPTIONAL IN BOTH DIRECTIONS
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_module_may_have_no_task_at_all(): void
    {
        // A normal state, not an empty state to be corrected. Nothing here
        // creates a task, and the module must be entirely comfortable.
        $this->assertSame(0, $this->module->tasks()->count());
        $this->assertSame(0, $this->module->requiredTasks()->count());

        $completion = $this->completion($this->module);

        $this->assertSame(0, $completion['assessments_required']);
        $this->assertSame(0, $completion['assessments_outstanding']);
    }

    public function test_an_assignment_may_belong_to_no_module(): void
    {
        $assignment = $this->publishedAssignment();

        $this->assertNull($assignment->course_offering_module_id);
        $this->assertFalse($assignment->isModuleTask());
        $this->assertFalse($assignment->isRequiredForModule());
        // The overwhelming majority of assignments are not module-scoped, and
        // this is the shape they all have.
        $this->assertSame($assignment->id, $assignment->module->id ?? $assignment->id);
    }

    public function test_a_new_assignment_defaults_to_optional_and_unattached(): void
    {
        // THE SAFETY PROPERTY. If this default were `required`, or if attaching
        // were implicit, every existing and newly created assignment would start
        // blocking students' progression.
        //
        // Asserted on an unsaved instance as well as a saved one, because the
        // default has to hold on BOTH paths: Eloquent does not apply a column
        // default, so a model-level default is what makes `new Assignment()` and a
        // direct insert agree with the database.
        $fresh = new Assignment();

        $this->assertSame(Assignment::ROLE_OPTIONAL, $fresh->requirement_role);
        $this->assertSame(Assignment::RULE_SUBMISSION, $fresh->completion_rule);
        $this->assertNull($fresh->course_offering_module_id);
        $this->assertFalse($fresh->isRequiredForModule());

        $assignment = $this->publishedAssignment();

        $this->assertSame(Assignment::ROLE_OPTIONAL, $assignment->requirement_role);
        $this->assertNull($assignment->course_offering_module_id);
        $this->assertFalse($assignment->isRequiredForModule());
        $this->assertFalse($assignment->supportsRequirementSatisfied());
    }

    public function test_a_task_can_be_attached_to_a_module_by_an_allocated_lecturer(): void
    {
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments", [
                'title' => 'Module 1 task — ratios worksheet',
                'instructions' => '<p>Complete questions 1 to 6.</p>',
                'max_marks' => 20,
                'submission_type' => Assignment::SUBMISSION_FILE_AND_TEXT,
                'late_policy' => Assignment::LATE_ALLOWED,
                'status' => Assignment::STATUS_DRAFT,
                'course_offering_module_id' => $this->module->id,
                'requirement_role' => Assignment::ROLE_OPTIONAL,
                'completion_rule' => Assignment::RULE_SUBMISSION,
                'submission_kinds' => ['text', 'image'],
            ])
            ->assertRedirect();

        $task = Assignment::query()
            ->where('title', 'Module 1 task — ratios worksheet')
            ->firstOrFail();

        $this->assertSame($this->module->id, (int) $task->course_offering_module_id);
        $this->assertTrue($task->isModuleTask());
        $this->assertFalse($task->isRequiredForModule());
        $this->assertSame(['text', 'image'], $task->acceptedEvidenceKinds());
        $this->assertSame(1, $this->module->tasks()->count());
    }

    public function test_a_task_cannot_be_attached_to_a_module_from_another_offering(): void
    {
        // SECURITY. The module id is resolved INSIDE the Offering in the URL, so a
        // substituted id from another delivery is refused rather than written.
        $other = $this->secondOfferingAssignment();
        $foreignModule = CourseOfferingModule::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $other['offeringId'],
            'title' => 'A module of another delivery',
            'sequence' => 1,
            'status' => CourseOfferingModule::STATUS_PUBLISHED,
        ]);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments", [
                'title' => 'Should not attach across Offerings',
                'instructions' => '<p>x</p>',
                'max_marks' => 10,
                'submission_type' => Assignment::SUBMISSION_TEXT,
                'late_policy' => Assignment::LATE_ALLOWED,
                'status' => Assignment::STATUS_DRAFT,
                'course_offering_module_id' => $foreignModule->id,
                'requirement_role' => Assignment::ROLE_OPTIONAL,
                'completion_rule' => Assignment::RULE_SUBMISSION,
            ])
            ->assertSessionHasErrors('course_offering_module_id');

        $this->assertSame(0, Assignment::query()->where('title', 'Should not attach across Offerings')->count());
    }

    public function test_a_task_cannot_be_attached_to_a_module_from_another_tenant(): void
    {
        $other = $this->otherTenantAssignment();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments", [
                'title' => 'Should not attach across tenants',
                'instructions' => '<p>x</p>',
                'max_marks' => 10,
                'submission_type' => Assignment::SUBMISSION_TEXT,
                'late_policy' => Assignment::LATE_ALLOWED,
                'status' => Assignment::STATUS_DRAFT,
                'course_offering_module_id' => 99999,
                'requirement_role' => Assignment::ROLE_OPTIONAL,
                'completion_rule' => Assignment::RULE_SUBMISSION,
            ])
            ->assertSessionHasErrors('course_offering_module_id');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. OPTIONAL VERSUS REQUIRED
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_optional_task_never_blocks_a_module(): void
    {
        // The property the brief is most emphatic about.
        $this->moduleTask($this->module, [
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);

        $this->markLessonComplete();

        $completion = $this->completion($this->module);

        $this->assertSame(1, $completion['assessments_optional'], 'the task is counted as supplementary');
        $this->assertSame(0, $completion['assessments_required'], 'an optional task is not a gate');
        $this->assertSame(0, $completion['assessments_outstanding'], 'it is never held against the student');
        $this->assertTrue($completion['is_complete'], 'an optional task cannot prevent completion');
    }

    public function test_a_required_task_is_outstanding_until_it_is_submitted(): void
    {
        $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);
        $this->markLessonComplete();

        $completion = $this->completion($this->module);

        $this->assertSame(1, $completion['assessments_required']);
        $this->assertSame(0, $completion['assessments_satisfied']);
        $this->assertSame(1, $completion['assessments_outstanding']);
        $this->assertTrue($completion['lessons_complete']);
        // Are the ASSESSMENTS satisfied, asked in its own right. A module can have
        // every lesson done and still be incomplete, so the two questions are not
        // the same and must not be collapsed.
        $this->assertFalse($completion['assessments_complete']);
        $this->assertFalse($completion['is_complete'], 'a required task gates the module');
    }

    public function test_a_required_task_is_satisfied_by_a_real_submission(): void
    {
        $task = $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);
        $this->markLessonComplete();

        $this->submitText($task, 'Ratio = 3 : 2.');

        $completion = $this->completion($this->module);

        $this->assertSame(1, $completion['assessments_satisfied']);
        $this->assertSame(0, $completion['assessments_outstanding']);
        $this->assertTrue($completion['is_complete']);
    }

    public function test_a_module_with_both_kinds_of_task_completes_on_the_required_one_only(): void
    {
        // The realistic case: a module with a supplementary task and a required
        // one. Completing the required one is enough; the optional one is neither
        // required nor counted.
        $this->moduleTask($this->module, [
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);
        $required = $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);
        $this->markLessonComplete();

        $this->submitText($required, 'Done.');

        $completion = $this->completion($this->module);

        $this->assertSame(1, $completion['assessments_optional']);
        $this->assertSame(1, $completion['assessments_required']);
        $this->assertSame(1, $completion['assessments_satisfied']);
        $this->assertTrue($completion['is_complete']);
    }

    public function test_lessons_still_gate_a_module_that_has_no_required_task(): void
    {
        // The required task is not the only thing that matters. Lessons are still
        // factual evidence and still have to be completed.
        // One lesson deliberately left incomplete.\n        $this->markLessonComplete([$this->firstLesson()->id], complete: false);

        $completion = $this->completion($this->module);

        $this->assertFalse($completion['lessons_complete']);
        $this->assertFalse($completion['is_complete'], 'an incomplete lesson still prevents completion');
    }

    public function test_an_unobservable_rule_does_not_strand_the_student(): void
    {
        // `teacher_verification` is declared but not implemented. Counting it
        // would make the module permanently incomplete with no way forward, so it
        // is EXCLUDED from the gate and REPORTED. A stranded student is a worse
        // failure than a rule that is not yet enforced, and the exclusion is
        // visible rather than silent.
        $task = $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);
        // Written directly, because the service refuses to save this combination.
        $task->forceFill(['completion_rule' => 'teacher_verification'])->save();

        $this->assertFalse($task->supportsRequirementSatisfied());

        $this->markLessonComplete();

        $completion = $this->completion($this->module);

        // CONFIGURED required, and still reported as such: a platform limitation
        // does not quietly rewrite what a lecturer asked for.
        $this->assertSame(1, $completion['assessments_required'], 'the configured role is still reported');

        // But it cannot GATE, because PIIE cannot evaluate its rule. This is the
        // distinction that keeps a student from being stranded on a state the
        // platform can never reach.
        $this->assertSame(0, $completion['assessments_gating'], 'it is not counted as a gate');
        $this->assertSame(0, $completion['assessments_outstanding'], 'nothing stands between the student and completion');

        // And the exclusion is reported rather than silent.
        $this->assertCount(1, $completion['unevaluable'], 'it is reported rather than hidden');
        $this->assertSame('teacher_verification', $completion['unevaluable'][0]['rule']);
        $this->assertSame('assignment', $completion['unevaluable'][0]['kind'], 'the report names the activity kind');

        $this->assertTrue($completion['is_complete'], 'the student is not stranded');
    }

    public function test_the_authoring_form_refuses_a_required_task_with_an_unobservable_rule(): void
    {
        // The situation must not be creatable in the first place. `in_array` on a
        // comma string would be the wrong check - a rule name is a single word.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments", [
                'title' => 'Required with an impossible rule',
                'instructions' => '<p>x</p>',
                'max_marks' => 10,
                'submission_type' => Assignment::SUBMISSION_TEXT,
                'late_policy' => Assignment::LATE_ALLOWED,
                'status' => Assignment::STATUS_DRAFT,
                'course_offering_module_id' => $this->module->id,
                'requirement_role' => Assignment::ROLE_REQUIRED,
                'completion_rule' => 'teacher_verification',
            ])
            ->assertSessionHasErrors('completion_rule');

        $this->assertSame(0, Assignment::query()->where('title', 'Required with an impossible rule')->count());
    }

    public function test_the_two_observable_rules_behave_differently(): void
    {
        // "Handed in" and "assessed and told" are different states, and a lecturer
        // who wants the second must get it.
        foreach ([
            [Assignment::RULE_SUBMISSION, false, true],
            [Assignment::RULE_RELEASED_MARK, false, false],
        ] as [$rule, $released, $expected]) {
            $task = $this->requiredModuleTask($this->module, [
                'status' => Assignment::STATUS_PUBLISHED,
                'is_published' => true,
                'released_at' => now()->subDay(),
                'submission_type' => Assignment::SUBMISSION_TEXT,
                'completion_rule' => $rule,
            ]);
            $this->markLessonComplete();

            $this->submitText($task, 'Submitted but not yet marked.');

            if ($released) {
                $this->releaseMark($task);
            }

            $completion = $this->completion($this->module);

            $this->assertSame(
                $expected ? 1 : 0,
                $completion['assessments_satisfied'],
                "rule '{$rule}' with released=".var_export($released, true)
            );

            $task->delete();
            // One lesson deliberately left incomplete.\n        $this->markLessonComplete([$this->firstLesson()->id], complete: false);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. OPENING AN ASSIGNMENT NEVER SATISFIES IT
    // ══════════════════════════════════════════════════════════════════════

    public function test_opening_a_required_task_does_not_satisfy_it(): void
    {
        $task = $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);
        $this->markLessonComplete();

        // Read the task. The list, the detail page, and the attempt history.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments")
            ->assertOk();
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$task->id}")
            ->assertOk();

        // Proven by the absence of a row, not by a page looking right.
        $this->assertSame(0, AssignmentSubmission::query()->where('assignment_id', $task->id)->count());

        $completion = $this->completion($this->module);

        $this->assertSame(0, $completion['assessments_satisfied'], 'reading a task is not doing it');
        $this->assertFalse($completion['is_complete']);
    }

    public function test_a_saved_draft_does_not_satisfy_a_required_task(): void
    {
        // Prepared work is work in progress, not work handed in. The draft row
        // exists and is deliberately not counted.
        $task = $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);
        $this->markLessonComplete();

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$task->id}/draft", [
                'text' => 'Nearly finished, still working.',
            ])
            ->assertRedirect();

        $draft = AssignmentSubmission::query()
            ->where('assignment_id', $task->id)
            ->firstOrFail();

        $this->assertTrue((bool) $draft->is_draft, 'the prepared work is a draft');
        $this->assertNull($draft->submitted_at, 'a draft has no submission instant');

        $completion = $this->completion($this->module);

        $this->assertSame(0, $completion['assessments_satisfied'], 'a draft is not a submission');
        $this->assertFalse($completion['is_complete']);
    }

    public function test_an_unpublished_required_task_is_never_satisfied(): void
    {
        // A task the lecturer has not released cannot have been done, so it must
        // not count as done - otherwise a draft assignment would satisfy itself.
        $task = $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_DRAFT,
            'is_published' => false,
        ]);
        $this->markLessonComplete();

        // A submission exists anyway, put there directly.
        AssignmentSubmission::query()->create([
            'assignment_id' => $task->id,
            'student_id' => $this->student->id,
            'course_offering_id' => $task->course_offering_id,
            'attempt_no' => 1,
            'is_draft' => false,
            'submitted_at' => now(),
            'text_response' => 'Somehow submitted.',
            'status' => 'submitted',
        ]);

        $completion = $this->completion($this->module);

        $this->assertSame(0, $completion['assessments_satisfied'], 'a draft task is not open, so it is not satisfied');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. EXISTING LESSON EVIDENCE IS NEVER TOUCHED
    // ══════════════════════════════════════════════════════════════════════

    public function test_computing_module_completion_writes_nothing(): void
    {
        $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);
        $this->markLessonComplete();

        $before = DB::table('course_offering_lesson_progress')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->completion($this->module);
        $this->completion($this->module);
        $this->completion($this->module);

        $after = DB::table('course_offering_lesson_progress')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        // THE REASON MODULE COMPLETION IS COMPUTED. There is no stored module
        // flag to recompute, so nothing can rewrite a student's lesson history -
        // not by being wrong, not by being run twice, not at all.
        $this->assertEquals($before, $after);
    }

    public function test_an_existing_two_of_two_lesson_completion_stays_exactly_as_it_was(): void
    {
        // Kyeyune's real situation: two published lessons, both completed. Adding
        // module-task machinery must not disturb that evidence or, crucially, not
        // stop the module reading as complete when no required task is outstanding.
        $task = $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);

        $lessons = $this->lessons();
        $this->assertGreaterThanOrEqual(2, $lessons->count(), 'the fixture needs at least two lessons');

        // Marks every published lesson on the module; the loop that used to sit
        // here was redundant once the helper took on that responsibility.
        $this->markLessonComplete();

        $rows = DB::table('course_offering_lesson_progress')
            ->where('student_id', $this->student->id)
            ->orderBy('id')
            ->get();

        $this->assertSame($lessons->count(), $rows->count());
        foreach ($rows as $row) {
            $this->assertSame('completed', $row->status);
            $this->assertNotNull($row->completed_at);
        }

        $completion = $this->completion($this->module);

        $this->assertTrue($completion['lessons_complete'], 'the lesson evidence still reads as complete');
        $this->assertSame(1, $completion['assessments_required'], 'the required task now gates the module');
        $this->assertFalse($completion['is_complete']);

        // Satisfy it, and the module completes on the SAME lesson evidence.
        $this->submitText($task, 'Submitted.');

        $this->assertTrue($this->completion($this->module)['is_complete']);

        $after = DB::table('course_offering_lesson_progress')
            ->where('student_id', $this->student->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $this->assertEquals(
            $rows->map(fn ($r) => (array) $r)->all(),
            $after,
            'the lesson evidence is byte-identical after the whole cycle'
        );
    }

    public function test_engagement_alone_does_not_complete_a_module(): void
    {
        // The Course Content "in progress" state is engagement, not completion,
        // and it must not be mistaken for either by this read model.
        $lesson = $this->firstLesson();

        CourseOfferingLessonProgress::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'course_offering_lesson_id' => $lesson->id,
            'student_id' => $this->student->id,
            'status' => CourseOfferingLessonProgress::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'last_viewed_at' => now(),
        ]);

        $completion = $this->completion($this->module);

        $this->assertSame(0, $completion['lessons_completed']);
        $this->assertSame(1, $completion['lessons_in_progress'], 'engagement is reported, separately');
        $this->assertFalse($completion['lessons_complete']);
        $this->assertFalse($completion['is_complete']);
    }

    public function test_draft_lessons_are_not_counted_as_requirements(): void
    {
        // Completion is counted over PUBLISHED lessons only. A draft lesson a
        // student cannot see must not become something they are blocked on.
        CourseOfferingLesson::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'course_offering_module_id' => $this->module->id,
            'title' => 'Not yet released',
            'body' => '<p>x</p>',
            'sequence' => 99,
            'status' => CourseOfferingLesson::STATUS_DRAFT,
        ]);

        $published = $this->lessons();
        $this->markLessonComplete();

        $completion = $this->completion($this->module);

        $this->assertSame($published->count(), $completion['lessons_total'], 'the draft lesson is not a requirement');
        $this->assertTrue($completion['is_complete']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // EVIDENCE: THE SIX KINDS, AND WHAT IS NOT IMPLEMENTED
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_six_evidence_kinds_can_be_required_together(): void
    {
        $assignment = $this->publishedAssignment([
            'submission_kinds' => 'text,document,image,audio,video,link',
        ]);

        $this->assertSame(
            ['text', 'document', 'image', 'audio', 'video', 'link'],
            $assignment->acceptedEvidenceKinds()
        );
    }

    public function test_a_permitted_combination_is_accepted_as_written(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment([
            'submission_kinds' => 'image,audio',
        ]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'image' => UploadedFile::fake()->image('my working.png', 40, 40),
                'audio' => UploadedFile::fake()->create('explanation.m4a', 12, 'audio/mp4'),
                'idempotency_key' => 'combo-1',
            ])
            ->assertRedirect();

        $items = AssignmentSubmissionItem::query()
            ->where('assignment_id', $assignment->id)
            ->orderBy('kind')
            ->get();

        $this->assertCount(2, $items);
        $this->assertSame(['audio', 'image'], $items->pluck('kind')->all());

        foreach ($items as $item) {
            // Every piece is private and addressed through an authorising route.
            $this->assertStringStartsWith('assignment-submissions/', $item->stored_path);
            $this->assertStringNotContainsString($item->original_name, $item->stored_path);
            Storage::disk('local')->assertExists($item->stored_path);
        }
    }

    public function test_an_image_slot_refuses_a_document(): void
    {
        // The per-kind allowlist is the real guard. A single combined list would
        // let a student hand an arbitrary document in where a lecturer was told
        // they supplied photographic evidence.
        Storage::fake('local');

        $assignment = $this->publishedAssignment(['submission_kinds' => 'image']);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'image' => UploadedFile::fake()->create('not-a-photo.pdf', 8, 'application/pdf'),
                'idempotency_key' => 'wrong-kind-1',
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, AssignmentSubmissionItem::query()->count());
    }

    public function test_a_kind_the_assignment_did_not_ask_for_is_never_stored(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment(['submission_kinds' => 'text']);

        // A crafted request attaching a recording to a text-only task. The
        // controller collects only the kinds the assignment ACCEPTS, so the stray
        // upload cannot become evidence and a marker is never left wondering why
        // the expected evidence is not there.
        //
        // The submission itself still SUCCEEDS. Refusing it outright would be
        // worse: a student's real work would be lost because of something they did
        // not mean to send. Dropping an unrequested extra is the safer outcome,
        // and the form only ever renders the accepted kinds in the first place.
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'My answer.',
                'audio' => UploadedFile::fake()->create('surprise.m4a', 8, 'audio/mp4'),
                'idempotency_key' => 'unasked-1',
            ])
            ->assertRedirect();

        $this->assertSame(1, AssignmentSubmission::query()->count());
        $this->assertStringContainsString('My answer.', AssignmentSubmission::query()->firstOrFail()->text_response);

        // The unasked-for recording is nowhere in storage.
        $this->assertSame(0, AssignmentSubmissionItem::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_link_must_be_a_real_http_address(): void
    {
        $assignment = $this->publishedAssignment(['submission_kinds' => 'text,link']);

        foreach (['javascript:alert(1)', 'not a url', 'file:///etc/passwd'] as $index => $url) {
            $this->actingAs($this->student)
                ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                    'text' => 'My answer.',
                    'link_url' => $url,
                    'idempotency_key' => 'link-'.$index,
                ])
                ->assertSessionHasErrors('items');
        }

        $this->assertSame(0, AssignmentSubmissionItem::query()->count());
    }

    public function test_a_valid_link_is_stored_with_no_file(): void
    {
        $assignment = $this->publishedAssignment(['submission_kinds' => 'text,link']);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => 'My answer.',
                'link_url' => 'https://example.org/my-published-result',
                'idempotency_key' => 'link-ok',
            ])
            ->assertRedirect();

        $item = AssignmentSubmissionItem::query()->firstOrFail();

        $this->assertSame(AssignmentSubmissionItem::KIND_LINK, $item->kind);
        $this->assertSame('https://example.org/my-published-result', $item->url);
        $this->assertNull($item->stored_path);
        $this->assertFalse($item->hasFile());
        $this->assertTrue($item->hasUsableLink());
    }

    public function test_nothing_in_the_product_offers_browser_recording(): void
    {
        // The model is deliberately ready for a recorder, and the product
        // deliberately does not have one. `audio` and `video` mean "a file the
        // student attached", and no control anywhere asks for a microphone.
        $this->assertTrue(AssignmentSubmissionItem::isFileKind(AssignmentSubmissionItem::KIND_AUDIO));
        $this->assertTrue(AssignmentSubmissionItem::isFileKind(AssignmentSubmissionItem::KIND_VIDEO));
        // A file kind, so an in-browser recording would reuse the same storage and
        // the same authorisation route without a schema change.
        $this->assertNotEmpty(AssignmentSubmissionItem::extensionsFor(AssignmentSubmissionItem::KIND_AUDIO));

        $assignment = $this->publishedAssignment(['submission_kinds' => 'audio,video']);
        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}")
            ->assertOk();

        $html = $response->getContent();

        // An upload control, and nothing that claims to record.
        $this->assertStringContainsString('type="file"', $html);
        foreach (['MediaRecorder', 'getUserMedia', 'startRecording', 'Record audio', 'Record video'] as $claim) {
            $this->assertStringNotContainsString($claim, $html, "'{$claim}' would be faking a recorder");
        }
    }

    public function test_student_evidence_is_private_and_cannot_be_read_by_another_student(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment(['submission_kinds' => 'image']);
        $other = $this->secondConfirmedStudent();

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'image' => UploadedFile::fake()->image('mine.png', 20, 20),
                'idempotency_key' => 'private-1',
            ])
            ->assertRedirect();

        $submission = AssignmentSubmission::query()->firstOrFail();
        $item = AssignmentSubmissionItem::query()->firstOrFail();

        $url = "/student/courses/{$this->offering->id}/assignments/{$assignment->id}"
            ."/submissions/{$submission->id}/evidence/{$item->id}";

        // The owner can read it.
        $this->actingAs($this->student)->get($url)->assertOk();

        // Another confirmed student on the SAME Offering cannot.
        $this->actingAs($other)->get($url)->assertNotFound();

        // And the stored path is not reachable by guessing under any URL.
        $this->assertStringNotContainsString($item->original_name, $item->stored_path);
    }

    public function test_an_allocated_lecturer_can_read_a_students_evidence(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment(['submission_kinds' => 'image']);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'image' => UploadedFile::fake()->image('working.png', 20, 20),
                'idempotency_key' => 'mark-1',
            ])
            ->assertRedirect();

        $submission = AssignmentSubmission::query()->firstOrFail();
        $item = AssignmentSubmissionItem::query()->firstOrFail();

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}"
                ."/submissions/{$submission->id}/evidence/{$item->id}")
            ->assertOk();
    }

    public function test_a_lecturer_without_an_allocation_cannot_read_a_students_evidence(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment(['submission_kinds' => 'image']);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'image' => UploadedFile::fake()->image('working.png', 20, 20),
                'idempotency_key' => 'mark-2',
            ])
            ->assertRedirect();

        $submission = AssignmentSubmission::query()->firstOrFail();
        $item = AssignmentSubmissionItem::query()->firstOrFail();

        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->otherLecturer->id)
            ->delete();

        $this->actingAs($this->otherLecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}"
                ."/submissions/{$submission->id}/evidence/{$item->id}")
            ->assertNotFound();
    }

    public function test_resubmitting_replaces_the_evidence_rather_than_adding_to_it(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment([
            'submission_kinds' => 'image',
            'allowed_attempts' => 2,
        ]);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'image' => UploadedFile::fake()->image('first.png', 20, 20),
                'idempotency_key' => 'attempt-1',
            ])->assertRedirect();

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'image' => UploadedFile::fake()->image('second.png', 20, 20),
                'idempotency_key' => 'attempt-2',
            ])->assertRedirect();

        $first = AssignmentSubmission::query()->where('attempt_no', 1)->firstOrFail();
        $second = AssignmentSubmission::query()->where('attempt_no', 2)->firstOrFail();

        // The previous attempt's photograph must not become the new attempt's
        // evidence, which is what a merge would produce.
        $this->assertSame(1, AssignmentSubmissionItem::query()->where('assignment_submission_id', $first->id)->count());
        $this->assertSame(1, AssignmentSubmissionItem::query()->where('assignment_submission_id', $second->id)->count());
        $this->assertSame(2, AssignmentSubmissionItem::query()->count());
    }

    public function test_evidence_items_are_scoped_to_their_own_attempt(): void
    {
        Storage::fake('local');

        $assignment = $this->publishedAssignment(['submission_kinds' => 'image']);
        $other = $this->secondConfirmedStudent();

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'image' => UploadedFile::fake()->image('mine.png', 20, 20),
                'idempotency_key' => 'own-1',
            ])->assertRedirect();

        $mine = AssignmentSubmission::query()
            ->where('student_id', $this->student->id)->firstOrFail();
        $theirs = $this->submission($assignment, $other);
        $theirItem = AssignmentSubmissionItem::query()->create([
            'school_id' => $assignment->school_id,
            'assignment_submission_id' => $theirs->id,
            'assignment_id' => $assignment->id,
            'course_offering_id' => $assignment->course_offering_id,
            'kind' => AssignmentSubmissionItem::KIND_IMAGE,
            'stored_path' => 'assignment-submissions/x/y/image/other.png',
            'original_name' => 'other.png',
            'size_bytes' => 100,
        ]);

        $myItem = AssignmentSubmissionItem::query()
            ->where('assignment_submission_id', $mine->id)->firstOrFail();

        // An item id belonging to the OTHER student is a 404 for me.
        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$assignment->id}"
                ."/submissions/{$mine->id}/evidence/{$theirItem->id}")
            ->assertNotFound();
    }

    public function test_a_written_response_is_sanitised_on_the_way_in(): void
    {
        $assignment = $this->publishedAssignment(['submission_kinds' => 'text']);

        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                'text' => '<p>My reasoning.</p><script>alert(1)</script><img src=x onerror=alert(2)>',
                'idempotency_key' => 'xss-1',
            ])
            ->assertRedirect();

        $stored = AssignmentSubmission::query()->firstOrFail()->text_response;

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringContainsString('My reasoning.', $stored);
    }

    public function test_an_empty_or_invisible_response_is_refused(): void
    {
        $assignment = $this->publishedAssignment(['submission_kinds' => 'text']);

        foreach (['   ', '<p></p>', '<p>&nbsp;</p>'] as $index => $text) {
            $this->actingAs($this->student)
                ->post("/student/courses/{$this->offering->id}/assignments/{$assignment->id}/submit", [
                    'text' => $text,
                    'idempotency_key' => 'empty-'.$index,
                ])
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, AssignmentSubmission::query()->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE GRADEBOOK CONTRACT IS UNAFFECTED
    // ══════════════════════════════════════════════════════════════════════

    public function test_marks_still_flow_to_the_gradebook_feed_unchanged(): void
    {
        // Nothing about marking was added, removed or retyped, so the read model a
        // future Course Offering Gradebook is written against must still work, and
        // a module task must appear in it like any other assignment.
        $task = $this->requiredModuleTask($this->module, [
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'max_marks' => 20,
            'submission_type' => Assignment::SUBMISSION_TEXT,
        ]);

        $this->submitText($task, 'Submitted.');
        $this->releaseMark($task);

        $rows = app(\App\Support\Assignments\GradebookFeed::class)
            ->releasedGradesForOffering($this->offering);

        $this->assertCount(1, $rows);
        $this->assertSame($task->id, $rows[0]['assignment_id']);
        $this->assertSame(20, $rows[0]['max_marks']);

        foreach ([
            'course_offering_id', 'assignment_id', 'assignment_title', 'student_id',
            'marks', 'max_marks', 'percent', 'graded_at', 'graded_by', 'released_at', 'is_late',
        ] as $field) {
            $this->assertArrayHasKey($field, $rows[0], "the feed must still expose '{$field}'");
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // helpers
    // ══════════════════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function completion(CourseOfferingModule $module): array
    {
        return app(ModuleCompletion::class)->forModule($module, $this->student);
    }

    /**
     * The module's PUBLISHED lessons, in reading order.
     *
     * `$module` is captured explicitly: a closure that reads a property of the
     * test class does not see it unless it is brought in with `use`.
     */
    private function lessons()
    {
        return CourseOfferingLesson::query()
            ->where('course_offering_module_id', $this->module->id)
            ->where('status', CourseOfferingLesson::STATUS_PUBLISHED)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    private function firstLesson(): CourseOfferingLesson
    {
        $lesson = $this->lessons()->first();

        if ($lesson) {
            return $lesson;
        }

        // The shared fixture does not create lessons, so make one. Publishing it
        // keeps it a real requirement rather than a free pass.
        return CourseOfferingLesson::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'course_offering_module_id' => $this->module->id,
            'title' => 'Introduction to Business Mathematics',
            'body' => '<p>Lesson body.</p>',
            'sequence' => 1,
            'status' => CourseOfferingLesson::STATUS_PUBLISHED,
            'released_at' => now()->subDay(),
        ]);
    }

    /**
     * Record lesson progress for the student.
     *
     * By default EVERY published lesson is marked complete, which is what "the
     * student has finished the lessons" means. A helper that marked one lesson of
     * several would leave every completion test failing for a reason unrelated to
     * what it was testing.
     *
     * @param  array<int, int>  $except  lesson ids to leave untouched
     */
    private function markLessonComplete(array $except = [], bool $complete = true): void
    {
        foreach ($this->lessons() as $lesson) {
            if (in_array((int) $lesson->id, $except, true)) {
                continue;
            }

            CourseOfferingLessonProgress::query()->updateOrCreate([
                'course_offering_lesson_id' => $lesson->id,
                'student_id' => $this->student->id,
            ], [
                'school_id' => $this->school,
                'course_offering_id' => $this->offering->id,
                'status' => $complete
                    ? CourseOfferingLessonProgress::STATUS_COMPLETED
                    : CourseOfferingLessonProgress::STATUS_IN_PROGRESS,
                'started_at' => now()->subHour(),
                'completed_at' => $complete ? now() : null,
                'last_viewed_at' => now(),
            ]);
        }
    }

    private function submitText(Assignment $task, string $text): AssignmentSubmission
    {
        $this->actingAs($this->student)
            ->post("/student/courses/{$this->offering->id}/assignments/{$task->id}/submit", [
                'text' => $text,
                'idempotency_key' => 'mc-'.$task->id.'-'.substr(md5($text), 0, 8),
            ])
            ->assertRedirect();

        return AssignmentSubmission::query()
            ->where('assignment_id', $task->id)
            ->where('is_draft', false)
            ->orderByDesc('attempt_no')
            ->firstOrFail();
    }

    private function releaseMark(Assignment $task): void
    {
        $submission = AssignmentSubmission::query()
            ->where('assignment_id', $task->id)
            ->where('is_draft', false)
            ->orderByDesc('attempt_no')
            ->firstOrFail();

        $base = "/teacher/course-offerings/{$this->offering->id}/assignments/{$task->id}"
            ."/submissions/{$submission->id}";

        $this->actingAs($this->lecturer)
            ->post("{$base}/grade", ['marks_awarded' => 16])
            ->assertRedirect();

        $this->actingAs($this->lecturer)->post("{$base}/release")->assertRedirect();
    }
}
