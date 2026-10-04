<?php

namespace App\Support\CourseOffering;

use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The lecturer's Course Offering workspace navigation, in one place.
 *
 * WHY THIS IS A SERVICE AND NOT A CONTROLLER METHOD
 *
 * The tab strip used to be a private method on TeacherCourseOfferingController,
 * rendered by a hand-written `@foreach` inside ONE view. That is why the Course
 * Content page had no tabs at all: the navigation physically existed in one file
 * and no other page could render it, so a lecturer who opened Content - the page
 * they were told to build content on - had no way to reach Assignments, Live
 * Classes or Students without being told a URL.
 *
 * Moving the tab list here and rendering it from a shared partial, supplied by a
 * view composer, makes the omission STRUCTURALLY IMPOSSIBLE rather than
 * something every new page has to remember. A page in this namespace either has
 * the workspace navigation or is not in this namespace.
 *
 * ONE LIST OF DESTINATIONS, NO DUPLICATES
 *
 * Every tab points at a route that already exists. Nothing here creates a second
 * Course Offering system, a second Live Class workflow or a second roster:
 *
 *   Overview      teacher.course_offerings.show        the existing lecturer overview
 *   Content       teacher.course_offerings.content.*   the existing module/lesson builder
 *   Live Classes  teacher.live_classes.index           the existing Live Class workflow,
 *                                                      pre-filtered to THIS Offering by the
 *                                                      `course_offering_id` query parameter
 *                                                      that workflow already supports
 *   Assignments   teacher.course_offerings.assignments.*  the existing Assignment workspace
 *   Students      teacher.course_offerings.students    the existing confirmed-registration roster
 *
 * Live Classes is worth a note. The lecturer's Live Class list is a whole-workspace
 * list that already accepts `?course_offering_id=`. Passing this Offering's id
 * gives the lecturer exactly their own classes without forking the workflow or
 * inventing an Offering-scoped Live Class screen. The tab re-checks authority on
 * arrival, so a lecturer can only ever see classes their allocation entitles them
 * to - which the Live Class controller already enforces independently.
 *
 * WHAT "NOT BUILT YET" MEANS HERE
 *
 * A tab with no engine behind it carries `url => null` and renders as plain text
 * with NO href. It is not a link to a stub and not a 404: a lecturer is never
 * offered a door that does not open, and nothing implies PIIE has a gradebook or
 * analytics engine when it does not. Those tabs are declared so the workspace's
 * intended shape is visible and reviewable, not so it can be faked.
 *
 * THE NAVIGATION IS NOT THE ACCESS CONTROL
 *
 * `available` is a courtesy so a lecturer is not offered something that will be
 * refused. Every destination re-checks the lecturer's own allocation on this exact
 * Course Offering server-side, and a tab is never a way around that.
 */
class WorkspaceNav
{
    /**
     * A stable list of destination keys, in the order a lecturer reads them.
     *
     * Declared once so the composer, the tests and any future surface cannot
     * disagree about what the workspace contains or what order it is in.
     */
    public const KEYS = [
        'overview',
        'content',
        'live_classes',
        'assignments',
        'quizzes_exams',
        'students',
        'gradebook',
        'analytics',
    ];

    public function __construct(
        private readonly LecturerCourseOfferingAccess $access,
    ) {}

