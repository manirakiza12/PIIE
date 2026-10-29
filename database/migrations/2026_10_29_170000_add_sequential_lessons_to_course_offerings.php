<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->boolean('sequential_lessons')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('course_offerings', fn (Blueprint $table) => $table->dropColumn('sequential_lessons'));
    }
};
