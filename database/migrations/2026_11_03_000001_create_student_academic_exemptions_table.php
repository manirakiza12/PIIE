<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['schools', 'users', 'programmes', 'curricula'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Cannot create Student Academic Exemptions: required table {$table} is missing.");
            }
        }

        Schema::create('student_academic_exemptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('programme_id');
            $table->unsignedBigInteger('curriculum_id');
            $table->string('exemption_type', 20); // level | course_unit
            $table->unsignedBigInteger('target_curriculum_stage_id')->nullable(); // for level exemption
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $table->string('previous_institution', 255)->nullable();
            $table->string('previous_qualification', 255)->nullable();
            $table->text('previous_results')->nullable();
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable(); // supporting document references
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'id'], 'sae_school_id_id_uq');
            $table->index(['school_id', 'student_id', 'status'], 'sae_student_status_idx');
            $table->index(['school_id', 'programme_id', 'curriculum_id'], 'sae_programme_curriculum_idx');
            $table->index(['school_id', 'exemption_type', 'status'], 'sae_type_status_idx');

            $table->foreign('school_id', 'sae_school_fk')
                ->references('id')->on('schools')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign(['school_id', 'student_id'], 'sae_student_tenant_fk')
                ->references(['school_id', 'id'])->on('users')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign(['school_id', 'programme_id'], 'sae_programme_tenant_fk')
                ->references(['school_id', 'id'])->on('programmes')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign(['school_id', 'programme_id', 'curriculum_id'], 'sae_curriculum_programme_fk')
                ->references(['school_id', 'programme_id', 'id'])->on('curricula')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign('requested_by', 'sae_requester_fk')
                ->references('id')->on('users')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign('decided_by', 'sae_decider_fk')
                ->references('id')->on('users')->onDelete('set null')->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_academic_exemptions');
    }
};