    /**
     * The tabs for one lecturer and one Course Offering.
     *
     * Each tab is:
     *   key           stable identifier, also the ACTIVE-state key
     *   label         the word a lecturer reads
     *   url           a real URL, or null when there is nothing to open
     *   available     may this lecturer use it right now
     *   coming_soon   declared but not built, as distinct from merely unavailable
     *   description   plain words, used as the title/tooltip and by the tests
     *   active        is this the page being viewed
     *
     * @return list<array{key:string,label:string,url:?string,available:bool,coming_soon:bool,description:string,active:bool}>
     */
    public function tabs(Request $request, CourseOffering $offering, bool $canTeach, ?string $currentKey = null): array
    {
        $rosterAvailable = $this->access->rosterAvailable($offering);

        $tabs = [
            [
                'key' => 'overview',
                'label' => 'Overview',
                'url' => route('teacher.course_offerings.show', $offering->id),
                'available' => true,
                'coming_soon' => false,
                'description' => 'This Course Offering at a glance: teaching team, Live Classes and what to do next.',
            ],
            [
                'key' => 'content',
                'label' => 'Content',
                'url' => route('teacher.course_offerings.content.index', $offering->id),
                'available' => $canTeach,
                'coming_soon' => false,
                'description' => 'Build and organise the learning journey students will follow in this Course Unit.',
            ],
            [
                'key' => 'live_classes',
                'label' => 'Live Classes',
                // The EXISTING Live Class workflow, filtered to this Course
                // Offering by the query parameter it already supports. Not a
                // second workflow and not a new route.
                'url' => route('teacher.live_classes.index', ['course_offering_id' => $offering->id]),
                'available' => $canTeach,
                'coming_soon' => false,
                'description' => 'Your Live Classes for this Course Offering.',
            ],
            [
                'key' => 'assignments',
                'label' => 'Assignments',
                'url' => route('teacher.course_offerings.assignments.index', $offering->id),
                'available' => $canTeach,
                'coming_soon' => false,
                'description' => 'Set work, choose when it opens and closes, and mark what comes back.',
            ],
            [
                'key' => 'quizzes_exams',
                'label' => 'Quizzes & Exams',
                'url' => route('teacher.online_exams.index'),
                'available' => $canTeach,
                'coming_soon' => false,
                'description' => 'Create, mark and monitor Online Exams. Exams remain scoped to the Course Unit.',
            ],
            [
                'key' => 'students',
                'label' => 'Students',
                'url' => route('teacher.course_offerings.students', $offering->id),
                'available' => $rosterAvailable,
                'coming_soon' => false,
                'description' => $rosterAvailable
                    ? 'Confirmed students registered for this Course Offering.'
                    : 'The confirmed student roster becomes available when teaching begins.',
            ],
            [
                'key' => 'gradebook',
                'label' => 'Gradebook',
                'url' => null,
                'available' => false,
                'coming_soon' => true,
                'description' => 'Not built. A gradebook needs a marks engine and an assessment source, and neither exists yet.',
            ],
            [
                'key' => 'analytics',
                'label' => 'Analytics',
                'url' => null,
                'available' => false,
                'coming_soon' => true,
                'description' => 'Not built. It will read real engagement and outcome data, not page counts.',
            ],
        ];

        return array_map(function (array $tab) use ($currentKey): array {
            // A tab the lecturer may not use is stated as unavailable rather than
            // linked-and-then-refused. The route authorises either way.
            if (! $tab['available']) {
                $tab['url'] = null;
            }

            $tab['active'] = $tab['key'] === $currentKey;

            return $tab;
        }, $tabs);
    }

    /**
     * Which tab corresponds to a URL path.
     *
     * ACTIVE STATE IS DERIVED FROM THE PATH, NEVER FROM A CONTROLLER VARIABLE.
     *
     * A hand-set "you are on the assignments tab" flag is correct until the first
     * page that forgets to set it - and a page that forgets is exactly the bug
     * this whole change is fixing. Matching the request path means a page cannot
     * claim to be the wrong section, because the section is read from where the
     * browser actually is.
     *
     * Matched on a route pattern rather than a route name, because the deeper
     * pages in each section (a lesson form, a submission, a preview) have
     * different route names but belong to the same section as their index.
     */
    public function currentKeyFor(Request $request): ?string
    {
        $path = '/'.ltrim($request->path(), '/');

        return match (true) {
            // Assignments, including the authoring, preview, marking and
            // submission pages.
            str_contains($path, '/assignments') => 'assignments',
            // Content, including the lesson editor and its preview.
            str_contains($path, '/content') => 'content',
            str_contains($path, '/attendance') => 'attendance',
            str_contains($path, '/students') => 'students',
            // The bare Offering page is the Overview. Checked last, because every
            // section above also lives under the same prefix.
            (bool) preg_match('#/teacher/course-offerings/\d+/?$#', $path) => 'overview',
            // `teacher/course-offerings` with no id at all is the lecturer's list
            // of Offerings, not a section of one.
            default => null,
        };
    }

    /**
     * The Course Offering a lecturer page is about, or null when the page is not
     * inside one.
     *
     * Resolved from the OFFERING already resolved by the controller when there is
     * one, and otherwise from the route's `id` parameter. Every lecturer page in
     * this namespace carries the Offering id in the path, which is what makes a
     * single uniform rule possible.
     */
    public function offeringFor(Request $request, array $viewData): ?CourseOffering
    {
        if (($viewData['offering'] ?? null) instanceof CourseOffering) {
            return $viewData['offering'];
        }

        $offeringId = $viewData['courseOffering']->id
            ?? $viewData['assignment']->courseOffering->id
            ?? $viewData['submission']->assignment->courseOffering->id
            ?? $request->route('id');

        if (! is_numeric($offeringId)) {
            return null;
        }

        return CourseOffering::query()->find((int) $offeringId);
    }
}
