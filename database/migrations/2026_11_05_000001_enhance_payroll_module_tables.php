<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll Module upgrade (professionalisation phase).
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up()
    {
        // ── Pre-flight validations (run BEFORE any schema change) ────────
        // Fail loudly rather than partially migrating. These queries only read
        // existing tables and never modify data.

        // 1. Payroll period uniqueness.
        if (Schema::hasTable('payroll')) {
            $duplicateGroups = DB::table('payroll')
                ->selectRaw('school_id, staff_id, pay_period, COUNT(*) as duplicate_count')
                ->groupBy('school_id', 'staff_id', 'pay_period')
                ->having('duplicate_count', '>', 1)
                ->get();

            if (!$duplicateGroups->isEmpty()) {
                throw new \RuntimeException(sprintf(
                    "Payroll period uniqueness cannot be enforced: %d group(s) contain duplicate rows. "
                    ."No payroll data was changed. Reconcile with docs/phase0/audit-payroll-duplicates.sql, "
                    ."then re-run this migration.",
                    $duplicateGroups->count()
                ));
            }
        }

        // 2. Payment reference uniqueness (institution-scoped).
        if (Schema::hasTable('payroll_payments')) {
            $dupRefs = DB::table('payroll_payments')
                ->selectRaw('school_id, payment_reference, COUNT(*) as duplicate_count')
                ->whereNotNull('payment_reference')
                ->where('payment_reference', '<>', '')
                ->groupBy('school_id', 'payment_reference')
                ->having('duplicate_count', '>', 1)
                ->get();

            if (!$dupRefs->isEmpty()) {
                throw new \RuntimeException(sprintf(
                    "Payroll payment reference uniqueness cannot be enforced: %d group(s) contain duplicate references. "
                    ."No payment data was changed. Reconcile duplicate references, then re-run this migration.",
                    $dupRefs->count()
                ));
            }
        }

        Schema::table('payroll', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll', 'other_earnings')) {
                $table->decimal('other_earnings', 15, 2)->default(0)->after('allowances');
            }
            if (!Schema::hasColumn('payroll', 'employer_nssf')) {
                $table->decimal('employer_nssf', 15, 2)->default(0)->after('nssf');
            }
            if (!Schema::hasColumn('payroll', 'currency')) {
                $table->string('currency', 10)->default('UGX')->after('bank_account');
            }
            if (!Schema::hasColumn('payroll', 'paid_amount')) {
                $table->decimal('paid_amount', 15, 2)->default(0)->after('currency');
            }
            if (!Schema::hasColumn('payroll', 'payment_reference')) {
                $table->string('payment_reference', 100)->nullable()->after('paid_amount');
            }
            if (!Schema::hasColumn('payroll', 'approved_at')) {
                $table->dateTime('approved_at')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('payroll', 'rejected_at')) {
                $table->dateTime('rejected_at')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('payroll', 'rejection_reason')) {
                $table->string('rejection_reason', 255)->nullable()->after('rejected_at');
            }
            if (!Schema::hasColumn('payroll', 'paid_by')) {
                $table->unsignedBigInteger('paid_by')->nullable()->after('paid_at');
            }
            if (!Schema::hasColumn('payroll', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('paid_by');
            }
        });

        // One-time, idempotent reconciliation for rows that were paid before
        // this module existed. Prefer the sum of recorded payroll_payments when
        // payment history exists; otherwise fall back to net_pay for paid rows.
        // Only touches paid rows whose paid_amount is still 0, so it never
        // overwrites a recorded amount. Safe to re-run.
        if (Schema::hasColumn('payroll', 'paid_amount')) {
            if (Schema::hasTable('payroll_payments')) {
                DB::statement("
                    UPDATE payroll
                    SET paid_amount = COALESCE((
                        SELECT SUM(pp.amount)
                        FROM payroll_payments pp
                        WHERE pp.payroll_id = payroll.id
                    ), 0)
                    WHERE status = 'paid'
                      AND paid_amount = 0
                      AND EXISTS (SELECT 1 FROM payroll_payments pp WHERE pp.payroll_id = payroll.id)
                ");
            }
            DB::table('payroll')
                ->where('status', 'paid')
                ->where('paid_amount', 0)
                ->update(['paid_amount' => DB::raw('net_pay')]);
        }

        // Create the period unique index (validations already passed).
        if (Schema::hasTable('payroll') && !$this->hasUniquePayrollPeriod()) {
            Schema::table('payroll', function (Blueprint $table) {
                $table->unique(
                    ['school_id', 'staff_id', 'pay_period'],
                    'payroll_school_staff_period_unique'
                );
            });
        }

        Schema::table('salary_structures', function (Blueprint $table) {
            if (!Schema::hasColumn('salary_structures', 'other_earnings')) {
                $table->decimal('other_earnings', 15, 2)->default(0)->after('medical');
            }
            if (!Schema::hasColumn('salary_structures', 'payment_method')) {
                $table->string('payment_method', 20)->default('bank')->after('other_earnings');
            }
            if (!Schema::hasColumn('salary_structures', 'bank_account')) {
                $table->string('bank_account', 100)->nullable()->after('payment_method');
            }
        });

        if (!Schema::hasTable('payroll_payments')) {
            Schema::create('payroll_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->index();
                $table->unsignedBigInteger('payroll_id')->index();
                $table->decimal('amount', 15, 2);
                $table->string('payment_method', 20);
                $table->string('payment_reference', 100)->nullable();
                $table->date('paid_on');
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->foreign('payroll_id')->references('id')->on('payroll')->onDelete('cascade');
            });
        }

        // Create the payment reference unique index (validations already passed).
        if (Schema::hasTable('payroll_payments') && !$this->hasPayrollPaymentReferenceUnique()) {
            Schema::table('payroll_payments', function (Blueprint $table) {
                $table->unique(
                    ['school_id', 'payment_reference'],
                    'payroll_payments_school_reference_unique'
                );
            });
        }

        if (!Schema::hasTable('payroll_settings')) {
            Schema::create('payroll_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->unique();
                $table->json('paye_bands')->nullable();
                $table->decimal('nssf_employee_rate', 6, 4)->nullable();
                $table->decimal('nssf_employer_rate', 6, 4)->nullable();
                $table->decimal('paye_surcharge_threshold', 15, 2)->nullable();
                $table->decimal('paye_surcharge_rate', 6, 4)->nullable();
                $table->string('notes', 500)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('payroll_settings');
        Schema::dropIfExists('payroll_payments');

        Schema::table('salary_structures', function (Blueprint $table) {
            foreach (['other_earnings', 'payment_method', 'bank_account'] as $col) {
                if (Schema::hasColumn('salary_structures', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('payroll', function (Blueprint $table) {
            if ($this->hasUniquePayrollPeriod()) {
                $table->dropUnique('payroll_school_staff_period_unique');
            }
        });

        Schema::table('payroll', function (Blueprint $table) {
            foreach ([
                'other_earnings', 'employer_nssf', 'currency', 'paid_amount',
                'payment_reference', 'approved_at', 'rejected_at',
                'rejection_reason', 'paid_by', 'created_by',
            ] as $col) {
                if (Schema::hasColumn('payroll', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    protected function hasPayrollPaymentReferenceUnique(): bool
    {
        if (!Schema::hasTable('payroll_payments')) {
            return false;
        }

        foreach (Schema::getIndexes('payroll_payments') as $index) {
            if (($index['name'] ?? null) === 'payroll_payments_school_reference_unique') {
                return (bool) ($index['unique'] ?? false);
            }
        }

        return false;
    }

    protected function hasUniquePayrollPeriod(): bool
    {
        $indexes = Schema::getIndexes('payroll');

        foreach ($indexes as $index) {
            $columns = array_map('strtolower', $index['columns'] ?? []);
            if (count($columns) === 3
                && in_array('school_id', $columns, true)
                && in_array('staff_id', $columns, true)
                && in_array('pay_period', $columns, true)
                && ($index['unique'] ?? false)) {
                return true;
            }
        }

        return false;
    }
};
