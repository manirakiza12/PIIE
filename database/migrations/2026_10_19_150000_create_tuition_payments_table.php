<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tuition_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('fee_id')->index();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->string('method')->default('pesapal');
            $table->string('status')->default('pending');
            $table->string('reference')->unique();
            $table->string('gateway_txn_id')->nullable()->unique();
            $table->json('gateway_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('tuition_payments'); }
};
