<?php

namespace Tests\Feature;

use App\Models\CourseOfferingLesson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Two faults on the authoring screens, both of which made the tool look broken
 * rather than make it fail honestly.
 *
 * 1. AN ATTACHMENT ERROR WAS REPORTED AS A CONTENT ERROR.
 *    Tapping "Upload file" without choosing a file produced "the file field is
 *    required" at the top of the page, inside a block headed "Please fix the
 *    following before saving". That heading is about the LESSON, and the lesson
 *    was not part of that request at all - it had saved fine. A lecturer reading
 *    that has no way to tell which of the two things on the screen went wrong.
 *    The attachment errors now carry their own bag and render beside the control
 *    that caused them.
 *
 * 2. MEDIA COULD NOT BE PLACED IN A LESSON.
 *    Summernote's built-in `video` button pastes a third-party URL, so PIIE's own
 *    uploaded recordings could not be embedded. The toolbar has an "insert a
 *    recording from this lesson" control that reads a radio the server rendered,
 *    which means the URL was never typed by the lecturer and cannot be forged.
 */
class LessonMediaAndAttachmentErrorsTest extends TestCase
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

    // ── Attachment errors land beside the attachment, not the lesson ───────

    public function test_a_missing_attachment_file_does_not_report_the_lesson_as_failing(): void
    {
        $lesson = $this->lesson();

        $response = $this->actingAs($this->lecturer)
            ->post(route('teacher.course_offerings.content.resources.store', [$this->offering->id, $lesson->id]), [
                'type' => 'file',
                'title' => 'Lecture recording',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('file', null, \App\Http\Controllers\CourseOfferingContentController::RESOURCE_ERROR_BAG);

        // The message is real and must survive - it just must not be dressed up as
        // a lesson failure. Before this, it rendered under "Please fix the
        // following before saving", which is a claim about the lesson.
        $errors = session('errors');
        $this->assertTrue(
            $errors->hasBag(\App\Http\Controllers\CourseOfferingContentController::RESOURCE_ERROR_BAG),
            'the attachment error must carry its own bag'
        );
        $this->assertFalse(
            $errors->has('file'),
            'the attachment error must not also land in the lesson default bag'
        );
    }

    public function test_the_lesson_form_no_longer_claims_the_lesson_failed_when_only_an_attachment_did(): void
    {
        $lesson = $this->lesson();

        $response = $this->actingAs($this->lecturer)
            ->post(route('teacher.course_offerings.content.resources.store', [$this->offering->id, $lesson->id]), [
                'type' => 'file',
            ]);

        $response->assertRedirect();

        $page = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.content.lessons.edit', [$this->offering->id, $lesson->id]));

        $page->assertOk();
        // The lesson's own error block is driven by the DEFAULT bag, so an
        // attachment failure cannot light it up.
        $page->assertDontSee('Please fix the following before saving');
        $page->assertSee('The attachment was not added');
    }

    public function test_an_attachment_error_is_reported_beside_the_attachment_control(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->lecturer)
            ->post(route('teacher.course_offerings.content.resources.store', [$this->offering->id, $lesson->id]), [
                'type' => 'file',
            ])
            ->assertSessionHasErrors('file', null, \App\Http\Controllers\CourseOfferingContentController::RESOURCE_ERROR_BAG);

        $page = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.content.lessons.edit', [$this->offering->id, $lesson->id]));

        $page->assertOk();
        $page->assertSee('The attachment was not added');
        $page->assertSee('file field is required', false);
    }

    public function test_the_assignment_attachment_error_is_equally_scoped(): void
    {
        // The same defect existed on the assignment form and was reported the same
        // wrong way, so it is fixed and pinned the same way.
        $assignment = $this->publishedAssignment();

        $this->actingAs($this->lecturer)
            ->post(route('teacher.course_offerings.assignments.resources.store', [$this->offering->id, $assignment->id]), [
                'type' => 'file',
            ])
            ->assertSessionHasErrors('file', null, \App\Http\Controllers\TeacherCourseOfferingAssignmentController::RESOURCE_ERROR_BAG);

        $errors = session('errors');

        $this->assertTrue(
            $errors->hasBag(\App\Http\Controllers\TeacherCourseOfferingAssignmentController::RESOURCE_ERROR_BAG)
        );
        $this->assertFalse($errors->has('file'), 'must not also land in the assignment default bag');
    }

    // ── The toolbar controls the editor swap had dropped ───────────────────

    public function test_the_editor_offers_colour_line_height_and_media_again(): void
    {
        // The lesson editor used to offer `color`, `height` and `video`. Moving it
        // onto the shared component silently dropped all three: `color` and
        // `height` are built into the Summernote Lite bundle, so they worked, and
        // their loss was a real regression rather than dead markup.
        $js = file_get_contents(base_path('public/js/academic-editor.js'));

        $this->assertIsString($js);

        foreach (['color', 'height', 'video', 'piieMedia'] as $control) {
            $this->assertStringContainsString(
                "'".$control."'",
                $js,
                "the shared editor no longer offers the '{$control}' control"
            );
        }
    }

    public function test_the_picture_button_is_found_by_name_not_by_position(): void
    {
        // It used to be `toolbar[5]`, which is a positional claim that breaks the
        // moment a group is inserted above it - which is exactly what happened.
        // A positional splice is the reason a working button disappeared once.
        $js = file_get_contents(base_path('public/js/academic-editor.js'));

        $this->assertIsString($js);
        $this->assertStringNotContainsString(
            "toolbar[5].splice",
            $js,
            'the picture button must not be spliced in by a hard-coded index'
        );
        $this->assertStringContainsString("row[0] === 'insert'", $js);
    }

    public function test_the_editor_media_control_never_accepts_a_typed_url(): void
    {
        // The embed reads a radio the SERVER rendered, whose value is the
        // authorised resource route. A lecturer cannot type a URL into it, so the
        // lesson body cannot be made to load arbitrary third-party media - which
        // matters because the body is sanitised on save but the src is the whole
        // attack surface if it were free text.
        $js = file_get_contents(base_path('public/js/academic-editor.js'));

        $this->assertIsString($js);
        $this->assertStringContainsString('data-cc-media', $js);
        $this->assertStringContainsString("media.preload = 'none'", $js, 'media must not download until asked');
    }

    public function test_a_playable_attachment_offers_itself_for_embedding(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->lecturer)
            ->post(route('teacher.course_offerings.content.resources.store', [$this->offering->id, $lesson->id]), [
                'type' => 'file',
                'title' => 'Lecture 1 recording',
                'file' => UploadedFile::fake()->create('lecture-1.mp4', 200, 'video/mp4'),
            ])
            ->assertSessionHasNoErrors();

        $page = $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.content.lessons.edit', [$this->offering->id, $lesson->id]));

        $page->assertOk();
        $page->assertSee('Place a recording in the lesson');
        // The radio carries the AUTHORISED route, not a bare id, so the browser
        // cannot construct a url the student route would refuse.
        $page->assertSee('data-cc-media', false);
    }
}
