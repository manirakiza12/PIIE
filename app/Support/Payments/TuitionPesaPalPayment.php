<?php

namespace App\Support\Payments;

use App\Models\{AuditLog, PaymentMethods, StudentFeeManager, TuitionPayment, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TuitionPesaPalPayment
{
    private static function reject(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }

    public static function start(StudentFeeManager $fee, ?string $phone = null, string $paymentOption = 'balance', ?string $requestedAmount = null): TuitionPayment
    {
        $configuration = PesaPalConfiguration::forSchool((int) $fee->school_id);
        if (! $configuration->notificationId) { self::reject('PesaPal notifications have not been configured.'); }
        // Reconcile failed orders before allowing a replacement: late successes must be credited first.
        foreach (TuitionPayment::where('fee_id', $fee->id)->where('school_id', $fee->school_id)->where('status', 'failed')->whereNotNull('gateway_txn_id')->get() as $previous) {
            self::reconcile($previous);
        }
        [$payment, $created] = DB::transaction(function () use ($fee, $configuration, $paymentOption, $requestedAmount) {
            PaymentMethods::whereKey($configuration->configurationId)->lockForUpdate()->firstOrFail();
            if (PesaPalConfiguration::forSchool((int) $fee->school_id)->cacheKey() !== $configuration->cacheKey()) { throw new PesaPalException(); }
            $locked = StudentFeeManager::whereKey($fee->id)->where('school_id', $fee->school_id)->lockForUpdate()->firstOrFail();
            $outstanding = (DecimalAmount::minorUnits($locked->total_amount) ?? 0) - (DecimalAmount::minorUnits($locked->paid_amount) ?? 0);
            if ($locked->status === 'paid' || $outstanding <= 0) { self::reject('This invoice is already paid.'); }
            $existing = TuitionPayment::where('fee_id', $locked->id)->where('school_id', $locked->school_id)->where('status', 'pending')->latest('id')->first();
            if ($existing) { return [$existing, false]; }
            if (in_array($locked->status, ['pending', 'processing'], true)) {
                self::reject('An earlier payment needs reconciliation. Contact finance before paying again.');
            }
            $amount = $outstanding;
            if ($paymentOption === 'minimum') {
                $student = User::whereKey($locked->student_id)->where('school_id', $locked->school_id)->firstOrFail();
                $eligibility = app(\App\Support\CourseRegistration\StudentRegistrationConfirmationEligibility::class)->summary($student);
                if ((int) $locked->session_id !== $eligibility['session_id']) { self::reject('The 20% payment option applies to current-session invoices only.'); }
                if ($eligibility['eligible']) { self::reject('You already meet the study eligibility requirement. Choose a balance or custom payment.'); }
                $amount = min($outstanding, $eligibility['shortfall_minor']);
            } elseif ($paymentOption === 'custom') {
                $amount = DecimalAmount::minorUnits($requestedAmount);
                if ($amount === null || $amount <= 0 || $amount > $outstanding) { self::reject('Enter a valid payment amount up to the outstanding invoice balance.'); }
            } elseif ($paymentOption !== 'balance') { self::reject('Choose a valid payment option.'); }
            if ($amount <= 0) { self::reject('No payment is required for this option.'); }
            $currency = strtoupper(trim((string) DB::table('schools')->where('id', $locked->school_id)->value('school_currency')));
            if (! preg_match('/\A[A-Z]{3}\z/', $currency)) { self::reject('Finance must configure the school currency before accepting payments.'); }
            $payment = TuitionPayment::create(['school_id' => $locked->school_id, 'fee_id' => $locked->id,
                'amount' => DecimalAmount::decimal($amount), 'currency' => $currency, 'method' => 'pesapal',
                'status' => 'pending', 'reference' => 'TUITION-' . Str::uuid(),
                'gateway_payload' => ['environment' => $configuration->environment, 'configuration_id' => $configuration->configurationId, 'initiation' => 'reserved']]);
            // The pending attempt reserves checkout; only verified settlement changes the invoice.
            return [$payment, true];
        });
        if (! $created) {
            if ($payment->gateway_txn_id) {
                $service = new PesaPalService(PesaPalConfiguration::forPayment($payment));
                $service->checkoutUrl($payment->gateway_payload['checkout_url'] ?? null);
                try { self::reconcile($payment); }
                catch (PesaPalException $exception) {
                    // A status outage must not strand the already submitted order.
                    // Resume that order only; no replacement request or credit is made.
                }
            }
            if ($payment->fresh()->status !== 'pending') { self::reject('Payment status changed. Reload the invoice.'); }
            return $payment->fresh();
        }
        $student = User::whereKey($fee->student_id)->where('school_id', $fee->school_id)->firstOrFail();
        // Pesapal validates the billing name fields; a lone first_name with an
        // empty last_name is what SubmitOrderRequest rejects with HTTP 422, so
        // single-word names fall back to the first token for both.
        $names = collect(preg_split('/\s+/', trim((string) $student->name)) ?: [])->filter()->values();
        $firstName = $names->first() ?: 'Payer';
        $lastName = $names->skip(1)->implode(' ') ?: $firstName;
        try {
            $order = (new PesaPalService($configuration))->submitOrder(['id' => $payment->reference,
                'amount' => (string) $payment->amount, 'currency' => $payment->currency,
                'description' => 'School fees PRN ' . $payment->reference,
                'callback_url' => route('tuition.pesapal.callback'), 'notification_id' => $configuration->notificationId,
                'billing_address' => ['email_address' => trim((string) $student->email), 'phone_number' => preg_replace('/[()\s-]/', '', $phone ?: (string) $student->phone),
                    'first_name' => $firstName, 'last_name' => $lastName]]);
            DB::transaction(function () use ($payment, $order) {
                $locked = TuitionPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($locked->gateway_txn_id && $locked->gateway_txn_id !== $order->orderTrackingId) { throw new PesaPalException(); }
                $locked->update(['gateway_txn_id' => $order->orderTrackingId,
                    'gateway_payload' => array_merge($locked->gateway_payload, ['initiation' => 'submitted', 'checkout_url' => $order->redirectUrl])]);
            });
        } catch (PesaPalNotDispatchedException $exception) {
            DB::transaction(function () use ($payment) {
                $fee = StudentFeeManager::whereKey($payment->fee_id)->lockForUpdate()->firstOrFail();
                $payment->update(['status' => 'failed', 'gateway_payload' => array_merge($payment->gateway_payload, ['initiation' => 'not-dispatched'])]);
                $fee->update(['status' => 'unpaid']);
            });
            self::reject('Payment was not submitted. Nothing was charged; please try again.');
        } catch (PesaPalException $exception) {
            self::reject('Payment initiation could not be confirmed. Do not pay again; contact finance with your PRN.');
        }
        return $payment->fresh();
    }

    public static function reconcile(TuitionPayment $payment, ?string $notifiedId = null, ?string $confirmation = null): string
    {
        $id = $payment->gateway_txn_id ?: $notifiedId;
        if (! is_string($id) || ! PesaPalService::isGuid($id)
            || ($payment->gateway_txn_id && $notifiedId !== null && strtolower($notifiedId) !== strtolower($payment->gateway_txn_id))) { throw new PesaPalException(); }
        $status = (new PesaPalService(PesaPalConfiguration::forPayment($payment)))->getTransactionStatus($id);
        if ($status->merchantReference !== $payment->reference || $status->currency !== $payment->currency
            || DecimalAmount::minorUnits($status->amount) !== DecimalAmount::minorUnits($payment->amount)) { throw new PesaPalException(); }
        if (filled($confirmation) && ($status->classification() !== 'COMPLETED' || blank($status->confirmationCode)
            || ! hash_equals($status->confirmationCode, trim($confirmation)))) { self::reject('The confirmation code does not match this completed PesaPal payment.'); }
        return DB::transaction(function () use ($payment, $status) {
            // Always lock invoice before attempt, matching checkout lock order.
            $fee = StudentFeeManager::whereKey($payment->fee_id)->where('school_id', $payment->school_id)->lockForUpdate()->firstOrFail();
            $locked = TuitionPayment::whereKey($payment->id)->where('school_id', $fee->school_id)->lockForUpdate()->firstOrFail();
            if ($locked->gateway_txn_id && $locked->gateway_txn_id !== $status->orderTrackingId) { throw new PesaPalException(); }
            $previous = $locked->gateway_payload['classification'] ?? null;
            if ($previous === 'REVERSED') { return 'reversed'; }
            $classification = $status->classification();
            $payload = array_merge($locked->gateway_payload, ['classification' => $classification,
                'provider_confirmation_code' => $status->confirmationCode, 'last_checked_at' => now()->toIso8601String()]);
            if (filled($status->paymentMethod)) {
                $payload['payment_channel'] = mb_substr(trim(strip_tags($status->paymentMethod)), 0, 100);
            }
            $changes = ['gateway_txn_id' => $status->orderTrackingId, 'gateway_payload' => $payload];
            if ($classification === 'REVERSED') {
                if ($locked->status === 'paid') {
                    $fee->update(['paid_amount' => DecimalAmount::decimal(max(0, (DecimalAmount::minorUnits($fee->paid_amount) ?? 0) - DecimalAmount::minorUnits($locked->amount))), 'status' => 'unpaid']);
                } elseif ($fee->status !== 'paid' && ! TuitionPayment::where('fee_id', $fee->id)->where('id', '!=', $locked->id)->where('status', 'pending')->exists()) {
                    $fee->update(['status' => 'unpaid']);
                }
                $changes['status'] = 'reversed';
            } elseif ($locked->status === 'paid') { return 'paid';
            } elseif ($classification === 'COMPLETED') {
                $paid = (DecimalAmount::minorUnits($fee->paid_amount) ?? 0) + DecimalAmount::minorUnits($locked->amount);
                $fee->update(['paid_amount' => DecimalAmount::decimal($paid), 'status' => $paid >= DecimalAmount::minorUnits($fee->total_amount) ? 'paid' : 'unpaid',
                    'payment_method' => 'pesapal', 'gateway_reference' => $status->orderTrackingId, 'gateway_payload' => $payload]);
                $changes['status'] = 'paid'; $changes['paid_at'] = now();
            } elseif ($classification === 'FAILED') {
                $changes['status'] = 'failed';
                // Do not reset an invoice with a newer active order.
                if (! TuitionPayment::where('fee_id', $fee->id)->where('id', '!=', $locked->id)->where('status', 'pending')->exists() && $fee->status !== 'paid') { $fee->update(['status' => 'unpaid']); }
            }
            $locked->update($changes);
            if ($locked->wasChanged('status')) {
                AuditLog::record('update', 'Student Fees', 'PesaPal school fee payment #' . $locked->id . ': ' . $locked->status,
                    ['school_id' => $fee->school_id, 'event_type' => 'DATA', 'record_type' => TuitionPayment::class, 'record_id' => $locked->id]);
            }
            return $locked->status;
        });
    }
}
