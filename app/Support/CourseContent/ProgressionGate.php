<?php

namespace App\Support\CourseContent;

use App\Models\Assignment;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingModule;
use App\Models\User;
use App\Support\Assignments\AssignmentOutcome;

/**
 * Whether a student may open the NEXT thing, or has to finish this one first.
 *
 * THIS IS MOODLE'S "RESTRICT ACCESS", AND IT IS ENFORCED ON THE SERVER
 *
 * The benchmark's rule is explicit: restrictions must hold even when a student
 * types a lesson URL directly. Hiding a link in a blade template is a
 * suggestion, not a restriction - the request never passes through the view. So
 * the decision lives here and is asked from `resolveLessonForStudent()`, the one
 * place every lesson read already passes through.
 *
 * WHY IT IS OFF BY DEFAULT
 *
 * Gating a live student population with no warning would lock people out of
 * content they are mid-way through. `CourseOffering` therefore carries the
 * switch, defaulting to OFF, and an offering that has never opted in behaves
 * exactly as it does today. Nothing changes for existing courses until a
 * lecturer turns it on, which is the only safe way to ship a change that can
 * deny access to a paying student.
 *
 * A STUDENT WHO HAS ALREADY PASSED IS NEVER LOCKED OUT
 *
 * The gate asks what the student has ALREADY achieved, never what they are
 * currently doing. A student sitting the final lesson of a course, or one who
 * passed a module before this feature existed, must reach every lesson. This is
 * the property that makes the feature safe to enable on an existing course, and
 * it is asserted by test rather than assumed.
 */
final class ProgressionGate
{
    /**
     * Is this offering set up to gate progression?
     */
    public function gatingEnabled(CourseOfferingModule $module): bool
    {
        $offering = $module->relationLoaded('offering')
            ? $module->offering
            : $module->offering()->first();

        return (bool) ($offering->gate_progression ?? false);
    }

    /**
     * The published modules of this offering, in the order a student meets them.
     *
     * Sequencing is by `sequence` then `id`, matching every other reading-order
     * query in the codebase. A module that is not released to students is
     * excluded entirely: gating on content nobody can see would strand a student
     * on a module that does not exist for them.
     *
     * @return list<CourseOfferingModule>
     */
    public function sequenceFor(User $student, CourseOfferingModule $current): array
    {
        return CourseOfferingModule::query()
            ->where('school_id', $current->school_id)
            ->where('course_offering_id', $current->course_offering_id)
            ->where('status', CourseOfferingModule::STATUS_PUBLISHED)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->filter(fn (CourseOfferingModule $m) => $m->isReleasedToStudents())
            ->values()
            ->all();
    }

    /**
     * May this student open this module?
     *
     * The rule is "every module before this one is complete". Complete is asked
     * of `ModuleCompletion`, which is the same computation the badges and the
     * progress bars already use - so a module cannot be simultaneously reported
     * as complete on one screen and locked on another.
     */
    public function mayOpenModule(User $student, CourseOfferingModule $target): bool
    {
        if (! $this->gatingEnabled($target)) {
            return true;
        }

        $sequence = $this->sequenceFor($student, $target);

        foreach ($sequence as $module) {
            // Reached the target: everything ahead of it has been satisfied.
            if ((int) $module->id === (int) $target->id) {
                return true;
            }

            if (! $this->moduleSatisfied($student, $module)) {
                return false;
            }
        }

        return true;
    }

