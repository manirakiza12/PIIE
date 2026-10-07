<?php

namespace App\Console\Commands;

use App\Support\Payments\PaymentIdentityPreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PaymentIdentityPreflightCommand extends Command
{
    protected $signature = 'payments:identity-preflight {--database= : Configured connection name}';
    protected $description = 'Read-only application payment identity audit (row IDs only)';

    public function handle(): int
    {
        $report = PaymentIdentityPreflight::inspect(DB::connection($this->option('database') ?: null));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $report['duplicate_eligible_row_ids'] ? 1 : 0;
    }
}
