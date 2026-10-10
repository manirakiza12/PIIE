<?php

namespace App\Support;

use App\Mail\StudentPortalActivationEmail;
use App\Models\IntakeSession;
use App\Models\Programme;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Shared "send the student their portal login email" helper, used by both
 * the Admission → Student conversion flow and the admin-triggered resend
 * action, so the mail-building logic (and the SMTP-configured guard) only
 * lives in one place. Never persists or logs the plaintext password passed
 * in — callers are responsible for having already hashed/stored it.
 */
class StudentPortalActivation
{
    public static function sendActivationEmail(User $student, string $plainPassword, ?int $programmeId = null, ?int $intakeSessionId = null, bool $durable = false): bool
    {
        if ($durable && \Illuminate\Support\Facades\Schema::hasTable('applicant_notification_deliveries')) {
            $id = \App\Support\Admissions\ApplicantNotificationDelivery::record($student->email, [
                'name' => $student->name, 'email' => $student->email, 'password' => $plainPassword, 'code' => $student->code,
                'programme' => $programmeId ? Programme::where('school_id', $student->school_id)->find($programmeId)?->name : null,
                'intake' => $intakeSessionId ? IntakeSession::where('school_id', $student->school_id)->find($intakeSessionId)?->name : null,
                'school_id' => $student->school_id,
            ], 'student_activation');
            if (\Illuminate\Support\Facades\DB::transactionLevel() > 0) {
                \Illuminate\Support\Facades\DB::afterCommit(fn () => \App\Support\Admissions\ApplicantNotificationDelivery::deliver($id));
                return true;
            }
            return \App\Support\Admissions\ApplicantNotificationDelivery::deliver($id);
        }
        if ($durable && \Illuminate\Support\Facades\DB::transactionLevel() > 0) {
            \Illuminate\Support\Facades\DB::afterCommit(fn () => self::sendActivationEmail($student, $plainPassword, $programmeId, $intakeSessionId));
            return true;
        }
        if (empty(get_settings('smtp_user')) || !get_settings('smtp_pass') || !get_settings('smtp_host') || !get_settings('smtp_port')) {
            return false;
        }

        $programme = $programmeId ? Programme::find($programmeId) : null;
        $intake    = $intakeSessionId ? IntakeSession::find($intakeSessionId) : null;

        \App\Support\Mail\SafeMail::send($student->email, new StudentPortalActivationEmail([
            'name'      => $student->name,
            'email'     => $student->email,
            'password'  => $plainPassword,
            'code'      => $student->code,
            'programme' => $programme->name ?? null,
            'intake'    => $intake->name ?? null,
            'school_id' => $student->school_id,
        ]));

        return true;
    }
}
