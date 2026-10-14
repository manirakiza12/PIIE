<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class RetryInvoiceEmails extends Command
{
    protected $signature = 'invoices:retry-emails';
    protected $description = 'Retry pending invoice emails, up to five attempts per invoice';
    public function handle(): int
    {
        $this->info(\App\Support\Billing\InvoiceEmail::retry().' invoice email(s) sent.');
        return self::SUCCESS;
    }
}
