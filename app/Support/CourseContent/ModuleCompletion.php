<?php

namespace App\Support\CourseContent;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingLessonProgress;
use App\Models\CourseOfferingModule;
use App\Models\User;
use App\Support\Assignments\AssignmentLifecycle;
use Illuminate\Support\Carbon;

/**
 * Whether a student has fully completed a module.
 *
 * THE RULE, IN GENERAL TERMS
 *
 *     A module is complete when every REQUIRED, COMPLETION-BEARING ACTIVITY in it
 *     satisfies its own configured completion rule.
 *
 * This was previously "all lessons completed" - a lesson-only rule wearing a
 * general name - which reported a module as finished while a required assessment
 * sat unsubmitted. `ModuleActivityKind` enumerates the kinds; this class evaluates
 * the two PIIE implements today and the rule itself does not need changing when a
 * third arrives.
 *
 * COMPUTED, NEVER STORED - AND THAT IS THE POINT
 *
 * Nothing here writes a completion flag. A module's completion is derived on every
 * read from the lesson progress rows a student earned and the submissions they
 * actually made.
 *
 * A stored module-completion record would have to be recomputed every time a lesson
 * was completed, a task was submitted or an assignment was re-marked - and each of
 * those recomputations is an opportunity to silently rewrite a student's history.
 * Computing instead makes that impossible: there is no field to get out of step, so
 * the lesson completion evidence already on record cannot be damaged by any of this.
 * That is what allowed required assessments to be integrated without touching a
 * single progress row.
 *
 * THE FOUR RULES THIS ENCODES
 *
 * 1. LESSON PROGRESS IS FACTUAL AND UNTOUCHED.
 *    Counted only over PUBLISHED lessons, and only from rows recording `completed`.
 *    Nothing here infers completion from having opened a lesson, and nothing here
 *    writes to `course_offering_lesson_progress`.
 *
 * 2. AN OPTIONAL TASK NEVER BLOCKS ANYTHING.
 *    Only assignments marked `required` contribute. Optional is supplementary in
 *    every state, for every student - it cannot gate, and it is not counted in the
 *    outstanding total either, so a student is not nagged about it.
 *
 * 3. AN ASSIGNMENT THE STUDENT CANNOT SEE NEVER BLOCKS.
 *    A draft, or a scheduled assignment whose release moment has not arrived, is
 *    neither shown nor allowed to gate. Unpublished lecturer work must never stand
 *    between a student and their progress, and a student cannot act on something
 *    they cannot see. An earlier version counted a DRAFT required task as
 *    outstanding, which would have blocked a module on work that did not exist for
 *    the student yet.
 *
 * 4. MERELY OPENING AN ASSIGNMENT NEVER SATISFIES IT.
 *    Satisfaction is derived from submissions, and from a submission only when it
 *    is a real one: `is_draft` false and `submitted_at` set. There is no path here
 *    that turns a page view into completion.
 *
 * SATISFACTION FOLWS EACH ACTIVITY'S OWN CONFIGURED RULE
 *
 * For an assignment, `submission` is satisfied by a real attempt, while
 * `released_mark` additionally requires the mark to have been returned. "Handed in"
 * and "assessed and told" are different states, and a lecturer who configures the
 * second gets it. The rule is read from the assignment, never hardcoded.
 *
 * A REQUIRED ACTIVITY WHOSE RULE CANNOT BE EVALUATED IS EXCLUDED FROM THE GATE
 *
 * A required task with an unimplemented rule, or a module containing a kind PIIE
 * does not implement yet, is EXCLUDED from the gate and REPORTED separately. If
 * such a thing counted, the module would be permanently incomplete and the student
 * would have no way forward - blocked by a state the platform cannot reach. The
 * exclusion is visible rather than silent.
 */
class ModuleCompletion
{
    public function __construct(
        private readonly \App\Support\Assignments\AssignmentDisplay $display,
    ) {}

