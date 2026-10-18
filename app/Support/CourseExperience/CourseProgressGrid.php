<?php

namespace App\Support\CourseExperience;

use App\Models\CourseOffering;
use App\Models\CourseOfferingModule;
use App\Models\CourseRegistration;
use App\Models\User;
use App\Support\Assignments\AssignmentOutcome;
use App\Support\CourseContent\ModuleCompletion;
use Illuminate\Support\Carbon;

/**
 * THE TEACHER'S COURSE PROGRESS GRID.
 *
 * Moodle's Activity Completion report is the benchmark: every student against every
 * activity, as a grid of ticks. It is the screen a lecturer opens to answer "who is
 * behind, and where".
 *
 * WHY A READ MODEL AND NOT A NEW QUERY
 *
 * Everything here is already computed. `ModuleCompletion::forCourseOffering()`
 * derives each module's lessons-complete and outstanding-assessments from rows
 * that exist for their own reasons, and `AssignmentOutcome` owns the one question
 * that must not be answered twice. This class aggregates those existing answers
 * into the shape a table needs and adds nothing of its own, so it cannot come to
 * disagree with the student-facing screens - which is the failure mode that
 * matters, where a lecturer is told a student has passed while the student is
 * locked out, or the reverse.
 *
 * WHY IT IS NOT STORED
 *
 * The same argument as module completion: a stored progress table has to be
 * recomputed every time a lesson is completed, an attempt is submitted or a mark
 * is released, and each of those is a chance to silently rewrite a student's
 * history. Computing on read makes that impossible - there is no field to fall out
 * of step.
 */
final class CourseProgressGrid
{
    public function __construct(private ModuleCompletion $completion)
    {
    }

    /**
     * The grid for one Course Allocation.
     *
     * @return array{
     *     modules: list<array{id:int,title:string,sequence:int,published:bool}>,
     *     rows: list<array{
     *         student: User,
     *         modules: array<int, array<string, mixed>>,
     *         lessons_total: int, lessons_done: int,
     *         assessments_total: int, assessments_passed: int,
     *         percent: int, current_module: ?string, needs_attention: bool
     *     }>,
     *     totals: array<string, int>
     * }
     */
    public function forOffering(CourseOffering $offering, ?Carbon $at = null): array
    {
        $at ??= now();

        $modules = CourseOfferingModule::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->where('status', '!=', CourseOfferingModule::STATUS_ARCHIVED)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        // CONFIRMED REGISTRATIONS ONLY.
        //
        // This is the same rule the marking queue and the notifications already
        // follow: a student whose registration is pending or cancelled cannot
        // have work to mark, so listing them would show a lecturer a row of
        // nothing and imply they are behind on work they were never given.
        $students = User::query()
            ->where('school_id', $offering->school_id)
            ->whereIn('id', function ($query) use ($offering): void {
                $query->select('student_id')
                    ->from('course_registrations')
                    ->where('course_offering_id', $offering->id)
                    ->where('status', CourseRegistration::STATUS_CONFIRMED);
            })
            ->orderBy('name')
            ->get();

        $moduleSummaries = $modules->map(fn (CourseOfferingModule $m) => [
            'id' => (int) $m->id,
            'title' => (string) $m->title,
            'sequence' => (int) $m->sequence,
            'published' => $m->status === CourseOfferingModule::STATUS_PUBLISHED,
        ])->values()->all();

        $rows = [];

        foreach ($students as $student) {
            $perModule = $this->completion->forCourseOffering($offering, $student, $at);

            $lessonsTotal = 0;
            $lessonsDone = 0;
            $assessmentsTotal = 0;
            $assessmentsPassed = 0;
            $currentModule = null;
            $needsAttention = false;

            $cells = [];

            foreach ($perModule as $moduleId => $report) {
                $lessonsTotal += (int) $report['lessons_total'];
                $lessonsDone += (int) $report['lessons_completed'];
                $assessmentsTotal += (int) $report['assessments_total'];
                $assessmentsPassed += (int) $report['assessments_satisfied'];

                // "WHERE THEY ARE" IS THE FIRST MODULE THEY HAVE NOT FINISHED.
                //
                // Not the first with outstanding work - a student who has skipped
                // straight to module 4 has not "reached" module 1, and reporting
                // the first unfinished module would name a module they are done
                // with. The first module they have not completed is the honest
                // answer, and it is the one a lecturer wants to read.
                if ($currentModule === null && ! $report['is_complete']) {
                    $currentModule = $moduleSummaries[array_search((int) $moduleId, array_column($moduleSummaries, 'id'), true)]['title'] ?? null;
                }

                $cells[(int) $moduleId] = [
                    'complete' => (bool) $report['is_complete'],
                    'lessons_percent' => (int) $report['lesson_percent'],
                    'assessments_outstanding' => (int) ($report['assessments_outstanding'] ?? 0),
                    'assessments_satisfied' => (int) ($report['assessments_satisfied'] ?? 0),
                ];
            }

            // FAILED, not merely behind. A student who has handed in three times
            // and not reached the pass mark is the one a lecturer needs to see -
            // they are waiting out a pause, and a grid that showed only "topic 2"
            // would hide that entirely.
            $needsAttention = $this->hasFailedAssessment($offering, $student, $at);

            $rows[] = [
                'student' => $student,
                'modules' => $cells,
                'lessons_total' => $lessonsTotal,
                'lessons_done' => $lessonsDone,
                'assessments_total' => $assessmentsTotal,
                'assessments_passed' => $assessmentsPassed,
                'percent' => $this->percentage($lessonsDone, $lessonsTotal, $assessmentsPassed, $assessmentsTotal),
                'current_module' => $currentModule,
                'needs_attention' => $needsAttention,
            ];
        }

        return [
            'modules' => $moduleSummaries,
            'rows' => $rows,
            'totals' => [
                'students' => count($rows),
                'modules' => count($moduleSummaries),
                'needing_attention' => count(array_filter($rows, fn (array $r) => $r['needs_attention'])),
            ],
        ];
    }

    /**
     * Has this student an assessment they have been told they FAILED?
     *
     * Asked through `AssignmentOutcome`, which is the only thing permitted to
     * decide whether a student has passed. Deriving it here would be exactly the
     * second source of truth this class exists to avoid.
     */
    private function hasFailedAssessment(CourseOffering $offering, User $student, Carbon $at): bool
    {
        $tasks = \App\Models\Assignment::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->where('requirement_role', \App\Models\Assignment::ROLE_REQUIRED)
            ->get();

        foreach ($tasks as $task) {
            if (! $task->hasPassMark()) {
                continue;
            }

            if (AssignmentOutcome::for($task, $student, $at)->failed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * ONE number per student, for sorting and for the summary bar.
     *
     * Lessons and assessments are counted separately and then combined by simply
     * adding their ratios, which is NOT a weighted grade and is not presented as
     * one. `StudentCourseHome` refuses to blend them on the student-facing screens
     * because there is no governed weighting between a lesson and an assessment;
     * the same objection applies here. It exists so a table can be sorted and
     * scanned, and the two components are shown beside it so the number is never
     * the only thing a lecturer sees.
     */
    private function percentage(int $lessonsDone, int $lessonsTotal, int $assessmentsPassed, int $assessmentsTotal): int
    {
        $parts = array_filter([
            $lessonsTotal > 0 ? $lessonsDone / $lessonsTotal : null,
            $assessmentsTotal > 0 ? $assessmentsPassed / $assessmentsTotal : null,
        ]);

        if ($parts === []) {
            return 0;
        }

        return (int) round(100 * (array_sum($parts) / count($parts)));
    }
}
