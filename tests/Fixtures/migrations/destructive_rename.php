<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('payroll', function (Blueprint $table) {
            $table
                ->string('legacy_reference', 50)
                ->nullable();

            $table->renameColumn('legacy_reference', 'payment_reference');
        });
    }

    public function down()
    {
        Schema::table('payroll', function (Blueprint $table) {
            $table->renameColumn('payment_reference', 'legacy_reference');
        });
    }
};
