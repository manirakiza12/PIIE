<?php

namespace App\Support\CourseExams;

use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\Enrollment;
use App\Models\OnlineExam;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\CourseContent\CourseContentAccess;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use App\Support\Permissions\OnlineExamAuthorizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * WHO MAY REACH A COURSE OFFERING ASSESSMENT.
 *
 * ── THIS CLASS INTRODUCES NO NEW AUTHORITY ──────────────────────────────────
 *
 * Three existing authorities already answer every question asked here, and each
 * is asked rather than reimplemented:
 *
 *   - `CourseContentAccess` owns the Offering lookup and the lecturer's
 *     allocation-based manage/view rights, plus the confirmed-registration test
 *     for a student. It is the single definition of "may this person reach this
 *     Offering", used by Course Content, Assignments and Live Classes alike.
 *   - `OnlineExamAuthorizer` owns "may this person create, manage, teach, mark or
 *     publish results for this exam", and is unchanged.
 *   - `OnlineExamPolicy::sit` owns the student gate, and is unchanged.
 *
 * The only genuinely new question is the one the old code could not ask: whether a
 * given student is on a given OFFERING. That is answered here, and answered with
 * the same `CourseRegistration` test the rest of the Course Offering student
 * surface uses.
 *
 * ── WHY THE STUDENT VISIBILITY RULE LIVES HERE AND NOT IN THE CONTROLLER ────
 *
 * `studentExams()` and `findStudentExamOrFail()` each contained their own copy of
 * the class/programme/session eligibility rule. Two copies of one rule is already
 * one too many - they can disagree, and a disagreement here means either a student
 * cannot sit an exam they should, or one they should not. So the rule is stated
 * once, in `applyStudentVisibility()`, and both call sites use it. Adding a third
 * copy inside Course Offering integration would have been the worst possible place
 * to do that, because this is precisely where the two halves of the product
 * (legacy Class/Section and Course Offering) have to agree.
 */
class CourseOfferingExamAccess
{
    /**
     * The two authorities this class ASKS about a single Offering, plus the one it
     * asks about a LIST of them.
     *
     * `CourseContentAccess` decides "may this person act on THAT Offering" and
     * `OnlineExamAuthorizer` decides "may this person act on THAT exam"; neither
     * can answer "which Offerings may this lecturer create assessments in", so
     * `LecturerCourseOfferingAccess` is injected for exactly that question and for
     * nothing else. `PermissionService` remains absent: it is not asked here.
     *
     * Resolved lazily rather than promoted, so a hand-built instance keeps working.
     */
    private readonly LecturerCourseOfferingAccess $lecturerAccess;

