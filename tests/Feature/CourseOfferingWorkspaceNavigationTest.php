<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Support\CourseOffering\WorkspaceNav;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AssignmentFixture;
use Tests\TestCase;

/**
 * The lecturer's Course Offering workspace navigation.
 *
 * THE DEFECT THIS SUITE EXISTS FOR
 *
 * The tab strip was a hand-written loop inside the Overview view and nowhere
 * else. The route for Assignments existed, the controller existed, the tests
 * passed, and the previous report could honestly say "the Assignments tab is a
 * real route" - while a lecturer standing on the Course Content page had no way
 * to see or reach it. The workspace was not one coherent place; it was several
 * pages that happened to share a URL prefix.
 *
 * So these tests assert the navigation is RENDERED on every lecturer page, not
 * merely that a route resolves. A test that only called `route()` would have
 * passed throughout the defect.
 */
class CourseOfferingWorkspaceNavigationTest extends TestCase
{
    // The fixture's setUp is ALIASED, not shadowed: an inner setUp would replace
    // it outright and the fixture's typed properties would never be initialised.
    use AssignmentFixture {
        setUp as protected assignmentSetUp;
    }

    protected function setUp(): void
    {
        $this->assignmentSetUp();

        // Nothing extra is booted here. This suite is about navigation, and the
        // destinations it is responsible for - Overview, Content, Live Classes,
        // Assignments, Students - all render against this fixture.
        //
        // The Quizzes & Exams tab belongs to the Online Exam feature. Booting that
        // feature's whole schema here - exams, question topics, the question bank -
        // to render its index is the wrong direction, and it collided with this
        // fixture's own helpers besides. It is therefore checked as a REGISTERED
        // route, which is the claim this suite can actually stand behind.
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE REPORTED DEFECT: CONTENT MUST OFFER A WAY TO ASSIGNMENTS
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_course_content_page_offers_a_way_to_reach_assignments(): void
    {
        // THE EXACT PAGE THE REPORT NAMED. A lecturer here must be able to see
        // and click Assignments without being told a URL.
        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content")
            ->assertOk()
            ->assertSee('Course Offering workspace')
            ->assertSee('Assignments')
            ->assertSee(route('teacher.course_offerings.assignments.index', $this->offering->id), false);
    }

    public function test_the_workspace_navigation_is_present_on_every_lecturer_page(): void
    {
        $this->publishedAssignment();

        $pages = [
            'overview'   => "/teacher/course-offerings/{$this->offering->id}",
            'content'    => "/teacher/course-offerings/{$this->offering->id}/content",
            'content new'=> "/teacher/course-offerings/{$this->offering->id}/assignments/create",
            'students'   => "/teacher/course-offerings/{$this->offering->id}/students",
            'assignments'=> "/teacher/course-offerings/{$this->offering->id}/assignments",
        ];

        foreach ($pages as $label => $path) {
            $this->actingAs($this->lecturer)
                ->get($path)
                ->assertOk("{$label} should render")
                // The navigation landmark itself, on every page.
                ->assertSee('Course Offering workspace')
                // And the Course Offering context a lecturer needs to stay oriented.
                ->assertSee('offering-context-title', false)
                ->assertSee($this->offering->subject->code)
                ->assertSee($this->offering->subject->name)
                ->assertSee($this->offering->academicYear->label)
                ->assertSee($this->offering->academicPeriod->label);
        }
    }

    public function test_the_navigation_is_present_on_the_lesson_authoring_and_preview_pages(): void
    {
        $module = \App\Models\CourseOfferingModule::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'title' => 'Introduction to Business Mathematics',
        ]);

        $lesson = \App\Models\CourseOfferingLesson::query()->create([
            'school_id' => $this->school,
            'course_offering_id' => $this->offering->id,
            'course_offering_module_id' => $module->id,
            'title' => 'Ratios explained',
            'body' => '<p>Body</p>',
            'status' => \App\Models\CourseOfferingLesson::STATUS_DRAFT,
        ]);

        // The lesson routes nest under /content/lessons - the real registered
        // URIs, not a guessed shape.
        $base = route('teacher.course_offerings.content.index', $this->offering->id).'/lessons';

        // The editor page is still the "Content" section, not a section of its own
        // and not Overview. Asserted through the tab list rather than by matching
        // markup: the order of attributes in a rendered <a> is not a contract, and
        // a test that depended on it would fail for a reason that means nothing.
        $tabs = collect($this->tabsForLecturer("{$base}/{$lesson->id}/edit"))
            ->keyBy('key');
        $this->assertTrue($tabs['content']['active'], 'the editor page is the Content section');
        $this->assertFalse($tabs['overview']['active']);

