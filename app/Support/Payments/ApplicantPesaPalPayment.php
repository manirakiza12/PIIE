<?php

namespace App\Support\Payments;

use App\Models\Admission;
use App\Models\ApplicationPayment;
use App\Support\Admissions\ApplicationFee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Shared checkout and reconciliation for public and staff-entry applications. */
final class ApplicantPesaPalPayment
{
    public static function eligible(Admission $admission): bool
    {
        return filled($admission->submitted_at)
            && in_array($admission->status, [Admission::STATUS_SUBMITTED, Admission::STATUS_UNDER_REVIEW, Admission::STATUS_ACCEPTED], true)
            && ApplicationFee::isRequired($admission);
    }

    private static function reject(string $message): never
    {
        throw ValidationException::withMessages(['payment' => $message]);
    }

    public static function start(Admission $admission): ApplicationPayment
    {
        if($guard=config('sandbox.checkout.start_guard')) { $guard($admission); }
        // Re-check terminal failed attempts before permitting a replacement order.
        $previous = $admission->payments()->where('school_id', $admission->school_id)->where('method', 'pesapal')
            ->where('status', 'failed')->whereNotNull('gateway_txn_id')->latest('id')->first();
        if ($previous) { self::reconcile($previous); }
        $configuration = PesaPalConfiguration::forSchool((int) $admission->school_id);
        if (! $configuration->notificationId) { self::reject('PesaPal notifications have not been configured.'); }

        // Reserve BEFORE the network call. An ambiguous timeout must never cause
        // another order to be submitted. No automatic transaction retries here.
        [$payment, $created] = DB::transaction(function () use ($admission, $configuration) {
            // Serialize credential replacement with reservation creation.
            \App\Models\PaymentMethods::whereKey($configuration->configurationId)->lockForUpdate()->firstOrFail();
            if (PesaPalConfiguration::forSchool((int) $admission->school_id)->cacheKey() !== $configuration->cacheKey()) { throw new PesaPalException(); }
            $locked = Admission::whereKey($admission->id)->where('school_id', $admission->school_id)->lockForUpdate()->firstOrFail();
            if (! self::eligible($locked)) { self::reject('Submit your application before paying. This application is not eligible for online payment.'); }
            if ($locked->applicant_id !== null && ! \App\Models\Applicant::whereKey($locked->applicant_id)->where('school_id', $locked->school_id)->exists()) {
                self::reject('The applicant link is invalid. Contact admissions before paying.');
            }
            ApplicationFee::freezeObligation($locked);
            ApplicationFee::refreshStatus($locked);
            if ($locked->isFeeSettled()) { self::reject('Your application fee is already settled.'); }
            $existing = $locked->payments()->where('school_id', $locked->school_id)
                ->where('status', ApplicationPayment::STATUS_PENDING)->whereIn('method', ['pesapal', 'marzpay', 'stripe', 'flutterwave'])->latest('id')->first();
            if ($locked->payments()->where('status', 'pending')->whereNotIn('method', ['pesapal', 'marzpay', 'stripe', 'flutterwave'])->exists()) {
                self::reject('An offline payment is awaiting finance review. Resolve it before starting an online payment.');
            }
            if ($existing) {
                if ($existing->method !== 'pesapal') { self::reject('An earlier online payment is outstanding. Contact finance before starting another payment.'); }
                if (($existing->gateway_payload['environment'] ?? null) !== $configuration->environment
                    || ($existing->gateway_payload['configuration_id'] ?? null) !== $configuration->configurationId) { throw new PesaPalException(); }
                return [$existing, false];
            }
            return [ApplicationPayment::create([
                'school_id' => $locked->school_id, 'admission_id' => $locked->id, 'applicant_id' => $locked->applicant_id,
                'amount' => self::outstanding($locked), 'currency' => ApplicationFee::currencyFor($locked), 'method' => 'pesapal',
                'status' => ApplicationPayment::STATUS_PENDING, 'reference' => 'APPFEE-' . $locked->id . '-' . Str::uuid(),
                'gateway_payload' => ['environment' => $configuration->environment, 'configuration_id' => $configuration->configurationId,
                    'initiation' => 'reserved'],
            ]), true];
        });
        if (! $created) {
            if ($payment->gateway_txn_id) {
                self::reconcile($payment);
                $payment->refresh();
                if ($payment->status !== 'pending') { self::reject('The earlier payment status has changed. Reload the payment page before continuing.'); }
            }
            return $payment;
        }
        ApplicationFee::refreshStatus($admission);
        try {
            $order = (new PesaPalService($configuration))->submitOrder([
                'id' => $payment->reference, 'amount' => (string) $payment->amount, 'currency' => $payment->currency,
                'description' => 'Application fee ' . $admission->app_number,
                'callback_url' => route('applicant.pesapal.callback'),
                'notification_id' => $configuration->notificationId,
                'billing_address' => ['email_address' => (string) $admission->email, 'phone_number' => (string) $admission->phone,
                    'first_name' => (string) $admission->first_name, 'last_name' => (string) $admission->last_name],
            ]);
            DB::transaction(function () use ($payment, $order) {
                $locked = ApplicationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if (filled($locked->gateway_txn_id) && $locked->gateway_txn_id !== $order->orderTrackingId) { throw new PesaPalException(); }
                $locked->update(['gateway_txn_id' => $order->orderTrackingId,
                    'gateway_payload' => array_merge($locked->gateway_payload ?? [], ['initiation' => 'submitted', 'checkout_url' => $order->redirectUrl])]);
            });
        } catch (PesaPalNotDispatchedException $exception) {
            // Nothing was ever sent to PesaPal, so no order exists and there is
            // nothing to double-charge. Release the reservation instead of
            // stranding the applicant on a row that can neither be completed
            // (no checkout_url) nor cleared (no gateway_txn_id to reconcile).
            self::releaseReservation($payment, 'Not submitted to PesaPal; nothing was charged.');
            ApplicationFee::refreshStatus($admission);
            self::reject('We could not start the payment. Nothing has been charged - please try again.');
        } catch (PesaPalException $exception) {
            // Keep the reservation and reference for recovery; never erase evidence.
            self::reject('Payment initiation could not be confirmed. Do not pay again; contact finance with your application reference.');
        }
        return $payment->fresh();
    }

