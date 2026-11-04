<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_progression_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('programme_id');
            $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('programme_cohort_membership_id')->nullable();
            $table->string('from_level', 60)->nullable();
            $table->string('to_level', 60)->nullable();
            $table->string('decision', 30); // promote | retain | conditional | exceptional
            $table->json('basis')->nullable(); // snapshot of completed/outstanding/exempted
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('decided_by');
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->unique(['school_id', 'id'], 'spd_school_id_id_uq');
            $table->index(['school_id', 'student_id', 'decided_at'], 'spd_student_decided_idx');
            $table->index(['school_id', 'programme_id', 'curriculum_id'], 'spd_programme_curriculum_idx');

            $table->foreign('school_id', 'spd_school_fk')
                ->references('id')->on('schools')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign('student_id', 'spd_student_fk')
                ->references('id')->on('users')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign(['school_id', 'programme_id'], 'spd_programme_tenant_fk')
                ->references(['school_id', 'id'])->on('programmes')->onDelete('restrict')->onUpdate('restrict');
            // curricula has unique(school_id, id) — use composite tenant FK
            $table->foreign(['school_id', 'curriculum_id'], 'spd_curriculum_tenant_fk')
                ->references(['school_id', 'id'])->on('curricula')->onDelete('restrict')->onUpdate('restrict');
            // programme_cohort_memberships has unique(school_id, id) — use composite tenant FK
            $table->foreign(['school_id', 'programme_cohort_membership_id'], 'spd_cohort_membership_tenant_fk')
                ->references(['school_id', 'id'])->on('programme_cohort_memberships')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign('decided_by', 'spd_decider_fk')
                ->references('id')->on('users')->onDelete('restrict')->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_progression_decisions');
    }
};