    /**
     * May this student open this lesson?
     *
     * Module progression remains independent. Teachers can additionally require
     * lesson completion in order inside a module. Neither setting imposes a
     * timetable, and completed, released lessons remain available for revision.
     */
    public function mayOpenLesson(User $student, CourseOfferingLesson $lesson): bool
    {
        if (! $lesson->module) {
            return false;
        }

        $completed = \App\Models\CourseOfferingLessonProgress::query()
            ->where('student_id', $student->id)
            ->where('course_offering_id', $lesson->course_offering_id)
            ->where('status', \App\Models\CourseOfferingLessonProgress::STATUS_COMPLETED)
            ->pluck('course_offering_lesson_id')->map(fn ($id) => (int) $id)->all();
        // Release and enrollment are enforced before this gate. Existing work
        // remains available for revision when new requirements are added.
        if (in_array((int) $lesson->id, $completed, true)) {
            return true;
        }
        if (! $this->mayOpenModule($student, $lesson->module)) {
            return false;
        }
        if (! $lesson->module->offering?->sequential_lessons) {
            return true;
        }
        foreach ($lesson->module->studentLessons()->orderBy('sequence')->orderBy('id')->get() as $previous) {
            if ((int) $previous->id === (int) $lesson->id) {
                return true;
            }
            if (! in_array((int) $previous->id, $completed, true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * The module a student should be working in, and why they cannot go further.
     *
     * `blocking_module` is what the student screen needs in order to say
     * something useful - "finish Topic 2 first" - rather than simply refusing.
     */
    public function stateFor(User $student, CourseOfferingModule $target): array
    {
        if (! $this->gatingEnabled($target)) {
            return ['open' => true, 'blocking_module' => null, 'message' => null];
        }

        foreach ($this->sequenceFor($student, $target) as $module) {
            // Reached the target: everything ahead of it has been satisfied.
            if ((int) $module->id === (int) $target->id) {
                return ['open' => true, 'blocking_module' => null, 'message' => null];
            }

            if (! $this->moduleSatisfied($student, $module)) {
                return [
                    'open' => false,
                    'blocking_module' => $module,
                    'message' => $this->lockedMessage($student, $module),
                ];
            }
        }

        return ['open' => true, 'blocking_module' => null, 'message' => null];
    }

    /**
     * Has the student cleared this module?
     *
     * Read from `ModuleCompletion`, which applies each activity's own configured
     * rule. A module with no required activity and no lessons is satisfied - an
     * empty module is not the gate's business, and refusing it would strand a
     * student on content that was never populated.
     */
    public function moduleSatisfied(User $student, CourseOfferingModule $module): bool
    {
        $completion = app(ModuleCompletion::class)->forModule($module, $student);

        return (bool) $completion['is_complete'];
    }

    /**
     * The words a student sees when the gate is closed.
     *
     * Names the thing they must finish. A refusal that does not say what to do
     * next is indistinguishable from a broken site, and a student who cannot tell
     * whether they have been blocked by a mistake will ask a lecturer rather
     * than try again.
     */
    public function lockedMessage(User $student, ?CourseOfferingModule $blocking): string
    {
        if ($blocking === null) {
            return 'Finish the earlier topic before starting this one.';
        }

        return 'Finish "'.$blocking->title.'" before starting this one.'
            .$this->outstandingSummary($student, $blocking);
    }

    /**
     * What is still outstanding in the blocking module, in a clause.
     *
     * Built from the same completion report the gate itself used, so the message
     * can never describe a different state from the one that caused the refusal.
     */
    private function outstandingSummary(User $student, CourseOfferingModule $module): string
    {
        $completion = app(ModuleCompletion::class)->forModule($module, $student);

        $parts = [];

        if (! $completion['lessons_complete']) {
            $done = (int) $completion['lessons_completed'];
            $total = (int) $completion['lessons_total'];
            $parts[] = $total > 0
                ? $done.' of '.$total.' lessons'
                : 'its lessons';
        }

        $outstanding = (int) ($completion['assessments_outstanding'] ?? 0);

        if ($outstanding > 0) {
            $parts[] = $outstanding === 1
                ? '1 assessment to pass'
                : $outstanding.' assessments to pass';
        }

        return $parts === [] ? '' : ' Still to do there: '.implode(' and ', $parts).'.';
    }

    /** The outcome of a module's first required assessment, when it has one. */
    public function outstandingAssessment(User $student, CourseOfferingModule $module): ?AssignmentOutcome
    {
        $task = Assignment::query()
            ->where('course_offering_module_id', $module->id)
            ->where('requirement_role', Assignment::ROLE_REQUIRED)
            ->orderBy('id')
            ->first();

        return $task === null ? null : AssignmentOutcome::for($task, $student);
    }
}
