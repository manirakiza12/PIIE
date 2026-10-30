<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Support\Assignments\SubmissionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

class StudentAssignmentSubmissionExperienceTest extends TestCase
{
    use AssignmentFixture { setUp as protected fixtureSetUp; }

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        Storage::fake('local');
    }

    private function task(string $kinds, ?string $mode = 'all', ?string $optional = null): Assignment
    {
        return $this->publishedAssignment(['submission_kinds' => $kinds, 'submission_requirement_mode' => $mode,
            'optional_submission_kinds' => $optional, 'allowed_attempts' => 2]);
    }

    private function endpoint(Assignment $assignment, string $action = ''): string
    {
        return "/student/courses/{$this->offering->id}/assignments/{$assignment->id}".($action ? '/'.$action : '');
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('answer.pdf', "%PDF-1.4\nSynthetic assignment answer.\n%%EOF");
    }

    public function test_all_lecturer_questions_arrive_once_and_remain_readable_after_submission(): void
    {
        $task = $this->task('text');
        $task->update(['allowed_attempts' => 1, 'max_marks' => 10]);
        $one = $this->questionAccepting($task, 'text', ['marks' => 5, 'prompt' => '<p>Explain the first principle.</p>']);
        $two = $this->questionAccepting($task, 'text', ['marks' => 5, 'prompt' => '<p>Explain the second principle.</p>']);
        $this->actingAs($this->student)->get($this->endpoint($task))->assertOk()
            ->assertSee('Question 1 of 2')->assertSee('Question 2 of 2')
            ->assertSee('data-as-question="'.$one->id.'"', false)
            ->assertSee('Explain the first principle.')->assertSee('Explain the second principle.');
        $this->post($this->endpoint($task, 'submit'), ['questions' => [
            $one->id => ['text' => '<p>My first answer.</p>'],
            $two->id => ['text' => '<p>My second answer.</p>'],
        ], 'idempotency_key' => 'question-delivery'])->assertSessionHasNoErrors();
        $this->get($this->endpoint($task))->assertOk()->assertSee('as-readonly-questions')
            ->assertSee('Explain the first principle.')->assertSee('Explain the second principle.')
            ->assertSee('My first answer.')->assertSee('My second answer.')
            ->assertDontSee('id="as-form"', false);
        $attempt = AssignmentSubmission::where('is_draft', false)->firstOrFail();
        $this->get(route('student.courses.assignments.submissions.show', [$this->offering->id, $task->id, $attempt->id]))
            ->assertOk()->assertSee('Question 1 of 2')->assertSee('Question 2 of 2')
            ->assertSee('My first answer.')->assertSee('My second answer.');
    }

    public function test_document_only_renders_one_form_and_submits_without_other_media(): void
    {
        $task = $this->task('document');
        $page = $this->actingAs($this->student)->get($this->endpoint($task))->assertOk();
        $dom = new \DOMDocument(); @$dom->loadHTML($page->getContent()); $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//form[@id="as-form"]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="document"]')->length);
        foreach (['image', 'audio', 'video', 'link_url', 'text'] as $field) {
            $this->assertSame(0, $xpath->query('//*[@name="'.$field.'"]')->length);
        }
        $page->assertSee('Instructions')->assertSee('Save Draft')->assertSee('Review and submit');
        if (getenv('PIIE_EXPORT_ASSIGNMENT_FIXTURES') === '1') {
            @mkdir(base_path('output'), 0777, true);
            file_put_contents(base_path('output/student-assignment-document.html'), $page->getContent());
        }
        $this->post($this->endpoint($task, 'submit'), ['document' => $this->pdf(), 'idempotency_key' => 'doc-1'])->assertSessionHasNoErrors();
        $this->assertSame(1, AssignmentSubmission::where('is_draft', false)->count());
        $attempt = AssignmentSubmission::where('is_draft', false)->firstOrFail();
        $this->get(route('student.courses.assignments.submissions.show', [$this->offering->id, $task->id, $attempt->id]))
            ->assertOk()->assertSee('answer.pdf');
    }

    public function test_returned_question_feedback_belongs_to_the_submitted_attempt_while_editing_a_new_draft(): void
    {
        $task = $this->task('text');
        $question = $this->questionAccepting($task, 'text', ['marks' => 20]);
        $service = app(SubmissionService::class);
        $attempt = $service->submit($this->student, $task, ['questions' => [$question->id => ['text' => 'First answer']]], 'feedback-attempt');
        $attempt->questionResponses()->update(['marks_awarded' => 15, 'feedback' => 'Feedback on the submitted answer']);
        $attempt->update(['marks_awarded' => 15, 'marks_released_at' => now()]);
        $service->saveDraft($this->student, $task, ['questions' => [$question->id => ['text' => 'New unfinished answer']]]);
        $this->actingAs($this->student)->get($this->endpoint($task))->assertOk()
            ->assertSee('Feedback on the submitted answer')->assertSee('New unfinished answer')
            ->assertViewHas('releasedQuestionAnswers', fn ($answers) => (int) $answers[$question->id]->marks_awarded === 15)
            ->assertViewHas('questionAnswers', fn ($answers) => $answers[$question->id]->marks_awarded === null);
    }

    public function test_written_only_and_written_plus_document_rules(): void
    {
        $text = $this->task('text');
        $this->actingAs($this->student)->post($this->endpoint($text, 'submit'), ['text' => 'My answer', 'idempotency_key' => 'text-1'])->assertSessionHasNoErrors();
        $both = $this->task('text,document');
        $this->post($this->endpoint($both, 'submit'), ['text' => 'Work in progress', 'idempotency_key' => 'both-1'])->assertSessionHasErrors('document');
        $this->assertSame(1, AssignmentSubmission::where('assignment_id', $both->id)->where('is_draft', true)->count());
        $this->post($this->endpoint($both, 'submit'), ['text' => 'Finished answer', 'document' => $this->pdf(), 'idempotency_key' => 'both-1'])->assertSessionHasNoErrors();
    }

    public function test_any_one_primary_method_is_sufficient_but_optional_work_is_not(): void
    {
        $task = $this->task('text,document', 'any', 'link');
        $page = $this->actingAs($this->student)->get($this->endpoint($task))->assertOk()->assertSee('Choose any one accepted method:');
        if (getenv('PIIE_EXPORT_ASSIGNMENT_FIXTURES') === '1') {
            @mkdir(base_path('output'), 0777, true);
            file_put_contents(base_path('output/student-assignment-any.html'), $page->getContent());
        }
        $this->post($this->endpoint($task, 'submit'), ['link_url' => 'https://example.org/support', 'idempotency_key' => 'any-1'])->assertSessionHasErrors('items');
        $this->post($this->endpoint($task, 'submit'), ['document' => $this->pdf(), 'idempotency_key' => 'any-1'])->assertSessionHasNoErrors();
        $this->assertSame(2, AssignmentSubmission::where('assignment_id', $task->id)->first()->items()->count());
        $other = $this->task('text,document', 'any');
        $this->post($this->endpoint($other, 'submit'), ['text' => 'Text is sufficient', 'idempotency_key' => 'any-text'])->assertSessionHasNoErrors();
    }

    public function test_optional_attachments_may_be_omitted_or_included(): void
    {
        $task = $this->task('document', 'all', 'image,link');
        $this->actingAs($this->student)->post($this->endpoint($task, 'submit'), ['document' => $this->pdf(), 'idempotency_key' => 'optional-1'])->assertSessionHasNoErrors();
        $task2 = $this->task('text', 'all', 'image');
        $this->post($this->endpoint($task2, 'submit'), ['text' => 'Explanation', 'image' => UploadedFile::fake()->image('working.png'), 'idempotency_key' => 'optional-2'])->assertSessionHasNoErrors();
    }

    public function test_required_media_is_enforced_and_legacy_rules_are_unchanged(): void
    {
        $task = $this->task('document,image,audio,video,link', null);
        $this->assertTrue($task->requiresAllResponseKinds());
        $this->assertStringContainsString(' and ', $task->evidenceRequirementLabel());
        $this->actingAs($this->student)->post($this->endpoint($task, 'submit'), ['document' => $this->pdf(), 'idempotency_key' => 'legacy-1'])
            ->assertSessionHasErrors(['image', 'audio', 'video', 'link_url']);
        $legacy = $this->publishedAssignment(['submission_type' => 'file']);
        $this->assertSame(['document'], $legacy->responseKinds());
        $this->post($this->endpoint($legacy, 'submit'), ['document' => $this->pdf(), 'idempotency_key' => 'legacy-file'])->assertSessionHasNoErrors();
    }

    public function test_incomplete_drafts_resume_and_retain_files_on_submit(): void
    {
        $task = $this->task('text,document');
        $this->actingAs($this->student)->post($this->endpoint($task, 'draft'), ['document' => $this->pdf()])->assertSessionHasNoErrors();
        $draft = AssignmentSubmission::where('assignment_id', $task->id)->first(); $path = $draft->items->first()->stored_path;
        $this->get($this->endpoint($task))->assertOk()->assertSee('Already attached:')->assertSee('answer.pdf');
        $this->post($this->endpoint($task, 'draft'), ['text' => 'Partial text'])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($path);
        $this->post($this->endpoint($task, 'submit'), ['text' => 'Final text', 'idempotency_key' => 'resume-1'])->assertSessionHasNoErrors();
        $this->assertSame(1, AssignmentSubmission::where('assignment_id', $task->id)->count());
        $this->assertFalse($draft->fresh()->is_draft);
        $this->assertSame($path, $draft->fresh()->items->first()->stored_path);
    }

    public function test_validation_retains_valid_uploads_and_entered_text(): void
    {
        $task = $this->task('text,document');
        $this->actingAs($this->student)->from($this->endpoint($task))->post($this->endpoint($task, 'submit'), ['document' => $this->pdf(), 'idempotency_key' => 'retain-1'])->assertSessionHasErrors('text');
        $this->get($this->endpoint($task))->assertOk()->assertSee('answer.pdf')->assertSee('aria-invalid="true"', false);
        $this->assertSame(0, AssignmentSubmission::where('is_draft', false)->count());
    }

    public function test_same_token_retry_after_attempt_limit_returns_same_submission(): void
    {
        $task = $this->task('text'); $task->update(['allowed_attempts' => 1]);
        $service = app(SubmissionService::class);
        $one = $service->submit($this->student, $task, ['text' => 'Answer'], 'repeat-key');
        $two = $service->submit($this->student, $task, ['text' => 'Answer'], 'repeat-key');
        $this->assertSame($one->id, $two->id);
        $this->assertSame(1, AssignmentSubmission::where('is_draft', false)->count());
    }

    public function test_file_format_size_and_link_validation_remain_enforced(): void
    {
        $task = $this->task('document');
        $this->actingAs($this->student)->post($this->endpoint($task, 'draft'), ['document' => UploadedFile::fake()->create('bad.exe', 1)])->assertSessionHasErrors('document');
        $this->post($this->endpoint($task, 'draft'), ['document' => UploadedFile::fake()->create('huge.pdf', 20481)])->assertSessionHasErrors('document');
        $link = $this->task('link');
        $this->post($this->endpoint($link, 'submit'), ['link_url' => 'javascript:alert(1)', 'idempotency_key' => 'bad-link'])->assertSessionHasErrors('link_url');
    }

    public function test_another_student_cannot_download_the_saved_document(): void
    {
        $task = $this->task('document');
        $this->actingAs($this->student)->post($this->endpoint($task, 'submit'), ['document' => $this->pdf(), 'idempotency_key' => 'private-1'])->assertSessionHasNoErrors();
        $record = AssignmentSubmission::where('assignment_id', $task->id)->first(); $item = $record->items->first();
        $path = $this->endpoint($task)."/submissions/{$record->id}/evidence/{$item->id}/file";
        // Resolve through the actual registered route names to keep authorization under test.
        $route = route('student.courses.assignments.submissions.evidence', [$this->offering->id, $task->id, $record->id, $item->id]);
        $this->actingAs($this->secondConfirmedStudent())->get($route)->assertNotFound();
        $this->actingAs($this->lecturer)->get(route('teacher.course_offerings.assignments.submissions.evidence', [$this->offering->id, $task->id, $record->id, $item->id]))->assertOk();
    }

    public function test_lecturer_can_explicitly_save_all_any_and_optional_methods(): void
    {
        $payload = ['title' => 'Document or explanation', 'instructions' => '<p>Choose one response.</p>',
            'max_marks' => 20, 'submission_type' => 'file', 'late_policy' => 'block', 'status' => 'draft',
            'submission_kinds' => ['document', 'text'], 'submission_requirement_mode' => 'any', 'optional_submission_kinds' => ['image'],
            'requirement_role' => 'optional', 'completion_rule' => 'submission'];
        $this->actingAs($this->lecturer)->post("/teacher/course-offerings/{$this->offering->id}/assignments", $payload)->assertSessionHasNoErrors();
        $task = Assignment::where('title', $payload['title'])->firstOrFail();
        $this->assertFalse($task->requiresAllResponseKinds());
        $this->assertSame(['image'], $task->optionalEvidenceKinds());
        $this->get("/teacher/course-offerings/{$this->offering->id}/assignments/{$task->id}/edit")->assertOk()->assertSee('ANY ONE accepted method sufficient');
    }

    public function test_deadline_attempt_and_institution_checks_still_block_writes(): void
    {
        $task = $this->task('text');
        $task->update(['due_date' => now()->subHour(), 'late_policy' => 'block']);
        $this->actingAs($this->student)->post($this->endpoint($task, 'submit'), ['text' => 'Too late', 'idempotency_key' => 'late'])->assertSessionHasErrors();
        $this->assertSame(0, AssignmentSubmission::count());
        $foreign = $this->otherTenantAssignment();
        $this->get("/student/courses/{$foreign['offeringId']}/assignments/{$foreign['assignmentId']}")->assertNotFound();
    }
}
