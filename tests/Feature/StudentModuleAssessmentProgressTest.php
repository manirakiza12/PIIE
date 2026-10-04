<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingModule;
use App\Support\CourseContent\ModuleActivityKind;
use App\Support\CourseContent\ModuleCompletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Required module assessments, as part of the student's learning journey.
 *
 * THE BUG THIS EXISTS TO FIX
 *
 * The student Course Content page derived one number from lessons and then said
 * "You have completed every lesson currently available" as though that settled
 * the matter. A student with two of two lessons done and a REQUIRED assessment
 * outstanding was told they had finished. Both halves were individually true and
 * the sentence built from them was false, which is the hardest kind of bug to see
 * from inside the code that produces each half.
 *
 * So the correction is not "change the sentence". It is: module completion is the
 * satisfaction of every REQUIRED completion-bearing activity, and the page reports
 * that alongside the lesson facts rather than instead of them.
 *
 * THE FOURTEEN PROPERTIES PINNED HERE
 *
 *   1.  all lessons complete + required assessment incomplete => module INCOMPLETE
 *   2.  required assessment not submitted                   => pending
 *   3.  submitted but not marked                            => incomplete
 *   4.  marked but not returned, where return is required   => incomplete
 *   5.  marked + returned                                    => satisfied
 *   6.  assessment satisfied + lessons satisfied             => module complete
 *   7.  an optional assessment never blocks, in any state
 *   8.  a DRAFT assessment is invisible AND does not block
 *   9.  a scheduled / not-yet-released assessment is handled correctly
 *   10. another Offering's assessment cannot affect this module
 *   11. another tenant's assessment cannot affect this module
 *   12. an unconfirmed student cannot reach the assessment
 *   13. existing lesson completion records are preserved
 *   14. no duplicate progress records are created
 *   15. the page links to the REAL assignment workflow, not a second one
 */
class StudentModuleAssessmentProgressTest extends TestCase
{
    use AssignmentFixture {
        // ALIASED and called by name. A class's own `setUp` always beats a trait's,
        // so an unaliased declaration here would shadow the fixture entirely and
        // every typed property would read as uninitialised - a symptom that looks
        // like a schema fault and is really method resolution.
        setUp as protected assignmentSetUp;
    }

    private CourseOfferingModule $module;

    /** @var array<int, CourseOfferingLesson> */
    private array $lessons = [];

