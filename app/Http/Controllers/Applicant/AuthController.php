<?php

namespace App\Http\Controllers\Applicant;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\AuditLog;
use App\Support\Admissions\ApplicantNotifier;
use App\Support\PublicTenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Registration, sign-in and password recovery for the applicant portal.
 *
 * Deliberately hand-rolled rather than routed through Laravel's password
 * broker: the broker resolves users through the default guard's provider and
 * shares the `password_resets` table, and an applicant must never be able to
 * obtain a reset link that authenticates them as a staff member holding the
 * same email address.
 */
class AuthController extends Controller
{
    private const RESET_TABLE = 'applicant_password_resets';

    private function schoolId(): int
    {
        $schoolId = PublicTenantResolver::resolveSchoolId();

        if (! $schoolId) {
            abort(503, get_phrase('Online applications are not currently configured.'));
        }

        return (int) $schoolId;
    }

    // ── Registration ─────────────────────────────────────────────────────

    public function showRegister()
    {
        return view('applicant.auth.register', [
            'schoolId'  => $this->schoolId(),
            'countries' => config('countries'),
        ]);
    }

    public function register(Request $request)
    {
        $schoolId = $this->schoolId();

        // Honeypot, same approach the old public form used: bots fill every
        // field, humans never see this one. Silently accepted so the bot
        // learns nothing from being rejected.
        if ($request->filled('website')) {
            return redirect()->route('applicant.login')->with('success', get_phrase('Account created. Please sign in.'));
        }

        $dialCodes = array_unique(array_filter(array_column(config('countries'), 'dial_code')));

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => [
                'required', 'email', 'max:150',
                Rule::unique('applicants', 'email')->where(fn ($q) => $q->where('school_id', $schoolId)),
            ],
            'phone_code'            => ['required', Rule::in($dialCodes)],
            'phone_number'          => 'required|string|max:20|regex:/^[0-9\s\-\(\)]+$/',
            'password'              => ['required', 'confirmed', Password::min(8)],
            'terms'                 => 'accepted',
        ], [
            'email.unique' => get_phrase('An account with this email already exists. Please sign in instead.'),
            'terms.accepted' => get_phrase('Please accept the terms to continue.'),
        ]);

        $phone = trim($validated['phone_code'] . ' ' . $validated['phone_number']);

        $applicant = Applicant::create([
            'school_id'  => $schoolId,
            'first_name' => $validated['first_name'],
            'last_name'  => $validated['last_name'],
            'email'      => $validated['email'],
            'phone'      => $phone,
            'password'   => Hash::make($validated['password']),
            'is_active'  => 1,
        ]);

        AuditLog::record('create', 'Admissions', "Applicant account registered: {$applicant->email}", [
            'event_type'  => 'DATA',
            'record_type' => Applicant::class,
            'record_id'   => $applicant->id,
            'school_id'   => $schoolId,
        ]);

        Auth::guard('applicant')->login($applicant);
        $request->session()->regenerate();

        // The welcome email intentionally goes out before the draft exists —
        // ApplicationController creates the draft on first dashboard load, so
        // sending it there would couple account creation to page rendering.
        ApplicantNotifier::welcome($applicant);

        return redirect()->route('applicant.dashboard')
            ->with('success', get_phrase('Welcome. Your application has been started — you can complete it in any order and come back at any time.'));
    }

    // ── Sign in ──────────────────────────────────────────────────────────

    public function showLogin()
    {
        return view('applicant.auth.login', ['schoolId' => $this->schoolId()]);
    }

    public function login(Request $request)
    {
        $schoolId = $this->schoolId();

        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $attempted = Auth::guard('applicant')->attempt([
            'email'     => $credentials['email'],
            'password'  => $credentials['password'],
            'school_id' => $schoolId,
            'is_active' => 1,
        ], $request->boolean('remember'));

        if (! $attempted) {
            return back()
                ->withInput($request->only('email'))
                ->with('error', get_phrase('Those credentials do not match our records.'));
        }

        $request->session()->regenerate();

        Auth::guard('applicant')->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('applicant.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::guard('applicant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('applicant.login')->with('success', get_phrase('You have been signed out.'));
    }

    // ── Password recovery ────────────────────────────────────────────────

    public function showForgotPassword()
    {
        return view('applicant.auth.forgot_password');
    }

    public function sendResetLink(Request $request)
    {
        $schoolId = $this->schoolId();

        $request->validate(['email' => 'required|email']);

        $applicant = Applicant::where('school_id', $schoolId)
            ->where('email', $request->email)
            ->first();

        // Always the same response whether or not the address exists — the
        // reset form must not double as a way to enumerate who has applied.
        $genericResponse = redirect()->route('applicant.password.request')
            ->with('success', get_phrase('If that email is registered, a password reset link is on its way.'));

        if (! $applicant) {
            return $genericResponse;
        }

        $token = Str::random(64);

        DB::table(self::RESET_TABLE)->where('email', $applicant->email)->delete();
        DB::table(self::RESET_TABLE)->insert([
            'email'      => $applicant->email,
            'token'      => Hash::make($token),
            'created_at' => now(),
        ]);

        ApplicantNotifier::passwordReset($applicant, $token);

        return $genericResponse;
    }

    public function showResetPassword(Request $request, string $token)
    {
        return view('applicant.auth.reset_password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function resetPassword(Request $request)
    {
        $schoolId = $this->schoolId();

        $validated = $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $record = DB::table(self::RESET_TABLE)->where('email', $validated['email'])->first();

        if (! $record
            || ! Hash::check($validated['token'], $record->token)
            || \App\Support\Compatibility\WholeDateIntervals::minutes(now(), \Carbon\Carbon::parse($record->created_at)) > config('auth.passwords.applicants.expire', 60)) {
            return back()
                ->withInput($request->only('email'))
                ->with('error', get_phrase('This password reset link is invalid or has expired.'));
        }

        $applicant = Applicant::where('school_id', $schoolId)
            ->where('email', $validated['email'])
            ->first();

        if (! $applicant) {
            return back()->with('error', get_phrase('This password reset link is invalid or has expired.'));
        }

        $applicant->forceFill(['password' => Hash::make($validated['password'])])->save();

        DB::table(self::RESET_TABLE)->where('email', $applicant->email)->delete();

        AuditLog::record('update', 'Admissions', "Applicant password reset: {$applicant->email}", [
            'event_type'  => 'AUTH',
            'record_type' => Applicant::class,
            'record_id'   => $applicant->id,
            'school_id'   => $schoolId,
        ]);

        return redirect()->route('applicant.login')
            ->with('success', get_phrase('Your password has been reset. Please sign in.'));
    }
}
