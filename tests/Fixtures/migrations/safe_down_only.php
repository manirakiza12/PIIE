<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('payroll', function (Blueprint $table) {
            $table->string('currency', 10)->default('UGX');
        });
    }

    public function down()
    {
        // Destructive operations are allowed in down(): they reverse up().
        Schema::dropIfExists('payroll_settings');

        Schema::table('payroll', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
