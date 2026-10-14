<?php

namespace App\Http\Controllers;

use App\Models\ApplicationPayment;
use App\Models\HostelFee;
use App\Models\StudentFeeManager;
use App\Support\Payments\ApplicationPaymentSettlement;
use App\Support\Payments\MarzPayService;
use App\Support\Payments\TuitionFeeSettlement;
use App\Support\Payments\VerifiedTuitionPayment;
use App\Support\Subscriptions\SubscriptionActivator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MarzPay's callback_url target — one shared endpoint for every payment
 * flow (tuition, hostel, admissions, subscription). Sits outside every
 * auth group (see routes/web.php) and VerifyCsrfToken::$except, since
 * MarzPay's server is the caller, not a logged-in user.
 *
 * MarzPay's guide doesn't document a verifiable HMAC scheme for
 * callback_url payloads (only "optionally verify... if enabled"), so
 * rather than half-implement signature checking against an unspecified
 * algorithm, this always re-fetches the transaction directly from MarzPay
 * before trusting it — the same "never trust the callback alone" rule
 * Applicant\PaymentController already applies to Stripe/Flutterwave.
 *
 * TUITION specifically is settled through {@see TuitionFeeSettlement}, and the
 * body of this controller deliberately does NOT decide the outcome for it:
 *
 *  - The invoice is located by the posted `context_id`, but never verified
 *    against the posted `transaction.uuid`. A caller can therefore name any
 *    invoice, but the transaction actually examined is always the one that
 *    invoice itself recorded in `gateway_reference` at payment start. Naming
 *    someone else's invoice can no longer settle it with someone else's
 *    successful payment — that was the vulnerability this replaced.
 *  - Amount, currency, ownership, status and re-delivery are all decided by
 *    the settlement boundary, under a locking read inside a transaction.
 *
 * The admissions (application) path is unchanged and remains independently
 * hardened by ApplicationPaymentSettlement.
 */
class MarzPayWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Dashboard-registered webhooks wrap the payload under `data`;
        // direct callback_url posts (what this app sends) don't.
        $body = $request->input('data') ?: $request->all();
        if (! is_array($body)) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $eventType = $body['event_type'] ?? null;
        $transactionUuid = $body['transaction']['uuid'] ?? null;
        $metadata = $this->flattenMetadata(is_array($body['metadata'] ?? null) ? $body['metadata'] : []);

        $context = $metadata['context'] ?? null;
        $contextId = $metadata['context_id'] ?? null;
        if (! is_string($eventType) || blank($eventType) || ! is_string($transactionUuid) || blank($transactionUuid)
            || ! is_string($context) || (! is_int($contextId) && ! is_string($contextId))
            || ! ctype_digit((string) $contextId) || (int) $contextId <= 0) {
            Log::warning('MarzPay webhook missing required fields');
            return response()->json(['status' => 'ignored'], 200);
        }
        $schoolId = $this->resolveSchoolId($context, $contextId);
        if (! $schoolId) {
            return response()->json(['status' => 'ignored'], 200);
        }

        // Tuition first, and on its own boundary. It is the one flow whose
        // invoice the attacker-supplied `context_id` selects, so it gets the
        // strictest treatment: the provider transaction consulted is the one
        // stored on that invoice, never the one named in the request body.
        if ($context === 'tuition') {
            return $this->settleTuition((int) $contextId, (int) $schoolId);
        }

        if ($context === 'application') {
            $payment = ApplicationPayment::where('school_id', $schoolId)->find($contextId);
            if (! $payment || $payment->method !== 'marzpay'
                || blank($payment->gateway_txn_id) || $payment->gateway_txn_id !== $transactionUuid) {
                return response()->json(['status' => 'ignored'], 200);
            }
            $evidence = MarzPayService::verifyApplicationPayment($payment);
            if ($evidence === null) {
                // Retryable: do not acknowledge an unverifiable payment as processed.
                return response()->json(['status' => 'verification_unavailable'], 503);
            }
            $result = ApplicationPaymentSettlement::apply($evidence);
            return response()->json(['status' => $result === ApplicationPaymentSettlement::REJECTED ? 'ignored' : 'ok'], 200);
        }

        // Re-verify against MarzPay directly rather than trusting the
        // posted body's status field.
        $verified = MarzPayService::getCollectionStatus($transactionUuid, (int) $schoolId);
        $verifiedStatus = $verified['transaction']['status'] ?? null;

        if ($verifiedStatus === 'successful' || $verifiedStatus === 'completed') {
            $this->applySuccess($context, (int) $contextId, $verified);
        } elseif (in_array($verifiedStatus, ['failed', 'cancelled'], true)) {
            $this->applyFailure($context, (int) $contextId);
        }

        // Legacy non-application contexts acknowledge ignored events. Application
        // verification above returns 503 when the provider cannot be verified.
        return response()->json(['status' => 'ok'], 200);
    }

    /** MarzPay sends metadata as [{"key": "value"}, ...], not one flat object. */
    private function flattenMetadata(array $metadata): array
    {
        $flat = [];

        foreach ($metadata as $item) {
            if (is_array($item)) {
                $flat += $item;
            }
        }

        return $flat;
    }

    private function resolveSchoolId(?string $context, $contextId): ?int
    {
        if (! $context || ! $contextId) {
            return null;
        }

        $schoolId = match ($context) {
            'tuition'      => StudentFeeManager::where('id', $contextId)->value('school_id'),
            'hostel'       => HostelFee::where('id', $contextId)->value('school_id'),
            'application'  => ApplicationPayment::where('id', $contextId)->value('school_id'),
            'subscription' => \App\Models\PaymentHistory::where('id', $contextId)->value('school_id'),
            default        => null,
        };

        return $schoolId ? (int) $schoolId : null;
    }

    /**
     * Settle a tuition invoice from provider evidence only.
     *
     * The posted transaction UUID is deliberately NOT consulted. The invoice's
     * own `gateway_reference` — written when the payer started this very
     * payment — is what gets looked up at MarzPay, so a caller naming an
     * arbitrary invoice can only ever cause that invoice's OWN transaction to
     * be consulted.
     *
     * 503 (not 200) when the provider cannot be consulted, so MarzPay retries
     * instead of the payment being silently lost.
     */
    private function settleTuition(int $feeId, int $schoolId): \Illuminate\Http\JsonResponse
    {
        $fee = StudentFeeManager::where('id', $feeId)->where('school_id', $schoolId)->first();

        if (! $fee) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $fetch = MarzPayService::fetchTuitionTransaction($fee);

        // Only a provider we could not reach is worth retrying. If MarzPay
        // answered and the answer is not about this invoice's own transaction —
        // a foreign uuid, a missing reference, unconfigured tenant — that is a
        // final "no", and acknowledging it stops MarzPay redelivering forever.
        if ($fetch['status'] === 'unverifiable') {
            Log::warning('MarzPay tuition payment could not be verified', [
                'fee_id' => $feeId, 'school_id' => $schoolId,
            ]);

            return response()->json(['status' => 'verification_unavailable'], 503);
        }

        $evidence = VerifiedTuitionPayment::fromProviderResponse($fee, 'marzpay', $fetch['data']);

        if ($evidence === null) {
            Log::notice('MarzPay tuition callback did not match the invoice transaction', [
                'fee_id' => $feeId, 'school_id' => $schoolId,
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        $result = TuitionFeeSettlement::apply($evidence);

        return response()->json(
            ['status' => $result === TuitionFeeSettlement::REJECTED ? 'ignored' : 'ok'],
            200
        );
    }

    private function applySuccess(string $context, int $contextId, array $verified): void
    {
        match ($context) {
            'hostel'       => $this->applyHostel($contextId, $verified),
            'subscription' => $this->applySubscription($contextId),
            default        => null,
        };
    }

    private function applyFailure(string $context, int $contextId): void
    {
        match ($context) {
            // Tuition failures are handled by settleTuition(), which decides the
            // outcome from verified provider evidence. Reaching here means the
            // provider said "failed" for a transaction that is not the invoice's
            // own stored one, so there is nothing this controller may change.
            // hostel_fees.status is an int column (0=unpaid, 1=paid, 2=rejected/failed)
            // — see acceptOfflinePaymentHostel/rejectOfflinePaymentHostel.
            'hostel'  => HostelFee::where('id', $contextId)->where('status', 0)->update(['status' => 2]),
            default => null,
        };
    }

    private function applyHostel(int $feeId, array $verified): void
    {
        $fee = HostelFee::find($feeId);

        if (! $fee || (string) $fee->status === '1' || $fee->status === 'paid') {
            return;
        }

        $paidAmount = $verified['collection']['amount']['raw'] ?? $fee->amount;

        $fee->update([
            'status'           => 1,
            'paid_amount'      => $paidAmount,
            'payment_method'   => 'marzpay',
            'payment_date'     => now()->toDateString(),
            'gateway_payload'  => $verified,
        ]);

        AuditLog::record('update', 'Hostel', "Hostel fee #{$fee->id} paid via MarzPay.", [
            'event_type' => 'DATA', 'record_type' => HostelFee::class, 'record_id' => $fee->id, 'school_id' => $fee->school_id,
        ]);
    }

    private function applySubscription(int $paymentHistoryId): void
    {
        $payment = \App\Models\PaymentHistory::find($paymentHistoryId);

        if (! $payment || $payment->status === 'approve') {
            return;
        }

        SubscriptionActivator::activate($paymentHistoryId);
    }
}
