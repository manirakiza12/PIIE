<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            // Explicit identity link; email alone must never authorize a transfer.
            $table->unsignedBigInteger('automation_student_id')->nullable()->index();
        });
        Schema::create('automation_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->string('workflow', 60);
            $table->unsignedBigInteger('subject_id');
            $table->string('code', 80);
            $table->text('reason');
            $table->string('owner', 100);
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unique(['school_id', 'workflow', 'subject_id'], 'automation_exception_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_exceptions');
        Schema::table('admissions', fn (Blueprint $table) => $table->dropColumn('automation_student_id'));
    }
};
