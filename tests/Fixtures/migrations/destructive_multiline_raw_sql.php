<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Raw multi-line SQL, the same statement shape as a schema cleanup.
        DB::statement('
            ALTER TABLE payroll
            DROP COLUMN legacy_amount
        ');
    }

    public function down()
    {
        DB::statement('
            ALTER TABLE payroll
            ADD COLUMN legacy_amount DECIMAL(15, 2) NULL
        ');
    }
};
