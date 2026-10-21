<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('next_of_kin_relationship', 100)->nullable();
            $table->string('next_of_kin_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn(['next_of_kin_relationship', 'next_of_kin_email']);
        });
    }
};
