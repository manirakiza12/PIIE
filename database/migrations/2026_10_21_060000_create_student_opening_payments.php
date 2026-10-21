<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_fee_managers', fn (Blueprint $table) => $table->decimal('opening_paid_amount', 18, 2)->default(0));
        Schema::create('student_opening_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('fee_id');
            $table->decimal('amount', 18, 2);
            $table->date('paid_on');
            $table->string('reference', 150);
            $table->string('reference_hash', 64);
            $table->string('reason', 500);
            $table->unsignedBigInteger('approved_by');
            $table->string('approved_by_name');
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->string('reversed_by_name')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'fee_id']);
            $table->unique(['school_id', 'reference_hash']);
        });
    }

    public function down(): void
    {
        if (DB::table('student_opening_payments')->whereNull('reversed_at')->exists()) {
            throw new RuntimeException('Reverse opening payments before removing their reconciliation records.');
        }
        Schema::dropIfExists('student_opening_payments');
        Schema::table('student_fee_managers', fn (Blueprint $table) => $table->dropColumn('opening_paid_amount'));
    }
};
