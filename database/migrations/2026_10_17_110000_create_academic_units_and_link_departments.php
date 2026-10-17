<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('parent_id')->nullable()->constrained('academic_units')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['school_id', 'type', 'name'], 'academic_units_school_type_name_unique');
            $table->index(['school_id', 'type', 'parent_id'], 'academic_units_school_type_parent_index');
        });

        Schema::table('departments', function (Blueprint $table): void {
            $table->foreignId('academic_unit_id')->nullable()->after('school_id')
                ->constrained('academic_units')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('academic_unit_id');
        });

        Schema::dropIfExists('academic_units');
    }
};
