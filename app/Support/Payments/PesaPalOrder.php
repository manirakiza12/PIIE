<?php

namespace App\Support\Payments;

final class PesaPalOrder
{
    public function __construct(
        public readonly string $orderTrackingId,
        public readonly string $merchantReference,
        public readonly string $redirectUrl,
        public readonly int $responseStatus,
    ) {}
}
