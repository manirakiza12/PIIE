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

    public function handle(): int
    {
        return 0;
    }
}
