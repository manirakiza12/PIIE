<?php

namespace App\Support\Admissions;

use App\Models\Admission;
use App\Models\Applicant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Gives a staff-entry Admission (applicant_id = NULL — created on the
 * candidate's behalf by an administrator, see AdmissionWizardController) a
 * way into the applicant portal, so it can pay its application fee through
 * the exact same Applicant\PaymentController every online applicant uses.
 * There is deliberately no separate staff-entry payment page/route — this
 * class's only job is to get the candidate a working Applicant login and a
 * link into the portal that already exists.
 *
 * The account is never given a password anyone else knows: it reuses the
 * portal's own forgot-password mechanism (applicant_password_resets +
 * Applicant\AuthController::resetPassword()) rather than emailing a
 * generated one, matching how the Applicant model has no
 * force_password_change concept to lean on the way User/StudentPortalActivation
 * does.
 */
class ApplicantPortalAccess
{
    private const RESET_TABLE = 'applicant_password_resets';

    /**
     * Ensures this Admission has a linked Applicant account, creating or
     * reusing one by (school_id, email) — an applicant who separately
     * started an online application under the same email is linked to
     * their existing account rather than getting a second one.
     */
    public static function ensureLinked(Admission $admission): Applicant
    {
        return DB::transaction(function () use ($admission) {
        $admission = Admission::whereKey($admission->id)->where('school_id', $admission->school_id)->lockForUpdate()->firstOrFail();
        if ($admission->applicant_id) {
            return $admission->applicant()->where('school_id', $admission->school_id)->where('email', $admission->email)->firstOrFail();
        }

        $applicant = Applicant::where('school_id', $admission->school_id)
            ->where('email', $admission->email)
            ->first();

        if (! $applicant) {
            $applicant = Applicant::create([
                'school_id'  => $admission->school_id,
                'first_name' => $admission->first_name,
                'last_name'  => $admission->last_name,
                'email'      => $admission->email,
                'phone'      => $admission->phone,
                // Unusable placeholder — nobody is ever told this value.
                // The candidate's first real access is always through the
                // reset-password link below.
                'password'   => Hash::make(Str::random(40)),
                'is_active'  => 1,
            ]);
        }

        $admission->forceFill(['applicant_id' => $applicant->id])->save();

        return $applicant;
        });
    }

    /**
     * Issues a reset-password link for the given applicant, using exactly
     * the token mechanism Applicant\AuthController::sendResetLink()/
     * resetPassword() already implement — this is not a new auth pathway,
     * just this class triggering the existing one server-side instead of
     * the applicant requesting it themselves from the login screen.
     */
    public static function paymentLinkFor(Applicant $applicant): string
    {
        $token = Str::random(64);

        DB::table(self::RESET_TABLE)->where('email', $applicant->email)->delete();
        DB::table(self::RESET_TABLE)->insert([
            'email'      => $applicant->email,
            'token'      => Hash::make($token),
            'created_at' => now(),
        ]);

        return route('applicant.password.reset', ['token' => $token, 'email' => $applicant->email]);
    }
}
