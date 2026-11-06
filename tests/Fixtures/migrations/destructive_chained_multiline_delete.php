<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('payroll', function (Blueprint $table) {
            $table->decimal('paid_amount', 15, 2)->default(0);
        });

        if (Schema::hasTable('payroll')) {
            DB::table('payroll')
                ->selectRaw('MIN(id) as keep_id, school_id, staff_id, pay_period, COUNT(*) as c')
                ->groupBy('school_id', 'staff_id', 'pay_period')
                ->having('c', '>', 1)
                ->get()
                ->each(function ($group) {
                    DB::table('payroll')
                        ->where('school_id', $group->school_id)
                        ->where('staff_id', $group->staff_id)
                        ->where('pay_period', $group->pay_period)
                        ->where('id', '!=', $group->keep_id)
                        ->where('status', 'draft')
                        ->whereNull('approved_by')
                        ->delete();
                });
        }
    }

    public function down()
    {
        Schema::table('payroll', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
