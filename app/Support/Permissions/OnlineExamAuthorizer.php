<?php

namespace App\Support\Permissions;

use App\Models\OnlineExam;
use App\Models\OnlineExamAnswer;
use App\Models\OnlineExamProctoringEvent;
use App\Models\OnlineExamQuestion;
use App\Models\OnlineExamSubmission;
use App\Models\CourseOffering;
use App\Models\TeacherPermission;
use App\Models\TeacherProgrammeAssignment;
use App\Models\User;
use App\Support\CourseContent\CourseContentAccess;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use Illuminate\Support\Facades\Schema;

class OnlineExamAuthorizer
{
    private OnlineExamPermissionService $permissionService;

    private CourseContentAccess $contentAccess;

    private LecturerCourseOfferingAccess $lecturerAccess;

    public function __construct(
        OnlineExamPermissionService $permissionService,
        ?CourseContentAccess $contentAccess = null,
        ?LecturerCourseOfferingAccess $lecturerAccess = null
    ) {
        $this->permissionService = $permissionService;

        // Both collaborators are ASKED, never constructed, and both are optional in
        // the signature so that a hand-built instance - a test double, a
        // `new OnlineExamAuthorizer(...)` - keeps working.
        //
        // Resolving them lazily also means the legacy path costs nothing: neither is
        // consulted for an exam with no `course_offering_id`.
        $this->contentAccess = $contentAccess ?? app(CourseContentAccess::class);
        $this->lecturerAccess = $lecturerAccess ?? app(LecturerCourseOfferingAccess::class);
    }

    public function can(User $user, string $permission): bool
    {
        return $this->permissionService->has($user, $permission);
    }

    public function canAny(User $user, array $permissions): bool
    {
        return $this->permissionService->hasAny($user, $permissions);
    }

    public function sameSchool(User $user, int $schoolId): bool
    {
        return (int) $user->school_id === (int) $schoolId;
    }

    public function ownsExam(User $user, OnlineExam $exam): bool
    {
        return (int) $user->id === (int) ($exam->creator_id ?: $exam->created_by);
    }

    public function canManageExam(User $user, OnlineExam $exam): bool
    {
        if (!$this->sameSchool($user, (int) $exam->school_id)) {
            return false;
        }

        if ($this->can($user, 'edit_all_online_exams')) {
            return true;
        }

        return $this->can($user, 'edit_own_online_exams') && $this->ownsExam($user, $exam);
    }

    /**
     * Marking access follows the authoritative academic assignment, while
     * authoring access remains owner/admin controlled.
     *
     * ── A COURSE OFFERING ASSESSMENT IS DECIDED BY THE LECTURER ALLOCATION ────
     *
     * ── THE GAP, AND HOW IT WAS FOUND ────────────────────────────────────────
     *
     * Reaching this method at all means the lecturer is neither the author
     * (`ownsExam()`) nor an `edit_all_online_exams` holder, because
     * `canAccessExamAttempts()` offers those two first. So this method is the only
     * remaining way in to somebody ELSE's submissions - and on a Course Offering
     * assessment it let a stranger through.
     *
     * A Course Offering exam is created with `class_id` AND `programme_id` both
     * NULL, deliberately: populating them would enrol every student of that class
     * and bypass confirmed registration. Those NULLs are the right answer for
     * DELIVERY. But this method was written to read them as "school-wide paper,
     * governed by subject permission", so it fell through to
     *
     *     if (empty($exam->class_id)) {
     *         return $subject && $this->teacherCanUseSubject($user, $subject);
     *     }
     *
     * and `teacherCanUseSubject()` FAILS OPEN for a subject linked to neither a
     * class nor a programme:
     *
     *     if (empty($subject->class_id)) {
     *         if (empty($subject->programme_id)) {
     *             return true;          // <-- no link at all
     *         }
     *
     * That fail-open is deliberate for legacy delivery, where a subject with no
     * assignment rows is the only configuration some schools have. It is the WRONG
     * answer for an Offering assessment, whose authority model is the Lecturer
     * Allocation - a table that exists, and was simply never consulted here.
     *
     * Measured on a real Course Offering exam whose subject was unlinked, an
     * unallocated role-3 lecturer in the same school passed this method,
     * `canMarkAnswer()` returned true, the route answered 302, and the mark was
     * written. That is precisely what §4 of the brief forbids.
     *
     * The fix asks the authority that already decides this everywhere else:
     * `CourseContentAccess::canLecturerManage()` - the same call Course Content,
     * Assignments and Live Classes make, which requires the capability AND a
     * current allocation in this tenant.
     *
     * ── WHAT IS NOT AFFECTED ────────────────────────────────────────────────
     *
     *   - LEGACY exams (`course_offering_id` NULL) reach none of this and keep the
     *     original rules below, character for character.
     *   - The exam's AUTHOR is unaffected: `canAccessExamAttempts()` answers
     *     `ownsExam()` first, so a lecturer who created the paper can still mark it
     *     after being deallocated.
     *   - An ADMINISTRATOR never enters this method - it is role 3 only.
     *   - Fail CLOSED: no allocation table, or an Offering that cannot be loaded,
     *     answers `false`.
     */
    public function canTeachExam(User $user, OnlineExam $exam): bool
    {
        if ((int) $user->role_id !== 3 || !$this->sameSchool($user, (int) $exam->school_id)) {
            return false;
        }

        // A COURSE OFFERING ASSESSMENT: the Lecturer Allocation decides. See the
        // method note above for why, and for what this deliberately leaves alone.
        if ($exam->course_offering_id !== null) {
            return $this->teachesCourseOffering($user, $exam);
        }

        // ── LEGACY, FROM HERE DOWN, UNCHANGED ────────────────────────────────
        if ($exam->programme_id && (!Schema::hasTable('teacher_programme_assignments') || !TeacherProgrammeAssignment::where('teacher_id', $user->id)
            ->where('school_id', $user->school_id)
            ->where('programme_id', $exam->programme_id)->exists())) {
            return false;
        }

        if (empty($exam->class_id)) {
            $subject = $exam->subject;
            return $subject && $this->teacherCanUseSubject($user, (int) $subject->id);
        }

        return TeacherPermission::where('teacher_id', $user->id)
            ->where('school_id', $user->school_id)
            ->where('class_id', $exam->class_id)
            ->exists()
            && $this->teacherCanUseSubject($user, (int) $exam->subject_id);
    }

