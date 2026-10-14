<?php

namespace App\Support\Payments;

use App\Models\AuditLog;
use App\Models\StudentFeeManager;
use Illuminate\Support\Facades\DB;

/**
 * Provider-neutral, atomic TUITION settlement boundary.
 *
 * The tuition counterpart of {@see ApplicationPaymentSettlement}, and the fix
 * for the vulnerability that let an unauthenticated caller settle an arbitrary
 * invoice: the webhook used to take an invoice id straight from the request
 * body and mark it paid. Nothing bound the posted transaction to the invoice.
 *
 * What this boundary enforces, in order:
 *
 *   1. Identity   the provider evidence names the exact invoice being settled
 *                  (see VerifiedTuitionPayment::fromProviderResponse).
 *   2. Ownership  the invoice is re-read with a locking read inside a
 *                  transaction, under its own school.
 *   3. Amount     the provider amount matches the invoice total EXACTLY, in
 *                  minor units. No floats, no rounding, no "close enough".
 *   4. Currency   the provider always states one, and it must match the
 *                  tenant's configured currency whenever that is configured.
 *   5. Status     only a provider-confirmed success credits money; a confirmed
 *                  failure marks the invoice failed; anything else leaves it
 *                  pending and writes nothing.
 *
 * Anything short of that returns REJECTED and changes nothing.
 */
final class TuitionFeeSettlement
{
    public const SETTLED        = 'settled';
    public const ALREADY_SETTLED = 'already_settled';
    public const FAILED        = 'failed';
    public const REJECTED      = 'rejected';
    public const PENDING       = 'pending';

    /** Statuses an invoice may be settled FROM. */
    private const SETTLEABLE_FROM = ['unpaid', 'processing', 'failed'];

    public static function apply(VerifiedTuitionPayment $verified): string
    {
        return DB::transaction(function () use ($verified) {
            // Current locking read, not a repeatable-read snapshot: the decision
            // below has to be made against the row as it is now.
            $fee = StudentFeeManager::whereKey($verified->feeId)
                ->where('school_id', $verified->schoolId)
                ->lockForUpdate()
                ->first();

            // 1 + 2. The evidence must name this invoice, this tenant, and this
            // provider's own stored transaction. `gateway_reference` is the only
            // identifier the app persisted for the transaction, so a callback
            // that does not already carry it can never be acting on this invoice.
            if (! $fee
                || $fee->payment_method !== $verified->provider
                || blank($fee->gateway_reference)
                || ! hash_equals((string) $fee->gateway_reference, $verified->transactionId)) {
                return self::REJECTED;
            }

            // An already-settled invoice is never re-credited, and a provider
            // that now says "failed" never un-settles money already taken.
            if ($fee->status === 'paid') {
                return $verified->status === 'paid' ? self::ALREADY_SETTLED : self::REJECTED;
            }

            if (! in_array((string) $fee->status, self::SETTLEABLE_FROM, true)) {
                return self::REJECTED;
            }

            // 3. Exact amount. DecimalAmount::minorUnits() returns null for
            // anything it cannot parse exactly, so a malformed provider amount
            // can never be rounded into an acceptable payment.
            $expected = DecimalAmount::minorUnits($fee->total_amount);
            $actual   = DecimalAmount::minorUnits($verified->amount);
            if ($expected === null || $expected <= 0 || $actual !== $expected) {
                return self::REJECTED;
            }

            // 4. The provider must always state a currency. Equality is enforced
            // whenever the tenant has configured one; where it has not, the value
            // is recorded for audit but cannot be compared. See the report: a
            // per-invoice currency column is the outstanding fix for that gap.
            if (blank($verified->currency)) {
                return self::REJECTED;
            }
            $expectedCurrency = self::tenantCurrency($fee);
            if ($expectedCurrency !== null
                && strtoupper($expectedCurrency) !== strtoupper($verified->currency)) {
                return self::REJECTED;
            }

            // 5. Only a confirmed success credits money.
            if ($verified->status === 'pending') {
                return self::PENDING;
            }

            if ($verified->status === 'failed') {
                if ($fee->status !== 'failed') {
                    $fee->update([
                        'status'         => 'failed',
                        'gateway_payload' => $verified->payload,
                    ]);
                    AuditLog::record('update', 'Fees', "Tuition fee #{$fee->id} reported failed by {$verified->provider}.", [
                        'event_type' => 'DATA', 'record_type' => StudentFeeManager::class,
                        'record_id' => $fee->id, 'school_id' => $fee->school_id,
                    ]);
                }

                return self::FAILED;
            }

            $fee->update([
                'status'          => 'paid',
                'paid_amount'     => $fee->total_amount,
                'payment_method'  => $verified->provider,
                'gateway_payload' => $verified->payload,
            ]);

            AuditLog::record('update', 'Fees', "Tuition fee #{$fee->id} verified and settled via {$verified->provider}.", [
                'event_type' => 'DATA', 'record_type' => StudentFeeManager::class,
                'record_id' => $fee->id, 'school_id' => $fee->school_id,
                'old_values' => ['status' => null, 'paid_amount' => null],
                'new_values' => [
                    'status'      => 'paid',
                    'paid_amount' => (int) $fee->paid_amount,
                    'currency'    => $verified->currency,
                ],
            ]);

            return self::SETTLED;
        }, 3);
    }

    /**
     * The tenant's configured currency code, or null when it has not set one.
     *
     * `schools.school_currency` is written from the payment-settings dropdown,
     * which submits the ISO `code` (see admin/payment_settings/key_settings).
     * Anything that is not a plausible ISO code is treated as "not configured"
     * rather than silently compared against — a tenant that has selected a
     * currency symbol by mistake should not have every payment rejected.
     */
    private static function tenantCurrency(StudentFeeManager $fee): ?string
    {
        // Guarded for the same reason as the rest of this codebase's schema
        // probes (see AcademicStructureController, ProgrammeCatalogueSchema):
        // `schools.school_currency` is a restored compatibility column, so an
        // install predating it must still be able to settle a payment.
        if (! \Illuminate\Support\Facades\Schema::hasColumn('schools', 'school_currency')) {
            return null;
        }

        $configured = \Illuminate\Support\Facades\DB::table('schools')
            ->where('id', $fee->school_id)
            ->value('school_currency');

        $configured = is_string($configured) ? trim($configured) : '';

        return preg_match('/\A[A-Za-z]{3}\z/', $configured) ? $configured : null;
    }
}