<?php

namespace Tests\Feature;

use App\Models\AssignmentSubmission;
use App\Support\Assignments\UploadLimit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

class StudentAssignmentUploadLimitTest extends TestCase
{
    use AssignmentFixture { setUp as protected fixtureSetUp; }

    protected function setUp(): void
    {
        $this->fixtureSetUp();
        Storage::fake('local');
    }

    private function endpoint(\App\Models\Assignment $assignment, string $action = ''): string
    {
        return "/student/courses/{$this->offering->id}/assignments/{$assignment->id}".($action ? '/'.$action : '');
    }

private function textFile(int $kilobytes, string $name = 'answer.txt'): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kilobytes);
    }

    public function test_label_matches_the_aggregate_and_per_file_constants(): void
    {
        $aggregateMB = round(UploadLimit::REQUEST_MAX_BYTES / 1048576, 0);
        $fileMB = round(UploadLimit::FILE_MAX_BYTES / 1048576, 0);

        $this->assertStringContainsString((string) $aggregateMB.' MB', UploadLimit::LABEL);
        $this->assertStringContainsString('under '.$fileMB.' MB', UploadLimit::LABEL);
        $this->assertGreaterThan(UploadLimit::FILE_MAX_BYTES, UploadLimit::REQUEST_MAX_BYTES);
    }

    public function test_submit_refuses_oversized_request_without_spending_attempt(): void
    {
        $assignment = $this->publishedAssignment(['allowed_attempts' => 1]);
        $this->actingAs($this->student);

        $before = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('student_id', $this->student->id)->count();

        $response = $this->post($this->endpoint($assignment, 'submit'), [
            'text' => '<p>My answer.</p>',
            'document' => $this->textFile(25 * 1024),
            'idempotency_key' => 'oversized-submit',
        ]);

        $response->assertRedirect();

        $this->assertSame($before, AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('student_id', $this->student->id)->count());
    }

    public function test_save_draft_refuses_oversized_request(): void
    {
        $assignment = $this->publishedAssignment(['allowed_attempts' => 1]);
        $this->actingAs($this->student);

        $response = $this->post($this->endpoint($assignment, 'draft'), [
            'text' => '<p>My answer so far.</p>',
            'document' => $this->textFile(25 * 1024),
        ]);

        $response->assertRedirect();

        // The gate runs before the draft is saved, so nothing was persisted.
        $this->assertSame(0, AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('student_id', $this->student->id)->count());
    }

    public function test_valid_submission_within_limits_succeeds(): void
    {
        $assignment = $this->publishedAssignment(['allowed_attempts' => 1]);
        $this->actingAs($this->student);

        $response = $this->post($this->endpoint($assignment, 'submit'), [
            'text' => '<p>My answer.</p>',
            'document' => $this->textFile(1024),
            'idempotency_key' => 'valid-submit',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('student_id', $this->student->id)
            ->where('is_draft', false)->count());
    }
}