    /**
     * Completion of one module for one student.
     *
     * @return array{
     *     module_id: int,
     *     lessons_total: int,
     *     lessons_completed: int,
     *     lessons_in_progress: int,
     *     lessons_complete: bool,
     *     assessments_total: int,
     *     assessments_required: int,
     *     assessments_gating: int,
     *     assessments_complete: bool,
     *     assessments_satisfied: int,
     *     assessments_outstanding: int,
     *     required_activities: int,
     *     required_satisfied: int,
     *     required_outstanding: int,
     *     optional_activities: int,
     *     unevaluable: array<int, array{kind:string,key:string,title:string,rule:?string}>,
     *     assessments: array<int, array<string, mixed>>,
     *     is_complete: bool,
     *     lesson_percent: int
     * }
     */
    public function forModule(CourseOfferingModule $module, User $student, ?Carbon $at = null): array
    {
        $at ??= now();

        // ── Lessons: factual, published, and only real completion rows. ──
        $lessons = $this->publishedLessons($module, $at);
        $lessonIds = $lessons->pluck('id')->map(fn ($id) => (int) $id)->all();

        $engagement = $lessonIds === []
            ? collect()
            : CourseOfferingLessonProgress::query()
                ->whereIn('course_offering_lesson_id', $lessonIds)
                ->where('student_id', $student->id)
                ->get();

        $completedIds = $engagement
            ->where('status', CourseOfferingLessonProgress::STATUS_COMPLETED)
            ->pluck('course_offering_lesson_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $inProgressIds = $engagement
            ->where('status', CourseOfferingLessonProgress::STATUS_IN_PROGRESS)
            ->pluck('course_offering_lesson_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $lessonsComplete = $lessonIds === [] || count($completedIds) === count($lessonIds);

        // ── Assessments: only student-visible REQUIRED ones can gate. ──
        $assessments = $this->assessmentStates($module, $student, $at);

        $required = $assessments->filter(fn (array $row) => $row['required'])->values();
        $evaluableRequired = $required->reject(fn (array $row) => ! $row['evaluable'])->values();
        $unevaluable = $required->reject(fn (array $row) => $row['evaluable'])
            ->map(fn (array $row) => [
                'kind' => ModuleActivityKind::ASSIGNMENT,
                'key' => 'assignment:'.$row['id'],
                'title' => $row['title'],
                'rule' => $row['rule'],
            ])->values()->all();

        $satisfied = $evaluableRequired->filter(fn (array $row) => $row['satisfied'])->count();
        $gating = $evaluableRequired->count();
        $outstanding = max(0, $gating - $satisfied);

        $requiredActivities = ($lessonIds === [] ? 0 : count($lessonIds)) + $gating;
        $requiredSatisfied = min(count($completedIds), count($lessonIds)) + $satisfied;

        return [
            'module_id' => (int) $module->id,
            'lessons_total' => count($lessonIds),
            'lessons_completed' => count($completedIds),
            'lessons_in_progress' => count($inProgressIds),
            'lessons_complete' => $lessonsComplete,

            'assessments_total' => $assessments->count(),

            // CONFIGURED as required and visible to the student. The honest fact
            // about what the lecturer asked for, even where the task turns out to
            // be unsatisfiable.
            'assessments_required' => $required->count(),

            // Required AND evaluable, and therefore actually able to block. This
            // is the count the gate actually uses.
            'assessments_gating' => $gating,

            'assessments_satisfied' => $satisfied,
            'assessments_outstanding' => $outstanding,

            // "Are the assessments satisfied?", asked in its own right. Kept
            // separate from `is_complete`, because a module can have every lesson
            // done and still be incomplete - which is the whole point.
            'assessments_complete' => $outstanding === 0,

            'required_activities' => $requiredActivities,
            'required_satisfied' => $requiredSatisfied,
            'required_outstanding' => ($lessonIds === [] || $lessonsComplete ? 0 : max(0, count($lessonIds) - count($completedIds))) + $outstanding,
            // Optional assessments: reported so a lecturer or a student can see the
            // supplementary work exists, and deliberately NOT in any outstanding
            // total. Optional work that nagged a student would stop being optional.
            'assessments_optional' => $assessments->count() - $required->count(),

            'unevaluable' => $unevaluable,
            'assessments' => $assessments->all(),

            // Both parts, or the module is not fully complete. A module with no
            // required assessment completes on its lessons alone - which is what
            // "an optional task does not block module completion" means in practice.
            'is_complete' => $lessonsComplete && $outstanding === 0,

            // A factual LESSON figure, deliberately not a combined one. See the
            // note on the class docblock about weighting.
            'lesson_percent' => $lessonIds === [] ? 0 : (int) round((count($completedIds) / count($lessonIds)) * 100),
        ];
    }

    /**
     * Every student-visible assessment on a module, with its factual state.
     *
     * Only assignments a STUDENT CAN SEE are returned at all, and visibility is
     * decided by exactly one rule: `AssignmentLifecycle::isOpenToStudents`, which
     * PIIE already documents as the single student-visibility rule. A draft and a
     * scheduled assignment whose release moment has not arrived are therefore
     * neither listed nor allowed to gate.
     *
     * THE QUERY DOES NOT FILTER ON `status` AT ALL, AND THAT IS DELIBERATE
     *
     * An earlier version narrowed the query to `published` and `closed` and then
     * re-applied the lifecycle rule in PHP. Two expressions of one rule is two
     * chances for them to disagree - and they had: a `scheduled` assignment whose
     * release moment had already passed is visible by the lifecycle rule, and the
     * query would have hidden it. The lifecycle rule also knows about the release
     * MOMENT, which a status filter cannot express.
     *
     * The rows fetched are the assignments attached to THIS module and school, so
     * a module that holds nothing but tasks is cheap to read. Filtering a few rows
     * in memory is the price of having one rule rather than two.
     *
     * An assignment scoped to a different Offering or tenant cannot appear here:
     * the module is already scoped, and the school is matched as well.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function assessmentStates(CourseOfferingModule $module, User $student, ?Carbon $at = null)
    {
        $at ??= now();

        $tasks = Assignment::query()
            ->where('school_id', (int) $module->school_id)
            ->where('course_offering_module_id', $module->id)
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($tasks as $task) {
            // ONE rule. A CLOSED assignment stays visible, because a student needs
            // its result; a DRAFT and a not-yet-released SCHEDULED one do not
            // exist for this student at all, and cannot stand between them and
            // their progress.
            if (! AssignmentLifecycle::isOpenToStudents($task, $at)) {
                continue;
            }

            $rows[] = $this->assessmentRow($task, $student, $at);
        }

        return collect($rows);
    }

    /**
     * One assessment's factual state, and whether its OWN rule is satisfied.
     *
     * The four states the brief names, in order, derived from stored rows:
     *
     *   not_submitted  no real attempt exists
     *   submitted      a real attempt exists, no mark
     *   marked         a mark exists, not yet returned to the student
     *   returned       the mark has been released to the student
     *
     * Satisfaction is then read from the assignment's configured rule, so
     * `submission` is satisfied at `submitted` while `released_mark` is not
     * satisfied until `returned`. Nothing is inferred from submission alone when a
     * lecturer has asked for more.
     *
     * @return array<string, mixed>
     */
    public function assessmentRow(Assignment $task, User $student, ?Carbon $at = null): array
    {
        $at ??= now();

        $attempts = \App\Models\AssignmentSubmission::query()
            ->where('assignment_id', $task->id)
            ->where('student_id', $student->id)
            ->where('is_draft', false)
            ->whereNotNull('submitted_at')
            ->orderBy('attempt_no')
            ->get();

        /** @var \App\Models\AssignmentSubmission|null $latest */
        $latest = $attempts->last();

        $state = match (true) {
            $latest === null => 'not_submitted',
            $latest->isReleased() => 'returned',
            $latest->isGraded() => 'marked',
            default => 'submitted',
        };

        // Each task's OWN rule decides satisfaction. Never a global assumption.
        $satisfied = match ($task->completion_rule) {
            Assignment::RULE_SUBMISSION => $state !== 'not_submitted',
            Assignment::RULE_RELEASED_MARK => $state === 'returned',
            // A rule PIIE cannot evaluate is never satisfied - but it is also never
            // allowed to gate, which is what the `evaluable` flag carries.
            default => false,
        };

        return [
            'id' => (int) $task->id,
            'kind' => ModuleActivityKind::ASSIGNMENT,
            'title' => (string) $task->title,
            'required' => $task->isRequiredForModule(),
            'role' => $task->requirement_role,
            'rule' => (string) $task->completion_rule,
            'rule_label' => $task->completionRuleLabel(),
            'evaluable' => $task->supportsRequirementSatisfied(),
            'state' => $state,
            'state_label' => $this->stateLabel($state, $latest),
            'satisfied' => $satisfied,
            'attempts' => $attempts->count(),
            'max_marks' => (int) $task->max_marks,
            'due_date' => $task->due_date,

            // A due date is an INSTANT, rendered in the ZONE THIS STUDENT reads
            // academic times in - the same policy the student's assignment pages
            // use. Resolved here so the course page and the assignment page can
            // never show two different times for the same assessment.
            //
            // `for()` primes the display service with this assignment and viewer.
            // It has to: the service reads the viewer and institution off the
            // model's loaded relations, and `getRelation()` throws on a missing key
            // rather than returning null, so an unprimed call is a hard error
            // rather than a silent fallback to the wrong timezone.
            'due_label' => $this->display->for($task, $student)->due($task, $student),            'is_late' => $latest?->isLate() ?? false,
            'open' => AssignmentLifecycle::mayAcceptNewSubmission($task, $attempts->count(), $at),
            'closed' => $task->status === Assignment::STATUS_CLOSED,
            'refusal' => AssignmentLifecycle::mayAcceptNewSubmission($task, $attempts->count(), $at)
                ? null
                : AssignmentLifecycle::refusalReason($task, $attempts->count(), $at),
        ];
    }

    /** Plain words for the four states, in the lecturer's and the student's language. */
    private function stateLabel(string $state, ?\App\Models\AssignmentSubmission $latest): string
    {
        $late = $latest?->isLate() ?? false;

        return match ($state) {
            'not_submitted' => 'Not submitted',
            'submitted' => $late ? 'Submitted (late) — awaiting marking' : 'Submitted — awaiting marking',
            'marked' => 'Marked — awaiting result',
            'returned' => $late ? 'Completed (submitted late)' : 'Completed',
            default => 'Not submitted',
        };
    }

    /**
     * The module's PUBLISHED lessons, in reading order.
     *
     * @return \Illuminate\Support\Collection<int, CourseOfferingLesson>
     */
    private function publishedLessons(CourseOfferingModule $module, Carbon $at)
    {
        return CourseOfferingLesson::query()
            ->where('course_offering_module_id', $module->id)
            ->where('status', CourseOfferingLesson::STATUS_PUBLISHED)
            ->where(fn ($q) => $q->whereNull('released_at')->orWhere('released_at', '<=', $at))
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
    }

    /**
     * Completion of every module in a Course Offering, keyed by module id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forCourseOffering(CourseOffering $offering, User $student, ?Carbon $at = null): array
    {
        $modules = CourseOfferingModule::query()
            ->where('course_offering_id', $offering->id)
            ->where('status', '!=', CourseOfferingModule::STATUS_ARCHIVED)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($modules as $module) {
            $out[(int) $module->id] = $this->forModule($module, $student, $at);
        }

        return $out;
    }
}
