<?php

namespace Tests\Feature;

use App\Models\Assignment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * An assignment can be nothing but files.
 *
 * THE GAP THIS PINS
 *
 * Handouts were offered only on the EDIT screen, behind "Save the assignment
 * first". So a lecturer whose assignment IS the material - a dataset to analyse,
 * a case study, a worksheet - had to invent instructions purely to unlock the
 * upload button. Instructions are `nullable` all the way down, so the data model
 * always allowed a file-only assignment; only the screen refused.
 *
 * MIRRORS THE LESSON ROUTE DELIBERATELY
 *
 * The lesson side gained the same capability, and it was built the same way: save
 * a draft carrying what has been typed, attach, land on the edit screen. The
 * reasoning is recorded there and applies unchanged - staging an upload in a
 * temporary area buys nothing and costs orphan files, two lifetimes per upload
 * and a cleanup job.
 *
 * AND IT MUST NEVER PUBLISH
 *
 * Attaching a PDF is not a judgement that an assessment is ready to be marked.
 * Publishing is, and it stays a separate deliberate act.
 */
class FileOnlyAssignmentTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignmentSetUp();
        Storage::fake('local');
    }

    private function upload(array $payload)
    {
        return $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.assignments.resources.store_new', [$this->offering->id]),
            $payload
        );
    }

    private function pdf(string $name = 'dataset.pdf', int $kb = 400): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    public function test_a_handout_can_be_attached_to_an_assignment_that_does_not_exist_yet(): void
    {
        $this->upload([
            'title' => 'Analyse the sales dataset',
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'late_policy' => Assignment::LATE_ALLOWED,
            'max_marks' => 50,
            'type' => 'file',
            'file' => $this->pdf(),
        ])->assertRedirect();

        $assignment = Assignment::query()->where('title', 'Analyse the sales dataset')->sole();

        // The whole point: no written brief at all.
        $this->assertTrue($assignment->resources->isNotEmpty(), 'the handout must be attached');
        $this->assertSame('dataset.pdf', $assignment->resources->first()->original_name);
    }

    public function test_the_assignment_created_by_an_upload_is_always_a_draft(): void
    {
        // A `.pdf`, not a `.mp4`. The handout allowlist deliberately excludes
        // video - a 20 MB ceiling on a metered connection is the wrong tool for a
        // lecture recording, and the panel says so. Uploading one here would be
        // refused and the transaction would roll back, which is correct but tests
        // nothing about drafts.
        $this->upload([
            'title' => 'Recorded case study',
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'late_policy' => Assignment::LATE_ALLOWED,
            'type' => 'file',
            'file' => $this->pdf('case-study.pdf', 900),
        ])->assertRedirect();

        $assignment = Assignment::query()->where('title', 'Recorded case study')->sole();

        $this->assertSame(Assignment::STATUS_DRAFT, $assignment->status);
    }

    public function test_a_request_asking_to_publish_cannot_publish_through_this_route(): void
    {
        $this->upload([
            'title' => 'Sneaky publish',
            'status' => 'published',
            'is_published' => 1,
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'late_policy' => Assignment::LATE_ALLOWED,
            'type' => 'file',
            'file' => $this->pdf('notes.pdf', 100),
        ])->assertRedirect();

        $assignment = Assignment::query()->where('title', 'Sneaky publish')->sole();

        $this->assertSame(Assignment::STATUS_DRAFT, $assignment->status);
    }

    public function test_what_the_lecturer_has_typed_so_far_survives_the_upload(): void
    {
        $this->upload([
            'title' => 'Half-written brief',
            'instructions' => '<p>Written before the upload.</p>',
            'learning_objectives' => 'By the end the student can do X',
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'late_policy' => Assignment::LATE_ALLOWED,
            'type' => 'file',
            'file' => $this->pdf('handout.pdf', 100),
        ])->assertRedirect();

        $assignment = Assignment::query()->where('title', 'Half-written brief')->sole();

        $this->assertStringContainsString('Written before the upload', (string) $assignment->instructions);
        $this->assertSame('By the end the student can do X', $assignment->learning_objectives);
    }

    public function test_a_missing_handout_leaves_no_assignment_behind(): void
    {
        $this->upload([
            'title' => 'Nothing chosen',
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'late_policy' => Assignment::LATE_ALLOWED,
            'type' => 'file',
        ]);

        $errors = session('errors');

        $this->assertTrue(
            $errors->hasBag(\App\Http\Controllers\TeacherCourseOfferingAssignmentController::RESOURCE_ERROR_BAG),
            'the refusal must be reported in the handout bag'
        );
        $this->assertSame(
            0,
            Assignment::query()->where('title', 'Nothing chosen')->count(),
            'a refused upload must not leave a brief-less assignment behind'
        );
    }

    public function test_a_link_works_on_the_new_assignment_path_too(): void
    {
        $this->upload([
            'title' => 'Reference data',
            'submission_type' => Assignment::SUBMISSION_TEXT,
            'late_policy' => Assignment::LATE_ALLOWED,
            'type' => 'link',
            'link_url' => 'https://example.org/dataset',
        ])->assertRedirect();

        $assignment = Assignment::query()->where('title', 'Reference data')->sole();

        $this->assertSame('https://example.org/dataset', $assignment->resources->first()->link_url);
    }

    public function test_an_unallocated_lecturer_cannot_use_this_route(): void
    {
        $stranger = \App\Models\User::query()->create([
            'school_id' => $this->school,
            'role_id' => 3,
            'name' => 'No Allocation Stranger',
            'email' => 'assignment-stranger@example.test',
            'account_status' => 'active',
        ]);

        $this->actingAs($stranger)->post(
            route('teacher.course_offerings.assignments.resources.store_new', [$this->offering->id]),
            [
                'title' => 'Should not exist',
                'submission_type' => Assignment::SUBMISSION_TEXT,
                'late_policy' => Assignment::LATE_ALLOWED,
                'type' => 'file',
                'file' => $this->pdf('x.pdf', 10),
            ]
        );

        $this->assertSame(0, Assignment::query()->where('title', 'Should not exist')->count());
    }

    public function test_the_create_screen_offers_handouts_rather_than_saying_save_first(): void
    {
        $page = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.assignments.create', [$this->offering->id]));

        $page->assertOk();
        $page->assertDontSee('Save the assignment first, then add links and files');
        $page->assertSee('assignments/handouts', false);
    }
}
