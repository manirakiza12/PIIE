<?php

namespace App\Console\Commands;

use App\Models\{AuditLog, StudentFeeManager, TuitionPayment, TuitionPaymentTombstone};
use App\Support\Payments\DecimalAmount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteSandboxTestPayments extends Command
{
    protected $signature = 'pesapaldeletetestpayments
        {--ids=1,6 : Comma-separated payment IDs (must be subset of the allowed set)}
        {--school= : Required school ownership (both payments must belong to this school)}
        {--dry-run : Show what would be deleted without mutating (default)}
        {--confirm : Actually perform the deletion}
        {--backup= : Reference/identifier of the verified production backup (required to execute)}
        {--reason= : Deletion reason (min 10 chars)}
        {--force : Skip interactive confirmation (automated/CI only)}';

    protected $description = 'One-time guarded deletion of obsolete Sandbox test tuition payments (IDs 1 and 6). Dry-run by default.';

    private const ALLOWED_IDS = [1, 6];

    private const EXPECTED = [
        1 => ['amount' => '440000.00', 'currency' => 'UGX'],
        6 => ['amount' => '650000.00', 'currency' => 'UGX'],
    ];

    public function handle(): int
    {
        file_put_contents(storage_path('logs/debug.txt'), "START env=" . app()->environment() . " force=" . var_export($this->option('force'), true) . "\n", FILE_APPEND);

        if ($this->option('force') && ! app()->environment('testing')) {
            file_put_contents(storage_path('logs/debug.txt'), "BLOCKED by force check\n", FILE_APPEND);
            $this->error('--force is only permitted in testing environments.');
            return 1;
        }

        $ids = array_values(array_unique(array_map('intval', explode(',', (string) $this->option('ids')))));
        file_put_contents(storage_path('logs/debug.txt'), "ids=" . implode(',', $ids) . "\n", FILE_APPEND);
        foreach ($ids as $id) {
            if (! in_array($id, self::ALLOWED_IDS, true)) {
                file_put_contents(storage_path('logs/debug.txt'), "BLOCKED disallowed id\n", FILE_APPEND);
                $this->error("Payment ID {$id} is not in the allowed set.");
                return 1;
            }
        }
        if (empty($ids)) {
            file_put_contents(storage_path('logs/debug.txt'), "BLOCKED empty ids\n", FILE_APPEND);
            $this->error('No payment IDs specified.');
            return 1;
        }

        $schoolId = (int) $this->option('school');
        file_put_contents(storage_path('logs/debug.txt'), "schoolId=$schoolId\n", FILE_APPEND);
        if ($schoolId <= 0) {
            file_put_contents(storage_path('logs/debug.txt'), "BLOCKED schoolId<=0\n", FILE_APPEND);
            $this->error('--school is required and must be a positive integer.');
            return 1;
        }

        $reason = (string) ($this->option('reason') ?: '');
        if (strlen($reason) < 10) {
            file_put_contents(storage_path('logs/debug.txt'), "BLOCKED reason short\n", FILE_APPEND);
            $this->error('--reason is required (min 10 chars).');
            return 1;
        }

        $dryRun = (bool) $this->option('dry-run') || ! (bool) $this->option('confirm');
        $backupRef = (string) ($this->option('backup') ?: '');
        file_put_contents(storage_path('logs/debug.txt'), "dryRun=" . var_export($dryRun, true) . "\n", FILE_APPEND);

        $plan = [];
        $allOk = true;
        foreach ($ids as $id) {
            $result = $this->validatePayment($id, $schoolId);
            $plan[] = $result;
            if (! $result['ok']) {
                $allOk = false;
            }
        }
        file_put_contents(storage_path('logs/debug.txt'), "allOk=" . var_export($allOk, true) . "\n", FILE_APPEND);

        if (! $allOk) {
            file_put_contents(storage_path('logs/debug.txt'), "BLOCKED safeguards failed\n", FILE_APPEND);
            $this->error('One or more safeguards FAILED. Aborting. No changes made.');
            return 1;
        }

        $alreadyDeleted = [];
        foreach ($ids as $id) {
            if (TuitionPaymentTombstone::where('payment_id', $id)->exists()) {
                $alreadyDeleted[] = $id;
            }
        }
        $toDelete = array_values(array_diff($ids, $alreadyDeleted));
        file_put_contents(storage_path('logs/debug.txt'), "toDelete=" . implode(',', $toDelete) . "\n", FILE_APPEND);
        if (empty($toDelete)) {
            file_put_contents(storage_path('logs/debug.txt'), "empty toDelete, returning 0\n", FILE_APPEND);
            $this->info('All target payments already deleted. Nothing to do (idempotent).');
            return 0;
        }

        if ($dryRun) {
            file_put_contents(storage_path('logs/debug.txt'), "DRYRUN branch, returning 0\n", FILE_APPEND);
            $this->info('DRY-RUN complete. No records modified.');
            $this->info('To execute, re-run with --confirm --backup="<verified backup id>" --reason="..."');
            return 0;
        }

        file_put_contents(storage_path('logs/debug.txt'), "past dryrun, backupRef=" . $backupRef . "\n", FILE_APPEND);
        if (trim($backupRef) === '') {
            file_put_contents(storage_path('logs/debug.txt'), "BLOCKED no backup\n", FILE_APPEND);
            $this->error('--backup is required in execute mode.');
            return 1;
        }

        file_put_contents(storage_path('logs/debug.txt'), "REACHED END, returning 0\n", FILE_APPEND);
        return 0;
    }

    private function validatePayment(int $id, int $schoolId): array
    {
        $checks = [];
        $notes = [];
        $ok = true;

        $payment = TuitionPayment::find($id);
        $exists = $payment !== null;
        $checks['exists'] = $exists;
        if (! $exists) {
            if (TuitionPaymentTombstone::where('payment_id', $id)->exists()) {
                $notes[] = 'Already deleted (tombstone exists) - will be skipped.';
                return ['id' => $id, 'exists' => false, 'ok' => true, 'checks' => $checks, 'notes' => $notes, 'reason' => 'already deleted'];
            }
            $ok = false;
            $notes[] = 'Payment not found and no tombstone - cannot verify.';
            return ['id' => $id, 'exists' => false, 'ok' => false, 'checks' => $checks, 'notes' => $notes, 'reason' => 'not found'];
        }

        $checks['school_ownership'] = ((int) $payment->school_id === $schoolId);
        if (! $checks['school_ownership']) {
            $ok = false;
            $notes[] = "school_id={$payment->school_id}, expected {$schoolId}";
        }

        $payload = $payment->gateway_payload ?? [];
        $checks['sandbox_environment'] = (($payload['environment'] ?? null) === 'sandbox');
        if (! $checks['sandbox_environment']) {
            $ok = false;
            $notes[] = 'environment=' . ($payload['environment'] ?? 'null');
        }

        $expCurrency = self::EXPECTED[$id]['currency'];
        $checks['currency'] = ($payment->currency === $expCurrency);
        if (! $checks['currency']) {
            $ok = false;
            $notes[] = "currency={$payment->currency}, expected {$expCurrency}";
        }

        $expAmount = self::EXPECTED[$id]['amount'];
        $checks['amount'] = (number_format((float) $payment->amount, 2, '.', '') === $expAmount);
        if (! $checks['amount']) {
            $ok = false;
            $notes[] = "amount={$payment->amount}, expected {$expAmount}";
        }

        $checks['status_not_final'] = in_array($payment->status, ['pending', 'failed'], true);
        if (! $checks['status_not_final']) {
            $ok = false;
            $notes[] = "status={$payment->status} (paid/reversed/archived not deletable)";
        }

        $hasSettlement = AuditLog::where('record_type', TuitionPayment::class)
            ->where('record_id', $payment->id)
            ->where('action', 'update')
            ->where('description', 'like', '%paid%')
            ->exists();
        $checks['no_settlement_record'] = ! $hasSettlement;
        if ($hasSettlement) {
            $ok = false;
            $notes[] = 'settlement audit record exists';
        }

        $fee = StudentFeeManager::where('id', $payment->fee_id)->where('school_id', $payment->school_id)->first();
        if ($fee) {
            $otherPaidCount = TuitionPayment::where('fee_id', $payment->fee_id)
                ->where('school_id', $payment->school_id)
                ->where('status', 'paid')
                ->where('id', '!=', $payment->id)
                ->count();
            $unexplained = ($otherPaidCount === 0 && DecimalAmount::minorUnits($fee->paid_amount) > 0);
            $checks['no_unexplained_credits'] = ! $unexplained;
            if ($unexplained) {
                $ok = false;
                $notes[] = 'invoice has unexplained payment credits';
            }
        } else {
            $checks['invoice_accessible'] = false;
            $notes[] = 'invoice inaccessible/missing - proceeding on payment-specific evidence only';
        }

        $checks['not_already_resolved'] = ! $payment->isResolved();
        if ($payment->isResolved()) {
            $ok = false;
            $notes[] = 'payment already resolved/archived';
        }

        $checks['not_already_tombstoned'] = ! TuitionPaymentTombstone::where('payment_id', $id)->exists();
        if (TuitionPaymentTombstone::where('payment_id', $id)->exists()) {
            $notes[] = 'already tombstoned - will be skipped';
        }

        $reason = $ok ? 'all safeguards pass' : 'safeguard failure';
        return ['id' => $id, 'exists' => true, 'ok' => $ok, 'checks' => $checks, 'notes' => $notes, 'reason' => $reason];
    }

    private function sanitizeSnapshot(array $data): array
    {
        $sensitive = ['password', 'secret', 'api_key', 'apikey', 'token', 'credentials', 'credential', 'private_key'];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->sanitizeSnapshot($v);
            } elseif (is_string($k) && in_array(strtolower($k), $sensitive, true)) {
                $data[$k] = '[redacted]';
            }
        }
        if (isset($data['gateway_payload']) && is_array($data['gateway_payload'])) {
            $data['gateway_payload'] = $this->sanitizeSnapshot($data['gateway_payload']);
        }
        return $data;
    }
}
