<?php

namespace App\Support\CourseRegistration;

use App\Models\{StudentFeeManager, StudentFeeRegistrationWaiver, User};
use App\Support\Payments\DecimalAmount;
use Illuminate\Support\Facades\{DB, Schema};

class StudentRegistrationConfirmationEligibility
{
    public function allows(User $student): bool { return $this->summary($student)['eligible']; }

    /** Current fee session only; a waiver never posts money or reduces debt. */
    public function summary(User $student): array
    {
        $result = ['eligible' => false, 'waived' => false, 'session_id' => null, 'total_minor' => 0,
            'paid_minor' => 0, 'required_minor' => 0, 'shortfall_minor' => 0, 'waiver' => null, 'fully_covered' => false];
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
