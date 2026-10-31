<?php

namespace Tests\Feature;

use App\Models\{PaymentMethods, StudentFeeManager, TuitionPayment};
use App\Support\Payments\TuitionPesaPalPayment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Http, Schema, URL};
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * The production Pesapal dashboard has the IPN registered against
 * https://piie.ac.ug/api/pesapal/ipn (id 402714d2-47f3-4fb6-9218-d9b975394ded).
 * These tests lock in that the application answers that URL with the same
 * verification as /payments/pesapal/ipn, for both GET and POST registration
 * methods, while never crediting an invoice more than once.
 */
class PesaPalApiIpnAliasRouteTest extends TestCase
{
    use AdmissionsTestHelper;
    private const GUID = '7e6b62d9-883e-440f-a63e-e1105bbfadc3';
    private int $school;
    private \App\Models\User $student;
    private string $reference = '';
    private int $code = 1;
    private string $description = 'COMPLETED';
    private string $amount = '5000.00';
    private bool $statusUnavailable = false;
    private ?array $reject = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', fn (Blueprint $t) => $t->string('school_currency')->nullable());
        $this->school = $this->makeSchool(['school_currency' => 'UGX']);
        $this->student = \App\Models\User::create(['school_id' => $this->school, 'role_id' => 7, 'name' => 'Ada Nakato', 'email' => 'student@example.test']);
        config(['app.url' => 'https://piie.example.test', 'cache.default' => 'array']);
        URL::forceRootUrl('https://piie.example.test'); URL::forceScheme('https');
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'fixture-key', 'consumer_secret' => 'fixture-secret', 'notification_id' => self::GUID])]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'RequestToken')) { return Http::response(['token' => 'fixture', 'expiryDate' => now()->addMinutes(5)->format('Y-m-d\TH:i:s'), 'status' => '200', 'error' => null]); }
            if (str_contains($request->url(), 'SubmitOrderRequest')) {
                if ($this->reject !== null) { return Http::response($this->reject, 422); }
                return Http::response(['order_tracking_id' => self::GUID, 'merchant_reference' => $request['id'],
                    'redirect_url' => 'https://cybqa.pesapal.com/pay?OrderTrackingId=' . self::GUID, 'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'GetTransactionStatus')) {
                if ($this->statusUnavailable) { return Http::response([], 503); }
                return Http::response(['merchant_reference' => $this->reference, 'amount' => $this->amount, 'currency' => 'UGX',
                    'status_code' => $this->code, 'payment_status_description' => $this->description,
                    'payment_method' => 'Card', 'confirmation_code' => 'PROVIDER-123', 'status' => '200', 'error' => null]);
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

    private function startOrder(): TuitionPayment
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->reference = $payment->reference;
        $this->assertSame(self::GUID, $payment->gateway_txn_id);
        return $payment;
    }

    public function test_registered_api_alias_get_verifies_and_credits_exactly_once(): void
    {
        $this->startOrder();
        $query = ['OrderTrackingId' => self::GUID, 'OrderMerchantReference' => $this->reference];
        $this->get(route('applicant.pesapal.ipn.api', $query))->assertOk()
            ->assertJson(['status' => 200, 'orderMerchantReference' => $this->reference]);
        $fee = StudentFeeManager::sole();
        $this->assertSame(5000, (int) $fee->paid_amount);
        $this->assertSame('paid', $fee->status);
        // A duplicate GET notification from Pesapal must never credit again,
        // and IPN handling itself must never submit a new order.
        $this->get(route('applicant.pesapal.ipn.api', $query))->assertOk()->assertJson(['status' => 200]);
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'SubmitOrderRequest')));
    }

    public function test_registered_api_alias_post_without_csrf_token_behaves_identically(): void
    {
        $this->startOrder();
        $this->post(route('applicant.pesapal.ipn.api.post'),
            ['OrderTrackingId' => self::GUID, 'OrderMerchantReference' => $this->reference])->assertOk();
        $fee = StudentFeeManager::sole();
        $this->assertSame(5000, (int) $fee->paid_amount);
        $this->assertSame('paid', $fee->status);
    }

    public function test_original_administered_ipn_url_remains_active_on_the_same_handler(): void
    {
        $this->startOrder();
        $this->get(route('applicant.pesapal.ipn', ['OrderTrackingId' => self::GUID, 'OrderMerchantReference' => $this->reference]))->assertOk();
        $this->assertSame('paid', StudentFeeManager::sole()->status);
    }

    public function test_alias_rejects_a_malformed_tracking_id_without_ledger_changes(): void
    {
        $this->startOrder();
        $this->get(route('applicant.pesapal.ipn.api', ['OrderTrackingId' => 'not-a-guid', 'OrderMerchantReference' => $this->reference]))->assertStatus(422);
        $this->assertSame('unpaid', StudentFeeManager::sole()->status);
    }

    public function test_alias_response_reports_verified_failure_without_crediting(): void
    {
        $this->startOrder();
        $this->code = 2; $this->description = 'FAILED';
        $path = route('applicant.pesapal.ipn.api', ['OrderTrackingId' => self::GUID, 'OrderMerchantReference' => $this->reference]);
        // The shared handler answers 200 (the ledger records 'failed'); only
        // rejected application settlement answers 422. Nothing is credited.
        $this->get($path)->assertOk()->assertJson(['status' => 200]);
        $this->assertSame('failed', TuitionPayment::sole()->status);
        $this->assertSame('unpaid', StudentFeeManager::sole()->status);
    }

    /**
     * Provider 4xx/5xx responses must be diagnosable in sanitized form: HTTP
     * status and provider error fields only, with credential content absent
     * from diagnostics even though the request carried it.
     */
    public function test_provider_rejections_are_logged_sanitized_without_secrets(): void
    {
        $log = storage_path('logs/pesapal-alias-diagnostics.log');
        @unlink($log);
        config(['logging.default' => 'pesapal_alias_diagnostics',
            'logging.channels.pesapal_alias_diagnostics' => ['driver' => 'single', 'path' => $log, 'level' => 'debug']]);
        $fee = $this->fee();
        $this->reject = ['status' => 422, 'error' => ['code' => 'GENERIC_ERROR', 'message' => 'Validation failed: check order payload']];
        $this->actingAs($this->student)->from('https://piie.example.test')
            ->post(route('student.payment.pesapal.start', $fee->id), ['payment_option' => 'balance'])
            ->assertRedirect();
        // A provider rejection must not strand a submitted order: no tracking
        // id exists, so the attempt remains a reservable waiting row.
        $payment = TuitionPayment::where('fee_id', $fee->id)->sole();
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->gateway_txn_id);
        $this->assertSame('reserved', $payment->gateway_payload['initiation'] ?? null);
        $written = strtolower(file_get_contents($log));
        $this->assertStringContainsString('pesapal: pesapal rejected the request.', $written);
        $this->assertStringContainsString('submitorderrequest', $written); // Exact failing endpoint is visible.
        $this->assertStringContainsString('"http_status":422', $written);
        $this->assertStringContainsString('generic_error', $written);
        $this->assertStringContainsString('validation failed: check order payload', $written);
        // The sandbox fixture credentials travelled in the request body; they
        // must never appear in diagnostics regardless of provider echo.
        $this->assertStringNotContainsString('fixture-key', $written);
        $this->assertStringNotContainsString('fixture-secret', $written);
    }
}
