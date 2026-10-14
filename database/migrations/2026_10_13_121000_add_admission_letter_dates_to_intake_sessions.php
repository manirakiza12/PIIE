<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['start_date', 'orientation_start_date', 'orientation_end_date', 'registration_deadline'] as $column) {
            if (! Schema::hasColumn('intake_sessions', $column)) {
                Schema::table('intake_sessions', fn (Blueprint $table) => $table->date($column)->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (['start_date', 'orientation_start_date', 'orientation_end_date', 'registration_deadline'] as $column) {
            if (Schema::hasColumn('intake_sessions', $column)) Schema::table('intake_sessions', fn (Blueprint $table) => $table->dropColumn($column));
        }
    }
};
