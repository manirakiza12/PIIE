<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AN APPROVED ADJUSTMENT TO THE EXAMINATION'S INTEGRITY CONTROLS.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 *
 * The attempt page runs a restricted-interaction mode: copy, cut, paste, the context
 * menu, drag and the clipboard/navigation shortcuts are refused inside the protected
 * exam area, and leaving the tab is recorded.
 *
 * That is right for most students and wrong for some. A student with an approved
 * adjustment — a screen reader that needs to select text to speak it, an approved
 * assistive tool, an extra time arrangement that involves a second window — must be
 * able to sit the same paper as everyone else. Without somewhere to record that, the
 * only two options an invigilator has are to turn the controls off for everybody or to
 * make an exception at the keyboard, and the first of those is the wrong default for
 * an institution that has both kinds of student.
 *
 * ── WHAT IT IS, PRECISELY ─────────────────────────────────────────────────
 *
 * A nullable, enumerated, EXAM-LEVEL setting. It is not a blanket "turn everything
 * off" flag: the value names WHICH control is relaxed, so the record says what was
 * granted and to which paper, and an examination that loses its restrictions does not
 * silently lose its clipboard and focus records with them.
 *
 * NULL means the full, default restrictions apply — which is what every existing row
 * has, so this migration changes no behaviour on its own.
 *
 * ── THE LIMIT, STATED PLAINLY ─────────────────────────────────────────────
 *
 * This is EXAM-level, not STUDENT-level. A single paper either carries the adjustment
 * or it does not. Per-student accommodations need an institution-specific decision
 * about who may grant one, when it expires and how it is evidenced, and inventing that
 * here would put an unapproved policy into an audit column. The column is the storage;
 * the approval workflow is deliberately not fabricated.
 *
 * Reversible by dropping one nullable column; no existing row is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_exams', function (Blueprint $table) {
            if (Schema::hasColumn('online_exams', 'integrity_accommodation')) {
                return;
            }

            $table->string('integrity_accommodation', 32)->nullable()->after('fullscreen_required');
        });

        Schema::table('online_exams', function (Blueprint $table) {
            if (Schema::hasColumn('online_exams', 'integrity_accommodation')) {
                $table->index('integrity_accommodation', 'online_exams_integrity_accommodation_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('online_exams', 'integrity_accommodation')) {
            return;
        }

        Schema::table('online_exams', function (Blueprint $table) {
            $table->dropIndex('online_exams_integrity_accommodation_index');
            $table->dropColumn('integrity_accommodation');
        });
    }
};