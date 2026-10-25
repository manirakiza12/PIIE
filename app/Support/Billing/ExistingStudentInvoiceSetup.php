<?php

namespace App\Support\Billing;

use App\Models\{AuditLog, FeeStructure, Session, StudentFeeManager, StudentProfile, User};
use App\Support\Payments\DecimalAmount;
use App\Support\Payments\ManualStudentTuitionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared invoice provisioning for the Add Existing Student and account completion flows. */
final class ExistingStudentInvoiceSetup
{
    public function create(User $actor, User $student, Session $session, FeeStructure $structure, int $expectedAmountMinor, ?array $confirmedPayment = null): StudentFeeManager
    {
        return DB::transaction(function () use ($actor, $student, $session, $structure, $expectedAmountMinor, $confirmedPayment) {
            $schoolId = (int) $actor->school_id;
            DB::table('schools')->where('id', $schoolId)->lockForUpdate()->firstOrFail();
            User::whereKey($student->id)->where('school_id', $schoolId)->where('role_id', 7)->lockForUpdate()->firstOrFail();
            $profile = StudentProfile::where('user_id', $student->id)->where('school_id', $schoolId)->lockForUpdate()->firstOrFail();
            $lockedSession = Session::whereKey($session->id)->where('school_id', $schoolId)->lockForUpdate()->firstOrFail();
            $currentStructure = FeeStructure::whereKey($structure->id)->where('school_id', $schoolId)->lockForUpdate()->firstOrFail();

            if ((int) $student->school_id !== $schoolId
                || (int) $lockedSession->academic_year_id !== (int) $session->academic_year_id
                || ! (bool) $currentStructure->is_mandatory
                || $currentStructure->class_id !== null
                || ($currentStructure->session_id && (int) $currentStructure->session_id !== (int) $lockedSession->id)
                || ($currentStructure->programme_id && (int) $currentStructure->programme_id !== (int) $profile->programme_id)) {
                throw ValidationException::withMessages(['fee_structure_id' => 'The selected programme fee or Finance Session context changed. Reload and try again.']);
            }

            if (StudentFeeManager::where('school_id', $schoolId)->where('student_id', $student->id)
                ->where('fee_structure_id', $currentStructure->id)->where('session_id', $session->id)->exists()) {
                throw ValidationException::withMessages(['fee_structure_id' => 'An invoice for this Fee Structure and Finance Session already exists. Open it to review or record a payment.']);
            }
            $configuredFeeMinor = DecimalAmount::minorUnits($currentStructure->amount);
            if ($configuredFeeMinor === null || $configuredFeeMinor <= 0 || $configuredFeeMinor !== $expectedAmountMinor) {
                throw ValidationException::withMessages(['fee_structure_id' => 'The configured programme fee changed. Reload and try again.']);
            }
            $amount = DecimalAmount::decimal($configuredFeeMinor);
            $invoice = StudentFeeManager::create([
                'title' => $currentStructure->name . ' — ' . $session->session_title,
                'amount' => $amount, 'discounted_price' => 0, 'total_amount' => $amount,
                'class_id' => 0, 'programme_id' => $currentStructure->programme_id ?? $profile->programme_id,
                'student_id' => $student->id, 'fee_structure_id' => $currentStructure->id,
                'parent_id' => $student->parent_id, 'payment_method' => 'unpaid',
                'paid_amount' => 0, 'status' => 'unpaid', 'school_id' => $schoolId,
                'session_id' => $session->id, 'timestamp' => now()->startOfDay()->timestamp,
            ]);

            if ($confirmedPayment && (DecimalAmount::minorUnits($confirmedPayment['amount'] ?? null) ?? 0) > 0) {
                app(ManualStudentTuitionPayment::class)->record($actor, (int) $invoice->id, [
                    'amount' => DecimalAmount::decimal(DecimalAmount::minorUnits($confirmedPayment['amount'])),
                    'paid_on' => $confirmedPayment['paid_on'] ?? null,
                    'method' => $confirmedPayment['method'] ?? null,
                    'reference' => $confirmedPayment['reference'] ?? null,
                    'reason' => $confirmedPayment['reason'] ?? null,
                ]);
            }

            AuditLog::record('create', 'Student Fees', "Created invoice #{$invoice->id} for existing student #{$student->id}.", [
                'school_id' => $schoolId, 'event_type' => 'DATA', 'record_type' => StudentFeeManager::class,
                'record_id' => $invoice->id,
                'new_values' => ['student_id' => $student->id, 'session_id' => $session->id, 'fee_structure_id' => $currentStructure->id],
            ]);
            return $invoice;
        });
    }
}
