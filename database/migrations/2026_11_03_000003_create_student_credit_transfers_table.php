<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_credit_transfers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('programme_id');
            $table->unsignedBigInteger('curriculum_id');
            $table->string('source_institution', 255);
            $table->string('source_qualification', 255)->nullable();
            $table->decimal('credits', 8, 2)->default(0);
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'id'], 'sct_school_id_id_uq');
            $table->index(['school_id', 'student_id', 'status'], 'sct_student_status_idx');

            $table->foreign('school_id', 'sct_school_fk')
                ->references('id')->on('schools')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign('student_id', 'sct_student_fk')
                ->references('id')->on('users')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign(['school_id', 'programme_id'], 'sct_programme_tenant_fk')
                ->references(['school_id', 'id'])->on('programmes')->onDelete('restrict')->onUpdate('restrict');
            // curricula has unique(school_id, id) — use composite tenant FK
            $table->foreign(['school_id', 'curriculum_id'], 'sct_curriculum_tenant_fk')
                ->references(['school_id', 'id'])->on('curricula')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign('requested_by', 'sct_requester_fk')
                ->references('id')->on('users')->onDelete('restrict')->onUpdate('restrict');
            $table->foreign('decided_by', 'sct_decider_fk')
                ->references('id')->on('users')->onDelete('set null')->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_credit_transfers');
    }
};