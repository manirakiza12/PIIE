<?php

namespace App\Support\CourseExams;

use App\Models\CourseOffering;
use App\Models\OnlineExam;
use App\Models\OnlineExamSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT A STUDENT'S ASSESSMENTS LOOK LIKE, IN THEIR OWN TERMS.
 *
 * ── WHY A READ SERVICE AND NOT A CONTROLLER ─────────────────────────────────
 *
 * The Course Home's Quizzes & Exams tab and the tab's own page both need this
 * list, and they must not be able to disagree about whether an assessment is
 * available, submitted or released. That is the same trap the assignment work hit
 * and solved by extracting `AssignmentState`: a status computed twice is two
 * chances to be wrong, and the failure is a student being told an exam is closed
 * on one page and offered to start it on the next.
 *
 * So the vocabulary lives here, once.
 *
 * ── EVERY GATE IS THE ENGINE'S ──────────────────────────────────────────────
 *
 *   - which exams exist:      `published()`, in this Offering, in this tenant
 *   - whether the window is open: `isWithinScheduledWindow()`
 *   - whether a mark may be seen: `isResultVisibleFor()`
 *   - whether an attempt is over: `isAttemptCompleted()`
 *   - which state an attempt is in: the engine's own `OnlineExamSubmission`
 *     status constants
 *
 * None of these are restated. In particular `isResultVisibleFor()` is the ONLY
 * thing that can produce a "Results available" row, and it requires the persisted
 * Admin publication state - so a release policy of "immediate" can never leak a
 * mark here, because "immediate" governs when the institution MAY publish and
 * never publishes by itself.
 *
 * ── OPENING AN ASSESSMENT IS NOT SUBMITTING IT ─────────────────────────────
 *
 * `in_progress` is its own state, distinct from every submitted state, and it
 * links to RESUME. A student who has opened a paper and then lost their
 * connection is therefore not shown as having handed anything in, and is not
 * invited to start a second attempt that would overwrite or abandon the first.
 */
class CourseOfferingAssessments
{
    public function __construct(
        private readonly CourseOfferingExamAccess $access,
    ) {}

    /**
     * The student's assessments in this Offering, most urgent first.
     *
     * @return list<array<string, mixed>>
     */
    public function forStudent(User $student, CourseOffering $offering, ?Carbon $at = null): array
    {
        $at ??= now();

        // ── NO EXAM ENGINE, NO ASSESSMENTS ──────────────────────────────────
        //
        // Fail-closed, and worth being explicit about why. Most of this codebase's
        // test suites build a partial schema containing only the tables they were
        // written against, and the Course Home suites predate the online exam engine
        // entirely. An unguarded query here made every one of them a 500.
        //
        // The answer is also the CORRECT answer rather than a concession to the
        // tests: with no exam engine installed, this institution runs no online
        // assessments, so "none" is what a student is entitled to be told. A module
        // or an assignment is unaffected - this only decides the Quizzes & Exams
        // tab, which is the one area that genuinely has nothing to read.
        if (! Schema::hasTable('online_exams') || ! Schema::hasTable('online_exam_submissions')) {
            return [];
        }

        // ── ELIGIBILITY IS ASKED HERE, NOT ONLY IN THE CONTROLLER ────────────
        //
        // The controllers check this before calling, so the pages were not
        // exploitable. The SERVICE was still wrong, and that is the worse problem:
        // a read service that will hand one student's course content to any caller
        // is a landmine for whoever calls it next - and the Course Home calls it
        // too, from a different controller entirely.
        //
        // `isConfirmedOnOffering()` is the EXISTING confirmed-registration test, so
        // this is not a second opinion about who may sit what; it is the same one.
        if (! $this->access->isConfirmedOnOffering(
            (int) $student->id,
            (int) $offering->id,
            (int) $offering->school_id
        )) {
            return [];
        }

        $exams = OnlineExam::query()
            ->forOffering($offering, $offering->school_id)
            // NOT `published()`.
            //
            // That scope is `workflow_state = 'published'`, so it also excluded
            // 'cancelled' - and an assessment withdrawn AFTER a student sat it then
            // vanished from their list with no explanation at all. For the one case
            // where a student is genuinely owed an answer, that is the worst
            // possible behaviour: they are waiting on a result that will never
            // arrive, and the page says nothing.
            //
            // So a draft and a paper awaiting review are still excluded - neither is
            // a student's business - while a cancelled one is listed and labelled,
            // which is what `describe()`'s withdrawn branch was written to do and
            // what the scope was making unreachable.
            ->whereNotIn('workflow_state', ['draft', 'pending_review'])
            ->with('questions')
            ->orderBy('start_datetime')
            ->orderBy('id')
            ->get();

        if ($exams->isEmpty()) {
            return [];
        }

        $submissions = OnlineExamSubmission::query()
            ->where('school_id', $offering->school_id)
            ->where('student_id', $student->id)
            ->whereIn('online_exam_id', $exams->pluck('id'))
            ->orderByDesc('attempt_no')
            ->get()
            ->groupBy('online_exam_id');

        $rows = [];

        foreach ($exams as $exam) {
            $attempts = $submissions->get($exam->id, collect());
            $latest = $attempts->first();
            $attemptsUsed = $attempts->count();

            $row = $this->describe($exam, $latest, $attemptsUsed, $at);

            // An exam cancelled after a student sat it is still a record they are
            // owed a result on, so it is listed - labelled as withdrawn - rather
            // than silently vanishing from under them.
            $rows[] = $row;
        }

        // What needs the student's attention first, then by opening time. A
        // deadline that has passed while an attempt is still open is the one thing
        // that must not be buried under a page of future papers.
        usort($rows, static function (array $a, array $b) {
            $rank = [
                'in_progress' => 0,
                'available' => 1,
                'under_review' => 2,
                'results_available' => 3,
                'closed' => 4,
                'attempts_used' => 5,
                'upcoming' => 6,
                'withdrawn' => 7,
            ];

            return [
                $rank[$a['status']] ?? 99,
                (string) ($a['window_starts_at'] ?? ''),
            ] <=> [
                $rank[$b['status']] ?? 99,
                (string) ($b['window_starts_at'] ?? ''),
            ];
        });

        return $rows;
    }

