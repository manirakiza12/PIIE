<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['programme_cohorts', 'created_by'],
            ['student_curriculum_assignments', 'assigned_by'],
            ['programme_cohort_memberships', 'assigned_by'],
        ] as [$tableName, $columnName]) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, $columnName)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->unsignedBigInteger($columnName)->nullable()->change());
            }
        }
    }

    public function down(): void
    {
        foreach ([
            ['programme_cohorts', 'created_by'],
            ['student_curriculum_assignments', 'assigned_by'],
            ['programme_cohort_memberships', 'assigned_by'],
        ] as [$tableName, $columnName]) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $columnName)) {
                continue;
            }
            if (DB::table($tableName)->whereNull($columnName)->exists()) {
                throw new RuntimeException("Resolve detached actor references in {$tableName}.{$columnName} before rolling back.");
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->unsignedBigInteger($columnName)->nullable(false)->change());
        }
    }
};
