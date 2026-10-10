<?php

namespace App\Http\Controllers\Applicant;

use App\Models\Admission;
use App\Models\ApplicationPayment;
use App\Models\AuditLog;
use App\Support\Admissions\ApplicantNotifier;
use App\Support\Admissions\ApplicationFee;
use App\Support\Admissions\ApplicationProgress;
use App\Support\Payments\ApplicationPaymentSettlement;
use App\Support\Payments\DecimalAmount;
use App\Support\Payments\MarzPayService;
use App\Support\Payments\VerifiedApplicationPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Application fees: shared PesaPal checkout and retained offline proof review.
 *
 * Nothing here ever marks a fee paid on the applicant's say-so. A deposit is
 * recorded as 'pending' for the finance office to confirm; online payments
 * require server-side verification. Historical gateway verification remains
 * available. The redirect back from a gateway is a hint that
 * something happened, not evidence of it.
 */
class PaymentController extends BaseApplicantController
{
    public function index()
    {
        $admission = $this->currentApplication();
        $admission->load(['intakeSession', 'payments']);

        if (! ApplicationFee::isRequired($admission)) {
            return redirect()->route('applicant.dashboard')
                ->with('success', get_phrase('No application fee is payable for your chosen intake.'));
        }

        return view('applicant.payment', [
            'admission'   => $admission,
            'amount'      => ApplicationFee::amountFor($admission),
            'methods'     => ApplicationFee::availableMethods($admission->school_id),
            'bankDetails' => ApplicationFee::bankInstructions(),
            'payments'    => $admission->payments()->latest('id')->get(),
            'percent'     => ApplicationProgress::percent($admission),
        ]);
    }

    /**
     * Bank deposit / mobile money: the applicant tells us what they paid and
     * uploads proof; finance confirms it later from the admin review screen.
     */
    public function submitOffline(Request $request)
    {
        $admission = $this->currentApplication();
        $amount    = ApplicationFee::amountFor($admission);

        if ($amount <= 0) {
            return redirect()->route('applicant.dashboard');
        }

        if ($admission->isFeeSettled()) {
            return back()->with('error', get_phrase('Your application fee has already been settled.'));
        }

        $validated = $request->validate([
            'reference' => 'required|string|max:100',
            'note'      => 'nullable|string|max:500',
            'proof'     => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ], [
            'reference.required' => get_phrase('Please enter the transaction or deposit reference.'),
            'proof.required'     => get_phrase('Please attach your deposit slip or transaction message.'),
        ]);

        $storedAs = \App\Support\Payments\ApplicationPaymentProof::store($request->file('proof'), $admission->id);

        $payment = ApplicationPayment::create([
            'school_id'    => $admission->school_id,
            'admission_id' => $admission->id,
            'applicant_id' => $this->applicant()->id,
            'amount'       => $amount,
            'currency'     => ApplicationFee::currencyFor($admission),
            'method'       => 'offline',
            'status'       => ApplicationPayment::STATUS_PENDING,
            'reference'    => $validated['reference'],
            'note'         => $validated['note'] ?? null,
            'proof_file'   => $storedAs,
        ]);

        ApplicationFee::refreshStatus($admission);

        AuditLog::record('create', 'Admissions', "Applicant submitted offline application-fee payment for {$admission->app_number} (ref {$payment->reference}).", [
            'event_type'  => 'DATA',
            'record_type' => ApplicationPayment::class,
            'record_id'   => $payment->id,
            'school_id'   => $admission->school_id,
        ]);

        ApplicantNotifier::paymentReceived($admission, $payment);

        return redirect()->route('applicant.payment')
            ->with('success', get_phrase('Thank you. Your payment details have been sent to the finance office for confirmation.'));
    }

