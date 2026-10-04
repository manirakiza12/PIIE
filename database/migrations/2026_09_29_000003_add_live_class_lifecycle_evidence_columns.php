<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative evidence of WHO did WHAT, and WHEN, to a Live Class.
 *
 * WHY THIS IS ADDED NOW
 *
 * Until now a Live Class recorded only two facts about its own conduct: the
 * stored status, and the wall-clock columns the author typed. That is enough to
 * answer "is it live right now?" but not the questions an academic record has
 * to be able to answer afterwards:
 *
 *   - who started this class, and when?
 *   - who declared it finished, and when?
 *   - who cancelled it, and when?
 *   - is a missing recording because nobody recorded, or because it failed?
 *
 * Without the first three, "Completed" could only ever be inferred from a
 * clock, which is precisely how a class that never ran could come to be
 * reported as though it had. The actor/time columns make the deliberate human
 * decision the authoritative fact and leave the clock to mean only "the time is
 * now".
 *
 * These are ADDITIVE and NULLABLE on purpose. Every one of them is NULL for
 * every existing row, which is the honest value: those classes were conducted
 * before this evidence existed, and inventing a start time for them would
 * fabricate the academic record. NULL therefore reads as "not recorded", and
 * the interface says so rather than guessing.
 *
 * `recording_status` exists because the absence of a URL is genuinely
 * ambiguous. A class may have had no recording, may still be processing, may
 * have failed, or may simply not have been released to students yet - four
 * different facts that all look like "no link" without it. Defaulting to
 * 'none' means a class that was never recorded says exactly that, instead of
 * leaving a blank that reads as a loading failure.
 *
 * No foreign keys are declared, matching every other relation in this schema.
 * The *_by columns are plain user ids and are read defensively.
 */
class AddLiveClassLifecycleEvidenceColumns extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('live_classes')) {
            return;
        }

        // Decided BEFORE the blueprint is opened. Schema::hasColumn() must not be
        // called inside a Schema::table() closure: the blueprint defers the ALTER
        // to the end of the statement, so an introspection query issued while it
        // is still open does not see the table the way the builder assumes and
        // the migration aborts. One closure, one ALTER, no nesting.
        $addTimestamps = array_values(array_filter(
            ['started_at', 'ended_at', 'cancelled_at'],
            fn (string $column): bool => ! Schema::hasColumn('live_classes', $column)
        ));
        $addActors = array_values(array_filter(
            ['started_by', 'ended_by', 'cancelled_by'],
            fn (string $column): bool => ! Schema::hasColumn('live_classes', $column)
        ));
        $addRecordingStatus = ! Schema::hasColumn('live_classes', 'recording_status');

        if ($addTimestamps === [] && $addActors === [] && ! $addRecordingStatus) {
            return;
        }

        Schema::table('live_classes', function (Blueprint $table) use ($addTimestamps, $addActors, $addRecordingStatus): void {
            foreach ($addTimestamps as $column) {
                $table->dateTime($column)->nullable();
            }
            foreach ($addActors as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            if ($addRecordingStatus) {
                // Deliberately a string, not an enum, so a later state can be
                // added without another ALTER on a growing table. The allowed
                // set is LiveClass::RECORDING_STATUSES.
                $table->string('recording_status', 20)->default('none');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('live_classes')) {
            Schema::table('live_classes', function (Blueprint $table): void {
                foreach (['started_at', 'ended_at', 'cancelled_at', 'started_by', 'ended_by', 'cancelled_by', 'recording_status'] as $column) {
                    if (Schema::hasColumn('live_classes', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
}
