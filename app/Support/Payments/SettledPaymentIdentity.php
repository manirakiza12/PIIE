<?php

namespace App\Support\Payments;

/** Frozen V1 schema contract. Add future providers through a new migration. */
final class SettledPaymentIdentity
{
    public const INDEX = 'application_payments_settled_provider_txn_unique';
    public const PROVIDERS = ['marzpay', 'stripe', 'flutterwave'];

    // Normalize labels, never opaque transaction IDs. Whitespace-only legacy
    // identities remain NULL; nonblank IDs retain every original byte.
    public const ELIGIBLE_SQL = "LOWER(TRIM(status)) = 'paid' AND LOWER(TRIM(method)) IN ('marzpay','stripe','flutterwave') AND LENGTH(TRIM(REPLACE(REPLACE(REPLACE(gateway_txn_id, CHAR(9), ''), CHAR(10), ''), CHAR(13), ''))) > 0";
    public const PROVIDER_SQL = 'CASE WHEN (' . self::ELIGIBLE_SQL . ') THEN LOWER(TRIM(method)) ELSE NULL END';
    public const TRANSACTION_SQL = 'CASE WHEN (' . self::ELIGIBLE_SQL . ') THEN gateway_txn_id ELSE NULL END';
}
