<?php

namespace App\Support\CourseContent;

use App\Models\Assignment;
use App\Models\CourseOfferingLesson;
use App\Models\CourseOfferingModule;
use DomainException;

/**
 * What a lecturer is told before a module is released to students.
 *
 * WHY THIS IS SEPARATE FROM THE LIFECYCLE
 *
 * `CourseOfferingModuleLifecycle` answers "is this move permitted". This answers
 * "is this module ready". Keeping them apart matters: the lifecycle is a state
 * machine that must not grow opinions about content, and the content check must
 * not become a second source of truth about which statuses exist.
 *
 * WHY A CHECK EXISTS AT ALL
 *
 * Publishing validated nothing. `transitionModule()` checked the release date
 * and the state transition, so a module with no lessons and no assessments
 * published cleanly and appeared to students as an empty topic - which is the
 * reported symptom of "content appears with no assignment". The benchmark's
 * stage 5 is "validate and publish", and that stage was simply absent.
 *
 * WHAT IS ENFORCED, AND WHAT IS ONLY WARNED
 *
 * A module may legitimately be a reading-only topic, so requiring an assessment
 * outright would refuse a legitimate shape and push lecturers to invent filler
 * assessments. The rule is therefore:
 *
 *   - a module with NO content at all is REFUSED. There is nothing to teach.
 *   - a module with content but NO required assessment is WARNED, not refused.
 *
 * The refusal is the one case with no defensible exception, and it is the one
 * that produces the empty-topic symptom.
 */
final class ModulePublicationCheck
{
    /**
     * @return array{ready: bool, errors: list<string>, warnings: list<string>}
     */
    public function evaluate(CourseOfferingModule $module): array
    {
        $errors = [];
        $warnings = [];

        $lessons = $this->publishedLessonCount($module);
        $requiredAssessments = $this->requiredAssessmentCount($module);

        if ($lessons === 0 && $requiredAssessments === 0) {
            $errors[] = 'This module has no lessons and no assessment, so there is nothing for a student to do. '
                .'Add at least one lesson before you publish it.';
        }

        // Warned rather than refused: a reading-only topic is a real shape, and a
        // lecturer who has deliberately built one should not be forced to add an
        // assessment they do not want. The warning is what makes the choice visible.
        if ($lessons > 0 && $requiredAssessments === 0) {
            $warnings[] = 'This module has no required assessment, so nothing in it checks that a student '
                .'understood the material. Students will be able to read everything and move on. '
                .'Add a required assessment if this topic should be checked.';
        }

        // A required assessment with no pass mark cannot gate on a threshold. The
        // module still publishes - it is the lecturer's choice - but the topic will
        // complete on any returned mark, including zero.
        if ($requiredAssessments > 0 && $this->hasUnthresholdedRequiredAssessment($module)) {
            $warnings[] = 'At least one required assessment has no pass mark, so it counts as passed on any '
                .'returned mark - including zero. Set a pass mark if that topic should require a standard.';
        }

        return [
            'ready' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @throws DomainException when the module must not be released
     */
    public function assertPublishable(CourseOfferingModule $module): void
    {
        $result = $this->evaluate($module);

        if (! $result['ready']) {
            throw new DomainException(implode(' ', $result['errors']));
        }
    }

    private function publishedLessonCount(CourseOfferingModule $module): int
    {
        return CourseOfferingLesson::query()
            ->where('course_offering_module_id', $module->id)
            ->where('status', CourseOfferingLesson::STATUS_PUBLISHED)
            ->count();
    }

    private function requiredAssessmentCount(CourseOfferingModule $module): int
    {
        return Assignment::query()
            ->where('course_offering_module_id', $module->id)
            ->where('requirement_role', Assignment::ROLE_REQUIRED)
            ->count();
    }

    private function hasUnthresholdedRequiredAssessment(CourseOfferingModule $module): bool
    {
        return Assignment::query()
            ->where('course_offering_module_id', $module->id)
            ->where('requirement_role', Assignment::ROLE_REQUIRED)
            ->where(function ($query): void {
                $query->whereNull('pass_mark');
            })
            ->exists();
    }
}
