<?php

namespace App\Support;

use App\Models\FeeStructure;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\StudentFeeManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Auto-generates a student's applicable fee invoices from mandatory
 * FeeStructure rows on admission conversion or student creation, and
 * backfills eligible legacy students when they open Fee Manager. It never
 * runs on a later Programme change, which remains a deliberate Finance action.
 *
 * Idempotent: keyed on (student_id, fee_structure_id, session_id) via
 * student_fee_managers.fee_structure_id, so calling this more than once for
 * the same student/session (e.g. a retried conversion request) never
 * creates duplicate invoices.
 */
class StudentFeeInvoiceGenerator
{
    /**
     * Backfill a legacy student's current-session mandatory invoices from the
     * programme or class already assigned to their student record. This is
     * safe to call whenever the student opens Fee Manager: existing invoices
     * are detected and repeated requests do not create duplicates.
     */
    public static function ensureForStudent(User $student): array
    {
        $schoolId = (int) $student->school_id;
        $sessionId = DB::table('schools')->where('id', $schoolId)->value('running_session');

        if (! $schoolId || ! $sessionId) {
            return [];
        }

        return DB::transaction(function () use ($student, $schoolId, $sessionId) {
            User::whereKey($student->id)->where('school_id', $schoolId)->lockForUpdate()->firstOrFail();

            $profile = StudentProfile::where('school_id', $schoolId)
                ->where('user_id', $student->id)
                ->first();

            if ($profile?->programme_id) {
                return self::generateForStudent($student, (int) $profile->programme_id, $schoolId);
            }

            $classId = Enrollment::where('school_id', $schoolId)
                ->where('user_id', $student->id)
                ->where('session_id', $sessionId)
                ->where('class_id', '>', 0)
                ->value('class_id');

            return $classId
                ? self::generateForClassBasedStudent($student, (int) $classId, $schoolId)
                : [];
        });
    }

    /**
     * @return StudentFeeManager[] newly created invoices (empty if none
     *                             applicable or all already exist)
     */
    public static function generateForStudent(User $student, ?int $programmeId, int $schoolId): array
    {
        if (! $programmeId) {
            return [];
        }

        $sessionId = DB::table('schools')->where('id', $schoolId)->value('running_session');

        $feeStructures = FeeStructure::where('school_id', $schoolId)
            ->where('is_mandatory', 1)
            ->where(function ($q) use ($programmeId) {
                $q->whereNull('programme_id')->orWhere('programme_id', $programmeId);
            })
            ->where(function ($q) use ($sessionId) {
                $q->whereNull('session_id')->orWhere('session_id', $sessionId);
            })
            ->get();

        $created = [];

        foreach ($feeStructures as $fee) {
            $alreadyInvoiced = StudentFeeManager::where('student_id', $student->id)
                ->where('fee_structure_id', $fee->id)
                ->where('session_id', $sessionId)
                ->exists();

            if ($alreadyInvoiced) {
                continue;
            }

            // Older Finance-created invoices predate fee_structure_id. Avoid
            // charging the same named obligation again during automatic
            // backfill, even when the legacy invoice was not structure-linked.
            if (StudentFeeManager::where('school_id', $schoolId)
                ->where('student_id', $student->id)
                ->where('session_id', $sessionId)
                ->whereNull('fee_structure_id')
                ->where('title', $fee->name)
                ->exists()) {
                continue;
            }

            $created[] = StudentFeeManager::create([
                'title'            => $fee->name,
                'total_amount'     => $fee->amount,
                'amount'           => $fee->amount,
                'discounted_price' => 0,
                // No class/section concept for a programme-based (HEI)
                // student — 0 is a sentinel meaning "not class-based";
                // class-filtered fee views will not surface this invoice,
                // but student-scoped views (by student_id) and the
                // programme_id-filtered admin view will.
                'class_id'         => 0,
                'programme_id'     => $programmeId,
                'student_id'       => $student->id,
                'fee_structure_id' => $fee->id,
                'parent_id'        => $student->parent_id,
                'payment_method'   => 'unpaid',
                'paid_amount'      => 0,
                'status'           => 'unpaid',
                'school_id'        => $schoolId,
                'session_id'       => $sessionId,
                'timestamp'        => strtotime(date('d-M-Y')),
            ]);
        }

        return $created;
    }

    /**
     * Class/section-track counterpart to generateForStudent() above — same
     * idempotent, create-only auto-invoicing, but sourced from a class-based
     * FeeStructure row (school's own class_id column, previously unused)
     * instead of a Programme one. Added so a student admitted through the
     * offline class/section admission forms (AdminController::
     * offlineAdmissionCreate/BulkCreate/ExcelCreate) gets fee invoices the
     * same way a Programme-track student already does, instead of never
     * getting any until a bursar remembers to run the separate manual
     * "mass invoice" action.
     *
     * @return StudentFeeManager[] newly created invoices
     */
    public static function generateForClassBasedStudent(User $student, ?int $classId, int $schoolId): array
    {
        if (! $classId) {
            return [];
        }

        $sessionId = DB::table('schools')->where('id', $schoolId)->value('running_session');

        $feeStructures = FeeStructure::where('school_id', $schoolId)
            ->where('is_mandatory', 1)
            ->where(function ($q) use ($classId) {
                $q->whereNull('class_id')->orWhere('class_id', $classId);
            })
            ->where(function ($q) use ($sessionId) {
                $q->whereNull('session_id')->orWhere('session_id', $sessionId);
            })
            ->get();

        $created = [];

        foreach ($feeStructures as $fee) {
            $alreadyInvoiced = StudentFeeManager::where('student_id', $student->id)
                ->where('fee_structure_id', $fee->id)
                ->where('session_id', $sessionId)
                ->exists();

            if ($alreadyInvoiced) {
                continue;
            }

            if (StudentFeeManager::where('school_id', $schoolId)
                ->where('student_id', $student->id)
                ->where('session_id', $sessionId)
                ->whereNull('fee_structure_id')
                ->where('title', $fee->name)
                ->exists()) {
                continue;
            }

            $created[] = StudentFeeManager::create([
                'title'            => $fee->name,
                'total_amount'     => $fee->amount,
                'amount'           => $fee->amount,
                'discounted_price' => 0,
                'class_id'         => $classId,
                'programme_id'     => null,
                'student_id'       => $student->id,
                'fee_structure_id' => $fee->id,
                'parent_id'        => $student->parent_id,
                'payment_method'   => 'unpaid',
                'paid_amount'      => 0,
                'status'           => 'unpaid',
                'school_id'        => $schoolId,
                'session_id'       => $sessionId,
                'timestamp'        => strtotime(date('d-M-Y')),
            ]);
        }

        return $created;
    }
}
