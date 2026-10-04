<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseOffering;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Lecturer grading and releasing results.
 *
 * MARKS ARE BOUNDED, ALWAYS
 *
 * A mark above the assignment maximum or below zero is refused here rather than
 * stored and clamped. Clamping would hide a slip, and a stored 120/100 would make
 * every downstream total - and any future gradebook - quietly wrong. A grader who
 * needs to exceed the maximum needs a different assignment, not a bigger number.
 *
 * RECORDING A MARK AND RELEASING IT ARE SEPARATE ACTS
 *
 * `grade()` records what the lecturer decided. `release()` is the deliberate
 * second step that makes it visible to the student. A lecturer can mark a batch
 * of work over a week and release it on an announced day, and a student never
 * sees a half-considered mark.
 *
 * GRADING HISTORY IS PRESERVED
 *
 * Grading records WHO and WHEN on the row, and an attempt is never overwritten -
 * a resubmission is a new attempt. So the audit trail of who marked what, and
 * when, falls out of the data rather than needing a separate history table.
 */
class GradingService
{
    public function __construct(
        private readonly AssignmentAccess $access,
        private readonly SubmissionService $submissions,
    ) {}

    /**
     * The marking list: every confirmed registrant on this Offering, with the
     * factual state of their work.
     *
     * Built from confirmed REGISTRATIONS, not from who happens to have submitted,
     * so a student who has not submitted still appears as "Not submitted". A
     * marking list assembled from submissions would quietly omit exactly the
     * students a lecturer most needs to chase.
     *
     * @return \Illuminate\Support\Collection<int, array{student: \App\Models\User, registration: mixed, submission: ?AssignmentSubmission, state: string, attempts: int}>
     */
    public function markingList(Assignment $assignment)
    {
        $registrations = $this->access->eligibleRegistrations($assignment);

        if ($registrations->isEmpty()) {
            return collect();
        }

        $studentIds = $registrations->pluck('student_id')->map(fn ($id) => (int) $id)->all();

        $students = User::query()
            ->where('school_id', (int) $assignment->school_id)
            ->whereIn('id', $studentIds)
            ->get()
            ->keyBy('id');

        $submissions = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->whereIn('student_id', $studentIds)
            ->where('is_draft', false)
            ->orderBy('attempt_no')
            ->get()
            ->groupBy('student_id');

        return $registrations->map(function ($registration) use ($students, $submissions, $assignment) {
            $studentId = (int) $registration->student_id;
            $attempts = $submissions->get($studentId, collect());
            /** @var AssignmentSubmission|null $latest */
            $latest = $attempts->last();

            return [
                'student' => $students->get($studentId),
                'registration' => $registration,
                'submission' => $latest,
                'attempts' => $attempts,
                'attempt_count' => $attempts->count(),
                'state' => $this->stateFor($assignment, $latest, $attempts->count()),
            ];
        })->values();
    }

    /**
     * The factual state of one student's work, in the lecturer's own words.
     */
    public function stateFor(Assignment $assignment, ?AssignmentSubmission $latest, int $attemptCount = 0): string
    {
        if (! $latest) {
            // "Missing" means work can no longer be accepted, so nothing is
            // coming. An assignment merely PAST ITS DUE DATE with late work still
            // permitted is not missing work - it is work that is still expected,
            // which is why this asks whether a submission is still possible
            // rather than whether a date has gone by.
            return AssignmentLifecycle::mayAcceptNewSubmission($assignment, 0)
                ? 'Not submitted'
                : 'Missing';
        }

        if ($latest->isReleased()) {
            return 'Returned';
        }

        if ($latest->isGraded()) {
            // Marked but not yet released: the lecturer has decided, the student
            // has not been told. Saying "Graded" here would imply they have.
            return 'Graded (not yet released)';
        }

        if ($latest->isLate()) {
            return 'Late';
        }

        return $attemptCount > 1 ? 'Resubmitted' : 'Submitted';
    }

