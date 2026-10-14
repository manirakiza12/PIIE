<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_email_preferences', function (Blueprint $table) {
            $table->unsignedBigInteger('school_id')->primary();
            $table->boolean('automatic')->default(false);
        });
        Schema::create('invoice_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('invoice_email_deliveries');
        Schema::dropIfExists('invoice_email_preferences');
    }
};
