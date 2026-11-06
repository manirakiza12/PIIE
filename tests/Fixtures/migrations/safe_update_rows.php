<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Data updates are mutations but not destructions; the gate only blocks
        // deletes/truncates/drops/renames and must not flag this.
        DB::table('payroll')
            ->whereNull('currency')
            ->update(['currency' => 'UGX']);
    }

    public function down()
    {
        // Not reversible.
    }
};
