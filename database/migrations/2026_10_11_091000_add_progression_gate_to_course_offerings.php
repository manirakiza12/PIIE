<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sequential progression: whether a student must finish one topic before
 * opening the next.
 *
 * SEPARATE FROM THE PASS-MARK MIGRATION, DELIBERATELY
 *
 * The gate and the threshold were specified together, but shipping them as one
 * migration means editing an already-applied file when one of them needs to
 * change - and a migration that has run on a real database must be treated as
 * history, not as a draft. An edited migration that is already recorded produces
 * exactly the silent drift this project keeps paying for: the file says one
 * thing, the database another, and the two disagree only in production.
 *
 * So the threshold lives in `2026_10_11_090000_add_pass_mark_and_recovery`, and
 * the switch that makes it enforceable lives here, where its own history is
 * intact.
 *
 * DEFAULT FALSE, AND THAT IS THE SAFETY ARGUMENT
 *
 * Gating a live student population without warning would lock people out of
 * content they are part-way through. Opt-in means every existing offering keeps
 * behaving exactly as it does today, and no course can deny a paying student
 * access to a lesson until a lecturer deliberately turns this on.
 *
 * @see \App\Support\CourseContent\ProgressionGate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table): void {
            $table->boolean('gate_progression')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('course_offerings', function (Blueprint $table): void {
            $table->dropColumn('gate_progression');
        });
    }
};
