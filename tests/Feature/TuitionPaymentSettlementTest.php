<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PaymentMethods;
use App\Models\StudentFeeManager;
use App\Support\Payments\TuitionFeeSettlement;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * The tuition settlement boundary.
 *
 * The vulnerability these lock down: POST /webhooks/marzpay is unauthenticated,
 * CSRF-exempt and unthrottled. It used to take an invoice id straight from the
 * posted metadata and mark that invoice paid, without ever checking that the
 * posted transaction belonged to it. Anyone holding one successful collection in
 * a school could therefore mark any other invoice in that school paid.
 *
 * The fix routes tuition through TuitionFeeSettlement, which re-fetches the
 * transaction recorded on the invoice itself. These tests prove the specific
 * ways that must not settle, and that legitimate settlement still works.
 *
 * Runs entirely on in-memory SQLite (tests/CreatesApplication.php poisons the
 * MySQL connection), with MarzPay's API faked. No network, no production data.
 */
class TuitionPaymentSettlementTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $schoolId;
    private int $otherSchoolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->schoolId = $this->makeSchool();
        $this->otherSchoolId = $this->makeSchool();

        PaymentMethods::create([
            'name' => 'marzpay', 'image' => 'marzpay.png', 'status' => 1, 'mode' => 'test',
            'school_id' => $this->schoolId,
            'payment_keys' => json_encode([
                'sandbox_api_key' => 'k', 'sandbox_api_secret' => 's', 'country' => 'UG',
            ]),
        ]);

        // `schools.school_currency` is a restored compatibility column the shared
        // test schema does not carry. It is added here for the same reason two
        // other tests add `student_fee_managers.document_image`: without it the
        // currency assertion in the settlement boundary cannot be exercised, and
        // would silently pass without comparing anything.
        if (! \Illuminate\Support\Facades\Schema::hasColumn('schools', 'school_currency')) {
            \Illuminate\Support\Facades\Schema::table('schools', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->string('school_currency')->nullable();
            });
        }

        // Written the way the payment-settings dropdown writes it: the ISO `code`,
        // not a symbol.
        \Illuminate\Support\Facades\DB::table('schools')
            ->where('id', $this->schoolId)->update(['school_currency' => 'UGX']);
    }

    /** A faked provider record for $uuid, settled and paid $raw in UGX. */
    private function fakeProvider(string $uuid, string $status = 'successful', string|int $raw = 5000, string $currency = 'UGX'): void
    {
        Http::fake([
            'wallet.wearemarz.com/api/v1/collect-money/*' => Http::response([
                'status' => 'success',
                'data' => [
                    'transaction' => ['uuid' => $uuid, 'reference' => 'ref-'.$uuid, 'status' => $status],
                    'collection'  => ['amount' => ['raw' => $raw, 'currency' => $currency]],
                ],
            ], 200),
        ]);
    }

    /** An invoice that has started a MarzPay payment. */
    private function processingFee(int $total = 5000, ?string $gatewayReference = 'txn-own', array $overrides = []): StudentFeeManager
    {
        return StudentFeeManager::create(array_merge([
            'title' => 'Tuition', 'total_amount' => $total, 'amount' => $total, 'class_id' => 0,
            'student_id' => 1, 'payment_method' => 'marzpay', 'paid_amount' => 0, 'status' => 'processing',
            'school_id' => $this->schoolId, 'gateway_reference' => $gatewayReference,
        ], $overrides));
    }

    private function postWebhook(string $context, int $contextId, string $uuid = 'txn-foreign'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('webhooks.marzpay'), [
            'event_type'  => 'collection.completed',
            'transaction' => ['uuid' => $uuid, 'reference' => 'ref-foreign', 'status' => 'completed'],
            'collection'  => ['amount' => ['raw' => 5000]],
            'metadata'    => [['context' => $context], ['context_id' => $contextId]],
        ]);
    }

    // ── The forgery this fixes ────────────────────────────────────────────

    /**
     * The core regression. An attacker holds a genuinely successful collection
     * ("txn-foreign") and posts it naming someone else's invoice. That invoice
     * recorded its own reference, so the lookup consults THAT transaction — which
     * the provider does not know — and nothing is credited.
     */
    public function test_a_webhook_naming_an_invoice_cannot_settle_it_with_a_foreign_transaction(): void
    {
        $victim = $this->processingFee(gatewayReference: 'txn-victim');

        // The attacker's own successful collection is real to the provider.
        $this->fakeProvider('txn-foreign');

        $this->postWebhook('tuition', $victim->id, 'txn-foreign')->assertOk();

        $victim->refresh();
        $this->assertSame('processing', $victim->status, 'A foreign transaction must not settle another invoice.');
        $this->assertSame(0, (int) $victim->paid_amount);
    }

    /** Two invoices, one successful transaction: only the invoice that started it settles. */
    public function test_a_transaction_settles_only_the_invoice_that_recorded_it(): void
    {
        $mine = $this->processingFee(gatewayReference: 'txn-mine');
        $theirs = $this->processingFee(gatewayReference: 'txn-theirs');

        $this->fakeProvider('txn-mine');

        $this->postWebhook('tuition', $mine->id, 'txn-mine');
        $this->postWebhook('tuition', $theirs->id, 'txn-mine');

        $this->assertSame('paid', $mine->fresh()->status);
        $this->assertSame('processing', $theirs->fresh()->status, 'Only the invoice that recorded the transaction may settle from it.');
    }

    /** An invoice whose payment was never started has no reference to verify against. */
    public function test_an_invoice_with_no_recorded_transaction_cannot_be_settled(): void
    {
        $fee = $this->processingFee(gatewayReference: null);

        $this->fakeProvider('txn-any');

        // A payment cannot appear later for a reference we never hold, so this
        // is a definitive "no" (200) rather than a retryable one (503).
        $this->postWebhook('tuition', $fee->id, 'txn-any')->assertOk();

        $this->assertSame('processing', $fee->fresh()->status);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
    }

    // ── Provider authenticity and evidence ────────────────────────────────

    /** Amount is compared exactly, in minor units. A short payment credits nothing. */
    public function test_a_provider_amount_that_does_not_match_the_invoice_credits_nothing(): void
    {
        $fee = $this->processingFee(total: 5000, gatewayReference: 'txn-1');

        // The payer sent 100, the invoice is 5000.
        $this->fakeProvider('txn-1', 'successful', 100);

        $this->postWebhook('tuition', $fee->id, 'txn-1')->assertOk();

        $fee->refresh();
        $this->assertSame('processing', $fee->status);
        $this->assertSame(0, (int) $fee->paid_amount);
    }

    /**
     * A currency the tenant does not accept is rejected.
     *
     * setUp() adds `schools.school_currency` and configures it as UGX, so this is
     * a real comparison rather than a skip — the same approach the existing
     * finance tests take when they need a column the shared schema lacks.
     */
    public function test_a_provider_currency_that_differs_from_the_tenant_is_rejected(): void
    {
        $fee = $this->processingFee(gatewayReference: 'txn-1');

        $this->fakeProvider('txn-1', 'successful', 5000, 'USD');

        $this->postWebhook('tuition', $fee->id, 'txn-1')->assertOk();

        $this->assertSame('processing', $fee->fresh()->status);
    }

    /** A provider that cannot be consulted must not be treated as a success. */
    public function test_an_unreachable_provider_leaves_the_invoice_alone_and_asks_for_retry(): void
    {
        $fee = $this->processingFee(gatewayReference: 'txn-1');

        Http::fake([
            'wallet.wearemarz.com/api/v1/collect-money/*' => Http::response(['status' => 'error'], 500),
        ]);

        // 503, so MarzPay retries rather than the payment being lost silently.
        $this->postWebhook('tuition', $fee->id, 'txn-1')->assertStatus(503);

        $this->assertSame('processing', $fee->fresh()->status);
    }

    /** An incomplete provider record is not evidence of anything. */
    public function test_an_incomplete_provider_record_credits_nothing(): void
    {
        $fee = $this->processingFee(gatewayReference: 'txn-1');

        Http::fake([
            'wallet.wearemarz.com/api/v1/collect-money/*' => Http::response([
                'status' => 'success',
                'data' => ['transaction' => ['uuid' => 'txn-1', 'status' => 'successful']],
            ], 200),
        ]);

        $this->postWebhook('tuition', $fee->id, 'txn-1')->assertStatus(503);

        $this->assertSame('processing', $fee->fresh()->status);
    }

    /**
     * Tenant isolation: an invoice of another school is never settled.
     *
     * `resolveSchoolId()` derives the tenant FROM the posted invoice id, so this
     * endpoint does reach another tenant's row. What stops it crediting is that
     * the settlement re-reads the invoice under its own school and the evidence
     * carries that school, so the two must agree.
     */
    public function test_an_invoice_belonging_to_another_school_is_never_settled(): void
    {
        $foreign = $this->processingFee(gatewayReference: 'txn-1', overrides: ['school_id' => $this->otherSchoolId]);

        // Credentials exist only for the first school, so the other tenant is
        // not configured and its callback is not actionable.
        $this->fakeProvider('txn-1');

        $this->postWebhook('tuition', $foreign->id, 'txn-1')->assertOk();

        $this->assertSame('processing', $foreign->fresh()->status);
        $this->assertSame(0, (int) $foreign->fresh()->paid_amount);
    }

    // ── Status handling ───────────────────────────────────────────────────

    public function test_the_invoice_settles_when_the_provider_confirms_its_own_transaction(): void
    {
        $fee = $this->processingFee(total: 5000, gatewayReference: 'txn-1');

        $this->fakeProvider('txn-1', 'successful', 5000, 'UGX');

        $this->postWebhook('tuition', $fee->id, 'txn-1')->assertOk();

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertSame(5000, (int) $fee->paid_amount);
        $this->assertSame('marzpay', $fee->payment_method);
    }

    public function test_a_redelivered_webhook_does_not_double_apply(): void
    {
        $fee = $this->processingFee(gatewayReference: 'txn-1');

        $this->fakeProvider('txn-1');

        $this->postWebhook('tuition', $fee->id, 'txn-1');
        $this->postWebhook('tuition', $fee->id, 'txn-1');

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertSame(5000, (int) $fee->paid_amount);
        $this->assertSame(1, AuditLog::where('description', 'like', "%Tuition fee #{$fee->id} verified%")->count());
    }

    /** A pending provider status must never be laundered into "paid". */
    public function test_a_still_pending_collection_is_not_paid(): void
    {
        $fee = $this->processingFee(gatewayReference: 'txn-1');

        $this->fakeProvider('txn-1', 'processing');

        $this->postWebhook('tuition', $fee->id, 'txn-1')->assertOk();

        $this->assertSame('processing', $fee->fresh()->status);
    }

    public function test_a_failed_collection_marks_the_invoice_failed(): void
    {
        $fee = $this->processingFee(gatewayReference: 'txn-1');

        $this->fakeProvider('txn-1', 'failed');

        $this->postWebhook('tuition', $fee->id, 'txn-1')->assertOk();

        $fee->refresh();
        $this->assertSame('failed', $fee->status);
        $this->assertSame(0, (int) $fee->paid_amount);
    }

    // ── The settlement boundary in isolation ──────────────────────────────

    /** Settlement is idempotent at the boundary, not only at the HTTP layer. */
    public function test_settlement_reports_already_settled_and_never_re_credits(): void
    {
        $fee = $this->processingFee(gatewayReference: 'txn-1');
        $this->fakeProvider('txn-1');
        $evidence = \App\Support\Payments\MarzPayService::verifyTuitionPayment($fee);

        $this->assertNotNull($evidence);
        $this->assertSame(TuitionFeeSettlement::SETTLED, TuitionFeeSettlement::apply($evidence));
        $this->assertSame(TuitionFeeSettlement::ALREADY_SETTLED, TuitionFeeSettlement::apply($evidence));
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
    }

    /** Evidence for one invoice cannot settle another, even handed over directly. */
    public function test_evidence_for_one_invoice_is_rejected_for_another(): void
    {
        $mine = $this->processingFee(gatewayReference: 'txn-1');
        $theirs = $this->processingFee(gatewayReference: 'txn-2');

        $this->fakeProvider('txn-1');
        $evidence = \App\Support\Payments\MarzPayService::verifyTuitionPayment($mine);

        // Re-point the evidence at the other invoice, as a forged caller would.
        $forged = new \App\Support\Payments\VerifiedTuitionPayment(
            $theirs->id, $evidence->schoolId, $evidence->provider,
            $evidence->reference, $evidence->transactionId, $evidence->amount,
            $evidence->currency, $evidence->status, $evidence->payload,
        );

        $this->assertSame(TuitionFeeSettlement::REJECTED, TuitionFeeSettlement::apply($forged));
        $this->assertSame('processing', $theirs->fresh()->status);
    }

    /** Tenant isolation at the boundary. */
    public function test_evidence_never_settles_an_invoice_in_another_tenant(): void
    {
        $mine = $this->processingFee(gatewayReference: 'txn-1');
        $foreign = $this->processingFee(gatewayReference: 'txn-1', overrides: ['school_id' => $this->otherSchoolId]);

        $this->fakeProvider('txn-1');
        $evidence = \App\Support\Payments\MarzPayService::verifyTuitionPayment($mine);

        $mislabelled = new \App\Support\Payments\VerifiedTuitionPayment(
            $foreign->id, $evidence->schoolId, $evidence->provider,
            $evidence->reference, $evidence->transactionId, $evidence->amount,
            $evidence->currency, $evidence->status, $evidence->payload,
        );

        $this->assertSame(TuitionFeeSettlement::REJECTED, TuitionFeeSettlement::apply($mislabelled));
        $this->assertSame('processing', $foreign->fresh()->status);
    }
}