<?php

namespace App\Support\CourseRegistration;

use App\Models\StudentFeeManager;
use App\Models\StudentFeeRegistrationWaiver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudentRegistrationConfirmationEligibility
{
    public function allows(User $student): bool
    {
        if (! Schema::hasTable('schools') || ! Schema::hasColumn('schools', 'running_session')) {
            return false;
        }
        if (! Schema::hasTable('student_fee_managers')) return false;
        $sessionId = DB::table('schools')->where('id', $student->school_id)->value('running_session');
        if (! $sessionId) {
            return false;
        }

        if (Schema::hasTable('student_fee_registration_waivers') && StudentFeeRegistrationWaiver::query()->where('school_id', $student->school_id)
            ->where('student_id', $student->id)->where('session_id', $sessionId)->whereNull('revoked_at')->exists()) {
            return true;
        }

        $invoices = StudentFeeManager::where('student_id', $student->id)
            ->where('school_id', $student->school_id)
            ->where('session_id', $sessionId)
            ->get();
        $total = (int) $invoices->sum(fn ($invoice) => max(0, (int) $invoice->total_amount));
        if ($total <= 0) {
            return false;
        }

        // Count verified invoice payments up to each invoice's face value. Five
        // times the paid amount avoids floating point rounding at the 20% line.
        $paid = (int) $invoices->sum(fn ($invoice) => min(
            max(0, (int) $invoice->total_amount),
            max(0, (int) $invoice->paid_amount)
        ));

        return $paid * 5 >= $total;
    }
}
