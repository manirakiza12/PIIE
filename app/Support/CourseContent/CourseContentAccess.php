<?php

namespace App\Support\CourseContent;

use App\Models\CourseOffering;
use App\Models\CourseRegistration;
use App\Models\User;
use App\Support\CourseOffering\LecturerCourseOfferingAccess;
use App\Support\Permissions\PermissionService;
use DomainException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Who may see and change Course Content. One place, delegating to the existing
 * authorities rather than restating them.
 *
 * THIS CLASS INTRODUCES NO NEW AUTHORITY
 *
 *  - A LECTURER's access is decided by LecturerCourseOfferingAccess, the same
 *    service the lecturer workspace, the roster and the attendance screens
 *    already use. A lecturer with no allocation on an Offering cannot author
 *    content for it here either, and a pre-start tester is treated identically in
 *    both places because it is literally the same call.
 *
 *  - An ADMINISTRATOR's access is decided by the existing Course Offering
 *    capabilities, so the RBAC matrix that governs Offerings governs their
 *    content too. No content-specific admin role is invented.
 *
 *  - A STUDENT's access is decided by a CONFIRMED course registration for that
 *    exact Offering, in that exact tenant - the same test Live Classes uses for
 *    participation, so a student can never be able to read content for a course
 *    they are not registered on.
 *
 * Every method also takes the Offering's own school_id from the Offering row
 * rather than from the request, so a mismatched id fails closed rather than
 * reaching a row it should not.
 */
class CourseContentAccess
{
    public const VIEW_CAPABILITY = 'course_content.view';

    public const MANAGE_CAPABILITY = 'course_content.manage';



    public function __construct(
        private LecturerCourseOfferingAccess $lecturerAccess,
        private PermissionService $permissions,
    ) {}

    /**
     * The Offering, resolved in the ACTOR's tenant, or a 404.
     *
     * A LECTURER's Offering is resolved through the existing
     * `resolveForLecturer()`, not through a plain lookup. That is not a
     * convenience: `teachingActionsAllowed()` reads the `my_allocation_id`
     * attribute that only `resolveForLecturer()` sets, so a Course Content
     * service that resolved the Offering itself would find no allocation and
     * deny a lecturer who legitimately holds one. Reusing the one resolver is
     * what keeps the two surfaces agreeing.
     *
     * There is no "is this actor a lecturer?" test here, and deliberately so.
     * Both resolvers are safe to attempt: a lecturer with an allocation gets the
     * annotated row, and everyone else falls through to a tenant-scoped lookup
     * against the Offering's OWN school_id - never a request field - and is then
     * refused by the authority check, which is evaluated on the same footing for
     * every role. Deciding by role number here would put "who is a lecturer" in a
     * second place, which the role-registry guard test exists to prevent.
     */
    public function resolveOffering(User $actor, int $offeringId): CourseOffering
    {
        $offering = $this->lecturerAccess->resolveForLecturer($actor, $offeringId);

        if ($offering) {
            return $offering;
        }

        // A staff member who is neither allocated nor an administrator is a 404,
        // exactly as in the lecturer workspace. "Does not exist" and "not yours"
        // must be the same answer, or a guessed id reveals what other staff are
        // teaching.
        //
        // Administrators are excluded from that rule, and must be: they hold no
        // lecturer allocation by definition, so applying it to them would lock
        // the academic office out of every Course Offering.
        if ($this->permissions->isStaffRole((int) $actor->role_id) && ! $this->administersOfferings($actor)) {
            throw new HttpException(404, 'Course Offering not found.');
        }

        $offering = CourseOffering::query()
            ->where('school_id', (int) $actor->school_id)
            ->whereKey($offeringId)
            ->first();

        if (! $offering) {
            throw new HttpException(404, 'Course Offering not found.');
        }

        return $offering;
    }

    /**
     * May this lecturer AUTHOR content for this Offering?
     *
     * Capability AND a current allocation on the Offering, which is the same pair
     * Live Class scheduling requires. Tenants stay apart because the allocation
     * lookup is tenant-scoped and the Offering was resolved in the actor's own
     * school.
     *
     * The allocation is resolved HERE rather than read off the Offering, because
     * `teachingActionsAllowed()` reads a `my_allocation_id` attribute that only
     * `resolveForLecturer()` attaches. A caller that loaded the Offering plainly
     * would otherwise be denied a permission they genuinely hold - a failure
     * that is safe but baffling. Re-resolving costs one indexed lookup and makes
     * the answer depend only on who the actor is, never on how the caller found
     * the row.
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

    /** May this lecturer at least LOOK at the builder without changing anything? */
    public function canLecturerView(User $actor, CourseOffering $offering): bool
    {
        if ((int) $actor->school_id !== (int) $offering->school_id) {
            return false;
        }

        return $this->lecturerAccess->rosterAvailable($offering)
            || $this->lecturerAccess->teachingActionsAllowed($offering);
    }

    /**
     * May this administrator change content for this Offering?
     *
     * Governed by the Course Offering capabilities that already control the
     * Offering itself, so there is no way to hold "manage this Offering" without
     * also being able to author its content, and no new admin grant to forget
     * to hand out.
     *
     * `course_content.manage` is deliberately NOT accepted here. The role-based
     * compatibility layer grants it to every teaching role, so accepting it
     * would make this method answer "true" for an ordinary lecturer - a
     * predicate whose whole purpose is to mean "academic office", and which
     * would be one careless caller away from granting teachers admin rights.
     * Lecturer authority is the allocation, checked in canLecturerManage().
     */
    public function canAdminManage(User $actor, CourseOffering $offering): bool
    {
        if ((int) $actor->school_id !== (int) $offering->school_id) {
            return false;
        }

        return $this->permissions->allowsAny($actor, [
            'academic.course_offering.manage',
            'academic.course_offering.lifecycle',
            'academic.course_offering.lecturer.manage',
        ]);
    }

