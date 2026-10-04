<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO RELEASED A RESULT, AND WHEN.
 *
 * ── WHY THIS EXISTS ───────────────────────────────────────────────────────
 *
 * Result publication wrote only `status = result_published` and
 * `result_review_state = 'published'`. That records THAT a result was released and
 * nothing about WHO released it or WHEN.
 *
 * That is a gap in an audit trail, not a cosmetic one. Every other governed transition
 * in this system names the actor — marking records `marked_by`, review records
 * `reviewed_by`. Publication was the one step that left no actor behind, which is
 * precisely the step an institution most often has to answer questions about.
 *
 * ── WHY ADDITIVE COLUMNS AND NOT A NEW TABLE ───────────────────────────────
 *
 * The release state already lives on the submission, and a student has exactly one
 * result per attempt, so a separate release-history table would duplicate a fact that
 * cannot vary. These columns are nullable, so every existing row — including the
 * published ones written before this migration — is untouched and simply has no
 * recorded releaser, which is the honest value for them.
 *
 * Reversible by dropping two nullable columns; no existing data is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_exam_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('online_exam_submissions', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('submitted_at');
            }

            if (! Schema::hasColumn('online_exam_submissions', 'published_by')) {
                // The administrator who released the result. Nullable on purpose:
                // a row published before this migration has no releaser, and inventing
                // one would be a fabrication in an audit column.
                $table->unsignedBigInteger('published_by')->nullable()->after('published_at');
            }
        });

        Schema::table('online_exam_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('online_exam_submissions', 'published_by')) {
                $table->index('published_by', 'online_exam_submissions_published_by_index');
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['published_at', 'published_by'],
            fn ($column) => Schema::hasColumn('online_exam_submissions', $column)
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('online_exam_submissions', function (Blueprint $table) use ($columns) {
            $table->dropIndex('online_exam_submissions_published_by_index');
            $table->dropColumn($columns);
        });
    }
};