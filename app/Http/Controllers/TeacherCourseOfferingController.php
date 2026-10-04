<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Models\LiveClass;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use App\Support\LiveClasses\LiveClassAccessService;
use App\Support\TenantConfiguration;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Lecturer academic workspace: My Course Offerings and the teaching
 * workspace for one allocated Course Offering.
 *
 * Authorization is entirely server-side and entirely allocation-based
 * (LecturerCourseOfferingAccess): every offering-specific request re-resolves the
 * authenticated lecturer's own allocation, so changing a URL id exposes another
 * lecturer's or another institution's Offering to nobody. A Lecturer gains no
 * administrative capability here - registration, confirmation, academic
 * placement and lifecycle remain with the academic office.
 */
class TeacherCourseOfferingController extends Controller
{
    public function __construct(
        private readonly LecturerCourseOfferingAccess $access,
        private readonly LiveClassAccessService $liveClassAccess,
    ) {
    }

    public function index(Request $request): View
    {
        $lecturer = $request->user();
        $data = $request->validate([
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('school_id', $lecturer->school_id)],
            'academic_period_id' => ['nullable', 'integer', Rule::exists('academic_periods', 'id')->where('school_id', $lecturer->school_id)],
            'status' => ['nullable', Rule::in(CourseOffering::STATUSES)],
        ]);

        $offerings = $this->access->offerings($lecturer, $data);
        $filters = $this->access->filterOptions($lecturer);

