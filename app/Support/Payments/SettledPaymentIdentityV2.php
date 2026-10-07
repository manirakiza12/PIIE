<?php

namespace App\Support\Payments;

/** Frozen V2 schema contract; V1 remains unchanged for migration rollback. */
final class SettledPaymentIdentityV2
{
    public const PROVIDERS = ['marzpay', 'stripe', 'flutterwave', 'pesapal'];
    public const ELIGIBLE_SQL = "LOWER(TRIM(status)) = 'paid' AND LOWER(TRIM(method)) IN ('marzpay','stripe','flutterwave','pesapal') AND LENGTH(TRIM(REPLACE(REPLACE(REPLACE(gateway_txn_id, CHAR(9), ''), CHAR(10), ''), CHAR(13), ''))) > 0";
    public const PROVIDER_SQL = 'CASE WHEN (' . self::ELIGIBLE_SQL . ') THEN LOWER(TRIM(method)) ELSE NULL END';
    public const TRANSACTION_SQL = 'CASE WHEN (' . self::ELIGIBLE_SQL . ') THEN gateway_txn_id ELSE NULL END';
}
