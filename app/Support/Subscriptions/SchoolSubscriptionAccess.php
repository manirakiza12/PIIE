<?php

namespace App\Support\Subscriptions;

use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only school access policy; applicant fees never enter this decision. */
final class SchoolSubscriptionAccess
{
    private const RECOVERY_ROUTES = [
        'admin.subscription', 'admin.subscription.purchase', 'admin.subscription.payment',
        'admin.subscription.offline_payment', 'admin.subscription.upgrade_subscription',
        'admin.subscription.marzpay.start', 'admin.subscription.marzpay.status',
        'admin_free_subcription', 'admin.admin_subscription_offline_payment',
        'admin.account_disableview',
    ];

    public static function bypassPermitted(): bool
    {
        if (!config('app.bypass_subscription', false) || !app()->environment('local')) return false;
        $connection = config('database.connections.'.config('database.default'));
        if (($connection['driver'] ?? null) !== 'mysql'
            || !in_array($connection['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
            || (int)($connection['port'] ?? 0) !== 3307) return false;
        try {
            $server = DB::selectOne('select @@port as p, @@datadir as d');
            $directory = strtolower(str_replace('\\', '/', (string)($server->d ?? '')));
            return (int)$server->p === 3307 && str_contains($directory, '/piie-dev-db/');
        } catch (\Throwable) { return false; }
    }

    /** A returned denial must be returned by middleware, never sent and ignored. */
    public static function denial($schoolId, ?string $routeName)
    {
        if (in_array($routeName, self::RECOVERY_ROUTES, true) || self::bypassPermitted()) return null;
        if (empty($schoolId) || !Schema::hasTable('subscriptions')) {
            return response('School subscription configuration is unavailable. Contact support.', 503);
        }

        $query = Subscription::where('school_id', $schoolId);
        if (Schema::hasColumn('subscriptions', 'status')) $query->where('status', 1);
        if (Schema::hasColumn('subscriptions', 'active')) $query->where('active', 1);
        $subscription = $query->latest('id')->first();

        // Preserve legacy lifetime (0), schemas without expiry, and the existing
        // calendar-day expiry boundary rather than introducing an hourly cutoff.
        $allowed = $subscription && (!Schema::hasColumn('subscriptions', 'expire_date')
            || (string)$subscription->expire_date === '0'
            || (int)$subscription->expire_date >= strtotime(date('Y-m-d')));
        return $allowed ? null : redirect()->route('admin.subscription');
    }
}