    /**
     * Starts a Stripe Checkout session and redirects to it.
     *
     * A 'pending' payment row is written first, keyed by our own reference,
     * so a payment that completes at the gateway but never makes it back
     * through the success URL (closed tab, dropped connection) is still
     * traceable from this side.
     */
    public function startGateway(Request $request, string $gateway)
    {
        $admission = $this->currentApplication();
        if ($gateway === 'pesapal') {
            try {
                $payment = \App\Support\Payments\ApplicantPesaPalPayment::start($admission);
                $url = $payment->gateway_payload['checkout_url'] ?? null;
                return $url ? redirect()->away($url) : back()->with('error', 'An outstanding payment is awaiting verification. Do not pay again; contact finance.');
            } catch (\App\Support\Payments\PesaPalException $exception) {
                return back()->with('error', 'PesaPal is temporarily unavailable. Please check your payment status before retrying.');
            }
        }
        // Retain legacy status/return processing; no new applicant legacy orders.
        return back()->with('error', 'New online application payments use PesaPal.');
    }

    public function checkPesaPalStatus(int $paymentId)
    {
        $admission = $this->currentApplication();
        $payment = $admission->payments()->where('school_id', $admission->school_id)->where('method', 'pesapal')->findOrFail($paymentId);
        try {
            $result = \App\Support\Payments\ApplicantPesaPalPayment::reconcile($payment);
            return back()->with('success', 'Payment status: ' . $result);
        } catch (\App\Support\Payments\PesaPalException $exception) {
            return back()->with('error', 'Payment verification is unavailable. Do not pay again; contact finance.');
        }
    }

    /** AJAX poll from the pending page. */
    public function checkMarzPayStatus(int $paymentId)
    {
        $admission = $this->currentApplication();
        $payment = ApplicationPayment::where('admission_id', $admission->id)
            ->where('school_id', $admission->school_id)->find($paymentId);

        if (! $payment || $payment->method !== 'marzpay') {
            return response()->json(['status' => 'not_found']);
        }

        if ($payment->isSettled()) {
            return response()->json(['status' => 'paid']);
        }

        $verified = MarzPayService::verifyApplicationPayment($payment);
        if ($verified === null) {
            return response()->json(['status' => 'verification_unavailable'], 503);
        }
        $result = ApplicationPaymentSettlement::apply($verified);
        if (in_array($result, [ApplicationPaymentSettlement::SETTLED, ApplicationPaymentSettlement::ALREADY_SETTLED], true)) {
            return response()->json(['status' => 'paid']);
        }
        if ($result === ApplicationPaymentSettlement::FAILED) {
            return response()->json(['status' => 'failed']);
        }
        if ($result === ApplicationPaymentSettlement::REJECTED) {
            return response()->json(['status' => 'verification_rejected'], 422);
        }

        return response()->json(['status' => 'processing']);
    }

    /**
     * Gateway return. The session/transaction is re-fetched from the
     * provider and only a confirmed-paid status settles the fee — the
     * applicant landing on this URL proves nothing on its own.
     */
    public function gatewayReturn(Request $request, string $gateway, int $paymentId)
    {
        $admission = $this->currentApplication();

        $payment = ApplicationPayment::where('admission_id', $admission->id)
            ->where('school_id', $admission->school_id)->find($paymentId);

        if (! $payment || $payment->method !== $gateway || ! in_array($gateway, ['stripe', 'flutterwave'], true)) {
            return redirect()->route('applicant.payment')->with('error', get_phrase('We could not match that payment.'));
        }

        if ($payment->isSettled()) {
            return redirect()->route('applicant.payment')->with('success', get_phrase('Your application fee is already settled.'));
        }

        $result = $gateway === 'flutterwave'
            ? $this->confirmFlutterwave($request, $payment)
            : $this->confirmStripe($request, $payment);

        if ($result === null) {
            return redirect()->route('applicant.payment')
                ->with('error', get_phrase('We could not confirm your payment with the provider. If you were charged, contact the finance office with your reference: ') . $payment->reference);
        }

        $settlement = ApplicationPaymentSettlement::apply($result);
        if (! in_array($settlement, [ApplicationPaymentSettlement::SETTLED, ApplicationPaymentSettlement::ALREADY_SETTLED], true)) {
            // Preserve the dormant hosted gateways' failed-return behavior.
            // A concurrent successful settlement must never be overwritten.
            if ($settlement === ApplicationPaymentSettlement::REJECTED) {
                ApplicationPayment::whereKey($payment->id)->where('status', ApplicationPayment::STATUS_PENDING)
                    ->update(['status' => ApplicationPayment::STATUS_FAILED]);
                ApplicationFee::refreshStatus($admission);
            }
            return redirect()->route('applicant.payment')
                ->with('error', get_phrase('Your payment was not completed. You can try again or pay by bank deposit.'));
        }

        return redirect()->route('applicant.application.step', ApplicationProgress::STEP_REVIEW)
            ->with('success', get_phrase('Payment received. Your application fee is settled.'));
    }