    protected function setUp(): void
    {
        $this->assignmentSetUp();

        $this->module = CourseOfferingModule::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'title' => 'Introduction to math',
            'sequence' => 1,
            'status' => CourseOfferingModule::STATUS_PUBLISHED,
            'released_at' => now()->subDay(),
        ]);

        // Two PUBLISHED lessons. Published is what makes them completion
        // requirements, so the scenario is stated rather than assumed: the whole
        // point of these tests is lessons-complete-and-still-incomplete.
        foreach (['Introduction to Business Mathematics', 'Introduction to Business Mathematics'] as $index => $title) {
            $this->lessons[] = CourseOfferingLesson::query()->create([
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
    // THE SCENARIO, REBUILT
    // ══════════════════════════════════════════════════════════════════════

    /** Module 1 Assessment: published, required, satisfied only once RETURNED. */
    private function releasedMarkAssessment(array $attributes = []): Assignment
    {
        return $this->requiredModuleTask($this->module, array_merge([
            'title' => 'Module 1 Assessment',
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'max_marks' => 20,
            'due_date' => now()->addWeek(),
        ], $attributes));
    }

    /** The student completes every lesson, through the real POST. */
    private function completeEveryLesson(): void
    {
        foreach ($this->lessons as $lesson) {
            $this->actingAs($this->student)
                ->post("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}/complete")
                ->assertRedirect();
        }
    }

    private function completion(): array
    {
        return app(ModuleCompletion::class)->forModule($this->module, $this->student);
    }

    private function page(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content");
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1, 2. LESSONS COMPLETE + REQUIRED ASSESSMENT OUTSTANDING => INCOMPLETE
    // ══════════════════════════════════════════════════════════════════════

    public function test_all_lessons_complete_with_a_required_assessment_incomplete_leaves_the_module_incomplete(): void
    {
        $this->releasedMarkAssessment();
        $this->completeEveryLesson();

        $completion = $this->completion();

        // The lesson fact is untouched and still true.
        $this->assertSame(2, $completion['lessons_total']);
        $this->assertSame(2, $completion['lessons_completed']);
        $this->assertTrue($completion['lessons_complete']);

        // And the module is NOT complete, which is the entire point.
        $this->assertSame(1, $completion['assessments_gating']);
        $this->assertSame(0, $completion['assessments_satisfied']);
        $this->assertSame(1, $completion['assessments_outstanding']);
        $this->assertFalse($completion['assessments_complete']);
        $this->assertFalse($completion['is_complete'], 'every lesson done is not the same claim as the module done');
    }

    public function test_the_page_stops_claiming_the_course_is_finished(): void
    {
        $this->releasedMarkAssessment();
        $this->completeEveryLesson();

        $response = $this->page()->assertOk();

        // The sentence that was false. Asserted by its exact text because the
        // failure mode was a sentence that was TRUE in isolation and false in
        // context, so a loose "not finished" check would not have caught it.
        $response->assertDontSee('You have completed every lesson currently available');

        // The lesson fact is still stated, exactly as before.
        $response->assertSee('2 of 2 lessons completed');

        // And the module is reported as pending, in the student's own words.
        $response->assertSee('Required assessment pending');
        $response->assertSee('Assessment pending');

        // Separate dimensions, not one blended number. The required count is
        // shown against its own denominator.
        $response->assertSee('Required assessments');
        $response->assertSee('0 of 1 completed');
        $response->assertSee('0 of 1 complete');
    }

    public function test_an_unsubmitted_required_assessment_reads_as_pending(): void
    {
        $this->releasedMarkAssessment();
        $this->completeEveryLesson();

        $row = $this->completion()['assessments'][0];

        $this->assertSame('not_submitted', $row['state']);
        $this->assertSame('Not submitted', $row['state_label']);
        $this->assertFalse($row['satisfied']);
        $this->assertTrue($row['open'], 'a student can still submit to it');
    }

    public function test_the_page_points_the_student_at_the_outstanding_assessment(): void
    {
        $this->releasedMarkAssessment();
        $this->completeEveryLesson();

        $response = $this->page()->assertOk();

        $response->assertSee('Continue with');
        $response->assertSee('Module 1 Assessment');
        $response->assertSee('Required to complete this module');
        $response->assertSee('Not submitted');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3, 4, 5. EACH STATE OF THE `released_mark` RULE, IN ORDER
    // ══════════════════════════════════════════════════════════════════════

    public function test_submitted_but_not_marked_is_awaiting_marking_and_still_incomplete(): void
    {
        $task = $this->releasedMarkAssessment();
        $this->completeEveryLesson();
        $this->submission($task, $this->student);

        $completion = $this->completion();

        $this->assertSame('submitted', $completion['assessments'][0]['state']);
        $this->assertStringContainsString('awaiting marking', $completion['assessments'][0]['state_label']);
        $this->assertFalse($completion['assessments'][0]['satisfied'], 'handing work in is not the same as being assessed');
        $this->assertFalse($completion['is_complete']);

        // And the page must not congratulate them.
        $this->page()->assertOk()->assertDontSee('You are up to date');
    }

    public function test_marked_but_not_returned_is_awaiting_the_result_and_still_incomplete(): void
    {
        $task = $this->releasedMarkAssessment();
        $this->completeEveryLesson();
        $this->submission($task, $this->student, [
            'status' => 'graded',
            'marks_awarded' => 17,
            'marked_at' => now()->subMinutes(30),
        ]);

        $completion = $this->completion();

        // THE CASE THAT MATTERS MOST. A mark exists, so almost any naive
        // implementation calls this done. The lecturer configured `released_mark`,
        // and only returning the result satisfies it.
        $this->assertSame('marked', $completion['assessments'][0]['state']);
        $this->assertStringContainsString('awaiting result', $completion['assessments'][0]['state_label']);
        $this->assertFalse($completion['assessments'][0]['satisfied'], 'a mark the student has not been given is not a result');
        $this->assertFalse($completion['is_complete']);

        $this->page()->assertOk()->assertDontSee('You are up to date');
    }

    public function test_marked_and_returned_satisfies_the_assessment(): void
    {
        $task = $this->releasedMarkAssessment();
        $this->completeEveryLesson();
        $this->submission($task, $this->student, [
            'status' => 'graded',
            'marks_awarded' => 17,
            'marked_at' => now()->subMinutes(30),
            'marks_released_at' => now()->subMinutes(10),
        ]);

        $completion = $this->completion();

        $this->assertSame('returned', $completion['assessments'][0]['state']);
        $this->assertSame('Completed', $completion['assessments'][0]['state_label']);
        $this->assertTrue($completion['assessments'][0]['satisfied']);
        $this->assertSame(0, $completion['assessments_outstanding']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. BOTH PARTS SATISFIED => THE MODULE IS COMPLETE
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_satisfied_assessment_and_satisfied_lessons_complete_the_module(): void
    {
        $task = $this->releasedMarkAssessment();
        $this->completeEveryLesson();
        $this->submission($task, $this->student, [
            'status' => 'graded',
            'marks_awarded' => 17,
            'marked_at' => now()->subMinutes(30),
            'marks_released_at' => now()->subMinutes(10),
        ]);

        $completion = $this->completion();

        $this->assertTrue($completion['lessons_complete']);
        $this->assertTrue($completion['assessments_complete']);
        $this->assertTrue($completion['is_complete'], 'both parts satisfied means the module is complete');

        $response = $this->page()->assertOk();

        // ONLY now is the student told they are up to date. The old page reached
        // this sentence with the assessment outstanding.
        $response->assertSee('You are up to date');
        $response->assertSee('1 of 1 complete');
        $response->assertSee('1 of 1 completed');
        $response->assertSee('Module 1 Assessment');
    }

    public function test_the_module_state_badge_follows_the_real_state(): void
    {
        $task = $this->releasedMarkAssessment();
        $this->completeEveryLesson();

        $this->page()->assertOk()->assertSee('Assessment pending');

        $this->submission($task, $this->student, [
            'status' => 'graded',
            'marks_awarded' => 17,
            'marks_released_at' => now()->subMinutes(10),
        ]);

        $this->page()->assertOk()->assertSee('Completed');
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE RULE IS READ FROM THE ASSIGNMENT, NEVER HARDCODED GLOBALLY
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_submission_rule_is_satisfied_by_handing_work_in(): void
    {
        $task = $this->requiredModuleTask($this->module, [
            'title' => 'Module 1 Assessment',
            'completion_rule' => Assignment::RULE_SUBMISSION,
        ]);
        $this->completeEveryLesson();
        $this->submission($task, $this->student);

        $completion = $this->completion();

        // Same data, different configured rule, different answer. If this ever
        // matches the `released_mark` case above, the rule is being ignored.
        $this->assertSame('submitted', $completion['assessments'][0]['state']);
        $this->assertTrue($completion['assessments'][0]['satisfied']);
        $this->assertTrue($completion['is_complete']);
    }

    public function test_a_submission_rule_does_not_make_a_module_complete_before_the_work_is_in(): void
    {
        $this->requiredModuleTask($this->module, ['completion_rule' => Assignment::RULE_SUBMISSION]);
        $this->completeEveryLesson();

        $this->assertFalse($this->completion()['is_complete']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 7. AN OPTIONAL ASSESSMENT NEVER BLOCKS, IN ANY STATE
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_unsubmitted_optional_assessment_does_not_block_the_module(): void
    {
        $this->moduleTask($this->module, ['title' => 'Optional practice set']);
        $this->completeEveryLesson();

        $completion = $this->completion();

        $this->assertSame(1, $completion['assessments_optional']);
        $this->assertSame(0, $completion['assessments_gating']);
        $this->assertSame(0, $completion['assessments_outstanding'], 'optional work is never held against a student');
        $this->assertTrue($completion['is_complete']);
    }

    public function test_a_graded_but_unreturned_optional_assessment_does_not_block_the_module(): void
    {
        $task = $this->moduleTask($this->module, [
            'title' => 'Optional practice set',
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
        ]);
        $this->completeEveryLesson();
        $this->submission($task, $this->student, ['status' => 'graded', 'marks_awarded' => 10]);

        $this->assertTrue($this->completion()['is_complete']);
    }

    public function test_the_page_labels_an_optional_assessment_as_optional(): void
    {
        $this->moduleTask($this->module, ['title' => 'Optional practice set']);
        $this->completeEveryLesson();

        $this->page()
            ->assertOk()
            ->assertSee('Optional practice set')
            ->assertSee('It does not affect')
            // And it is NOT counted among the required ones.
            ->assertSee('None required');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 8. A DRAFT IS INVISIBLE AND DOES NOT BLOCK
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_draft_required_assessment_is_invisible_and_does_not_block(): void
    {
        // Required and unsatisfied, but never released. A lecturer's unshown work.
        $this->requiredModuleTask($this->module, [
            'title' => 'Unreleased draft assessment',
            'status' => Assignment::STATUS_DRAFT,
            'is_published' => false,
            'released_at' => null,
        ]);
        $this->completeEveryLesson();

        $completion = $this->completion();

        // THE BUG THIS CORRECTION FIXED. An earlier version counted any required
        // task, so a DRAFT blocked the module: a student stood permanently short
        // of work that did not exist for them, and could do nothing about it.
        $this->assertSame(0, $completion['assessments_total'], 'a draft is not listed');
        $this->assertSame(0, $completion['assessments_gating']);
        $this->assertSame(0, $completion['assessments_outstanding']);
        $this->assertTrue($completion['is_complete'], 'unpublished work never blocks a student');

        $this->page()
            ->assertOk()
            ->assertDontSee('Unreleased draft assessment')
            ->assertSee('You are up to date');
    }

    public function test_a_draft_alongside_a_published_required_one_still_leaves_one_outstanding(): void
    {
        $this->requiredModuleTask($this->module, [
            'title' => 'Unreleased draft assessment',
            'status' => Assignment::STATUS_DRAFT,
            'is_published' => false,
            'released_at' => null,
        ]);
        $this->releasedMarkAssessment();
        $this->completeEveryLesson();

        $completion = $this->completion();

        // Exactly one outstanding, not two. The draft contributes nothing.
        $this->assertSame(1, $completion['assessments_total']);
        $this->assertSame(1, $completion['assessments_gating']);
        $this->assertSame(1, $completion['assessments_outstanding']);
        $this->assertFalse($completion['is_complete']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 9. SCHEDULED / NOT YET RELEASED
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_scheduled_assessment_whose_moment_has_not_arrived_is_invisible_and_does_not_block(): void
    {
        $this->requiredModuleTask($this->module, [
            'title' => 'Next term assessment',
            'status' => Assignment::STATUS_SCHEDULED,
            'is_published' => false,
            'released_at' => now()->addMonth(),
        ]);
        $this->completeEveryLesson();

        $completion = $this->completion();

        // A student cannot act on something they cannot see, so it cannot be one
        // they are expected to complete.
        $this->assertSame(0, $completion['assessments_total']);
        $this->assertSame(0, $completion['assessments_outstanding']);
        $this->assertTrue($completion['is_complete']);

        $this->page()->assertOk()->assertDontSee('Next term assessment');
    }

    public function test_a_scheduled_assessment_becomes_visible_and_gating_once_its_moment_arrives(): void
    {
        $this->requiredModuleTask($this->module, [
            'title' => 'Next term assessment',
            'status' => Assignment::STATUS_SCHEDULED,
            'is_published' => false,
            'released_at' => now()->addMonth(),
        ]);
        $this->completeEveryLesson();

        $this->travelTo(now()->addMonth()->addDay());
        $this->freezeTime();

        try {
            $completion = $this->completion();

            // The same row, unchanged, now blocks - because the moment has passed.
            // A status filter alone would have hidden this forever: visibility
            // depends on the release INSTANT, not the status column.
            $this->assertSame(1, $completion['assessments_total']);
            $this->assertSame(1, $completion['assessments_gating']);
            $this->assertSame(1, $completion['assessments_outstanding']);
            $this->assertFalse($completion['is_complete']);

            $this->page()->assertOk()->assertSee('Next term assessment');
        } finally {
            $this->travelBack();
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 10, 11. OFFERING AND TENANT ISOLATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_another_offerings_required_assessment_cannot_affect_this_module(): void
    {
        $other = $this->secondOfferingAssignment();

        $secondModule = CourseOfferingModule::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $other['offeringId'],
            'title' => 'Another offering module',
            'sequence' => 1,
            'status' => CourseOfferingModule::STATUS_PUBLISHED,
            'released_at' => now()->subDay(),
        ]);

        // Required, published and unsatisfied - on the OTHER offering's module.
        $this->requiredModuleTask($secondModule, ['title' => 'Other offering required task']);

        $this->completeEveryLesson();

        $completion = $this->completion();

        $this->assertSame(0, $completion['assessments_total'], 'a task on another module is not this module’s task');
        $this->assertSame(0, $completion['assessments_outstanding'], 'another offering cannot hold this student back');
        $this->assertTrue($completion['is_complete']);

        $this->page()->assertOk()->assertDontSee('Other offering required task');
    }

    public function test_another_tenants_required_assessment_cannot_affect_this_module(): void
    {
        $theirs = $this->otherTenantAssignment();

        $theirModule = CourseOfferingModule::query()->create([
            'school_id' => $theirs['schoolId'],
            'course_offering_id' => $theirs['offeringId'],
            'title' => 'Other tenant module',
            'sequence' => 1,
            'status' => CourseOfferingModule::STATUS_PUBLISHED,
            'released_at' => now()->subDay(),
        ]);

        DB::table('assignments')->where('id', $theirs['assignmentId'])->update([
            'course_offering_module_id' => $theirModule->id,
            'requirement_role' => Assignment::ROLE_REQUIRED,
            'completion_rule' => Assignment::RULE_SUBMISSION,
        ]);

        $this->completeEveryLesson();

        $completion = $this->completion();

        $this->assertSame(0, $completion['assessments_total'], 'the school is matched as well as the module');
        $this->assertSame(0, $completion['assessments_outstanding']);
        $this->assertTrue($completion['is_complete']);

        $this->page()->assertOk()->assertDontSee('Other tenant assignment');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 12. AN UNCONFIRMED REGISTRANT CANNOT REACH THE ASSESSMENT
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_student_awaiting_confirmation_cannot_see_the_assessment_or_the_page(): void
    {
        $task = $this->releasedMarkAssessment();

        // Complete the lessons FIRST. A student whose registration is downgraded
        // mid-scenario cannot post a completion, so the evidence has to exist
        // before the authority is withdrawn - otherwise this test would be
        // asserting that a 404 happened, for the wrong reason.
        $this->completeEveryLesson();

        DB::table('course_registrations')
            ->where('student_id', $this->student->id)
            ->update(['status' => 'registered']);

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content")
            ->assertNotFound();

        $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/assignments/{$task->id}")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 13, 14. THE LESSON EVIDENCE IS NOT REWRITTEN
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_existing_lesson_completion_records_are_byte_identical_after_the_whole_assessment_flow(): void
    {
        $this->completeEveryLesson();

        $before = CourseOfferingLessonProgress::query()
            ->where('student_id', $this->student->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $this->assertCount(2, $before);

        $task = $this->releasedMarkAssessment();
        $this->submission($task, $this->student);
        $this->page()->assertOk();
        $this->submission($task, $this->student, [
            'attempt_no' => 2,
            'status' => 'graded',
            'marks_awarded' => 17,
            'marks_released_at' => now()->subMinutes(5),
        ]);
        $this->page()->assertOk();

        $after = CourseOfferingLessonProgress::query()
            ->where('student_id', $this->student->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        // Module completion is COMPUTED, never stored, so there is no field that
        // could be recomputed wrongly and no write that could damage history.
        $this->assertEquals($before, $after, 'the assessment flow must not touch lesson evidence');
    }

    public function test_no_duplicate_progress_rows_are_created_by_repeating_the_flow(): void
    {
        $this->completeEveryLesson();
        $this->completeEveryLesson();
        $this->completeEveryLesson();

        $this->assertSame(
            2,
            CourseOfferingLessonProgress::query()->where('student_id', $this->student->id)->count()
        );

        // And no stored module-completion record exists to fall out of step.
        // Named candidates rather than a schema scan, so the assertion says what
        // it is guarding: a completion FLAG would be a field that could disagree
        // with the lesson progress and the submissions it is derived from.
        foreach ([
            'course_offering_module_completions',
            'module_completions',
            'course_offering_lesson_completions',
        ] as $candidate) {
            $this->assertFalse(
                Schema::hasTable($candidate),
                "module completion is computed, so {$candidate} must not exist"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // 15. THE PAGE LINKS TO THE REAL WORKFLOW
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_page_links_to_the_real_student_assignment_workflow(): void
    {
        $task = $this->releasedMarkAssessment(['due_date' => now()->addWeek()]);
        $this->completeEveryLesson();

        $expected = route('student.courses.assignments.show', [$this->offering->id, $task->id]);

        $this->page()
            ->assertOk()
            ->assertSee($expected, false)
            ->assertSee('Open assignment')
            // The real details, not a placeholder: marks and the due date in the
            // student's own zone.
            ->assertSee('20 marks')
            ->assertSee('Due');
    }

    public function test_the_link_carries_the_student_through_to_a_submission_they_can_make(): void
    {
        $task = $this->releasedMarkAssessment();
        $this->completeEveryLesson();

        // The link on the course page is the real workflow, not a preview: it
        // resolves to the same page the student submits from.
        $this->actingAs($this->student)
            ->get(route('student.courses.assignments.show', [$this->offering->id, $task->id]))
            ->assertOk()
            ->assertSee('Module 1 Assessment');

        // And the whole loop closes. This task is configured `released_mark`, so
        // a real attempt alone is not enough - the mark has to come back to the
        // student before the module is theirs.
        $this->submission($task, $this->student, [
            'status' => 'graded',
            'marks_awarded' => 17,
            'marks_released_at' => now()->subMinutes(5),
        ]);

        $this->assertTrue($this->completion()['is_complete']);
        $this->page()->assertOk()->assertSee('You are up to date');
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE RULE IS GENERAL, NOT LESSON-ONLY
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_activity_vocabulary_is_declared_but_only_evaluable_kinds_may_gate(): void
    {
        // The kinds PIIE intends a module to hold.
        $this->assertSame([
            'lesson', 'video', 'document', 'audio', 'link',
            'live_class', 'discussion', 'quiz', 'assignment', 'exam',
        ], ModuleActivityKind::ALL);

        // Only two of them are evaluable today, and the completion rule is
        // written against THAT rather than against the wish list.
        $this->assertSame(['lesson', 'assignment'], ModuleActivityKind::IMPLEMENTED);
        $this->assertFalse(ModuleActivityKind::isImplemented(ModuleActivityKind::QUIZ), 'a declared kind is not an implemented one');
        $this->assertTrue(ModuleActivityKind::isImplemented(ModuleActivityKind::ASSIGNMENT));
        $this->assertTrue(ModuleActivityKind::isAssessment(ModuleActivityKind::EXAM));
        $this->assertFalse(ModuleActivityKind::isAssessment(ModuleActivityKind::LESSON));

        // A kind nobody can evaluate is still a declared one: the vocabulary is
        // honest about where PIIE is going, and `isImplemented()` is what callers
        // ask - never mere membership of the list.
        $this->assertTrue(ModuleActivityKind::isDeclared(ModuleActivityKind::QUIZ));
        $this->assertFalse(ModuleActivityKind::isDeclared('seminar'));

        // And the unimplemented ones are named, so the gap is visible rather than
        // forgotten - while being incapable of blocking a student.
        $this->assertContains(ModuleActivityKind::LIVE_CLASS, ModuleActivityKind::unimplemented());
    }

    public function test_a_module_holding_only_an_assessment_is_still_shown(): void
    {
        // A module with no lessons at all, and one required assessment.
        $bare = CourseOfferingModule::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'title' => 'Assessment only module',
            'sequence' => 2,
            'status' => CourseOfferingModule::STATUS_PUBLISHED,
            'released_at' => now()->subDay(),
        ]);

        $this->requiredModuleTask($bare, [
            'title' => 'Standalone required assessment',
            'completion_rule' => Assignment::RULE_SUBMISSION,
        ]);

        // It is reachable from the course page. The lesson-only filter used to
        // hide a module with no lessons, which would have made a required
        // assessment unreachable from the very page meant to point at it.
        $this->page()
            ->assertOk()
            ->assertSee('Assessment only module')
            ->assertSee('Standalone required assessment');
    }
}
