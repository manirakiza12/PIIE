<?php

namespace App\Support\Billing;

use App\Models\{AuditLog, StudentFeeAward, StudentFeeManager, TuitionPayment, User};
use App\Support\Payments\DecimalAmount;
use App\Support\Permissions\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Institution-funded reductions, scoped to one invoice. Never payment credits. */
final class StudentFeeAwards
{
    public function change(User $actor, int $feeId, array $data): void
    {
        abort_unless(app(PermissionService::class)->allows($actor, 'finance.invoices'), 403);
        DB::transaction(function () use ($actor, $feeId, $data) {
            $fee = StudentFeeManager::whereKey($feeId)->where('school_id', $actor->school_id)->lockForUpdate()->firstOrFail();
            // Failed provider orders may still settle late. Reconcile those before adjusting an invoice.
            $unresolved = TuitionPayment::where('fee_id', $fee->id)->where('school_id', $fee->school_id)
                ->whereIn('status', ['pending', 'failed'])->where(function ($query) {
                    $query->whereNotNull('gateway_txn_id')->orWhere('status', 'pending');
                })->exists();
            if ($unresolved || in_array($fee->status, ['pending', 'processing'], true)) {
                $this->reject('Reconcile outstanding payment attempts before changing a fee award.');
            }
            $active = StudentFeeAward::where('school_id', $fee->school_id)->where('fee_id', $fee->id)->whereNull('revoked_at')->lockForUpdate()->first();
            $total = DecimalAmount::minorUnits($fee->total_amount);
            $paid = DecimalAmount::minorUnits($fee->paid_amount);
            if ($total === null || $paid === null || $total < 0 || $paid < 0) { $this->reject('Finance must reconcile this invoice before adding an award.'); }
            if ($data['action'] === 'grant') {
                if ($active) { $this->reject('Revoke the existing award before approving a replacement.'); }
                $amount = DecimalAmount::minorUnits($data['amount']);
                if ($amount === null || $amount <= 0 || $amount > $total || $total - $amount < $paid) {
                    $this->reject('Enter an award greater than zero and no larger than the unpaid invoice balance.');
                }
                $award = StudentFeeAward::create(['school_id' => $fee->school_id, 'fee_id' => $fee->id,
                    'type' => $data['type'], 'amount' => DecimalAmount::decimal($amount), 'reference' => $data['reference'] ?? null,
                    'reason' => $data['reason'], 'approved_by' => $actor->id, 'approved_by_name' => $actor->name]);
                $newTotal = $total - $amount;
            } else {
                if (! $active) { $this->reject('This invoice has no active award to revoke.'); }
                $award = $active;
                $newTotal = $total + DecimalAmount::minorUnits($active->amount);
                $active->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revoked_by_name' => $actor->name, 'revocation_reason' => $data['reason']]);
            }
            $fee->update(['total_amount' => DecimalAmount::decimal($newTotal), 'status' => $paid >= $newTotal ? 'paid' : 'unpaid']);
            AuditLog::record('update', 'Student Fees', ucfirst($data['action']) . ' ' . $award->type . ' on invoice #' . $fee->id,
                ['school_id' => $fee->school_id, 'record_type' => StudentFeeAward::class, 'record_id' => $award->id,
                    'old_values' => ['total_amount' => DecimalAmount::decimal($total)], 'new_values' => ['total_amount' => DecimalAmount::decimal($newTotal)]]);
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['award' => $message]);
    }
}
