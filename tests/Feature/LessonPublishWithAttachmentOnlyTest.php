<?php

namespace Tests\Feature;

use App\Models\CourseOfferingLesson;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * A lesson with an attachment and no written body is publishable.
 *
 * THE REPORTED FAULT
 *
 * A lecturer attached a link - the reading for the topic - and was then told
 *
 *     "Add some lesson content before publishing. A published lesson with no
 *      content is not something a student should be shown."
 *
 * while looking at a lesson that had content: the link. The rule existed to stop
 * a blank page reaching a student, and it was refusing a lesson that had content
 * of a kind it did not count.
 *
 * This is not an unusual lesson. Distance learning is full of them: the reading
 * is the lesson, the recording IS the lesson, the slide deck is the lesson.
 *
 * WHAT STILL MUST BE REFUSED
 *
 * A lesson with NEITHER body NOR attachment. That is genuinely blank, and the
 * guard is not being weakened - it is being pointed at the right question, which
 * is "would a student find anything here" rather than "is there prose".
 *
 * THE HOLE THIS EXPOSED
 *
 * The guard lived only on UPDATE. `createLesson()` never checked at all, so the
 * path a lecturer takes first - creating a lesson - could publish a completely
 * blank one, and the update guard was not protecting anything. Both paths are
 * pinned below.
 */
class LessonPublishWithAttachmentOnlyTest extends TestCase
{
    use AssignmentFixture {
        setUp as protected assignmentFixtureSetUp;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assignmentFixtureSetUp();
        Storage::fake('local');
    }

