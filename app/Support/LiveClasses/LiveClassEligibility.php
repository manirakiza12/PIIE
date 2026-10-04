<?php

namespace App\Support\LiveClasses;

use App\Models\Enrollment;
use App\Models\CourseRegistration;
use App\Models\CourseOffering;
use App\Models\LiveClass;
use App\Models\Subject;
use Illuminate\Support\Collection;

/**
 * "Which students should be told about this class?" — used by the reminder
 * job to build its recipient list.
 *
 * Deliberately a separate, simpler read than
 * LiveClassController::canStudentAccessClass()/studentIndex(), which govern
 * actual access to a class and stay conservative about edge cases (a
 * subject's class compatibility, etc.) because getting access control wrong
 * has real consequences. Getting a reminder recipient list slightly broad or
 * narrow at the margins does not, so this only resolves the two common,
 * well-defined dimensions — class and session — the same way
 * LiveClassController::meetNow() already treats a subject's own class as the
 * effective class when the live class itself doesn't specify one.
 */
class LiveClassEligibility
{
    public static function eligibleStudentUserIds(LiveClass $liveClass): Collection
    {
        if ($liveClass->course_offering_id !== null) {
            $operationalOffering = CourseOffering::query()
                ->where('school_id', $liveClass->school_id)
                ->whereKey($liveClass->course_offering_id)
                ->whereIn('status', [CourseOffering::STATUS_OPEN, CourseOffering::STATUS_IN_PROGRESS])
                ->exists();
            if (! $operationalOffering || ! $liveClass->is_published || $liveClass->status === LiveClass::STATUS_CANCELLED) {
                return collect();
            }

            return self::confirmedOfferingStudentUserIds($liveClass);
        }

        $classId = $liveClass->class_id;

        if (!$classId && $liveClass->subject_id) {
            $subject = Subject::where('id', $liveClass->subject_id)
                ->where('school_id', $liveClass->school_id)
                ->first();

            if ($subject && $subject->class_id) {
                $classId = $subject->class_id;
            }
        }

        return Enrollment::where('school_id', $liveClass->school_id)
            ->when($classId, fn ($q) => $q->where('class_id', $classId))
            ->when($liveClass->academic_session_id, fn ($q) => $q->where('session_id', $liveClass->academic_session_id))
            ->pluck('user_id')
            ->unique()
            ->values();
    }

    /**
     * The students a Course-Offering-backed Live Class concerns, regardless of
     * whether the class can currently be joined.
     *
     * This exists because eligibleStudentUserIds() deliberately returns nothing
     * for a cancelled or unpublished class - correct for "may this student
     * open it", wrong for "who must be told". A cancellation notice resolved
     * through that method would reach nobody, which is precisely backwards:
     * the students who were told "your class is scheduled" are the ones who
     * need to hear "it is cancelled". A reschedule and a new recording have the
     * same requirement.
     *
     * Authority is unchanged and remains exactly one thing: a CONFIRMED
     * course_registrations row for THIS course_offering_id, joined to a role-7
     * student of the SAME school whose account is not disabled. Programme,
     * Cohort, Study Plan and legacy Class/Section membership are never
     * consulted, so a student who merely shares a Programme with the class is
     * never notified and never granted access.
     *
     * Returns an empty collection for a class that is not Offering-backed, so
     * callers must not use this to decide the legacy recipient list.
     */
    public static function confirmedOfferingStudentUserIds(LiveClass $liveClass): Collection
    {
        if ($liveClass->course_offering_id === null) {
            return collect();
        }

        return CourseRegistration::query()
            ->join('users as eligible_students', function ($join): void {
                $join->on('eligible_students.id', '=', 'course_registrations.student_id')
                    ->on('eligible_students.school_id', '=', 'course_registrations.school_id');
            })
            ->where('course_registrations.school_id', $liveClass->school_id)
            ->where('course_registrations.course_offering_id', $liveClass->course_offering_id)
            ->where('course_registrations.status', CourseRegistration::STATUS_CONFIRMED)
            ->where('eligible_students.school_id', $liveClass->school_id)
            ->where('eligible_students.role_id', 7)
            ->where(function ($query): void {
                $query->whereNull('eligible_students.account_status')
                    ->orWhere('eligible_students.account_status', '!=', 'disable');
            })
            ->pluck('eligible_students.id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }
}
