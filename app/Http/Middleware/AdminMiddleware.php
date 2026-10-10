<?php

namespace App\Http\Middleware;

use App\Support\Permissions\PortalAccessDenial;
use App\Support\Permissions\PermissionService;
use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        // Generic Staff may enter only explicitly mapped RBAC routes. They do
        // not join the compatibility allowlist used by legacy staff accounts.
        if ($user && (int) $user->role_id === \App\Support\Roles\SystemRole::GENERIC_STAFF) {
            $route = $request->route();
            $permission = app(PermissionService::class)->routePermission($route?->getName());
            if ($permission !== null && app(PermissionService::class)->allows($user, $permission)) {
                return app(EnsureSchoolSubscription::class)->handle($request, $next);
            }

            return PortalAccessDenial::redirect($user);
        }

        // 9 = Registrar (RegistrarMiddleware) — added so logging in doesn't
        // dead-end at admin.dashboard; no Registrar-specific view exists
        // yet, so this is shared Admin access, not a scoped Registrar one.
        $staffRoles = [2, 3, 4, 5, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19];

        if ($user && in_array($user->role_id, $staffRoles) && $user->account_status != 'disable' && !$user->isStaffPortalBlocked()) {
            return app(EnsureSchoolSubscription::class)->handle($request, $next);
        }

        return PortalAccessDenial::redirect($user, 'admin.account_disableview');
    }
}
