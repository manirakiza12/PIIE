<?php

namespace App\Http\Middleware;

use App\Support\Subscriptions\SchoolSubscriptionAccess;
use Closure;
use Illuminate\Http\Request;

/** Runs after staff/account authorization, across every controller admitted by admin guards. */
class EnsureSchoolSubscription
{
  public function handle(Request $request, Closure $next)
{
    if (!config('app.enforce_school_subscriptions', false)) {
        return $next($request);
    }

    $user = auth()->user();

    if (!$user || (int) $user->role_id === 1) {
        return $next($request);
    }

    $denial = SchoolSubscriptionAccess::denial(
        $user->school_id,
        $request->route()?->getName()
    );

    return $denial ?? $next($request);
}
}
