<?php

namespace App\Support\Admissions;

use App\Mail\ApplicantNotificationEmail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Encrypted durable retry records; a crashed send can be delivered more than once. */
final class ApplicantNotificationDelivery
{
    public static function record(string $to, array $data, string $mailType = 'applicant'): int
    {
        return DB::table('applicant_notification_deliveries')->insertGetId([
            'school_id' => $data['school_id'] ?? null,
            'encrypted_message' => Crypt::encryptString(json_encode(['to' => $to, 'data' => $data, 'mail_type' => $mailType], JSON_THROW_ON_ERROR)),
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function deliver(int $id): bool
    {
        $row = DB::transaction(function () use ($id) {
            $row = DB::table('applicant_notification_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->sent_at || $row->attempts >= 5 || now()->lt($row->available_at)) { return null; }
            DB::table('applicant_notification_deliveries')->where('id', $id)->update([
                'attempts' => $row->attempts + 1, 'available_at' => now()->addMinutes(10), 'updated_at' => now(),
            ]);
            return $row;
        });
        if (! $row) { return false; }
        try {
            if (! ApplicantNotifier::isConfigured()) { return false; }
            $message = json_decode(Crypt::decryptString($row->encrypted_message), true, 512, JSON_THROW_ON_ERROR);
            if (isset($message['data']['payment_invitation_admission_id'])) {
                $admission = \App\Models\Admission::whereKey($message['data']['payment_invitation_admission_id'])
                    ->where('school_id', $row->school_id)->first();
                // Never deliver an obsolete invitation or one reassigned to another email.
                if (! $admission || $admission->email !== $message['to'] || $admission->isFeeSettled()
                    || ! \App\Support\Payments\ApplicantPesaPalPayment::eligible($admission)) { return false; }
                $applicant = ApplicantPortalAccess::ensureLinked($admission);
                $message['data']['cta_url'] = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'applicant.pesapal.invitation', now()->addHour(), ['admission' => $admission->id]);
                $message['data']['access_url'] = ApplicantPortalAccess::paymentLinkFor($applicant);
            }
            $mail = match ($message['mail_type'] ?? 'applicant') {
                'applicant' => new ApplicantNotificationEmail($message['data']),
                'student_activation' => new \App\Mail\StudentPortalActivationEmail($message['data']),
                default => throw new \RuntimeException('Unsupported notification type'),
            };
            Mail::to($message['to'])->send($mail);
            DB::table('applicant_notification_deliveries')->where('id', $id)->update([
                'sent_at' => now(), 'encrypted_message' => '[delivered]', 'updated_at' => now(),
            ]);
            return true;
        } catch (\Throwable $exception) {
            // Do not log mail transport exceptions containing recipients or credentials.
            \Illuminate\Support\Facades\Log::warning('Applicant notification delivery failed', ['delivery_id' => $id]);
            return false;
        }
    }
}
