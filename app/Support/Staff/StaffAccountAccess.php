<?php

namespace App\Support\Staff;

use App\Models\User;
use Illuminate\Support\Facades\Password;

/**
 * The state of the EXISTING governed portal setup workflow, in one place.
 *
 * This is the single source of truth behind the Account Access screen, the
 * "Set Up Portal Access" action on a staff profile, and the account-access state
 * shown in the Staff Directory. It adds no new mechanism: the states are the
 * ones GenericStaffAccountAccessController has always used, and every question
 * about a setup link is answered through Laravel's own password broker
 * (Password::broker('users')), so no token is ever read, stored or generated here.
 *
 *   required   no password has been chosen yet, and no setup link is outstanding
 *   pending    a setup link was issued and has not yet been used
 *   completed  a password exists and no change is pending
 */
final class StaffAccountAccess
{
    public const REQUIRED = 'required';

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    /**
     * A staff member still owes a password choice: the account was created with
     * a forced-change placeholder, or has no usable password at all.
     */
    public static function requiresSetup(User $member): bool
    {
        return (bool) $member->force_password_change || empty($member->getAuthPassword());
    }

    /**
     * Whether a setup link is outstanding.
     *
     * Delegated to the broker's own repository, which is the canonical and
     * secure check: it purges an expired token and reports whether a live one
     * exists. The token value itself is never read or returned.
     */
    public static function hasOutstandingLink(User $member): bool
    {
        return Password::broker('users')->getRepository()->recentlyCreatedToken($member);
    }

    /** required | pending | completed */
    public static function state(User $member): string
    {
        if (!self::requiresSetup($member)) {
            return self::COMPLETED;
        }

        return self::hasOutstandingLink($member) ? self::PENDING : self::REQUIRED;
    }

    /** True when the workflow still permits issuing (or re-issuing) a setup link. */
    public static function canSendLink(User $member): bool
    {
        return self::requiresSetup($member);
    }

    /**
     * Whether the institution's mail provider is configured, using the same
     * read-only check the rest of the application uses before it attempts to
     * send. This only READS the existing settings; it never changes them, and it
     * exists so the Account Access screen can explain an undeliverable link
     * instead of failing silently.
     */
    public static function isMailConfigured(): bool
    {
        return !empty(get_settings('smtp_user'))
            && (bool) get_settings('smtp_pass')
            && (bool) get_settings('smtp_host')
            && (bool) get_settings('smtp_port');
    }
}
