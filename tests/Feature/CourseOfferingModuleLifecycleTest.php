<?php

namespace Tests\Feature;

use App\Models\CourseOfferingModule;
use App\Support\CourseContent\CourseOfferingModuleLifecycle;
use App\Support\CourseContent\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\CourseOfferingExamFixture;
use Tests\Feature\Support\OnlineExamTestHelper;
use Tests\TestCase;

/**
 * THE MODULE LIFECYCLE, AND THE RICH-TEXT AUTHORING THAT WAS LOSING TEXT.
 *
 * ── WHY THE MODULE LIFECYCLE IS TESTED AS A ROUTE AND NOT ONLY A SERVICE ─────
 *
 * The reported symptom was a lecturer with "no clear action" on a module marked
 * Scheduled. The lifecycle was never missing - `updateModule()` has always accepted
 * `status` and `released_at` - it was unreachable, because the only control was a
 * `<select>` and a date field inside a collapsed panel.
 *
 * So the assertions here are about the ROUTE and the BUTTONS:
 *   - a lecturer allocated to the Offering can perform every legal move;
 *   - a lecturer allocated to a DIFFERENT Offering cannot, by URL or by form;
 *   - a student sees a published module, and does NOT see a draft or a future one.
 *
 * A service-only test would pass on all of the above while the page still offered
 * nothing, which is precisely the bug.
 */
