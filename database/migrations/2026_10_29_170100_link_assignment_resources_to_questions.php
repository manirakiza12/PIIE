<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('assignment_resources', function (Blueprint $table) {
            // Historical assignment-wide resources remain unchanged.
            $table->unsignedBigInteger('assignment_question_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('assignment_resources', function (Blueprint $table) {
            $table->dropIndex(['assignment_question_id']);
            $table->dropColumn('assignment_question_id');
        });
    }
};
