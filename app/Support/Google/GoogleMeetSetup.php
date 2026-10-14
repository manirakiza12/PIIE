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

    public static function callbackUrl(?\Illuminate\Http\Request $request = null): string
    {
        $uri = new \GuzzleHttp\Psr7\Uri(rtrim(config('app.url'), '/').'/auth/google/callback');
        if ($request && in_array(strtolower($request->getHost()), self::trustedHosts($uri->getHost()), true)) {
            $uri = $uri->withHost(strtolower($request->getHost()));
        }
        return (string) $uri;
    }

    public static function callbackUrls(): array
    {
        $uri = new \GuzzleHttp\Psr7\Uri(self::callbackUrl());
        return array_map(fn ($host) => (string) $uri->withHost($host), self::trustedHosts($uri->getHost()));
    }

    private static function trustedHosts(string $host): array
    {
        $host = strtolower($host);
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) || !str_contains($host, '.')) return [$host];
        return [$host, str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host];
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
