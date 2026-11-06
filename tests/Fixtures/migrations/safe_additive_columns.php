<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('payroll', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll', 'paid_amount')) {
                $table->decimal('paid_amount', 15, 2)->default(0);
            }
        });

        if (! Schema::hasTable('payroll_settings')) {
            Schema::create('payroll_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->unique();
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('payroll_settings');
    }
};
