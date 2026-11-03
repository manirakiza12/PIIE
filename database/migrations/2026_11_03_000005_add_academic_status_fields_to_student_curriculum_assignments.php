<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('student_curriculum_assignments')) {
            throw new RuntimeException('Cannot alter Student Curriculum assignments: table is missing.');
        }

        Schema::table('student_curriculum_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('student_curriculum_assignments', 'current_academic_level')) {
                $table->string('current_academic_level', 60)->nullable()->after('entry_curriculum_stage_id');
            }
            if (! Schema::hasColumn('student_curriculum_assignments', 'expected_graduation_date')) {
                $table->date('expected_graduation_date')->nullable()->after('current_academic_level');
            }
            if (! Schema::hasColumn('student_curriculum_assignments', 'graduation_eligibility_status')) {
                $table->string('graduation_eligibility_status', 30)->nullable()->after('expected_graduation_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_curriculum_assignments', function (Blueprint $table): void {
            foreach (['current_academic_level', 'expected_graduation_date', 'graduation_eligibility_status'] as $column) {
                if (Schema::hasColumn('student_curriculum_assignments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
