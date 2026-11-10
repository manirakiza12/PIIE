<?php

namespace App\Support\Payroll;

use App\Models\AuditLog;
use App\Models\Payroll;
use App\Models\PayrollPayment;
use App\Models\PayrollSetting;
use App\Models\SalaryStructure;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Payroll orchestration: preview → generate → approve/reject → pay.
 *
 * Responsibilities (and why they live here, not in the controller):
 *   - Tenant + eligibility enforcement: every query is scoped to one
 *     school, and employees enter only through PayrollEligibility.
 *   - Idempotent generation: one payslip per employee per period, backed
 *     by the (school_id, staff_id, pay_period) unique index — the original
 *     exists()-only race window is gone. Rejected drafts may be
 *     regenerated (that is the authorised revision workflow: they were
 *     never approved or paid), approved/paid rows never are.
 *   - Separate life-cycle stages: generating is NOT paying. Money touches
 *     the school's finances only inside recordPayment(), which
 *     atomically (one DB transaction, row locked):
 *       1. validates the payment against remaining balance (duplicate /
 *          overpayment protection),
 *       2. writes an immutable payroll_payments row,
 *       3. updates the payslip status/paid_amount,
 *       4. posts the salary outflow as a school EXPENSE in the existing
 *          expenses module (category "Payroll"),
 *       5. writes the audit trail.
 *     Student fee tables are never touched by payroll at any stage, so a
 *     teacher's salary can never reduce a student's outstanding balance.
 *   - Employer cost (NSSF employer share) is stored separately
 *     (payroll.employer_nssf) from employee deductions (nssf/tax), as the
 *     accounting rule requires.
 */
final class PayrollService
{
    public const PAYMENT_METHODS = ['cash', 'bank', 'mobile_money'];

    /** Existing expense category that payroll payments post into. */
    public const EXPENSE_CATEGORY = 'Payroll';

    /**
     * Canonical period value for `payroll.pay_period` (a DATE column).
     * A Y-m string is turned into an explicit "Y-m-d 00:00:00" datetime
     * string: MySQL compares it to the DATE column cleanly (midnight is
     * exact), and the model's date cast writes the same textual form, so
     * equality queries behave identically on MySQL and SQLite (tests).
     */
    public static function periodValue(string $period): string
    {
        return \Carbon\Carbon::parse($period)->startOfDay()->format('Y-m-d H:i:s');
    }

    // ── Configuration ──────────────────────────────────────────────────

    /** @return array{paye_bands: array|null, nssf_employee_rate: float|null, nssf_employer_rate: float|null, paye_surcharge_threshold: float|null, paye_surcharge_rate: float|null} */
    public function settingsFor(int $schoolId): array
    {
        $row = PayrollSetting::where('school_id', $schoolId)->first();

        return [
            'paye_bands' => $row?->paye_bands,
            'nssf_employee_rate' => $row?->nssf_employee_rate,
            'nssf_employer_rate' => $row?->nssf_employer_rate,
            'paye_surcharge_threshold' => $row?->paye_surcharge_threshold,
            'paye_surcharge_rate' => $row?->paye_surcharge_rate,
        ];
    }

    // ── Preview (read-only — no state change) ────────────────────────────

    public function preview(int $schoolId, string $period, ?int $departmentId = null): array
    {
        $settings = $this->settingsFor($schoolId);
        $existing = Payroll::where('school_id', $schoolId)
            ->where('pay_period', self::periodValue($period))
            ->get()
            ->keyBy('staff_id');

        $rows = [];
        $totals = ['new_net' => 0.0, 'new_employer' => 0.0];

        foreach (PayrollEligibility::activeStaff($schoolId, $departmentId)->get() as $staff) {
            /** @var User $staff */
            $structure = $this->structureFor($staff->id, $schoolId, $period);
            $existingRow = $existing->get($staff->id);
            $row = [
                'staff' => $staff,
                'department' => $staff->department?->name,
                'designation' => $staff->designationRecord?->name ?: $staff->designation,
                'structure' => $structure,
                'existing' => $existingRow,
                'computed' => null,
            ];

            if ($structure && !$existingRow) {
                $computed = UgandaPayrollCalculator::compute($this->earnings($structure), $settings);
                $row['computed'] = $computed;
                $totals['new_net'] = round($totals['new_net'] + $computed['net'], 2);
                $totals['new_employer'] = round($totals['new_employer'] + $computed['employer_nssf'], 2);
            }

            $rows[] = $row;
        }

        return [
            'period' => $period,
            'rows' => $rows,
            'totals' => $totals,
            'missing_structure_count' => count(array_filter($rows, fn ($r) => !$r['structure'] && !$r['existing'])),
            'existing_count' => count(array_filter($rows, fn ($r) => $r['existing'])),
        ];
    }

