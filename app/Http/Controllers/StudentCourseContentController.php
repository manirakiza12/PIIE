<?php

namespace App\Http\Controllers;

use App\Models\CourseOfferingLessonResource;
use App\Models\CourseOfferingModule;
use App\Support\CourseContent\CourseContentAccess;
use App\Support\CourseContent\CourseContentService;
use App\Support\CourseContent\ModuleCompletion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The student's side of Course Content: the first complete reader.
 *
 * Every action resolves the Offering, then the lesson, and BOTH must pass -
 * confirmed registration for that exact Offering, and a lesson that is actually
 * released. A draft is a 404 here, not a hidden link: an id that resolves to
 * unpublished content must be indistinguishable from an id that does not exist,
 * or the reader becomes an oracle for what a lecturer has not published yet.
 *
 * Opening a lesson records engagement, never completion. Completion is a
 * separate POST, it satisfies the lesson's completion rule, and it is idempotent.
 */
class StudentCourseContentController extends Controller
{
    public function __construct(
        private CourseContentAccess $access,
        private CourseContentService $content,
        private ModuleCompletion $moduleCompletion,
    ) {}

    /**
     * GET /student/courses/{id}/content
     *
     * My Courses -> Business Mathematics. Immediately answers where the student
     * is: which module, which lesson, what is done, what is next.
     */
    public function index(Request $request, int $id): View
    {
        $student = $request->user();
        $offering = $this->access->resolveOffering($student, $id);

        if (! $this->access->canStudentAccess($student, $offering)) {
            abort(404);
        }

        $tree = $this->content->studentContent($student, $offering);
        $courseUnitLabel = $this->courseUnitLabel($offering);

        // Per-module completion, computed on read and never stored. This is what
        // tells the page the difference between "every lesson done" and "module
        // done" - the distinction the old single figure could not express.
        $moduleCompletion = $this->moduleCompletion->forCourseOffering($offering, $student);

        // A module is shown if it has visible LESSONS or a visible ASSESSMENT.
        //
        // `studentContent()` filters out modules with no lessons, on the sound
        // ground that an empty section heading looks broken. But a module whose
        // only content is a required assessment is precisely where a student must
        // be sent - it is all that is left for them to do. Hiding it would make a
        // required assessment unreachable from the course page, which is the gap
        // this correction exists to close.
        //
        // The lesson-only filter is left in the service for its other callers, so
        // Course Content does not come to depend on the Assignment domain.
        $visibleModules = $tree['modules']->filter(
            fn (CourseOfferingModule $module): bool => $module->lessons->isNotEmpty()
                || ($moduleCompletion[$module->id]['assessments_total'] ?? 0) > 0
        )->values();

        // Course-level facts, kept as SEPARATE dimensions. There is deliberately
        // no single combined number: PIIE has no governed weighting model, and
        // inventing one would present arithmetic as progress.
        //
        // Counted over the VISIBLE modules only. An archived or empty module
        // would otherwise be reported as "complete" - it has no requirements
        // outstanding, because it has no requirements at all - and inflate the
        // completed count against a list the student cannot even see.
        $requiredAssessments = 0;
        $satisfiedAssessments = 0;
        $outstandingAssessments = 0;
        $modulesComplete = 0;

        foreach ($visibleModules as $module) {
            $row = $moduleCompletion[$module->id] ?? null;

            if ($row === null) {
                continue;
            }

            $requiredAssessments += $row['assessments_gating'];
            $satisfiedAssessments += $row['assessments_satisfied'];
            $outstandingAssessments += $row['assessments_outstanding'];
            $modulesComplete += $row['is_complete'] ? 1 : 0;
        }

        $modulesTotal = $visibleModules->count();

        return view('student.course_content.index', [
            'moduleCompletion' => $moduleCompletion,
            'modulesTotal' => $modulesTotal,
            'modulesComplete' => $modulesComplete,
            'requiredAssessments' => $requiredAssessments,
            'satisfiedAssessments' => $satisfiedAssessments,
            'outstandingAssessments' => $outstandingAssessments,
            // True only when nothing required is outstanding. The page must not
            // claim the course is finished otherwise.
            'hasOutstandingRequirements' => $outstandingAssessments > 0
                || $tree['completed'] < $tree['total'],
            'offering' => $offering,
            'courseUnitLabel' => $courseUnitLabel,
            'unitName' => optional($offering->subject)->name ?: $offering->reference,
            // The VISIBLE set: modules with something a student can actually
            // engage with. The lesson figures below are computed from the same
            // tree, so they cannot disagree with the list.
            'modules' => $visibleModules,
            'completedIds' => $tree['completedIds'],
            // Per-lesson "In progress", which is display state only and is NOT
            // part of the progress figure.
            'inProgressIds' => $tree['inProgressIds'],
            'completed' => $tree['completed'],
            'total' => $tree['total'],
            'percent' => $tree['percent'],
        ]);
    }