    /**
     * Publish THIS lesson.
     *
     * Takes the lesson rather than creating one: an earlier version created a
     * fresh lesson here, which meant the attachment under test was attached to a
     * different row and the guard correctly refused the empty one. A test that
     * passes for the wrong reason is worse than one that fails.
     */
    private function publish(CourseOfferingLesson $lesson, array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->lecturer)->put(
            "/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}",
            // The module id travels with the save, exactly as the real form sends
            // it. Without it the service re-resolves a module from the Allocation,
            // which is not necessarily the one this lesson belongs to - so the
            // request was refused for moving the lesson, long before the publish
            // guard was ever reached.
            $payload + [
                'title' => $lesson->title,
                'assignment_id' => $lesson->assignment_id ?: $this->publishedAssignment()->id,
                'status' => 'published',
                'course_offering_module_id' => $lesson->course_offering_module_id,
            ]
        );
    }

    private function lesson(array $attributes = []): CourseOfferingLesson
    {
        $module = $this->module();

        // `school_id` and `course_offering_id` are set EXPLICITLY rather than
        // relying on the relation. The `lessons()` relation fills in only the
        // module foreign key, and `CourseOfferingLesson`'s saving guard compares
        // the lesson's offering against its module's - so a lesson built through
        // the relation alone is refused for belonging to no Allocation at all. The
        // guard is right; the fixture was incomplete.
        return CourseOfferingLesson::query()->create(array_merge([
            'school_id' => $this->school,
            'course_offering_id' => $module->course_offering_id,
            'course_offering_module_id' => $module->id,
            'title' => 'A lesson',
            'status' => CourseOfferingLesson::STATUS_DRAFT,
            'content_type' => CourseOfferingLesson::CONTENT_TYPE_LESSON,
        ], $attributes));
    }

    private function attachLink(CourseOfferingLesson $lesson): void
    {
        $this->actingAs($this->lecturer)->post(
            "/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}/resources",
            ['type' => 'link', 'link_url' => 'https://www.example.com/keychron-k3']
        )->assertSessionHasNoErrors();
    }

    private function attachFile(CourseOfferingLesson $lesson, string $name = 'slides.pdf'): void
    {
        $this->actingAs($this->lecturer)->post(
            "/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}/resources",
            [
                'type' => 'file',
                'file' => UploadedFile::fake()->create($name, 200, 'application/pdf'),
            ]
        )->assertSessionHasNoErrors();
    }

    // ── The reported fault ──────────────────────────────────────────────────

    public function test_a_lesson_with_only_a_link_can_be_published(): void
    {
        $lesson = $this->lesson();
        $this->attachLink($lesson);

        $this->publish($lesson)->assertSessionHasNoErrors();

        $this->assertSame(
            CourseOfferingLesson::STATUS_PUBLISHED,
            $lesson->fresh()->status,
            'a link IS content; refusing it was the reported fault'
        );
    }

    public function test_a_lesson_with_only_an_uploaded_file_can_be_published(): void
    {
        $lesson = $this->lesson();
        $this->attachFile($lesson, 'lecture-slides.pdf');

        $this->publish($lesson)->assertSessionHasNoErrors();

        $this->assertSame(CourseOfferingLesson::STATUS_PUBLISHED, $lesson->fresh()->status);
    }

    public function test_a_lesson_with_a_recording_can_be_published(): void
    {
        $lesson = $this->lesson();
        $this->attachFile($lesson, 'recorded-lecture.mp4');

        $this->publish($lesson)->assertSessionHasNoErrors();

        $this->assertSame(CourseOfferingLesson::STATUS_PUBLISHED, $lesson->fresh()->status);
    }

    // ── What must still be refused ──────────────────────────────────────────

    public function test_a_lesson_with_neither_body_nor_attachment_is_still_refused(): void
    {
        // The guard is not being weakened. A genuinely blank lesson still must not
        // reach a student.
        $lesson = $this->lesson();

        $this->publish($lesson, ['body' => null])
            ->assertSessionHasErrors('body');

        $this->assertSame(
            CourseOfferingLesson::STATUS_DRAFT,
            $lesson->fresh()->status
        );
    }

    public function test_whitespace_and_empty_markup_do_not_count_as_content(): void
    {
        // Otherwise the guard is trivially bypassed with a space or an empty
        // paragraph, which is exactly the blank page it exists to prevent.
        foreach (['   ', '<p><br></p>', '<div></div>'] as $junk) {
            $lesson = $this->lesson(['title' => 'Junk '.md5($junk)]);

            $this->actingAs($this->lecturer)->put(
                "/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}",
                ['title' => 'Junk', 'body' => $junk, 'status' => 'published', 'completion_rule' => 'manual']
            )->assertSessionHasErrors('body');

            $this->assertSame(
                CourseOfferingLesson::STATUS_DRAFT,
                $lesson->fresh()->status,
                'junk markup must not satisfy the publish guard'
            );
        }
    }

    public function test_a_blank_lesson_can_still_be_saved_as_a_draft(): void
    {
        // The guard is about PUBLISHING. A lecturer is entitled to start a lesson
        // and come back to it.
        $lesson = $this->lesson();

        $this->actingAs($this->lecturer)->put(
            "/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}",
            ['title' => 'Later', 'body' => null, 'status' => 'draft', 'completion_rule' => 'manual']
        )->assertSessionHasNoErrors();

        $this->assertSame(CourseOfferingLesson::STATUS_DRAFT, $lesson->fresh()->status);
    }

    // ── The hole: createLesson never checked at all ─────────────────────────

    public function test_creating_a_lesson_already_published_with_nothing_in_it_is_refused(): void
    {
        // The guard used to exist only on update, so the FIRST thing a lecturer
        // does - create a lesson - could publish a blank one outright.
        $this->expectException(DomainException::class);

        app(\App\Support\CourseContent\CourseContentService::class)->createLesson(
            $this->lecturer,
            $this->module(),
            [
                'title' => 'Born published and blank',
                'status' => CourseOfferingLesson::STATUS_PUBLISHED,
            ]
        );
    }

    public function test_creating_a_published_lesson_with_a_body_is_allowed(): void
    {
        $lesson = app(\App\Support\CourseContent\CourseContentService::class)->createLesson(
            $this->lecturer,
            $this->module(),
            [
                'title' => 'Born published and written',
                'assignment_id' => $this->publishedAssignment()->id,
                'status' => CourseOfferingLesson::STATUS_PUBLISHED,
                'body' => '<p>Real content.</p>',
            ]
        );

        $this->assertSame(CourseOfferingLesson::STATUS_PUBLISHED, $lesson->status);
    }
}
