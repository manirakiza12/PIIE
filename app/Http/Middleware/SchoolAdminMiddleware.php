<?php

namespace App\Http\Middleware;

use App\Support\Permissions\PortalAccessDenial;
use Closure;
use Illuminate\Http\Request;

/**
 * Narrow guard for privileged administrator-management actions only
 * (RBAC Phase 2A). AdminMiddleware deliberately admits every staff role
 * (3, 4, 5, 9–19) so they can share ordinary admin/* pages; that stays as
 * it is. This is attached on top of it, per route, to the handful of
 * actions that must be School Administrator (role 2) only:
 *
 *   'school_admin'          any School Administrator.
 *   'school_admin:primary'  the school's primary administrator
 *                           (users.school_role = 1 — the same condition
 *                           admin_list.blade.php already uses to show the
 *                           "Admin Permission" action), and never on their
 *                           own account (the {id} route parameter).
 *   'school_admin:hr'       a School Administrator or an HR Manager (15) —
 *                           staff employment-record actions (RBAC Phase 2B:
 *                           create/edit/delete Teacher, Accountant, Librarian
 *                           and Warden records). Login-identity changes on
 *                           those records stay School Administrator only; see
 *                           AdminController::rejectLoginEmailChange().
 *
 * Target accounts are still resolved within the authenticated user's school
 * by the controller (AdminController::findStaffOrFail()); nothing here reads
 * a school_id from the request.
 */
class SchoolAdminMiddleware
{
    private const HR_MANAGER = 15;

    public function handle(Request $request, Closure $next, ?string $scope = null)
    {
        $user = auth()->user();
        $roleId = $user ? (int) $user->role_id : 0;

        $allowed = $user
            && ($roleId === 2 || ($scope === 'hr' && $roleId === self::HR_MANAGER))
            && $user->account_status != 'disable'
            && !$user->isStaffPortalBlocked();

        if ($allowed && $scope === 'primary') {
            $allowed = (int) $user->school_role === 1
                && (int) $request->route('id') !== (int) $user->id;
        }

        if ($allowed) {
            return app(EnsureSchoolSubscription::class)->handle($request, $next);
        }

        return PortalAccessDenial::redirect($user, 'admin.account_disableview');
    }
}
