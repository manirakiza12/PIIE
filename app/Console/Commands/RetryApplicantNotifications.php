<?php

namespace App\Console\Commands;

use App\Support\Admissions\ApplicantNotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RetryApplicantNotifications extends Command
{
    protected $signature = 'applications:retry-notifications';
    protected $description = 'Retry encrypted applicant notification delivery records';
    public function handle(): int
    {
        if (! Schema::hasTable('applicant_notification_deliveries')) { $this->error('Notification migration is required.'); return self::FAILURE; }
        DB::table('applicant_notification_deliveries')->whereNull('sent_at')->where('attempts', '<', 5)->where('available_at', '<=', now())
            ->orderBy('id')->limit(100)->pluck('id')->each(fn ($id) => ApplicantNotificationDelivery::deliver($id));
        return self::SUCCESS;
    }
}