    public function canAdminView(User $actor, CourseOffering $offering): bool
    {
        return (int) $actor->school_id === (int) $offering->school_id
            && $this->permissions->allowsAny($actor, [
                self::VIEW_CAPABILITY,
                'academic.course_offering.view',
            ]);
    }

    /**
     * May this student READ published content for this Offering?
     *
     * A CONFIRMED registration for that exact Offering, in that exact tenant.
     * "registered" (awaiting confirmation) is not enough: the brief's chain puts
     * Course Registration - and specifically a valid one - before the student,
     * and confirmed is what the Live Class participation rule uses.
     *
     * The actor must also not be staff and must be an active account. That is
     * expressed by asking `PermissionService` - which already owns the
     * distinction - rather than by comparing a role number here, so "is this a
     * student" is not answered a second time in a second place.
     */
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
     * Resolve a module for an actor, enforcing the caller's authority.
     *
     * @throws HttpException
     */
    public function resolveModuleForManager(User $actor, int $moduleId): \App\Models\CourseOfferingModule
    {
        $module = \App\Models\CourseOfferingModule::query()
            ->where('school_id', (int) $actor->school_id)
            ->whereKey($moduleId)
            ->first();

        if (! $module) {
            throw new HttpException(404, 'Module not found.');
        }

        $this->assertCanManage($actor, $this->resolveOffering($actor, (int) $module->course_offering_id));

        return $module;
    }

    public function resolveLessonForManager(User $actor, int $lessonId): \App\Models\CourseOfferingLesson
    {
        $lesson = \App\Models\CourseOfferingLesson::query()
            ->where('school_id', (int) $actor->school_id)
            ->whereKey($lessonId)
            ->first();

        if (! $lesson) {
            throw new HttpException(404, 'Lesson not found.');
        }

        $this->assertCanManage($actor, $this->resolveOffering($actor, (int) $lesson->course_offering_id));

        return $lesson;
    }

    /**
     * Resolve a lesson for a STUDENT, enforcing registration AND release.
     *
     * All three are required, and a lesson that fails any of them is not
     * "not found by accident" - it is refused here, so no view, no resource and
     * no progress route can be reached by guessing an id.
     *
     * THE PARENT MODULE IS CHECKED TOO, AND THAT IS NOT REDUNDANT
     *
     * A lesson is released only if BOTH it and its module are. The listing
     * already filters on the module, so without this check the two surfaces
     * would disagree: a module scheduled for next week would correctly be absent
     * from the list while every lesson inside it stayed open to anyone who
     * guessed an id. Release has to be decided in one place, and this is it.
     */
    public function resolveLessonForStudent(User $actor, int $lessonId): \App\Models\CourseOfferingLesson
    {
        $lesson = \App\Models\CourseOfferingLesson::query()
            ->where('school_id', (int) $actor->school_id)
            ->whereKey($lessonId)
            ->with('module')
            ->first();

        if (! $lesson) {
            throw new HttpException(404, 'Lesson not found.');
        }

        $offering = $this->resolveOffering($actor, (int) $lesson->course_offering_id);

        if (! $this->canStudentAccess($actor, $offering)) {
            throw new HttpException(404, 'Lesson not found.');
        }

        if (! $lesson->isReleasedToStudents()) {
            throw new HttpException(404, 'Lesson not found.');
        }

        if (! $lesson->module || ! $lesson->module->isReleasedToStudents()) {
            throw new HttpException(404, 'Lesson not found.');
        }

        return $lesson;
    }

    /**
     * Does this actor ADMINISTER Course Offerings, rather than teach one?
     *
     * This is the question that decides which authority applies, and it is
     * asked with CAPABILITIES rather than a role number. The distinction is
     * real and load-bearing:
     *
     * `course_content.manage` cannot answer it, because the role-based
     * compatibility layer in PermissionService grants that to every teaching
     * role - deliberately, so a lecturer is not stranded behind a permission
     * nobody has been asked to hand out. If that grant were used to tell an
     * administrator from a lecturer, a lecturer who failed the ALLOCATION
     * check would fall through to the admin path and be let in by their role
     * alone, which defeats the whole gate. So the question uses the academic
     * Course Offering capabilities, which only academic administrators hold.
     */
    private function administersOfferings(User $actor): bool
    {
        return $this->permissions->allowsAny($actor, [
            'academic.course_offering.manage',
            'academic.course_offering.lifecycle',
            'academic.course_offering.lecturer.manage',
        ]);
    }

    /**
     * The single gate every lecturer/admin mutation passes through.
     *
     * Administrators and lecturers are routed to DIFFERENT checks, never to
     * "either will do". A lecturer's authority is their allocation on this exact
     * Offering; an administrator's is the academic Course Offering capabilities.
     * Unioning them would let a lecturer in on their role as soon as the
     * allocation check failed, so the two paths are kept apart deliberately.
     *
     * @throws HttpException
     */
    public function assertCanManage(User $actor, CourseOffering $offering): void
    {
        $allowed = $this->administersOfferings($actor)
            ? $this->canAdminManage($actor, $offering)
            : $this->canLecturerManage($actor, $offering);

        if (! $allowed) {
            throw new HttpException(403, 'You are not authorized to manage content for this Course Offering.');
        }
    }

    /** Domain-flavoured variant, for service methods that do not return HTTP. */
    public function assertCanManageOrFail(User $actor, CourseOffering $offering): void
    {
        try {
            $this->assertCanManage($actor, $offering);
        } catch (HttpException $exception) {
            throw new DomainException('You are not authorized to manage content for this Course Offering.');
        }
    }
}
