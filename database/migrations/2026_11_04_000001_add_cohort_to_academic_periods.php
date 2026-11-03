<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('academic_periods')) {
            throw new RuntimeException('Cannot alter Academic Periods: table is missing.');
        }
        if (! Schema::hasTable('programme_cohorts')) {
            throw new RuntimeException('Cannot alter Academic Periods: programme_cohorts table is missing.');
        }

        Schema::table('academic_periods', function (Blueprint $table): void {
            if (! Schema::hasColumn('academic_periods', 'programme_cohort_id')) {
                $table->unsignedBigInteger('programme_cohort_id')->nullable()->after('academic_year_id');
                $table->index(['school_id', 'programme_cohort_id'], 'ap_cohort_idx');
                $table->foreign('programme_cohort_id', 'ap_cohort_fk')
                    ->references('id')->on('programme_cohorts')->onDelete('set null')->onUpdate('restrict');
            }
        });
    }

    public function down(): void
    {
        Schema::table('academic_periods', function (Blueprint $table): void {
            if (Schema::hasColumn('academic_periods', 'programme_cohort_id')) {
                $table->dropForeign('ap_cohort_fk');
                $table->dropIndex('ap_cohort_idx');
                $table->dropColumn('programme_cohort_id');
            }
        });
    }
};