    /**
     * GET /student/courses/{id}/content/lessons/{lesson}
     *
     * The reader. Records engagement, then renders.
     */
    public function show(Request $request, int $id, int $lesson): View
    {
        $student = $request->user();
        $offering = $this->access->resolveOffering($student, $id);
        $row = $this->access->resolveLessonForStudent($student, $lesson);

        // Engagement only. Completion is a deliberate act, and the model has no
        // path that completes a lesson on a read.
        $this->content->recordEngagement($student, $row);

        $progress = $this->content->studentContent($student, $offering);
        $navigation = $this->content->navigationFor($student, $row);

        $completed = false;
        $lastViewed = null;
        foreach ($progress['modules'] as $module) {
            foreach ($module->lessons as $item) {
                if ((int) $item->id === (int) $row->id) {
                    $completed = in_array((int) $item->id, $progress['completedIds'], true);
                }
            }
        }

        $ownProgress = \App\Models\CourseOfferingLessonProgress::query()
            ->where('course_offering_lesson_id', $row->id)
            ->where('student_id', $student->id)
            ->first();
        $lastViewed = $ownProgress?->last_viewed_at;

        return view('student.course_content.lesson', [
            'offering' => $offering,
            'courseUnitLabel' => $this->courseUnitLabel($offering),
            'unitName' => optional($offering->subject)->name ?: $offering->reference,
            'lesson' => $row,
            'module' => $row->module,
            'resources' => $row->resources,
            'position' => $navigation['position'],
            'total' => $navigation['total'],
            'previous' => $navigation['previous'],
            'next' => $navigation['next'],
            'completed' => $completed,
            'lastViewed' => $lastViewed,
            'completedCount' => $progress['completed'],
            'lessonTotal' => $progress['total'],
            'percent' => $progress['percent'],
            'canComplete' => $row->supportsStudentCompletion(),
        ]);
    }

    /**
     * POST mark complete. Separate from reading, and idempotent.
     */
    public function complete(Request $request, int $id, int $lesson): RedirectResponse
    {
        $student = $request->user();
        $offering = $this->access->resolveOffering($student, $id);
        $row = $this->access->resolveLessonForStudent($student, $lesson);

        try {
            $this->content->markComplete($student, $row);
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Lesson marked complete.');
    }

    /**
     * GET an attachment. Authorised exactly as the reader is: registered, and
     * the lesson released. A student never sees an attachment of a draft lesson.
     */
    public function resource(Request $request, int $resource)
    {
        $row = CourseOfferingLessonResource::query()
            ->where('school_id', (int) $request->user()->school_id)
            ->whereKey($resource)
            ->firstOrFail();

        $lesson = $this->access->resolveLessonForStudent($request->user(), (int) $row->course_offering_lesson_id);

        if ($row->isFile()) {
            if (! $row->stored_name || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($row->stored_name)) {
                abort(404);
            }

            return \Illuminate\Support\Facades\Storage::disk('local')->download(
                $row->stored_name,
                $row->original_name ?: $row->title
            );
        }

        if (! $row->hasUsableLink()) {
            abort(404);
        }

        return redirect()->away($row->link_url);
    }

    private function courseUnitLabel(\App\Models\CourseOffering $offering): string
    {
        $terminology = app(\App\Support\TenantConfiguration::class)->terminology(
            \App\Models\School::query()->find($offering->school_id)
        );

        return $terminology['course_unit'] ?? 'Course Unit';
    }
}