    /**
     * Marks a reservation that never reached the provider as failed.
     *
     * Strictly limited to a pending row with no gateway_txn_id AND explicitly
     * flagged 'not-dispatched', i.e. one this process already knows it never
     * sent. A plain pending row without a gateway_txn_id is NOT released here:
     * that is exactly what an ambiguous transport timeout also looks like, and
     * releasing it would allow a second order for a payment PesaPal may
     * already hold. Those stay reserved for finance to reconcile.
     */
    private static function releaseReservation(ApplicationPayment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason) {
            $locked = ApplicationPayment::whereKey($payment->id)
                ->where('status', ApplicationPayment::STATUS_PENDING)
                ->whereNull('gateway_txn_id')
                ->lockForUpdate()
                ->first();
            if (! $locked) {
                return;
            }
            $locked->update([
                'status' => ApplicationPayment::STATUS_FAILED,
                'note' => $reason,
                'gateway_payload' => array_merge($locked->gateway_payload ?? [], ['initiation' => 'not-dispatched']),
            ]);
        });
    }

    public static function outstanding(Admission $admission): string
    {
        $admission = $admission->fresh();
        $required = DecimalAmount::minorUnits(ApplicationFee::amountFor($admission));
        $paid = 0;
        $seen = [];
        foreach ($admission->payments()->where('school_id', $admission->school_id)->where('status', 'paid')->get() as $payment) {
            if (filled($payment->currency) && strtoupper($payment->currency) !== strtoupper(ApplicationFee::currencyFor($admission))) { continue; }
            if (($payment->gateway_payload['classification'] ?? null) === 'REVERSED') { continue; }
            $identity = $payment->method . ':' . $payment->gateway_txn_id;
            if (filled($payment->gateway_txn_id) && isset($seen[$identity])) { continue; }
            $seen[$identity] = true;
            $paid += max(0, DecimalAmount::minorUnits($payment->amount) ?? 0);
        }
        return DecimalAmount::decimal(max(0, ($required ?? 0) - $paid));
    }

    public static function reconcile(ApplicationPayment $payment, ?string $notifiedId = null): string
    {
        if($guard=config('sandbox.checkout.reconcile_guard')) { $guard($payment); }
        if ($payment->method !== 'pesapal') { throw new PesaPalException(); }
        $id = $payment->gateway_txn_id ?: $notifiedId;
        if (! is_string($id) || ! PesaPalService::isGuid($id)
            || (filled($payment->gateway_txn_id) && $notifiedId !== null && strtolower($notifiedId) !== $payment->gateway_txn_id)) { throw new PesaPalException(); }
        $configuration = PesaPalConfiguration::forPayment($payment);
        if (($payment->gateway_payload['environment'] ?? null) !== $configuration->environment
            || ($payment->gateway_payload['configuration_id'] ?? null) !== $configuration->configurationId) { throw new PesaPalException(); }
        $status = (new PesaPalService($configuration))->getTransactionStatus($id);
        if ($status->merchantReference !== $payment->reference || $status->currency !== $payment->currency
            || DecimalAmount::minorUnits($status->amount) !== DecimalAmount::minorUnits($payment->amount)) { throw new PesaPalException(); }
        $observation=['classification'=>$status->classification(),'last_checked_at'=>now()->toIso8601String(),
            'verified_failure_reason'=>$status->classification()==='FAILED'?PesaPalPaymentExperience::failureReason($status->description):null];

        DB::transaction(function () use ($payment, $status) {
            $locked = ApplicationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (filled($locked->gateway_txn_id) && $locked->gateway_txn_id !== $status->orderTrackingId) { throw new PesaPalException(); }
            $locked->update(['gateway_txn_id' => $status->orderTrackingId]);
        });
        if ($status->classification() === 'REVERSED') {
            DB::transaction(function () use ($payment) {
                $locked = ApplicationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if (($locked->gateway_payload['classification'] ?? null) === 'REVERSED') { return; }
                // Keep paid identity protection and historical paid_at. Exclude the
                // reversed credit from the obligation; never automatically re-credit.
                    $locked->update(['gateway_payload' => array_merge($locked->gateway_payload ?? [], ['classification' => 'REVERSED','last_checked_at'=>now()->toIso8601String()])]);
                ApplicationFee::refreshStatus($locked->admission);
                \App\Models\AuditLog::record('update', 'Admissions', 'PesaPal reversal verified for payment #' . $locked->id, [
                    'event_type' => 'DATA', 'school_id' => $locked->school_id, 'record_type' => ApplicationPayment::class, 'record_id' => $locked->id,
                ]);
                \App\Support\Admissions\ApplicantNotifier::paymentReversed($locked->admission, $locked);
            });
            return 'reversed';
        }
        if (($payment->fresh()->gateway_payload['classification'] ?? null) === 'REVERSED') { return 'reversed'; }
        if (! in_array($status->classification(), ['COMPLETED', 'FAILED'], true)) {
            DB::transaction(function () use ($payment, $observation) {
                $locked = ApplicationPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'pending') {
                    $locked->update(['gateway_payload' => array_merge($locked->gateway_payload ?? [], $observation)]);
                }
            });
        }
        return ApplicationPaymentSettlement::apply(new VerifiedApplicationPayment(
            (int) $payment->id, (int) $payment->school_id, 'pesapal', $status->merchantReference,
            $status->orderTrackingId, $status->amount, $status->currency,
            match ($status->classification()) { 'COMPLETED' => 'paid', 'FAILED' => 'failed', default => 'pending' },
            array_merge($payment->fresh()->gateway_payload ?? [], $observation),
        ));
    }
}
