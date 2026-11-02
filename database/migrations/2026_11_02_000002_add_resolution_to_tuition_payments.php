<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tuition_payments', function (Blueprint $table) {
            $table->timestamp('resolved_at')->nullable()->after('paid_at');
            $table->unsignedBigInteger('resolution_id')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('tuition_payments', function (Blueprint $table) {
            $table->dropColumn(['resolved_at', 'resolution_id']);
        });
    }
};