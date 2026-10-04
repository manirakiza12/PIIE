<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A read model for the Course Offering Gradebook that does not exist yet.
 *
 * WHAT THIS IS, AND WHAT IT IS NOT
 *
 * This is NOT a gradebook. No page, no totals, no letter grades, no ranking. The
 * brief is explicit that the Gradebook comes later, and inventing one here would
 * be a second, competing product.
 *
 * It IS the agreement about SHAPE. A future Course Offering Gradebook must be able
 * to read assignment outcomes without a migration and without a rewrite, which
 * means the data it needs has to be derivable now, under stable names, from rows
 * that already exist. This class fixes that contract in one place, so when the
 * Gradebook is built it is written against this and not against the submission
 * tables' incidental shape.
 *
 * WHY A READ MODEL AND NOT A NEW TABLE
 *
 * Every field below is already stored: the mark, the maximum, the release
 * instant, the student, the Offering. A `course_offering_grades` table would be a
 * denormalised copy that can drift from the submission it came from - and the one
 * thing a gradebook must never do is disagree with the marking a lecturer
 * actually did.
 *
 * THE CONTRACT, STATED ONCE
 *
 *  - one row per (student, assignment) for a RELEASED outcome
 *  - `marks` is what the lecturer awarded; `max_marks` is the assignment maximum
 *    AT THE TIME OF MARKING, snapshotted on the submission, so a later change to
 *    an assignment's total marks cannot silently rescale history
 *  - `percent` is marks / max_marks, computed, never stored twice
 *  - `graded_at` and `graded_by` say who decided and when
 *  - ungraded work is ABSENT rather than zero, so "no mark yet" and "marked zero"
 *    can never be confused - the same reasoning Course Content uses for "not
 *    started"
 */
class GradebookFeed
{

    /**
     * Every RELEASED grade for one Course Offering.
     *
     * Unreleased marks are excluded, because a gradebook that showed a mark the
     * student has not been given would leak it. A lecturer building their own
     * record can use `markingList()` instead, which shows the ungraded work too.
     *
     * @return array<int, array<string, mixed>>
     */
    public function releasedGradesForOffering(CourseOffering $offering): array
    {
        $submissions = AssignmentSubmission::query()
            ->whereIn('assignment_id', Assignment::query()
                ->where('school_id', (int) $offering->school_id)
                ->where('course_offering_id', (int) $offering->id)
                ->select('id'))
            ->where('is_draft', false)
            ->whereNotNull('marks_awarded')
            ->whereNotNull('marks_released_at')
            // `max_marks` MUST be in this column list. It is the denominator of
            // every percentage this feed produces, and an eager-load `select` is
            // a WHITELIST: omit a column and Eloquent silently leaves it null.
            // That would report a maximum of 0 and a percent of null for every
            // grade, which is exactly the quiet corruption a gradebook must never
            // contain - and it would only appear in production, because a test
            // that reads the assignment separately would not notice.
            ->with('assignment:id,title,course_offering_id,school_id,max_marks')
            ->orderBy('assignment_id')
            ->orderBy('student_id')
            ->orderBy('attempt_no')
            ->get();

        $rows = [];

        foreach ($submissions as $submission) {
            // The maximum is read from the assignment, and `attempt_no` means the
            // LATEST graded attempt wins for a student who resubmitted - the same
            // "current state" rule the lecturer's marking list uses.
            $max = (int) ($submission->assignment?->max_marks ?? 0);

            $rows[] = [
                'course_offering_id' => (int) $offering->id,
                'school_id' => (int) $offering->school_id,
                'assignment_id' => (int) $submission->assignment_id,
                'assignment_title' => (string) ($submission->assignment?->title ?? ''),
                'student_id' => (int) $submission->student_id,
                'attempt_no' => (int) $submission->attempt_no,
                'marks' => (float) $submission->marks_awarded,
                'max_marks' => $max,
                'percent' => $max > 0 ? round(((float) $submission->marks_awarded / $max) * 100, 2) : null,
                'graded_at' => $submission->graded_at?->toIso8601String(),
                'graded_by' => $submission->graded_by !== null ? (int) $submission->graded_by : null,
                'released_at' => $submission->marks_released_at?->toIso8601String(),
                'is_late' => $submission->isLate(),
            ];
        }

        return $rows;
    }

