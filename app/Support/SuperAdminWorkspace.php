<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SuperAdminWorkspace
{
    public static function showsSubscriptions(): bool
    {
        if (config('app.enforce_school_subscriptions', false)) {
            return true;
        }

        // Never hide existing platform billing records, even when enforcement is off.
        foreach (['packages', 'subscriptions', 'payment_history'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    public static function showsAddons(): bool
    {
        return Schema::hasTable('addons') && DB::table('addons')->exists();
    }
}
