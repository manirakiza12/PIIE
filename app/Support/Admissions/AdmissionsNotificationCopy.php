<?php

namespace App\Support\Admissions;

use App\Mail\ApplicantNotificationEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Independent office delivery: a failed office email must not resend the student's email. */
final class AdmissionsNotificationCopy
{
    public static function settingKey(int $schoolId): string
    {
        return 'admissions_notification_email_'.$schoolId;
    }

    public static function recipient(int $schoolId): ?string
    {
        if ($schoolId <= 0) return null;
        $email = trim((string) DB::table('global_settings')->where('key', self::settingKey($schoolId))->value('value'));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function send(string $studentEmail, array $data): void
    {
        $schoolId = (int) ($data['school_id'] ?? 0);
        $recipient = self::recipient($schoolId);
        if (! $recipient || strcasecmp($recipient, $studentEmail) === 0) return;

        // Office copies preserve the notice and attachments, never personal access links.
        unset($data['cta_url'], $data['cta_label'], $data['access_url'], $data['payment_invitation_admission_id']);
        $data['subject'] = 'Admissions copy: '.($data['subject'] ?? 'Student notification');
        $data['details']['Student Email'] = $studentEmail;
        $data['_student_recipient'] = $studentEmail;
        if (Schema::hasTable('applicant_notification_deliveries')) {
            $id = ApplicantNotificationDelivery::record($recipient, $data, 'admissions_copy');
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(fn () => ApplicantNotificationDelivery::deliver($id));
            } else {
                ApplicantNotificationDelivery::deliver($id);
            }
            return;
        }
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(function () use ($recipient, $data) {
                if (ApplicantNotifier::isConfigured()) {
                    \App\Support\Mail\SafeMail::send($recipient, new ApplicantNotificationEmail($data), 'admissions-copy');
                }
            });
            return;
        }
        if (ApplicantNotifier::isConfigured()) {
            \App\Support\Mail\SafeMail::send($recipient, new ApplicantNotificationEmail($data), 'admissions-copy');
        }
    }
}
