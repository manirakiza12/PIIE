<?php

namespace Tests\Feature;

use App\Models\{StudentFeeManager, TuitionPayment, TuitionPaymentTombstone, User};
use App\Support\Payments\TuitionPesaPalPayment;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class DeleteSandboxLateCallbackTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->school = $this->makeSchool();
    }

    public function test_missing_invoice_payment_can_be_deleted_with_payment_specific_evidence(): void
    {
        \App\Models\TuitionPayment::unguard();
        TuitionPayment::create([
            'id' => 1,
            'school_id' => $this->school,
            'fee_id' => 999999,
            'amount' => 440000,
            'currency' => 'UGX',
            'method' => 'pesapal',
            'status' => 'pending',
            'reference' => 'TEST-PAY-1',
            'gateway_txn_id' => 'tracking-1',
            'gateway_payload' => ['environment' => 'sandbox'],
        ]);
        TuitionPayment::create([
            'id' => 6,
            'school_id' => $this->school,
            'fee_id' => 999999,
            'amount' => 650000,
            'currency' => 'UGX',
            'method' => 'pesapal',
            'status' => 'pending',
            'reference' => 'TEST-PAY-6',
            'gateway_txn_id' => 'tracking-6',
            'gateway_payload' => ['environment' => 'sandbox'],
        ]);
        \App\Models\TuitionPayment::reguard();

        $this->artisan('pesapal:delete-sandbox-test-payments', [
            '--school' => $this->school,
            '--ids' => '1,6',
            '--reason' => 'missing invoice cleanup',
            '--confirm' => true,
            '--force' => true,
            '--backup' => 'BACKUP-MI',
        ])->assertExitCode(0);
        $this->assertDatabaseMissing('tuition_payments', ['id' => 1]);
        $this->assertDatabaseHas('tuition_payment_tombstones', ['payment_id' => 1]);
    }

    public function test_late_reconcile_of_deleted_payment_throws(): void
    {
        \App\Models\TuitionPayment::unguard();
        TuitionPayment::create([
            'id' => 6,
            'school_id' => $this->school,
            'fee_id' => 999999,
            'amount' => 650000,
            'currency' => 'UGX',
            'method' => 'pesapal',
            'status' => 'pending',
            'reference' => 'TEST-PAY-6',
            'gateway_txn_id' => 'tracking-6',
            'gateway_payload' => ['environment' => 'sandbox', 'classification' => 'FAILED', 'last_checked_at' => now()->toIso8601String()],
        ]);
        \App\Models\TuitionPayment::reguard();

        $this->artisan('pesapal:delete-sandbox-test-payments', [
            '--school' => $this->school,
            '--ids' => '6',
            '--reason' => 'late callback test',
            '--confirm' => true,
            '--force' => true,
            '--backup' => 'BACKUP-LC',
        ])->assertExitCode(0);

        $stale = new TuitionPayment();
        $stale->id = 6;
        $stale->school_id = $this->school;
        $stale->fee_id = 999999;
        $stale->amount = 650000;
        $stale->currency = 'UGX';
        $stale->status = 'pending';
        $stale->reference = 'TEST-PAY-6';
        $stale->gateway_txn_id = 'tracking-6';
        $stale->gateway_payload = ['environment' => 'sandbox', 'classification' => 'FAILED', 'last_checked_at' => now()->toIso8601String()];

        $threw = false;
        try {
            TuitionPesaPalPayment::reconcile($stale);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Late reconcile of deleted payment must throw.');
    }
}
