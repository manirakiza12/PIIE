<?php

namespace Tests\Feature;

use App\Models\{StudentFeeManager, TuitionPayment, TuitionPaymentTombstone, User};
use App\Support\Payments\DecimalAmount;
use Tests\Feature\Support\AdmissionsTestHelper;
use Tests\TestCase;

class DeleteSandboxTestPaymentsCommandTest extends TestCase
{
    use AdmissionsTestHelper;

    private int $school;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdmissionsTestSchema();
        $this->school = $this->makeSchool();
        $this->admin = $this->makeAdminUser($this->school);
    }

    private function makePayment(int $id, array $overrides = []): TuitionPayment
    {
        \App\Models\TuitionPayment::unguard();
        $p = TuitionPayment::create(array_merge([
            'id' => $id,
            'school_id' => $this->school,
            'fee_id' => $this->makeFee(),
            'amount' => $id === 6 ? 650000 : 440000,
            'currency' => 'UGX',
            'method' => 'pesapal',
            'status' => 'pending',
            'reference' => 'TEST-PAY-' . $id,
            'gateway_txn_id' => 'tracking-' . $id,
            'gateway_payload' => ['environment' => 'sandbox'],
        ], $overrides));
        \App\Models\TuitionPayment::reguard();
        return $p;
    }

    private function makeFee(): int
    {
        $student = User::create(['school_id' => $this->school, 'role_id' => 7, 'name' => 'S', 'email' => 's' . uniqid() . '@t.test']);
        $fee = StudentFeeManager::create(['school_id' => $this->school, 'student_id' => $student->id, 'title' => 'Tuition', 'total_amount' => 1000, 'amount' => 1000, 'discounted_price' => 1000, 'paid_amount' => 0, 'class_id' => 0, 'session_id' => 1, 'payment_method' => '', 'status' => 'unpaid']);
        return $fee->id;
    }

    public function test_dry_run_does_not_delete(): void
    {
        $this->makePayment(6);
        $this->makePayment(1);
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $code = $kernel->call('pesapal:delete-sandbox-test-payments', [
            '--school' => $this->school,
            '--ids' => '1,6',
            '--reason' => 'dry run test reason',
        ], $output);
        fwrite(STDERR, 'EXITCODE=' . $code . "\n" . 'OUTPUT=' . $output->fetch() . "\n");
        $this->assertSame(0, $code);
        $this->assertDatabaseCount('tuition_payments', 2);
        $this->assertDatabaseCount('tuition_payment_tombstones', 0);
    }

    public function test_wrong_school_aborts(): void
    {
        $this->makePayment(6);
        $this->makePayment(1);
        $this->artisan('pesapal:delete-sandbox-test-payments', [
            '--school' => 9999,
            '--ids' => '1,6',
            '--reason' => 'wrong school test',
            '--confirm' => true,
            '--force' => true,
            '--backup' => 'BACKUP-1',
        ])->assertExitCode(1);
        $this->assertDatabaseCount('tuition_payments', 2);
    }

    public function test_wrong_amount_aborts(): void
    {
        $this->makePayment(6, ['amount' => 100]);
        $this->makePayment(1);
        $this->artisan('pesapal:delete-sandbox-test-payments', [
            '--school' => $this->school,
            '--ids' => '1,6',
            '--reason' => 'wrong amount test',
            '--confirm' => true,
            '--force' => true,
            '--backup' => 'BACKUP-1',
        ])->assertExitCode(1);
        $this->assertDatabaseCount('tuition_payments', 2);
    }

    public function test_live_environment_aborts(): void
    {
        $this->makePayment(6, ['gateway_payload' => ['environment' => 'live']]);
        $this->makePayment(1);
        $this->artisan('pesapal:delete-sandbox-test-payments', [
            '--school' => $this->school,
            '--ids' => '1,6',
            '--reason' => 'live env test',
            '--confirm' => true,
            '--force' => true,
            '--backup' => 'BACKUP-1',
        ])->assertExitCode(1);
        $this->assertDatabaseCount('tuition_payments', 2);
    }

    public function test_disallowed_id_aborts(): void
    {
        $this->artisan('pesapal:delete-sandbox-test-payments', [
            '--school' => $this->school,
            '--ids' => '2,6',
            '--reason' => 'disallowed id test',
        ])->assertExitCode(1);
    }

    public function test_execute_creates_tombstone_and_deletes(): void
    {
        $this->makePayment(6);
        $this->makePayment(1);
        $this->artisan('pesapal:delete-sandbox-test-payments', [
            '--school' => $this->school,
            '--ids' => '1,6',
            '--reason' => 'one-time sandbox cleanup',
            '--confirm' => true,
            '--force' => true,
            '--backup' => 'BACKUP-TEST-1',
        ])->assertExitCode(0);
        $this->assertDatabaseCount('tuition_payments', 0);
        $this->assertDatabaseCount('tuition_payment_tombstones', 2);
        $t = TuitionPaymentTombstone::where('payment_id', 6)->first();
        $this->assertNotNull($t);
        $this->assertSame('tracking-6', $t->gateway_txn_id);
        $this->assertSame('BACKUP-TEST-1', $t->backup_reference);
        $this->assertNotNull($t->payment_snapshot);
    }

    public function test_idempotent_second_run(): void
    {
        $this->makePayment(6);
        $this->makePayment(1);
        $this->artisan('pesapal:delete-sandbox-test-payments', [
            '--school' => $this->school,
            '--ids' => '1,6',
            '--reason' => 'first run cleanup',
            '--confirm' => true,
            '--force' => true,
            '--backup' => 'B1',
        ])->assertExitCode(0);
        $this->assertDatabaseCount('tuition_payment_tombstones', 2);
    }

    public function test_missing_backup_aborts_execute(): void
    {
        $this->makePayment(6);
        $this->makePayment(1);
        $this->artisan('pesapal:delete-sandbox-test-payments', [
            '--school' => $this->school,
            '--ids' => '1,6',
            '--reason' => 'no backup ref',
            '--confirm' => true,
            '--force' => true,
        ])->assertExitCode(1);
        $this->assertDatabaseCount('tuition_payments', 2);
    }

    public function test_settlement_record_aborts(): void
    {
        $p = $this->makePayment(6);
        $this->makePayment(1);
        \App\Models\AuditLog::record('update', 'Tuition Payments', 'PesaPal school fee payment #' . $p->id . ': paid', [
            'school_id' => $this->school, 'event_type' => 'DATA', 'record_type' => \App\Models\TuitionPayment::class, 'record_id' => $p->id,
        ]);
        $this->artisan('pesapal:delete-sandbox-test-payments', [
            '--school' => $this->school,
            '--ids' => '1,6',
            '--reason' => 'settlement test',
            '--confirm' => true,
            '--force' => true,
            '--backup' => 'B',
        ])->assertExitCode(1);
        $this->assertDatabaseCount('tuition_payments', 2);
    }
}
