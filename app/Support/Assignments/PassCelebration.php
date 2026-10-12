<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\CourseOfferingModule;
use App\Models\User;
use App\Support\CourseContent\ModuleCompletion;
use App\Support\CourseContent\ProgressionGate;

/**
 * WHETHER TO CELEBRATE A PASS - AND WHAT TO SAY.
 *
 * WHY THIS EXISTS RATHER THAN A VIEW CHECK
 *
 * The specification asks for a popup saying "your mark is X and you have passed,
 * you qualify for the next topic", and immediately warns that it must never appear
 * for a non-pass. A check inside a Blade template is one `@if` away from being
 * written against the wrong variable - the latest attempt rather than the best one,
 * a released mark rather than a pass - and a student congratulated on a failure is
 * not a cosmetic defect. It is the platform lying to the one person who most needs
 * it to be accurate.
 *
 * So this object returns NULL when there is nothing to celebrate. A caller cannot
 * render a celebration it was not handed, and cannot be handed one for a non-pass.
 *
 * "QUALIFY FOR THE NEXT TOPIC" IS NOT DECORATIVE
 *
 * The line tells a student they may move on only when they actually may. If the
 * current module still has outstanding work, saying "you can go to the next topic"
 * is a promise the server will break on the next click - and a student who is told
 * to go on and then refused is considerably worse off than one who was never told.
 * That is why this reads `ProgressionGate` rather than assuming the pass is enough.
 *
 * AND THE GATE MAY BE OFF
 *
 * Gating is opt-in per Course Offering. When it is off a student was never going to
 * be stopped, so the pass unlocks nothing here either, and the line says what the
 * pass actually means rather than inventing a consequence.
 */
final class PassCelebration
{
    private function __construct(
        private readonly Assignment $assignment,
        private readonly AssignmentOutcome $outcome,
        private readonly ?string $unit,
        private readonly ?string $nextTitle,
        private readonly bool $nextOpen,
        private readonly string $line,
    ) {
    }

    /**
     * The celebration, or null.
     *
     * Null is the answer for every case that must not be celebrated: not passed,
     * not yet marked, still resting, no pass mark at all. Returning null rather
     * than a neutral string is deliberate - see the note on `congratulation()`.
     */
    public static function for(
        Assignment $assignment,
        User $student,
        ?string $unit = null,
        ?ProgressionGate $gate = null,
        ?ModuleCompletion $completion = null,
    ): ?self {
        $outcome = AssignmentOutcome::for($assignment, $student);

        // A PASS MARK IS ALSO REQUIRED, EVEN THOUGH THE OUTCOME SAYS "PASSED".
        //
        // `AssignmentOutcome` deliberately reports an unthresholded assignment with
        // a returned mark as PASSED, because for the question it exists to answer -
        // is this required activity satisfied - the answer genuinely is yes, and
        // `ModuleCompletion` depends on it. Changing that would break completion and
        // with it progression gating for every unthresholded assignment in the
        // school.
        //
        // But "you have PASSED, and you qualify for the next topic" is a stronger
        // claim than "this requirement is satisfied". There is no bar to have
        // cleared, and nothing about the mark opens anything. Celebrating it would
        // tell a student they passed a test nobody set, and imply a qualification
        // the gate will refuse.
        //
        // So the completion question and the celebration question are kept apart:
        // the first may be satisfied without a pass mark, the second may not.
        if (! $assignment->hasPassMark() || ! $outcome->passed()) {
            return null;
        }

        $line = $outcome->congratulation($unit);

        if ($line === null) {
            return null;
        }

        [$nextTitle, $nextOpen] = self::nextTopic($assignment, $student, $gate, $completion);

        return new self($assignment, $outcome, $unit, $nextTitle, $nextOpen, $line);
    }

    /** The base line: passed, and the mark that did it. */
    public function line(): string
    {
        return $this->line;
    }

    /**
     * May this student actually open the next topic?
     *
     * True when this assignment belonged to a module, that module is now complete,
     * and the module after it exists. Asking the gate rather than assuming is what
     * keeps the popup honest.
     */
    public function qualifiedForNextTopic(): bool
    {
        return $this->nextOpen && $this->nextTitle !== null;
    }

    public function nextTopicTitle(): ?string
    {
        return $this->qualifiedForNextTopic() ? $this->nextTitle : null;
    }

    /**
     * The whole message, in the order a student needs it.
     *
     * The mark and the pass first, because that is what they came for. The next
     * topic only when it is genuinely open.
     */
    public function message(): string
    {
        $message = $this->line;

        if ($this->qualifiedForNextTopic()) {
            $message .= ' You have qualified for the next topic, '.$this->nextTitle.'.';
        } elseif ($this->nextTitle !== null && $this->assignment->course_offering_module_id !== null) {
            // The topic after this one EXISTS but is not open. Saying why is more
            // useful than saying nothing: "finish what is left of this topic" is an
            // instruction, whereas silence leaves a passed student wondering why
            // the next button is grey.
            $message .= ' Finish the rest of this topic and the next one opens.';
        }

        return $message;
    }

    /**
     * The next topic after this assignment's, and whether the student may open it.
     *
     * @return array{0: ?string, 1: bool}
     */
    private static function nextTopic(
        Assignment $assignment,
        User $student,
        ?ProgressionGate $gate,
        ?ModuleCompletion $completion,
    ): array {
        $moduleId = $assignment->course_offering_module_id;

        if ($moduleId === null) {
            // A loose assessment belongs to no topic, so it gates nothing and
            // unlocks nothing. Saying "you have qualified for the next topic" would
            // be inventing a consequence for a passing grade.
            return [null, false];
        }

        $module = CourseOfferingModule::query()->find($moduleId);

        if ($module === null) {
            return [null, false];
        }

        $next = CourseOfferingModule::query()
            ->where('course_offering_id', $module->course_offering_id)
            ->where('status', '!=', CourseOfferingModule::STATUS_ARCHIVED)
            ->where('sequence', '>', $module->sequence)
            ->orderBy('sequence')
            ->orderBy('id')
            ->first();

        if ($next === null) {
            // The last topic. There is nothing to qualify for, and the pass is the
            // end of the course for this student rather than a door.
            return [null, false];
        }

        $gate ??= app(ProgressionGate::class);
        $completion ??= app(ModuleCompletion::class);

        // THE MODULE MUST BE COMPLETE FIRST, NOT JUST THIS ASSESSMENT.
        //
        // The student may have lessons still open in the same topic. Passing the
        // assessment clears one requirement, not the topic, and telling them
        // otherwise sends them to a page that refuses them.
        if (! $completion->forModule($module, $student)['is_complete']) {
            return [(string) $next->title, false];
        }

        return [(string) $next->title, $gate->mayOpenModule($student, $next)];
    }
}