        return view('teacher.course_offerings.index', [
            'offerings' => $offerings,
            'filters' => $filters,
            'selectedYearId' => $data['academic_year_id'] ?? null,
            'selectedPeriodId' => $data['academic_period_id'] ?? null,
            'selectedStatus' => $data['status'] ?? null,
            'terms' => app(TenantConfiguration::class)->terminology($lecturer->school),
            'current' => $this->currentCount($offerings),
        ]);
    }

    public function show(Request $request, int $id): View
    {
        [$offering, $terms, $canTeach] = $this->resolve($request, $id);

        $liveClasses = $this->liveClasses($request, $offering, $canTeach);

        return view('teacher.course_offerings.show', [
            'offering' => $offering,
            'terms' => $terms,
            'canTeach' => $canTeach,
            'roster' => $this->access->rosterAvailable($offering) ? $this->access->teachingRoster($offering) : collect(),
            'liveClasses' => $liveClasses,
            // Derived from the same collection, so the counter and the list are
            // one fact rather than two that can drift.
            'liveClassCounts' => $this->liveClassCounts($liveClasses),
            'canCreateLiveClass' => $this->liveClassAccess->canLecturerCreateForOffering($request->user(), $offering),
            'modules' => $this->modules($request, $offering, $canTeach, $terms),
            // The full destination strip: Overview | Content | Live Classes |
            // Assignments | Quizzes & Exams | Students | Gradebook | Analytics.
            // Tabs with no engine yet are declared, not faked, and carry no href.
            'workspaceTabs' => $this->workspaceTabs($request, $offering, $canTeach),
        ]);
    }

    /**
     * POST: give this Course Offering a cover image.
     *
     * The SERVICE enforces everything - the allocation, the extension allowlist, the
     * 4 MB limit, the private storage location and the disposal of the superseded
     * bytes - and it re-checks the allocation itself rather than trusting the page
     * that rendered this form. The form is a door; the service is the lock.
     *
     * So this method does three things and no more: resolve the Offering through
     * the same access helper the rest of the surface uses, hand the file over, and
     * report what happened. It holds no rule of its own, because a second rule is
     * a second thing to be wrong.
     */
    public function setCoverImage(Request $request, int $id)
    {
        [$offering] = $this->resolve($request, $id);

        $validated = $request->validate([
            'cover_image' => ['required', 'file', 'max:4096'],
        ], [], [
            'cover_image' => 'course image',
        ]);

        app(\App\Support\CourseOffering\CourseCoverImage::class)->set(
            $request->user(),
            $offering,
            $validated['cover_image']
        );

        return back()->with('success', 'The course image has been updated.');
    }

    /**
     * POST: remove the cover image.
     *
     * "No cover" is a legitimate end state - the relationship is optional, so it
     * must be as reachable as "has one" - and removing the image also disposes of
     * the bytes, rather than leaving a file readable that the institution has
     * withdrawn.
     */
    public function clearCoverImage(Request $request, int $id)
    {
        [$offering] = $this->resolve($request, $id);

        app(\App\Support\CourseOffering\CourseCoverImage::class)->clear($request->user(), $offering);

        return back()->with('success', 'The course image has been removed.');
    }

    public function students(Request $request, int $id): View
    {
        [$offering, $terms, $canTeach] = $this->resolve($request, $id);
        if (! $this->access->rosterAvailable($offering)) {
            return view('teacher.course_offerings.students', [
                'offering' => $offering,
                'terms' => $terms,
                'canTeach' => false,
                'roster' => collect(),
                'withheld' => 'The student roster for this Course Offering becomes available once teaching begins, and remains readable after the Course Offering is completed.',
            ]);
        }

        return view('teacher.course_offerings.students', [
            'offering' => $offering,
            'terms' => $terms,
            'canTeach' => $canTeach,
            'roster' => $this->access->teachingRoster($offering),
            'withheld' => null,
        ]);
    }

    /**
     * Allocation + tenant gate. An unallocated, foreign-tenant or cancelled
     * allocation resolves to the same 404 as a missing Offering.
     *
     * @return array{0: CourseOffering, 1: array, 2: bool}
     */
    private function resolve(Request $request, int $id): array
    {
        $offering = $this->access->resolveForLecturer($request->user(), $id);
        abort_if($offering === null, 404);

        return [
            $offering,
            app(TenantConfiguration::class)->terminology($request->user()->school),
            $this->access->teachingActionsAllowed($offering),
        ];
    }
    /**
     * The lecturer's Course Offering workspace navigation.
     *
     * DELEGATES TO WorkspaceNav, which is the single definition of the tab list.
     * The same service supplies the navigation to every other page in this
     * namespace through a view composer, so the two can never disagree.
     *
     * @return list<array{key:string,label:string,url:?string,available:bool,coming_soon:bool,description:string,active:bool}>
     */
    /**
     * Serve a Course Offering cover image.
     *
     * The only way to read the bytes: they live outside the web root under a
     * generated name. Authority is re-evaluated on every request - an allocated
     * lecturer, a CONFIRMED student on this exact Offering, or the academic office
     * - and everyone else receives a 404, so the route cannot be used to discover
     * that another Offering has a cover.
     *
     * A Course Offering with no cover is a normal state, not an error: the
     * relationship is optional, so this returns 404 rather than a placeholder,
     * and no caller should treat a missing cover as a problem to fix.
     */
    public function coverImage(Request $request, int $id)
    {
        // CourseContentAccess::resolveOffering() is the ONE shared rule for
        // resolving a Course Offering for an actor - the same one Course Content and
        // AssignmentAccess both delegate to. Resolving it any other way would be a
        // second rule, and a second rule eventually disagrees.
        $offering = app(\App\Support\CourseContent\CourseContentAccess::class)
            ->resolveOffering($request->user(), $id);

        app(\App\Support\CourseOffering\CourseCoverImage::class)
            ->assertMayView($request->user(), $offering);

        if (! $offering->cover_image_path) {
            abort(404);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $disk->exists($offering->cover_image_path)) {
            abort(404);
        }

        return $disk->download(
            $offering->cover_image_path,
            $offering->cover_image_name ?: 'course-cover'
        );
    }
    private function workspaceTabs(Request $request, CourseOffering $offering, bool $canTeach): array
    {
        return app(\App\Support\CourseOffering\WorkspaceNav::class)->tabs(
            $request,
            $offering,
            $canTeach,
            app(\App\Support\CourseOffering\WorkspaceNav::class)->currentKeyFor($request),
        );
    }

    /**
     * Only modules that genuinely exist are offered. Attendance and Assignments
     * are not Course Offering scoped in the current schema, so they are not
     * presented as Offering actions rather than being faked.
     */
    private function modules(Request $request, CourseOffering $offering, bool $canTeach, array $terms): array
    {
        $lecturer = $request->user();

        return [
            [
                'key' => 'content',
                'label' => 'Content',
                'description' => 'Build and organise the learning journey students will follow in this Course Unit.',
                'available' => $canTeach,
                'url' => route('teacher.course_offerings.content.index', $offering->id),
            ],
            [
                'key' => 'students',
                'label' => 'Students',
                'description' => $this->access->rosterAvailable($offering)
                    ? 'Confirmed students registered for this Course Offering.'
                    : 'The confirmed student roster becomes available when teaching begins.',
                'available' => $this->access->rosterAvailable($offering),
                'url' => route('teacher.course_offerings.students', $offering->id),
            ],
            [
                'key' => 'attendance',
                'label' => 'Attendance',
                'description' => 'Teaching sessions held for this Course Offering and each student register.',
                'available' => $canTeach || $offering->status === CourseOffering::STATUS_COMPLETED,
                'url' => route('teacher.course_offerings.attendance.index', $offering->id),
            ],
            [
                'key' => 'live_classes',
                'label' => 'Live Classes',
                'description' => 'Your Live Classes for this Course Offering are listed below. Scheduling is managed in Live Classes.',
                'available' => $canTeach,
                'url' => route('teacher.live_classes.index'),
            ],
            [
                'key' => 'online_exams',
                'label' => 'Online Exams',
                'description' => 'Create, mark and monitor Online Exams. Exams remain scoped to the Course Unit and are not yet attached to a Course Offering.',
                'available' => $canTeach,
                'url' => route('teacher.online_exams.index'),
            ],
        ];
    }

    /**
     * Live Classes for this Offering, reusing the existing lecturer visibility
     * query (allocation-derived, tenant scoped, capability gated) rather than a
     * second authorization path. Completed Offerings keep their record; a Draft
     * or Cancelled Offering shows none.
     */
    private function liveClasses(Request $request, CourseOffering $offering, bool $canTeach): \Illuminate\Support\Collection
    {
        if (! $canTeach && $offering->status !== CourseOffering::STATUS_COMPLETED) {
            return collect();
        }

        // ->select('live_classes.*') is required, not cosmetic: the access
        // query selects only `id` so that it stays a legal single-column IN
        // operand, and this list renders the full model.
        return $this->liveClassAccess->lecturerVisibleClassIdsQuery($request->user(), (int) $offering->school_id)
            ->select('live_classes.*')
            ->where('course_offering_id', $offering->id)
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * The Offering's Live Class total, plus a breakdown a reader can check.
     *
     * Derived from the SAME Offering-scoped collection the page lists, so the
     * number and the list can never disagree - which is the whole point. A bare
     * total is unfalsifiable: a dashboard reading "0" beside five existing
     * classes is indistinguishable from a genuinely empty Offering, and that is
     * exactly the confusion being fixed.
     *
     * Counted from the DERIVED state, so a class whose scheduled time has passed
     * without being closed is reported as unconfirmed rather than swept into
     * "Completed". Presenting an untaught class as completed is the one thing
     * this module must not do.
     *
     * @return array{total: int, parts: array<string, int>}
     */
    private function liveClassCounts(\Illuminate\Support\Collection $liveClasses): array
    {
        $resolver = app(\App\Support\LiveClasses\LiveClassLifecycle::class);
        $labels = [
            'draft' => get_phrase('Draft'),
            'upcoming' => get_phrase('Upcoming'),
            'ready' => get_phrase('Ready to start'),
            'live' => get_phrase('Live Now'),
            'completed' => get_phrase('Completed'),
            'not_concluded' => get_phrase('Ended, not confirmed'),
            'cancelled' => get_phrase('Cancelled'),
        ];

        $parts = array_fill_keys(array_values($labels), 0);
        foreach ($liveClasses as $liveClass) {
            $state = $resolver->state(
                $liveClass,
                $liveClass->scheduled_at?->copy(),
                $liveClass->scheduled_at?->copy()->subMinutes(\App\Support\LiveClasses\LiveClassLifecycle::JOIN_LEAD_MINUTES),
                $liveClass->ends_at?->copy(),
                now()
            );
            $label = $labels[$state] ?? get_phrase('Scheduled');
            $parts[$label] = ($parts[$label] ?? 0) + 1;
        }

        return ['total' => $liveClasses->count(), 'parts' => $parts];
    }

    private function currentCount($offerings): int
    {
        return $offerings->filter(fn (CourseOffering $offering) => $offering->getAttribute('my_allocation_is_current'))->count();
    }
}
