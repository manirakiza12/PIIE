<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_applicability_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            // Compound tenant relationships are revalidated under locks by the domain service.
            foreach (['programme_id', 'intake_session_id', 'curriculum_id', 'entry_academic_year_id', 'entry_curriculum_stage_id'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->string('status', 20)->default('draft');
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->char('approval_fingerprint', 64)->nullable();
            $table->longText('approved_snapshot')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'programme_id', 'intake_session_id', 'status'], 'cam_context_idx');
            $table->unique(['school_id', 'id'], 'cam_tenant_id');
            $table->foreign('programme_id')->references('id')->on('programmes')->restrictOnDelete();
            $table->foreign('intake_session_id')->references('id')->on('intake_sessions')->restrictOnDelete();
            $table->foreign('curriculum_id')->references('id')->on('curricula')->restrictOnDelete();
            $table->foreign('entry_academic_year_id')->references('id')->on('academic_years')->restrictOnDelete();
            $table->foreign('entry_curriculum_stage_id')->references('id')->on('curriculum_stages')->restrictOnDelete();
        });
        Schema::create('curriculum_assignment_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->unsignedBigInteger('mapping_id');
            $table->foreignId('admission_id')->constrained('admissions')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->char('fingerprint', 64);
            $table->longText('snapshot');
            $table->text('ordinary_entry_evidence');
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamp('enrolment_acknowledged_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('placement_id')->nullable()->constrained('student_curriculum_assignments')->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['school_id', 'mapping_id'], 'cap_mapping_tenant_fk')->references(['school_id', 'id'])->on('curriculum_applicability_mappings')->restrictOnDelete();
            $table->unique(['school_id', 'admission_id', 'fingerprint'], 'cap_decision_unique');
            $table->unique('placement_id', 'cap_placement_unique');
            $table->index(['school_id', 'status'], 'cap_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_assignment_proposals');
        Schema::dropIfExists('curriculum_applicability_mappings');
    }
};
