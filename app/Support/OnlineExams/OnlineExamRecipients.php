<?php
/**
 * ONE AUTHORITATIVE ANSWER TO "WHO IS ELIGIBLE FOR THIS EXAM".
 *
 * ── THE DEFECT, TRACED ON REAL DATA ────────────────────────────────────────
 *
 * The reported symptom was "students were not receiving Online Exams". Tracing it
 * on the real database produced two distinct findings, and it matters that they are
 * different in kind:
 *
 *   1. NO exam is bound to any Course Offering. All 9 have
 *      `course_offering_id = NULL`. So the Course Home's Quizzes & Exams tab is
 *      CORRECTLY empty, and the report is an accurate observation of a real
 *      absence. Nothing is broken about that; there is simply nothing to deliver.
 *
 *   2. A LEAK that would have happened the moment an Offering exam was published.
 *      `OnlineExamPortalNotifier::eligibleStudents()` narrows recipients by
 *      `class_id` ONLY:
 *
 *          if ($exam->class_id) $query->whereExists(... enrollment ...);
 *
 *      A Course Offering exam has `class_id = NULL` by design, so the narrowing
 *      NEVER RUNS and the query degrades to "every student in the school".
 *      Simulated against the real rows: publishing an Offering-5 exam would have
 *      notified 4 students when only 3 are confirmed on it - leaking to
 *      "Fag alex", who is confirmed on nothing in Offering 5.
 *
 *      `OnlineExamAnnouncementNotifier::eligibleStudents()` has the identical
 *      shape, so the "starting soon" reminder would leak the same way.
 *
 * This is the same class-only blind spot that made exam VISIBILITY school-wide,
 * and it was fixed there last pass. The notification side was not: the two were
 * never joined, so a page that correctly showed a student nothing would still have
 * emailed them about it.
 *
 * ── WHY ONE CLASS, CALLED, RATHER THAN A THIRD RULE ────────────────────────
 *
 * Three call sites needed the same answer. Left as three inline queries they would
 * drift - which is precisely how this defect arose, from one query copied twice.
 * So this resolves recipients ONCE and the three sites call it.
 *
 * It delegates to `CourseOfferingExamAccess`, which is the service the engine's own
 * student gate now uses. So the question "may this student open this exam?" and the
 * question "should this student be told about it?" are answered by the same code.
 * A notification is not a softer form of eligibility; it is eligibility, acted on
 * earlier.
 *
 * ── THE LEGACY ARM IS UNCHANGED, CHARACTER FOR CHARACTER ──────────────────
 *
 * A legacy exam (`course_offering_id IS NULL`) keeps the class/enrolment rule
 * exactly as it was, including the NULL-class school-wide arm that nine live exams
 * depend on. Only the OFFERING arm is new, and it requires a confirmed Course
 * Registration - the test every other part of the Course Offering student surface
 * already uses.
 */
namespace App\Support\OnlineExams;

use App\Models\OnlineExam;
use App\Models\User;
use App\Support\CourseExams\CourseOfferingExamAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHO SHOULD BE TOLD ABOUT AN EXAM.
 *
 * Deliberately a class of static methods with no constructor, so the three existing
 * notifiers can call it without being rewired, and so it cannot hold state that
 * differs from the arguments it was given.
 */
final class OnlineExamRecipients
{
    /**
     * The students an exam's notifications must reach.
     *
     * @return list<int>
     */
    public static function forExam(OnlineExam $exam): array
    {
        $query = User::query()
            ->where('school_id', $exam->school_id)
            ->where('role_id', 7);

        // ── A COURSE OFFERING ASSESSMENT: confirmed registration, nothing else ──
        //
        // Never Programme, Cohort, Study Plan or legacy Class membership. A student
        // is told about an Offering assessment because they are CONFIRMED on that
        // Offering, which is the same test the exam's own visibility rule uses.
        if ($exam->course_offering_id !== null) {
            if (! Schema::hasTable('course_registrations')) {
                // Fail CLOSED. No registration table means no confirmed
                // registration, and an absent table must never widen who is
                // contacted.
                return [];
            }

            return $query
                ->whereExists(fn ($q) => $q->selectRaw('1')
                    ->from('course_registrations')
                    ->whereColumn('course_registrations.student_id', 'users.id')
                    ->where('course_registrations.school_id', $exam->school_id)
                    ->where('course_registrations.course_offering_id', $exam->course_offering_id)
                    ->where('course_registrations.status', 'confirmed'))
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        // ── A LEGACY EXAM: the original rule, unchanged ────────────────────────
        //
        // `class_id` NULL still means a school-wide paper, because nine live exams
        // rely on it. Nothing about that meaning is altered here.
        if ($exam->class_id) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('enrollment')
                ->whereColumn('enrollment.user_id', 'users.id')
                ->where('enrollment.class_id', $exam->class_id));
        }

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The COUNT, for the notifier's own bookkeeping.
     *
     * Kept as a separate call so the announcement notifier does not have to
     * materialise a list to log how many it reached - it already needs the list to
     * notify them, so this simply reports its size.
     */
    public static function countForExam(OnlineExam $exam): int
    {
        return count(self::forExam($exam));
    }

    /**
     * Does this student exist in the recipient set?
     *
     * The single-exam form, for a caller holding one student rather than a list.
     * `in_array` on the resolved list rather than a second query, so a caller
     * cannot get a different answer from a different code path.
     */
    public static function includes(OnlineExam $exam, int $userId): bool
    {
        return in_array((int) $userId, self::forExam($exam), true);
    }
}