    /**
     * One assessment, in one student's hands.
     *
     * @return array<string, mixed>
     */
    private function describe(OnlineExam $exam, ?OnlineExamSubmission $latest, int $attemptsUsed, Carbon $at): array
    {
        $attemptsAllowed = max(1, (int) $exam->max_attempts);
        $questionCount = $exam->questions->count();

        $row = [
            'id' => $exam->id,
            'title' => (string) $exam->title,
            'type_label' => $exam->typeLabel(),
            'instructions' => $exam->proseInstructions(),
            'question_count' => $questionCount,
            'total_marks' => (int) $exam->total_marks,
            'pass_mark' => (int) $exam->pass_mark,
            'duration_minutes' => (int) $exam->duration_mins,
            'window' => $exam->windowSummary(),
            'window_starts_at' => $exam->start_datetime?->toIso8601String(),
            'window_ends_at' => $exam->end_datetime?->toIso8601String(),
            'attempts_used' => $attemptsUsed,
            'attempts_allowed' => $attemptsAllowed,
            'action' => null,
            'action_label' => null,
            'result_url' => null,
        ];

        // ── CANCELLED ────────────────────────────────────────────────────────
        //
        // Checked before everything else. A cancelled paper has no window that
        // means anything, so asking whether it is open would be a question about a
        // fiction.
        if ($exam->workflow_state === 'cancelled') {
            return array_merge($row, [
                'status' => 'withdrawn',
                'status_label' => 'Withdrawn',
                'detail' => $exam->cancellation_reason
                    ? 'This assessment was withdrawn: '.$exam->cancellation_reason
                    : 'This assessment was withdrawn by the lecturer.',
            ]);
        }

        // ── A STUDENT'S OWN ATTEMPT ──────────────────────────────────────────
        //
        // The attempt outranks the window. An exam that closed an hour ago while
        // the student still has an open attempt is "in progress", not "closed",
        // and offering them a fresh start would be the one genuinely harmful thing
        // this page could do.
        if ($latest && $latest->status === OnlineExamSubmission::STATUS_IN_PROGRESS) {
            return array_merge($row, [
                'status' => 'in_progress',
                'status_label' => 'In progress',
                'detail' => $this->attemptDetail($latest),
                // RESUME, never Start. A second attempt here would abandon the one
                // already in progress.
                'action' => route('student.online_exam.resume', $latest->id),
                'action_label' => 'Resume',
            ]);
        }

        if ($latest) {
            $resultVisible = $exam->isResultVisibleFor($latest);

            $row['result_url'] = $resultVisible
                ? route('student.online_exam.result', $latest->id)
                : null;

            if ($latest->status === OnlineExamSubmission::STATUS_RESULT_PUBLISHED) {
                $status = 'under_review';
                $label = 'Under review';
                $detail = 'Your work has been marked. The result is not released yet.';
            } elseif ($latest->status === OnlineExamSubmission::STATUS_FINALIZED) {
                $status = 'under_review';
                $label = 'Under review';
                $detail = 'Marking is complete. The result is not released yet.';
            } else {
                $status = 'under_review';
                $label = 'Submitted — under review';
                $detail = 'You submitted this. It is with your lecturer.';
            }

            if ($resultVisible) {
                $row['status'] = 'results_available';
                $row['status_label'] = 'Results available';
                $row['detail'] = 'Your result has been released.';
                $row['action'] = route('student.online_exam.result', $latest->id);
                $row['action_label'] = 'View result';

                return $row;
            }

            return array_merge($row, [
                'status' => $status,
                'status_label' => $label,
                'detail' => $detail,
                // Instructions stay readable after submission, so a student can
                // check what they were actually asked. That is not an answer
                // surface: the paper itself is closed to a completed attempt.
                'action' => route('student.online_exam.instructions', $exam->id),
                'action_label' => 'Read the instructions again',
            ]);
        }

        // ── NO ATTEMPT YET ───────────────────────────────────────────────────
        if (! $exam->isWithinScheduledWindow($at)) {
            $started = $exam->start_datetime
                && $exam->start_datetime->copy()->setTimezone($exam->scheduleTimezone())->gt($at);

            if ($started) {
                return array_merge($row, [
                    'status' => 'upcoming',
                    'status_label' => 'Upcoming',
                    'detail' => 'Opens '.$exam->start_datetime->format('j M Y, H:i').'.',
                    'action' => route('student.online_exam.instructions', $exam->id),
                    'action_label' => 'Read ahead',
                ]);
            }

            return array_merge($row, [
                'status' => 'closed',
                'status_label' => 'Closed',
                'detail' => 'This assessment closed on '
                    .($exam->end_datetime?->format('j M Y, H:i') ?? 'its scheduled end')
                    .' and you did not sit it.',
                'action' => route('student.online_exam.instructions', $exam->id),
                'action_label' => 'Read the instructions',
            ]);
        }

        if ($attemptsUsed >= $attemptsAllowed) {
            return array_merge($row, [
                'status' => 'attempts_used',
                'status_label' => 'No attempts remaining',
                'detail' => 'You have used all '.$attemptsAllowed.' allowed attempt(s).',
                'action' => route('student.online_exam.instructions', $exam->id),
                'action_label' => 'Read the instructions',
            ]);
        }

        if ($questionCount === 0) {
            // Defensive, and honest rather than silent: the engine's publication
            // readiness refuses to publish a paper with no questions, so this
            // should be unreachable. If it is ever reached it is shown as a fact
            // instead of offering a Start button that would open an empty paper.
            return array_merge($row, [
                'status' => 'closed',
                'status_label' => 'Not ready',
                'detail' => 'This assessment has no questions yet.',
            ]);
        }

        return array_merge($row, [
            'status' => 'available',
            'status_label' => 'Available',
            'detail' => $attemptsAllowed > 1
                ? 'Open now. You have '.$attemptsAllowed.' attempt(s); '
                    .($attemptsUsed > 0 ? $attemptsUsed.' used.' : 'none used yet.')
                : 'Open now.',
            'action' => route('student.online_exam.instructions', $exam->id),
            'action_label' => 'Read the instructions and start',
        ]);
    }

    /**
     * A sentence about an open attempt, in the student's own terms.
     *
     * The engine owns the arithmetic: `remainingSeconds()` already accounts for
     * both the duration and the scheduled end, whichever is sooner. Recomputing it
     * here would risk telling a student they have longer than they do.
     */
    private function attemptDetail(OnlineExamSubmission $submission): string
    {
        $seconds = $submission->remainingSeconds();

        if ($seconds <= 0) {
            return 'Your time has run out. This will be submitted for you — contact your lecturer if that is unexpected.';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $remaining = $hours > 0
            ? $hours.'h '.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT).'m'
            : $minutes.'m';

        return 'Attempt '.$submission->attempt_no.' is open. About '.$remaining.' remaining. '
            .'Your answers are saved as you go.';
    }
}
