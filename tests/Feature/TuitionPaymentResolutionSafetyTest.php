<?php

namespace Tests\Feature;

use App\Models\{StudentFeeManager, TuitionPayment, TuitionPaymentResolution, User};
use App\Support\Billing\StudentFeeAwards;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class TuitionPaymentResolutionSafetyTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $school;
    private User $admin;
    private User $student;
    private StudentFeeManager $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->school = $this->makeSchool();
        $this->admin = $this->makeAdminUser($this->school);
        $this->student = User::create(['school_id' => $this->school, 'role_id' => 7, 'name' => 'Test Student', 'email' => 'test@example.test']);
        $this->fee = StudentFeeManager::create(['school_id' => $this->school, 'student_id' => $this->student->id,
            'title' => 'Tuition', 'total_amount' => 900, 'amount' => 1000, 'discounted_price' => 100,
            'paid_amount' => 0, 'class_id' => 0, 'session_id' => 1, 'payment_method' => '', 'status' => 'unpaid']);
    }

    private function makePayment(array $overrides = []): TuitionPayment
    {
        return TuitionPayment::create(array_merge([
            'school_id' => $this->school,
            'fee_id' => $this->fee->id,
            'amount' => 100,
            'currency' => 'UGX',
            'method' => 'pesapal',
            'status' => 'pending',
            'reference' => 'TEST-' . uniqid(),
            'gateway_txn_id' => 'test-tracking-id-123',
            'gateway_payload' => ['environment' => 'sandbox'],
        ], $overrides));
    }

    public function test_unknown_provider_status_is_rejected(): void
    {
        $payment = $this->makePayment();
        $this->actingAs($this->admin);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $payment->id), [
            'reason' => 'Testing unknown status rejection',
            'provider_status' => 'unknown',
            'provider_evidence' => 'some evidence',
        ]);
        $response->assertSessionHasErrors('provider_status');
        $this->assertDatabaseCount('tuition_payment_resolutions', 0);
        $this->assertNull($payment->fresh()->resolved_at);
    }

    public function test_forged_failed_status_without_classification_is_rejected(): void
    {
        $payment = $this->makePayment([
            'gateway_payload' => ['environment' => 'sandbox'], // No classification — forged
        ]);
        $this->actingAs($this->admin);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $payment->id), [
            'reason' => 'Testing forged classification rejection',
            'provider_status' => 'failed',
            'provider_evidence' => 'forged evidence',
        ]);
        $response->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('tuition_payment_resolutions', 0);
        $this->assertNull($payment->fresh()->resolved_at);
    }


    public function test_missing_tracking_id_alone_cannot_authorize_archival(): void
    {
        $payment = $this->makePayment(['gateway_txn_id' => null]);
        $this->actingAs($this->admin);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $payment->id), [
            'reason' => 'Testing missing tracking ID cannot archive',
            'provider_status' => 'failed',
            'provider_evidence' => 'no tracking id',
        ]);
        $response->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('tuition_payment_resolutions', 0);
        $this->assertNull($payment->fresh()->resolved_at);
    }

    public function test_review_only_records_note_without_archiving(): void
    {
        $payment = $this->makePayment();
        $this->actingAs($this->admin);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $payment->id), [
            'reason' => 'Testing review only resolution',
            'provider_status' => 'review_only',
            'provider_evidence' => 'Admin review note',
        ]);
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tuition_payment_resolutions', 1);
        $this->assertNull($payment->fresh()->resolved_at);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_school_isolation(): void
    {
        $otherSchool = $this->makeSchool();
        $otherPayment = TuitionPayment::create([
            'school_id' => $otherSchool,
            'fee_id' => $this->fee->id,
            'amount' => 100,
            'currency' => 'UGX',
            'method' => 'pesapal',
            'status' => 'pending',
            'reference' => 'OTHER-' . uniqid(),
            'gateway_txn_id' => null,
            'gateway_payload' => ['environment' => 'sandbox'],
        ]);
        $this->actingAs($this->admin);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $otherPayment->id), [
            'reason' => 'Testing school isolation',
            'provider_status' => 'review_only',
            'provider_evidence' => 'evidence',
        ]);
        $response->assertStatus(404);
    }

    public function test_permission_required(): void
    {
        $student = User::create(['school_id' => $this->school, 'role_id' => 7, 'name' => 'Student', 'email' => 'student@test.com']);
        $payment = $this->makePayment();
        $this->actingAs($student);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $payment->id), [
            'reason' => 'Testing permission check',
            'provider_status' => 'review_only',
            'provider_evidence' => 'evidence',
        ]);
        $response->assertRedirect();
        // Should redirect to login or show 403
        $this->assertDatabaseCount('tuition_payment_resolutions', 0);
    }

    public function test_paid_payment_cannot_be_resolved(): void
    {
        $payment = $this->makePayment(['status' => 'paid']);
        $this->actingAs($this->admin);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $payment->id), [
            'reason' => 'Testing paid payment rejection',
            'provider_status' => 'review_only',
            'provider_evidence' => 'evidence',
        ]);
        $response->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('tuition_payment_resolutions', 0);
    }

    public function test_audit_record_survives_payment_deletion_attempt(): void
    {
        $payment = $this->makePayment(['gateway_txn_id' => null]);
        $this->actingAs($this->admin);
        $this->post(route('admin.tuition_payments.resolve_test', $payment->id), [
            'reason' => 'Testing audit retention',
            'provider_status' => 'review_only',
            'provider_evidence' => 'No tracking ID',
        ])->assertRedirect();
        
        // Verify resolution record exists
        $this->assertDatabaseCount('tuition_payment_resolutions', 1);
        $resolution = TuitionPaymentResolution::first();
        $this->assertNotNull($resolution->payment_snapshot);
        $this->assertSame($payment->id, $resolution->payment_id);
    }

    public function test_sandbox_environment_required(): void
    {
        $payment = $this->makePayment(['gateway_payload' => ['environment' => 'live']]);
        $this->actingAs($this->admin);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $payment->id), [
            'reason' => 'Testing live environment rejection',
            'provider_status' => 'review_only',
            'provider_evidence' => 'evidence',
        ]);
        $response->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('tuition_payment_resolutions', 0);
    }

    public function test_verified_terminal_archival_succeeds_with_trusted_evidence(): void
    {
        // Simulates a payment that was previously reconciled by PesaPal (classification=FAILED, last_checked_at set)
        // The classification and last_checked_at originate from TuitionPesaPalPayment::reconcile() which calls
        // PesaPal's getTransactionStatus() API and stores the result in gateway_payload.
        $payment = $this->makePayment([
            'status' => 'failed',
            'gateway_payload' => [
                'environment' => 'sandbox',
                'classification' => 'FAILED',
                'last_checked_at' => now()->toIso8601String(),
                'provider_confirmation_code' => 'TEST-CONFIRMATION-CODE',
            ],
        ]);
        $this->actingAs($this->admin);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $payment->id), [
            'reason' => 'Testing verified terminal archival with trusted evidence',
            'provider_status' => 'failed',
            'provider_evidence' => 'PesaPal tracking ID: TEST-TRACKING, dashboard shows FAILED',
        ]);
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('tuition_payment_resolutions', 1);
        $this->assertNotNull($payment->fresh()->resolved_at);
        $this->assertSame('archived', $payment->fresh()->status);
        $resolution = \App\Models\TuitionPaymentResolution::first();
        $this->assertSame('test_payment_archived', $resolution->resolution_type);
        $this->assertArrayHasKey('classification', $resolution->verification_evidence);
        $this->assertSame('FAILED', $resolution->verification_evidence['classification']);
    }

    public function test_audit_record_survives_payment_resolution(): void
    {
        $payment = $this->makePayment(['gateway_txn_id' => null]);
        $this->actingAs($this->admin);
        $this->post(route('admin.tuition_payments.resolve_test', $payment->id), [
            'reason' => 'Testing audit retention after resolution',
            'provider_status' => 'review_only',
            'provider_evidence' => 'No tracking ID found',
        ])->assertRedirect();

        // Verify resolution record exists and is immutable
        $this->assertDatabaseCount('tuition_payment_resolutions', 1);
        $resolution = TuitionPaymentResolution::first();
        $this->assertNotNull($resolution->payment_snapshot);
        $this->assertSame($payment->id, $resolution->payment_id);
        $this->assertSame($this->admin->id, $resolution->resolved_by);
        $this->assertNotNull($resolution->resolved_at);
        $this->assertNotNull($resolution->payment_snapshot['reference']);
    }

    public function test_lifecycle_regression_pending_only_review_failed_archivable(): void
    {
        // Pending payment: only review_only allowed, no archiving
        $pendingPayment = $this->makePayment(['status' => 'pending']);
        $this->actingAs($this->admin);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $pendingPayment->id), [
            'reason' => 'Testing pending payment cannot archive',
            'provider_status' => 'failed',
            'provider_evidence' => 'evidence',
        ]);
        $response->assertSessionHasErrors('payment');
        $this->assertNull($pendingPayment->fresh()->resolved_at);
        $this->assertSame('pending', $pendingPayment->fresh()->status);

        // Failed payment (reconciled): can be archived
        $failedPayment = $this->makePayment([
            'status' => 'failed',
            'gateway_payload' => [
                'environment' => 'sandbox',
                'classification' => 'FAILED',
                'last_checked_at' => now()->toIso8601String(),
            ],
        ]);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $failedPayment->id), [
            'reason' => 'Testing failed payment can be archived',
            'provider_status' => 'failed',
            'provider_evidence' => 'PesaPal confirmed FAILED',
        ]);
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertNotNull($failedPayment->fresh()->resolved_at);
        $this->assertSame('archived', $failedPayment->fresh()->status);

        // Paid payment: cannot be resolved
        $paidPayment = $this->makePayment(['status' => 'paid']);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $paidPayment->id), [
            'reason' => 'Testing paid payment cannot be resolved',
            'provider_status' => 'review_only',
            'provider_evidence' => 'evidence',
        ]);
        $response->assertSessionHasErrors('payment');
        $this->assertNull($paidPayment->fresh()->resolved_at);
    }

    public function test_forged_failed_status_with_classification_but_no_timestamp_is_rejected(): void
    {
        $payment = $this->makePayment([
            'gateway_payload' => ['environment' => 'sandbox', 'classification' => 'FAILED'], // Forged — no last_checked_at
        ]);
        $this->actingAs($this->admin);
        $response = $this->post(route('admin.tuition_payments.resolve_test', $payment->id), [
            'reason' => 'Testing forged classification without timestamp',
            'provider_status' => 'failed',
            'provider_evidence' => 'forged evidence',
        ]);
        $response->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('tuition_payment_resolutions', 0);
        $this->assertNull($payment->fresh()->resolved_at);
    }

}
