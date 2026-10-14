<?php

namespace App\Console\Commands;

use App\Models\LiveClass;
use App\Models\User;
use App\Support\Google\GoogleAccountService;
use App\Support\Google\GoogleCalendarService;
use App\Support\Google\GoogleOAuthCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RefreshGoogleMeetLinks extends Command
{
    protected $signature = 'live-classes:refresh-google-links';
    protected $description = 'Refresh pending Google Meet conferences without creating new meetings';

    public function handle(): int
    {
        if (!GoogleOAuthCredentials::isConfigured() || !Schema::hasTable('live_classes')) return self::SUCCESS;
        $updated = 0;
        LiveClass::where('platform', 'google_meet')->where('google_conference_status', 'pending')
            ->whereNotNull('google_calendar_event_id')->whereNotIn('status', ['cancelled', 'ended'])
            ->where('ends_at', '>', now())->orderBy('id')->limit(25)->get()->each(function ($class) use (&$updated) {
                $owner = User::find($class->google_account_owner_id ?: $class->created_by);
                if (!$owner || $owner->account_status !== 'active' || !in_array((int) $owner->role_id, [1,3], true)) return;
                if ((int) $owner->role_id === 3 && (int) $owner->school_id !== (int) $class->school_id) return;
                try {
                    $access = app(GoogleAccountService::class)->accessTokenFor($owner);
                    if (!$access) return;
                    $result = app(GoogleCalendarService::class)->refreshEvent($access['token'], $class->google_calendar_id ?: ($access['connection']->calendar_id ?: 'primary'), $class->google_calendar_event_id);
                    if ($result['conference_status'] === 'ready') {
                        $class->update(['meeting_url' => $result['meeting_url'], 'google_conference_status' => 'ready']);
                        $updated++;
                    }
                } catch (\Throwable $e) {
                    // Retry later; provider exception bodies may contain credentials.
                }
            });
        $this->info("Google Meet links refreshed: $updated");
        return self::SUCCESS;
    }
}
