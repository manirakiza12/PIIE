<?php

namespace App\Support\CourseRegistration;

use App\Models\{StudentFeeManager, StudentFeeRegistrationWaiver, User};
use App\Support\Payments\DecimalAmount;
use Illuminate\Support\Facades\{DB, Schema};

class StudentRegistrationConfirmationEligibility
{
    public function allows(User $student): bool { return $this->summary($student)['eligible']; }

    /** Plain-language explanation of the same fee gate used by registration and confirmation. */
    public function blocker(User $student, ?array $summary = null): string
    {
        $finance = $summary ?? $this->summary($student);
        if (! $finance['session_id']) {
            return 'No current Finance Session is configured. Next step: Finance must set the institution current fee session.';
        }
        if ($finance['invoice_count'] === 0) {
            return 'No invoice is recorded for the current Finance Session. Next step: Finance must apply the applicable mandatory Fee Structure and create the invoice.';
        }
        if ($finance['shortfall_minor'] > 0) {
            $required = number_format($finance['required_minor'] / 100, 2);
            $paid = number_format($finance['paid_minor'] / 100, 2);
            $shortfall = number_format($finance['shortfall_minor'] / 100, 2);
            return "Verified payments cover {$paid}; the existing requirement is {$required} (20% of current-session invoices), leaving {$shortfall}. Pending payments and prior-payment claims do not count. Next step: Finance confirms a payment, or an authorised staff member approves a study waiver.";
        }
        if (! $finance['fully_covered']) {
            return 'The current-session invoice total is zero without an approved fee award. Next step: Finance must review the Fee Structure or approve the applicable award.';
        }
        return 'The current-session fee requirement is not yet met. Next step: Finance confirms the payment or an authorised staff member approves a study waiver.';
    }

    /** Current fee session only; a waiver never posts money or reduces debt. */
    public function summary(User $student): array
    {
        $result = ['eligible' => false, 'waived' => false, 'session_id' => null, 'total_minor' => 0,
            'paid_minor' => 0, 'required_minor' => 0, 'shortfall_minor' => 0, 'invoice_count' => 0, 'waiver' => null, 'fully_covered' => false];
        if (! Schema::hasTable('schools') || ! Schema::hasColumn('schools', 'running_session')
            || ! Schema::hasTable('student_fee_managers')) { return $result; }
        $sessionId = DB::table('schools')->where('id', $student->school_id)->value('running_session');
        if (! $sessionId) { return $result; }
        $result['session_id'] = (int) $sessionId;
        $waiver = Schema::hasTable('student_fee_registration_waivers')
            ? StudentFeeRegistrationWaiver::where('school_id', $student->school_id)->where('student_id', $student->id)
                ->where('session_id', $sessionId)->whereNull('revoked_at')->first() : null;
        $invoices = StudentFeeManager::where('student_id', $student->id)->where('school_id', $student->school_id)
            ->where('session_id', $sessionId)->get();
        $result['invoice_count'] = $invoices->count();
        foreach ($invoices as $invoice) {
            $total = max(0, DecimalAmount::minorUnits($invoice->total_amount) ?? 0);
            $result['total_minor'] += $total;
            // Historical offline claims may contain an asserted paid amount;
            // an unreviewed claim is never evidence of money received.
            $paid = $invoice->status === 'pending' ? 0 : max(0, DecimalAmount::minorUnits($invoice->paid_amount) ?? 0);
            $result['paid_minor'] += min($total, $paid);
        }
        $result['required_minor'] = intdiv($result['total_minor'] + 4, 5);
        $result['shortfall_minor'] = max(0, $result['required_minor'] - $result['paid_minor']);
        $result['waiver'] = $waiver;
        $result['waived'] = $waiver !== null;
        $result['fully_covered'] = $invoices->isNotEmpty() && $result['total_minor'] === 0
            && Schema::hasTable('student_fee_awards')
            && \App\Models\StudentFeeAward::where('school_id', $student->school_id)->whereIn('fee_id', $invoices->pluck('id'))->whereNull('revoked_at')->exists();
        $result['eligible'] = $waiver !== null || $result['fully_covered'] || ($result['total_minor'] > 0 && $result['shortfall_minor'] === 0);
        return $result;
    }
}
