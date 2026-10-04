<?php

namespace Tests\Feature;

use App\Models\CourseOffering;
use App\Support\CourseOffering\CourseCoverImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * The optional Course Offering cover image: CAPABILITY ONLY.
 *
 * The visual card design is explicitly deferred, so nothing in this suite asserts
 * that an image is rendered anywhere. What it asserts is the part that cannot be
 * added later cheaply:
 *
 *   - the relationship is OPTIONAL, and a missing cover is a normal state;
 *   - the file is stored OUTSIDE the web root under a generated name;
 *   - the authorised route is the only way to read it;
 *   - that route answers for a lecturer with an allocation, a CONFIRMED student,
 *     and nobody else - and a 404 rather than a 403, so it cannot be used to
 *     discover that some other Offering has a cover;
 *   - replacing or clearing a cover disposes of the old bytes rather than
 *     accumulating files nobody intends to keep.
 */
class CourseOfferingCoverImageTest extends TestCase
{
    use AssignmentFixture;

    private function cover(): CourseCoverImage
    {
        return app(CourseCoverImage::class);
    }

    private function routeFor(?CourseOffering $offering = null): string
    {
        $offering ??= CourseOffering::query()->findOrFail($this->offering->id);

        return "/teacher/course-offerings/{$offering->id}/cover-image";
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE RELATIONSHIP IS OPTIONAL
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_offering_with_no_cover_is_a_normal_state(): void
    {
        $offering = CourseOffering::query()->findOrFail($this->offering->id);

        $this->assertNull($offering->cover_image_path);
        $this->assertFalse($this->cover()->hasCover($offering));

        // The read path answers 404 for a missing cover rather than a
        // placeholder, and nothing should treat that as a fault to repair.
        $this->actingAs($this->lecturer)->get($this->routeFor($offering))->assertNotFound();
    }

    public function test_a_lecturer_with_an_allocation_can_set_and_replace_a_cover(): void
    {
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);

        $first = $this->cover()->set(
            $this->lecturer,
            $offering,
            UploadedFile::fake()->image('cover.png', 200, 120)
        );

        $this->assertTrue($this->cover()->hasCover($first));
        $this->assertStringStartsWith('course-covers/', $first->cover_image_path);
        $this->assertNotNull($first->cover_image_updated_at);
        Storage::disk('local')->assertExists($first->cover_image_path);

        $firstPath = $first->cover_image_path;

        $second = $this->cover()->set(
            $this->lecturer,
            $offering->fresh(),
            UploadedFile::fake()->image('new-cover.jpg', 300, 200)
        );

        $this->assertNotSame($firstPath, $second->cover_image_path);

        // The superseded file is disposed of. A cover is cosmetic,
        // institution-owned material, and retaining every previous version would
        // keep readable files the institution has withdrawn.
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($second->cover_image_path);
    }

    public function test_clearing_a_cover_is_permitted_and_removes_the_bytes(): void
    {
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);
        $set = $this->cover()->set($this->lecturer, $offering, UploadedFile::fake()->image('c.png', 100, 100));
        $path = $set->cover_image_path;

        $cleared = $this->cover()->clear($this->lecturer, $set);