    /**
     * Verifies the checkout session startStripe() created for THIS payment
     * (gateway_txn_id), not whichever session id the browser was redirected
     * back with — that value is client-controlled, and a paid session from
     * another payment must never settle this one (Security Phase 2I). The
     * reference, amount and currency are re-checked, as confirmFlutterwave() does.
     */
    private function confirmStripe(Request $request, ApplicationPayment $payment): ?VerifiedApplicationPayment
    {
        $secretKey = get_payment_keys('stripe', 'test_secret_key') ?: get_payment_keys('stripe', 'secret_live_key');
        $sessionId = (string) $payment->gateway_txn_id;
        $returned = $request->query('session_id');

        if ($sessionId === '' || (filled($returned) && $returned !== $sessionId)) {
            return null;
        }

        try {
            \Stripe\Stripe::setApiKey($secretKey);
            $session = \Stripe\Checkout\Session::retrieve($sessionId);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $minor = $session->amount_total ?? null;
        if (! is_int($minor) || $minor <= 0) {
            return null;
        }
        return new VerifiedApplicationPayment(
            (int) $payment->id, (int) $payment->school_id, 'stripe',
            (string) ($session->client_reference_id ?? ''), (string) ($session->id ?? ''),
            DecimalAmount::decimal($minor), (string) ($session->currency ?? ''),
            ($session->payment_status ?? null) === 'paid' ? 'paid' : 'failed',
            ['payment_intent' => $session->payment_intent ?? null, 'payment_status' => $session->payment_status ?? null],
        );
    }

    /**
     * Verifies against Flutterwave's own record of the transaction, not the
     * status query param the browser was redirected back with — that value
     * is client-controlled and never trusted on its own. amount/currency are
     * re-checked too, so a tampered redirect can't settle the fee for less
     * than what was actually charged.
     */
    private function confirmFlutterwave(Request $request, ApplicationPayment $payment): ?VerifiedApplicationPayment
    {
        $secretKey = ApplicationFee::flutterwaveSecretKey();
        $transactionId = $request->query('transaction_id');

        if (blank($secretKey) || blank($transactionId)) {
            return null;
        }

        try {
            $response = Http::withToken($secretKey)
                ->acceptJson()
                ->get("https://api.flutterwave.com/v3/transactions/{$transactionId}/verify");
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json('data') ?? [];

        if (! is_array($data) || ! isset($data['id']) || (string) $data['id'] !== (string) $transactionId
            || DecimalAmount::minorUnits($data['amount'] ?? null) === null) {
            return null;
        }
        return new VerifiedApplicationPayment(
            (int) $payment->id, (int) $payment->school_id, 'flutterwave',
            (string) ($data['tx_ref'] ?? ''), (string) $data['id'], (string) $data['amount'],
            (string) ($data['currency'] ?? ''), ($data['status'] ?? null) === 'successful' ? 'paid' : 'failed', $data, true,
        );
    }

    public function gatewayCancel(string $gateway, int $paymentId)
    {
        $admission = $this->currentApplication();

        $payment = ApplicationPayment::where('admission_id', $admission->id)
            ->where('school_id', $admission->school_id)->find($paymentId);

        if ($payment && $payment->status === ApplicationPayment::STATUS_PENDING) {
            ApplicationPayment::whereKey($payment->id)->where('status', ApplicationPayment::STATUS_PENDING)
                ->update(['status' => ApplicationPayment::STATUS_FAILED, 'note' => get_phrase('Cancelled at the payment page.')]);
            ApplicationFee::refreshStatus($admission);
        }

        return redirect()->route('applicant.payment')
            ->with('error', get_phrase('Your payment was cancelled. Nothing has been charged.'));
    }
}
