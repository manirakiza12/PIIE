<?php

namespace App\Console\Commands;

use App\Models\TuitionPayment;
use App\Support\Payments\PesaPalException;
use App\Support\Payments\TuitionPesaPalPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-verify outstanding school-fee orders through PesaPal.
 *
 * The IPN is the primary confirmation path, but a missed or rejected
 * notification must not leave a verified payment uncredited. This command is
 * read-only against the provider and settles only what PesaPal confirms; an
 * unavailable status endpoint leaves the order pending.
 *
 * Each payment is processed independently. A payment that cannot be reconciled
 * (for example, because its invoice is missing or belongs to another school)
 * is logged as a sanitized warning and skipped; it is never marked paid or
 * failed automatically, and processing continues with the remaining orders.
 */
final class ReconcileTuitionPesaPalPayments extends Command
{
    protected $signature = 'tuition:reconcile-pesapal {--limit=100}';
    protected $description = 'Verify outstanding school-fee PesaPal orders using stored identities';

    public function handle(): int
    {
        $unavailable = 0;
        $skipped = 0;
        TuitionPayment::where('method', 'pesapal')->where('status', 'pending')->whereNotNull('gateway_txn_id')
            ->orderBy('updated_at')->limit(max(1, min(500, (int) $this->option('limit'))))->get()
            ->each(function ($payment) use (&$unavailable, &$skipped) {
                try {
                    TuitionPesaPalPayment::reconcile($payment);
                } catch (PesaPalException $exception) {
                    $unavailable++;
                } catch (\Throwable $exception) {
                    // An inaccessible invoice or other per-payment failure must
                    // not abort the whole run. Log only the payment id and
                    // exception class; never log payloads, credentials or
                    // student data. The payment is left pending.
                    $skipped++;
                    Log::warning('tuition:reconcile-pesapal skipped payment', [
                        'payment_id' => $payment->id,
                        'exception' => get_class($exception),
                    ]);
                }
            });
        $this->info('Reconciliation finished; unavailable verifications: ' . $unavailable . ', skipped: ' . $skipped);
        return ($unavailable || $skipped) ? self::FAILURE : self::SUCCESS;
    }
}
