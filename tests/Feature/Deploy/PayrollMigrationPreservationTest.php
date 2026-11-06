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

    public function test_duplicate_drafts_approved_and_paid_rows_are_preserved(): void
    {
        foreach (['draft', 'draft', 'approved', 'paid'] as $status) {
            DB::table('payroll')->insert([
                'school_id' => 1, 'staff_id' => 1, 'pay_period' => '2026-11-01',
                'status' => $status, 'net_pay' => 12345,
            ]);
        }
        $before = DB::table('payroll')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration = require base_path('database/migrations/2026_11_05_000001_enhance_payroll_module_tables.php');
        $migration->up();
        $migration->up(); // Retry after additive DDL must also preserve data.
        $after = DB::table('payroll')->select(array_keys($before[0]))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertSame($before, $after);
        $this->assertTrue(Schema::hasTable('payroll_payments'));
        $this->assertFalse(collect(Schema::getIndexes('payroll'))->contains('name', 'payroll_school_staff_period_unique'));
    }

    public function test_clean_existing_rows_gain_unique_period_index(): void
    {
        DB::table('payroll')->insert(['school_id' => 1, 'staff_id' => 1, 'pay_period' => '2026-11-01', 'net_pay' => 12345]);
        (require base_path('database/migrations/2026_11_05_000001_enhance_payroll_module_tables.php'))->up();
        $this->assertSame(1, DB::table('payroll')->count());
        $this->assertEquals(12345, DB::table('payroll')->value('net_pay'));
        $this->assertTrue(collect(Schema::getIndexes('payroll'))->contains(fn ($index) => $index['name'] === 'payroll_school_staff_period_unique' && $index['unique']));
    }
}
