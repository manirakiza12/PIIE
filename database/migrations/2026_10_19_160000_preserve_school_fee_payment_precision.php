<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_fee_managers', function (Blueprint $table) {
            $table->decimal('total_amount', 18, 2)->change();
            $table->decimal('paid_amount', 18, 2)->change();
        });
    }

    public function down(): void
    {
        // Preserve recorded money on code rollback; narrowing to integers would lose cents.
    }
};
