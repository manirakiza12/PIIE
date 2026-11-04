<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_academic_exemption_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('exemption_id');
            $table->unsignedBigInteger('curriculum_id'); // for tenant-aware FK to curriculum_memberships
            $table->unsignedBigInteger('curriculum_membership_id')->nullable(); // course unit in curriculum
            $table->unsignedBigInteger('subject_id')->nullable(); // fallback/legacy subject
            $table->string('status', 20)->default('exempted'); // exempted | credit_transferred
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'id'], 'saei_school_id_id_uq');
            $table->index(['school_id', 'exemption_id'], 'saei_exemption_idx');
            $table->index(['school_id', 'curriculum_id', 'curriculum_membership_id'], 'saei_membership_idx');
            $table->index(['school_id', 'subject_id'], 'saei_subject_idx');

            $table->foreign('school_id', 'saei_school_fk')
                ->references('id')->on('schools')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign(['school_id', 'exemption_id'], 'saei_exemption_tenant_fk')
                ->references(['school_id', 'id'])->on('student_academic_exemptions')->onDelete('cascade')->onUpdate('restrict');
            // Ensure item's curriculum_id matches parent exemption's curriculum_id via FK
            $table->foreign(['school_id', 'curriculum_id'], 'saei_curriculum_tenant_fk')
                ->references(['school_id', 'id'])->on('curricula')->onDelete('restrict')->onUpdate('restrict');
            // curriculum_memberships has unique(school_id, curriculum_id, id) — use composite tenant FK
            $table->foreign(['school_id', 'curriculum_id', 'curriculum_membership_id'], 'saei_membership_tenant_fk')
                ->references(['school_id', 'curriculum_id', 'id'])->on('curriculum_memberships')->onDelete('restrict')->onUpdate('restrict');
            // subjects has unique(school_id, id) — use composite tenant FK
            $table->foreign(['school_id', 'subject_id'], 'saei_subject_tenant_fk')
                ->references(['school_id', 'id'])->on('subjects')->onDelete('restrict')->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_academic_exemption_items');
    }
};