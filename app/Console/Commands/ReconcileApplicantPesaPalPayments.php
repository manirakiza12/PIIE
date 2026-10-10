<?php

namespace App\Console\Commands;

use App\Models\ApplicationPayment;
use App\Support\Payments\ApplicantPesaPalPayment;
use App\Support\Payments\PesaPalException;
use Illuminate\Console\Command;

final class ReconcileApplicantPesaPalPayments extends Command
{
    protected $signature = 'applications:reconcile-pesapal {--limit=100}';
    protected $description = 'Verify outstanding applicant PesaPal orders using stored identities';

    public function handle(): int
    {
        $unavailable = 0;
        ApplicationPayment::where('method', 'pesapal')->where('status', 'pending')->whereNotNull('gateway_txn_id')
            ->orderBy('updated_at')->limit(max(1, min(500, (int) $this->option('limit'))))->get()
            ->each(function ($payment) use (&$unavailable) {
                try { ApplicantPesaPalPayment::reconcile($payment); }
                catch (PesaPalException $exception) { $unavailable++; }
            });
        $this->info('Reconciliation finished; unavailable verifications: ' . $unavailable);
        return $unavailable ? self::FAILURE : self::SUCCESS;
    }
}
