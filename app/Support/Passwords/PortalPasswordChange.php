<?php

namespace App\Support\Passwords;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * One place that decides whether a portal "change my password" submission is
 * acceptable, so every portal page can reuse the same rules and the same
 * human-readable wording.
 *
 * Why this exists: the pages used to hand-roll the check as
 *
 *     if ($request->new_password != $request->confirm_password) { ... }
 *
 * Two problems. First, the error was a single generic flash message, so a
 * student who typed two matching passwords was told the passwords did not match
 * whenever a THIRD, visually last and identical-placeholder field
 * ("Current Password") was left blank - exactly what a student doing first-time
 * activation does, because the temporary password is only in the email. Second,
 * `!=` is a loose PHP comparison, so genuinely different numeric passwords were
 * accepted as matching: '123456' != '0123456' and '1e3' != '1000' are both
 * false. The confirmation now uses a strict comparison.
 *
 * The security rules are unchanged and are not weakened here: the CURRENT
 * password is still required (it proves the account holder is present), and the
 * new password is still hashed with the application's existing hasher. Nothing
 * is logged and no plaintext is ever returned.
 */
class PortalPasswordChange
{
    /**
     * Validate a portal password change. On failure the request is redirected
     * back with per-field, human-readable messages that never expose Laravel's
     * internal validation terminology.
     *
     * @return void
     */
    public static function validate(Request $request, string $temporaryPasswordHint = ''): void
    {
        Validator::make($request->all(), [
            'old_password' => ['required', 'string'],
            'new_password' => ['required', 'string'],
            'confirm_password' => ['required', 'string'],
        ], [
            'old_password.required' => self::currentPasswordMessage($temporaryPasswordHint),
            'new_password.required' => 'Please enter your new password.',
            'confirm_password.required' => 'Please enter your new password a second time to confirm it.',
        ])->validate();

        // Strict comparison: two identical strings are equal, and nothing else is.
        // This is what actually refuses a mismatched confirmation.
        if (! hash_equals((string) $request->input('new_password'), (string) $request->input('confirm_password'))) {
            Validator::make([], [])->after(function (ValidatorContract $validator): void {
                $validator->errors()->add(
                    'confirm_password',
                    'The two new passwords you entered do not match. Please type them again carefully.'
                );
            })->validate();
        }
    }

    /**
     * Whether the supplied current password really is this account's password.
     * Kept separate from validation so the caller can render a field-level error.
     */
    public static function currentPasswordMatches(?string $supplied, ?string $hash): bool
    {
        if ($supplied === null || $supplied === '' || $hash === null || $hash === '') {
            return false;
        }

        return Hash::check($supplied, $hash);
    }

    private static function currentPasswordMessage(string $hint): string
    {
        return $hint !== ''
            ? "Please enter the temporary password from your activation email. {$hint}"
            : 'Please enter your current password.';
    }
}
