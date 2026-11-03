<?php

namespace App\Console\Commands;

use App\Models\{AuditLog, StudentFeeManager, TuitionPayment, TuitionPaymentTombstone};
use App\Support\Payments\DecimalAmount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteSandboxTestPayments extends Command
{
    protected $signature = 'pesapal:delete-sandbox-test-payments
        {--ids=1,6 : Comma-separated payment IDs (must be subset of the allowed set)}
        {--school= : Required school ownership (both payments must belong to this school)}
        {--dry-run : Show what would be deleted without mutating (default)}
        {--confirm : Actually perform the deletion}
        {--backup= : Reference/identifier of the verified production backup (required to execute)}
        {--reason= : Deletion reason (min 10 chars)}
        {--force : Skip interactive confirmation (automated/CI only)}';

    protected $description = 'One-time guarded deletion of obsolete Sandbox test tuition payments (IDs 1 and 6). Dry-run by default.';

    /** The only payment IDs this command is ever permitted to touch. */
    private const ALLOWED_IDS = [1, 6];

    /** Expected immutable attributes per payment ID. */
    private const EXPECTED = [
        1 => ['amount' => '440000.00', 'currency' => 'UGX'],
        6 => ['amount' => '650000.00', 'currency' => 'UGX'],
    ];

    public function handle(): int
    {
    {
        return 0;
        if ($this->option('force') && ! app()->environment('testing')) {
            $this->error('--force is only permitted in testing environments. In production, confirm interactively.');
            return 1;
        }

        $ids = array_values(array_unique(array_map('intval', explode(',', (string) $this->option('ids')))));
        foreach ($ids as $id) {
            if (! in_array($id, self::ALLOWED_IDS, true)) {
                $this->error("Payment ID {$id} is not in the allowed set {" . implode(',', self::ALLOWED_IDS) . "}.");
                return 1;
            }
        }
        if (empty($ids)) {
            $this->error('No payment IDs specified.');
            return 1;
        }

        $schoolId = (int) $this->option('school');
        if ($schoolId <= 0) {
            $this->error('--school is required and must be a positive integer.');
            return 1;
        }

        $reason = (string) ($this->option('reason') ?: '');
        if (strlen($reason) < 10) {
            $this->error('--reason is required (min 10 chars).');
            return 1;
        }

        $dryRun = (bool) $this->option('dry-run') || ! (bool) $this->option('confirm');
        $backupRef = (string) ($this->option('backup') ?: '');

        $this->info('=== Sandbox Test Payment Deletion ===');
        $this->info('Mode: ' . ($dryRun ? 'DRY-RUN (no mutations)' : 'EXECUTE'));
        $this->info('Target IDs: ' . implode(', ', $ids));
        $this->info('School ID: ' . $schoolId);
        $this->newLine();

        $plan = [];
        $allOk = true;
        foreach ($ids as $id) {
            $result = $this->validatePayment($id, $schoolId);
            $plan[] = $result;
            if (! $result['ok']) {
                $allOk = false;
            }
        }

        $rows = [];
        foreach ($plan as $p) {
            $rows[] = [
                $p['id'],
                $p['exists'] ? 'yes' : 'no',
                $p['ok'] ? 'PASS' : 'FAIL',
                $p['reason'] ?? '',
            ];
        }
        $this->table(['Payment ID', 'Exists', 'Safeguards', 'Detail'], $rows);
        $this->newLine();

        foreach ($plan as $p) {
            $this->line("Payment #{$p['id']}:");
            foreach ($p['checks'] as $name => $status) {
                $this->line("  - {$name}: " . ($status ? 'OK' : 'FAIL'));
            }
            if (! empty($p['notes'])) {
                foreach ($p['notes'] as $n) {
                    $this->line("    note: {$n}");
                }
            }
            $this->newLine();
        }

        if (! $allOk) {
            $this->error('One or more safeguards FAILED. Aborting. No changes made.');
            return 1;
        }

        $alreadyDeleted = [];
        foreach ($ids as $id) {
            if (TuitionPaymentTombstone::where('payment_id', $id)->exists()) {
                $alreadyDeleted[] = $id;
            }
        }
        if (! empty($alreadyDeleted)) {
            $this->warn('Already deleted (tombstone exists): ' . implode(', ', $alreadyDeleted));
        }
        $toDelete = array_values(array_diff($ids, $alreadyDeleted));
        if (empty($toDelete)) {
            $this->info('All target payments already deleted. Nothing to do (idempotent).');
            return 0;
        }

        if ($dryRun) {
            $this->info('DRY-RUN complete. No records modified.');
            $this->info('To execute, re-run with --confirm --backup="<verified backup id>" --reason="..."');
            return 0;
        }

        if (trim($backupRef) === '') {
            $this->error('--backup is required in execute mode (reference of verified production backup).');
            return 1;
        }

        $this->warn('EXECUTING deletion of payments: ' . implode(', ', $toDelete));
        if (! $this->option('force') && ! $this->confirm('Are you absolutely sure? This is irreversible (recoverable only from backup).')) {
            $this->info('Aborted by operator.');
            return 1;
        }

        try {
            DB::transaction(function () use ($toDelete, $schoolId, $reason, $backupRef) {
                foreach ($toDelete as $id) {
                    $payment = TuitionPayment::where('id', $id)->where('school_id', $schoolId)->lockForUpdate()->firstOrFail();

                    // Re-verify safeguards inside the transaction (race-condition protection).
                    $payload = $payment->gateway_payload ?? [];
                    if (($payload['environment'] ?? null) !== 'sandbox') { throw new \RuntimeException('Safeguard failed: not sandbox.'); }
                    if ($payment->currency !== 'UGX') { throw new \RuntimeException('Safeguard failed: currency.'); }
                    if (! in_array($payment->status, ['pending', 'failed'], true)) { throw new \RuntimeException('Safeguard failed: status.'); }
                    if ($payment->isResolved()) { throw new \RuntimeException('Safeguard failed: already resolved.'); }
                    if (number_format((float) $payment->amount, 2, '.', '') !== self::EXPECTED[$id]['amount']) { throw new \RuntimeException('Safeguard failed: amount changed.'); }
                    if ($payment->currency !== self::EXPECTED[$id]['currency']) { throw new \RuntimeException('Safeguard failed: currency changed.'); }
                    if (TuitionPaymentTombstone::where('payment_id', $id)->exists()) { throw new \RuntimeException('Safeguard failed: already tombstoned.'); }
                    $hasSettlement = AuditLog::where('record_type', TuitionPayment::class)->where('record_id', $payment->id)->where('action', 'update')->where('description', 'like', '%paid%')->exists();
                    if ($hasSettlement) { throw new \RuntimeException('Safeguard failed: settlement record exists.'); }
                    $fee = StudentFeeManager::where('id', $payment->fee_id)->where('school_id', $schoolId)->first();
                    if ($fee) {
                        $otherPaidCount = TuitionPayment::where('fee_id', $payment->fee_id)->where('school_id', $payment->school_id)->where('status', 'paid')->where('id', '!=', $payment->id)->count();
                        if ($otherPaidCount === 0 && DecimalAmount::minorUnits($fee->paid_amount) > 0) { throw new \RuntimeException('Safeguard failed: unexplained credits.'); }
                    }

                    // Tombstone (immutable audit).
                    TuitionPaymentTombstone::create([
                        'payment_id' => $payment->id,
                        'school_id' => $payment->school_id,
                        'fee_id' => $payment->fee_id,
                        'reference' => $payment->reference,
                        'gateway_txn_id' => $payment->gateway_txn_id,
                        'currency' => $payment->currency,
                        'amount' => $payment->amount,
                        'status' => $payment->status,
                        'environment' => $payment->gateway_payload['environment'] ?? null,
                        'provider_classification' => $payment->gateway_payload['classification'] ?? null,
                        'payment_snapshot' => $this->sanitizeSnapshot($payment->toArray()),
                        'deletion_reason' => $reason,
                        'backup_reference' => $backupRef,
                        'deleted_by' => null,
                        'deleted_by_name' => 'artisan-command',
                        'deleted_at' => now(),
                    ]);

                    AuditLog::record('delete', 'Tuition Payments',
                        'Sandbox test payment permanently deleted #' . $payment->id . ': ' . $reason,
                        ['school_id' => $payment->school_id, 'event_type' => 'DATA', 'record_type' => TuitionPayment::class, 'record_id' => $payment->id]);

                    $payment->delete();
                }
            });
        } catch (\RuntimeException $e) {
            $this->error('Deletion aborted: ' . $e->getMessage());
            return 1;
        }

        $this->info('Deletion complete. Tombstones created for: ' . implode(', ', $toDelete));
        $this->info('Late Sandbox callbacks for these payments will find no payment row and cannot credit invoices.');
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
                $notes[] = 'Already deleted (tombstone exists) — will be skipped.';
                return ['id' => $id, 'exists' => false, 'ok' => true, 'checks' => $checks, 'notes' => $notes, 'reason' => 'already deleted'];
            }
            $ok = false;
            $notes[] = 'Payment not found and no tombstone — cannot verify.';
            return ['id' => $id, 'exists' => false, 'ok' => false, 'checks' => $checks, 'notes' => $notes, 'reason' => 'not found'];
        }

        $checks['school_ownership'] = ((int) $payment->school_id === $schoolId);
        if (! $checks['school_ownership']) {
            $ok = false; $notes[] = "school_id={$payment->school_id}, expected {$schoolId}";
        }

        $payload = $payment->gateway_payload ?? [];
        $checks['sandbox_environment'] = (($payload['environment'] ?? null) === 'sandbox');
        if (! $checks['sandbox_environment']) {
            $ok = false; $notes[] = 'environment=' . ($payload['environment'] ?? 'null');
        }

        $expCurrency = self::EXPECTED[$id]['currency'];
        $checks['currency'] = ($payment->currency === $expCurrency);
        if (! $checks['currency']) {
            $ok = false; $notes[] = "currency={$payment->currency}, expected {$expCurrency}";
        }

        $expAmount = self::EXPECTED[$id]['amount'];
        $checks['amount'] = (number_format((float) $payment->amount, 2, '.', '') === $expAmount);
        if (! $checks['amount']) {
            $ok = false; $notes[] = "amount={$payment->amount}, expected {$expAmount}";
        }

        $checks['status_not_final'] = in_array($payment->status, ['pending', 'failed'], true);
        if (! $checks['status_not_final']) {
            $ok = false; $notes[] = "status={$payment->status} (paid/reversed/archived not deletable)";
        }

        $hasSettlement = AuditLog::where('record_type', TuitionPayment::class)
            ->where('record_id', $payment->id)
            ->where('action', 'update')
            ->where('description', 'like', '%paid%')
            ->exists();
        $checks['no_settlement_record'] = ! $hasSettlement;
        if ($hasSettlement) {
            $ok = false; $notes[] = 'settlement audit record exists';
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
                $ok = false; $notes[] = 'invoice has unexplained payment credits';
            }
        } else {
            $checks['invoice_accessible'] = false;
            $notes[] = 'invoice inaccessible/missing - proceeding on payment-specific evidence only';
        }

        $checks['not_already_resolved'] = ! $payment->isResolved();
        if ($payment->isResolved()) {
            $ok = false; $notes[] = 'payment already resolved/archived';
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


