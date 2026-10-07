<?php

namespace App\Support\Payments;

use App\Models\Admission;
use App\Models\Applicant;
use App\Models\ApplicationPayment;
use App\Models\AuditLog;
use App\Support\Admissions\ApplicantNotifier;
use App\Support\Admissions\ApplicationFee;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

/** Provider-neutral, atomic application-payment settlement boundary. */
final class ApplicationPaymentSettlement
{
    public const SETTLED = 'settled';
    public const ALREADY_SETTLED = 'already_settled';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';
    public const PENDING = 'pending';

    public static function apply(VerifiedApplicationPayment $verified): string
    {
        try {
            return self::settle($verified);
        } catch (QueryException $exception) {
            // DB::transaction has already rolled back payment, fee state and
            // after-commit callbacks. Only our identity index means a competing
            // credit; unrelated database faults must retain normal error handling.
            $info = $exception->errorInfo ?? [];
            $message = (string) ($info[2] ?? '');
            $mysqlIdentityConflict = (int) ($info[1] ?? 0) === 1062
                && str_contains($message, SettledPaymentIdentity::INDEX);
            $sqliteIdentityConflict = in_array((int) ($info[1] ?? 0), [19, 2067], true)
                && str_contains($message, 'UNIQUE constraint failed: application_payments.settled_provider, application_payments.settled_provider_txn_id');
            if ($mysqlIdentityConflict || $sqliteIdentityConflict) {
                return self::REJECTED;
            }
            throw $exception;
        }
    }

    private static function settle(VerifiedApplicationPayment $verified): string
    {
        return DB::transaction(function () use ($verified) {
            // Database uniqueness complements this validation and lock protocol.
            // Lock this provider's existing payment rows in a consistent order,
            // including across schools, before checking transaction reuse. This
            // also serializes first-time binding (Flutterwave assigns its ID on
            // verification). A later attempt must acquire these same locks and
            // perform a current read before it can credit the transaction again.
            $payments = ApplicationPayment::where('method', $verified->provider)
                ->select(['id', 'method', 'reference', 'gateway_txn_id', 'status'])
                ->orderBy('id')->lockForUpdate()->get();
            // Fetch the target's full row with a current locking read; do not
            // load every historical provider payload into memory or establish
            // a repeatable-read snapshot before locking the admission.
            $payment = ApplicationPayment::where('method', $verified->provider)
                ->whereKey($verified->paymentId)->lockForUpdate()->first();

            if (! $payment || $payment->method !== $verified->provider || (int) $payment->school_id !== $verified->schoolId
                || blank($verified->provider) || blank($verified->reference)
                || blank($verified->transactionId) || $payment->reference !== $verified->reference
                || (filled($payment->gateway_txn_id) && $payment->gateway_txn_id !== $verified->transactionId)
                || (blank($payment->gateway_txn_id) && (! $verified->canBindTransaction
                    || $payments->contains(fn ($other) => $other->id !== $payment->id && $other->reference === $verified->reference)))) {
                return self::REJECTED;
            }

            $admission = Admission::where('id', $payment->admission_id)
                ->where('school_id', $verified->schoolId)->lockForUpdate()->first();
            if (! $admission || ($payment->applicant_id !== null && (
                (int) $payment->applicant_id !== (int) $admission->applicant_id
                || ! Applicant::where('id', $payment->applicant_id)->where('school_id', $verified->schoolId)->exists()
            ))) {
                return self::REJECTED;
            }

            $expected = DecimalAmount::minorUnits($payment->amount);
            $actual = DecimalAmount::minorUnits($verified->amount);
            if ($expected === null || $expected <= 0 || $actual !== $expected
                || blank($payment->currency) || blank($verified->currency)
                || strtoupper($payment->currency) !== strtoupper($verified->currency)) {
                return self::REJECTED;
            }

            if ($payments->contains(fn ($other) => $other->id !== $payment->id
                && $other->gateway_txn_id === $verified->transactionId
                && $other->status === ApplicationPayment::STATUS_PAID)) {
                return self::REJECTED;
            }

            if ($payment->status === ApplicationPayment::STATUS_PAID) {
                return $verified->status === 'paid' ? self::ALREADY_SETTLED : self::REJECTED;
            }
            if (! in_array($payment->status, [ApplicationPayment::STATUS_PENDING, ApplicationPayment::STATUS_FAILED], true)) {
                return self::REJECTED;
            }
            if (! in_array($verified->status, ['paid', 'failed'], true)) {
                return self::PENDING;
            }

            if ($verified->status === 'failed') {
                $payment->update(['status' => ApplicationPayment::STATUS_FAILED, 'gateway_payload' => $verified->payload]);
                ApplicationFee::refreshStatus($admission);
                return self::FAILED;
            }

            $payment->update([
                'status' => ApplicationPayment::STATUS_PAID,
                'gateway_txn_id' => $verified->transactionId,
                'gateway_payload' => $verified->payload,
                'paid_at' => now(),
            ]);
            ApplicationFee::refreshStatus($admission);
            AuditLog::record('create', 'Admissions', "Application payment #{$payment->id} verified via {$verified->provider}.", [
                'event_type' => 'DATA', 'record_type' => ApplicationPayment::class,
                'record_id' => $payment->id, 'school_id' => $payment->school_id,
            ]);

            // Only the transaction that changed pending/failed -> paid notifies.
            // Keep mail outside locks, and never notify before the outer commit.
            DB::afterCommit(fn () => ApplicantNotifier::paymentReceived($admission, $payment));
            return self::SETTLED;
        }, 3);
    }
}
