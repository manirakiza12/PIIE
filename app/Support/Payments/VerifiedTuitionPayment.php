<?php

namespace App\Support\Payments;

use App\Models\StudentFeeManager;

/**
 * Evidence obtained by a SERVER-SIDE provider adapter for a TUITION invoice,
 * never from request input.
 *
 * The counterpart of {@see VerifiedApplicationPayment}, and built to the same
 * rule: a provider adapter normalises only documented fields, and any missing
 * field makes the whole object unconstructable so the settlement boundary fails
 * closed rather than crediting a payment it cannot fully verify.
 *
 * Note on what binds this evidence to an invoice. Unlike an ApplicationPayment,
 * a StudentFeeManager has no `reference` column — the only identifier the app
 * itself persisted for the transaction is `gateway_reference` (written at
 * payment start from MarzPay's transaction UUID). That is therefore the sole
 * binding anchor available without a schema change, and it is why
 * {@see TuitionFeeSettlement} refuses any callback whose posted transaction
 * UUID does not already sit on the invoice being settled.
 */
final class VerifiedTuitionPayment
{
    public function __construct(
        public readonly int $feeId,
        public readonly int $schoolId,
        public readonly string $provider,
        public readonly string $reference,
        public readonly string $transactionId,
        public readonly string $amount,
        public readonly string $currency,
        /** 'paid' | 'failed' | 'pending' — normalised from the provider's own vocabulary. */
        public readonly string $status,
        public readonly array $payload,
    ) {}

    /**
     * Convenience constructor used by the MarzPay adapter.
     *
     * Refuses to build anything unless every documented field is present and of
     * the right shape, so a truncated or renamed provider response produces
     * null evidence rather than an object with holes in it.
     */
    public static function fromProviderResponse(
        StudentFeeManager $fee,
        string $provider,
        ?array $data,
    ): ?self {
        if (! is_array($data)) {
            return null;
        }

        $transaction = $data['transaction'] ?? null;
        $amount      = $data['collection']['amount'] ?? null;

        if (! is_array($transaction) || ! is_array($amount)) {
            return null;
        }

        $uuid      = $transaction['uuid'] ?? null;
        $reference = $transaction['reference'] ?? null;
        $raw       = $amount['raw'] ?? null;
        $currency  = $amount['currency'] ?? null;

        // Every one of these is fail-closed: a provider response that omits any
        // of them cannot be shown to describe THIS invoice, so it is discarded.
        if (! is_string($uuid) || blank($uuid)
            || ! is_string($reference) || blank($reference)
            || ! is_string($currency) || blank($currency)
            || DecimalAmount::minorUnits($raw) === null) {
            return null;
        }

        // The provider must be describing the transaction we asked about.
        if (! hash_equals((string) $fee->gateway_reference, $uuid)) {
            return null;
        }

        $status = match ($transaction['status'] ?? null) {
            'successful', 'completed' => 'paid',
            'failed', 'cancelled'    => 'failed',
            default                  => 'pending',
        };

        return new self(
            (int) $fee->id,
            (int) $fee->school_id,
            $provider,
            $reference,
            $uuid,
            (string) $raw,
            $currency,
            $status,
            $data,
        );
    }
}