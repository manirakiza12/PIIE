<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tuition_payment_tombstones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id')->index();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('fee_id')->nullable();
            $table->string('reference', 191)->nullable();
            $table->string('gateway_txn_id', 191)->nullable()->index();
            $table->string('currency', 3)->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('status', 30)->nullable();
            $table->string('environment', 20)->nullable();
            $table->string('provider_classification', 30)->nullable();
            $table->json('payment_snapshot'); // immutable full copy of the deleted payment
            $table->string('deletion_reason', 500);
            $table->string('backup_reference', 191)->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('deleted_by_name', 191)->nullable();
            $table->timestamp('deleted_at');
            $table->timestamps();

            $table->unique('payment_id'); // idempotency: one tombstone per payment
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tuition_payment_tombstones');
    }
};