        $this->assertNull($cleared->cover_image_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_non_image_is_refused(): void
    {
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);

        // An explicit allowlist, not "anything not blocked": an unexpected type is
        // refused by default rather than accepted by omission. A document is not a
        // cover, and an executable is not a cover.
        $this->actingAs($this->lecturer);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->cover()->set($this->lecturer, $offering, UploadedFile::fake()->create('notes.pdf', 8, 'application/pdf'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE FILE IS PRIVATE
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_cover_is_stored_outside_the_web_root_under_a_generated_name(): void
    {
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);
        $set = $this->cover()->set(
            $this->lecturer,
            $offering,
            UploadedFile::fake()->image('my course cover.png', 100, 100)
        );

        // The client's filename never becomes the PATH, so an uploaded
        // "my course cover.png" cannot be requested at a guessable location.
        $this->assertStringNotContainsString('my course cover', $set->cover_image_path);
        $this->assertStringStartsWith('course-covers/'.$offering->id.'/', $set->cover_image_path);

        // The original name is KEPT, deliberately, but only as a DISPLAY LABEL -
        // the same arrangement the Course Content and Assignment resources use, so
        // the download presents a recognisable name while the storage location
        // never reveals it. An earlier version of this test wrongly asserted the
        // name was discarded, which is not the design.
        $this->assertSame('my course cover.png', $set->cover_image_name);
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE AUTHORISED READ ROUTE
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_allocated_lecturer_and_a_confirmed_student_can_read_the_cover(): void
    {
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);
        $this->cover()->set($this->lecturer, $offering, UploadedFile::fake()->image('c.png', 100, 100));

        $this->actingAs($this->lecturer)->get($this->routeFor($offering))->assertOk();
        // Through the STUDENT route, which is the only one a student can reach: the
        // lecturer route's middleware group redirects them away.
        $this->actingAs($this->student)
            ->get("/student/courses/{$offering->id}/cover-image")
            ->assertOk();
    }

    public function test_a_student_awaiting_confirmation_cannot_read_the_cover(): void
    {
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);
        $this->cover()->set($this->lecturer, $offering, UploadedFile::fake()->image('c.png', 100, 100));

        DB::table('course_registrations')
            ->where('student_id', $this->student->id)
            ->update(['status' => 'registered']);

        // A 404, not a 403: the route must not confirm that a cover exists for a
        // student with no business knowing.
        $this->actingAs($this->student)
            ->get("/student/courses/{$offering->id}/cover-image")
            ->assertNotFound();
    }

    public function test_an_unallocated_lecturer_cannot_read_the_cover(): void
    {
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);
        $this->cover()->set($this->lecturer, $offering, UploadedFile::fake()->image('c.png', 100, 100));

        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->otherLecturer->id)
            ->delete();

