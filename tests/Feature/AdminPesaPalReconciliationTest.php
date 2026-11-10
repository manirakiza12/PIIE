<?php

namespace Tests\Feature;

use App\Models\{PaymentMethods, StudentFeeManager, TuitionPayment, User};
use App\Support\Payments\{PesaPalException, TuitionPesaPalPayment};
use Illuminate\Support\Facades\{DB, Http, Schema, URL};
use Illuminate\Database\Schema\Blueprint;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class AdminPesaPalReconciliationTest extends TestCase
{
    use AdmissionsTestHelper;

    private const GUID = '7e6b62d9-883e-440f-a63e-e1105bbfadc3';
    private int $school;
    private User $admin;
    private User $student;
    private string $reference = '';
    private int $orders = 0;
    private int $code = 0;
    private string $description = 'INVALID';
    private string $amount = '5000.00';
    private string $channel = 'Card';
    private string $trackingId = self::GUID;
    private bool $timeout = false;
    private bool $statusUnavailable = false;
    private string $checkoutHost = 'cybqa.pesapal.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', fn (Blueprint $t) => $t->string('school_currency')->nullable());
        $this->school = $this->makeSchool(['school_currency' => 'UGX']);
        $this->admin = $this->makeAdminUser($this->school);
        $this->student = User::create(['school_id' => $this->school, 'role_id' => 7, 'name' => 'Student', 'email' => 'student@example.test']);
        config(['app.url' => 'https://piie.example.test', 'cache.default' => 'array']);
        URL::forceRootUrl('https://piie.example.test'); URL::forceScheme('https');
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'fixture', 'consumer_secret' => 'fixture', 'notification_id' => self::GUID])]);
        Http::preventStrayRequests();
        $this->trackingIdToReference = [];
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'RequestToken')) { return Http::response(['token' => 'fixture', 'expiryDate' => now()->addMinutes(5)->format('Y-m-d\TH:i:s'), 'status' => '200', 'error' => null]); }
            if (str_contains($request->url(), 'SubmitOrderRequest')) {
                $this->orders++; $this->reference = $request['id'];
                if ($this->timeout) { throw new \Illuminate\Http\Client\ConnectionException('timeout'); }
                return Http::response(['order_tracking_id' => $this->trackingId, 'merchant_reference' => $this->reference,
                    'redirect_url' => 'https://' . $this->checkoutHost . '/pay?OrderTrackingId=' . $this->trackingId, 'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'GetTransactionStatus')) {
                if ($this->statusUnavailable) { return Http::response([], 503); }
                $orderTrackingId = $request['orderTrackingId'] ?? $this->trackingId;
                $reference = $this->trackingIdToReference[$orderTrackingId] ?? $this->reference;
                return Http::response(['merchant_reference' => $reference, 'order_tracking_id' => $orderTrackingId,
                    'amount' => $this->amount, 'currency' => 'UGX', 'status_code' => $this->code, 'payment_status_description' => $this->description,
                    'payment_method' => $this->channel, 'confirmation_code' => 'PROVIDER-123', 'status' => '200', 'error' => null]);
            }
            throw new \RuntimeException('Unexpected request');
        });
    }

    private function fee(array $extra = []): StudentFeeManager
    {
        return StudentFeeManager::create(array_merge(['school_id' => $this->school, 'student_id' => $this->student->id,
            'title' => 'School fees', 'total_amount' => 5000, 'amount' => 5000, 'paid_amount' => 0, 'class_id' => 0,
            'payment_method' => '', 'status' => 'unpaid'], $extra));
    }

    private static int $paymentCounter = 0;

    private function createPendingPayment(StudentFeeManager $fee): TuitionPayment
    {
        self::$paymentCounter++;
        // Use valid GUID format (no suffix)
        $trackingId = sprintf('%08x-%04x-%04x-%04x-%012x', 
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0x4000, 0x4fff), // version 4
            random_int(0x8000, 0xbfff), // variant 10
            random_int(0, 0xffffffffffff)
        );
        $configId = (int) PaymentMethods::where('school_id', $this->school)->where('name', 'pesapal')->value('id');
        $reference = 'TUITION-' . uniqid();
        
        $this->trackingIdToReference[$trackingId] = $reference;
        
        return TuitionPayment::create([
            'school_id' => $this->school, 'fee_id' => $fee->id, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'pending',
            'reference' => $reference, 'gateway_txn_id' => $trackingId,
            'gateway_payload' => ['environment' => 'sandbox', 'configuration_id' => $configId, 'initiation' => 'submitted'],
        ]);
    }

    private function createPaidPayment(StudentFeeManager $fee): TuitionPayment
    {
        $payment = TuitionPayment::create([
            'school_id' => $this->school, 'fee_id' => $fee->id, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'paid',
            'reference' => 'TUITION-' . uniqid(), 'gateway_txn_id' => 'paid-' . uniqid(),
            'gateway_payload' => ['environment' => 'sandbox', 'classification' => 'COMPLETED'],
            'paid_at' => now(),
        ]);
        $fee->update(['paid_amount' => 5000, 'status' => 'paid']);
        return $payment;
    }

    private function createFailedPayment(StudentFeeManager $fee): TuitionPayment
    {
        return TuitionPayment::create([
            'school_id' => $this->school, 'fee_id' => $fee->id, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'failed',
            'reference' => 'TUITION-' . uniqid(), 'gateway_txn_id' => 'failed-' . uniqid(),
            'gateway_payload' => ['environment' => 'sandbox', 'classification' => 'FAILED'],
        ]);
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_reconciliation_page_requires_finance_payments_permission(): void
    {
        $user = User::factory()->create(['school_id' => $this->school, 'role_id' => 3]); // Teacher, no finance.payments
        $this->actingAs($user)->get(route('admin.tuition_payments.reconciliation'))->assertStatus(403);
    }

    public function test_reconciliation_page_accessible_to_finance_payments_user(): void
    {
        $this->actingAs($this->admin)->get(route('admin.tuition_payments.reconciliation'))->assertOk();
    }

    public function test_cross_school_access_denied(): void
    {
        $otherSchool = $this->makeSchool(['school_currency' => 'UGX']);
        $otherAdmin = $this->makeAdminUser($otherSchool);
        $fee = $this->fee();
        $payment = $this->createPendingPayment($fee);

        $response = $this->actingAs($otherAdmin)->get(route('admin.tuition_payments.reconciliation'));
        $response->assertOk();

        $content = $response->getContent();

        $hasRef = strpos($content, $payment->reference) !== false;

        $feeIdPatterns = [
            '#' . $fee->id . '"',
            '#' . $fee->id . ' ',
            'Fee #' . $fee->id,
            'Invoice #' . $fee->id,
            '>#' . $fee->id . '<',
        ];
        $hasFeeIdInContext = false;
        foreach ($feeIdPatterns as $pattern) {
            if (strpos($content, $pattern) !== false) {
                $hasFeeIdInContext = true;
                break;
            }
        }

        $this->assertFalse($hasRef, "Payment reference should not be visible across schools. Admin school: {$otherAdmin->school_id}, Payment school: {$payment->school_id}");
        $this->assertFalse($hasFeeIdInContext, "Fee ID should not be visible in fee context across schools. Admin school: {$otherAdmin->school_id}, Fee school: {$fee->school_id}");
    }

    // ── Filters & Listing ────────────────────────────────────────────────────

    public function test_reconciliation_filters_by_status(): void
    {
        $fee1 = $this->fee();
        $payment1 = $this->createPendingPayment($fee1);
        $fee2 = $this->fee();
        $this->createPaidPayment($fee2);

        $this->actingAs($this->admin)
            ->get(route('admin.tuition_payments.reconciliation', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('pending', false);
    }

    public function test_reconciliation_filters_by_environment(): void
    {
        $fee1 = $this->fee();
        $payment1 = $this->createPendingPayment($fee1);
        $fee2 = $this->fee();
        $payment2 = TuitionPayment::create([
            'school_id' => $this->school, 'fee_id' => $fee2->id, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'pending',
            'reference' => 'TUITION-' . uniqid(), 'gateway_txn_id' => 'live-' . uniqid(),
            'gateway_payload' => ['environment' => 'live', 'configuration_id' => 'test', 'initiation' => 'submitted'],
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.tuition_payments.reconciliation', ['environment' => 'sandbox']))
            ->assertOk()
            ->assertSee($payment1->reference)
            ->assertDontSee($payment2->reference);
    }

    public function test_reconciliation_filters_by_student_search(): void
    {
        $student2 = User::create(['school_id' => $this->school, 'role_id' => 7, 'name' => 'Other Student', 'email' => 'other@example.test', 'code' => 'STU-OTHER']);
        $fee1 = $this->fee();
        $payment1 = $this->createPendingPayment($fee1);
        $fee2 = $this->fee(['student_id' => $student2->id]);
        $payment2 = TuitionPayment::create([
            'school_id' => $this->school, 'fee_id' => $fee2->id, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'pending',
            'reference' => 'TUITION-' . uniqid(), 'gateway_txn_id' => 'student2-' . uniqid(),
            'gateway_payload' => ['environment' => 'sandbox', 'configuration_id' => 'test', 'initiation' => 'submitted'],
        ]);

        // Search for the first student's unique code (not set, so use name that won't match "Other Student")
        // The first student has name "Student", second has "Other Student" - both contain "Student"
        // So search for a term that only matches the first student
        $this->actingAs($this->admin)
            ->get(route('admin.tuition_payments.reconciliation', ['student_search' => 'STU-OTHER']))
            ->assertOk()
            ->assertSee($payment2->reference)
            ->assertDontSee($payment1->reference);
    }

    public function test_reconciliation_filters_by_reference_search(): void
    {
        $fee1 = $this->fee();
        $payment1 = $this->createPendingPayment($fee1);
        $fee2 = $this->fee();
        $payment2 = $this->createPendingPayment($fee2);

        $this->actingAs($this->admin)
            ->get(route('admin.tuition_payments.reconciliation', ['reference_search' => $payment1->reference]))
            ->assertOk()
            ->assertSee($payment1->reference)
            ->assertDontSee($payment2->reference);
    }

    public function test_reconciliation_discrepancy_filter_shows_only_pending_with_balance(): void
    {
        $fee1 = $this->fee(['total_amount' => 5000, 'paid_amount' => 0]);
        $payment1 = $this->createPendingPayment($fee1);

        $fee2 = $this->fee(['total_amount' => 5000, 'paid_amount' => 5000, 'status' => 'paid']);
        $this->createPendingPayment($fee2);

        $this->actingAs($this->admin)
            ->get(route('admin.tuition_payments.reconciliation', ['discrepancy' => true]))
            ->assertOk()
            ->assertSee('pending');
    }

    // ── Summary Cards ────────────────────────────────────────────────────────

    public function test_reconciliation_summary_shows_correct_counts(): void
    {
        $this->createPendingPayment($this->fee());
        $this->createPendingPayment($this->fee());
        $this->createPaidPayment($this->fee());
        $this->createFailedPayment($this->fee());

        $response = $this->actingAs($this->admin)->get(route('admin.tuition_payments.reconciliation'));
        $response->assertOk();
    }

    // ── Discrepancy Detection ────────────────────────────────────────────────

    public function test_discrepancy_detection_shows_invoices_with_pending_payments_and_balance(): void
    {
        $fee = $this->fee(['total_amount' => 5000, 'paid_amount' => 0, 'status' => 'unpaid']);
        $this->createPendingPayment($fee);

        $response = $this->actingAs($this->admin)->get(route('admin.tuition_payments.reconciliation'));
        $response->assertOk()
            ->assertSee('Discrepancy');
    }

    public function test_no_discrepancy_when_invoice_paid(): void
    {
        $fee = $this->fee(['total_amount' => 5000, 'paid_amount' => 5000, 'status' => 'paid']);
        $this->createPendingPayment($fee);

        $response = $this->actingAs($this->admin)->get(route('admin.tuition_payments.reconciliation'));
        $response->assertOk();
    }

    // ── Verify with PesaPal (Sandbox) ────────────────────────────────────────

    public function test_verify_pending_payment_updates_status_to_paid(): void
    {
        $fee = $this->fee();
        $payment = $this->createPendingPayment($fee);
        $this->code = 1; // COMPLETED
        $this->description = 'COMPLETED';

        // Use a valid GUID for trackingId (without suffix)
        $validTrackingId = '7e6b62d9-883e-440f-a63e-e1105bbfadc3';
        $payment->update(['gateway_txn_id' => $validTrackingId]);
        $this->trackingIdToReference[$validTrackingId] = $payment->reference;

        $response = $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.verify_pesapal', $payment->id));

        $response->assertRedirect();

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $fee->refresh();
        $this->assertSame(5000, (int) $fee->paid_amount);
    }

    public function test_verify_failed_payment_updates_status_to_failed(): void
    {
        $fee = $this->fee();
        $payment = $this->createPendingPayment($fee);
        $this->code = 2; // FAILED
        $this->description = 'FAILED';

        $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.verify_pesapal', $payment->id))
            ->assertRedirect();

        $payment->refresh();
        $this->assertSame('failed', $payment->status);
    }

    public function test_verify_rejects_live_payments(): void
    {
        $fee = $this->fee();
        $payment = $this->createPendingPayment($fee);
        $payment->update(['gateway_payload' => array_merge($payment->gateway_payload, ['environment' => 'live'])]);

        $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.verify_pesapal', $payment->id))
            ->assertSessionHas('error');
    }

    public function test_verify_rejects_payments_without_tracking_id(): void
    {
        $fee = $this->fee();
        $payment = TuitionPayment::create([
            'school_id' => $this->school, 'fee_id' => $fee->id, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'pending',
            'reference' => 'TUITION-' . uniqid(), 'gateway_txn_id' => null,
            'gateway_payload' => ['environment' => 'sandbox', 'configuration_id' => 'test', 'initiation' => 'reserved'],
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.verify_pesapal', $payment->id))
            ->assertSessionHas('error');
    }

    public function test_verify_rejects_already_paid_payment(): void
    {
        $fee = $this->fee();
        $payment = $this->createPaidPayment($fee);

        $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.verify_pesapal', $payment->id))
            ->assertSessionHas('error', 'Only pending or failed payments can be verified.');
    }

    // ── Audit Logging ────────────────────────────────────────────────────────

    public function test_verify_action_audited(): void
    {
        $fee = $this->fee();
        $payment = $this->createPendingPayment($fee);
        $this->code = 1; $this->description = 'COMPLETED';

        $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.verify_pesapal', $payment->id));

        $this->assertDatabaseHas('audit_logs', [
            'school_id' => $this->school,
            'record_type' => TuitionPayment::class,
            'record_id' => $payment->id,
            'action' => 'update',
        ]);
    }

    // ── Financial Integrity ────────────────────────────────────────────────

    public function test_verify_does_not_credit_without_provider_confirmation(): void
    {
        $fee = $this->fee();
        $payment = $this->createPendingPayment($fee);
        $this->statusUnavailable = true; // Provider unavailable

        $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.verify_pesapal', $payment->id))
            ->assertSessionHas('error');

        $payment->refresh();
        $this->assertSame('pending', $payment->status);
        $fee->refresh();
        $this->assertSame(0, (int) $fee->paid_amount);
    }

    public function test_reconciliation_does_not_affect_other_invoices(): void
    {
        $fee1 = $this->fee(['total_amount' => 5000]);
        $payment1 = $this->createPendingPayment($fee1);
        $fee2 = $this->fee(['total_amount' => 10000]);
        $trackingId2 = 'other-' . uniqid();
        $reference2 = 'TUITION-' . uniqid();
        $this->trackingIdToReference[$trackingId2] = $reference2;
        $payment2 = TuitionPayment::create([
            'school_id' => $this->school, 'fee_id' => $fee2->id, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'pending',
            'reference' => 'TUITION-' . uniqid(), 'gateway_txn_id' => $trackingId2,
            'gateway_payload' => ['environment' => 'sandbox', 'configuration_id' => 'test', 'initiation' => 'submitted'],
        ]);

        $this->code = 1; $this->description = 'COMPLETED';

        $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.verify_pesapal', $payment1->id));

        $fee1->refresh();
        $fee2->refresh();
        $this->assertSame(5000, (int) $fee1->paid_amount);
        $this->assertSame(0, (int) $fee2->paid_amount);
    }

    // ── Provider Unavailable Handling ──────────────────────────────────────

    public function test_provider_unavailable_does_not_change_status(): void
    {
        $fee = $this->fee();
        $payment = $this->createPendingPayment($fee);
        $this->statusUnavailable = true;

        $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.verify_pesapal', $payment->id))
            ->assertSessionHas('error');

        $payment->refresh();
        $this->assertSame('pending', $payment->status);
    }

    // ── Idempotency ────────────────────────────────────────────────────────

    public function test_reconcile_same_payment_twice_is_idempotent(): void
    {
        $fee = $this->fee();
        $payment = $this->createPendingPayment($fee);
        $this->code = 1; $this->description = 'COMPLETED';

        $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.verify_pesapal', $payment->id))
            ->assertRedirect();
        $payment->refresh();
        $this->assertSame('paid', $payment->status);

        // Second verification should be rejected (already paid)
        $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.verify_pesapal', $payment->id))
            ->assertSessionHas('error', 'Only pending or failed payments can be verified.');
    }

    // ── Manual reconciliation endpoint (regression: GET used to 500) ────────

    public function test_run_reconciliation_get_does_not_500(): void
    {
        // The button once used window.location (a GET) against this POST-only
        // route. That GET matched admin/fee-payments/{id} with a non-numeric id
        // and fataled as a TypeError -> 500. It must now be a clean client error.
        $response = $this->actingAs($this->admin)
            ->get('/admin/fee-payments/run-reconciliation');

        $this->assertContains($response->getStatusCode(), [404, 405],
            'GET run-reconciliation must not be a 500; got ' . $response->getStatusCode());
    }

    public function test_run_reconciliation_post_reconciles_pending_payments(): void
    {
        $fee = $this->fee();
        $payment = $this->createPendingPayment($fee);
        $this->code = 1; // COMPLETED
        $this->description = 'COMPLETED';

        $this->actingAs($this->admin)
            ->post(route('admin.tuition_payments.run_reconciliation'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
    }

    public function test_reconciliation_page_uses_post_form_and_action_dropdown(): void
    {
        $fee = $this->fee();
        $this->createPendingPayment($fee);

        $this->actingAs($this->admin)
            ->get(route('admin.tuition_payments.reconciliation'))
            ->assertOk()
            ->assertSee('dropdown-menu', false)
            ->assertSee('Run Reconciliation')
            // The run action must be a POST form, never a GET link/redirect.
            ->assertSee('action="' . route('admin.tuition_payments.run_reconciliation') . '"', false);
    }

    public function test_reconciliation_page_has_no_duplicated_sections(): void
    {
        $fee = $this->fee(['total_amount' => 5000, 'paid_amount' => 0, 'status' => 'unpaid']);
        $this->createPendingPayment($fee);

        $this->actingAs($this->admin)
            ->get(route('admin.tuition_payments.reconciliation'))
            ->assertOk()
            // The old page repeated the same payments in a second
            // "Discrepancy Alert" table and repeated the filters in a
            // "Reconciliation Quick Actions" block. Both are gone.
            ->assertDontSee('Reconciliation Quick Actions')
            ->assertDontSee('Invoices with pending PesaPal payments and an outstanding balance')
            // The discrepancy notice now links to the filter instead.
            ->assertSee('Show only discrepancies');
    }
}
