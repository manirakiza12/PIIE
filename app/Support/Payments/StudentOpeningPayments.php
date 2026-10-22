<?php

namespace App\Support\Payments;

use App\Models\{AuditLog, StudentFeeManager, StudentOpeningPayment, TuitionPayment, User};
use App\Support\Permissions\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudentOpeningPayments
{
    public function change(User $actor, int $feeId, array $data): void
    {
        abort_unless(app(PermissionService::class)->allows($actor, 'finance.payments'), 403);
        DB::transaction(function () use ($actor, $feeId, $data) {
            // Serialize receipt allocation within an institution, including different invoices.
            DB::table('schools')->where('id', $actor->school_id)->lockForUpdate()->firstOrFail();
            $fee = StudentFeeManager::whereKey($feeId)->where('school_id', $actor->school_id)->lockForUpdate()->firstOrFail();
            abort_unless(\App\Models\StudentProfile::where('user_id', $fee->student_id)->where('school_id', $fee->school_id)
                ->where('was_existing_at_portal_creation', true)->exists(), 404);
            $unresolved = TuitionPayment::where('school_id', $fee->school_id)->where('fee_id', $fee->id)
                ->where(function ($q) { $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'failed')->whereNotNull('gateway_txn_id')); })->exists();
            if ($unresolved || in_array($fee->status, ['pending', 'processing'], true)) {
                $this->reject('Reconcile pending payment claims and provider orders before changing prior payments.');
            }
            $paid = DecimalAmount::minorUnits($fee->paid_amount);
            $total = DecimalAmount::minorUnits($fee->total_amount);
            $opening = DecimalAmount::minorUnits($fee->opening_paid_amount);
            if ($paid === null || $total === null || $opening === null || $opening < 0 || $paid < $opening) {
                $this->reject('Finance must reconcile this invoice before recording prior payments.');
            }
            if ($data['action'] === 'record') {
                if (! in_array($data['method'] ?? null, ['cash', 'bank_transfer', 'mobile_money', 'card', 'cheque', 'other'], true)) {
                    $this->reject('Select the verified payment method.');
                }
                $reference = trim($data['reference']);
                $hash = hash('sha256', mb_strtolower(preg_replace('/\s+/u', '', $reference)));
                if (StudentOpeningPayment::where('school_id', $fee->school_id)->where('reference_hash', $hash)->exists()) {
                    $this->reject('This receipt reference has already been recorded. Check its original allocation; do not enter it again.');
                }
                $amount = DecimalAmount::minorUnits($data['amount']);
                if ($amount === null || $amount <= 0 || $paid + $amount > $total) {
                    $this->reject('Prior payment must be greater than zero and no larger than the unpaid invoice balance.');
                }
                $entry = StudentOpeningPayment::create(['school_id' => $fee->school_id, 'fee_id' => $fee->id,
                    'amount' => DecimalAmount::decimal($amount), 'paid_on' => $data['paid_on'], 'method' => $data['method'], 'reference' => $reference,
                    'reference_hash' => $hash, 'reason' => $data['reason'], 'approved_by' => $actor->id, 'approved_by_name' => $actor->name]);
            } else {
                $entry = StudentOpeningPayment::whereKey($data['opening_payment_id'])->where('school_id', $fee->school_id)
                    ->where('fee_id', $fee->id)->lockForUpdate()->firstOrFail();
                if ($entry->reversed_at) { $this->reject('This prior payment has already been reversed.'); }
                $amount = -(DecimalAmount::minorUnits($entry->amount) ?? 0);
                if ($opening + $amount < 0 || $paid + $amount < 0) { $this->reject('Finance must reconcile this invoice before reversal.'); }
                $entry->update(['reversed_at' => now(), 'reversed_by' => $actor->id,
                    'reversed_by_name' => $actor->name, 'reversal_reason' => $data['reason']]);
            }
            $newPaid = $paid + $amount;
            $fee->forceFill(['paid_amount' => DecimalAmount::decimal($newPaid),
                'opening_paid_amount' => DecimalAmount::decimal($opening + $amount),
                'status' => $newPaid >= $total ? 'paid' : 'unpaid'])->save();
            AuditLog::record('update', 'Student Fees', ucfirst($data['action']) . ' prior payment on invoice #' . $fee->id,
                ['school_id' => $fee->school_id, 'record_type' => StudentOpeningPayment::class, 'record_id' => $entry->id,
                    'old_values' => ['paid_amount' => DecimalAmount::decimal($paid)], 'new_values' => ['paid_amount' => DecimalAmount::decimal($newPaid)]]);
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['opening_payment' => $message]);
    }
}
