<?php

namespace App\Console\Commands;

use App\Mail\PortalMessageReminder;
use App\Models\{MessageThrade, User};
use App\Support\Mail\SafeMail;
use App\Support\Messaging\MessagingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB, Log, Schema};

final class SendPortalMessageReminders extends Command
{
    protected $signature = 'messages:send-reminders {--limit=100}';
    protected $description = 'Deliver delayed, rate-limited reminders for unread portal messages without including their content.';

    public function handle(MessagingService $messages): int
    {
        if (!Schema::hasTable('messaging_email_deliveries')) return self::SUCCESS;
        if (!get_settings('smtp_user') || !get_settings('smtp_pass') || !get_settings('smtp_host') || !get_settings('smtp_port')) {
            $this->info('Mail is not configured; message reminders remain pending.');
            return self::SUCCESS;
        }
        $ids = DB::table('messaging_email_deliveries')->whereIn('status', ['pending', 'processing'])
            ->where('available_at', '<=', now())->orderBy('id')->limit(min(500, max(1, (int) $this->option('limit'))))->pluck('id');
        foreach ($ids as $id) {
            $row = DB::transaction(function () use ($id) {
                $row = DB::table('messaging_email_deliveries')->where('id', $id)->lockForUpdate()->first();
                if (!$row || !in_array($row->status, ['pending', 'processing'], true) || now()->lt($row->available_at)) return null;
                if ($row->attempts >= 3) {
                    DB::table('messaging_email_deliveries')->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);
                    return null;
                }
                DB::table('messaging_email_deliveries')->where('id', $id)->update(['status' => 'processing', 'attempts' => $row->attempts + 1,
                    'available_at' => now()->addMinutes(10), 'updated_at' => now()]);
                return $row;
            });
            if (!$row) continue;
            $recipient = User::where('school_id', $row->school_id)->find($row->user_id);
            $thread = MessageThrade::where('school_id', $row->school_id)
                ->where(fn ($q) => $q->where('sender_id', $row->user_id)->orWhere('reciver_id', $row->user_id))->find($row->thread_id);
            $eligible = false;
            if ($recipient && $thread) {
                $otherId = (int) $thread->sender_id === (int) $recipient->id ? $thread->reciver_id : $thread->sender_id;
                $other = User::where('school_id', $row->school_id)->find($otherId);
                $eligible = $other && $messages->pairAllowed($recipient, $other) && !$messages->blocked($recipient, $other)
                    && $messages->messages($recipient, $thread)->where('reciver_id', $recipient->id)->where('read_status', 0)->where('id', '<=', $row->last_chat_id)->exists();
            }
            if (!$eligible) {
                DB::table('messaging_email_deliveries')->where('id', $id)->where('last_chat_id', $row->last_chat_id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                continue;
            }
            if (Cache::has('messaging:presence:'.$row->school_id.':'.$row->user_id)) {
                DB::table('messaging_email_deliveries')->where('id', $id)->where('last_chat_id', $row->last_chat_id)->update([
                    'status' => 'pending', 'attempts' => $row->attempts, 'available_at' => now()->addMinute(), 'updated_at' => now()]);
                continue;
            }
            try {
                $sent = SafeMail::send($recipient->email, new PortalMessageReminder((int) $thread->id), 'portal_message_reminder');
            } catch (\Throwable $error) {
                // No content, recipient, mail exception text or credentials in logs.
                Log::warning('Portal message reminder failed', ['delivery_id' => $id, 'exception' => get_class($error)]);
                $sent = false;
            }
            $updates = ['status' => $sent ? 'sent' : ($row->attempts >= 2 ? 'failed' : 'pending'), 'updated_at' => now()];
            if ($sent) $updates['last_sent_at'] = now();
            DB::table('messaging_email_deliveries')->where('id', $id)->where('last_chat_id', $row->last_chat_id)->update($updates);
            if ($sent) {
                DB::table('messaging_email_deliveries')->where('id', $id)->update(['last_sent_at' => now()]);
                // A new message can arrive while SMTP is sending the reminder.
                // Preserve that new batch and give it the same cooldown.
                DB::table('messaging_email_deliveries')->where('id', $id)->where('status', 'pending')
                    ->where('available_at', '<', now()->addMinutes(10))->update(['available_at' => now()->addMinutes(10)]);
            }
        }
        return self::SUCCESS;
    }
}
