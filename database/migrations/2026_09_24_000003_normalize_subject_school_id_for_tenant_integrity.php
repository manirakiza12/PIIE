<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CODE_INDEX = 'subjects_school_id_code_unique';
    private const TENANT_KEY_INDEX = 'subjects_school_id_id_unique';

    public function up(): void
    {
        $column = DB::selectOne(
            "SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'subjects' AND column_name = 'school_id'"
        );

        if (! $column || ! preg_match('/^int(?:\(\d+\))?$/i', $column->column_type) || $column->is_nullable !== 'NO') {
            throw new RuntimeException('Expected subjects.school_id to be signed INT NOT NULL before normalization.');
        }

        $invalid = DB::table('subjects')
            ->whereNull('school_id')
            ->orWhere('school_id', '<=', 0)
            ->exists();

        if ($invalid) {
            throw new RuntimeException('Subject tenant values must be positive and fit BIGINT UNSIGNED before normalization.');
        }

        if (DB::table('subjects as s')->leftJoin('schools as h', 'h.id', '=', 's.school_id')->whereNull('h.id')->exists()) {
            throw new RuntimeException('Subject tenant values must reference an existing School before normalization.');
        }

        $codeIndex = $this->indexColumns(self::CODE_INDEX);
        if ($codeIndex !== ['school_id', 'code'] || ! $this->indexIsUnique(self::CODE_INDEX)) {
            throw new RuntimeException('Expected unique subjects_school_id_code_unique(school_id, code) before normalization.');
        }

        if ($this->indexColumns(self::TENANT_KEY_INDEX) !== []) {
            throw new RuntimeException('The target Subject tenant composite index already exists; refusing to alter an unexpected schema.');
        }

        // Explicit MySQL DDL avoids relying on Laravel change() / DBAL behavior.
        // MySQL rebuilds the affected index as needed; the code index is not dropped,
        // so its order, uniqueness, NULL behavior, and collation remain unchanged.
        DB::statement('ALTER TABLE `subjects` MODIFY `school_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('subjects', function ($table): void {
            $table->unique(['school_id', 'id'], self::TENANT_KEY_INDEX);
        });
    }

    public function down(): void
    {
        $column = DB::selectOne(
            "SELECT COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'subjects' AND column_name = 'school_id'"
        );

        if (! $column || ! preg_match('/^bigint(?:\(\d+\))? unsigned$/i', $column->column_type) || $column->is_nullable !== 'NO') {
            throw new RuntimeException('Expected subjects.school_id to be BIGINT UNSIGNED NOT NULL before rollback.');
        }

        $outsideLegacyRange = DB::table('subjects')
            ->whereNull('school_id')
            ->orWhere('school_id', '>', 2147483647)
            ->exists();

        if ($outsideLegacyRange) {
            throw new RuntimeException('Cannot roll back subjects.school_id: one or more values do not fit signed INT.');
        }

        $codeIndex = $this->indexColumns(self::CODE_INDEX);
        if ($codeIndex !== ['school_id', 'code'] || ! $this->indexIsUnique(self::CODE_INDEX)) {
            throw new RuntimeException('Cannot roll back: the original Subject code unique index is missing or has changed.');
        }

        if ($this->indexColumns(self::TENANT_KEY_INDEX) !== ['school_id', 'id'] || ! $this->indexIsUnique(self::TENANT_KEY_INDEX)) {
            throw new RuntimeException('Cannot roll back: expected Subject tenant composite unique index is missing or has changed.');
        }

        Schema::table('subjects', function ($table): void {
            $table->dropUnique(self::TENANT_KEY_INDEX);
        });

        // Narrow only after proving every value remains representable.
        DB::statement('ALTER TABLE `subjects` MODIFY `school_id` INT NOT NULL');
    }

    /** @return list<string> */
    private function indexColumns(string $indexName): array
    {
        return array_map(
            fn ($row) => $row->column_name,
            DB::select(
                'SELECT COLUMN_NAME AS column_name FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
                 ORDER BY seq_in_index',
                ['subjects', $indexName]
            )
        );
    }

    private function indexIsUnique(string $indexName): bool
    {
        $row = DB::selectOne(
            'SELECT MIN(non_unique) AS non_unique, MAX(non_unique) AS max_non_unique
             FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            ['subjects', $indexName]
        );

        return $row !== null && (int) $row->non_unique === 0 && (int) $row->max_non_unique === 0;
    }
};
