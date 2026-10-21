<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_fee_awards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('fee_id');
            $table->string('type', 20);
            $table->decimal('amount', 18, 2);
            $table->string('reference', 150)->nullable();
            $table->string('reason', 500);
            $table->unsignedBigInteger('approved_by');
            $table->string('approved_by_name');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'fee_id']);
        });
    }

    public function down(): void
    {
        // Restoring balances requires an explicit finance reconciliation first.
        if (\Illuminate\Support\Facades\DB::table('student_fee_awards')->whereNull('revoked_at')->exists()) {
            throw new RuntimeException('Revoke active fee awards before rolling back this migration.');
        }
        Schema::dropIfExists('student_fee_awards');
    }
};