    // ── Generation ───────────────────────────────────────────────────────

    /**
     * @return array{generated: int, skipped_existing: int, skipped_no_structure: array<int,string>, total_net: float, total_employer: float}
     */
    public function generate(int $schoolId, int $actorId, string $period, ?int $departmentId = null): array
    {
        $settings = $this->settingsFor($schoolId);
        $summary = [
            'generated' => 0,
            'skipped_existing' => 0,
            'skipped_no_structure' => [],
            'total_net' => 0.0,
            'total_employer' => 0.0,
        ];

        DB::transaction(function () use ($schoolId, $actorId, $period, $departmentId, $settings, &$summary) {
            // Authorised revision flow: rejected drafts for this period were
            // never approved or paid, so regenerating replaces them.
            $rejectedCount = Payroll::where('school_id', $schoolId)
                ->where('pay_period', self::periodValue($period))
                ->where('status', 'draft')
                ->whereNotNull('rejected_at')
                ->delete();

            foreach (PayrollEligibility::activeStaff($schoolId, $departmentId)->get() as $staff) {
                /** @var User $staff */
                $exists = Payroll::where('school_id', $schoolId)
                    ->where('staff_id', $staff->id)
                    ->where('pay_period', self::periodValue($period))
                    ->exists();
                if ($exists) {
                    $summary['skipped_existing']++;
                    continue;
                }

                $structure = $this->structureFor($staff->id, $schoolId, $period);
                if (!$structure) {
                    $summary['skipped_no_structure'][] = $staff->name;
                    continue;
                }

                $computed = UgandaPayrollCalculator::compute($this->earnings($structure), $settings);

                Payroll::create([
                    'school_id' => $schoolId,
                    'staff_id' => $staff->id,
                    'pay_period' => $period,
                    'basic_salary' => $computed['basic'],
                    'allowances' => $computed['allowances'],
                    'other_earnings' => $computed['other_earnings'],
                    'deductions' => 0, // discretionary deductions: none generated
                    'tax' => $computed['paye'],
                    'nssf' => $computed['nssf_employee'],
                    'employer_nssf' => $computed['employer_nssf'],
                    'net_pay' => $computed['net'],
                    'payment_method' => $structure->payment_method ?: 'bank',
                    'bank_account' => $structure->bank_account,
                    'currency' => 'UGX',
                    'paid_amount' => 0,
                    'status' => 'draft',
                    'created_by' => $actorId,
                ]);

                $summary['generated']++;
                $summary['total_net'] = round($summary['total_net'] + $computed['net'], 2);
                $summary['total_employer'] = round($summary['total_employer'] + $computed['employer_nssf'], 2);
            }

            AuditLog::record(
                'generate',
                'Payroll',
                sprintf(
                    'Generated payroll for %s for %s (%d previously rejected drafts replaced%s).',
                    $period, $summary['generated'],
                    $rejectedCount,
                    $summary['skipped_no_structure'] !== [] ? '; no salary structure: '.count($summary['skipped_no_structure']) : ''
                ),
                ['event_type' => 'GENERATE'],
            );
        });

        return $summary;
    }

    // ── Approval workflow ───────────────────────────────────────────────

    public function approve(int $schoolId, int $actorId, int $payrollId): Payroll
    {
        return DB::transaction(function () use ($schoolId, $actorId, $payrollId) {
            /** @var Payroll $pay */
            $pay = Payroll::where('school_id', $schoolId)->whereKey($payrollId)->lockForUpdate()->firstOrFail();

            $this->guardUnpaid($pay, 'approved');
            if ($pay->status !== 'draft' || $pay->rejected_at !== null) {
                abort(422, 'Only a pending draft can be approved.');
            }

            $old = ['status' => $pay->status];
            $pay->forceFill([
                'status' => 'approved',
                'approved_by' => $actorId,
                'approved_at' => now(),
                'rejected_at' => null,
                'rejection_reason' => null,
            ])->save();

            AuditLog::record('approve', 'Payroll', "Approved payslip #{$pay->id} ({$pay->pay_period}).", [
                'record_type' => 'Payroll',
                'record_id' => $pay->id,
                'old_values' => $old,
                'new_values' => ['status' => 'approved'],
            ]);

            return $pay->fresh();
        });
    }

