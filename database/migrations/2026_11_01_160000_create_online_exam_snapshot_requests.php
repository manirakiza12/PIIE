<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_exam_snapshot_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('submission_id')->index();
            $table->unsignedBigInteger('requested_by')->index();
            $table->enum('status', ['pending', 'completed', 'failed', 'expired'])->default('pending')->index();
            $table->dateTime('requested_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('submission_id')
                ->references('id')->on('online_exam_submissions')
                ->onDelete('cascade');
            $table->foreign('requested_by')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_exam_snapshot_requests');
    }
};