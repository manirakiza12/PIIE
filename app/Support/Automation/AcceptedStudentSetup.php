<?php

namespace App\Support\Automation;

use App\Models\{Admission, Applicant, AuditLog, StudentProfile, User};
use App\Support\Admissions\{ApplicationFee, ApplicationProgress};
use App\Support\{StudentFeeInvoiceGenerator, StudentPortalActivation, StudentProvisioningService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Acceptance authorizes account preparation, never academic placement or enrolment. */
final class AcceptedStudentSetup
{
    public static function available(): bool
    {
        return Schema::hasTable('automation_exceptions') && Schema::hasColumn('admissions', 'automation_student_id');
    }

    public function run(int $schoolId, int $admissionId): void
    {
        DB::transaction(function () use ($schoolId, $admissionId) {
            // Same school lock also serializes invoice preparation and retries.
            DB::table('schools')->where('id', $schoolId)->lockForUpdate()->firstOrFail();
            $admission = Admission::where('school_id', $schoolId)->whereKey($admissionId)->lockForUpdate()->firstOrFail();
            if (! in_array($admission->status, [Admission::STATUS_ACCEPTED, Admission::STATUS_ENROLLED], true)) {
                return;
            }
            if ($admission->status === Admission::STATUS_ENROLLED && ! $admission->automation_student_id) {
                return; // Existing historical conversions remain under their original workflow.
            }
            $block = fn ($code, $reason) => AutomationExceptions::block($schoolId, 'accepted_student', $admissionId, $code, $reason, 'Admissions / Registrar');
            if (! $admission->submitted_at || ! ApplicationProgress::canSubmit($admission)
                || ! in_array(ApplicationFee::calculatedStatus($admission), [Admission::FEE_PAID, Admission::FEE_WAIVED], true)) {
                $block('acceptance_requirements_missing', 'Admissions must confirm the completed submitted application and settled application fee before account preparation.');
                return;
            }
            if (! filter_var($admission->email, FILTER_VALIDATE_EMAIL) || ! $admission->programme_id || ! $admission->intake_session_id) {
                $block('account_details_missing', 'Admissions must confirm an email, programme and intake for account preparation.');
                return;
            }
            foreach (['programme_id' => 'programmes', 'intake_session_id' => 'intake_sessions'] as $field => $table) {
                if (! DB::table($table)->where('school_id', $schoolId)->where('id', $admission->$field)->exists()) {
                    $block('academic_context_invalid', 'Admissions must select a programme and intake belonging to this institution.');
                    return;
                }
            }
            $applicant = $admission->applicant_id ? Applicant::where('school_id', $schoolId)->whereKey($admission->applicant_id)->lockForUpdate()->first() : null;
            if ($admission->applicant_id && ! $applicant) {
                $block('applicant_context_invalid', 'Admissions must resolve the application owner in this institution.');
                return;
            }
            $student = $admission->automation_student_id
                ? User::whereKey($admission->automation_student_id)->lockForUpdate()->first()
                : User::whereRaw('LOWER(email) = ?', [strtolower(trim($admission->email))])->lockForUpdate()->first();
            $linkedId = $admission->automation_student_id ?: $applicant?->converted_user_id;
            if (($linkedId && (! $student || (int) $student->id !== (int) $linkedId))
                || ($student && ((int) $student->id !== (int) $linkedId || (int) $student->school_id !== $schoolId || (int) $student->role_id !== 7 || $student->account_status === 'disable'
                    || strcasecmp($student->email, trim($admission->email)) !== 0))
                || ($applicant?->converted_user_id && $admission->automation_student_id && (int) $applicant->converted_user_id !== (int) $admission->automation_student_id)) {
                $block('identity_requires_review', 'Admissions must resolve the existing account identity or disabled account. Automation will not attach by email, reset credentials or transfer an account.');
                return;
            }
            $profile = $student ? StudentProfile::where('user_id', $student->id)->lockForUpdate()->first() : null;
            if ($profile && ((int) $profile->school_id !== $schoolId || $profile->status !== 'active'
                || (int) $profile->programme_id !== (int) $admission->programme_id || (int) $profile->intake_session_id !== (int) $admission->intake_session_id)) {
                $block('profile_requires_review', 'Registrar must confirm an active profile with the approved programme/intake. Automation never reactivates students, performs a transfer or overwrites academic history.');
                return;
            }
            try {
                $result = $student && $profile
                    ? ['student' => $student, 'created' => false, 'plain_password' => null]
                    : StudentProvisioningService::provision([
                    'school_id' => $schoolId, 'first_name' => $admission->first_name, 'last_name' => $admission->last_name,
                    'email' => trim($admission->email), 'gender' => $admission->gender, 'birthday' => $admission->dob,
                    'phone' => $admission->phone, 'address' => $admission->physical_address,
                    'programme_id' => $admission->programme_id, 'intake_session_id' => $admission->intake_session_id,
                    'nationality' => $admission->nationality, 'applicant_id' => $admission->applicant_id,
                    'preserve_existing_profile' => true, 'prepare_invoices' => false,
                ]);
            } catch (ValidationException $exception) {
                $block('registration_details_missing', 'Admissions / Registrar must resolve: ' . implode(' ', $exception->validator->errors()->all()));
                return;
            }
            $student = $result['student'];
            if (! $admission->automation_student_id) {
                $admission->forceFill(['automation_student_id' => $student->id])->save();
            }
            if ($result['created']) {
                AuditLog::record('create', 'Admissions', 'Student account and profile prepared from accepted application; academic placement remains pending.', [
                    'school_id' => $schoolId, 'event_type' => 'DATA', 'record_type' => Admission::class,
                    'record_id' => $admissionId, 'new_values' => ['student_id' => $student->id, 'source' => 'application_acceptance'],
                ]);
                StudentPortalActivation::sendActivationEmail($student, $result['plain_password'], $admission->programme_id, $admission->intake_session_id, true, passwordSetupOnly: true);
            }
            AutomationExceptions::resolve($schoolId, 'accepted_student', $admissionId);
            $billing = StudentFeeInvoiceGenerator::prepareMandatoryForStudent($student);
            if ($billing['code']) {
                AutomationExceptions::block($schoolId, 'mandatory_invoice', $admissionId, $billing['code'], $billing['message'], 'Finance / Bursar');
            } else {
                AutomationExceptions::resolve($schoolId, 'mandatory_invoice', $admissionId);
            }
        }, 3);
    }
}
