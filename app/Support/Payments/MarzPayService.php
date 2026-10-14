<?php

namespace App\Support\Payments;

use App\Models\PaymentMethods;
use App\Models\ApplicationPayment;
use App\Models\StudentFeeManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one place that talks to MarzPay's API (mobile money + card
 * collections). Credentials live per-school in `payment_methods`
 * (name='marzpay'), same as every other gateway in this app — not .env,
 * since a school admin configures this from the dashboard, not a deploy.
 *
 * Deliberately reads `school_id` directly here rather than going through
 * the existing get_payment_keys() helper: that helper ignores school_id
 * for its stripe/paytm branches (a pre-existing bug), and there's no
 * reason for new code to inherit it.
 */
class MarzPayService
{
    public const BASE_URL = 'https://wallet.wearemarz.com/api/v1';

    public static function keysFor(int $schoolId): ?array
    {
        $row = PaymentMethods::where('name', 'marzpay')
            ->where('school_id', $schoolId)
            ->where('status', 1)
            ->first();

        if (! $row) {
            return null;
        }

        $keys = json_decode((string) $row->payment_keys, true) ?: [];
        $mode = $row->mode === 'live' ? 'live' : 'test';

        $apiKey = $mode === 'live' ? ($keys['live_api_key'] ?? '') : ($keys['sandbox_api_key'] ?? '');
        $apiSecret = $mode === 'live' ? ($keys['live_api_secret'] ?? '') : ($keys['sandbox_api_secret'] ?? '');

        if (blank($apiKey) || blank($apiSecret)) {
            return null;
        }

        return [
            'api_key'    => $apiKey,
            'api_secret' => $apiSecret,
            'country'    => $keys['country'] ?: 'UG',
            'mode'       => $mode,
        ];
    }

    public static function isConfigured(int $schoolId): bool
    {
        return self::keysFor($schoolId) !== null;
    }

    /**
     * Starts a mobile money collection (USSD/SMS prompt pushed to the
     * customer's phone). Nothing is settled here — the caller must wait
     * for a webhook or poll getCollectionStatus() before marking anything
     * paid.
     *
     * @return array{ok: bool, transaction_uuid: ?string, status: ?string, error: ?string}
     */
    public static function initiateMobileMoneyCollection(
        int $schoolId,
        string $phoneNumber,
        float $amount,
        string $reference,
        string $description,
        ?string $callbackUrl,
        array $metadataContext
    ): array {
        $keys = self::keysFor($schoolId);

        if (! $keys) {
            return ['ok' => false, 'transaction_uuid' => null, 'status' => null, 'error' => 'MarzPay is not configured for this school.'];
        }

        $payload = [
            'amount'       => $amount,
            'phone_number' => $phoneNumber,
            'reference'    => $reference,
            'country'      => $keys['country'],
            'description'  => $description,
            'metadata'     => self::buildMetadata($metadataContext),
        ];

        if ($callbackUrl) {
            $payload['callback_url'] = $callbackUrl;
        }

        return self::postCollection($keys, $payload);
    }

    /**
     * Starts a card collection — same endpoint, redirects the customer to
     * a hosted gateway page instead of pushing a USSD prompt.
     *
     * @return array{ok: bool, redirect_url: ?string, transaction_uuid: ?string, error: ?string}
     */
    public static function initiateCardCollection(
        int $schoolId,
        float $amount,
        string $reference,
        string $description,
        string $callbackUrl,
        array $metadataContext
    ): array {
        $keys = self::keysFor($schoolId);

        if (! $keys) {
            return ['ok' => false, 'redirect_url' => null, 'transaction_uuid' => null, 'error' => 'MarzPay is not configured for this school.'];
        }

        $payload = [
            'amount'       => $amount,
            'method'       => 'card',
            'reference'    => $reference,
            'country'      => $keys['country'],
            'description'  => $description,
            'callback_url' => $callbackUrl,
            'metadata'     => self::buildMetadata($metadataContext),
        ];

        try {
            $response = Http::withBasicAuth($keys['api_key'], $keys['api_secret'])
                ->acceptJson()
                ->post(self::BASE_URL . '/collect-money', $payload);
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'redirect_url' => null, 'transaction_uuid' => null, 'error' => 'Could not reach MarzPay.'];
        }

