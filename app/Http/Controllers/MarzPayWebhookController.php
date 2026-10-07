<?php

namespace App\Http\Controllers;

use App\Models\ApplicationPayment;
use App\Models\AuditLog;
use App\Models\HostelFee;
use App\Models\StudentFeeManager;
use App\Support\Payments\ApplicationPaymentSettlement;
use App\Support\Payments\MarzPayService;
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

    private function applySuccess(string $context, int $contextId, array $verified): void
    {
        match ($context) {
            'tuition'      => $this->applyTuition($contextId, $verified),
            'hostel'       => $this->applyHostel($contextId, $verified),
            'subscription' => $this->applySubscription($contextId),
            default        => null,
        };
    }

    private function applyFailure(string $context, int $contextId): void
    {
        match ($context) {
            'tuition' => StudentFeeManager::where('id', $contextId)->where('status', 'processing')->update(['status' => 'failed']),
            // hostel_fees.status is an int column (0=unpaid, 1=paid, 2=rejected/failed)
            // — see acceptOfflinePaymentHostel/rejectOfflinePaymentHostel.
            'hostel'  => HostelFee::where('id', $contextId)->where('status', 0)->update(['status' => 2]),
            default => null,
        };
    }

    private function applyTuition(int $feeId, array $verified): void
    {
        $fee = StudentFeeManager::find($feeId);

        if (! $fee || $fee->status === 'paid') {
            return;
        }

        $paidAmount = $verified['collection']['amount']['raw'] ?? $fee->total_amount;

        $fee->update([
            'status'          => 'paid',
            'paid_amount'     => $paidAmount,
            'payment_method'  => 'marzpay',
            'gateway_payload' => $verified,
        ]);

        AuditLog::record('update', 'Fees', "Tuition fee #{$fee->id} paid via MarzPay.", [
            'event_type' => 'DATA', 'record_type' => StudentFeeManager::class, 'record_id' => $fee->id, 'school_id' => $fee->school_id,
        ]);
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
