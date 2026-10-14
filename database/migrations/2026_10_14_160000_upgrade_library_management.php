<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('books', function (Blueprint $t) {
            $t->string('isbn', 32)->nullable();
            $t->string('category', 100)->nullable();
            $t->string('publisher')->nullable();
            $t->string('edition', 80)->nullable();
            $t->unsignedSmallInteger('publication_year')->nullable();
            $t->string('language', 80)->default('English');
            $t->string('shelf_location', 100)->nullable();
            $t->text('description')->nullable();
            $t->string('resource_url', 1000)->nullable();
            $t->boolean('is_active')->default(true);
            $t->index(['school_id', 'is_active']);
        });
        Schema::create('library_copies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('book_id');
            $t->string('accession', 80);
            $t->string('condition', 20)->default('good');
            $t->string('status', 20)->default('circulating');
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'accession']);
            $t->index(['school_id', 'book_id', 'status']);
        });
        Schema::table('book_issues', function (Blueprint $t) {
            $t->unsignedBigInteger('copy_id')->nullable();
            $t->date('due_date')->nullable();
            $t->timestamp('returned_at')->nullable();
            $t->unsignedSmallInteger('renewal_count')->default(0);
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->unsignedBigInteger('returned_by')->nullable();
            $t->string('return_condition', 20)->nullable();
            $t->text('notes')->nullable();
            $t->index(['school_id', 'status', 'due_date']);
            $t->index(['school_id', 'student_id', 'status']);
            $t->index(['school_id', 'book_id', 'status']);
            $t->index(['copy_id', 'status']);
        });
        Schema::create('library_reservations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('book_id');
            $t->unsignedBigInteger('user_id');
            $t->string('status', 20)->default('waiting');
            $t->date('expires_on');
            $t->timestamps();
            $t->index(['school_id', 'book_id', 'status']);
            $t->index(['school_id', 'user_id', 'status']);
        });
        Schema::create('library_policies', function (Blueprint $t) {
            $t->unsignedBigInteger('school_id')->primary();
            $t->unsignedSmallInteger('loan_days')->default(14);
            $t->unsignedSmallInteger('max_loans')->default(3);
            $t->unsignedSmallInteger('max_renewals')->default(1);
            $t->unsignedSmallInteger('reservation_days')->default(7);
            $t->boolean('block_overdue')->default(true);
            $t->timestamps();
        });
        Schema::create('library_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('school_id');
            $t->unsignedBigInteger('actor_id');
            $t->string('action', 40);
            $t->unsignedBigInteger('book_id')->nullable();
            $t->unsignedBigInteger('loan_id')->nullable();
            $t->text('description');
            $t->timestamp('created_at');
            $t->index(['school_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Library history and accession records are retained on code rollback.
        // Reversing this additive migration requires a reviewed data retention plan.
    }
};