        if (! $response->successful()) {
            Log::warning('MarzPay card collection failed', ['status' => $response->status(), 'body' => $response->body()]);

            return ['ok' => false, 'redirect_url' => null, 'transaction_uuid' => null, 'error' => $response->json('message') ?: 'Card payment could not be started.'];
        }

        return [
            'ok'               => true,
            'redirect_url'     => $response->json('data.redirect_url'),
            'transaction_uuid' => $response->json('data.transaction.uuid'),
            'error'            => null,
        ];
    }

    private static function postCollection(array $keys, array $payload): array
    {
        try {
            $response = Http::withBasicAuth($keys['api_key'], $keys['api_secret'])
                ->acceptJson()
                ->post(self::BASE_URL . '/collect-money', $payload);
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'transaction_uuid' => null, 'status' => null, 'error' => 'Could not reach MarzPay.'];
        }

        if (! $response->successful()) {
            Log::warning('MarzPay collection failed', ['status' => $response->status(), 'body' => $response->body()]);

            return ['ok' => false, 'transaction_uuid' => null, 'status' => null, 'error' => $response->json('message') ?: 'Payment could not be started.'];
        }

        return [
            'ok'               => true,
            'transaction_uuid' => $response->json('data.transaction.uuid'),
            'status'           => $response->json('data.transaction.status'),
            'error'            => null,
        ];
    }

    /**
     * Re-fetches a collection's status directly from MarzPay. Used both as
     * a manual "check now" fallback and — critically — by the webhook
     * handler, which never trusts the POSTed body's status on its own
     * (MarzPay's guide doesn't document a verifiable HMAC scheme, so the
     * same "never trust the callback alone, ask the provider" rule this
     * app already applies to Stripe/Flutterwave applies here too).
     */
    public static function getCollectionStatus(string $uuid, int $schoolId): ?array
    {
        $keys = self::keysFor($schoolId);

        if (! $keys) {
            return null;
        }

        try {
            $response = Http::withBasicAuth($keys['api_key'], $keys['api_secret'])
                ->acceptJson()
                ->get(self::BASE_URL . '/collect-money/' . $uuid);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json('data');
        return is_array($data) ? $data : null;
    }

    /**
     * Fetch the stored transaction using this school's credentials. Documented
     * GET /collect-money/{uuid} fields: transaction.uuid/reference/status and
     * collection.amount.raw/currency. Never substitute webhook claims, the
     * invoice amount, or the merchant reference for missing provider fields.
     */
    public static function verifyApplicationPayment(ApplicationPayment $payment): ?VerifiedApplicationPayment
    {
        if ($payment->method !== 'marzpay' || blank($payment->gateway_txn_id)) {
            return null;
        }
        $data = self::getCollectionStatus($payment->gateway_txn_id, (int) $payment->school_id);
        $transaction = $data['transaction'] ?? [];
        $amount = $data['collection']['amount'] ?? [];
        if (! is_array($transaction) || ! is_array($amount)
            || ! is_string($transaction['uuid'] ?? null) || blank($transaction['uuid'])
            || ! is_string($transaction['reference'] ?? null) || blank($transaction['reference'])
            || ! is_string($amount['currency'] ?? null) || blank($amount['currency'])
            || DecimalAmount::minorUnits($amount['raw'] ?? null) === null) {
            return null;
        }
        $status = match ($transaction['status'] ?? null) {
            'successful', 'completed' => 'paid',
            'failed', 'cancelled' => 'failed',
            default => 'pending',
        };
        return new VerifiedApplicationPayment(
            (int) $payment->id, (int) $payment->school_id, 'marzpay',
            $transaction['reference'], $transaction['uuid'], (string) $amount['raw'],
            $amount['currency'], $status, $data,
        );
    }

    /**
     * Fetch the provider's own record of the transaction an invoice recorded.
     *
     * The tuition counterpart of verifyApplicationPayment(). The decisive
     * choice: the lookup uses the invoice's OWN stored `gateway_reference`, never
     * a transaction id supplied by a caller. That alone stops an unauthenticated
     * webhook from steering the lookup at an unrelated transaction and then
     * crediting it to whichever invoice it happened to name.
     *
     * Returns null only when the provider could not be consulted or did not
     * return the documented fields — a TRANSIENT condition the caller should
     * retry. A provider that answers, but about a transaction that is not this
     * invoice's, returns a non-null array that simply fails to become evidence;
     * that is a DEFINITIVE answer and retrying it would loop forever.
     *
     * @return array{status: string, data: ?array} `status` is 'unverifiable'
     *         (retry later) or 'answered' (the provider replied).
     */
    public static function fetchTuitionTransaction(StudentFeeManager $fee): array
    {
        if ($fee->payment_method !== 'marzpay' || blank($fee->gateway_reference)) {
            // Nothing was ever started for this invoice, so there is nothing to
            // retry: a payment cannot appear later for a reference we never hold.
            return ['status' => 'answered', 'data' => null];
        }

        if (! self::isConfigured((int) $fee->school_id)) {
            // This tenant has no MarzPay credentials, so its callback is not
            // actionable. Definitive, not transient.
            return ['status' => 'answered', 'data' => null];
        }

        $data = self::getCollectionStatus((string) $fee->gateway_reference, (int) $fee->school_id);

        if (! is_array($data)) {
            return ['status' => 'unverifiable', 'data' => null];
        }

        $uuid = $data['transaction']['uuid'] ?? null;

        if (! is_string($uuid) || $uuid === '') {
            return ['status' => 'unverifiable', 'data' => null];
        }

        // MarzPay named a transaction but did not describe its collection — no
        // amount, no currency, or an amount we cannot parse exactly. That is a
        // provider-side defect rather than a statement about this invoice, so it
        // is transient and the caller should retry. Crediting on a partial
        // record is exactly the failure mode this boundary exists to prevent.
        $amount = $data['collection']['amount'] ?? null;
        $currency = is_array($amount) ? ($amount['currency'] ?? null) : null;

        if (! is_array($amount)
            || ! is_string($currency) || $currency === ''
            || DecimalAmount::minorUnits($amount['raw'] ?? null) === null) {
            return ['status' => 'unverifiable', 'data' => null];
        }

        return ['status' => 'answered', 'data' => $data];
    }

    /**
     * Fetch and validate the provider's record for a TUITION invoice.
     *
     * VerifiedTuitionPayment::fromProviderResponse() refuses to build evidence
     * unless the provider's returned uuid matches the invoice's stored
     * reference, so a provider response about a different transaction cannot be
     * laundered into this invoice either.
     */
    public static function verifyTuitionPayment(StudentFeeManager $fee): ?VerifiedTuitionPayment
    {
        $result = self::fetchTuitionTransaction($fee);

        if ($result['status'] !== 'answered') {
            return null;
        }

        return VerifiedTuitionPayment::fromProviderResponse($fee, 'marzpay', $result['data']);
    }

    /**
     * MarzPay expects metadata as an array of single-field objects
     * ({"context": "tuition"}, {"context_id": 123}), not one flat object —
     * see the integration guide's metadata examples throughout section 5.
     */
    private static function buildMetadata(array $context): array
    {
        $items = [];

        foreach ($context as $key => $value) {
            $items[] = [$key => $value];
        }

        return $items;
    }
}
