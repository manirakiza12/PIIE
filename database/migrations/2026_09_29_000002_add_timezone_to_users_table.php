<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's own timezone, for how THEIR screen reads a time.
 *
 * WHY THIS IS A SEPARATE COLUMN FROM schools.timezone
 *
 * There are two different questions, and conflating them was the design
 * mistake being corrected:
 *
 *  1. "What is the institution's official time?" - the reference for academic
 *     calendars, exam schedules, attendance records, deadlines, reporting and
 *     audit history. That is `schools.timezone`, one per institution, and it is
 *     what the academic record is expressed in.
 *
 *  2. "Where is this person standing when they read it?" - a lecturer teaching
 *     from London for a Kampala institution still schedules against ONE
 *     absolute instant; they simply want to type and read it in their own
 *     clock. That is personal presentation and input convenience, nothing more.
 *
 * A personal preference must never be able to change institutional time, and
 * one person's preference must never affect anyone else's. Keeping them in
 * different columns, owned by different tables, is what guarantees that.
 *
 * Nullable on purpose: NULL means "this person has not chosen", and the
 * resolver then falls back to their institution's timezone. Storing a copy of
 * the institution value would be worse - it would freeze a preference that
 * should follow the institution if the institution later moves.
 *
 * A varchar holding a real IANA identifier ("Europe/London"), never a fixed
 * offset: a region with daylight saving cannot be described by "UTC+3", and an
 * offset would silently drift by an hour twice a year.
 */
class AddTimezoneToUsersTable extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'timezone')) {
            Schema::table('users', function (Blueprint $table): void {
                // Nullable, and indexed: the resolution query is always
                // "this user's own timezone, else their institution's".
                $table->string('timezone', 64)->nullable()->after('language');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'timezone')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('timezone');
            });
        }
    }
}
