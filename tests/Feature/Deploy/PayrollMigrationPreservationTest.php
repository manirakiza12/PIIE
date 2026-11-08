<?php

namespace Tests\Feature\Deploy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PayrollMigrationPreservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Explicit disposable database; never use a developer's .env connection.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (require base_path('database/migrations/2026_07_02_130010_create_payroll_graduation_tables.php'))->up();
    }

    public function test_duplicate_period_rows_cause_migration_to_fail_loudly(): void
    {
        foreach (['draft', 'draft', 'approved', 'paid'] as $status) {
            DB::table('payroll')->insert([
                'school_id' => 1, 'staff_id' => 1, 'pay_period' => '2026-11-01',
                'status' => $status, 'net_pay' => 12345,
            ]);
        }
        // Historical paid rows are reconciled (paid_amount backfilled) before the
        // uniqueness check, so reporting is corrected even when the index cannot
        // be created.
        $this->expectException(\RuntimeException::class);
        (require base_path('database/migrations/2026_11_05_000001_enhance_payroll_module_tables.php'))->up();
    }

    public function test_historical_paid_rows_are_backfilled_with_net_pay(): void
    {
        DB::table('payroll')->insert([
            'school_id' => 1, 'staff_id' => 1, 'pay_period' => '2026-11-01',
            'status' => 'paid', 'net_pay' => 12345,
        ]);
        DB::table('payroll')->insert([
            'school_id' => 1, 'staff_id' => 2, 'pay_period' => '2026-11-01',
            'status' => 'approved', 'net_pay' => 9999,
        ]);
        (require base_path('database/migrations/2026_11_05_000001_enhance_payroll_module_tables.php'))->up();
        $this->assertEquals(12345, (float) DB::table('payroll')->where('staff_id', 1)->value('paid_amount'));
        $this->assertEquals(0, (float) DB::table('payroll')->where('staff_id', 2)->value('paid_amount'));
    }

    public function test_duplicate_payment_references_cause_migration_to_fail(): void
    {
        DB::table('payroll')->insert(['school_id' => 1, 'staff_id' => 1, 'pay_period' => '2026-11-01', 'net_pay' => 12345]);
        // Pre-create payroll_payments with duplicate references to simulate legacy data.
        (require base_path('database/migrations/2026_11_05_000001_enhance_payroll_module_tables.php'))->up();
        // Drop the unique index if it was created, then insert duplicates.
        try { Schema::table('payroll_payments', fn ($t) => $t->dropUnique('payroll_payments_school_reference_unique')); } catch (\Throwable $e) {}
        DB::table('payroll_payments')->insert([
            'school_id' => 1, 'payroll_id' => 1, 'amount' => 100, 'payment_method' => 'cash',
            'payment_reference' => 'REF-1', 'paid_on' => '2026-11-02', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payroll_payments')->insert([
            'school_id' => 1, 'payroll_id' => 1, 'amount' => 200, 'payment_method' => 'cash',
            'payment_reference' => 'REF-1', 'paid_on' => '2026-11-03', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(\RuntimeException::class);
        (require base_path('database/migrations/2026_11_05_000001_enhance_payroll_module_tables.php'))->up();
    }

    public function test_clean_payment_references_gain_unique_index(): void
    {
        DB::table('payroll')->insert(['school_id' => 1, 'staff_id' => 1, 'pay_period' => '2026-11-01', 'net_pay' => 12345]);
        (require base_path('database/migrations/2026_11_05_000001_enhance_payroll_module_tables.php'))->up();
        DB::table('payroll_payments')->insert([
            'school_id' => 1, 'payroll_id' => 1, 'amount' => 100, 'payment_method' => 'cash',
            'payment_reference' => 'REF-1', 'paid_on' => '2026-11-02', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (require base_path('database/migrations/2026_11_05_000001_enhance_payroll_module_tables.php'))->up();
        $this->assertTrue(collect(Schema::getIndexes('payroll_payments'))->contains(
            fn ($i) => ($i['name'] ?? null) === 'payroll_payments_school_reference_unique' && ($i['unique'] ?? false)
        ));
    }
}
