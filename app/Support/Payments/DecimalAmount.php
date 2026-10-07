<?php

namespace App\Support\Payments;

/** Exact two-decimal accounting for the existing DECIMAL(12,2) payment ledger. */
final class DecimalAmount
{
    public static function minorUnits($value): ?int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        // Never round an invalid provider amount into an acceptable payment.
        if (! preg_match('/\A(-?)([0-9]{1,12})(?:\.([0-9]{1,2}))?\z/', (string) $value, $matches)) {
            return null;
        }

        $minor = (int) $matches[2] * 100 + (int) str_pad($matches[3] ?? '', 2, '0');
        return $matches[1] === '-' ? -$minor : $minor;
    }

    public static function decimal(int $minorUnits): string
    {
        return intdiv($minorUnits, 100) . '.' . str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }
}
