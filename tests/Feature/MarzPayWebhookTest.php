<?php

namespace Tests\Feature;

use App\Models\ApplicationPayment;
use App\Models\PaymentMethods;
use App\Models\StudentFeeManager;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * MarzPayWebhookController never trusts the posted body's status on its
 * own — it re-fetches the transaction from MarzPay before applying
 * anything. These tests fake that re-fetch rather than the webhook POST's
 * own status field, since that's what actually decides the outcome.
 */
class MarzPayWebhookTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $schoolId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->schoolId = $this->makeSchool();

        PaymentMethods::create([
            'name'         => 'marzpay',
            'image'        => 'marzpay.png',
            'status'       => 1,
            'mode'         => 'test',
            'school_id'    => $this->schoolId,
            'payment_keys' => json_encode([
                'sandbox_api_key'    => 'k',
                'sandbox_api_secret' => 's',
                'country'            => 'UG',
            ]),
        ]);
    }

    private function fakeVerifiedTransaction(string $status = 'successful', string $reference = 'ref-1', string $uuid = 'txn-1'): void
    {
        Http::fake([
            'wallet.wearemarz.com/api/v1/collect-money/*' => Http::response([
                'status' => 'success',
                'data'   => [
                    'transaction' => ['uuid' => $uuid, 'reference' => $reference, 'status' => $status],
                    'collection'  => ['amount' => ['raw' => 5000, 'currency' => 'UGX']],
                ],
            ], 200),
        ]);
    }

    private function postWebhook(string $context, int $contextId, string $eventType = 'collection.completed', string $uuid = 'txn-1'): void
    {
        $this->postJson(route('webhooks.marzpay'), [
            'event_type'  => $eventType,
            'transaction' => ['uuid' => $uuid, 'reference' => 'ref-1', 'status' => 'completed'],
            'collection'  => ['amount' => ['raw' => 5000]],
            'metadata'    => [['context' => $context], ['context_id' => $contextId]],
        ])->assertOk();
    }

    public function test_it_marks_a_tuition_fee_paid_when_marzpay_confirms_the_collection(): void
    {
        $fee = StudentFeeManager::create([
            'title' => 'Term Fee', 'total_amount' => 5000, 'amount' => 5000, 'class_id' => 0,
            'student_id' => 1, 'payment_method' => 'marzpay', 'paid_amount' => 0, 'status' => 'processing',
            'school_id' => $this->schoolId, 'gateway_reference' => 'txn-1',
        ]);

        $this->fakeVerifiedTransaction('successful');

        $this->postWebhook('tuition', $fee->id);

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertEquals(5000, $fee->paid_amount);
        $this->assertSame('marzpay', $fee->payment_method);
    }

    public function test_a_redelivered_webhook_for_an_already_paid_fee_is_a_no_op(): void
    {
        $fee = StudentFeeManager::create([
            'title' => 'Term Fee', 'total_amount' => 5000, 'amount' => 5000, 'class_id' => 0,
            'student_id' => 1, 'payment_method' => 'marzpay', 'paid_amount' => 5000, 'status' => 'paid',
            'school_id' => $this->schoolId, 'gateway_reference' => 'txn-1',
        ]);

        $this->fakeVerifiedTransaction('successful');

        // Redeliver the same completed event a second time.
        $this->postWebhook('tuition', $fee->id);
        $this->postWebhook('tuition', $fee->id);

        $fee->refresh();
        $this->assertSame('paid', $fee->status);
        $this->assertEquals(5000, $fee->paid_amount);
    }

    public function test_it_marks_an_application_payment_paid_when_marzpay_confirms_the_collection(): void
    {
        $admission = $this->makeAdmission($this->schoolId);

        $payment = ApplicationPayment::create([
            'school_id' => $this->schoolId, 'admission_id' => $admission, 'method' => 'marzpay',
            'status' => ApplicationPayment::STATUS_PENDING, 'amount' => 5000, 'reference' => 'ref-1',
            'currency' => 'UGX',
            'gateway_txn_id' => 'txn-1',
        ]);

        $this->fakeVerifiedTransaction('successful');

        $this->postWebhook('application', $payment->id);

        $payment->refresh();
        $this->assertSame(ApplicationPayment::STATUS_PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_it_does_not_mark_paid_when_marzpay_reports_the_collection_is_still_pending(): void
    {
        $fee = StudentFeeManager::create([
            'title' => 'Term Fee', 'total_amount' => 5000, 'amount' => 5000, 'class_id' => 0,
            'student_id' => 1, 'payment_method' => 'marzpay', 'paid_amount' => 0, 'status' => 'processing',
            'school_id' => $this->schoolId, 'gateway_reference' => 'txn-1',
        ]);

        $this->fakeVerifiedTransaction('processing');

        $this->postWebhook('tuition', $fee->id);

        $fee->refresh();
        $this->assertSame('processing', $fee->status);
    }

    public function test_it_ignores_a_malformed_webhook_without_crashing(): void
    {
        $this->postJson(route('webhooks.marzpay'), ['garbage' => true])->assertOk();
    }

    /**
     * MarzPay redelivers on anything but a 200 — this proves the redelivery
     * itself is harmless: the settlement boundary validates the evidence and
     * returns already_settled without writing or notifying a second time.
     */
    public function test_a_duplicate_application_payment_webhook_does_not_double_apply(): void
    {
        $admission = $this->makeAdmission($this->schoolId);

        $payment = ApplicationPayment::create([
            'school_id' => $this->schoolId, 'admission_id' => $admission, 'method' => 'marzpay',
            'status' => ApplicationPayment::STATUS_PENDING, 'amount' => 5000, 'reference' => 'ref-dup',
            'currency' => 'UGX',
            'gateway_txn_id' => 'txn-dup',
        ]);

        $this->fakeVerifiedTransaction('successful', 'ref-dup', 'txn-dup');

        $this->postWebhook('application', $payment->id, 'collection.completed', 'txn-dup');
        $firstPaidAt = $payment->refresh()->paid_at;

        // Simulate MarzPay redelivering the same event a second time.
        $this->postWebhook('application', $payment->id, 'collection.completed', 'txn-dup');

        $this->assertSame(1, ApplicationPayment::where('admission_id', $admission)->count(), 'A duplicate webhook must never create a second payment row.');
        $this->assertSame(ApplicationPayment::STATUS_PAID, $payment->fresh()->status);
        $this->assertEquals($firstPaidAt, $payment->fresh()->paid_at, 'A duplicate webhook must not re-timestamp an already-settled payment.');
    }

    /**
     * Forged context_id metadata must not settle another application, even
     * when historical records share a gateway ID. The server-verified merchant
     * reference must identify the target payment as well.
     */
    public function test_a_webhook_for_one_payment_never_settles_a_different_admissions_payment(): void
    {
        $admissionA = $this->makeAdmission($this->schoolId, ['email' => 'a@example.com']);
        $admissionB = $this->makeAdmission($this->schoolId, ['email' => 'b@example.com']);

        $paymentA = ApplicationPayment::create([
            'school_id' => $this->schoolId, 'admission_id' => $admissionA, 'method' => 'marzpay',
            'status' => ApplicationPayment::STATUS_PENDING, 'amount' => 5000, 'reference' => 'ref-a',
            'currency' => 'UGX',
            'gateway_txn_id' => 'txn-1',
        ]);
        $paymentB = ApplicationPayment::create([
            'school_id' => $this->schoolId, 'admission_id' => $admissionB, 'method' => 'marzpay',
            'status' => ApplicationPayment::STATUS_PENDING, 'amount' => 5000, 'reference' => 'ref-b',
            'currency' => 'UGX',
            'gateway_txn_id' => 'txn-1',
        ]);

        $this->fakeVerifiedTransaction('successful', 'ref-a');

        $this->postWebhook('application', $paymentA->id);
        $this->postWebhook('application', $paymentB->id);

        $this->assertSame(ApplicationPayment::STATUS_PAID, $paymentA->fresh()->status);
        $this->assertSame(ApplicationPayment::STATUS_PENDING, $paymentB->fresh()->status, 'Only the payment identified by context_id may be settled, regardless of a shared gateway reference.');
    }
}