    public function reject(int $schoolId, int $actorId, int $payrollId, string $reason): Payroll
    {
        return DB::transaction(function () use ($schoolId, $actorId, $payrollId, $reason) {
            /** @var Payroll $pay */
            $pay = Payroll::where('school_id', $schoolId)->whereKey($payrollId)->lockForUpdate()->firstOrFail();

            $this->guardUnpaid($pay, 'rejected');
            if ($pay->status !== 'draft') {
                abort(422, 'Only a pending draft can be rejected.');
            }

            $pay->forceFill([
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            AuditLog::record('reject', 'Payroll', "Rejected payslip #{$pay->id}: {$reason}", [
                'record_type' => 'Payroll',
                'record_id' => $pay->id,
                'new_values' => ['status' => 'draft (rejected)'],
            ]);

            return $pay->fresh();
        });
    }

    public function deleteDraft(int $schoolId, int $payrollId): void
    {
        DB::transaction(function () use ($schoolId, $payrollId) {
            /** @var Payroll $pay */
            $pay = Payroll::where('school_id', $schoolId)->whereKey($payrollId)->lockForUpdate()->firstOrFail();

            if ($pay->status !== 'draft') {
                abort(422, 'Only draft payslips can be deleted.');
            }
            if ((float) $pay->paid_amount > 0) {
                abort(422, 'Payslips with recorded payments cannot be deleted.');
            }

            $pay->delete();

            AuditLog::record('delete', 'Payroll', "Deleted draft payslip #{$payrollId}.", [
                'record_type' => 'Payroll',
                'record_id' => $payrollId,
            ]);
        });
    }


    /**
     * Records an actual salary payment (full or partial). Salaries reach the
     * school's finances ONLY here: an expense row is posted into the existing
     * expenses module. Student fee invoices/payments are never touched.
     */
    public function recordPayment(int $schoolId, int $actorId, int $payrollId, float $amount, string $method, string $reference, ?string $paidOn): array
    {
        $paidOn = $paidOn ?: now()->toDateString();

        return DB::transaction(function () use ($schoolId, $actorId, $payrollId, $amount, $method, $reference, $paidOn) {
            /** @var Payroll $pay */
            $pay = Payroll::where('school_id', $schoolId)->whereKey($payrollId)->lockForUpdate()->firstOrFail();

            if ($pay->status !== 'approved') {
                abort(422, 'Only approved payslips can be paid.');
            }
            if (!in_array($method, self::PAYMENT_METHODS, true)) {
                abort(422, 'Unsupported payment method.');
            }
            if ($amount <= 0) {
                abort(422, 'Payment amount must be greater than zero.');
            }

            $remaining = round((float) $pay->net_pay - (float) $pay->paid_amount, 2);
            if ($amount > $remaining + 0.001) {
                abort(422, 'Payment exceeds the remaining net salary balance.');
            }

            PayrollPayment::create([
                'school_id' => $schoolId,
                'payroll_id' => $pay->id,
                'amount' => $amount,
                'payment_method' => $method,
                'payment_reference' => $reference,
                'paid_on' => $paidOn,
                'created_by' => $actorId,
            ]);

            $oldStatus = $pay->status;
            $pay->paid_amount = round((float) $pay->paid_amount + $amount, 2);
            $pay->payment_reference = $reference;

            $fullyPaid = number_format($pay->paid_amount, 2) === number_format($pay->net_pay, 2);
            if ($fullyPaid) {
                $pay->status = 'paid';
                $pay->paid_at = now();
                $pay->paid_by = $actorId;
            }
            $pay->save();

            $this->postExpense($schoolId, $amount, $paidOn, "Salary payment ref {$reference} — payslip #{$pay->id}");

            AuditLog::record($fullyPaid ? 'paid_full' : 'paid_partial', 'Payroll', sprintf(
                'Recorded %s salary payment of %.2f (ref %s) for payslip #%d.',
                $fullyPaid ? 'full' : 'partial', $amount, $reference, $pay->id
            ), [
                'record_type' => 'Payroll',
                'record_id' => $pay->id,
                'old_values' => ['status' => $oldStatus, 'paid_amount' => (float) $pay->paid_amount - $amount],
                'new_values' => ['status' => $pay->status, 'paid_amount' => (float) $pay->paid_amount],
            ]);

            return ['payroll' => $pay->fresh(), 'fully_paid' => $fullyPaid];
        });
    }

    public function paymentHistory(int $schoolId, int $payrollId)
    {
        return PayrollPayment::where('school_id', $schoolId)
            ->where('payroll_id', $payrollId)
            ->orderByDesc('id')
            ->get();
    }

    // ── Dashboard / reporting aggregates ────────────────────────────────

    /** @return array<string, float|int|array{0: float}|int> */
    public function summary(int $schoolId, ?string $period = null): array
    {
        $scope = fn () => Payroll::where('school_id', $schoolId)
            ->when($period !== null, fn ($q) => $q->where('pay_period', self::periodValue($period)));

        $payroll = $scope();
        $periodNet = (clone $payroll)->selectRaw('
            COALESCE(SUM(basic_salary), 0) as basic,
            COALESCE(SUM(allowances), 0) as allowances,
            COALESCE(SUM(other_earnings), 0) as other_earnings,
            COALESCE(SUM(basic_salary + allowances + other_earnings), 0) as gross,
            COALESCE(SUM(nssf + tax + deductions), 0) as employee_deductions,
            COALESCE(SUM(employer_nssf), 0) as employer_nssf,
            COALESCE(SUM(net_pay), 0) as net
        ')->first();

        return [
            'staff_count' => PayrollEligibility::activeStaff($schoolId)->count(),
            'teaching_count' => PayrollEligibility::activeStaff($schoolId)->where('role_id', 3)->count(),
            'non_teaching_count' => PayrollEligibility::activeStaff($schoolId)->where('role_id', '!=', 3)->count(),
            'basic_total' => (float) ($periodNet->basic ?? 0),
            'allowances_total' => (float) ($periodNet->allowances ?? 0),
            'other_earnings_total' => (float) ($periodNet->other_earnings ?? 0),
            'gross_total' => (float) ($periodNet->gross ?? 0),
            'employee_deductions' => (float) ($periodNet->employee_deductions ?? 0),
            'employer_nssf' => (float) ($periodNet->employer_nssf ?? 0),
            'net_total' => (float) ($periodNet->net ?? 0),
            'record_count' => (clone $payroll)->count(),
            'draft_count' => (clone $payroll)->where('status', 'draft')->whereNull('rejected_at')->count(),
            'rejected_count' => (clone $payroll)->where('status', 'draft')->whereNotNull('rejected_at')->count(),
            'approved_count' => (clone $payroll)->where('status', 'approved')->count(),
            'partially_paid_count' => (clone $payroll)->where('status', 'approved')->where('paid_amount', '>', 0)->count(),
            'paid_count' => (clone $payroll)->where('status', 'paid')->count(),
            'paid_total' => (float) (clone $payroll)->sum('paid_amount'),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function structureFor(int $userId, int $schoolId, string $period): ?SalaryStructure
    {
        $periodEnd = \Carbon\Carbon::parse($period)->endOfMonth()->toDateString();

        return SalaryStructure::where('school_id', $schoolId)
            ->where('user_id', $userId)
            ->whereDate('effective_from', '<=', $periodEnd)
            ->orderByDesc('effective_from')
            ->first();
    }

    /** @return array{basic: float, housing: float, transport: float, medical: float, other: float} */
    private function earnings(SalaryStructure $structure): array
    {
        return [
            'basic' => (float) $structure->basic,
            'housing' => (float) $structure->housing,
            'transport' => (float) $structure->transport,
            'medical' => (float) $structure->medical,
            'other' => (float) $structure->other_earnings,
        ];
    }

    private function postExpense(int $schoolId, float $amount, string $paidOn, string $title): void
    {
        $categoryId = DB::table('expense_categories')
            ->where('school_id', $schoolId)
            ->where('name', self::EXPENSE_CATEGORY)
            ->value('id');

        if (!$categoryId) {
            $categoryId = DB::table('expense_categories')->insertGetId([
                'name' => self::EXPENSE_CATEGORY,
                'school_id' => $schoolId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $sessionId = get_school_settings($schoolId)->value('running_session');

        if (!$sessionId) {
            throw ValidationException::withMessages([
                'session_id' => 'No running session configured for this school. Set a running session in school settings before recording payroll payments.',
            ]);
        }

        DB::table('expenses')->insert([
            'expense_category_id' => $categoryId,
            'date' => strtotime($paidOn),
            'amount' => $amount,
            'school_id' => $schoolId,
            'session_id' => get_school_settings($schoolId)->value('running_session'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function guardUnpaid(Payroll $pay, string $action): void
    {
        if ((float) $pay->paid_amount > 0) {
            abort(422, "This payslip already has payments recorded and can no longer be {$action}.");
        }
        if ($pay->status === 'paid' || $pay->approved_by !== null) {
            abort(422, 'This payslip has been acted on already and is protected from modification.');
        }
    }
}
