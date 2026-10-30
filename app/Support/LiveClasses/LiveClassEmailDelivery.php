<?php

namespace App\Support\LiveClasses;

use App\Mail\LiveClassReminderEmail;
use App\Models\LiveClass;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Durable per-recipient retries. SMTP acknowledgement cannot guarantee exactly-once receipt. */
final class LiveClassEmailDelivery
{
    public static function queue(LiveClass $class, User $student, string $key, LiveClassReminderEmail $mail): void
    {
        DB::table('live_class_email_deliveries')->insertOrIgnore([
            'school_id' => $class->school_id, 'live_class_id' => $class->id, 'user_id' => $student->id,
            'event_key' => $key, 'schedule_revision' => (int) $class->schedule_revision,
            'notification_revision' => (int) $class->notification_revision,
            'encrypted_message' => Crypt::encryptString(json_encode(['to' => $student->email, 'data' => $mail->data], JSON_THROW_ON_ERROR)),
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('live_class_email_deliveries')->where('live_class_id', $class->id)
            ->where('user_id', $student->id)->where('event_key', $key)->value('id');
        DB::afterCommit(fn () => self::deliver((int) $id));
    }

    public static function deliver(int $id): bool
    {
        // Claim a ten-minute lease under a row lock. No second worker may send
        // the same row while a transport call is in progress.
        $row = DB::transaction(function () use ($id) {
            $row = DB::table('live_class_email_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (!$row || $row->sent_at || $row->discarded_at || $row->attempts >= 5 || now()->lt($row->available_at)
                || !LiveClassNotifier::isMailConfigured()) return null;
            DB::table('live_class_email_deliveries')->where('id', $id)->update([
                'attempts' => $row->attempts + 1, 'available_at' => now()->addMinutes(10), 'updated_at' => now(),
            ]);
            return $row;
        });
        if (!$row) return false;
        try {
            $class = LiveClass::where('school_id', $row->school_id)->find($row->live_class_id);
            $student = User::where('school_id', $row->school_id)->where('role_id', 7)->find($row->user_id);
            $message = json_decode(Crypt::decryptString($row->encrypted_message), true, 512, JSON_THROW_ON_ERROR);
            $cancelled = $row->event_key === 'cancelled';
            $eligible = $class && ($cancelled ? LiveClassEligibility::confirmedOfferingStudentUserIds($class)
                : LiveClassEligibility::eligibleStudentUserIds($class))->contains((int) $row->user_id);
            $stale = !$class || !$student || !$eligible || $student->email !== $message['to']
                || $student->account_status === 'disable'
                || (int) $class->schedule_revision !== (int) $row->schedule_revision
                || (int) $class->notification_revision !== (int) $row->notification_revision
                || ($cancelled ? $class->status !== LiveClass::STATUS_CANCELLED
                    : (!$class->is_published || $class->status === LiveClass::STATUS_CANCELLED))
                || (str_starts_with($row->event_key, 'reminder_') && ($class->status !== LiveClass::STATUS_SCHEDULED
                    || !$class->scheduled_at || now()->gte($class->scheduled_at)));
            if ($stale) {
                DB::table('live_class_email_deliveries')->where('id', $id)->update([
                    'discarded_at' => now(), 'encrypted_message' => '[discarded]', 'updated_at' => now(),
                ]);
                return false;
            }
            Mail::to($student->email)->send(new LiveClassReminderEmail($message['data']));
            DB::table('live_class_email_deliveries')->where('id', $id)->update([
                'sent_at' => now(), 'encrypted_message' => '[delivered]', 'updated_at' => now(),
            ]);
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Live Class email delivery failed', ['delivery_id' => $id]);
            return false;
        }
    }
}
