<?php

namespace App\Console\Commands;

use App\Support\LiveClasses\LiveClassEmailDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RetryLiveClassEmails extends Command
{
    protected $signature = 'live-classes:retry-emails';
    protected $description = 'Retry pending Live Class emails without duplicating in-app notifications';

    public function handle(): int
    {
        DB::table('live_class_email_deliveries')->whereNull('sent_at')->whereNull('discarded_at')
            ->where('attempts', '<', 5)->where('available_at', '<=', now())->orderBy('id')->limit(100)
            ->pluck('id')->each(fn ($id) => LiveClassEmailDelivery::deliver((int) $id));
        return self::SUCCESS;
    }
}
