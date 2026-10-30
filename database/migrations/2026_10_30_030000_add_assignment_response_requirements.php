<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            // NULL preserves the existing all-selected requirement; no backfill.
            $table->string('submission_requirement_mode', 8)->nullable();
            $table->string('optional_submission_kinds', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assignments', fn (Blueprint $table) => $table->dropColumn(['submission_requirement_mode', 'optional_submission_kinds']));
    }
};
