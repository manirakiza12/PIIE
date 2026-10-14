<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('books', function (Blueprint $t) {
            $t->string('resource_type',30)->default('book'); $t->string('digital_path',500)->nullable(); $t->string('digital_name')->nullable();
            $t->string('digital_mime',80)->nullable(); $t->string('download_audience',20)->default('all'); $t->decimal('replacement_cost',12,2)->default(0);
        });
        Schema::table('library_policies', function (Blueprint $t) {
            $t->string('library_name')->default('PIIE Library'); $t->string('opening_hours',500)->nullable(); $t->boolean('module_enabled')->default(true);
            $t->boolean('student_downloads')->default(true); $t->boolean('staff_downloads')->default(true); $t->boolean('allow_epub')->default(false);
            $t->decimal('fine_per_day',12,2)->default(0); $t->unsignedSmallInteger('fine_grace_days')->default(0); $t->decimal('fine_cap',12,2)->default(0); $t->string('fine_currency',3)->default('UGX');
        });
        Schema::table('book_issues', function (Blueprint $t) {
            $t->decimal('fine_per_day',12,2)->default(0); $t->unsignedSmallInteger('fine_grace_days')->default(0); $t->decimal('fine_cap',12,2)->default(0);
            $t->decimal('replacement_cost',12,2)->default(0); $t->string('fine_currency',3)->default('UGX'); $t->timestamp('reminded_at')->nullable();
        });
        Schema::create('library_fines', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('loan_id'); $t->unsignedBigInteger('user_id');
            $t->string('reason',30); $t->decimal('amount',12,2); $t->string('currency',3); $t->string('status',20)->default('outstanding');
            $t->unsignedBigInteger('resolved_by')->nullable(); $t->timestamp('resolved_at')->nullable(); $t->text('resolution_note')->nullable();
            $t->timestamps(); $t->unique(['loan_id','reason']); $t->index(['school_id','user_id','status']);
        });
        Schema::create('library_requests', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id'); $t->string('title'); $t->string('author');
            $t->string('resource_type',30)->default('book'); $t->string('category',100)->nullable(); $t->text('reason'); $t->string('resource_url',1000)->nullable();
            $t->string('digital_path',500)->nullable(); $t->string('digital_name')->nullable(); $t->string('digital_mime',80)->nullable(); $t->string('status',20)->default('pending');
            $t->unsignedBigInteger('book_id')->nullable(); $t->unsignedBigInteger('reviewed_by')->nullable(); $t->text('review_note')->nullable();
            $t->timestamps(); $t->index(['school_id','status']);
        });
        Schema::create('library_notices', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('created_by'); $t->string('title'); $t->text('body');
            $t->string('audience',20)->default('all'); $t->boolean('is_active')->default(true); $t->date('expires_on')->nullable(); $t->timestamps(); $t->index(['school_id','is_active']);
        });
        Schema::create('library_course_resources', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('course_offering_id'); $t->unsignedBigInteger('book_id');
            $t->unsignedBigInteger('linked_by'); $t->timestamps(); $t->unique(['course_offering_id','book_id']); $t->index('school_id');
        });
        Schema::create('library_member_blocks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('school_id'); $t->unsignedBigInteger('user_id'); $t->text('reason');
            $t->boolean('is_active')->default(true); $t->unsignedBigInteger('changed_by'); $t->timestamps(); $t->unique(['school_id','user_id']);
        });
    }
    public function down(): void { /* Retain digital assets, fine and audit history on code rollback. */ }
};
