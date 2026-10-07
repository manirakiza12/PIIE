<?php

namespace App\Support\Payments;

final class PesaPalToken
{
    public function __construct(private readonly string $value, public readonly int $expiresAt) {}

    public function bearer(): string { return $this->value; }

    public function __debugInfo(): array { return ['value' => '[redacted]', 'expiresAt' => $this->expiresAt]; }
}
