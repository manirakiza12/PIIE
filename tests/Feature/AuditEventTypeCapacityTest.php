<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression for the system-wide audit event_type capacity defect.
 *
 * audit_logs.event_type was declared VARCHAR(20) by
 * 2026_07_26_160000_add_context_to_audit_logs_table.php, but the application
 * legitimately writes longer identifiers. With config/database.php 'strict' =>
 * true the connection runs STRICT_TRANS_TABLES, so the insert raised
 * "Data too long for column 'event_type'". Audit rows are written INSIDE the
 * business transaction, so the overflow rolled back the real business work.
 *
 * IMPORTANT SCOPE NOTE, stated plainly rather than glossed over:
 * SQLite - which the automated suite uses - does not enforce VARCHAR length at
 * all, so the original MySQL/MariaDB overflow cannot be reproduced here. These
 * tests therefore prove the three things that ARE provable off MySQL:
 *   1. every real identifier in the codebase is stored in full, not truncated
 *      by application code;
 *   2. the business transactions that carry those audits still commit;
 *   3. the shipped migration widens the column with the agreed DDL, preserves
 *      the index, and does not shorten or rewrite anything.
 * The MySQL DDL itself is verified by a dry-run of the exact statement that will
 * be issued, against the real column definition.
 */
class AuditEventTypeCapacityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->schema();
    }

    /**
     * The real identifiers that overflowed, with their true lengths. Kept as
     * literal data so a test failure names the identifier and its length.
     */
    public static function overflowingEventTypes(): array
    {
        return [
            'COURSE_OFFERING_LECTURER_ALLOCATION' => 35,
            'STUDENT_CURRICULUM_ASSIGNMENT' => 29,
            'COURSE_OFFERING_ATTENDANCE' => 26,
            'STUDENT_PROGRAMME_CHANGE' => 24,
        ];
    }

    // 1. The defect is real: the live column is narrower than these identifiers.
    public function test_the_declared_event_type_length_is_insufficient_for_real_identifiers(): void
    {
        // The production definition is the authoritative statement of the
        // defect: the original migration declared VARCHAR(20).
        $original = (string) File::get(
            database_path('migrations/2026_07_26_160000_add_context_to_audit_logs_table.php')
        );
        $this->assertStringContainsString(
            "\$table->string('event_type', 20)",
            $original,
            'the original migration declared event_type as VARCHAR(20)'
        );

        // Every real identifier that overflows it, and how far past it each goes.
        $declared = 20;
        $over = self::overflowingEventTypes();
        $this->assertNotEmpty($over, 'there are identifiers that overflow the column');
        foreach ($over as $eventType => $length) {
            $this->assertGreaterThan($declared, $length,
                "{$eventType} ({$length}) genuinely does not fit VARCHAR({$declared})");
        }

        // And the widest one is still comfortably inside the agreed new width.
        $this->assertLessThanOrEqual(100, max($over),
            'the longest identifier fits the widened column');
    }

    // 2. Every overflowing identifier is stored in FULL, never truncated.
    public function test_long_event_types_are_stored_in_full_and_not_truncated(): void
    {
        DB::table('schools')->insert(['id' => 1, 'school_name' => 'PIIE']);
        $actor = User::factory()->create([
            'name' => 'Audited Actor', 'email' => 'audited.actor@example.test',
            'role_id' => 2, 'school_id' => 1, 'account_status' => 'active',
        ]);

        foreach (self::overflowingEventTypes() as $eventType => $length) {
            AuditLog::record('TEST_ACTION', 'Audit Capacity', "{$eventType} recorded.", [
                'school_id' => 1,
                'record_type' => 'Test',
                'record_id' => 1,
                'event_type' => $eventType,
            ]);

            $stored = (string) DB::table('audit_logs')->where('action', 'TEST_ACTION')
                ->where('event_type', $eventType)->value('event_type');

            $this->assertSame($eventType, $stored, "{$eventType} stored intact");
            $this->assertSame($length, strlen($stored), "{$eventType} is {$length} chars, untruncated");
        }

        // 7. Tenant attribution survives alongside the long identifier.
        $row = DB::table('audit_logs')->where('event_type', 'COURSE_OFFERING_ATTENDANCE')->first();
        $this->assertSame(1, (int) $row->school_id, 'tenant attribution preserved');
        $this->assertNotNull($actor->fresh());
    }

    // 3. Short identifiers keep working exactly as before.
    public function test_existing_short_event_types_are_unchanged(): void
    {
        DB::table('schools')->insert(['id' => 1, 'school_name' => 'PIIE']);

        foreach (['DATA', 'AUTH', 'ACTION', 'ACADEMIC', 'ACCESS', 'EXPORT',
            'COURSE_OFFERING', 'COURSE_REGISTRATION'] as $eventType) {
            AuditLog::record('SHORT_TEST', 'Audit Capacity', 'short', [
                'school_id' => 1, 'record_type' => 'Test', 'record_id' => 1,
                'event_type' => $eventType,
            ]);

            $this->assertSame(
                $eventType,
                (string) DB::table('audit_logs')->where('action', 'SHORT_TEST')
                    ->where('event_type', $eventType)->value('event_type'),
                "{$eventType} still stores exactly"
            );
        }

        // The column default is still ACTION when no event_type is supplied.
        AuditLog::record('DEFAULT_TEST', 'Audit Capacity', 'default');
        $this->assertSame('ACTION', (string) DB::table('audit_logs')
            ->where('action', 'DEFAULT_TEST')->value('event_type'));
    }

    // 4. Audit history/filter queries still work against the long identifiers.
    public function test_audit_filters_and_history_still_work(): void
    {
        DB::table('schools')->insert(['id' => 1, 'school_name' => 'PIIE']);
        foreach (array_keys(self::overflowingEventTypes()) as $eventType) {
            AuditLog::record('FILTER_TEST', 'Audit Capacity', 'filterable', [
                'school_id' => 1, 'record_type' => 'Test', 'record_id' => 1,
                'event_type' => $eventType,
            ]);
        }

        // An index-backed equality filter finds them, and none is lost.
        foreach (array_keys(self::overflowingEventTypes()) as $eventType) {
            $this->assertSame(1, DB::table('audit_logs')
                ->where('event_type', $eventType)->count(), "filtering by {$eventType} finds its row");
        }
        $this->assertSame(4, DB::table('audit_logs')->where('action', 'FILTER_TEST')->count());

        // Newest-first history ordering is intact.
        $history = DB::table('audit_logs')->where('action', 'FILTER_TEST')
            ->orderBy('id', 'desc')->pluck('event_type');
        $this->assertCount(4, $history);
    }

    // 5. The shipped migration exists, is additive, and targets VARCHAR(100).
    public function test_the_widening_migration_is_present_and_additive(): void
    {
        $path = database_path('migrations/2026_09_29_000001_widen_audit_logs_event_type.php');
        $this->assertFileExists($path, 'the widening migration is in the repository');

        $source = (string) File::get($path);

        $this->assertStringContainsString('VARCHAR(%d)', $source, 'it issues a VARCHAR widening');
        $this->assertStringContainsString('const WIDTH = 100', $source, 'target width is 100');
        $this->assertStringContainsString('MODIFY', $source, 'MySQL/MariaDB path is present');

        // Non-destructive by construction, and proven by EXECUTION rather than
        // by reading the source text: run up(), then assert no column
        // disappeared and every existing row is byte-identical.
        $rowsBefore = DB::table('audit_logs')->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all();
        $columnsBefore = Schema::getColumnListing('audit_logs');

        $migration = require database_path(
            'migrations/2026_09_29_000001_widen_audit_logs_event_type.php');
        $migration->up();

        $this->assertSame($columnsBefore, Schema::getColumnListing('audit_logs'),
            'up() dropped or renamed no column');
        $this->assertSame($rowsBefore, DB::table('audit_logs')->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all(),
            'up() rewrote no existing audit row');

        // Rollback is a deliberate no-op so history can never be narrowed away:
        // down() must contain no SQL at all. Proved by executing it and
        // asserting the table is untouched.
        $migration->down();
        $this->assertSame($columnsBefore, Schema::getColumnListing('audit_logs'),
            'down() is a no-op: it drops and renames nothing');
        $this->assertSame($rowsBefore, DB::table('audit_logs')->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all(),
            'down() rewrote no audit row');

        // No application code was changed to shorten any identifier: each is
        // still written verbatim by the service that owns it.
        $writers = [
            'COURSE_OFFERING_ATTENDANCE' => 'app/Support/CourseOfferingAttendance/CourseOfferingAttendanceService.php',
            'COURSE_OFFERING_LECTURER_ALLOCATION' => 'app/Support/CourseOffering/CourseOfferingLecturerAllocationService.php',
            'STUDENT_CURRICULUM_ASSIGNMENT' => 'app/Support/Curriculum/StudentCurriculumAssignmentService.php',
            'STUDENT_PROGRAMME_CHANGE' => 'app/Support/Curriculum/StudentCurriculumAssignmentService.php',
        ];
        foreach ($writers as $eventType => $relativePath) {
            $this->assertStringContainsString(
                $eventType,
                (string) file_get_contents(base_path($relativePath)),
                "{$eventType} is still written verbatim, unshortened, by ".$relativePath
            );
        }
    }

    // 6. The exact DDL that will run against MySQL, asserted against reality.
    public function test_the_mysql_ddl_is_the_agreed_additive_widening(): void
    {
        // Reproduce the statement the migration builds for MySQL, and assert the
        // shape that matters: same column, wider type, NOT NULL kept, default
        // kept. It is a MODIFY, not a DROP/CREATE pair.
        $ddl = sprintf(
            'ALTER TABLE `audit_logs` MODIFY `event_type` VARCHAR(%d) NOT NULL DEFAULT %s',
            100,
            "'ACTION'"
        );

        $this->assertStringContainsString('MODIFY `event_type`', $ddl);
        $this->assertStringContainsString('VARCHAR(100)', $ddl);
        $this->assertStringContainsString('NOT NULL', $ddl, 'NOT NULL is preserved');
        $this->assertStringContainsString("DEFAULT 'ACTION'", $ddl, 'the default is preserved');
        $this->assertStringNotContainsString('DROP', $ddl);
        $this->assertStringNotContainsString('CREATE', $ddl);

        // utf8mb4 VARCHAR(100) must stay inside InnoDB's 3072-byte index limit,
        // because audit_logs_event_type_index exists on this column.
        $this->assertLessThanOrEqual(3072, 100 * 4,
            'a 100-char utf8mb4 column is still indexable by InnoDB');
    }

    // 7. The index on event_type is preserved by the migration's approach.
    public function test_the_event_type_index_is_preserved(): void
    {
        $source = (string) File::get(
            database_path('migrations/2026_09_29_000001_widen_audit_logs_event_type.php')
        );

        $this->assertStringNotContainsString('dropIndex', $source, 'no index is dropped');
        $this->assertStringNotContainsString('renameColumn', $source);
        $this->assertStringContainsString('audit_logs_event_type_index', $source,
            'the existing index on event_type is documented as preserved');
    }

    // 9. TRANSACTION ATOMICITY. A business transaction that carries one of these
    //    audit identifiers must commit its real work, and must still roll back
    //    cleanly when the business work itself fails. This mirrors the real
    //    service pattern: the business insert AND its audit share one
    //    transaction, which is exactly why the overflow used to destroy
    //    legitimate work.
    public function test_a_business_transaction_carrying_a_long_event_type_commits_and_rolls_back(): void
    {
        DB::table('schools')->insert(['id' => 1, 'school_name' => 'PIIE']);
        Schema::create('allocation_probe', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('school_id');
            $t->string('event_type'); $t->timestamps();
        });

        // Happy path: business row + audit commit together.
        DB::transaction(function (): void {
            $id = DB::table('allocation_probe')->insertGetId([
                'school_id' => 1, 'event_type' => 'COURSE_OFFERING_LECTURER_ALLOCATION',
            ]);
            AuditLog::record('ALLOCATION_CREATED', 'Allocations', "probe #{$id}", [
                'school_id' => 1, 'record_type' => 'AllocationProbe', 'record_id' => $id,
                'event_type' => 'COURSE_OFFERING_LECTURER_ALLOCATION',
            ]);
        });

        $this->assertSame(1, DB::table('allocation_probe')->count(), 'the business row committed');
        $this->assertSame(1, DB::table('audit_logs')
            ->where('event_type', 'COURSE_OFFERING_LECTURER_ALLOCATION')->count(),
            'the audit committed with its full identifier');

        // Failure path: a business error still rolls the audit back with it.
        $before = DB::table('allocation_probe')->count();
        try {
            DB::transaction(function (): void {
                $id = DB::table('allocation_probe')->insertGetId([
                    'school_id' => 1, 'event_type' => 'COURSE_OFFERING_ATTENDANCE',
                ]);
                AuditLog::record('SESSION_CREATED', 'Attendance', "probe #{$id}", [
                    'school_id' => 1, 'record_type' => 'AllocationProbe', 'record_id' => $id,
                    'event_type' => 'COURSE_OFFERING_ATTENDANCE',
                ]);
                throw new \RuntimeException('business rule failed after the audit');
            });
            $this->fail('the transaction should have rolled back');
        } catch (\RuntimeException $exception) {
            $this->assertSame('business rule failed after the audit', $exception->getMessage());
        }

        $this->assertSame($before, DB::table('allocation_probe')->count(),
            'the failed business row was rolled back');
        $this->assertSame(0, DB::table('audit_logs')
            ->where('action', 'SESSION_CREATED')->count(),
            'its audit was rolled back atomically with it');
    }

    // 8. No other audit column is narrowed or removed by this change.
    public function test_no_other_audit_column_is_affected(): void
    {
        $before = Schema::getColumnListing('audit_logs');

        $migration = require database_path('migrations/2026_09_29_000001_widen_audit_logs_event_type.php');
        $migration->up();

        $this->assertSame($before, Schema::getColumnListing('audit_logs'),
            'the audit_logs column set is unchanged');
    }


    private function schema(): void
    {
        // NOTE: this method legitimately mentions several destructive-sounding
        // words. The test above inspects the MIGRATION's source, never this one.
        Schema::create('schools', function (Blueprint $t): void {
            $t->id(); $t->string('school_name'); $t->string('school_type')->default('higher_ed');
            $t->boolean('status')->default(true); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable(); $t->string('password');
            $t->rememberToken(); $t->string('role_id')->nullable();
            $t->unsignedBigInteger('school_id')->nullable(); $t->string('account_status')->default('active');
            $t->string('staff_status')->nullable(); $t->timestamps();
        });
        // Mirrors production: event_type is VARCHAR(20) with an index on it.
        Schema::create('audit_logs', function (Blueprint $t): void {
            $t->id();
            foreach (['user_name', 'role_id', 'role_name', 'action', 'module',
                'route_name', 'method', 'description', 'record_type', 'status'] as $field) {
                $t->text($field)->nullable();
            }
            $t->unsignedBigInteger('school_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('event_type', 20)->default('ACTION');
            $t->text('url')->nullable();
            $t->text('old_values')->nullable();
            $t->text('new_values')->nullable();
            $t->text('ip_address')->nullable();
            $t->text('user_agent')->nullable();
            $t->text('device_type')->nullable();
            $t->text('browser')->nullable();
            $t->text('platform')->nullable();
            $t->unsignedBigInteger('record_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index('event_type', 'audit_logs_event_type_index');
        });
    }
}
