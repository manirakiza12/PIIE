<?php

namespace App\Support\CourseExperience;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingLecturerAllocation;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use App\Models\CourseOfferingModule;
use App\Models\LiveClass;
use App\Models\User;
use App\Support\Assignments\AssignmentLifecycle;
use App\Support\Assignments\AssignmentState;
use App\Support\Assignments\SubmissionService;
use App\Support\CourseContent\ModuleActivityKind;
use App\Support\CourseContent\ModuleCompletion;
use App\Support\LiveClasses\LiveClassAccessService;
use App\Support\LiveClasses\LiveClassDisplay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * THE STUDENT'S COURSE HOME - assembled, not stored.
 *
 * â”€â”€ WHY A READ SERVICE AND NOT A PAGE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 *
 * A Course Home needs to say seven things at once: who teaches this, how far the
 * student has got, what to do next, what is outstanding, what is live, what can be
 * downloaded, and which of the six navigation areas actually exist. Written as a
 * controller, that is several hundred lines of queries and vocabulary, and the
 * next surface to want the same answers - a mobile summary, an email, a
 * notification - would copy them.
 *
 * So the answers are assembled here, from services that already exist, and both
 * the page and any future surface ask this class.
 *
 * â”€â”€ NOTHING IS DUPLICATED â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 *
 * There is no table of "home widgets" and no cache of "the next thing". Every
 * fact below is read from the rows that already record it, through the services
 * that already know the rules:
 *
 *   progress          ModuleCompletion        the completion rule, in one place
 *   assignment state  SubmissionService + AssignmentState
 *   Live Classes      LiveClassAccessService  the join policy, in one place
 *   content           CourseContentService    what is published and released
 *
 * A second implementation of any of those rules is the exact thing this project
 * has been built to avoid, so there isn't one.
 *
 * â”€â”€ AUTHORISATION IS THE CALLER'S JOB, AND IT IS NOT NEGOTIABLE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 *
 * This class reads. It decides nothing about who may see this Offering. The
 * controller resolves the Offering through `AssignmentAccess`, which is the single
 * authority on a confirmed registration, and only then calls `build()`. A read
 * service that also checked would create a second gate that could disagree -
 * and a gate that sometimes opens is worse than no gate.
 *
 * â”€â”€ WHAT "ONLY EXPOSE WHAT EXISTS" MEANS HERE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
 *
 * The six navigation areas are reported with an explicit availability and, where
 * something is missing, the reason. An area that is not built is shown as
 * "not available yet" and links NOWHERE - not to a page that would 404, and
 * never to a page with invented content on it. A student who clicks through to
 * fabricated data has been told something untrue about their own course, and
 * there is no framing that makes that acceptable.
 */
class StudentCourseHome
{
    public function __construct(
        private readonly ModuleCompletion $moduleCompletion,
        private readonly SubmissionService $submissions,
        private readonly LiveClassAccessService $liveClassAccess,
        private readonly LiveClassDisplay $liveClassDisplay,
        // Quizzes & Exams, read through the existing online exam engine. Added last
        // and without disturbing the four above: this is a new area on the page, not
        // a change to how any existing one is built.
        private readonly \App\Support\CourseExams\CourseOfferingAssessments $assessments,
    ) {}

