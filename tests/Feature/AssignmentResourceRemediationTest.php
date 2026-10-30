<?php

namespace Tests\Feature;

use App\Models\AssignmentResource;
use App\Support\Assignments\AssignmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

class AssignmentResourceRemediationTest extends TestCase
{
    use AssignmentFixture;

    public function test_question_file_is_saved_and_visible_to_teacher_and_enrolled_student(): void
    {
        Storage::fake('local');
        $assignment = $this->publishedAssignment();
        $question = $this->questionAccepting($assignment, 'text');
        $this->actingAs($this->lecturer)->post(route('teacher.course_offerings.assignments.resources.store', [$this->offering->id, $assignment->id]), [
            'type' => 'file', 'link_url' => null, 'assignment_question_id' => $question->id,
            'file' => UploadedFile::fake()->create('log-template.docx', 10),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $resource = $question->resources()->firstOrFail();
        Storage::disk('local')->assertExists($resource->stored_name);
        $this->actingAs($this->lecturer)->get(route('teacher.course_offerings.assignments.questions', [$this->offering->id, $assignment->id]))
            ->assertOk()->assertSee('log-template.docx');
        $this->actingAs($this->student)->get(route('student.courses.assignments.show', [$this->offering->id, $assignment->id]))
            ->assertOk()->assertSee('log-template.docx');
        $this->actingAs($this->student)->get(route('student.courses.assignments.resources.file', $resource->id))->assertOk();
    }

    public function test_question_resource_cannot_target_a_different_assignment(): void
    {
        $assignment = $this->assignment();
        $question = $this->question($this->assignment());
        $this->actingAs($this->lecturer)->post(route('teacher.course_offerings.assignments.resources.store', [$this->offering->id, $assignment->id]), [
            'type' => 'link', 'link_url' => 'https://example.org/logs', 'assignment_question_id' => $question->id,
        ])->assertNotFound();
        $this->assertDatabaseCount('assignment_resources', 0);
    }

    public function test_question_resources_freeze_after_submission(): void
    {
        $assignment = $this->publishedAssignment();
        $question = $this->question($assignment);
        $resource = app(AssignmentService::class)->attachLink($this->lecturer, $assignment, 'Logs', 'https://example.org/logs', $question);
        $this->submission($assignment, $this->student);
        $this->actingAs($this->lecturer)->post(route('teacher.course_offerings.assignments.resources.destroy', $resource->id))->assertSessionHasErrors();
        $this->assertDatabaseHas('assignment_resources', ['id' => $resource->id]);
        $this->actingAs($this->lecturer)->post(route('teacher.course_offerings.assignments.resources.store', [$this->offering->id, $assignment->id]), [
            'type' => 'link', 'link_url' => 'https://example.org/changed', 'assignment_question_id' => $question->id,
        ])->assertSessionHasErrors();
        $this->assertDatabaseCount('assignment_resources', 1);
    }

    public function test_removing_an_unanswered_question_preserves_its_handout_on_the_assignment(): void
    {
        $assignment = $this->assignment();
        $question = $this->question($assignment);
        $resource = app(AssignmentService::class)->attachLink($this->lecturer, $assignment, 'Logs', 'https://example.org/logs', $question);
        $this->actingAs($this->lecturer)->delete(route('teacher.course_offerings.assignments.questions.destroy', [$this->offering->id, $assignment->id, $question->id]))
            ->assertSessionHasNoErrors();
        $this->assertNull($resource->fresh()->assignment_question_id);
        $this->assertSame($assignment->id, $resource->fresh()->assignment_id);
    }

    public function test_meaningful_html_question_persists_and_empty_markup_is_rejected(): void
    {
        $assignment = $this->assignment();
        $route = route('teacher.course_offerings.assignments.questions.store', [$this->offering->id, $assignment->id]);
        $this->actingAs($this->lecturer)->post($route, [
            'prompt' => '<h2>Daily logs</h2><p>Describe <strong>three</strong> entries.</p>',
            'marks' => 10, 'response_kinds' => ['text'],
        ])->assertSessionHasNoErrors();
        $this->assertStringContainsString('<strong>three</strong>', $assignment->questions()->firstOrFail()->prompt);
        foreach (['<p><br></p>', '<p>&nbsp; &#8203;</p>', '   '] as $empty) {
            $this->actingAs($this->lecturer)->post($route, ['prompt' => $empty, 'marks' => 1, 'response_kinds' => ['text']])
                ->assertSessionHasErrors('prompt');
        }
        $this->assertSame(1, $assignment->questions()->count());
    }
}
