<?php

namespace App\Support\Mail;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Applies the existing platform-global settings to Laravel's active SMTP mailer. */
final class PlatformSmtpConfiguration
{
    public function apply(): void
    {
        if (!Schema::hasTable('global_settings')) {
            return;
        }

        $settings = DB::table('global_settings')->whereIn('key', [
            'smtp_protocol', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_crypto',
        ])->pluck('value', 'key');

        $host = $settings->get('smtp_host');
        if (!is_string($host) || trim($host) === '') {
            return;
        }

        Config::set([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $settings->get('smtp_port') ?: config('mail.mailers.smtp.port'),
            'mail.mailers.smtp.username' => $settings->get('smtp_user') ?: config('mail.mailers.smtp.username'),
            'mail.mailers.smtp.password' => SmtpPasswordSecret::reveal($settings->get('smtp_pass')) ?: config('mail.mailers.smtp.password'),
            'mail.mailers.smtp.encryption' => $settings->get('smtp_crypto') ?: config('mail.mailers.smtp.encryption'),
        ]);
    }
}
