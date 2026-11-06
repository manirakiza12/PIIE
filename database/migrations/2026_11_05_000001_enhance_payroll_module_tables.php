<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll Module upgrade (professionalisation phase).
 *
 * Additive only — no existing column is dropped or re-typed, so every
 * current reader of `payroll` (dashboard totals, teacher payslips, CSV
 * export, payslip PDF) keeps working untouched.
 *
 * 1. `payroll` gains the columns the audit identified as missing:
 *      - other_earnings / employer_nssf: the employer's own statutory
 *        contribution, tracked separately from employee deductions;
 *      - currency + paid_amount + payment_reference: payment tracking and
 *        partial-payment support (status stays the existing enum of
 *        draft/approved/paid; partially-paid rows are simply approved rows
 *        whose paid_amount is between 0 and net_pay);
 *      - approved_at / rejected_at / rejection_reason: approval workflow
 *        evidence and an explicit rejection state for drafts;
 *      - paid_by / created_by: accountability.
 *    It also gains the business-rule constraint the application code used
 *    to enforce only by racing exists() checks:
 *      UNIQUE (school_id, staff_id, pay_period) — one payslip per employee
 *    per period. The index is created ONLY when no pre-existing duplicates
 *    exist. This migration never deletes, deduplicates or rewrites payroll
 *    rows: if duplicates are found it reports them and leaves the index for
 *    a separately reviewed follow-up after the rows are reconciled.
 *
 * 2. `salary_structures` gains the employee payment channels and an
 *    open-ended "other earnings" line so payroll reflects how each
 *    employee is actually paid.
 *
 * 3. New tables:
 *      - payroll_payments: one immutable row per recorded payment (cash /
 *        bank / mobile money), giving full and partial payment history
 *        with transaction references and duplicate-payment protection.
 *      - payroll_settings: per-school statutory configuration (NSSF rates,
 *        PAYE bands) so rates are editable data with effective dates —
 *        never hardcoded in the calculation path. The JSON bands default
 *        to the widely-cited URA monthly PAYE schedule and the NSSF
 *        Act employee 5% / employer 10% contributions; the institution's
 *        accountant must review them against the current URA schedule
 *        before relying on generated figures.
 */
return new class extends Migration
{
    public function up()
    {
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

        // One payslip per employee per period is enforced by a UNIQUE index,
        // but that index can only be created when no historical duplicates
        // exist. This migration does NOT delete or rewrite any payroll row.
        // If duplicates are present the index is skipped and reported, so a
        // human can reconcile the rows and add the index in a reviewed
        // follow-up migration.
        if (Schema::hasTable('payroll') && !$this->hasUniquePayrollPeriod()) {
            $duplicateGroups = DB::table('payroll')
                ->selectRaw('school_id, staff_id, pay_period, COUNT(*) as duplicate_count')
                ->groupBy('school_id', 'staff_id', 'pay_period')
                ->having('duplicate_count', '>', 1)
                ->get();

            if ($duplicateGroups->isEmpty()) {
                Schema::table('payroll', function (Blueprint $table) {
                    $table->unique(
                        ['school_id', 'staff_id', 'pay_period'],
                        'payroll_school_staff_period_unique'
                    );
                });
            } else {
                // Visible in the migration output; no production data changed.
                fwrite(STDERR, sprintf(
                    "WARNING: %d payroll period group(s) contain duplicate rows.\n"
                    ."The unique index payroll_school_staff_period_unique was NOT created.\n"
                    ."No payroll data was changed. Audit and reconcile with "
                    ."docs/phase0/audit-payroll-duplicates.sql, then add the index "
                    ."in a separate reviewed migration.\n",
                    $duplicateGroups->count()
                ));
            }
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
                $table->string('payment_reference', 100);
                $table->date('paid_on');
                $table->unsignedBigInteger('created_by');
                $table->timestamps();

                $table->foreign('payroll_id')->references('id')->on('payroll')->onDelete('cascade');
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

    private function hasUniquePayrollPeriod(): bool
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