    /**
     * The same grades collapsed to one row per student, keeping the latest graded
     * attempt of each assignment.
     *
     * This is the shape a per-student transcript wants. It is offered now
     * because collapsing is a rule, and a rule decided twice is a rule that will
     * eventually be decided differently.
     *
     * @return array<int, array<string, mixed>>
     */
    public function studentSummaryForOffering(CourseOffering $offering): array
    {
        $byStudent = [];

        foreach ($this->releasedGradesForOffering($offering) as $row) {
            $key = $row['student_id'].':'.$row['assignment_id'];

            if (! isset($byStudent[$key]) || $row['attempt_no'] >= $byStudent[$key]['attempt_no']) {
                $byStudent[$key] = $row;
            }
        }

        return array_values($byStudent);
    }

    /**
     * Students on the Offering with a factual state for every assignment,
     * including the ones with no mark at all.
     *
     * A gradebook must be able to show a student who has NOT been marked as
     * unmarked, so this returns an entry for every confirmed registrant against
     * every assignment rather than only the graded ones. Ungraded work carries
     * `marks => null`, which is what keeps "no mark yet" distinct from "marked
     * zero".
     *
     * @return array<int, array<string, mixed>>
     */
    public function matrixForOffering(CourseOffering $offering): array
    {
        $access = app(AssignmentAccess::class);

        $assignments = Assignment::query()
            ->where('school_id', (int) $offering->school_id)
            ->where('course_offering_id', (int) $offering->id)
            ->orderBy('id')
            ->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        $registrations = \App\Models\CourseRegistration::query()
            ->where('school_id', (int) $offering->school_id)
            ->where('course_offering_id', (int) $offering->id)
            ->where('status', \App\Models\CourseRegistration::STATUS_CONFIRMED)
            ->get();

        $studentIds = $registrations->pluck('student_id')->map(fn ($id) => (int) $id)->all();

        $submissions = AssignmentSubmission::query()
            ->whereIn('assignment_id', $assignments->pluck('id')->all())
            ->whereIn('student_id', $studentIds)
            ->where('is_draft', false)
            ->orderBy('attempt_no')
            ->get()
            ->groupBy(fn ($submission) => $submission->student_id.':'.$submission->assignment_id);

        $rows = [];

        foreach ($studentIds as $studentId) {
            foreach ($assignments as $assignment) {
                /** @var Collection $attempts */
                $attempts = $submissions->get($studentId.':'.$assignment->id, collect());
                $latest = $attempts->last();
                $released = $latest?->isReleased() ? $latest : null;
                $max = (int) $assignment->max_marks;

                $rows[] = [
                    'course_offering_id' => (int) $offering->id,
                    'student_id' => $studentId,
                    'assignment_id' => (int) $assignment->id,
                    'assignment_title' => (string) $assignment->title,
                    'state' => $latest === null
                        ? 'Not submitted'
                        : ($released?->isGraded() ? 'Returned' : ($latest->isLate() ? 'Late' : 'Submitted')),
                    'attempts_used' => $attempts->count(),
                    // null means "no released mark", which is NOT the same as 0.
                    'marks' => $released?->isGraded() ? (float) $released->marks_awarded : null,
                    'max_marks' => $max,
                    'percent' => ($released?->isGraded() && $max > 0)
                        ? round(((float) $released->marks_awarded / $max) * 100, 2)
                        : null,
                    'is_late' => $latest?->isLate() ?? false,
                ];
            }
        }

        return $rows;
    }
}
