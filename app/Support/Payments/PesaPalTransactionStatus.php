<?php

namespace App\Support\Payments;

/** Authenticated provider observation, not permission to credit a payment. */
final class PesaPalTransactionStatus
{
    public function __construct(
        public readonly string $orderTrackingId,
        public readonly string $merchantReference,
        public readonly string $amount,
        public readonly string $currency,
        public readonly int $statusCode,
        public readonly string $paymentStatusDescription,
        public readonly ?string $paymentMethod,
        public readonly ?string $confirmationCode,
        public readonly int $responseStatus,
        public readonly ?array $error = null,
        public readonly ?string $description = null,
    ) {}

    public function classification(): string
    {
        $expected = [0 => 'INVALID', 1 => 'COMPLETED', 2 => 'FAILED', 3 => 'REVERSED'][$this->statusCode] ?? null;
        return $expected !== null && $expected === $this->paymentStatusDescription ? $expected : 'UNKNOWN';
    }
}
