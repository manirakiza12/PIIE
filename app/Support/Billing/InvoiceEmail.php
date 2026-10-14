<?php

namespace App\Support\Billing;

use App\Mail\InvoiceEmail as InvoiceMessage;
use App\Models\StudentFeeManager;
use App\Support\Admissions\ApplicantNotifier;
use App\Support\Mail\SafeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class InvoiceEmail
{
    public static function automatic(int $schoolId): bool
    {
        return Schema::hasTable('invoice_email_preferences') && (bool) DB::table('invoice_email_preferences')->where('school_id', $schoolId)->value('automatic');
    }

    public static function record(StudentFeeManager $invoice): int
    {
        if (!Schema::hasTable('invoice_email_deliveries')) throw new \RuntimeException('Invoice email migration is required.');
        DB::table('invoice_email_deliveries')->insertOrIgnore([
            'invoice_id' => $invoice->id, 'school_id' => $invoice->school_id,
            'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return (int) DB::table('invoice_email_deliveries')->where('invoice_id', $invoice->id)->value('id');
    }

    public static function created(StudentFeeManager $invoice): void
    {
        if (!self::automatic((int) $invoice->school_id)) return;
        self::request($invoice);
    }

    public static function request(StudentFeeManager $invoice): void
    {
        $id = self::record($invoice);
        DB::afterCommit(fn () => self::deliver($id));
    }

    public static function deliver(int $id, bool $resend = false): bool
    {
        $statuses = $resend ? ['pending', 'failed', 'sent'] : ['pending', 'failed'];
        $claim = DB::table('invoice_email_deliveries')->where('id', $id)->whereIn('status', $statuses);
        if (!$resend) $claim->where('attempts', '<', 5)->where('available_at', '<=', now());
        if (!$claim->update(['status' => 'sending', 'attempts' => $resend ? 1 : DB::raw('attempts + 1'), 'available_at' => now()->addMinutes(10), 'updated_at' => now()])) return false;
        $delivery = DB::table('invoice_email_deliveries')->find($id);
        $invoice = StudentFeeManager::where('school_id', $delivery->school_id)->find($delivery->invoice_id);
        $sent = false;
        try {
            if ($invoice && ApplicantNotifier::isConfigured()) {
                $billing = InvoiceBilling::details($invoice->toArray());
                if (filter_var($billing['email'], FILTER_VALIDATE_EMAIL)) $sent = SafeMail::send($billing['email'], new InvoiceMessage($invoice->toArray(), $billing), 'student-invoice');
            }
        } catch (\Throwable $exception) {
            // Invoice creation has committed; mail failures never invite duplicate billing.
            Log::warning('Invoice email could not be delivered', ['invoice_id' => $delivery->invoice_id, 'exception' => get_class($exception)]);
        }
        DB::table('invoice_email_deliveries')->where('id', $id)->update([
            'status' => $sent ? 'sent' : 'failed', 'sent_at' => $sent ? now() : null, 'updated_at' => now(),
        ]);
        return $sent;
    }

    public static function retry(): int
    {
        if (!Schema::hasTable('invoice_email_deliveries')) return 0;
        // Recover a worker interrupted after claiming a delivery.
        DB::table('invoice_email_deliveries')->where('status', 'sending')->where('available_at', '<=', now())->update(['status' => 'failed']);
        $sent = 0;
        foreach (DB::table('invoice_email_deliveries')->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)->where('available_at', '<=', now())->limit(100)->pluck('id') as $id) {
            if (self::deliver((int) $id)) ++$sent;
        }
        return $sent;
    }
}
