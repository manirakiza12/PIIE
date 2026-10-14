<?php

namespace Tests\Feature;

use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingModule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * A lesson can be nothing but files.
 *
 * THE GAP THIS PINS
 *
 * Not every lesson is written prose. A lecturer whose lesson IS the material - a
 * slide deck, a scanned handout, a recorded lecture - previously had no way to
 * start one: attachments were only offered on the EDIT screen, so the upload
 * button did not exist until a draft had been saved. The first thing such a
 * lecturer was forced to do was type a paragraph of filler text.
 *
 * The body has always been `nullable`, so the data model allowed a file-only
 * lesson throughout. Only the screen refused, which is the easiest kind of fault
 * to miss and the most annoying to work around in practice.
 *
 * WHY IT SAVES A DRAFT INSTEAD OF STASHING THE UPLOAD
 *
 * The alternative - upload to a temporary area, move on first save - buys nothing
 * and costs a great deal: orphan files nobody will ever find, two lifetimes for
 * every upload, and a cleanup job. Writing the draft immediately puts the file in
 * its permanent home on the first request. If the upload is refused, what is left
 * behind is a draft the lecturer can delete, not a cache entry.
 *
 * AND IT MUST NEVER PUBLISH.
 *
 * The handler forces `status = draft`. Publishing is a judgement about whether a
 * lesson is ready, and "somebody attached a PDF" is not that judgement - so the
 * shortcut must be incapable of making a lesson visible to students.
 */
class FileOnlyLessonTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    private CourseOfferingModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignmentSetUp();
        Storage::fake('local');

        // A lesson lives inside a module and the handler resolves it with
        // `firstOrFail`, so every test needs one. Created once here because
        // calling `$this->module()` again mid-test would compare against a
        // different row.
        $this->module = $this->module();
    }

    /**
     * POST to the new-lesson upload route as the allocated lecturer.
     *
     * The module id is supplied the way the real form supplies it, so a test that
     * forgets it fails for the honest reason rather than on a 404.
     */
    private function upload(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->lecturer)->post(
            route('teacher.course_offerings.content.lessons.resources.store_new', [$this->offering->id]),
            array_merge(['course_offering_module_id' => $this->module->id], $payload)
        );
    }

    private function pdf(string $name = 'slides.pdf', int $kb = 300): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    // ── The capability ─────────────────────────────────────────────────────

    public function test_a_file_can_be_attached_to_a_lesson_that_does_not_exist_yet(): void
    {
        $this->upload([
            'title' => 'Week 1 slides',
            'type' => 'file',
            'file' => $this->pdf(),
        ])->assertRedirect();

        $lesson = CourseOfferingLesson::query()->where('title', 'Week 1 slides')->sole();

        // The whole point: no written content at all, and the lesson exists.
        $this->assertTrue($lesson->resources->isNotEmpty(), 'the file must be attached');
        $this->assertSame('slides.pdf', $lesson->resources->first()->original_name);
    }

    public function test_the_lesson_created_by_an_upload_is_always_a_draft(): void
    {
        $this->upload([
            'title' => 'Recorded lecture',
            'type' => 'file',
            'file' => UploadedFile::fake()->create('lecture.mp4', 900, 'video/mp4'),
        ])->assertRedirect();

        $lesson = CourseOfferingLesson::query()->where('title', 'Recorded lecture')->sole();

        $this->assertSame(CourseOfferingLesson::STATUS_DRAFT, $lesson->status);
        $this->assertFalse($lesson->isReleasedToStudents());
    }

    public function test_a_request_asking_to_publish_cannot_publish_through_this_route(): void
    {
        // The whole point of forcing the status. A crafted request carrying
        // `status=published` must still produce a draft.
        $this->upload([
            'title' => 'Sneaky publish',
            'status' => 'published',
            'is_published' => 1,
            'type' => 'file',
            'file' => $this->pdf('notes.pdf', 100),
        ])->assertRedirect();

        $lesson = CourseOfferingLesson::query()->where('title', 'Sneaky publish')->sole();

        $this->assertSame(CourseOfferingLesson::STATUS_DRAFT, $lesson->status);
        $this->assertFalse($lesson->isReleasedToStudents());
    }

    // ── The lecturer's work is not thrown away by the shortcut ──────────────

    public function test_what_the_lecturer_has_typed_so_far_survives_the_upload(): void
    {
        $this->upload([
            'title' => 'Half-written lesson',
            'summary' => 'A summary typed before the upload.',
            'learning_objectives' => 'By the end the student can do X',
            'body' => '<p>Some notes written first.</p>',
            'type' => 'file',
            'file' => $this->pdf('handout.pdf', 100),
        ])->assertRedirect();

        $lesson = CourseOfferingLesson::query()->where('title', 'Half-written lesson')->sole();

        $this->assertSame('A summary typed before the upload.', $lesson->summary);
        $this->assertSame('By the end the student can do X', $lesson->learning_objectives);
        $this->assertStringContainsString('Some notes written first', (string) $lesson->body);
    }

    public function test_a_lesson_with_no_title_at_all_still_works(): void
    {
        // A lecturer who wants a file and nothing else should not be forced to
        // invent a title to get there.
        $this->upload(['type' => 'file', 'file' => $this->pdf('scan.png', 100)])
            ->assertRedirect();

        $lesson = CourseOfferingLesson::query()->latest('id')->first();

        $this->assertSame('Untitled lesson', $lesson->title);
        $this->assertTrue($lesson->resources->isNotEmpty());
    }

    // ── The same rules on both paths ────────────────────────────────────────

    public function test_a_missing_file_is_refused_on_the_new_lesson_path_too(): void
    {
        $this->upload(['type' => 'file', 'title' => 'Nothing chosen']);

        $errors = session('errors');

        // Asserted against the bag directly rather than through
        // `assertSessionHasErrors`, because that helper's own failure message
        // dumps the message text rather than the bag it looked in - so a test
        // that is failing for the right reason reads as if the rule never fired.
        $this->assertTrue(
            $errors->hasBag(\App\Http\Controllers\CourseOfferingContentController::RESOURCE_ERROR_BAG),
            'the refusal must be reported in the attachment bag'
        );
        $this->assertTrue(
            $errors->getBag(\App\Http\Controllers\CourseOfferingContentController::RESOURCE_ERROR_BAG)->has('file'),
            'the missing file must be named'
        );
        // `hasBag()` answers "does this bag exist"; it does not return it, so the
        // default bag is read with `getBag()` when its CONTENTS matter.
        $this->assertFalse(
            $errors->getBag('default')->any(),
            'the lesson must not be reported as the thing that failed'
        );

        // And it must not have left a half-made lesson behind.
        $this->assertSame(0, CourseOfferingLesson::query()->count());
    }

    public function test_a_link_works_on_the_new_lesson_path_too(): void
    {
        $this->upload([
            'title' => 'Reference reading',
            'type' => 'link',
            'link_url' => 'https://example.org/paper',
        ])->assertRedirect();

        $lesson = CourseOfferingLesson::query()->where('title', 'Reference reading')->sole();

        $this->assertTrue($lesson->resources->isNotEmpty());
        $this->assertSame('https://example.org/paper', $lesson->resources->first()->link_url);
    }

    // ── Authority ───────────────────────────────────────────────────────────

    public function test_an_unallocated_lecturer_cannot_use_this_route(): void
    {
        $stranger = \App\Models\User::query()->create([
            'school_id' => $this->school,
            'role_id' => 3,
            'name' => 'No Allocation Stranger',
            'email' => 'stranger@example.test',
            'account_status' => 'active',
        ]);

        $this->actingAs($stranger)->post(
            route('teacher.course_offerings.content.lessons.resources.store_new', [$this->offering->id]),
            [
                'title' => 'Should not exist',
                'type' => 'file',
                'file' => $this->pdf('x.pdf', 10),
            ]
        );

        $this->assertSame(0, CourseOfferingLesson::query()->where('title', 'Should not exist')->count());
    }

    public function test_another_tenants_module_cannot_be_targeted(): void
    {
        // The module id arrives from the form, so it is re-resolved against the
        // authorised Offering. A substituted id from another tenant is refused
        // rather than written - the same guarantee the normal lesson form gives.
        $foreign = DB::table('course_offering_modules')->insertGetId([
            'school_id' => $this->school + 999,
            'course_offering_id' => $this->offering->id + 999,
            'title' => 'Foreign module',
            'sequence' => 1,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->upload([
            'title' => 'Cross-tenant attempt',
            'course_offering_module_id' => $foreign,
            'type' => 'file',
            'file' => $this->pdf('y.pdf', 10),
        ]);

        $this->assertSame(
            0,
            CourseOfferingLesson::query()->where('title', 'Cross-tenant attempt')->count(),
            'a module from another tenant must not accept a lesson'
        );
    }

    // ── The screen offers it ────────────────────────────────────────────────

    public function test_the_create_screen_offers_uploads_rather_than_saying_save_first(): void
    {
        $page = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.content.lessons.create', [$this->offering->id]));

        $page->assertOk();
        $page->assertDontSee('Save the lesson first, then add links and files');
        $page->assertSee('lessons/attachments', false);
    }
}
