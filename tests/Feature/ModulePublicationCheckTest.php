<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingModule;
use App\Support\CourseContent\ModulePublicationCheck;
use DomainException;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Stage 5 of the teaching cycle - "validate and publish" - which did not exist.
 *
 * Publishing a module validated only the release date and the state transition,
 * so a module with no lessons and no assessments published cleanly and reached
 * students as an empty topic. That is the reported symptom of "content appears
 * with no assignment", and it is reproduced here before it is fixed.
 *
 * THE LINE DRAWN DELIBERATELY
 *
 * A module with nothing in it is REFUSED. A module with lessons but no required
 * assessment is only WARNED, because a reading-only topic is a legitimate shape
 * and forcing an assessment onto one would push lecturers into inventing filler
 * work. The refusal is the case with no defensible exception.
 */
class ModulePublicationCheckTest extends TestCase
{
    // `AssignmentFixture`, not `CourseContentFixture`: the publication check
    // counts required assessments, so the assignments table has to exist.
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    private ModulePublicationCheck $check;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignmentSetUp();

        $this->check = new ModulePublicationCheck;
    }

    // ── The refusal ─────────────────────────────────────────────────────────

    public function test_a_module_with_nothing_in_it_cannot_be_published(): void
    {
        $module = $this->module(['title' => 'Empty topic']);

        $result = $this->check->evaluate($module);

        $this->assertFalse($result['ready']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_publishing_an_empty_module_is_refused_with_a_reason(): void
    {
        $module = $this->module(['title' => 'Empty topic']);

        // Reproduces the defect: before this check, this call succeeded.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/nothing for a student to do/i');

        $this->check->assertPublishable($module);
    }

    // ── The warning, which must NOT block ───────────────────────────────────

    public function test_a_reading_only_module_without_a_lesson_assignment_is_blocked(): void
    {
        $module = $this->module(['title' => 'Reading only']);
        $this->lesson($module, ['status' => CourseOfferingLesson::STATUS_PUBLISHED]);

        $result = $this->check->evaluate($module);

        $this->assertFalse($result['ready'], 'each published lesson now requires its own assignment');
        $this->assertNotEmpty($result['warnings'], 'but the lecturer is told nothing checks understanding');
    }

    public function test_a_module_with_a_required_assessment_and_no_pass_mark_is_warned_about(): void
    {
        $module = $this->module(['title' => 'Unthresholded']);
        $this->lesson($module, ['status' => CourseOfferingLesson::STATUS_PUBLISHED, 'assignment_id' => $this->publishedAssignment()->id]);

        $assessment = Assignment::query()->create([
            'school_id' => $module->school_id,
            'course_offering_id' => $module->course_offering_id,
            'course_offering_module_id' => $module->id,
            'title' => 'Unthresholded assessment',
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'max_marks' => 100,
            'requirement_role' => Assignment::ROLE_REQUIRED,
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'pass_mark' => null,
        ]);

        $result = $this->check->evaluate($module);

        $this->assertTrue($result['ready']);
        $this->assertStringContainsString(
            'including zero',
            implode(' ', $result['warnings']),
            'the warning must say plainly that zero passes'
        );
    }

    public function test_a_thresholded_required_assessment_produces_no_threshold_warning(): void
    {
        $module = $this->module(['title' => 'Thresholded']);
        $this->lesson($module, ['status' => CourseOfferingLesson::STATUS_PUBLISHED, 'assignment_id' => $this->publishedAssignment()->id]);

        Assignment::query()->create([
            'school_id' => $module->school_id,
            'course_offering_id' => $module->course_offering_id,
            'course_offering_module_id' => $module->id,
            'title' => 'Thresholded assessment',
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'max_marks' => 100,
            'requirement_role' => Assignment::ROLE_REQUIRED,
            'completion_rule' => Assignment::RULE_RELEASED_MARK,
            'pass_mark' => 50,
        ]);

        $result = $this->check->evaluate($module);

        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['warnings'], 'a lecturer who has set a pass mark needs no warning about it');
    }

    // ── The distinction that carries the whole feature ──────────────────────

    public function test_an_optional_assessment_does_not_satisfy_the_required_assessment_rule(): void
    {
        // Optional work is supplementary in every state. It must not silence the
        // warning, because it gates nothing and checks nothing.
        $module = $this->module(['title' => 'Only optional']);
        $this->lesson($module, ['status' => CourseOfferingLesson::STATUS_PUBLISHED, 'assignment_id' => $this->publishedAssignment()->id]);

        Assignment::query()->create([
            'school_id' => $module->school_id,
            'course_offering_id' => $module->course_offering_id,
            'course_offering_module_id' => $module->id,
            'title' => 'Optional extra',
            'status' => Assignment::STATUS_PUBLISHED,
            'is_published' => true,
            'released_at' => now()->subDay(),
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'max_marks' => 100,
            'requirement_role' => Assignment::ROLE_OPTIONAL,
            'completion_rule' => Assignment::RULE_SUBMISSION,
        ]);

        $result = $this->check->evaluate($module);

        $this->assertTrue($result['ready']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_a_draft_lesson_does_not_count_as_content(): void
    {
        // A module whose only lesson is a draft has nothing a student can see, so
        // counting it would let an effectively empty module publish.
        $module = $this->module(['title' => 'Draft only']);
        $this->lesson($module, ['status' => CourseOfferingLesson::STATUS_DRAFT]);

        $result = $this->check->evaluate($module);

        $this->assertFalse($result['ready']);
    }
}
