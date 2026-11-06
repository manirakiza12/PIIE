<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::statement('DELETE FROM payroll WHERE status = "draft"');
    }

    public function down()
    {
        // Nothing to reverse.
    }
};