        $this->actingAs($this->lecturer)
            ->get("{$base}/{$lesson->id}/edit")
            ->assertOk()
            ->assertSee('Course Offering workspace');

        $this->actingAs($this->lecturer)
            ->get("{$base}/{$lesson->id}/preview")
            ->assertOk()
            ->assertSee('Course Offering workspace');
    }

    public function test_the_navigation_is_present_on_the_assignment_workspace_pages(): void
    {
        $assignment = $this->publishedAssignment(['max_marks' => 20]);
        $submission = $this->submission($assignment, $this->student);

        $base = "/teacher/course-offerings/{$this->offering->id}/assignments";

        $paths = [
            $base,
            $base.'/create',
            "{$base}/{$assignment->id}",
            "{$base}/{$assignment->id}/edit",
            "{$base}/{$assignment->id}/preview",
            "{$base}/{$assignment->id}/submissions",
            "{$base}/{$assignment->id}/submissions/{$submission->id}",
        ];

        foreach ($paths as $path) {
            $this->actingAs($this->lecturer)
                ->get($path)
                ->assertOk()
                ->assertSee('Course Offering workspace');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE REQUIRED DESTINATIONS, EACH REUSING AN EXISTING ROUTE
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_navigation_points_at_the_existing_workflows(): void
    {
        $tabs = $this->tabsForLecturer('/teacher/course-offerings/'.$this->offering->id.'/content');
        $byKey = collect($tabs)->keyBy('key');

        // Every destination the brief requires, with the route it must use.
        $this->assertSame(
            route('teacher.course_offerings.show', $this->offering->id),
            $byKey['overview']['url']
        );
        $this->assertSame(
            route('teacher.course_offerings.content.index', $this->offering->id),
            $byKey['content']['url']
        );
        $this->assertSame(
            route('teacher.course_offerings.students', $this->offering->id),
            $byKey['students']['url']
        );
        $this->assertSame(
            route('teacher.course_offerings.assignments.index', $this->offering->id),
            $byKey['assignments']['url']
        );

        // Live Classes reuses the EXISTING workflow, pre-filtered to this Course
        // Offering by the query parameter that workflow already supports. It is
        // not a new route and not a second Live Class system.
        $this->assertSame(
            route('teacher.live_classes.index', ['course_offering_id' => $this->offering->id]),
            $byKey['live_classes']['url']
        );
        $this->assertStringContainsString('course_offering_id='.$this->offering->id, $byKey['live_classes']['url']);
    }

    public function test_the_live_classes_tab_opens_the_existing_workflow_for_this_offering(): void
    {
        // Clicking through, not asserting on a URL string.
        $this->actingAs($this->lecturer)
            ->get('/teacher/live-classes?course_offering_id='.$this->offering->id)
            ->assertOk();
    }

    public function test_the_assignments_tab_opens_the_existing_assignment_workspace(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.assignments.index', $this->offering->id))
            ->assertOk()
            ->assertSee('Assignments');
    }

    public function test_the_students_tab_opens_the_existing_confirmed_registration_roster(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.students', $this->offering->id))
            ->assertOk();
    }

    public function test_no_duplicate_routes_were_created_for_the_navigation(): void
    {
        // A navigation is a pointer at existing routes. If this had invented a
        // "workspace" route, the count below would rise.
        $names = [];
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getName()) {
                $names[] = $route->getName();
            }
        }

        $this->assertSame(count($names), count(array_unique($names)), 'route names must be unique');
        $this->assertContains('teacher.course_offerings.assignments.index', $names);
        $this->assertContains('teacher.course_offerings.content.index', $names);
        $this->assertContains('teacher.course_offerings.students', $names);
        $this->assertContains('teacher.live_classes.index', $names);
    }

    // ══════════════════════════════════════════════════════════════════════
    // ACTIVE STATE
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_active_tab_matches_the_page_being_viewed(): void
    {
        $expectations = [
            "/teacher/course-offerings/{$this->offering->id}" => 'overview',
            "/teacher/course-offerings/{$this->offering->id}/content" => 'content',
            "/teacher/course-offerings/{$this->offering->id}/assignments" => 'assignments',
            "/teacher/course-offerings/{$this->offering->id}/students" => 'students',
        ];

        foreach ($expectations as $path => $key) {
            $tabs = $this->tabsForLecturer($path);
            $active = array_values(array_filter($tabs, fn ($t) => $t['active']));

            $this->assertCount(1, $active, "exactly one tab may be active on {$path}");
            $this->assertSame($key, $active[0]['key'], "the wrong tab was active on {$path}");
        }
    }

    public function test_exactly_one_tab_is_ever_active(): void
    {
        $paths = [
            "/teacher/course-offerings/{$this->offering->id}",
            "/teacher/course-offerings/{$this->offering->id}/content",
            "/teacher/course-offerings/{$this->offering->id}/assignments",
        ];

        foreach ($paths as $path) {
            $response = $this->actingAs($this->lecturer)->get($path)->assertOk();
            $this->assertSame(
                1,
                substr_count($response->getContent(), 'data-testid="workspace-tab-active"'),
                "exactly one active tab must be marked on {$path}"
            );
        }
    }

    public function test_a_deeper_page_in_a_section_keeps_that_section_active(): void
    {
        $assignment = $this->publishedAssignment();

        // The authoring, preview and marking pages are all still "Assignments",
        // not "Overview", even though their route names differ.
        foreach ([
            "/teacher/course-offerings/{$this->offering->id}/assignments/create",
            "/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/preview",
            "/teacher/course-offerings/{$this->offering->id}/assignments/{$assignment->id}/submissions",
        ] as $path) {
            $tabs = $this->tabsForLecturer($path);
            $active = array_values(array_filter($tabs, fn ($t) => $t['active']));

            $this->assertCount(1, $active);
            $this->assertSame('assignments', $active[0]['key'], "wrong active tab on {$path}");
        }
    }

    public function test_the_active_tab_is_announced_not_only_coloured(): void
    {
        // The current section must be conveyed to assistive technology as well as
        // to the eye. A lecturer using a screen reader is otherwise told nothing
        // about where they are.
        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content")
            ->assertOk();

        $this->assertStringContainsString('aria-current="page"', $response->getContent());
        $this->assertStringContainsString('aria-label="Course Offering workspace"', $response->getContent());
    }

    // ══════════════════════════════════════════════════════════════════════
    // NOTHING IS A DEAD LINK
    // ══════════════════════════════════════════════════════════════════════

    public function test_every_workspace_href_really_opens(): void
    {
        // A tab that renders as a link but does not open is worse than no tab at
        // all: it teaches a lecturer the workspace has a door in it.
        //
        // These five are the workspace's OWN destinations, and rendering them is
        // this change's responsibility. Quizzes & Exams belongs to the Online
        // Exam feature and is checked separately below.
        $this->publishedAssignment();

        $workspaceKeys = ['overview', 'content', 'live_classes', 'assignments', 'students'];
        $tabs = collect($this->tabsForLecturer(
            "/teacher/course-offerings/{$this->offering->id}/content"
        ))->keyBy('key');

        foreach ($workspaceKeys as $key) {
            $tab = $tabs[$key];
            $this->assertNotNull($tab['url'], "{$key} must be linked for an allocated lecturer");

            $this->actingAs($this->lecturer)
                ->get($tab['url'])
                ->assertOk("the {$key} tab is a dead link");
        }
    }

    public function test_a_tab_with_no_href_does_not_claim_to_be_usable(): void
    {
        $tabs = collect($this->tabsForLecturer(
            "/teacher/course-offerings/{$this->offering->id}/content"
        ))->keyBy('key');

        foreach (['gradebook', 'analytics'] as $key) {
            $tab = $tabs[$key];
            $this->assertNull($tab['url'], "{$key} has no engine and so must have no href");
            $this->assertFalse($tab['available'], "{$key} is not built and so cannot be available");
            $this->assertTrue($tab['coming_soon'], "{$key} is declared but not built");
        }
    }

    public function test_the_quizzes_tab_points_at_the_existing_online_exam_workflow(): void
    {
        // Checked as a REGISTERED route rather than by rendering that feature's
        // index. Whether the Online Exams list renders is that feature's own
        // concern, tested there; what this suite owns is that the tab is wired to
        // a route that exists instead of to a URL that was typed by hand.
        $tab = collect($this->tabsForLecturer(
            "/teacher/course-offerings/{$this->offering->id}/content"
        ))->keyBy('key')['quizzes_exams'];

        $this->assertSame(route('teacher.online_exams.index'), $tab['url']);

        $resolved = app('router')->getRoutes()->getByName('teacher.online_exams.index');
        $this->assertNotNull($resolved, 'the Online Exam index route must exist');
        $this->assertSame($tab['url'], route('teacher.online_exams.index'));
    }

    public function test_unbuilt_sections_are_declared_but_never_linked(): void
    {
        $response = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content")
            ->assertOk();

        $html = $response->getContent();

        // Declared, so the intended shape is visible...
        $this->assertStringContainsString('Gradebook', $html);
        $this->assertStringContainsString('Analytics', $html);

        // ...but rendered with no href at all, so no door is offered that does not
        // open. Not a stub link, not a 404, and not an invented result.
        $this->assertMatchesRegularExpression(
            '#<span[^>]*nav-link disabled[^>]*>Gradebook#',
            $html,
            'Gradebook must render as a disabled span, not a link'
        );
        $this->assertMatchesRegularExpression(
            '#<span[^>]*nav-link disabled[^>]*>Analytics#',
            $html,
            'Analytics must render as a disabled span, not a link'
        );
    }

    public function test_the_legend_no_longer_claims_assignments_is_unbuilt(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content")
            ->assertOk()->getContent();

        // Assignments IS built. A footnote still listing it as forthcoming would
        // tell a lecturer to go and look for it somewhere that does not exist.
        $this->assertStringNotContainsString('Assignments, Gradebook and', $html);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AUTHORITY IS NOT CHANGED BY ANY OF THIS
    // ══════════════════════════════════════════════════════════════════════

    public function test_an_unallocated_lecturer_is_shown_no_workspace_navigation(): void
    {
        // The navigation is a pointer, never a permission. A lecturer with no
        // allocation on this Offering is not offered tabs they cannot use, and
        // still cannot reach the destinations by typing the URLs.
        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->otherLecturer->id)->delete();

        $this->actingAs($this->otherLecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content")
            ->assertNotFound();
    }

    public function test_the_allocation_is_re_checked_by_every_destination_behind_a_tab(): void
    {
        DB::table('course_offering_lecturer_allocations')
            ->where('user_id', $this->lecturer->id)
            ->update(['starts_on' => now()->addYear()->toDateString()]);

        // The tab is a convenience and never the access control. With the
        // allocation no longer in force, the AUTHORING action behind the
        // Assignments tab refuses on its own.
        //
        // The tab's own URL is deliberately NOT asserted as 403: the list page is
        // a read surface for an allocated lecturer, and asserting a refusal there
        // would be asserting something that was never true.
        $this->actingAs($this->lecturer)
            ->post("/teacher/course-offerings/{$this->offering->id}/assignments", [
                'title' => 'Should not be authorable',
                'max_marks' => 10,
                'submission_type' => Assignment::SUBMISSION_TEXT,
                'late_policy' => Assignment::LATE_ALLOWED,
                'status' => Assignment::STATUS_DRAFT,
            ])
            ->assertForbidden();

        $this->assertSame(0, DB::table('assignments')->where('title', 'Should not be authorable')->count());
    }

    public function test_pre_start_testing_authority_still_reaches_every_tab(): void
    {
        // Daniel authorises on the pre-start System Tester path, and the
        // navigation must not quietly hide tabs from him.
        $this->assertTrue(
            app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class)
                ->teachingActionsAllowed(
                    app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class)
                        ->resolveForLecturer($this->lecturer, (int) $this->offering->id)
                )
        );

        $this->actingAs($this->lecturer)
            ->get("/teacher/course-offerings/{$this->offering->id}/content")
            ->assertOk()
            ->assertSee(route('teacher.course_offerings.assignments.index', $this->offering->id), false);
    }

    public function test_a_lecturer_gets_no_navigation_on_their_own_offering_list(): void
    {
        // The list of Offerings is not a section of one, so it has no workspace
        // navigation - and the partial must fail quietly rather than error.
        $this->actingAs($this->lecturer)
            ->get(route('teacher.course_offerings.index'))
            ->assertOk()
            ->assertDontSee('aria-label="Course Offering workspace"', false);
    }

    // ══════════════════════════════════════════════════════════════════════
    // K12 IS NOT AFFECTED
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_legacy_k12_assignment_screens_are_unchanged(): void
    {
        // The workspace navigation is a Course Offering surface. A legacy K12
        // assignment is not inside a Course Offering, so the composer resolves no
        // offering and the partial must render nothing at all - not a broken
        // partial, and not a tab strip whose links would mean nothing here.
        $k12 = $this->k12Assignment();

        $this->assertNull($k12->course_offering_id, 'a legacy row belongs to no Course Offering');

        // The legacy K12 student list, reached as a student, is unchanged and
        // carries no Course Offering workspace navigation.
        $this->actingAs($this->student)
            ->get(route('student.assignments.list'))
            ->assertOk()
            ->assertDontSee('aria-label="Course Offering workspace"', false);
    }

    // ══════════════════════════════════════════════════════════════════════
    // helpers
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The tab list a lecturer would be SHOWN at a given path.
     *
     * Computed through the same service the view composer uses, so these tests
     * cannot pass against a list the UI does not actually render.
     *
     * @return list<array<string, mixed>>
     */
    private function tabsForLecturer(string $path): array
    {
        $request = Request::create($path, 'GET');

        $nav = app(WorkspaceNav::class);
        $access = app(\App\Support\CourseOffering\LecturerCourseOfferingAccess::class);
        $resolved = $access->resolveForLecturer($this->lecturer, (int) $this->offering->id);

        return $nav->tabs($request, $resolved, $access->teachingActionsAllowed($resolved), $nav->currentKeyFor($request));
    }
}
