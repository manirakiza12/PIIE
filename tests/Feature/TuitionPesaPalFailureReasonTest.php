<?php

namespace Tests\Feature;

use App\Models\{PaymentMethods, StudentFeeManager, TuitionPayment, User};
use App\Support\Payments\TuitionPesaPalPayment;
use App\Support\Payments\PesaPalPaymentExperience;
use Illuminate\Support\Facades\{Http, Schema, URL};
use Illuminate\Database\Schema\Blueprint;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class TuitionPesaPalFailureReasonTest extends TestCase
{
    use AdmissionsTestHelper;
    private const GUID = '7e6b62d9-883e-440f-a63e-e1105bbfadc3';
    private int $school;
    private User $student;
    private string $reference = '';
    private int $orders = 0;
    private int $code = 0;
    private string $description = 'INVALID';
    private string $amount = '5000.00';
    private string $channel = 'Card';
    private string $failureDescription = '';
    private bool $statusUnavailable = false;
    private bool $amountMismatch = false;
    private bool $currencyMismatch = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', fn (Blueprint $t) => $t->string('school_currency')->nullable());
        $this->school = $this->makeSchool(['school_currency' => 'UGX']);
        $this->student = User::create(['school_id' => $this->school, 'role_id' => 7, 'name' => 'Student', 'email' => 'student@example.test']);
        config(['app.url' => 'https://piie.example.test', 'cache.default' => 'array']);
        URL::forceRootUrl('https://piie.example.test');
        URL::forceScheme('https');
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'fixture', 'consumer_secret' => 'fixture', 'notification_id' => self::GUID])]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'RequestToken')) {
                return Http::response(['token' => 'fixture', 'expiryDate' => now()->addMinutes(5)->format('Y-m-d\TH:i:s'), 'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'SubmitOrderRequest')) {
                $this->orders++;
                $this->reference = $request['id'];
                if ($this->statusUnavailable) { throw new \Illuminate\Http\Client\ConnectionException('timeout'); }
                return Http::response(['order_tracking_id' => self::GUID, 'merchant_reference' => $this->reference,
                    'redirect_url' => 'https://cybqa.pesapal.com/pay?OrderTrackingId=' . self::GUID, 'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'GetTransactionStatus')) {
                if ($this->statusUnavailable) { return Http::response([], 503); }
                $amount = $this->amountMismatch ? '4999.00' : $this->amount;
                $currency = $this->currencyMismatch ? 'KES' : 'UGX';
                return Http::response(['merchant_reference' => $this->reference,
                    'amount' => $amount, 'currency' => $currency, 'status_code' => $this->code, 'payment_status_description' => $this->description,
                    'payment_method' => $this->channel, 'confirmation_code' => 'PROVIDER-123', 'status' => '200', 'error' => null,
                    'description' => $this->failureDescription]);
            }
            throw new \RuntimeException('Unexpected request');
        });
    }

    private function fee(): StudentFeeManager
    {
        return StudentFeeManager::create(['school_id' => $this->school, 'student_id' => $this->student->id,
            'title' => 'School fees', 'total_amount' => 5000, 'amount' => 5000, 'paid_amount' => 0, 'class_id' => 0,
            'payment_method' => '', 'status' => 'unpaid']);
    }

    public function test_insufficient_funds_failure_reason_captured_and_displayed(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 2;
        $this->description = 'Failed';
        $this->amount = '5000.00';
        $this->channel = 'MTN Mobile Money';
        $this->failureDescription = 'Insufficient funds';

        $result = TuitionPesaPalPayment::reconcile($payment);
        $payment->refresh();

        $this->assertSame('failed', $result);
        $this->assertSame('failed', $payment->status);
        $this->assertSame('INSUFFICIENT_FUNDS', $payment->gateway_payload['verified_failure_reason']);

        $experience = PesaPalPaymentExperience::forPayment($payment);
        $this->assertSame('failed', $experience['state']);
        $this->assertSame('The provider reported insufficient funds.', $experience['reason']);
    }

    public function test_cancelled_payment_failure_reason_captured_and_displayed(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 2;
        $this->description = 'Failed';
        $this->amount = '5000.00';
        $this->failureDescription = 'Cancelled by user';

        $result = TuitionPesaPalPayment::reconcile($payment);
        $payment->refresh();

        $this->assertSame('failed', $result);
        $this->assertSame('CANCELLED', $payment->gateway_payload['verified_failure_reason']);

        $experience = PesaPalPaymentExperience::forPayment($payment);
        $this->assertSame('The provider reported that the payment was cancelled.', $experience['reason']);
    }

    public function test_expired_payment_failure_reason_captured_and_displayed(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 2;
        $this->description = 'Failed';
        $this->failureDescription = 'Payment expired';

        $result = TuitionPesaPalPayment::reconcile($payment);
        $payment->refresh();

        $this->assertSame('failed', $result);
        $this->assertSame('EXPIRED', $payment->gateway_payload['verified_failure_reason']);

        $experience = PesaPalPaymentExperience::forPayment($payment);
        $this->assertSame('expired', $experience['state']);
        $this->assertSame('The payment approval expired.', $experience['reason']);
    }

    public function test_authorization_declined_failure_reason_captured_and_displayed(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 2;
        $this->description = 'Failed';
        $this->failureDescription = 'Unable to authorize transaction';

        $result = TuitionPesaPalPayment::reconcile($payment);
        $payment->refresh();

        $this->assertSame('failed', $result);
        $this->assertSame('AUTHORIZATION_DECLINED', $payment->gateway_payload['verified_failure_reason']);

        $experience = PesaPalPaymentExperience::forPayment($payment);
        $this->assertSame('The provider declined authorization.', $experience['reason']);
    }

    public function test_failed_payment_without_specific_reason_shows_neutral_message(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 2;
        $this->description = 'Failed';
        $this->amount = '5000.00';
        $this->channel = 'Card';
        $this->failureDescription = 'Request processed successfully';

        $result = TuitionPesaPalPayment::reconcile($payment);
        $payment->refresh();

        $this->assertSame('failed', $result);
        $this->assertNull($payment->gateway_payload['verified_failure_reason']);

        $experience = PesaPalPaymentExperience::forPayment($payment);
        $this->assertSame('PesaPal did not provide a specific failure reason.', $experience['reason']);
    }

    public function test_completed_payment_credits_invoice_and_shows_success(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 1;
        $this->description = 'Completed';

        $result = TuitionPesaPalPayment::reconcile($payment);
        $payment->refresh();

        $this->assertSame('paid', $result);
        $this->assertSame('paid', $payment->status);
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);

        $experience = PesaPalPaymentExperience::forPayment($payment);
        $this->assertSame('successful', $experience['state']);
        $this->assertSame('PesaPal verified this payment as completed. Your fee balance reflects the verified payment.', $experience['message']);
    }

    public function test_pending_payment_shows_awaiting_payment(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);

        $experience = PesaPalPaymentExperience::forPayment($payment);
        $this->assertSame('pending', $experience['state']);
        $this->assertSame('Awaiting payment', $experience['label']);
        $this->assertStringContainsString('has not been paid yet', $experience['message']);
    }

    public function test_reversed_payment_shows_reversed_message(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 1;
        $this->description = 'Completed';
        TuitionPesaPalPayment::reconcile($payment);
        $this->code = 3;
        $this->description = 'Reversed';

        $result = TuitionPesaPalPayment::reconcile($payment->fresh());
        $payment->refresh();

        $this->assertSame('reversed', $result);
        $this->assertSame('reversed', $payment->status);

        $experience = PesaPalPaymentExperience::forPayment($payment);
        $this->assertSame('reversed', $experience['state']);
        $this->assertStringContainsString('reversal', $experience['message']);
    }

    public function test_failed_payment_never_credits_invoice(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 2;
        $this->description = 'Failed';
        $this->amount = '5000.00';
        $this->channel = 'Card';

        TuitionPesaPalPayment::reconcile($payment);

        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        $this->assertSame('unpaid', $fee->fresh()->status);
    }

    public function test_pending_payment_never_generates_confirmed_receipt(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);

        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->paid_at);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
    }

    public function test_completed_payment_requires_verified_provider_evidence(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 1;
        $this->description = 'Completed';

        // Use wrong reference to simulate mismatched provider evidence
        $this->reference = 'WRONG-REF';

        try {
            TuitionPesaPalPayment::reconcile($payment);
            $this->fail('Mismatched provider evidence should be rejected');
        } catch (\App\Support\Payments\PesaPalException $e) {
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        }
    }

    public function test_duplicate_callback_cannot_credit_twice(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 1;
        $this->description = 'Completed';

        TuitionPesaPalPayment::reconcile($payment);
        $firstPaidAmount = $fee->fresh()->paid_amount;

        TuitionPesaPalPayment::reconcile($payment->fresh());
        $secondPaidAmount = $fee->fresh()->paid_amount;

        $this->assertSame($firstPaidAmount, $secondPaidAmount);
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
    }

    public function test_incorrect_amount_rejected(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 1;
        $this->description = 'Completed';
        $this->amountMismatch = true;

        try {
            TuitionPesaPalPayment::reconcile($payment);
            $this->fail('Amount mismatch should be rejected');
        } catch (\App\Support\Payments\PesaPalException $e) {
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        }
    }

    public function test_incorrect_currency_rejected(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 1;
        $this->description = 'Completed';
        $this->currencyMismatch = true;

        try {
            TuitionPesaPalPayment::reconcile($payment);
            $this->fail('Currency mismatch should be rejected');
        } catch (\App\Support\Payments\PesaPalException $e) {
            $this->assertSame('pending', $payment->fresh()->status);
        }
    }

    public function test_provider_timeout_leaves_payment_unresolved(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->statusUnavailable = true;

        try {
            TuitionPesaPalPayment::reconcile($payment);
            $this->fail('Provider timeout should throw exception');
        } catch (\App\Support\Payments\PesaPalException $e) {
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        }
    }

    public function test_reversal_follows_authorized_accounting_rules(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 1;
        $this->description = 'Completed';
        TuitionPesaPalPayment::reconcile($payment);

        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
        $this->assertSame('paid', $fee->fresh()->status);

        $this->code = 3;
        $this->description = 'Reversed';

        TuitionPesaPalPayment::reconcile($payment->fresh());
        $payment->refresh();

        $this->assertSame('reversed', $payment->status);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        $this->assertSame('unpaid', $fee->fresh()->status);

        // Late success after reversal should not re-credit
        $this->code = 1;
        $this->description = 'Completed';

        $result = TuitionPesaPalPayment::reconcile($payment->fresh());
        $this->assertSame('reversed', $result);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
    }

    public function test_failure_reason_matcher_recognizes_variations(): void
    {
        $this->assertSame('INSUFFICIENT_FUNDS', PesaPalPaymentExperience::failureReason('Insufficient funds'));
        $this->assertSame('INSUFFICIENT_FUNDS', PesaPalPaymentExperience::failureReason('Insufficient balance'));
        $this->assertSame('INSUFFICIENT_FUNDS', PesaPalPaymentExperience::failureReason('Not enough funds'));
        $this->assertSame('INSUFFICIENT_FUNDS', PesaPalPaymentExperience::failureReason('Low balance'));
        $this->assertSame('INSUFFICIENT_FUNDS', PesaPalPaymentExperience::failureReason('Insufficient funds available'));

        $this->assertSame('CANCELLED', PesaPalPaymentExperience::failureReason('Cancelled by user'));
        $this->assertSame('CANCELLED', PesaPalPaymentExperience::failureReason('User cancelled'));

        $this->assertSame('EXPIRED', PesaPalPaymentExperience::failureReason('Payment expired'));
        $this->assertSame('EXPIRED', PesaPalPaymentExperience::failureReason('Session expired'));
        $this->assertSame('EXPIRED', PesaPalPaymentExperience::failureReason('Timeout'));

        $this->assertSame('AUTHORIZATION_DECLINED', PesaPalPaymentExperience::failureReason('Authorization declined'));
        $this->assertSame('AUTHORIZATION_DECLINED', PesaPalPaymentExperience::failureReason('Auth declined'));
        $this->assertSame('AUTHORIZATION_DECLINED', PesaPalPaymentExperience::failureReason('Transaction declined'));

        $this->assertNull(PesaPalPaymentExperience::failureReason('Request processed successfully'));
        $this->assertNull(PesaPalPaymentExperience::failureReason('Phone prompt sent'));
    }
}