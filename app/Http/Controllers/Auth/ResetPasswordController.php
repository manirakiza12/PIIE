<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Support\Permissions\PermissionService;
use App\Support\Roles\SystemRole;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    /**
     * Where to redirect users after resetting their password.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::HOME;

    public function showResetForm(Request $request)
    {
        $user = \App\Models\User::where('email', $request->query('email'))->first();
        $token = $request->route()->parameter('token');

        if ($user && (int) $user->role_id === SystemRole::GENERIC_STAFF
            && Password::broker('users')->tokenExists($user, $token)) {
            return view('auth.passwords.staff-setup', [
                'token' => $token,
                'email' => $user->email,
            ]);
        }

        return view('auth.passwords.reset')->with([
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    /**
     * The successful end of a password establishment.
     *
     * Reached only from ResetsPasswords::reset(), which validates the request,
     * confirms the token against the broker and only then invokes this callback
     * — so an invalid, expired or mistyped attempt never reaches it, and the
     * setup state is never advanced by opening the form or by sending a link.
     *
     * `users.force_password_change` is the platform's existing DURABLE record of
     * "this account still owes the user choosing their own password": it is set
     * when the account is provisioned with a generated password
     * (AdminController, StaffProvisioningService) and is what
     * App\Support\Staff\StaffAccountAccess::requiresSetup() reads to decide
     * Setup required / Pending / Completed. Clearing it here, in the same single
     * save that stores the password, is what completes initial staff setup.
     *
     * This previously cleared the flag ONLY for Other Staff (role 20). Every
     * other staff base role — Lecturer, Accountant, Warden, HR Manager and the
     * rest — therefore kept force_password_change = 1 after a fully successful
     * setup: the password was set and the token consumed, but the admin Account
     * Access screen still reported "Setup required". The flag is now cleared for
     * every staff role, so all of them share one state machine.
     *
     * Scoped to staff on purpose: students run their own dedicated flow
     * (StudentController clears the flag on their own password page, and
     * StudentMiddleware enforces it), and parents have no staff setup at all.
     * Neither is touched here.
     *
     * Because this only ever clears the flag, an ordinary forgotten-password
     * reset for a staff member who has already completed setup leaves them
     * Completed — it never moves them back to Pending.
     */
    protected function resetPassword($user, $password)
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => Str::random(60),
        ]);

        if (app(PermissionService::class)->isStaffRole((int) $user->role_id)) {
            $user->force_password_change = false;
        }

        $user->save();
        event(new PasswordReset($user));
        $this->guard()->login($user);
    }

    public function redirectPath()
    {
        return (int) auth()->user()?->role_id === SystemRole::GENERIC_STAFF
            ? route('staff.dashboard')
            : $this->redirectTo;
    }

    protected function validationErrorMessages()
    {
        return [
            'token.required' => 'This password setup link is incomplete. Ask your administrator to send a new link.',
            'email.required' => 'Enter the email address associated with this account.',
            'email.email' => 'Enter a valid email address.',
            'password.required' => 'Choose a new password.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}
