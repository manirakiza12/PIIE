<?php

namespace App\Support\Payments;

/**
 * Evidence obtained by a SERVER-SIDE provider adapter, never from request input.
 * The school identifies the credentials used to verify the transaction.
 * Provider adapters normalize only documented fields; missing fields fail closed.
 */
final class VerifiedApplicationPayment
{
    public function __construct(
        public readonly int $paymentId,
        public readonly int $schoolId,
        public readonly string $provider,
        public readonly string $reference,
        public readonly string $transactionId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $status,
        public readonly array $payload,
        // Only adapters whose initiation contract has no tracking ID may bind
        // one from a verified, unambiguous merchant reference on first return.
        public readonly bool $canBindTransaction = false,
    ) {}
}