    public function __construct(
        private readonly CourseContentAccess $contentAccess,
        private readonly OnlineExamAuthorizer $examAuthorizer,
        ?LecturerCourseOfferingAccess $lecturerAccess = null,
    ) {
        $this->lecturerAccess = $lecturerAccess ?? app(LecturerCourseOfferingAccess::class);
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE OFFERINGS A LECTURER MAY AUTHOR AN ASSESSMENT IN
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The Offerings this actor may currently create a Course Offering assessment in.
     *
     * This is the list behind the generic create page's FIRST selector, and it is
     * `LecturerCourseOfferingAccess::offerings()` - not a query written here. That
     * matters for two reasons, and both are about authority rather than tidiness:
     *
     *  - Every row is derived from a `CourseOfferingLecturerAllocation` in the
     *    actor's own tenant, so an Offering the lecturer does not teach cannot
     *    appear, and another institution's cannot either. There is no code path in
     *    which this method widens what the allocation table says.
     *  - Each row arrives already carrying its academic context (`subject`,
     *    `academicYear`, `academicPeriod`), which is exactly what the derived
     *    read-only summary reads. Re-deriving it here would be a second opinion
     *    about what a lecturer is teaching.
     *
     * Empty when the allocation tables are absent, which is the correct answer:
     * no allocation table means no allocation, and the create page then falls back
     * to the legacy selectors rather than offering a course the lecturer cannot
     * reach.
     *
     * @return Collection<int, CourseOffering>
     */
    public function teachableOfferings(User $actor): Collection
    {
        if (! Schema::hasColumn('online_exams', 'course_offering_id')) {
            return new Collection();
        }

        if (! Schema::hasTable('course_offerings')
            || ! Schema::hasTable('course_offering_lecturer_allocations')) {
            return new Collection();
        }

        return $this->lecturerAccess->offerings($actor);
    }

    /**
     * The authoritative academic fields for an exam that belongs to this Offering.
     *
     * ── WHY THIS IS A FUNCTION AND NOT FOUR LINES IN A CONTROLLER ────────────
     *
     * Two controllers now create Offering-backed exams - the Offering-scoped one
     * and the generic create page - and they must not disagree about a single
     * value. Stated once, it is also the place where the reasoning behind the NULLs
     * lives, rather than in a comment that one of the two call sites may not read.
     *
     * Everything returned here comes from the OFFERING ROW. Nothing in this array
     * may be read from the request, ever: a lecturer who posts a different
     * `subject_id` to the one their course teaches gets the course's Course Unit,
     * and a lecturer who posts a `class_id` gets NULL, because the class graph is
     * not how higher-education delivery is expressed here.
     *
     * `course_offering_id` is returned too, so the caller writes the same value it
     * authorised rather than the one that arrived.
     *
     * @return array<string, int|null>
     */
    public function authoritativeFieldsFor(CourseOffering $offering): array
    {
        return [
            'school_id' => (int) $offering->school_id,
            'course_offering_id' => (int) $offering->id,
            // The Offering has exactly ONE Course Unit. It is read, never chosen.
            'subject_id' => (int) $offering->subject_id,
            // NULL on purpose. Populating any of these would enrol every student of
            // that class into the assessment and bypass the confirmed Course
            // Registration that `CourseOfferingExamAccess::applyStudentVisibility()`
            // uses, which is the only legitimate higher-education eligibility test.
            'class_id' => null,
            'programme_id' => null,
            'session_id' => null,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE MARKING AUTHORITY, FOR RECIPIENT RESOLUTION
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The lecturers who must be TOLD about work on this exam, and who may act on it.
     *
     * ── WHY THIS IS THE UNION OF TWO EXISTING AUTHORITIES ────────────────────
     *
     * The old rule answered the question with `creator_id ?: created_by`, which is
     * authorship. That is wrong in both directions, and a notification list is the
     * worst place to get it wrong in one of them:
     *
     *  - too NARROW, which is the failure that was reported. A student submitted an
     *    exam and nobody was told. On a Course Offering, a CO-LECTURER allocated to
     *    the course teaches it and marks it, and did not write the paper - so
     *    authorship excluded the person who actually needed to know.
     *
     *  - too WIDE, which is worse. Telling a lecturer who cannot act on a paper
     *    creates an inbox item they can neither open nor action, and in a school
     *    with many staff that is how a notification centre stops being read.
     *
     * So the set is exactly the people who may act, and it is derived from the two
     * authorities that already decide that, rather than from a third rule:
     *
     *   1. the lecturer ALLOCATED to this Course Offering, who may mark it because
     *      `OnlineExamAuthorizer::canTeachExam()` says so - and that method is the
     *      one that requires a current allocation in this tenant and fails CLOSED;
     *   2. the paper's AUTHOR, who may always act on their own paper - the same
     *      "an author may still mark after being deallocated" allowance the
     *      authorizer documents and relies on.
     *
     * Every recipient can therefore act, and everyone who can act is a recipient.
     * A legacy exam (`course_offering_id` NULL) keeps its original creator-only
     * rule, unchanged, because nine live exams depend on that audience.
     *
     * @return list<int>
     */
    public function activeMarkerUserIds(OnlineExam $exam): array
    {
        if ($exam->course_offering_id === null) {
            $creatorId = (int) ($exam->creator_id ?: $exam->created_by);

            return $creatorId ? [$creatorId] : [];
        }

        if (! Schema::hasTable('course_offerings')
            || ! Schema::hasTable('course_offering_lecturer_allocations')) {
            // No allocation table means no allocated markers can be resolved - but it
            // does NOT mean an empty recipient list.
            //
            // The author is added below, unconditionally, because they can still act on
            // their own paper with no allocation row. Returning early here skipped that
            // entirely, so on any schema without the allocation table an
            // offering-scoped paper notified NOBODY: not the allocated markers (the
            // table is absent) and not the author (the early return). A lecturer who
            // wrote the paper, and owns it, never heard that it was handed in.
            //
            // Falling through keeps the documented behaviour instead of contradicting it.
            $candidates = collect();
        } else {
            // Candidates come from the allocation table in the EXAM's own tenant.
            // `resolveForLecturer()` then re-resolves each one through that lecturer's
            // own allocations, so a forged or stale `course_offering_id` reaches nobody
            // rather than everybody.
            $candidates = DB::table('course_offering_lecturer_allocations')
                ->where('school_id', $exam->school_id)
                ->where('course_offering_id', (int) $exam->course_offering_id)
                ->whereIn('status', ['planned', 'active', 'ended'])
                ->orderBy('id')
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();
        }

        $ids = [];

        // The author is a recipient even with no allocation row, because they can
        // still act on their own paper.
        $authorId = (int) ($exam->creator_id ?: $exam->created_by);
        if ($authorId) {
            $candidates->push($authorId);
        }

        foreach ($candidates->unique() as $userId) {
            $lecturer = User::where('school_id', (int) $exam->school_id)->whereKey($userId)->first();

            if (! $lecturer) {
                continue;
            }

            // THE MARKING AUTHORITY, asked. Not a rule restated here.
            if ($this->examAuthorizer->canTeachExam($lecturer, $exam)) {
                $ids[] = (int) $lecturer->id;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE MANAGER SIDE - authoring inside an Offering
    // ══════════════════════════════════════════════════════════════════════

    /**
     * The Offering, for someone who is about to author in it, or a 404.
     *
     * The lookup and the manage check are both `CourseContentAccess`'s, so an
     * Offering is found by exactly one rule across the whole product and a
     * lecturer can only author assessments for Offerings they are genuinely
     * allocated to.
     */
    public function resolveOfferingForManager(User $actor, int $offeringId): CourseOffering
    {
        $offering = $this->contentAccess->resolveOffering($actor, $offeringId);
        $this->contentAccess->assertCanManage($actor, $offering);

        return $offering;
    }

    /**
     * The Offering, for someone who is only going to LOOK at it.
     *
     * View is deliberately weaker than manage: a co-lecturer who may see the
     * assessment list has not thereby gained the right to change an assessment.
     */
    public function resolveOfferingForViewer(User $actor, int $offeringId): CourseOffering
    {
        $offering = $this->contentAccess->resolveOffering($actor, $offeringId);

        $mayView = $this->contentAccess->canLecturerView($actor, $offering)
            || $this->contentAccess->canAdminView($actor, $offering)
            || $this->contentAccess->canStudentAccess($actor, $offering);

        if (! $mayView) {
            throw new HttpException(404, 'Course Offering not found.');
        }

        return $offering;
    }

    /**
     * Is this exam one of THIS Offering's assessments?
     *
     * Checked, never assumed. A route shaped
     * `/teacher/course-offerings/{offering}/exams/{exam}` is trivially mistyped, and
     * without this a lecturer allocated to two Offerings could move an assessment
     * from one to the other by editing the number in the address bar. It is a 404
     * rather than a 403, consistent with the rest of the Course Offering surface:
     * a colleague should not learn that an exam exists somewhere they cannot reach.
     */
    public function assertExamBelongsToOffering(OnlineExam $exam, CourseOffering $offering): void
    {
        if ((int) $exam->course_offering_id !== (int) $offering->id) {
            throw new HttpException(404, 'Assessment not found for this Course Offering.');
        }

        if ((int) $exam->school_id !== (int) $offering->school_id) {
            throw new HttpException(404, 'Assessment not found for this Course Offering.');
        }
    }

    /**
     * May this person run the engine's exam-management actions on this exam?
     *
     * Delegated whole. Adding an Offering to an exam must not make it easier to
     * manage than any other exam, so the engine's own authorizer stays the only
     * answer, and the Offering merely decides WHICH exam is on the table.
     */
    public function canManageExam(User $actor, OnlineExam $exam): bool
    {
        return $this->examAuthorizer->canManageExam($actor, $exam);
    }

    // ══════════════════════════════════════════════════════════════════════
    // THE STUDENT SIDE
    // ══════════════════════════════════════════════════════════════════════

    /**
     * May this student reach the Course Offering at all?
     *
     * `CourseContentAccess`'s answer, asked rather than restated: a confirmed
     * registration for that exact Offering, in that exact tenant, for an active
     * non-staff user.
     */
    public function canStudentAccessOffering(User $actor, CourseOffering $offering): bool
    {
        return $this->contentAccess->canStudentAccess($actor, $offering);
    }

    /**
     * The Offerings this student is CONFIRMED on, in this tenant.
     *
     * Returns an empty list when the registration table is absent, which is the
     * right answer rather than a fallback: no table means no confirmed
     * registration, and an empty list makes branch (A) of the visibility rule match
     * nothing. That is fail-CLOSED, which is the only safe direction - a missing
     * table must never widen who may sit an assessment.
     *
     * The guard is not paranoia. Most of this engine's test suites build a partial
     * schema that predates the Course Offering work, and an unguarded query here
     * turned every student exam page in seventeen of them into a 500.
     *
     * @return list<int>
     */
    public function confirmedOfferingIdsFor(int $studentId, int $schoolId): array
    {
        if (! Schema::hasTable('course_registrations')) {
            return [];
        }

        return CourseRegistration::query()
            ->where('school_id', $schoolId)
            ->where('student_id', $studentId)
            ->where('status', CourseRegistration::STATUS_CONFIRMED)
            ->whereNotNull('course_offering_id')
            ->orderBy('course_offering_id')
            ->pluck('course_offering_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function isConfirmedOnOffering(int $studentId, int $offeringId, int $schoolId): bool
    {
        return in_array((int) $offeringId, $this->confirmedOfferingIdsFor($studentId, $schoolId), true);
    }

    /**
     * The ONE student visibility rule, for the exam list and for opening one exam.
     *
     * ── THE HAZARD THIS EXISTS TO CLOSE ─────────────────────────────────────
     *
     * The original rule was: match an exam if `class_id` is NULL **or** one of the
     * student's classes, likewise for `programme_id` and `session_id`. The NULL
     * arms are what make school-wide papers work, and they are also why a legacy
     * exam with no class is visible to EVERY student in the institution.
     *
     * That behaviour is pre-existing and is preserved exactly - the brief forbids
     * changing working legacy delivery, and nine live exams depend on it. It is
     * reported, not silently rewritten.
     *
     * What could not be preserved is that rule's application to a Course Offering
     * assessment. An Offering assessment is created with `class_id`, `programme_id`
     * and `session_id` all NULL - the brief forbids faking higher-education
     * delivery through the legacy Class/Section graph, and populating them would
     * not be a harmless placeholder: it would enrol every student of that class
     * into the assessment, bypassing the confirmed Course Registration that is the
     * only legitimate higher-education eligibility test.
     *
     * So a NULL-armed legacy rule applied to an Offering assessment would make it
     * sit-able by the whole school. The two cases are therefore separated, and an
     * Offering assessment is reachable only by a student confirmed on THAT
     * Offering:
     *
     *   (A) course_offering_id IS NOT NULL  -> confirmed registration on it
     *   (B) course_offering_id IS NULL      -> the original rule, unchanged
     *
     * This only ever NARROWS what is visible. For all nine existing exams the
     * column is NULL, so branch (B) applies with byte-identical conditions.
     */
    public function applyStudentVisibility($query, int $studentId, int $schoolId)
    {
        $classIds = Enrollment::where('user_id', $studentId)
            ->where('school_id', $schoolId)
            ->pluck('class_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $sessionIds = Enrollment::where('user_id', $studentId)
            ->where('school_id', $schoolId)
            ->pluck('session_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $programmeId = Schema::hasTable('student_profiles')
            ? (int) (StudentProfile::where('user_id', $studentId)
                ->where('school_id', $schoolId)
                ->value('programme_id') ?? 0)
            : 0;

        $confirmedOfferingIds = $this->confirmedOfferingIdsFor($studentId, $schoolId);

        // Before the additive migration ran, there is no `course_offering_id`
        // column at all and every exam is a legacy one. Branch (A) is then simply
        // not expressible, so it is omitted and branch (B) stands alone - which is
        // exactly the pre-existing behaviour, on a schema that predates Course
        // Offerings. The engine guards its own optional columns the same way
        // (`Schema::hasColumn('online_exams', 'programme_id')`), and a missing
        // column must not be a fatal error on every student exam page.
        $offeringColumnExists = Schema::hasColumn('online_exams', 'course_offering_id');

        return $query->where(function ($q) use (
            $classIds, $sessionIds, $programmeId, $confirmedOfferingIds, $offeringColumnExists
        ) {
            // (A) a Course Offering assessment this student is confirmed on
            if ($offeringColumnExists) {
                $q->where(function ($inner) use ($confirmedOfferingIds) {
                    $inner->whereNotNull('course_offering_id');

                    if ($confirmedOfferingIds) {
                        $inner->whereIn('course_offering_id', $confirmedOfferingIds);
                    } else {
                        // Registered on nothing. Expressing this as an impossible
                        // predicate rather than skipping the branch is deliberate: it
                        // documents that a student with no confirmed registration sees
                        // no Offering assessment, rather than accidentally seeing all.
                        $inner->whereRaw('1 = 0');
                    }
                });
            }

            // (B) a legacy Class/Section assessment - the original rule, verbatim
            $q->orWhere(function ($legacy) use ($classIds, $sessionIds, $programmeId, $offeringColumnExists) {
                $legacy->when($offeringColumnExists, fn ($l) => $l->whereNull('course_offering_id'))
                    ->where(function ($c) use ($classIds) {
                        $c->whereNull('class_id');
                        if ($classIds) {
                            $c->orWhereIn('class_id', $classIds);
                        }
                    })
                    ->where(function ($p) use ($programmeId) {
                        $p->whereNull('programme_id');
                        if ($programmeId) {
                            $p->orWhere('programme_id', $programmeId);
                        }
                    })
                    ->where(function ($s) use ($sessionIds) {
                        $s->whereNull('session_id');
                        if ($sessionIds) {
                            $s->orWhereIn('session_id', $sessionIds);
                        }
                    });
            });
        });
    }

    /**
     * A single-answer test for one exam, for callers that hold a model rather than
     * building a query.
     */
    public function studentMaySeeExam(User $student, OnlineExam $exam): bool
    {
        if ((int) $exam->school_id !== (int) $student->school_id) {
            return false;
        }

        if ($exam->course_offering_id !== null) {
            return $this->isConfirmedOnOffering(
                (int) $student->id,
                (int) $exam->course_offering_id,
                (int) $student->school_id
            );
        }

        // Legacy: ask the query, which holds the class/programme/session rule.
        return OnlineExam::query()
            ->whereKey($exam->id)
            ->tap(fn ($q) => $this->applyStudentVisibility($q, (int) $student->id, (int) $student->school_id))
            ->exists();
    }

    /**
     * The confirmed students an Offering assessment's marking queue should cover.
     *
     * Confirmed Course Registrations on the Offering and nothing else - never
     * Programme, Cohort, Study Plan or legacy Class membership. A student cannot
     * be added to a marking list for an Offering assessment by belonging to a
     * cohort, which is the same rule Assignments and Live Classes already use.
     *
     * Returns an empty list for a legacy exam, because the legacy marking queue
     * derives its cohort from Class/Session membership and has always done so.
     * This method must not be used to re-scope a legacy exam, or it would quietly
     * empty a working queue.
     *
     * @return list<int>
     */
    public function eligibleStudentIds(OnlineExam $exam): array
    {
        if ($exam->course_offering_id === null || ! Schema::hasTable('course_registrations')) {
            return [];
        }

        return CourseRegistration::query()
            ->where('school_id', $exam->school_id)
            ->where('course_offering_id', $exam->course_offering_id)
            ->where('status', CourseRegistration::STATUS_CONFIRMED)
            ->orderBy('student_id')
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
