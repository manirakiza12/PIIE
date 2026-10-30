<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('course_offering_lessons', function (Blueprint $table) {
            // Nullable for historical records. No content/status is rewritten.
            $table->unsignedBigInteger('assignment_id')->nullable()->unique('lesson_assignment_unique');
            $table->string('creation_key', 64)->nullable();
            $table->char('creation_fingerprint', 64)->nullable();
            $table->unique(['school_id', 'created_by', 'creation_key'], 'lesson_creation_unique');
        });
    }

    public function down(): void
    {
        Schema::table('course_offering_lessons', function (Blueprint $table) {
            $table->dropUnique('lesson_assignment_unique');
            $table->dropUnique('lesson_creation_unique');
            $table->dropColumn(['assignment_id', 'creation_key', 'creation_fingerprint']);
        });
    }
};
