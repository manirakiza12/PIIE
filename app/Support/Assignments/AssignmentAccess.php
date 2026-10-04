<?php

namespace App\Support\Assignments;

use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\User;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use App\Support\CourseContent\CourseContentAccess;
use App\Support\Permissions\PermissionService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Who may see and change Course Offering Assignments.
 *
 * THIS CLASS INTRODUCES NO NEW AUTHORITY
 *
 * A LECTURER's access is decided by LecturerCourseOfferingAccess - the same
 * service the lecturer workspace, the roster and Course Content already use - so
 * a lecturer can only author and grade for Offerings they are legitimately
 * allocated to. Two lecturers who happen to teach the same Subject are still
 * two different allocations, and neither can grade the other's work.
 *
 * A STUDENT's access is a CONFIRMED course registration for that exact Offering,
 * in that exact tenant - the identical test Course Content and Live Classes use.
 * A student registered elsewhere, awaiting confirmation, or in another
 * institution is refused, and the refusal is indistinguishable from the record
 * not existing.
 *
 * Administration reuses the Course Offering capabilities, so there is no new
 * admin role to hand out and no way to hold "manage this Offering" without also
 * being able to author its assignments.
 */
class AssignmentAccess
{
    public const VIEW_CAPABILITY = 'course_assignments.view';

    public const MANAGE_CAPABILITY = 'course_assignments.manage';