    /**
     * Record a mark and optional feedback WITHOUT showing it to the student.
     */
    public function grade(User $actor, AssignmentSubmission $submission, array $attributes): AssignmentSubmission
    {
        $assignment = $this->assertCanGrade($actor, $submission);

        if ($submission->is_draft) {
            throw new DomainException('This is prepared work that was never submitted, so there is nothing to grade.');
        }

        $marks = $this->normaliseMarks($attributes['marks_awarded'] ?? null, $assignment);

        return DB::transaction(function () use ($submission, $marks, $attributes, $actor) {
            // Lock and write ONCE. Mutating the passed instance and then
            // re-reading into a second one would let a concurrent release be
            // overwritten with a stale, unreleased mark.
            $locked = AssignmentSubmission::query()
                ->whereKey($submission->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->marks_awarded = $marks;
            $locked->feedback = $this->feedbackOrNull($attributes['feedback'] ?? null);
            $locked->graded_at = now();
            $locked->graded_by = $actor->id;
            $locked->status = 'graded';
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * Make a recorded mark and its feedback visible to the student.
     *
     * The single point at which a student gains access to a result. Called
     * explicitly, and it is what a "Return feedback" action means.
     */
    public function release(User $actor, AssignmentSubmission $submission): AssignmentSubmission
    {
        $this->assertCanGrade($actor, $submission);

        if (! $submission->isGraded()) {
            throw new DomainException('Record a mark before returning feedback to the student.');
        }

        $submission->returned_at = now();
        $submission->returned_by = $actor->id;
        $submission->marks_released_at = now();
        $submission->save();

        return $submission->fresh();
    }

    /**
     * Withdraw a released result so the lecturer can reconsider it.
     *
     * Permitted deliberately: a mistake in a released mark is worse than a
     * delayed correction, and the row keeps the marks and feedback so nothing is
     * lost. The student's view simply stops showing the result again.
     */
    public function unrelease(User $actor, AssignmentSubmission $submission): AssignmentSubmission
    {
        $this->assertCanGrade($actor, $submission);

        $submission->returned_at = null;
        $submission->returned_by = null;
        $submission->marks_released_at = null;
        $submission->save();

        return $submission->fresh();
    }

    /**
     * Resolve a submission for a LECTURER, inside the Offering in the URL.
     *
     * The Assignment is resolved FIRST, under tenant + Offering + id, and the
     * submission is then scoped to that assignment. Deliberately not the other
     * way round: `assignment_submissions` carries no `school_id` of its own, and
     * filtering a submission by an offering id it merely mirrors would trust that
     * mirror. Resolving the parent first means the tenant and Offering are proven
     * from `assignments` itself, and a submission id from another tenant or
     * another delivery is a 404.
     */
    public function resolveSubmissionForManager(
        User $actor,
        int $offeringId,
        int $assignmentId,
        int $submissionId
    ): AssignmentSubmission {
        $assignment = $this->access->resolveForManager($actor, $offeringId, $assignmentId);

        $submission = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->whereKey($submissionId)
            ->first();

        if (! $submission) {
            throw new HttpException(404, 'Submission not found.');
        }

        return $submission->load(['assignment', 'student']);
    }

    /**
     * Resolve a submission for the STUDENT WHO OWNS IT.
     *
     * A student may read their own attempt and its released result. They may
     * never read anybody else's, and the refusal is a 404 so the page cannot be
     * used to discover that another student's submission exists.
     */
    public function resolveSubmissionForStudent(User $actor, int $offeringId, int $assignmentId, int $submissionId): AssignmentSubmission
    {
        $assignment = $this->access->resolveForStudent($actor, $offeringId, $assignmentId);

        $submission = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $actor->id)
            ->whereKey($submissionId)
            ->first();

        if (! $submission) {
            throw new HttpException(404, 'Submission not found.');
        }

        return $submission->load('assignment');
    }

    /**
     * @throws HttpException when the lecturer has no allocation on the Offering
     *                       this submission belongs to
     */
    private function assertCanGrade(User $actor, AssignmentSubmission $submission): Assignment
    {
        // The parent assignment is the authority for tenant and Offering, so it
        // is loaded and re-checked through the manager path. A submission row
        // carries no school_id of its own precisely so that this cannot be
        // short-circuited by a denormalised value that has drifted.
        $assignment = Assignment::query()
            ->whereKey($submission->assignment_id)
            ->first();

        if (! $assignment || $assignment->course_offering_id === null) {
            throw new HttpException(404, 'Assignment not found.');
        }

        $offering = $this->access->resolveOffering($actor, (int) $assignment->course_offering_id);
        $this->access->assertCanManage($actor, $offering);
        $this->access->resolveForManager($actor, (int) $offering->id, (int) $assignment->id);

        return $assignment;
    }

    /**
     * @return float|null
     */
    private function normaliseMarks($marks, Assignment $assignment): ?float
    {
        if ($marks === null || $marks === '') {
            return null;
        }

        if (! is_numeric($marks)) {
            throw ValidationException::withMessages(['marks_awarded' => 'Enter a mark as a number.']);
        }

        $value = round((float) $marks, 2);
        $maximum = (float) $assignment->max_marks;

        // Refused, never clamped: a stored 120 against a maximum of 100 would
        // make every total and any future gradebook quietly wrong, and clamping
        // would hide the slip that caused it.
        if ($value < 0) {
            throw ValidationException::withMessages(['marks_awarded' => 'A mark cannot be negative.']);
        }

        if ($maximum > 0 && $value > $maximum) {
            throw ValidationException::withMessages([
                'marks_awarded' => 'The mark cannot be more than the maximum for this assignment ('.$assignment->max_marks.').',
            ]);
        }

        return $value;
    }

    private function feedbackOrNull($feedback): ?string
    {
        if (! is_string($feedback)) {
            return null;
        }

        $feedback = trim($feedback);

        return $feedback === '' ? null : $feedback;
    }
}
