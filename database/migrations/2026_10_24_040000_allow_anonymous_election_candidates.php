<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('election_candidates') && Schema::hasColumn('election_candidates', 'student_id')) {
            Schema::table('election_candidates', fn (Blueprint $table) => $table->unsignedBigInteger('student_id')->nullable()->change());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('election_candidates') || ! Schema::hasColumn('election_candidates', 'student_id')) {
            return;
        }

        if (DB::table('election_candidates')->whereNull('student_id')->exists()) {
            throw new RuntimeException('Resolve anonymous election candidates before rolling back this migration.');
        }

        Schema::table('election_candidates', fn (Blueprint $table) => $table->unsignedBigInteger('student_id')->nullable(false)->change());
    }
};
