<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('student_opening_payments', 'method')) {
            Schema::table('student_opening_payments', fn (Blueprint $table) => $table->string('method', 40)->nullable()->after('paid_on'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('student_opening_payments', 'method')) {
            Schema::table('student_opening_payments', fn (Blueprint $table) => $table->dropColumn('method'));
        }
    }
};
