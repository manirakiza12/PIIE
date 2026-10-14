<?php

namespace App\Support\Google;

use App\Models\GoogleMeetSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class GoogleMeetSetup
{
    public static function settings(): ?GoogleMeetSetting
    {
        return Schema::hasTable('google_meet_settings') ? GoogleMeetSetting::find(1) : null;
    }

    public static function callbackUrl(): string
    {
        return rtrim(config('app.url'), '/').'/auth/google/callback';
    }

    public static function owner(?int $schoolId = null): ?User
    {
        $settings = self::settings();
        if (!$settings?->enabled || !$settings->platform_user_id || ($schoolId !== null && (int) $settings->school_id !== $schoolId)) return null;
        return User::where('id', $settings->platform_user_id)->where('role_id', 1)->where('account_status', 'active')->first();
    }

    public static function ready(?int $schoolId = null): bool
    {
        $owner = self::owner($schoolId);
        return $owner && GoogleOAuthCredentials::isConfigured() && app(GoogleAccountService::class)->isConnected($owner);
    }
}
