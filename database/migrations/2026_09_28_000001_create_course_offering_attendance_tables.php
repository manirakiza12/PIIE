<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HEI Course Offering attendance foundation.
 *
 * A fourth attendance domain, added beside the three that already exist and
 * deliberately not merged with any of them:
 *
 *   K12 daily register  -> daily_attendances        (untouched by this migration)
 *   Live Class evidence -> live_class_attendances   (untouched by this migration)
 *   HEI Course Offering -> the two tables created here
 *
 * A Course Offering meets dozens of times, so attendance cannot be keyed on a
 * course_offering_id alone. A session row is one teaching occurrence (a lecture
 * on a date, with an optional time and topic) and a record row is one student's
 * status in that occurrence. Together they answer "which teaching session was
 * attended?".
 *
 * Tenant isolation is enforced by the database wherever the existing schema
 * actually supports a composite key, and transactionally in the service
 * everywhere it does not. Every foreign key is declared inline in CREATE TABLE
 * rather than added with ALTER, because SQLite cannot add a constraint to an
 * existing table; inline works on both MySQL/MariaDB and SQLite.
 *
 *   sessions (school_id, course_offering_id) -> course_offerings (school_id, id)
 *     Supported: course_offerings_school_id_id_unique already exists.
 *   records (school_id, attendance_session_id) -> sessions (school_id, id)
 *     Supported: the composite unique created below, before this table.
 *   records (school_id, course_registration_id) -> course_registrations (school_id, id)
 *     Supported: the composite unique added below. course_registrations.school_id
 *     is already BIGINT UNSIGNED and id is the primary key, so a unique over
 *     (school_id, id) cannot collide with existing data. Additive only.
 *
 * Deliberately NOT composite foreign keys, because the real schema cannot
 * support them:
 *
 *   records (school_id, student_id)                   -> users (school_id, id)
 *   sessions (school_id, recorded_by_user_id)         -> users (school_id, id)
 *   records (school_id, marked_by_user_id)            -> users (school_id, id)
 *   sessions (school_id, live_class_id)               -> live_classes (school_id, id)
 *
 *   `users`.`school_id` is a plain nullable `integer`, not BIGINT UNSIGNED, and
 *   `users` carries no unique index of any kind, so no composite key to users is
 *   expressible without altering a column type on the most-referenced table in
 *   the product. `live_classes` likewise has no (school_id, id) unique. These
 *   are therefore plain references to the parent primary key, with same-tenant
 *   equality guaranteed by LecturerCourseOfferingAccess and asserted again in
 *   the service. The limitation is recorded, not worked around.
 *
 * Duplicate prevention is deliberately split. A session that has a start time is
 * protected by a real unique constraint. NULL values are distinct in both MySQL
 * and SQLite, so a unique key cannot express "at most one untimed session per
 * offering per day"; the untimed case is prevented in the service under a row
 * lock on the Course Offering, which serialises session creation per offering
 * and is deterministic rather than best-effort.
 */