    /**
     * Is this lecturer currently allocated to the Offering this assessment belongs
     * to?
     *
     * Two collaborators, each the single authority for one half of the question,
     * and neither of them restated here:
     *
     *  1. `LecturerCourseOfferingAccess::resolveForLecturer()` answers "is there an
     *     Offering this lecturer is allocated to, in this tenant". It returns NULL
     *     rather than throwing.
     *
     *     That distinction matters, and it was found by running this. The first
     *     attempt called `CourseContentAccess::resolveOffering()` instead - which is
     *     the method controllers use - and that one throws `HttpException(404)` for
     *     an unallocated lecturer. An authorizer must return a boolean: a policy that
     *     throws turns "you may not" into a 404 from wherever it happened to be
     *     consulted, which is both the wrong status and an accident of call site.
     *
     *  2. `CourseContentAccess::canLecturerManage()` answers "and does that
     *     allocation support a teaching action today", composing the capability,
     *     the tenant and the CURRENT state of the allocation. It is the same call
     *     Course Content, Assignments and Live Classes make.
     *
     * So one allocation rule, asked from a seventh place, rather than a seventh copy
     * of it.
     */
    private function teachesCourseOffering(User $user, OnlineExam $exam): bool
    {
        if (! Schema::hasTable('course_offerings')
            || ! Schema::hasTable('course_offering_lecturer_allocations')) {
            return false;
        }

        // Resolved through the lecturer's own allocations in their own tenant, so a
        // tampered `course_offering_id` finds nothing rather than reaching across.
        $offering = $this->lecturerAccess->resolveForLecturer($user, (int) $exam->course_offering_id);

        if (! $offering instanceof CourseOffering) {
            return false;
        }

        // Defence in depth, and free: the Offering found must be the exam's OWN
        // Offering and in the exam's OWN tenant. `resolveForLecturer()` already
        // scopes by the actor's school, so this comparison is what stops a future
        // refactor of that method from quietly widening this one.
        if ((int) $offering->id !== (int) $exam->course_offering_id
            || (int) $offering->school_id !== (int) $exam->school_id) {
            return false;
        }

        return $this->contentAccess->canLecturerManage($user, $offering);
    }

    public function canAccessExamAttempts(User $user, OnlineExam $exam): bool
    {
        if (!$this->sameSchool($user, (int) $exam->school_id)) {
            return false;
        }

        if ($this->can($user, 'edit_all_online_exams')) {
            return true;
        }

        // The author may mark their own exam; other teachers require the
        // authoritative class/course assignment.
        return $this->ownsExam($user, $exam) || $this->canTeachExam($user, $exam);
    }

    public function canManageQuestion(User $user, OnlineExamQuestion $question): bool
    {
        $exam = $question->exam;
        if (!$exam) {
            return false;
        }

        if (!$this->can($user, 'manage_exam_questions')) {
            return false;
        }

        return $this->canManageExam($user, $exam);
    }

    public function canAccessSubmission(User $user, OnlineExamSubmission $submission): bool
    {
        if (!$this->sameSchool($user, (int) $submission->school_id)) {
            return false;
        }

        if ((int) $user->role_id === 7) {
            return (int) $submission->student_id === (int) $user->id;
        }

        $exam = $submission->exam;
        if (!$exam) {
            return false;
        }

        if ($this->can($user, 'view_exam_attempts') && $this->canAccessExamAttempts($user, $exam)) {
            return true;
        }

        return false;
    }

    public function canMarkAnswer(User $user, OnlineExamAnswer $answer): bool
    {
        if (!$this->can($user, 'mark_exam_answers')) {
            return false;
        }

        $submission = $answer->submission;
        if (!$submission) {
            return false;
        }

        return $this->canAccessSubmission($user, $submission);
    }

    public function canReviewProctoring(User $user, OnlineExamSubmission|OnlineExamProctoringEvent $target): bool
    {
        if (!$this->can($user, 'review_exam_proctoring')) {
            return false;
        }

        $submission = $target instanceof OnlineExamSubmission ? $target : $target->submission;
        if (!$submission) {
            return false;
        }

        return $this->canAccessSubmission($user, $submission);
    }

    public function teacherCanUseSubject(User $user, ?int $subjectId): bool
    {
        return $this->permissionService->teacherCanUseSubject($user, $subjectId);
    }
}
