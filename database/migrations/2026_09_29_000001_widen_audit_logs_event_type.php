<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen audit_logs.event_type so legitimate system event identifiers fit.
 *
 * THE DEFECT
 * ----------
 * 2026_07_26_160000_add_context_to_audit_logs_table.php declared
 *
 *     $table->string('event_type', 20)->default('ACTION')
 *
 * but the application writes event identifiers far longer than 20 characters:
 *
 *     COURSE_OFFERING_LECTURER_ALLOCATION   35
 *     STUDENT_CURRICULUM_ASSIGNMENT         29
 *     COURSE_OFFERING_ATTENDANCE            26
 *     STUDENT_PROGRAMME_CHANGE              24
 *
 * config/database.php sets 'strict' => true, so Laravel's connection runs with
 * STRICT_TRANS_TABLES and an over-length value is a hard
 * "Data too long for column 'event_type'" error rather than a silent truncation.
 * Audit rows are written INSIDE the business transaction in every service that
 * audits, so that error rolls back the legitimate business work: a lecturer
 * allocation, an attendance session or a curriculum assignment would be lost
 * because its own audit identifier did not fit.
 *
 * THE FIX
 * -------
 * One additive, non-destructive column widening: VARCHAR(20) -> VARCHAR(100).
 * 100 is the agreed target; the longest identifier in the codebase is 35, and
 * the same length as audit_logs.action (already varchar(100)), so the two
 * columns are consistent and leave room for future modules.
 *
 * Explicitly NOT done here:
 *   - no event identifier is shortened, renamed or rewritten;
 *   - no existing audit row is modified, truncated or deleted;
 *   - audit_logs is not dropped or recreated;
 *   - the audit_logs_event_type_index is preserved (MODIFY COLUMN on an
 *     indexed column keeps its index in place in MySQL/MariaDB).
 *
 * WHY RAW SQL RATHER THAN ->change()
 * -------------------------------
 * doctrine/dbal is not a dependency of this application, and on Laravel 9
 * Schema::table()->change() on an existing column requires dbal. Calling it
 * would throw at runtime. This migration therefore issues driver-appropriate DDL
 * directly, which is also the exact operation - a length widening - with no
 * data conversion and no lock beyond the ALTER itself.
 *
 * SQLite is treated as a deliberate no-op: SQLite does not enforce VARCHAR
 * length at all, so there is nothing to widen and a table rebuild would be pure
 * risk. Automated tests therefore run against SQLite, where the real MySQL
 * overflow cannot be reproduced - the tests prove the application stores these
 * identifiers in full and that the business transactions commit, and the
 * migration's DDL is verified by inspection plus a dry-run assertion.
 */
return new class extends Migration
{
    /** Target width: matches audit_logs.action, comfortably above the longest identifier (35). */
    private const WIDTH = 100;

    private const COLUMN = 'event_type';

    public function up(): void
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasColumn('audit_logs', self::COLUMN)) {
            return;
        }

        if ($this->driver() === 'sqlite') {
            // SQLite ignores VARCHAR length; no rebuild, no risk.
            return;
        }

        if ($this->currentLength() >= self::WIDTH) {
            return;
        }

        if ($this->driver() === 'mysql') {
            // MODIFY preserves the column's NOT NULL, its DEFAULT and the
            // existing index on it. utf8mb4 VARCHAR(100) = 400 bytes, well
            // inside InnoDB's 3072-byte index limit.
            DB::statement(sprintf(
                'ALTER TABLE `audit_logs` MODIFY `%s` VARCHAR(%d) NOT NULL DEFAULT %s',
                self::COLUMN,
                self::WIDTH,
                $this->quote('ACTION')
            ));

            return;
        }

        if ($this->driver() === 'pgsql') {
            DB::statement(sprintf(
                'ALTER TABLE "audit_logs" ALTER COLUMN "%s" TYPE VARCHAR(%d)',
                self::COLUMN,
                self::WIDTH
            ));

            return;
        }

        if ($this->driver() === 'sqlsrv') {
            DB::statement(sprintf(
                'ALTER TABLE [audit_logs] ALTER COLUMN [%s] VARCHAR(%d) NOT NULL',
                self::COLUMN,
                self::WIDTH
            ));
        }
    }

    /**
     * Narrowing is deliberately refused.
     *
     * Shrinking the column would fail outright if any stored identifier were
     * longer than the new width, and could truncate history. Rolling back a
     * widening is not a safe operation on an audit table, so this reports the
     * no-op rather than risking the audit trail.
     */
    public function down(): void
    {
        // Intentionally a no-op. See the note above: narrowing an audit column
        // can destroy legitimate history, so it is not performed automatically.
    }

    private function driver(): string
    {
        return (string) DB::connection()->getDriverName();
    }

    /** The column's current declared length, or 0 when it cannot be determined. */
    private function currentLength(): int
    {
        try {
            $row = DB::selectOne(
                'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                ['audit_logs', self::COLUMN]
            );
        } catch (\Throwable $exception) {
            return 0;
        }

        return (int) ($row->len ?? 0);
    }

    private function quote(string $value): string
    {
        return DB::connection()->getPdo()->quote($value);
    }
};