    public function __construct(
        private readonly LecturerCourseOfferingAccess $lecturerAccess,
        private readonly CourseContentAccess $contentAccess,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * The Offering, resolved for this actor, or a 404.
     *
     * Delegates to the shared Course Content resolver so an Offering is looked
     * up by exactly one rule: a lecturer with an allocation is annotated with
     * theirs, and a staff member who is neither allocated nor an administrator
     * learns nothing about Offerings they were not given.
     */
    public function resolveOffering(User $actor, int $offeringId): CourseOffering
    {
        return $this->contentAccess->resolveOffering($actor, $offeringId);
    }

    /**
     * May this lecturer author and grade for this Offering?
     *
     * The Offering is re-resolved through the lecturer resolver rather than read
     * off the passed row, because `teachingActionsAllowed()` reads the
     * `my_allocation_id` attribute that only that resolver attaches. A caller
     * holding a plainly loaded Offering would otherwise be denied a permission
     * they genuinely hold - safe, but baffling.
     */
    public function canLecturerManage(User $actor, CourseOffering $offering): bool
    {
        if ((int) $actor->school_id !== (int) $offering->school_id) {
            return false;
        }

        if (! $this->permissions->allowsAny($actor, [self::MANAGE_CAPABILITY, self::VIEW_CAPABILITY])) {
            return false;
        }

        $resolved = $this->lecturerAccess->resolveForLecturer($actor, (int) $offering->id);

        return $resolved !== null && $this->lecturerAccess->teachingActionsAllowed($resolved);
    }

    /**
     * May this ADMINISTRATOR author or grade for this Offering?
     *
     * `course_assignments.manage` is deliberately NOT accepted here. The
     * role-based compatibility layer in PermissionService grants that capability
     * to EVERY teaching role, deliberately, so accepting it would make this
     * predicate answer "true" for an ordinary lecturer - even one holding no
     * allocation at all.
     *
     * That is a trap worth naming. This method means "the academic office", it is
     * public, and any future caller reaching for it would hand every teacher
     * admin rights over every Offering in the school. It is the same trap already
     * found and fixed in Course Content.
     *
     * So it delegates to administersOfferings() - the SAME question
     * assertCanManage() already asks to choose between the two paths. There is
     * therefore exactly one definition of "administers Course Offerings" in this
     * feature, and this predicate cannot drift away from it. Lecturer authority
     * is the allocation, checked in canLecturerManage().
     */
    public function canAdminManage(User $actor, CourseOffering $offering): bool
    {
        if ((int) $actor->school_id !== (int) $offering->school_id) {
            return false;
        }

        return $this->administersOfferings($actor);
    }

    /**
     * The single mutation gate. Administrators and lecturers are routed to
     * DIFFERENT checks and never unioned: a lecturer's authority is their
     * allocation on this exact Offering, so a lecturer who fails the allocation
     * check must not fall through to a path their role alone satisfies.
     */
    public function assertCanManage(User $actor, CourseOffering $offering): void
    {
        $allowed = $this->administersOfferings($actor)
            ? $this->canAdminManage($actor, $offering)
            : $this->canLecturerManage($actor, $offering);

        if (! $allowed) {
            throw new HttpException(403, 'You are not authorized to manage assignments for this Course Offering.');
        }
    }

    /**
     * Administration is asked with Course Offering capabilities rather than a
     * role number, for the same reason Course Content does it: the role-based
     * compatibility layer grants the shared capability to every teaching role,
     * so it cannot be used to tell an administrator from a lecturer.
     */
    private function administersOfferings(User $actor): bool
    {
        return $this->permissions->allowsAny($actor, [
            'academic.course_offering.manage',
            'academic.course_offering.lifecycle',
            'academic.course_offering.lecturer.manage',
        ]);
    }

    public function assertCanManageOrFail(User $actor, CourseOffering $offering): void
    {
        $this->assertCanManage($actor, $offering);
    }

    /** Confirmed registration for this exact Offering, in this exact tenant. */
    public function canStudentAccess(User $actor, CourseOffering $offering): bool
    {
        if ($this->permissions->isStaffRole((int) $actor->role_id)) {
            return false;
        }

        if (! $this->permissions->isActive($actor)) {
            return false;
        }

        if ((int) $actor->school_id !== (int) $offering->school_id) {
            return false;
        }

        return CourseRegistration::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->where('student_id', $actor->id)
            ->where('status', CourseRegistration::STATUS_CONFIRMED)
            ->exists();
    }

    /**
     * Resolve an assignment for a LECTURER, inside the Offering in the URL.
     *
     * A wrong Offering id is a 404 rather than a 403: the lecturer is entitled
     * to know the assignment does not exist at that address, and a 403 would
     * confirm it exists somewhere.
     */
    public function resolveForManager(User $actor, int $offeringId, int $assignmentId): Assignment
    {
        $offering = $this->resolveOffering($actor, $offeringId);
        $this->assertCanManage($actor, $offering);

        $assignment = Assignment::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereKey($assignmentId)
            ->first();

        if (! $assignment) {
            throw new HttpException(404, 'Assignment not found.');
        }

        return $assignment;
    }

    /**
     * Resolve an assignment for a STUDENT.
     *
     * All three are required: an Offering the student is confirmed on, and a
     * status the lecturer has actually released. A draft is a 404, not a 403,
     * so the student-facing pages cannot be used to discover what has not been
     * published.
     */
    public function resolveForStudent(User $actor, int $offeringId, int $assignmentId): Assignment
    {
        $offering = $this->resolveOffering($actor, $offeringId);

        if (! $this->canStudentAccess($actor, $offering)) {
            throw new HttpException(404, 'Assignment not found.');
        }

        $assignment = Assignment::query()
            ->where('school_id', $offering->school_id)
            ->where('course_offering_id', $offering->id)
            ->whereKey($assignmentId)
            ->first();

        if (! $assignment || ! AssignmentLifecycle::isOpenToStudents($assignment)) {
            throw new HttpException(404, 'Assignment not found.');
        }

        return $assignment;
    }

    /**
     * The students a lecturer may grade for this assignment: CONFIRMED
     * registrations on this exact Offering.
     *
     * Never Programme, Cohort, Study Plan or legacy Class membership - the same
     * eligibility Course Content and Live Classes use, so a student cannot be
     * added to a marking list by belonging to a cohort.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\CourseRegistration>
     */
    public function eligibleRegistrations(Assignment $assignment)
    {
        return CourseRegistration::query()
            ->where('school_id', $assignment->school_id)
            ->where('course_offering_id', $assignment->course_offering_id)
            ->where('status', CourseRegistration::STATUS_CONFIRMED)
            ->orderBy('student_id')
            ->get();
    }

    public function studentIdsFor(Assignment $assignment): array
    {
        return $this->eligibleRegistrations($assignment)
            ->map(fn ($registration) => (int) $registration->student_id)
            ->all();
    }
}
