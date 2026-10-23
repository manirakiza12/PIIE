<?php

namespace App\Support\Billing;

use App\Models\{AuditLog, StudentFeeManager, StudentFinancialSetupReview, User};
use App\Support\CourseRegistration\CourseRegistrationFeeWaiverService;
use App\Support\Payments\{DecimalAmount, StudentOpeningPayments};
use App\Support\Permissions\PermissionService;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

final class ExistingStudentFinancialSetup
{
    private const PERMISSIONS = [
        'prior_payment' => 'finance.payments',
        'award' => 'finance.invoices',
        'study_waiver' => 'academic.course_registration.fee_waiver',
    ];

    public function mayConfirm(User $actor, string $item): bool
    {
        return isset(self::PERMISSIONS[$item])
            && app(PermissionService::class)->allows($actor, self::PERMISSIONS[$item]);
    }

    public function confirm(User $actor, int $feeId, string $item): void
    {
        abort_unless($this->mayConfirm($actor, $item), 403);
        if (! Schema::hasTable('student_financial_setup_reviews')) {
            abort(404);
        }

        DB::transaction(function () use ($actor, $feeId, $item): void {
            DB::table('schools')->where('id', $actor->school_id)->lockForUpdate()->firstOrFail();
            $fee = StudentFeeManager::whereKey($feeId)->where('school_id', $actor->school_id)->lockForUpdate()->firstOrFail();
            $review = StudentFinancialSetupReview::where('school_id', $actor->school_id)
                ->where('fee_id', $fee->id)->where('student_id', $fee->student_id)
                ->where('session_id', $fee->session_id)->lockForUpdate()->firstOrFail();

            $statusField = $item . '_status';
            $payloadField = $item;
            if ($review->{$statusField} !== 'pending' || ! is_array($review->{$payloadField})) {
                throw ValidationException::withMessages(['financial_setup' => 'This setup item is not awaiting confirmation.']);
            }

            $payload = $review->{$payloadField};
            switch ($item) {
                case 'prior_payment':
                    $this->confirmPriorPayment($actor, $fee, $payload);
                    break;
                case 'award':
                    $this->confirmAward($actor, $fee, $payload);
                    break;
                case 'study_waiver':
                    $this->confirmStudyWaiver($actor, $fee, $payload);
                    break;
            }

            $review->forceFill([
                $statusField => 'confirmed',
                $item . '_confirmed_by' => $actor->id,
                $item . '_confirmed_at' => now(),
            ])->save();

            AuditLog::record('confirm', 'Student Financial Setup', "Confirmed {$item} for invoice #{$fee->id}.", [
                'school_id' => $fee->school_id,
                'record_type' => StudentFinancialSetupReview::class,
                'record_id' => $review->id,
                'new_values' => ['item' => $item, 'confirmed_by' => $actor->id, 'session_id' => $fee->session_id],
            ]);
        });
    }

    private function confirmPriorPayment(User $actor, StudentFeeManager $fee, array $payload): void
    {
        app(StudentOpeningPayments::class)->change($actor, (int) $fee->id, [
            'action' => 'record',
            'amount' => $payload['amount'],
            'paid_on' => $payload['paid_on'],
            'method' => $payload['method'],
            'reference' => $payload['reference'],
            'reason' => $payload['reason'],
        ]);
    }

    private function confirmAward(User $actor, StudentFeeManager $fee, array $payload): void
    {
        app(StudentFeeAwards::class)->change($actor, (int) $fee->id, [
            'action' => 'grant',
            'type' => $payload['type'],
            'amount' => $payload['amount'],
            'reference' => $payload['reference'] ?? null,
            'reason' => $payload['reason'],
        ]);
    }

    private function confirmStudyWaiver(User $actor, StudentFeeManager $fee, array $payload): void
    {
        $currentSession = DB::table('schools')->where('id', $fee->school_id)->value('running_session');
        if (! $currentSession || (int) $currentSession !== (int) $fee->session_id) {
            throw ValidationException::withMessages(['study_waiver' => 'The 20% study waiver can only be confirmed for the institution’s current fee session.']);
        }

        try {
            app(CourseRegistrationFeeWaiverService::class)->grantForStudent(
                $actor,
                (int) $fee->student_id,
                (int) $fee->school_id,
                $payload['reason']
            );
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['study_waiver' => $exception->getMessage()]);
        }
    }

    public function projectedBalance(StudentFeeManager $fee, ?StudentFinancialSetupReview $review): int
    {
        $total = DecimalAmount::minorUnits($fee->total_amount) ?? 0;
        $paid = DecimalAmount::minorUnits($fee->paid_amount) ?? 0;
        if (! $review) {
            return max(0, $total - $paid);
        }

        if ($review->award_status === 'pending' && is_array($review->award)) {
            $total -= DecimalAmount::minorUnits($review->award['amount'] ?? '0') ?? 0;
        }
        if ($review->prior_payment_status === 'pending' && is_array($review->prior_payment)) {
            $paid += DecimalAmount::minorUnits($review->prior_payment['amount'] ?? '0') ?? 0;
        }

        return max(0, $total - $paid);
    }
}
