<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_fee_registration_waivers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('granted_by');
            $table->string('reason', 500);
            $table->timestamp('granted_at');
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'student_id', 'session_id'], 'fee_reg_waiver_student_session_unique');
            $table->index(['school_id', 'session_id', 'revoked_at'], 'fee_reg_waiver_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fee_registration_waivers');
    }
};