    /**
     * Everything the Course Home shows, in one call.
     *
     * @return array<string, mixed>
     */
    public function build(User $student, CourseOffering $offering, ?Carbon $at = null): array
    {
        $at ??= now();

        // ModuleCompletion evaluates the completion rule and already resolves the
        // assessments; the module and lesson LISTS are read here rather than taken
        // from CourseContentService, because this page needs a module that has no
        // lessons but does hold a required assessment - the content page filters
        // those out for its own reasons, and a Course Home that hid them would be
        // hiding the one thing left for a student to do.
        $completion = $this->moduleCompletion->forCourseOffering($offering, $student, $at);

        // One pass over the visible modules, so the header's progress figure and
        // the Continue Learning card are derived from the same rows in the same
        // order and cannot drift apart.
        $modules = $this->visibleModules($offering, $at, $completion);

        // Quizzes & Exams, read once. A course with none is an ordinary state on the
        // first week of term, and the section says so rather than pretending the tab
        // does not exist.
        $assessments = $this->assessments->forStudent($student, $offering, $at);

        return [
            'header' => $this->header($offering, $student, $at),
            'progress' => $this->progress($modules, $completion),
            'continueLearning' => $this->continueLearning($student, $offering, $modules, $completion, $at),
            'assignments' => $this->assignments($student, $offering, $at),
            'liveClasses' => $this->liveClasses($student, $offering, $at),
            // ONE read, shared by the tab's count and the four cards. Computing it
            // twice would let the count and the list disagree, and the count is the
            // number a student reads first.
            'assessments' => $assessments,
            'resources' => $this->resources($student, $offering, $at),
            'sections' => $this->sections($student, $offering, $modules, count($assessments)),
        ];
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // THE HEADER
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    /**
     * Cover, title, code, year, period, teachers, progress.
     *
     * @return array<string, mixed>
     */
    private function header(CourseOffering $offering, User $student, Carbon $at): array
    {
        $subject = $offering->relationLoaded('subject') ? $offering->subject : $offering->subject()->first();

        // THE COVER IS OPTIONAL AND ITS ABSENCE IS NOT A DEFECT.
        //
        // CourseCoverImage documents the relationship as optional, so a course with
        // no cover is a normal state for every course, forever, and must never be
        // treated as something to fix. When there is no image the caller draws a
        // typographic fallback from the code below - a real, honest placeholder
        // that says which course it is, rather than a stock photograph implying
        // this is a photography course when it is Business Mathematics.
        $coverPath = filled($offering->cover_image_path) ? (string) $offering->cover_image_path : null;

        return [
            'cover_path' => $coverPath,
            'cover_name' => $coverPath ? ($offering->cover_image_name ?: 'course-cover') : null,
            'title' => $subject?->name ?: (string) $offering->reference,
            'code' => (string) $offering->reference,
            // `label`, NOT `name`.
            //
            // `academic_years` and `academic_periods` carry a `label` column. There
            // is no `name` and no accessor producing one, so `->name` was always
            // null and the header showed a dash on EVERY course, everywhere. The
            // fixture hid it by building its own rows - a test asserting on an
            // attribute the production table does not have is testing the fixture.
            'academic_year' => $offering->academicYear?->label,
            'period' => $offering->academicPeriod?->label,
            'lecturers' => $this->lecturers($offering, $at),
            'status' => $offering->status,
            'status_label' => $this->offeringStatusLabel($offering),
        ];
    }

    /**
     * Who is ALLOCATED to teach this Offering - the teaching RECORD.
     *
     * ── WHY THIS IS NOT "WHO IS TEACHING RIGHT NOW" ─────────────────────────
     *
     * Those are two questions, and the previous version of this method asked only
     * the second and then used its answer as a NAME LIST. A lecturer whose
     * allocation begins tomorrow was therefore not printed at all, and the page
     * said "No lecturer is currently allocated to this course" - a false statement
     * about a colleague who is allocated.
     *
     * The domain already draws the line, in the other direction, in
     * `reachableOfferings()`: "Cancelled allocations are excluded outright; ENDED
     * ones are included so a COMPLETED Course Offering still shows its TEACHING
     * RECORD." A header is exactly such a record.
     *
     * So the list is the allocations, and each row says which it is - teaching now,
     * starting on a date, or finished - using the EXISTING currency test. The
     * course opens on 1 October and today is 30 September, so "Daniel Okello,
     * starts 1 Oct" is the truthful answer today; omitting him is the misleading
     * one. A lecturer who is genuinely absent still gets the honest absence.
     *
     * ── WHY `status` STILL FILTERS ──────────────────────────────────────────
     *
     * Cancelled is excluded, because nobody is allocated by a cancelled
     * allocation. PLANNED is included: a planned allocation is a real decision the
     * academic office has made, and hiding it would make a course look
     * unstaffed for a term that is already timetabled. `status` on the row is
     * factual, and a name is shown with its own state rather than suppressed.
     *
     * ── THE TENANT CHECK ON THE PERSON ──────────────────────────────────────
     *
     * The `lecturer` relation is a plain `belongsTo(User, 'user_id')` with no
     * tenant constraint, so a corrupt cross-tenant `user_id` would otherwise put
     * another institution's colleague on this header. The user is therefore
     * accepted only when they belong to the ALLOCATION's own school - the same
     * rule `LecturerCourseOfferingAccess::lecturerForAllocation()` applies.
     *
     * @return list<array{name: string, role: string, role_label: string, is_current: bool, note: ?string, starts_on: ?string, ends_on: ?string}>
     */
    private function lecturers(CourseOffering $offering, Carbon $at): array
    {
        $allocations = $offering->relationLoaded('lecturerAllocations')
            ? $offering->lecturerAllocations
            : $offering->lecturerAllocations()->get();

        // The EXISTING currency rule, reached and not copied - see
        // `isAllocationCurrent()` on the access service. It is the same method
        // `teachingActionsAllowed()` gates on, so the label on a name and the
        // authority behind it cannot disagree.
        $currencyTest = function ($allocation) use ($at): bool {
            return app(LecturerCourseOfferingAccess::class)
                ->isAllocationCurrent($allocation, $at->toDateString());
        };

        $out = [];

        foreach ($allocations as $allocation) {
            if ($allocation->status === CourseOfferingLecturerAllocation::STATUS_CANCELLED) {
                continue;
            }

            $lecturer = $allocation->relationLoaded('lecturer')
                ? $allocation->lecturer
                : $allocation->lecturer()->first();

            $name = trim((string) ($lecturer->name ?? ''));

            // In the allocation's OWN tenant, never just any user with that id.
            if ($name === ''
                || (int) $lecturer->school_id !== (int) $allocation->school_id) {
                continue;
            }

            $isCurrent = $currencyTest($allocation);

            $startsOn = $allocation->starts_on ? Carbon::parse($allocation->starts_on) : null;
            $endsOn = $allocation->ends_on ? Carbon::parse($allocation->ends_on) : null;

            $note = null;

            if (! $isCurrent) {
                if ($endsOn && $endsOn->lt($at)) {
                    $note = 'Taught until '.$endsOn->format('j M Y');
                } elseif ($startsOn) {
                    $note = 'Starts '.$startsOn->format('j M Y');
                } else {
                    $note = $allocation->status === CourseOfferingLecturerAllocation::STATUS_PLANNED
                        ? 'Planned'
                        : 'Not currently teaching';
                }
            }

            $out[] = [
                'name' => $name,
                'role' => (string) $allocation->role,
                'role_label' => ucwords(str_replace('_', ' ', (string) $allocation->role)),
                'is_current' => $isCurrent,
                'note' => $note,
                'starts_on' => $allocation->starts_on,
                'ends_on' => $allocation->ends_on,
            ];
        }

        // The primary lecturer leads, then whoever is teaching NOW, then by name -
        // so a course with a part-time co-lecturer reads the way it is timetabled
        // rather than by row order.
        $order = array_flip(CourseOfferingLecturerAllocation::ROLES);

        usort($out, static function (array $a, array $b) use ($order): int {
            return [($order[$a['role']] ?? 99), ($a['is_current'] ? 0 : 1), $a['name']]
                <=> [($order[$b['role']] ?? 99), ($b['is_current'] ? 0 : 1), $b['name']];
        });

        return $out;
    }
    private function offeringStatusLabel(CourseOffering $offering): string
    {
        return match ($offering->status) {
            CourseOffering::STATUS_OPEN => 'Open',
            CourseOffering::STATUS_IN_PROGRESS => 'In progress',
            CourseOffering::STATUS_COMPLETED => 'Completed',
            CourseOffering::STATUS_CANCELLED => 'Cancelled',
            default => 'Not yet open',
        };
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // OVERALL PROGRESS
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    /**
     * How far through the course this student is, AS DIMENSIONS.
     *
     * Each figure counts one kind of thing, and none of them is folded into
     * another. The reasoning is long and it matters, because the obvious
     * implementation - one percentage over lessons plus assessments - is the one
     * thing Course Content explicitly refuses to do:
     *
     *   "Folding '2 lessons + 1 assessment' into a single percentage requires a
     *    weighting - what share of a module is a lesson worth, what share an
     *    assessment - and PIIE has no governed model for that. Any number produced
     *    without one is invented arithmetic wearing the clothes of a grade."
     *
     * A blended figure was tried here first and removed. It is worse than
     * useless rather than merely approximate: a student two thirds of the way
     * through the reading and a student who has handed in every assessment both
     * read 67%, and that number is the one a dashboard would be judged on. A
     * governed weighting belongs with the Gradebook, where that pass belongs.
     *
     * Every count here is of the same population the content page counts:
     * published, released lessons in published, released modules, and required,
     * visible, evaluable assessments. A draft module and an unreleased lesson
     * contribute to neither numerator nor denominator.
     *
     * @param  list<CourseOfferingModule>  $modules
     * @param  array<int, array<string, mixed>>  $completion
     * @return array<string, mixed>
     */
    private function progress(array $modules, array $completion): array
    {
        $lessonsTotal = 0;
        $lessonsDone = 0;
        $assessmentsGating = 0;
        $assessmentsDone = 0;
        $modulesComplete = 0;

        foreach ($modules as $module) {
            $row = $completion[(int) $module->id] ?? null;

            if (! is_array($row)) {
                continue;
            }

            $lessonsTotal += (int) ($row['lessons_total'] ?? 0);
            $lessonsDone += (int) ($row['lessons_completed'] ?? 0);

            // `assessments_gating`, not `assessments_required`: a required
            // assessment carrying an unimplemented completion rule is reported
            // elsewhere as unsatisfiable, and counting it as something the student
            // can complete would put a figure on screen that can never be reached.
            $assessmentsGating += (int) ($row['assessments_gating'] ?? 0);
            $assessmentsDone += (int) ($row['assessments_satisfied'] ?? 0);

            if (($row['is_complete'] ?? false) === true) {
                $modulesComplete++;
            }
        }

        return [
            'lessons_total' => $lessonsTotal,
            'lessons_completed' => $lessonsDone,
            'lessons_percent' => self::percentage($lessonsDone, $lessonsTotal),

            'assessments_total' => $assessmentsGating,
            'assessments_completed' => $assessmentsDone,
            'assessments_percent' => self::percentage($assessmentsDone, $assessmentsGating),

            'modules_total' => count($modules),
            'modules_complete' => $modulesComplete,
            'modules_percent' => self::percentage($modulesComplete, count($modules)),
        ];
    }

    /**
     * A percentage of things THIS KIND, or 100 when there were none to do.
     *
     * A course with no required assessment reports 100% of its assessments
     * complete, not 0% and not a division by zero. There was nothing to do, so
     * nothing is outstanding - and a student seeing 0% for a dimension their course
     * does not have would be told they are behind in something that does not exist.
     *
     * Clamped, because a bar that overflows its track is a visible lie and this
     * figure is read at a glance.
     */
    private static function percentage(int $done, int $total): int
    {
        if ($total <= 0) {
            return 100;
        }

        return max(0, min(100, (int) round(($done / $total) * 100)));
    }
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // CONTINUE LEARNING
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    /**
     * The next thing this student can LEGITIMATELY do.
     *
     * â”€â”€ WHY NOT "THE NEXT DATABASE ROW" â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     *
     * The next row is a scheduling accident. It may be a lesson already finished,
     * an assessment whose mark has not come back and cannot be hurried, an
     * unpublished module, a draft the lecturer has not released, or work whose
     * deadline was six weeks ago. Sending a student to any of those is worse than
     * sending them nowhere, because the page then says "your next activity is
     * here" and the activity is not something they can do.
     *
     * â”€â”€ WHAT COUNTS AS LEGITIMATE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     *
     *   - it is PUBLISHED and RELEASED to students
     *   - it is not already done
     *   - the student can act on it right now
     *   - it has somewhere real to go
     *
     * â”€â”€ WHY OVERDUE WORK IS HOISTED â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     *
     * Overdue actionable work goes first, ahead of the course's reading order.
     * That is a deliberate product judgement and worth stating: reading order
     * optimises for a student keeping pace, and an assignment whose deadline has
     * passed is the one thing that must be dealt with today - everything else can
     * wait. Reading order still decides the order of everything that is NOT
     * overdue, so the sequence a lecturer set out is the sequence a student
     * follows.
     *
     * â”€â”€ WHY "NOTHING TO DO" IS AN ANSWER â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     *
     * When nothing is actionable the card says so, and says WHY. The common real
     * case is an assessment submitted and awaiting a mark: the student has done
     * everything they can, and the honest message is that the work is with the
     * lecturer - not a "keep going" prompt pointing at a lesson they have already
     * read.
     *
     * @param  list<CourseOfferingModule>  $modules
     * @param  array<int, array<string, mixed>>  $completion
     * @return array<string, mixed>
     */
    private function continueLearning(User $student, CourseOffering $offering, array $modules, array $completion, Carbon $at): array
    {
        $candidates = $this->actionableActivities($student, $offering, $modules, $completion, $at);

        if ($candidates !== []) {
            $urgent = array_values(array_filter(
                $candidates,
                static fn (array $row) => ($row['overdue'] ?? false) === true
            ));

            $pool = $urgent !== [] ? $urgent : $candidates;

            return [
                'has_next' => true,
                'next' => $pool[0],
                'also' => array_slice($pool, 1, 3),
                'total_actionable' => count($candidates),
                'waiting_on_lecturer' => false,
            ];
        }

        return [
            'has_next' => false,
            'next' => null,
            'also' => [],
            'total_actionable' => 0,
            'waiting_on_lecturer' => $this->isWaitingOnLecturer($student, $offering, $at),
        ];
    }

    /**
     * Everything this student could act on, in course order.
     *
     * @param  list<CourseOfferingModule>  $modules
     * @param  array<int, array<string, mixed>>  $completion
     * @return list<array<string, mixed>>
     */
    private function actionableActivities(User $student, CourseOffering $offering, array $modules, array $completion, Carbon $at): array
    {
        $rows = [];

        foreach ($modules as $module) {
            $moduleRow = $completion[(int) $module->id] ?? [];

            foreach ($module->lessons as $lesson) {
                if (! $lesson->isReleasedToStudents($at)) {
                    continue;
                }

                $progress = $this->lessonProgressFor($student, $lesson);

                if ($progress && $progress->status === CourseOfferingLessonProgress::STATUS_COMPLETED) {
                    continue;
                }

                $rows[] = [
                    'kind' => ModuleActivityKind::LESSON,
                    'kind_label' => ModuleActivityKind::label(ModuleActivityKind::LESSON),
                    'title' => $lesson->title,
                    'module_title' => $module->title,
                    'module_id' => (int) $module->id,
                    'sequence' => (int) $lesson->sequence,
                    'url' => route('student.courses.content.lessons.show', [$offering->id, $lesson->id]),
                    'in_progress' => (bool) ($progress
                        && $progress->status === CourseOfferingLessonProgress::STATUS_IN_PROGRESS),
                    'overdue' => false,
                    'due' => null,
                ];
            }

            foreach ($this->moduleAssessments($offering, $module, $student, $moduleRow, $at) as $assessment) {
                $rows[] = $assessment;
            }
        }

        // Reading order: module sequence, then the activity's own sequence, then
        // the kind. The last component only matters where a module holds both a
        // lesson and an assessment numbered the same, and it keeps the ordering
        // total so the sort is never at the mercy of the engine's tie-breaking.
        usort($rows, static function (array $a, array $b): int {
            return [$a['module_id'], $a['sequence'], $a['kind']]
                <=> [$b['module_id'], $b['sequence'], $b['kind']];
        });

        return $rows;
    }

    /**
     * The assessments in one module that this student could still act on.
     *
     * Reads its own facts from `SubmissionService` and states them through
     * `AssignmentState`, so a card can never describe an assessment differently
     * from the assignment list does.
     *
     * @param  array<string, mixed>  $moduleRow
     * @return list<array<string, mixed>>
     */
    private function moduleAssessments(CourseOffering $offering, CourseOfferingModule $module, User $student, array $moduleRow, Carbon $at): array
    {
        $out = [];

        $tasks = $module->tasks()->orderBy('id')->get();

        foreach ($tasks as $task) {
            if (! AssignmentLifecycle::isOpenToStudents($task, $at)) {
                continue;
            }

            $latest = $this->submissions->latestSubmission($task, $student);
            $attemptsUsed = $this->submissions->attemptsUsed($task, $student);
            $hasDraft = (bool) $this->submissions->currentDraft($task, $student);

            $state = AssignmentState::for($task, $latest, $attemptsUsed, $hasDraft);

            // "Actionable" is a claim about whether the student can move this
            // forward, and only the shared vocabulary may make it.
            if (! AssignmentState::isActionable($state)) {
                continue;
            }

            $required = (bool) ($moduleRow['assessments_required'] ?? 0)
                && $this->isRequiredForModule($module, $task);

            $due = $task->due_date;

            $out[] = [
                'kind' => ModuleActivityKind::ASSIGNMENT,
                'kind_label' => ModuleActivityKind::label(ModuleActivityKind::ASSIGNMENT),
                'title' => $task->title,
                'module_title' => $module->title,
                'module_id' => (int) $module->id,
                // Assessments sort AFTER the module's lessons at the same number: a
                // student is expected to read before being set the work.
                'sequence' => 1000000 + (int) $task->id,
                'url' => route('student.courses.assignments.show', [$offering->id, $task->id]),
                'state' => $state,
                'state_short' => AssignmentState::shortLabel($state),
                'state_chip' => AssignmentState::chipClass($state),
                'required' => $required,
                'has_draft' => $hasDraft,
                'in_progress' => $hasDraft,
                // Overdue means the deadline has PASSED. Not "late" - that is a
                // property of a submission that exists. This is about time running
                // out on work that has not been handed in.
                'overdue' => (bool) ($due && $due->lt($at) && AssignmentState::isOverdue($state)),
                'due' => $due,
            ];
        }

        return $out;
    }

    private function isRequiredForModule(CourseOfferingModule $module, Assignment $task): bool
    {
        return $module->requiredTasks()->whereKey($task->id)->exists();
    }

    private function lessonProgressFor(User $student, CourseOfferingLesson $lesson): ?CourseOfferingLessonProgress
    {
        return CourseOfferingLessonProgress::query()
            ->where('course_offering_lesson_id', $lesson->id)
            ->where('student_id', $student->id)
            ->first();
    }

    /**
     * Is the student blocked purely because work is with the lecturer?
     *
     * The distinction matters and is easy to get wrong. "You have finished
     * everything you can" and "you have finished everything" are different
     * statements, and only the first is true of a student whose assessment is
     * sitting in a marker's queue. The second would be a claim PIIE cannot support
     * and would leave the student looking for work that does not exist.
     */
    private function isWaitingOnLecturer(User $student, CourseOffering $offering, Carbon $at): bool
    {
        $assignments = $this->studentAssignments($student, $offering, $at);

        foreach ($assignments as $row) {
            $state = $row['state'];

            if ($state === AssignmentState::SUBMITTED || $state === AssignmentState::SUBMITTED_LATE) {
                return true;
            }
        }

        return false;
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // THE ASSIGNMENTS CARD
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    /**
     * This student's assignments, split into what needs doing and what is done.
     *
     * @return array<string, mixed>
     */
    private function assignments(User $student, CourseOffering $offering, Carbon $at): array
    {
        $all = $this->studentAssignments($student, $offering, $at);

        $actionable = array_values(array_filter(
            $all,
            static fn (array $row) => AssignmentState::isActionable($row['state'])
        ));

        // Soonest deadline first, and an undated assignment after a dated one -
        // which is a judgement, and the right one: an assignment with no deadline
        // is genuinely less pressing than one due tomorrow.
        usort($actionable, static function (array $a, array $b): int {
            $adue = $a['assignment']->due_date;
            $bdue = $b['assignment']->due_date;

            if ($adue === null && $bdue === null) {
                return $a['assignment']->id <=> $b['assignment']->id;
            }

            if ($adue === null) {
                return 1;
            }

            if ($bdue === null) {
                return -1;
            }

            return $adue->getTimestamp() <=> $bdue->getTimestamp();
        });

        $done = array_values(array_filter(
            $all,
            static fn (array $row) => ! AssignmentState::isActionable($row['state'])
        ));

        $overdue = array_values(array_filter(
            $actionable,
            static fn (array $row) => AssignmentState::isOverdue($row['state'])
        ));

        return [
            'actionable' => array_slice($actionable, 0, 4),
            'actionable_count' => count($actionable),
            'overdue' => $overdue,
            'overdue_count' => count($overdue),
            'done' => array_slice($done, 0, 3),
            'done_count' => count($done),
            'total' => count($all),
            'marks_released' => array_values(array_filter(
                $done,
                static fn (array $row) => in_array($row['state'], [
                    AssignmentState::RETURNED,
                    AssignmentState::RETURNED_LATE,
                ], true)
            )),
        ];
    }

    /**
     * Every assignment on this Offering that this student may see, with its state.
     *
     * The visibility rule is the assignment list's rule, unchanged: published and
     * released to students. A draft or a not-yet-scheduled assignment is not
     * hidden from this card - it is UNREACHABLE, because `AssignmentAccess` refuses
     * to resolve it either, and a link that would 404 is worse than no link.
     *
     * @return list<array<string, mixed>>
     */
    private function studentAssignments(User $student, CourseOffering $offering, Carbon $at): array
    {
        $assignments = Assignment::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END, due_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Assignment $assignment) => AssignmentLifecycle::isOpenToStudents($assignment, $at))
            ->values();

        return $assignments->map(function (Assignment $assignment) use ($student, $offering) {
            $latest = $this->submissions->latestSubmission($assignment, $student);
            $attemptsUsed = $this->submissions->attemptsUsed($assignment, $student);
            $hasDraft = (bool) $this->submissions->currentDraft($assignment, $student);

            $state = AssignmentState::for($assignment, $latest, $attemptsUsed, $hasDraft);

            return [
                'assignment' => $assignment,
                'state' => $state,
                'state_short' => AssignmentState::shortLabel($state),
                'state_chip' => AssignmentState::chipClass($state),
                'attempts_used' => $attemptsUsed,
                'attempts_allowed' => $assignment->attemptsAllowed(),
                'has_draft' => $hasDraft,
                'due' => $assignment->due_date,
                'closes' => $assignment->closes_at,
                'max_marks' => $assignment->max_marks,
                'marks' => $latest && $latest->isReleased() ? $latest->marks_awarded : null,
                'url' => route('student.courses.assignments.show', [$offering->id, $assignment->id]),
            ];
        })->all();
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // THE LIVE CLASSES CARD
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    /**
     * This Offering's Live Classes, and whether this student may join each.
     *
     * â”€â”€ THE JOIN BUTTON IS A QUESTION TO `LiveClassAccessService`, NOT A CLOCK â”€â”€
     *
     * `canStudentJoin()` already encodes the whole join policy: publication,
     * cancellation, the join window, the platform being enabled, and the platform's
     * own free-tier limit. Re-deriving any of that here to decide whether to draw a
     * button would be a second implementation of a rule that already exists, and it
     * would eventually disagree - most likely on the free-tier limit, which is the
     * kind of rule that is true for the first sixty minutes and false after.
     *
     * So the button appears exactly when that service says yes, and the URL is the
     * EXISTING `student.live_classes.join` route. No meeting URL is built here and
     * no provider is assumed: the existing Jitsi/Meet behaviour is untouched, and
     * the architecture stays ready for an institutional integration because
     * nothing in this feature has to be undone to add one.
     *
     * @return array<string, mixed>
     */
    private function liveClasses(User $student, CourseOffering $offering, Carbon $at): array
    {
        $classes = LiveClass::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->published()
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get()
            // `canStudentViewClass` is the same authority the student's own Live
            // Class pages use, so a class shown here is a class they can open.
            ->filter(fn (LiveClass $class) => $this->liveClassAccess->canStudentViewClass($student, $class))
            ->values();

        // THE INSTANT IS IN THE CAPTURE LIST, and it has to be. Without it the
        // "not upcoming" - so the card would have shown only past classes and
        // raised nothing at all.
        // THE INSTANT IS IN THE CAPTURE LIST, and it has to be. Without it the
        $rows = $classes->map(function (LiveClass $class) use ($student, $at) {
            $display = $this->liveClassDisplay->for($class, $student);

            $joinable = $this->liveClassAccess->canStudentJoin($student, $class);

            return [
                'class' => $class,
                'title' => $class->title,
                'when' => $display->timeRange(),
                'date' => $display->date(),
                'status' => $class->status,
                'status_label' => LiveClass::DISPLAY_STATUS_LABELS[$class->status] ?? 'Scheduled',
                'cancelled' => $class->status === LiveClass::STATUS_CANCELLED,
                'ended' => $class->status === LiveClass::STATUS_ENDED,
                // THE ONE THING THAT DECIDES THE BUTTON.
                'joinable' => $joinable,
                'join_url' => $joinable ? route('student.live_classes.join', $class) : null,
                'show_url' => route('student.live_classes.show', $class),
                'has_recording' => $this->liveClassAccess->canStudentViewRecording($student, $class),
                'has_materials' => $this->liveClassAccess->canStudentViewMaterials($student, $class),

                // THREE STATES, because there are three. The middle one used to be
                // missing, and a class that had started was filed as PAST - so the
                // one moment a student most wants to act on was the one moment the
                // card had nothing to say about.
                'live_now' => self::isRunningNow($class, $at),
                'upcoming' => ! self::isRunningNow($class, $at)
                    && $class->scheduled_at !== null
                    && $class->scheduled_at->gt($at),
            ];
        });

        $live = $rows->filter(fn (array $row) => $row['live_now'])->values();
        $upcoming = $rows->filter(fn (array $row) => $row['upcoming'])->values();
        $past = $rows->filter(fn (array $row) => ! $row['live_now'] && ! $row['upcoming'])->values();

        return [
            // A class that is running RIGHT NOW leads the card, because "is it on?"
            // is the question a student who has just opened the page is asking. The
            // join button is still gated on `canStudentJoin()` and nothing else.
            'next' => $live->first() ?? $upcoming->first(),
            'live_now' => $live,
            'live_now_count' => $live->count(),
            'upcoming' => $upcoming->take(4),
            'upcoming_count' => $upcoming->count(),
            'past' => $past->take(3),
            'past_count' => $past->count(),
            'total' => $rows->count(),
        ];
    }

    /**
     * Is this class running at this instant?
     *
     * Both the STATUS and the clock are consulted, because either alone is wrong.
     * A class can be marked live a moment before its start time, and a class can
     * be left marked live after it was supposed to end; a card that trusted either
     * field on its own would announce a class that is not running, or hide one
     * that is.
     *
     * A CANCELLED class is never running, whatever its other fields say.
     */
    private static function isRunningNow(LiveClass $class, Carbon $at): bool
    {
        if ($class->status === LiveClass::STATUS_CANCELLED) {
            return false;
        }

        if ($class->scheduled_at === null) {
            return false;
        }

        $endsAt = $class->ends_at;

        $started = $class->scheduled_at->lte($at);
        $notFinished = $endsAt === null ? true : $endsAt->gte($at);

        if ($started && $notFinished) {
            return true;
        }

        // The status is authoritative when it disagrees with the clock, EXCEPT
        // that a class whose end time has plainly passed is not "live" however it
        // is labelled - that combination is how a class is left behind after an
        // interrupted session.
        return $class->status === LiveClass::STATUS_LIVE && ($endsAt === null || $endsAt->gte($at));
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    /**
     * Downloadable and linkable material attached to PUBLISHED lessons.
     *
     * "Published course content" means exactly that: a resource on a lesson the
     * student can see. A resource on a draft lesson is not merely excluded from
     * this list - it is unreachable, because the lesson page that would link it
     * does not resolve. Counting unpublished material here would be counting
     * things the student cannot obtain.
     *
     * @return array<string, mixed>
     */
    private function resources(User $student, CourseOffering $offering, Carbon $at): array
    {
        $lessons = $this->visibleLessons($offering, $at)
            ->load('resources')
            ->filter(fn (CourseOfferingLesson $lesson) => $lesson->resources->isNotEmpty());

        $rows = [];

        foreach ($lessons as $lesson) {
            foreach ($lesson->resources as $resource) {
                // A link resource with no usable URL is a row in a table, not a
                // resource. Reporting it would put a dead entry in front of a
                // student.
                if ($resource->isFile() || $resource->hasUsableLink()) {
                    $rows[] = [
                        'resource' => $resource,
                        'name' => $resource->displayName(),
                        'kind' => $resource->isFile() ? 'file' : 'link',
                        'kind_label' => $resource->isFile() ? 'File' : 'Link',
                        'size' => $resource->isFile() ? $resource->sizeLabel() : null,
                        'lesson_title' => $lesson->title,
                        'url' => route('student.courses.content.resources.show', $resource),
                    ];
                }
            }
        }

        return [
            'items' => array_slice($rows, 0, 6),
            'count' => count($rows),
        ];
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // THE NAVIGATION, AND WHAT IS HONESTLY AVAILABLE
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    /**
     * The six areas, each with whether it exists and where it goes.
     *
     * A controlled absence, not a hidden one: the area appears, says it is not
     * available yet, and carries no link. A navigation that silently omits two of
     * its own sections makes a student wonder whether they have been given the
     * wrong account; one that shows "not available yet" tells them the truth and
     * still admits the plan.
     *
     * @param  list<CourseOfferingModule>  $modules
     * @param  int  $assessmentCount  The number of published assessments for this
     *         Offering, counted by `CourseOfferingAssessments` - the same service the
     *         tab's own page lists from. Passed IN rather than recomputed here so
     *         the count in the navigation and the rows on the page cannot disagree.
     * @return list<array<string, mixed>>
     */
    private function sections(User $student, CourseOffering $offering, array $modules, int $assessmentCount = 0): array
    {
        // A plain loop, because `visibleModules()` returns a LIST and not a
        // Collection. Calling `->sum()` on an array is a fatal.
        $lessonCount = 0;

        foreach ($modules as $module) {
            $lessonCount += $module->lessons->count();
        }

        return [
            [
                'key' => 'overview',
                'label' => 'Overview',
                'available' => true,
                'url' => route('student.courses.show', $offering),
                'count' => null,
            ],
            [
                'key' => 'content',
                'label' => 'Content',
                'available' => true,
                'url' => route('student.courses.content', $offering),
                'count' => $lessonCount,
            ],
            [
                'key' => 'live_classes',
                'label' => 'Live Classes',
                'available' => true,
                // The student's own Live Class pages exist, so this area is real and
                // links to them. Per-Offering filtering happens on the destination's
                // own terms, which is where the Live Class domain's rules already
                // are.
                'url' => route('student.live_classes.index'),
                'count' => null,
            ],
            [
                'key' => 'assignments',
                'label' => 'Assignments',
                'available' => true,
                'url' => route('student.courses.assignments.index', $offering),
                'count' => null,
            ],
            [
                'key' => 'quizzes_exams',
                'label' => 'Quizzes & Exams',
                // Available whatever the count. A zero is an honest state the tab
                // itself explains in words, and hiding the tab would leave a student
                // unable to tell "no assessments yet" from "this course has no such
                // feature" - which is the confusion this navigation exists to
                // remove.
                'available' => true,
                'url' => route('student.courses.exams', $offering),
                'count' => $assessmentCount,
            ],
            [
                'key' => 'grades',
                'label' => 'Grades',
                'available' => false,
                'url' => null,
                // Gradebook is explicitly out of scope for this pass, and the honest
                // thing is to say so rather than to show the legacy K12 grade list
                // as though it described this course. It does not: it is a different
                // domain, a different data source, and a different set of rules.
                'note' => 'A course gradebook is not available yet. Released marks appear on each assignment you can open.',
            ],
        ];
    }

    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
    // SHARED READS
    // â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

    /**
     * The published, released modules a student may see, with their lessons
     * loaded - the one set of content every part of the home agrees on.
     *
     * @param  array<int, array<string, mixed>>  $completion
     * @return list<CourseOfferingModule>
     */
    private function visibleModules(CourseOffering $offering, Carbon $at, array $completion): array
    {
        return CourseOfferingModule::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->where('status', '!=', CourseOfferingModule::STATUS_ARCHIVED)
            ->where(fn ($q) => $q->whereNull('released_at')->orWhere('released_at', '<=', $at))
            ->orderBy('sequence')
            ->orderBy('id')
            ->with(['lessons' => fn ($q) => $q
                ->where('status', CourseOfferingLesson::STATUS_PUBLISHED)
                ->whereIn('content_type', CourseOfferingLesson::STUDENT_CONTENT_TYPES)
                ->where(fn ($q2) => $q2->whereNull('released_at')->orWhere('released_at', '<=', $at))
                ->orderBy('sequence')
                ->orderBy('id'),
            ])
            ->get()
            // A module is shown when it has visible lessons OR a visible
            // assessment. A module whose only content is a required assessment is
            // precisely where a student must be sent - it is all that is left to
            // do - and hiding it would make that assessment unreachable from the
            // very page whose job is to point at it.
            ->filter(fn (CourseOfferingModule $module) => $module->lessons->isNotEmpty()
                || (int) ($completion[(int) $module->id]['assessments_total'] ?? 0) > 0)
            ->values()
            ->all();
    }

    /**
     * The published, released lessons across the whole Offering.
     *
     * @return Collection<int, CourseOfferingLesson>
     */
    private function visibleLessons(CourseOffering $offering, Carbon $at): Collection
    {
        return CourseOfferingLesson::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->where('status', CourseOfferingLesson::STATUS_PUBLISHED)
            ->whereIn('content_type', CourseOfferingLesson::STUDENT_CONTENT_TYPES)
            ->where(fn ($q) => $q->whereNull('released_at')->orWhere('released_at', '<=', $at))
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }
}