        $this->actingAs($this->otherLecturer)->get($this->routeFor($offering))->assertNotFound();
    }

    public function test_another_tenants_student_cannot_read_the_cover(): void
    {
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);
        $this->cover()->set($this->lecturer, $offering, UploadedFile::fake()->image('c.png', 100, 100));

        $other = $this->otherTenantAssignment();

        $this->actingAs($other['student'])
            ->get("/student/courses/{$offering->id}/cover-image")
            ->assertNotFound();
    }

    public function test_a_student_of_another_offering_cannot_read_the_cover(): void
    {
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);
        $this->cover()->set($this->lecturer, $offering, UploadedFile::fake()->image('c.png', 100, 100));

        // Same tenant, same student, different delivery. Only the Offering differs,
        // so the school check alone would not catch it.
        $second = \App\Models\CourseOffering::query()->findOrFail(
            $this->secondOfferingAssignment()['offeringId']
        );

        $this->actingAs($this->student)
            ->get("/student/courses/{$second->id}/cover-image")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════
    // VIEWING AND CHANGING ARE DIFFERENT QUESTIONS
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_confirmed_student_may_view_but_not_change_the_cover(): void
    {
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);
        $set = $this->cover()->set($this->lecturer, $offering, UploadedFile::fake()->image('c.png', 100, 100));

        // Conflating "may look at it" with "may change it" would let a student
        // decorate their own course unit.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->cover()->set($this->student, $set, UploadedFile::fake()->image('mine.png', 100, 100));
    }

    public function test_an_unallocated_lecturer_cannot_change_the_cover(): void
    {
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);

        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->otherLecturer->id)
            ->delete();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->cover()->set(
            $this->otherLecturer,
            $offering,
            UploadedFile::fake()->image('c.png', 100, 100)
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // NO INTERFACE EXISTS YET - BY DESIGN
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_cover_is_reachable_ONLY_through_its_authorising_route(): void
    {
        // ── WHY THIS REPLACES "no view renders a cover image yet" ────────────
        //
        // That test recorded a DELIBERATE state: the service was capability with no
        // interface, and the test made a later change have to come here and say so
        // rather than quietly introducing a card nobody reviewed. That is what has
        // now happened - the Course Home, its typographic fallback and the lecturer
        // upload - so the test is replaced rather than deleted.
        //
        // What it was really guarding still holds, and is asserted DIRECTLY here
        // rather than inferred from a page not mentioning a route: the bytes live
        // OUTSIDE the web root, and the only way to read them is a route that
        // re-checks that the viewer is entitled to this Offering.
        Storage::fake('local');

        $offering = CourseOffering::query()->findOrFail($this->offering->id);

        $this->cover()->set(
            $this->lecturer,
            $offering,
            UploadedFile::fake()->image('c.png', 100, 100)
        );

        $offering = $offering->fresh();

        // 1. Outside the web root. Not "we chose a private path" but "there is no
        //    public URL for it at all".
        $this->assertStringStartsWith('course-covers/', (string) $offering->cover_image_path);
        $this->assertFileDoesNotExist(public_path((string) $offering->cover_image_path));

        // 2. Readable through the lecturer's authorising route, and through the
        //    STUDENT's - one rule, two doors. An existing test already covers the
        //    student door in full, including that a student awaiting confirmation
        //    is refused, so repeating it here would assert something about the
        //    fixture rather than about the product.
        $this->actingAs($this->lecturer)
            ->get($this->routeFor($offering))
            ->assertOk();

        $this->actingAs($this->student)
            ->get("/student/courses/{$offering->id}/cover-image")
            ->assertOk();
    }

    public function test_the_LECTURER_upload_control_is_offered_and_honest_about_an_absent_cover(): void
    {
        $offering = CourseOffering::query()->findOrFail($this->offering->id);

        // The control is offered, and it says plainly that there is no image rather
        // than showing a stock photograph: a picture of a library over Business
        // Mathematics is a false claim about the course, and a borrowed image is
        // somebody else's to license.
        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$offering->id}")
            ->assertOk()
            ->assertSee('data-testid="cc-no-cover"', false)
            ->assertSee('This course has no image.', false)
            ->assertSee('data-testid="cc-cover-form"', false);
    }

    public function test_the_cover_upload_is_a_POST_that_refuses_a_missing_file(): void
    {
        $offering = CourseOffering::query()->findOrFail($this->offering->id);

        // The mutating route is a POST, because it changes stored state and must
        // not be reachable by a link prefetch or a crawler following a URL.
        //
        // A missing file is refused with a validation error, which is a better
        // outcome than a 404 from the READ route: the lecturer is told what was
        // wrong with their upload rather than being sent somewhere that does not
        // exist.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$offering->id}/cover-image", [])
            ->assertSessionHasErrors('cover_image');

        // And the cover is still absent, because a refused upload changes nothing.
        $this->assertNull(
            CourseOffering::query()->findOrFail($offering->id)->cover_image_path
        );
    }


    public function test_google_meet_is_still_not_a_hardcoded_course_field(): void
    {
        // The brief is explicit that Google Meet stays deferred until institutional
        // configuration exists, and that individual Live Classes keep their own
        // governed meeting URLs. Nothing may add a Meeting field to an Offering.
        // Schema::hasColumn(), not information_schema. The suite runs on SQLite,
        // where information_schema does not exist - so a test written against it
        // would only ever pass on the development database, which is not a test.
        foreach (['meet_url', 'google_meet_url', 'meeting_url', 'live_classroom_url'] as $forbidden) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('course_offerings', $forbidden),
                "'{$forbidden}' must not exist on a Course Offering"
            );
        }

        // And the individual Live Class keeps its own governed meeting URL, which is
        // where a Meeting belongs until institutional Google configuration exists.
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('live_classes', 'meeting_url'),
            'a Live Class must keep its own meeting_url'
        );
    }
}