return new class extends Migration
{
    public const SESSION_TABLE = 'course_offering_attendance_sessions';
    public const RECORD_TABLE = 'course_offering_attendance_records';
    public const REGISTRATION_UNIQUE = 'cr_school_id_id_unique';

    public function up(): void
    {
        $this->assertSchemaAssumptions();

        // Needed as the parent key of the record -> registration composite key.
        // Additive only: id is already the primary key, so no existing row can
        // violate it and no data is copied or rewritten.
        $this->ensureUniqueIndex('course_registrations', self::REGISTRATION_UNIQUE, ['school_id', 'id']);

        if (! Schema::hasTable(self::SESSION_TABLE)) {
            Schema::create(self::SESSION_TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->autoIncrement();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('course_offering_id');
                $table->date('session_date');
                $table->time('starts_at')->nullable();
                $table->time('ends_at')->nullable();
                $table->string('type', 20)->default('lecture');
                $table->string('topic', 255)->nullable();
                $table->unsignedBigInteger('live_class_id')->nullable();
                $table->unsignedBigInteger('recorded_by_user_id')->nullable();
                $table->string('status', 20)->default('draft');
                $table->timestamps();

                $table->unique(['school_id', 'id'], 'coas_school_id_id_unique');
                // Real database protection for a timed session.
                $table->unique(['school_id', 'course_offering_id', 'session_date', 'starts_at'], 'coas_offering_slot_unique');
                $table->index(['school_id', 'course_offering_id', 'session_date'], 'coas_offering_date_idx');
                $table->index(['live_class_id'], 'coas_live_class_idx');

                $table->foreign(['school_id', 'course_offering_id'], 'coas_offering_tenant_fk')
                    ->references(['school_id', 'id'])->on('course_offerings')
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign(['school_id'], 'coas_school_fk')->references(['id'])->on('schools')
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign(['live_class_id'], 'coas_live_class_fk')->references(['id'])->on('live_classes')
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign(['recorded_by_user_id'], 'coas_recorder_fk')->references(['id'])->on('users')
                    ->onDelete('restrict')->onUpdate('restrict');
            });
        }

        if (! Schema::hasTable(self::RECORD_TABLE)) {
            Schema::create(self::RECORD_TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->autoIncrement();
                $table->unsignedBigInteger('school_id');
                $table->unsignedBigInteger('attendance_session_id');
                $table->unsignedBigInteger('course_registration_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedTinyInteger('status')->default(0);
                $table->unsignedBigInteger('marked_by_user_id')->nullable();
                $table->dateTime('marked_at')->nullable();
                $table->timestamps();

                // One record per Course Registration per Attendance Session, so a
                // repeated bulk submission collides instead of duplicating.
                $table->unique(['school_id', 'attendance_session_id', 'course_registration_id'], 'coar_session_registration_unique');
                $table->index(['school_id', 'attendance_session_id'], 'coar_session_idx');
                $table->index(['school_id', 'student_id'], 'coar_student_idx');
                $table->index(['school_id', 'course_registration_id'], 'coar_registration_idx');

                $table->foreign(['school_id', 'attendance_session_id'], 'coar_session_tenant_fk')
                    ->references(['school_id', 'id'])->on(self::SESSION_TABLE)
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign(['school_id', 'course_registration_id'], 'coar_registration_tenant_fk')
                    ->references(['school_id', 'id'])->on('course_registrations')
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign(['student_id'], 'coar_student_fk')->references(['id'])->on('users')
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign(['marked_by_user_id'], 'coar_marker_fk')->references(['id'])->on('users')
                    ->onDelete('restrict')->onUpdate('restrict');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::RECORD_TABLE);
        Schema::dropIfExists(self::SESSION_TABLE);
        if ($this->indexExists('course_registrations', self::REGISTRATION_UNIQUE)) {
            Schema::table('course_registrations', function (Blueprint $table): void {
                $table->dropUnique(self::REGISTRATION_UNIQUE);
            });
        }
    }

    /**
     * Refuse to run against a drifted schema rather than creating keys over
     * columns of the wrong type. MySQL-only: SQLite has no information_schema
     * and the automated test environment is SQLite.
     */
    private function assertSchemaAssumptions(): void
    {
        if ($this->driver() !== 'mysql') {
            return;
        }

        foreach ([
            ['course_offerings', 'school_id'], ['course_offerings', 'id'],
            ['course_registrations', 'school_id'], ['course_registrations', 'id'],
            ['course_registrations', 'student_id'], ['course_registrations', 'course_offering_id'],
            ['live_classes', 'id'], ['schools', 'id'],
        ] as [$table, $column]) {
            $row = DB::selectOne('SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]);
            if (! $row || ! preg_match('/^bigint(?:\(\d+\))? unsigned$/i', $row->COLUMN_TYPE)) {
                throw new RuntimeException("Cannot create tenant-safe HEI attendance keys: {$table}.{$column} must be BIGINT UNSIGNED.");
            }
        }

        $this->assertIndex('course_offerings', 'course_offerings_school_id_id_unique', ['school_id', 'id'], true);
    }

    private function driver(): string
    {
        return (string) DB::connection()->getDriverName();
    }

    private function indexExists(string $table, string $name): bool
    {
        if ($this->driver() !== 'mysql') {
            try {
                return Schema::getConnection()->getDoctrineSchemaManager()->listsTableColumns($table)
                    && in_array($name, array_keys(Schema::getIndexes($table) ?? []), true);
            } catch (\Throwable $exception) {
                return false;
            }
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();
    }

    private function ensureUniqueIndex(string $table, string $name, array $columns): void
    {
        if ($this->indexExists($table, $name)) {
            $this->assertIndex($table, $name, $columns, true);

            return;
        }
        // Outside MySQL the introspection above is best-effort, so tolerate the
        // index already being present rather than aborting a safe migration.
        try {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
        } catch (\Throwable $exception) {
            if (! $this->isAlreadyExists($exception)) {
                throw $exception;
            }
        }
    }

    private function assertIndex(string $table, string $name, array $columns, bool $unique): void
    {
        if ($this->driver() !== 'mysql') {
            return;
        }
        $rows = DB::select('SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? ORDER BY SEQ_IN_INDEX', [$table, $name]);
        $actual = array_map(fn ($row) => $row->COLUMN_NAME, $rows);
        $isUnique = count($rows) > 0 && (int) $rows[0]->NON_UNIQUE === 0;
        if ($actual !== $columns || $isUnique !== $unique) {
            throw new RuntimeException("Existing index {$table}.{$name} does not match the required definition.");
        }
    }

    private function isAlreadyExists(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'already exists')
            || str_contains($message, 'duplicate key name')
            || str_contains($message, 'duplicate index');
    }
};
