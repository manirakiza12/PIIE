<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_units', function (Blueprint $table): void {
            $table->string('code', 50)->nullable()->after('name');
            $table->string('head_title', 100)->nullable()->after('code');
            $table->string('head_name', 255)->nullable()->after('head_title');
            $table->unique(['school_id', 'type', 'code'], 'academic_units_school_type_code_unique');
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->string('code', 50)->nullable()->after('name');
            $table->string('hod_name', 255)->nullable()->after('code');
            $table->unique(['school_id', 'code'], 'departments_school_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropUnique('departments_school_code_unique');
            $table->dropColumn(['code', 'hod_name']);
        });

        Schema::table('academic_units', function (Blueprint $table): void {
            $table->dropUnique('academic_units_school_type_code_unique');
            $table->dropColumn(['code', 'head_title', 'head_name']);
        });
    }
};
