<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_financial_setup_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('fee_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('captured_by');
            $table->json('prior_payment')->nullable();
            $table->string('prior_payment_status', 20)->default('not_requested');
            $table->unsignedBigInteger('prior_payment_confirmed_by')->nullable();
            $table->timestamp('prior_payment_confirmed_at')->nullable();
            $table->json('award')->nullable();
            $table->string('award_status', 20)->default('not_requested');
            $table->unsignedBigInteger('award_confirmed_by')->nullable();
            $table->timestamp('award_confirmed_at')->nullable();
            $table->json('study_waiver')->nullable();
            $table->string('study_waiver_status', 20)->default('not_requested');
            $table->unsignedBigInteger('study_waiver_confirmed_by')->nullable();
            $table->timestamp('study_waiver_confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'fee_id'], 'financial_setup_review_invoice_unique');
            $table->index(['school_id', 'session_id', 'student_id'], 'financial_setup_review_student_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('student_financial_setup_reviews')
            && \Illuminate\Support\Facades\DB::table('student_financial_setup_reviews')->where(function ($query): void {
                $query->where('prior_payment_status', 'confirmed')
                    ->orWhere('award_status', 'confirmed')
                    ->orWhere('study_waiver_status', 'confirmed');
            })->exists()) {
            throw new RuntimeException('Do not remove financial setup review records after ledger entries have been confirmed.');
        }

        Schema::dropIfExists('student_financial_setup_reviews');
    }
};
