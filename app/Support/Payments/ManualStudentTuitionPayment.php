<?php

namespace App\Support\Payments;

use App\Models\{AuditLog, StudentFeeManager, TuitionPayment, User};
use App\Support\Permissions\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Records an offline receipt that an authorized Finance user has verified. */
final class ManualStudentTuitionPayment
{
    public function record(User $actor, int $feeId, array $data): TuitionPayment
    {
        abort_unless(app(PermissionService::class)->allows($actor, 'finance.payments'), 403);

        return DB::transaction(function () use ($actor, $feeId, $data): TuitionPayment {
            DB::table('schools')->where('id', $actor->school_id)->lockForUpdate()->firstOrFail();
            $fee = StudentFeeManager::whereKey($feeId)->where('school_id', $actor->school_id)->lockForUpdate()->firstOrFail();
            User::whereKey($fee->student_id)->where('school_id', $fee->school_id)->firstOrFail();

            $unresolved = TuitionPayment::where('school_id', $fee->school_id)->where('fee_id', $fee->id)
                ->where(fn ($query) => $query->where('status', 'pending')
                    ->orWhere(fn ($query) => $query->where('status', 'failed')->whereNotNull('gateway_txn_id')))
                ->exists();
            if ($unresolved || in_array($fee->status, ['pending', 'processing'], true)) {
                $this->reject('Reconcile the existing online payment before recording a manual payment.');
            }

            $amount = DecimalAmount::minorUnits($data['amount'] ?? null);
            $paid = DecimalAmount::minorUnits($fee->paid_amount);
            $total = DecimalAmount::minorUnits($fee->total_amount);
            if ($amount === null || $amount <= 0 || $paid === null || $total === null || $amount > $total - $paid) {
                $this->reject('Enter a confirmed payment greater than zero and no larger than the remaining balance.');
            }

            $method = $data['method'] ?? null;
            if (! in_array($method, ['cash', 'bank_transfer', 'mobile_money', 'card', 'cheque', 'other'], true)) {
                $this->reject('Select the verified payment method.');
            }

            $externalReference = trim((string) ($data['reference'] ?? ''));
            $referenceKey = mb_strtolower(preg_replace('/\s+/u', '', $externalReference));
            if ($referenceKey === '') {
                $this->reject('Provide the receipt or transaction reference for this payment.');
            }
            $referenceHash = hash('sha256', $referenceKey);
            // The ledger reference is stable for the school's receipt, making a
            // repeated form post collide with its unique key instead of double-crediting.
            $ledgerReference = 'MANUAL-SCHOOL-' . $fee->school_id . '-' . $referenceHash;
            if (TuitionPayment::where('reference', $ledgerReference)->exists()) {
                throw ValidationException::withMessages([
                    'received_payment_reference' => 'This receipt or transaction reference has already been recorded. Check its existing allocation.',
                ]);
            }

            $currency = strtoupper(trim((string) DB::table('schools')->where('id', $fee->school_id)->value('school_currency')));
            if (! preg_match('/\A[A-Z]{3}\z/', $currency)) {
                $this->reject('Finance must configure the school currency before recording a payment.');
            }

            $payment = TuitionPayment::create([
                'school_id' => $fee->school_id,
                'fee_id' => $fee->id,
                'amount' => DecimalAmount::decimal($amount),
                'currency' => $currency,
                'method' => $method,
                'status' => 'paid',
                'reference' => $ledgerReference,
                'gateway_payload' => [
                    'kind' => 'manual-confirmed',
                    'receipt_reference' => $externalReference,
                    'reason' => trim((string) ($data['reason'] ?? '')),
                    'recorded_by' => $actor->id,
                ],
                'paid_at' => $data['paid_on'],
            ]);

            $newPaid = $paid + $amount;
            $fee->forceFill([
                'paid_amount' => DecimalAmount::decimal($newPaid),
                'status' => $newPaid >= $total ? 'paid' : 'unpaid',
                'payment_method' => $method,
            ])->save();

            AuditLog::record('create', 'Student Fees', "Confirmed manual payment for invoice #{$fee->id}.", [
                'school_id' => $fee->school_id,
                'event_type' => 'DATA',
                'record_type' => TuitionPayment::class,
                'record_id' => $payment->id,
                'new_values' => [
                    'fee_id' => $fee->id,
                    'amount' => DecimalAmount::decimal($amount),
                    'method' => $method,
                    'recorded_by' => $actor->id,
                ],
            ]);

            return $payment;
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['received_payment_amount' => $message]);
    }
}
