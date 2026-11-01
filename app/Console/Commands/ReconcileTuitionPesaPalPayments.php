<?php

namespace App\Console\Commands;

use App\Models\TuitionPayment;
use App\Support\Payments\PesaPalException;
use App\Support\Payments\TuitionPesaPalPayment;
use Illuminate\Console\Command;

/**
 * Re-verify outstanding school-fee orders through PesaPal.
 *
 * The IPN is the primary confirmation path, but a missed or rejected
 * notification must not leave a verified payment uncredited. This command is
 * read-only against the provider and settles only what PesaPal confirms; an
 * unavailable status endpoint leaves the order pending.
 */
final class ReconcileTuitionPesaPalPayments extends Command
{
    protected $signature = 'tuition:reconcile-pesapal {--limit=100}';
    protected $description = 'Verify outstanding school-fee PesaPal orders using stored identities';

    public function handle(): int
    {
        $unavailable = 0;
        TuitionPayment::where('method', 'pesapal')->where('status', 'pending')->whereNotNull('gateway_txn_id')
            ->orderBy('updated_at')->limit(max(1, min(500, (int) $this->option('limit'))))->get()
            ->each(function ($payment) use (&$unavailable) {
                try { TuitionPesaPalPayment::reconcile($payment); }
                catch (PesaPalException $exception) { $unavailable++; }
            });
        $this->info('Reconciliation finished; unavailable verifications: ' . $unavailable);
        return $unavailable ? self::FAILURE : self::SUCCESS;
    }
}
