<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * student_fee_managers.document_image — the offline-payment proof filename.
 *
 * WHY THIS MIGRATION EXISTS
 *
 * StudentController::offlinePaymentStudent() and ParentController::offlinePayment()
 * both write `document_image` when a payer submits a bank/transfer receipt. The
 * column has never been created by any migration in this repository: a search of
 * database/migrations returns hits only for hostel_fees and payment_history.
 *
 * It IS present in database/legacy-install.sql (inside student_fee_managers), which
 * is how an install seeded from the vendor dump ends up with it while a
 * migrate-only install does not — the divergence this migration removes.
 *
 * It is ABSENT from all 15 schema dumps in storage/app/reconciliation/ (newest
 * 20260930), so the migrate-built production schema does not have it and the two
 * offline-payment submit routes fail on an unknown column.
 *
 * Two tests paper over this by adding the column themselves in setUp()
 * (AcademicFinanceTenantSecurityTest, FinalSecurityHardeningTest), which is why the
 * suite passes while the live route is broken.
 *
 * Add-only and guarded: no data is touched, no column is dropped or renamed, and
 * the whole thing is skipped where the column already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('student_fee_managers', 'document_image')) {
            return;
        }

        Schema::table('student_fee_managers', function (Blueprint $table) {
            // Stored filename only. The bytes live outside the database, and the
            // legacy baseline types this column as varchar(255) — matching it keeps
            // the two install paths byte-identical for this field.
            $table->string('document_image')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        // Proof filenames are evidence of a payment. Dropping the column would
        // destroy that record, so a rollback leaves it in place deliberately.
        // Deployments that must revert the column can do so explicitly.
    }
};