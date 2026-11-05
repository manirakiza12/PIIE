<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tuition_payment_resolutions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id')->index();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('resolved_by');
            $table->string('resolved_by_name', 191);
            $table->string('reason', 500);
            $table->string('resolution_type', 30); // 'test_payment_archived', 'admin_review'
            $table->json('payment_snapshot'); // immutable copy of the payment record
            $table->json('verification_evidence')->nullable(); // provider status, tracking ID, etc.
            $table->timestamp('resolved_at');
            $table->timestamps();

            $table->foreign('payment_id')->references('id')->on('tuition_payments')->onDelete('restrict');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tuition_payment_resolutions');
    }
};