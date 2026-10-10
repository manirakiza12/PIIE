<?php
namespace PiieSandbox;

final class ApplicantWorkflowGuard
{
    public static function syntheticEmail(mixed $email): bool
    {
        return is_string($email) && preg_match('/\Asandbox-journey-[a-z0-9-]{1,60}@example\.test\z/',$email)===1;
    }

    public function handle($request, \Closure $next)
    {
        if($request->path()!=='apply' && !str_starts_with($request->path(),'applicant/')) return $next($request);
        $settings=config('sandbox.connectivity');
        ApplicantRequestDiagnostics::record($request,'workflow_enter',['registration'=>$settings['registration'],'transactions'=>$settings['transactions']]);
        abort_if($settings['registration'] || ($settings['transactions'] && !ControlledCheckout::active()),403);
        $school=\App\Support\PublicTenantResolver::resolveSchoolId();
        ApplicantRequestDiagnostics::record($request,'workflow_tenant',['school_matches'=> $school===1,'resolved_type'=>get_debug_type($school)]);
        abort_unless($school===1,403);
        if($request->isMethod('POST') && in_array('/'.$request->path(),['/applicant/register','/applicant/login'],true)) {
            ApplicantRequestDiagnostics::record($request,'workflow_login_input',['synthetic_email_matches'=>self::syntheticEmail($request->input('email'))]);
            if (!self::syntheticEmail($request->input('email'))) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => 'This isolated sandbox accepts synthetic test accounts only. Use an address such as sandbox-journey-student-20261009@example.test.',
                ]);
            }
        }
        $applicant=auth('applicant')->user();
        if($settings['transactions']) {
            abort_if($request->isMethod('POST') && '/'.$request->path()!=='/applicant/payment/pesapal/start'
                && !preg_match('~\Aapplicant/payment/pesapal/[1-9][0-9]*/status\z~',$request->path()),403);
            abort_unless(ControlledCheckout::ownerMatches($applicant),403);
        }
        ApplicantRequestDiagnostics::record($request,'workflow_identity',['applicant_authenticated'=>$applicant!==null]);
        if($applicant) {
            $eligible=(int)$applicant->school_id===1 && self::syntheticEmail($applicant->email)
                && !\App\Models\Admission::where('applicant_id',$applicant->id)->whereIn('id',[1,2])->exists();
            if(!$eligible) {
                ApplicantRequestDiagnostics::record($request,'workflow_ineligible_session');
                // An old/ineligible cookie must not trap a visitor outside the public sign-in pages.
                // It grants no access: discard only the isolated applicant session, then render as a guest.
                if($request->isMethod('GET') && in_array('/'.$request->path(),['/apply','/applicant/register','/applicant/login'],true)) {
                    auth('applicant')->logoutCurrentDevice();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                } else abort(403);
            }
        }
        // Prevent input fields from being used to select an existing applicant/application.
        foreach(['admission_id','application_id','applicant_id','school_id'] as $key) {
            if($request->exists($key)) ApplicantRequestDiagnostics::record($request,'workflow_targeting_field',['field'=>$key]);
            abort_if($request->exists($key),403);
        }
        ApplicantRequestDiagnostics::record($request,'workflow_allowed');
        return $next($request);
    }
}
