<?php

namespace Tests\Feature;

use App\Models\{PaymentMethods, StudentFeeManager, TuitionPayment, User};
use App\Support\Payments\TuitionPesaPalPayment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Http, Schema, URL};
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

/**
 * The scheduled school-fee reconciliation must credit only provider-verified
 * success, leave an unavailable verification pending, and never invent an
 * identity for an ambiguous submission.
 */
class TuitionPesaPalReconciliationCommandTest extends TestCase
{
    use AdmissionsTestHelper;

    private const GUID = '7e6b62d9-883e-440f-a63e-e1105bbfadc3';
    private const SECOND_GUID = '8e6b62d9-883e-440f-a63e-e1105bbfadc4';

    private int $school;
    private User $student;
    private string $reference = '';
    private int $orders = 0;
    private int $code = 0;
    private string $description = 'INVALID';
    private string $amount = '5000.00';
    private bool $statusUnavailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        Schema::table('schools', fn (Blueprint $t) => $t->string('school_currency')->nullable());
        $this->school = $this->makeSchool(['school_currency' => 'UGX']);
        $this->student = User::create(['school_id' => $this->school, 'role_id' => 7, 'name' => 'Student',
            'email' => 'student@example.test']);
        config(['app.url' => 'https://piie.example.test', 'cache.default' => 'array']);
        URL::forceRootUrl('https://piie.example.test');
        URL::forceScheme('https');
        PaymentMethods::create(['school_id' => $this->school, 'name' => 'pesapal', 'status' => 1,
            'payment_keys' => json_encode(['environment' => 'sandbox', 'consumer_key' => 'fixture',
                'consumer_secret' => 'fixture', 'notification_id' => self::GUID])]);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'RequestToken')) {
                return Http::response(['token' => 'fixture', 'expiryDate' => now()->addMinutes(5)->format('Y-m-d\TH:i:s'),
                    'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'SubmitOrderRequest')) {
                $this->orders++;
                $this->reference = $request['id'];
                return Http::response(['order_tracking_id' => self::GUID, 'merchant_reference' => $this->reference,
                    'redirect_url' => 'https://cybqa.pesapal.com/pay?OrderTrackingId=' . self::GUID,
                    'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'GetTransactionStatus')) {
                if ($this->statusUnavailable) { return Http::response([], 503); }
                return Http::response(['merchant_reference' => $this->reference, 'amount' => $this->amount,
                    'currency' => 'UGX', 'status_code' => $this->code, 'payment_status_description' => $this->description,
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

    public function test_command_settles_a_verified_pending_order_once_and_does_not_reprocess_it(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->code = 1;
        $this->description = 'COMPLETED';

        $this->artisan('tuition:reconcile-pesapal')->assertSuccessful();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
        $this->assertSame('paid', $fee->fresh()->status);

        $this->artisan('tuition:reconcile-pesapal')->assertSuccessful();
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
        $this->assertSame(1, TuitionPayment::where('fee_id', $fee->id)->where('status', 'paid')->count());
    }

    public function test_command_leaves_the_invoice_uncredited_while_verification_is_unavailable(): void
    {
        $fee = $this->fee();
        $payment = TuitionPesaPalPayment::start($fee);
        $this->statusUnavailable = true;

        $this->artisan('tuition:reconcile-pesapal')->assertExitCode(1);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        $this->assertSame('unpaid', $fee->fresh()->status);

        $this->statusUnavailable = false;
        $this->code = 1;
        $this->description = 'COMPLETED';
        $this->artisan('tuition:reconcile-pesapal')->assertSuccessful();
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
        $this->assertSame(1, $this->orders);
    }

    public function test_command_never_queries_an_order_that_has_no_provider_identity(): void
    {
        $fee = $this->fee();
        TuitionPayment::create(['school_id' => $this->school, 'fee_id' => $fee->id, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'pending', 'reference' => 'TUITION-NO-IDENTITY']);

        $this->artisan('tuition:reconcile-pesapal')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, (int) $fee->fresh()->paid_amount);
        $this->assertSame('pending', TuitionPayment::where('reference', 'TUITION-NO-IDENTITY')->value('status'));
    }

    public function test_unknown_tuition_reference_is_a_404_instead_of_a_server_error(): void
    {
        $this->get(route('applicant.pesapal.ipn', ['OrderTrackingId' => self::GUID,
            'OrderMerchantReference' => 'TUITION-UNKNOWN']))->assertNotFound();
        $this->get(route('tuition.pesapal.callback', ['OrderTrackingId' => self::GUID,
            'OrderMerchantReference' => 'TUITION-UNKNOWN']))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_reconciliation_continues_after_an_inaccessible_invoice(): void
    {
        $fee = $this->fee();
        $valid = TuitionPesaPalPayment::start($fee);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'RequestToken')) {
                return Http::response(['token' => 'fixture', 'expiryDate' => now()->addMinutes(5)->format('Y-m-d\\TH:i:s'),
                    'status' => '200', 'error' => null]);
            }
            if (str_contains($request->url(), 'GetTransactionStatus')) {
                $ref = str_contains($request->url(), self::SECOND_GUID) ? 'TUITION-ORPHAN' : $this->reference;
                return Http::response(['merchant_reference' => $ref, 'amount' => '5000.00',
                    'currency' => 'UGX', 'status_code' => 1, 'payment_status_description' => 'COMPLETED',
                    'payment_method' => 'Card', 'confirmation_code' => 'PROVIDER-123', 'status' => '200', 'error' => null]);
            }
            throw new \RuntimeException('Unexpected request');
        });

        // An orphaned pending order referencing a non-existent invoice, with a valid provider identity.
        TuitionPayment::create(['school_id' => $this->school, 'fee_id' => 999999, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'pending', 'reference' => 'TUITION-ORPHAN',
            'gateway_txn_id' => self::SECOND_GUID]);

        // The command must not crash; it processes the valid order and skips the orphaned one.
        $this->artisan('tuition:reconcile-pesapal')->assertExitCode(1);
        $this->assertSame('paid', $valid->fresh()->status);
        $this->assertSame(5000, (int) $fee->fresh()->paid_amount);
        $this->assertSame('pending', TuitionPayment::where('reference', 'TUITION-ORPHAN')->value('status'));
        $this->assertSame(1, TuitionPayment::where('fee_id', $fee->id)->where('status', 'paid')->count());
    }

    public function test_view_and_verify_returns_404_for_an_orphaned_pending_order(): void
    {
        $fee = $this->fee();
        TuitionPayment::create(['school_id' => $this->school, 'fee_id' => 999999, 'amount' => 5000,
            'currency' => 'UGX', 'method' => 'pesapal', 'status' => 'pending', 'reference' => 'TUITION-ORPHAN',
            'gateway_txn_id' => self::SECOND_GUID]);

        $admin = $this->makeAdminUser($this->school);
        $this->actingAs($admin);
        // The invoice no longer exists, so View/verify cannot load it.
        $this->get(route('admin.tuition_payments.show', 999999))->assertNotFound();
    }
}
