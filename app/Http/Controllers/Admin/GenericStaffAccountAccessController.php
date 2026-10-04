<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\GenericStaffPasswordSetupMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Mail\SafeMail;
use App\Support\Permissions\PermissionAssignmentService;
use App\Support\Roles\SystemRole;
use App\Support\Staff\StaffAccountAccess;
use App\Support\Staff\StaffRecordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * The EXISTING governed staff account-setup workflow — password setup, the secure
 * setup link, and the account-access state.
 *
 * This began life serving only Other Staff (role_id 20), which is why a Lecturer
 * whose account was created with a forced-change placeholder had no screen to
 * finish setup on: the resolver refused every other base role with a 404. It is
 * now the account-access workflow for EVERY staff member of the acting
 * administrator's school, because none of the mechanism was ever role-specific:
 *
 *   - the same Password::broker('users') that every user account uses;
 *   - the same GenericStaffPasswordSetupMail, which carries a link and never a
 *     password, so no administrator ever sets or sees one;
 *   - the same SafeMail transport handling, which keeps the completed action and
 *     reports an undeliverable message instead of raising a 500;
 *   - the same audit action.
 *
 * Nothing is duplicated. Setup-state resolution moved into StaffAccountAccess so
 * this screen, the staff profile and the Staff Directory all read one answer, and
 * the token is only ever handled through the broker's own repository.
 *
 * Authorization is unchanged: a school administrator who can administer access
 * (PermissionAssignmentService::canAdminister). Tenant scope comes from
 * StaffRecordService::staffInSchool, so another school's staff id is a plain 404
 * and a student, parent or platform Super Admin is never a staff record.
 *
 * The class name is retained for backwards compatibility with the existing
 * routes and tests; the workflow it implements is no longer limited to
 * "generic" staff.
 */
class GenericStaffAccountAccessController extends Controller
{
    public function show($id, PermissionAssignmentService $assignments)
    {
        $this->authorizeAdministrator($assignments);
        $member = $this->staffOrFail($id);

        return response()->view('admin.rbac.staff.account_access', [
            'member' => $member,
            'setupState' => StaffAccountAccess::state($member),
            'mailConfigured' => StaffAccountAccess::isMailConfigured(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function sendSetupLink(Request $request, $id, PermissionAssignmentService $assignments)
    {
        $this->authorizeAdministrator($assignments);
        $member = $this->staffOrFail($id);

        if (!StaffAccountAccess::canSendLink($member)) {
            return redirect()->back()->with('message', get_phrase('Password setup is completed. The staff member can use password recovery on the sign-in page if needed.'));
        }

        $broker = Password::broker('users');
        $repository = $broker->getRepository();

        // The broker's own "recently created" check: an outstanding link is
        // honoured rather than reissued, and an expired one is purged.
        if ($repository->recentlyCreatedToken($member)) {
            return redirect()->back()->with('error', get_phrase('A password setup link was sent recently. Please wait before sending another.'));
        }

        $token = $broker->createToken($member);
        $setupUrl = route('password.reset', ['token' => $token, 'email' => $member->email]);
        $delivered = SafeMail::send(
            $member->email,
            new GenericStaffPasswordSetupMail($member->name, $setupUrl),
            'staff-password-setup'
        );

        if (! $delivered) {
            // Never leave a live, undelivered token behind: a link nobody
            // received cannot be used, and a stale one would block the retry.
            $broker->deleteToken($member);

            return redirect()->back()->with('error', StaffAccountAccess::isMailConfigured()
                ? get_phrase("We couldn't send the password setup email. Please check the institution's email settings and try again.")
                : get_phrase("We couldn't send the password setup email because this institution's email settings are not configured yet. An administrator must configure them under Settings before a setup link can be delivered."));
        }

        AuditLog::record('STAFF_PASSWORD_SETUP_LINK_ISSUED', 'Staff & Students', 'Issued a password setup link for a staff account.', [
            'school_id' => (int) $member->school_id,
            'record_type' => User::class,
            'record_id' => (int) $member->id,
        ]);

        return redirect()->back()
            ->with('message', get_phrase('A secure password setup link was sent to the staff member.'));
    }

    private function authorizeAdministrator(PermissionAssignmentService $assignments): User
    {
        $actor = auth()->user();
        abort_unless($actor
            && (int) $actor->role_id === SystemRole::SCHOOL_ADMIN
            && !empty($actor->school_id)
            && $assignments->canAdminister($actor), 403);

        return $actor;
    }

    /**
     * A staff member of the administrator's own school, of ANY base role, so a
     * Lecturer's pending setup is reachable. Another school's id — and a student,
     * parent or platform Super Admin — is a plain 404.
     */
    private function staffOrFail($id): User
    {
        return app(StaffRecordService::class)->staffInSchool(auth()->user(), (int) $id);
    }
}
