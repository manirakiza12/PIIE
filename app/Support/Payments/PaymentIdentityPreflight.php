<?php

namespace App\Support\Payments;

use Illuminate\Database\Connection;

/** Read-only inventory: no payloads, credentials, personal details or rewrites. */
final class PaymentIdentityPreflight
{
    public static function inspect(Connection $connection): array
    {
        $rows = $connection->table('application_payments')
            ->select(['id', 'school_id', 'admission_id', 'method', 'status', 'gateway_txn_id'])
            ->orderBy('id')->get();
        $eligible = []; $variants = []; $missing = []; $excluded = []; $providerVariants = [];
        $counts = []; $schools = [];
        foreach ($rows as $row) {
            $provider = strtolower(trim((string) $row->method, ' '));
            $online = in_array($provider, SettledPaymentIdentity::PROVIDERS, true);
            $paid = strtolower(trim((string) $row->status, ' ')) === 'paid';
            $identity = (string) $row->gateway_txn_id;
            $hasIdentity = trim(str_replace(["\t", "\n", "\r"], '', $identity), ' ') !== '';
            $counts[$provider] = ($counts[$provider] ?? 0) + 1;
            if ($online) { $schools[$provider][(int) $row->school_id] = true; }
            if ($online && ($row->method !== $provider || $identity !== trim($identity))) {
                $providerVariants[] = (int) $row->id;
            }
            if ($online && $paid && ! $hasIdentity) { $missing[] = (int) $row->id; }
            if (! $online || ! $paid || ! $hasIdentity) {
                $excluded[] = ['id' => (int) $row->id, 'reason' => ! $online ? 'manual_or_unrecognized_provider' : (! $paid ? 'not_paid' : 'missing_transaction_identity')];
                continue;
            }
            // Base64 preserves opaque UTF-8 bytes and avoids PHP numeric key coercion.
            $key = $provider . ':' . base64_encode($identity);
            $eligible[$key][] = (int) $row->id;
            $variantKey = $provider . ':' . base64_encode(strtolower(trim($identity)));
            $variants[$variantKey][$key] = array_merge($variants[$variantKey][$key] ?? [], [(int) $row->id]);
        }
        $duplicates = array_values(array_filter($eligible, fn ($ids) => count($ids) > 1));
        $variantGroups = array_values(array_map(fn ($group) => array_merge(...array_values($group)),
            array_filter($variants, fn ($group) => count($group) > 1)));
        $ambiguity = [];
        foreach ($schools as $provider => $ids) {
            $ambiguity[] = ['provider' => $provider, 'school_ids' => array_keys($ids),
                'account_namespace' => 'not_persisted; provider-wide protection follows existing settlement policy'];
        }
        return ['rows' => $rows->count(), 'provider_counts' => $counts,
            'duplicate_eligible_row_ids' => $duplicates, 'paid_online_missing_identity_row_ids' => $missing,
            'case_whitespace_variant_row_groups' => $variantGroups, 'noncanonical_identity_row_ids' => $providerVariants,
            'excluded_rows' => $excluded, 'provider_namespace_review' => $ambiguity];
    }
}
