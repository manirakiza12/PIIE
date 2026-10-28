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
     * Conservative automatic path. Finance's mandatory Fee Structures are the
     * authority; catalogue tuition amounts and payment claims are not fees.
     * Returns a human-owned blocker before creating any invoice if ambiguous.
     */
    public static function prepareMandatoryForStudent(User $student): array
    {
        return DB::transaction(function () use ($student) {
            $schoolId = (int) $student->school_id;
            $school = DB::table('schools')->where('id', $schoolId)->lockForUpdate()->firstOrFail();
            $student = User::whereKey($student->id)->where('school_id', $schoolId)->lockForUpdate()->firstOrFail();
            $blocked = fn ($code, $message) => ['created' => [], 'code' => $code, 'message' => $message];
            if ((int) $student->role_id !== 7 || $student->account_status === 'disable') {
                return $blocked('student_inactive', 'Admissions must confirm an active student account.');
            }
            $profile = StudentProfile::where('school_id', $schoolId)->where('user_id', $student->id)->lockForUpdate()->first();
            if (! $profile?->programme_id || ! DB::table('programmes')->where('school_id', $schoolId)->where('id', $profile->programme_id)->where('is_active', 1)->exists()) {
                return $blocked('programme_missing', 'Admissions must confirm the student programme; Finance must not infer it.');
            }
            $session = DB::table('sessions')->where('school_id', $schoolId)->where('id', $school->running_session)->where('status', 1)->lockForUpdate()->first();
            if (! $session || (! empty($session->academic_year_id) && (int) $session->academic_year_id !== (int) ($school->current_academic_year_id ?? 0))) {
                return $blocked('finance_session_invalid', 'Finance must select an active Finance Session in this institution matching the current academic year.');
            }
            $fees = FeeStructure::where('school_id', $schoolId)->where('is_mandatory', 1)->whereNull('class_id')
                ->where(fn ($q) => $q->whereNull('programme_id')->orWhere('programme_id', $profile->programme_id))
                ->where(fn ($q) => $q->whereNull('session_id')->orWhere('session_id', $session->id))->lockForUpdate()->get();
            if ($fees->isEmpty()) {
                return $blocked('mandatory_fee_missing', 'Finance must configure the approved mandatory programme fee for this Finance Session.');
            }
            foreach ($fees as $fee) {
                if ($fee->per_semester) {
                    return $blocked('semester_billing_ambiguous', 'Finance must explicitly prepare semester fees: no approved semester-to-invoice billing key is recorded.');
                }
                if ((\App\Support\Payments\DecimalAmount::minorUnits($fee->amount) ?? 0) <= 0 || trim((string) $fee->name) === '') {
                    return $blocked('mandatory_fee_invalid', 'Finance must confirm a named mandatory fee with a positive approved amount. Zero fees require a Finance decision.');
                }
            }
            $created = self::generateForStudent($student, (int) $profile->programme_id, $schoolId);
            foreach ($created as $invoice) {
                \App\Models\AuditLog::record('create', 'Student Fees', 'Mandatory invoice prepared automatically; no payment recorded.', [
                    'school_id' => $schoolId, 'event_type' => 'DATA', 'record_type' => StudentFeeManager::class,
                    'record_id' => $invoice->id, 'new_values' => ['source' => 'approved_mandatory_fee', 'fee_structure_id' => $invoice->fee_structure_id, 'session_id' => $session->id],
                ]);
            }
            return ['created' => $created, 'code' => null, 'message' => null];
        });
    }

    /**
     * Backfill a legacy student's current-session mandatory invoices from the
     * programme or class already assigned to their student record. This is
     * safe to call whenever the student opens Fee Manager: existing invoices
     * are detected and repeated requests do not create duplicates.
     */
    public static function ensureForStudent(User $student): array
    {
        $schoolId = (int) $student->school_id;
        // Newly automated accounts must not bypass a queued Finance blocker by
        // opening the older fee-manager backfill. Historical accounts retain it.
        if (\App\Support\Automation\AcceptedStudentSetup::available()) {
            $admissionId = DB::table('admissions')->where('school_id', $schoolId)
                ->where('automation_student_id', $student->id)->value('id');
            if ($admissionId) {
                app(\App\Support\Automation\AcceptedStudentSetup::class)->run($schoolId, (int) $admissionId);
                return [];
            }
        }
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
            ->whereNull('class_id')
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
