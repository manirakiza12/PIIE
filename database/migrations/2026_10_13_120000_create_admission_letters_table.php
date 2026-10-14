<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admission_letters')) return;
        Schema::create('admission_letters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('admission_id')->unique();
            $table->unsignedBigInteger('student_id')->index();
            $table->longText('snapshot');
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('admission_letters'); }
};