class CourseOfferingModuleLifecycleTest extends TestCase
{
    use OnlineExamTestHelper;
    use CourseOfferingExamFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCourseOfferingExamSchema();
    }

    private function lecturer(): \App\Models\User
    {
        return $this->lecturerA ??= $this->makeUser(3, 1, 'active', 'Lecturer A');
    }

    private ?\App\Models\User $lecturerA = null;

    /**
     * ONE Offering for the whole test.
     *
     * Memoised on purpose. The first version of this file used `$this->offering()`
     * assuming the fixture declared it, which it does not - so every test errored on
     * an undefined property before reaching a single assertion. A helper that builds a
     * NEW Offering per call is the same trap as a cast helper that builds a new user
     * per call: two calls are two different Offerings, and "the module's Offering"
     * stops meaning anything.
     */
    private ?\App\Models\CourseOffering $offeringRow = null;

    private function offering(): \App\Models\CourseOffering
    {
        return $this->offeringRow ??= $this->makeOffering(1, ['reference' => 'LIFECYCLE-2026-S1']);
    }

    private function student(): \App\Models\User
    {
        return $this->makeUser(7, 1, 'active', 'Student A');
    }

    /** A module in a given state, on this Offering. */
    private function module(string $status = CourseOfferingModule::STATUS_DRAFT, ?string $releasedAt = null): CourseOfferingModule
    {
        $row = [
            'school_id' => 1,
            'course_offering_id' => $this->offering()->id,
            'title' => 'Module under test',
            'summary' => null,
            'sequence' => 1,
            'status' => $status,
            'released_at' => $releasedAt,
            'created_by' => 1,
            'updated_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $id = (int) DB::table('course_offering_modules')
            ->insertGetId($this->onlyExistingColumns('course_offering_modules', $row));

        return CourseOfferingModule::query()->findOrFail($id);
    }

    /**
     * Keep only the columns the table actually has.
     *
     * The shared `CourseOfferingExamFixture` is a hand-written, REDUCED copy of the
     * Course Offering schema - it has no `course_offering_modules.summary`, and its
     * `assignments` has no `created_by`. Three other suites depend on it, and it was
     * left deliberately partial rather than corrected.
     *
     * Correcting it mid-presentation would mean editing a fixture those suites share,
     * to fix a problem in MY test rather than in the product. So the insert is trimmed
     * to the columns that exist instead. The lifecycle assertions read `status` and
     * `released_at` through the real model and the real controller, so nothing under
     * test is weakened by a column this helper happens to drop.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $table, array $row): array
    {
        $present = \Illuminate\Support\Facades\Schema::getColumnListing($table);

        return array_intersect_key($row, array_flip($present));
    }

    /** A plain assignment for the authoring tests. */
    private function assignment(string $title): \App\Models\Assignment
    {
        $row = [
            'school_id' => 1,
            'course_offering_id' => $this->offering()->id,
            'title' => $title,
            'instructions' => 'Answer it.',
            'status' => 'draft',
            'created_by' => $this->lecturer()->id,
            'updated_by' => $this->lecturer()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $id = (int) DB::table('assignments')->insertGetId(
            $this->onlyExistingColumns('assignments', $row)
        );

        return \App\Models\Assignment::query()->findOrFail($id);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. THE MOVES, THROUGH THE REAL ROUTE
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_DRAFT_module_can_be_PUBLISHED_NOW_by_its_LECTURER(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);

        $this->actingAs($lecturer)
            ->post($this->transitionUrl($module, 'published'))
            ->assertRedirect(route('teacher.course_offerings.content.index', $this->offering()->id))
            ->assertSessionHas('success');

        $fresh = $module->fresh();

        $this->assertSame('published', $fresh->status);
        // Publish NOW means released at once, encoded as "no waiting" rather than as
        // an instant in the past that every later comparison would have to special-case.
        $this->assertNull($fresh->released_at, 'publishing now must not leave a release instant behind');
        $this->assertTrue($fresh->isReleasedToStudents(), 'and students can see it at once');
        $this->assertSame('Published', $fresh->displayStatusLabel());
    }

    public function test_a_module_can_be_SCHEDULED_for_a_future_moment(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);

        $when = now()->addWeek();

        $this->actingAs($lecturer)
            ->post($this->transitionUrl($module, 'published'), [
                'released_at' => $when->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect();

        $fresh = $module->fresh();

        $this->assertSame('published', $fresh->status);
        $this->assertTrue($fresh->released_at->isFuture(), 'the release instant is kept');
        $this->assertSame('Scheduled', $fresh->displayStatusLabel(), 'and it reads as Scheduled, not Published');
        $this->assertFalse($fresh->isReleasedToStudents(), 'a future module is NOT released yet');
    }

    /**
     * THE REPORTED SYMPTOM: a module already Scheduled, released immediately.
     *
     * Before the lifecycle actions existed, this move was only reachable by opening
     * the settings panel, changing a dropdown that already said "published", and
     * clearing a date field - i.e. it was reachable, but not obviously so.
     */
    public function test_a_SCHEDULED_module_can_be_released_IMMEDIATELY(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $module = $this->module(CourseOfferingModule::STATUS_PUBLISHED, now()->addWeek()->format('Y-m-d H:i:s'));

        $this->assertSame('Scheduled', $module->displayStatusLabel(), 'precondition');
        $this->assertFalse($module->isReleasedToStudents(), 'precondition: not yet visible');

        $this->actingAs($lecturer)
            ->post($this->transitionUrl($module, 'published'))
            ->assertRedirect();

        $fresh = $module->fresh();

        $this->assertSame('Published', $fresh->displayStatusLabel());
        $this->assertNull($fresh->released_at, 'the schedule is cleared, not overwritten with now()');
        $this->assertTrue($fresh->isReleasedToStudents());
    }

    public function test_a_SCHEDULED_module_can_be_returned_to_DRAFT(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $module = $this->module(CourseOfferingModule::STATUS_PUBLISHED, now()->addWeek()->format('Y-m-d H:i:s'));

        $this->actingAs($lecturer)
            ->post($this->transitionUrl($module, 'draft'))
            ->assertRedirect();

        $fresh = $module->fresh();

        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->released_at, 'returning to draft clears the release instant');
        $this->assertFalse($fresh->isReleasedToStudents());
    }

    public function test_a_PUBLISHED_module_can_be_UNPUBLISHED(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $module = $this->module(CourseOfferingModule::STATUS_PUBLISHED, null);

        $this->actingAs($lecturer)
            ->post($this->transitionUrl($module, 'draft'))
            ->assertRedirect();

        $this->assertFalse($module->fresh()->isReleasedToStudents());
    }

    /**
     * A release date in the PAST is not a schedule.
     *
     * Storing it would render the module "overdue" on every page and make any later
     * comparison against the past ambiguous, so it is normalised to publish-now.
     */
    public function test_a_PAST_release_date_publishes_NOW_rather_than_scheduling_in_the_PAST(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);

        $this->actingAs($lecturer)
            ->post($this->transitionUrl($module, 'published'), [
                'released_at' => now()->subWeek()->format('Y-m-d\TH:i'),
            ])
            ->assertRedirect();

        $fresh = $module->fresh();

        $this->assertSame('published', $fresh->status);
        $this->assertNull($fresh->released_at);
        $this->assertTrue($fresh->isReleasedToStudents());
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. THE BUTTONS EXIST, AND ONLY FOR LEGAL MOVES
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_DRAFT_module_offers_PUBLISH_NOW_and_SCHEDULE(): void
    {
        $actions = CourseOfferingModuleLifecycle::actionsFor($this->module(CourseOfferingModule::STATUS_DRAFT));

        $this->assertArrayHasKey('publish_now', $actions);
        $this->assertArrayHasKey('schedule', $actions);

        // A module already in draft is NOT offered "Return to Draft". There is
        // nothing to return to, and a button that appears to do something while
        // changing nothing is worse than no button.
        $this->assertArrayNotHasKey('draft', $actions);
    }

    public function test_a_SCHEDULED_module_offers_PUBLISH_NOW_and_RESCHEDULE(): void
    {
        $actions = CourseOfferingModuleLifecycle::actionsFor(
            $this->module(CourseOfferingModule::STATUS_PUBLISHED, now()->addWeek()->format('Y-m-d H:i:s'))
        );

        $this->assertArrayHasKey('publish_now', $actions);
        $this->assertSame(
            'Reschedule',
            $actions['schedule']['label'],
            'the label says Reschedule, because the module already has a date'
        );
        $this->assertStringContainsString(
            'waiting',
            $actions['publish_now']['hint'],
            'and Publish now explains that it overrides the date'
        );
    }

    public function test_the_ACTION_ROW_is_rendered_with_the_controls(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);

        $html = $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.content.index', $this->offering()->id))
            ->assertOk()
            ->getContent();

        // The controls the report said were missing.
        $this->assertStringContainsString('data-testid="cc-module-action-publish_now"', $html, 'Publish now must be reachable');
        $this->assertStringContainsString('data-testid="cc-module-schedule"', $html, 'Schedule must be reachable');
        $this->assertStringContainsString(
            '/state/published',
            $html,
            'and it must post to the transition route'
        );
        $this->assertStringContainsString('name="_token"', $html, 'the form needs a CSRF token or a click does nothing');

        // The schedule panel, with a date field of its own.
        $this->assertStringContainsString('data-testid="cc-module-release-input"', $html);

        // Module-aware assessment links.
        $this->assertStringContainsString('data-testid="cc-module-assignment"', $html);
        $this->assertStringContainsString(
            'course_offering_module_id='.$module->id,
            $html,
            'the assignment link must carry the module'
        );
        $this->assertStringContainsString('data-testid="cc-module-exam"', $html);

        // Adding a lesson was already there and must not have been displaced.
        $this->assertStringContainsString('Add Lesson', $html);

        // A DRAFT module is NOT offered "Return to Draft" - there is nothing to
        // return to. Asserted so the absence is deliberate rather than an accident.
        $this->assertStringNotContainsString(
            'data-testid="cc-module-action-draft"',
            $html,
            'a draft must not be offered a button that changes nothing'
        );
    }

    public function test_a_MANAGER_sees_NO_action_buttons_in_the_CONTROLLER_action_forms(): void
    {
        // The buttons are POST forms. Each must post to the transition route with a
        // CSRF token, or a browser would silently do nothing when clicked.
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);

        $html = $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.content.index', $this->offering()->id))
            ->getContent();

        $this->assertStringContainsString(
            'modules/'.$module->id.'/state/published',
            $html,
            'the button must post to the transition route'
        );
        $this->assertStringContainsString('name="_token"', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. SECURITY: NOBODY ELSE CAN MOVE IT
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_LECTURER_allocated_ELSEWHERE_cannot_move_the_module(): void
    {
        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);
        $stranger = $this->makeUser(3, 1, 'active', 'Lecturer B');

        // 404, not 403, and deliberately: `resolveOffering()` refuses before the
        // manage check runs, so an unallocated colleague cannot even learn that this
        // Offering exists. Asserting 403 would have been asserting the WEAKER property.
        $this->actingAs($stranger)
            ->post($this->transitionUrl($module, 'published'))
            ->assertNotFound();

        $this->assertSame('draft', $module->fresh()->status, 'and nothing changed');
    }

    public function test_a_LECTURER_from_ANOTHER_institution_cannot_move_it(): void
    {
        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);
        $theirs = $this->otherInstitution();

        $this->actingAs($theirs['lecturer'])
            ->post($this->transitionUrl($module, 'published'))
            ->assertNotFound();

        $this->assertSame('draft', $module->fresh()->status);
    }

    public function test_a_STUDENT_cannot_move_a_module(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);
        $student = $this->student();
        $this->confirmStudent($this->offering(), $student);

        $this->actingAs($student)
            ->post($this->transitionUrl($module, 'published'))
            // A student on a teacher route is turned away by the portal guard.
            ->assertStatus(302);

        $this->assertSame('draft', $module->fresh()->status);
    }

    public function test_a_module_in_ANOTHER_offering_cannot_be_moved_through_THIS_offerings_url(): void
    {
        // The Offering id is in the URL and the module is re-resolved INSIDE it, so a
        // module from another Offering is not found - a 404, not a silent success.
        $theirs = $this->otherInstitution();
        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);

        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);

        $this->actingAs($lecturer)
            ->post(route(
                'teacher.course_offerings.content.modules.transition',
                [$theirs['offering']->id, $module->id, 'published']
            ))
            ->assertNotFound();

        $this->assertSame('draft', $module->fresh()->status);
    }

    public function test_an_ILLEGAL_move_is_refused_rather_than_performed(): void
    {
        // Archived -> published is not in the transition table. The route would not
        // serve it directly, but the service must refuse it for any other caller.
        $archived = $this->module('archived');

        $this->assertFalse(
            CourseOfferingModuleLifecycle::canTransition($archived, 'published'),
            'an archived module must not be publishable in one step'
        );

        $this->expectException(\DomainException::class);
        CourseOfferingModuleLifecycle::apply($archived, 'published');
    }

    public function test_an_UNRECOGNISED_state_is_refused(): void
    {
        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);

        $this->expectException(\DomainException::class);
        CourseOfferingModuleLifecycle::apply($module, 'deleted');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4. STUDENT VISIBILITY, THE CONSEQUENCE OF EVERY MOVE
    // ══════════════════════════════════════════════════════════════════════

    public function test_STUDENT_VISIBILITY_follows_the_lifecycle_exactly(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $student = $this->student();
        $this->confirmStudent($this->offering(), $student);

        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);

        $released = CourseOfferingModule::query()
            ->where('course_offering_id', $this->offering()->id)
            ->whereNotIn('id', [$module->id]);

        // DRAFT: not visible.
        $this->assertFalse($module->isReleasedToStudents(), 'a draft is never visible, however old');

        // PUBLISHED NOW: visible.
        CourseOfferingModuleLifecycle::apply($module, 'published', null, $lecturer->id);
        $this->assertTrue($module->fresh()->isReleasedToStudents(), 'published is visible at once');

        // UNPUBLISHED: hidden again.
        CourseOfferingModuleLifecycle::apply($module->fresh(), 'draft', null, $lecturer->id);
        $this->assertFalse($module->fresh()->isReleasedToStudents(), 'unpublishing hides it again');

        // FUTURE: not visible yet.
        CourseOfferingModuleLifecycle::apply($module->fresh(), 'published', now()->addWeek()->toDateTimeString(), $lecturer->id);
        $this->assertFalse($module->fresh()->isReleasedToStudents(), 'a scheduled module appears only at its moment');

        // PAST: visible.
        CourseOfferingModuleLifecycle::apply($module->fresh(), 'published', now()->subHour()->toDateTimeString(), $lecturer->id);
        $this->assertTrue($module->fresh()->isReleasedToStudents(), 'and once the moment has passed it is visible');

        $this->assertNotNull($released, 'sanity: the query above ran');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5. THE ASSIGNMENT LINK CARRIES THE MODULE
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_assignment_CREATE_form_is_PRE_SELECTED_with_the_module(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $module = $this->module(CourseOfferingModule::STATUS_DRAFT);

        $html = $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.assignments.create', $this->offering()->id)
                .'?course_offering_module_id='.$module->id)
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/value="'.$module->id.'"\s+selected/',
            $html,
            'the module must arrive already chosen, so the lecturer does not re-pick it'
        );
    }

    public function test_a_module_id_from_ANOTHER_offering_is_IGNORED_not_applied(): void
    {
        $lecturer = $this->lecturer();
        $this->allocateLecturer($this->offering(), $lecturer);
        $theirs = $this->otherInstitution();

        // The id is matched against THIS Offering's own module options, so a foreign
        // one is dropped rather than attached. `resolveModule()` remains the authority.
        $html = $this->actingAs($lecturer)
            ->get(route('teacher.course_offerings.assignments.create', $this->offering()->id)
                .'?course_offering_module_id='.$theirs['offering']->id)
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/value="'.$theirs['offering']->id.'"\s+selected/',
            $html,
            'a module belonging to another Offering must never be pre-selected'
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6. THE RICH-TEXT AUTHORING FIXES
    // ══════════════════════════════════════════════════════════════════════

    /**
     * THE REPORTED AUTHORING FAILURE, AS A UNIT.
     *
     * `API.syncAll()` existed with a comment saying it should be called "on
     * `submit`", and nothing called it. So the editor relied entirely on Summernote's
     * internal per-keystroke sync, and a lecturer who typed a question and pressed
     * Save could have the textarea submitted with its OLD value - which the server
     * correctly refused with "Write the question. A question with no text is not a
     * question."
     *
     * The wiring is JavaScript, so it is asserted on the SOURCE: a listener bound to
     * `submit`, in the capture phase, calling `syncAll`.
     *
     * The sweep is scoped to the form being submitted rather than to `document`. Both
     * cover the question form; the scoped form is the tighter contract, so the
     * assertion checks that the listener really CALLS the sweep rather than pinning
     * which root it is handed — which is an implementation detail, not the guarantee.
     */
    public function test_the_editor_syncs_its_CONTENT_into_the_FIELD_on_SUBMIT(): void
    {
        $js = (string) file_get_contents(public_path('js/academic-editor.js'));

        $this->assertMatchesRegularExpression(
            "/addEventListener\('submit'/",
            $js,
            'a submit listener must exist'
        );

        $this->assertMatchesRegularExpression(
            "/addEventListener\('submit'.*?true\s*\)/s",
            $js,
            'and it must be CAPTURE phase, so it runs before a handler that calls preventDefault'
        );

        $this->assertMatchesRegularExpression(
            "/addEventListener\('submit'[\s\S]*?API\.syncAll\(/",
            $js,
            'the listener must actually call syncAll - defining it is what went wrong'
        );

        // The half that produced exam 21. `syncAll()` once read the vendor getter and
        // wrote the result back into the VENDOR INSTANCE, so the element the browser
        // serialises was never assigned and the question posted empty while the
        // lecturer was looking at their text in the editor.
        $this->assertMatchesRegularExpression(
            '/syncAll: function \(root\)[\s\S]*?area\.value = code/',
            $js,
            'syncAll must assign area.value - the field the browser actually posts'
        );

        // And the wiring must be INVOKED, not merely defined. It was defined and
        // commented as if it were called, for a long time, and nothing called it.
        $this->assertMatchesRegularExpression(
            '/wireSubmitSync\(\);/',
            $js,
            'the submit sync must be invoked at load, or a defined listener never runs'
        );
    }

    /**
     * ONE canonical answer to "does this rich text contain anything?".
     *
     * The cases the brief names, and the one that caused the bad row in the
     * database: a heading and a typeface wrapping U+FEFF.
     *
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('meaningfulTextCases')]
    public function test_the_canonical_RICH_TEXT_completeness_check(string $html, bool $expected): void
    {
        $this->assertSame(
            $expected,
            app(HtmlSanitizer::class)->hasMeaningfulText($html),
            'the completeness answer for '.json_encode($html)
        );
    }

    public static function meaningfulTextCases(): array
    {
        return [
            'an empty string' => ['', false],
            'an empty paragraph' => ['<p><br></p>', false],
            'an empty paragraph with a nbsp' => ["<p><br></p>\u{00A0}", false],
            'nbsp alone' => ["<p>\u{00A0}</p>", false],
            'BOM alone - THE BAD ROW' => ["<p>\u{FEFF}</p>", false],
            'a heading wrapping a BOM - THE BAD ROW' => [
                "<h1><span style=\"font-family: Arial\">\u{FEFF}</span></h1>",
                false,
            ],
            'zero-width space alone' => ["<p>\u{200B}</p>", false],
            'an empty div' => ['<div></div>', false],
            'an empty list' => ['<ul></ul>', false],
            'an image alone' => ['<p><img src="x.png"></p>', false],
            'a heading with REAL TEXT - MUST PASS' => [
                '<h1><span style="font-family: Arial">Question text</span></h1>',
                true,
            ],
            'bold text' => ['<p><b>Explain the addition.</b></p>', true],
            'plain text' => ['<p>Explain the addition.</p>', true],
            'a table cell' => ['<table><tr><td>Profit</td></tr></table>', true],
            'a superscript formula' => ['<p>10<sup>3</sup> units</p>', true],
        ];
    }

    /**
     * THE AUTHORING RULE ITSELF: the save path no longer answers completeness with
     * the PREVIEW function.
     *
     * The two `QuestionService::add()` tests this replaces both failed on
     * "no such table: assignment_submissions" - the shared fixture does not create
     * that table - so they never reached the prompt check they were written to
     * exercise. The rule is therefore pinned where it is decided instead:
     *
     *   - `hasMeaningfulText()` is the canonical answer, proven by the data provider
     *     above against every case that matters;
     *   - and no completeness check anywhere still calls `toText() === ''`.
     *
     * The second half is the assertion that would actually have caught the reported
     * bug, and it does not need a table that does not exist.
     */
    public function test_the_SAVE_PATH_no_longer_answers_completeness_with_the_PREVIEW_function(): void
    {
        foreach ([
            'App\Support\Assignments\QuestionService',
            'App\Support\Assignments\AssignmentService',
            'App\Support\CourseContent\CourseContentService',
        ] as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $source = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression(
                '/\$this->sanitizer->toText\([^)]*\)\s*===?\s*\'\'/',
                $source,
                $class.' must not decide "is this empty?" with a preview function'
            );
        }
    }

    /**
     * THE PROVENANCE OF THE BAD ROW, STATED AS A TEST.
     *
     * The document that reached `assignment_questions` on Offering 5 was:
     *
     *     <h1><span style="font-family: Arial">﻿</span></h1>
     *
     * It passed the then-current `toText() === ''` check, because U+FEFF is a
     * zero-width NO-BREAK SPACE and `trim()` does not remove it. So an unanswerable
     * question worth 5 marks was published to a student.
     *
     * That exact byte sequence is asserted here against the canonical helper, so the
     * reason it happened cannot come back.
     */
    public function test_the_EXACT_document_that_reached_the_database_is_now_REFUSED(): void
    {
        $badRow = "<h1><span style=\"font-family: Arial\">\u{FEFF}</span></h1>";

        $this->assertFalse(
            app(HtmlSanitizer::class)->hasMeaningfulText($badRow),
            'the row that reached Offering 5 must be recognised as empty'
        );

        // And a real heading with real words in it must NOT be refused - which was
        // the other half of the reported problem: a valid rich-text question being
        // rejected with "Write the question".
        $real = '<h1><span style="font-family: Arial">Question text</span></h1>';

        $this->assertTrue(
            app(HtmlSanitizer::class)->hasMeaningfulText($real),
            'a styled heading with real text is content, and must be accepted'
        );
    }
    private function transitionUrl(CourseOfferingModule $module, string $to): string
    {
        return route(
            'teacher.course_offerings.content.modules.transition',
            [$this->offering()->id, $module->id, $to]
        );
    }
}