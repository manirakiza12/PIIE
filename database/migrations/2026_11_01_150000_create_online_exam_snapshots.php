<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_exam_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('submission_id')->index();
            $table->string('disk', 50)->default('local');
            $table->string('path', 500);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('capture_type', 30)->nullable(); // 'automatic', 'manual', 'invigilator_requested'
            $table->json('metadata')->nullable();
            $table->dateTime('captured_at')->index();
            $table->timestamps();

            $table->foreign('submission_id')
                ->references('id')->on('online_exam_submissions')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_exam_snapshots');
    }
};