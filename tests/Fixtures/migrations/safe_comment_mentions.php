<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // This comment says DELETE FROM payroll and ->delete() but comments are
        // stripped before matching, so the scanner must not flag this file.
        Schema::table('payroll', function (Blueprint $table) {
            $table->string('payment_reference', 100)->nullable();
        });
    }

    public function down()
    {
        Schema::table('payroll', function (Blueprint $table) {
            $table->dropColumn('payment_reference');
        });
    }
};
