<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $column = DB::select("SHOW COLUMNS FROM `programmes` WHERE Field = 'mode'")[0] ?? null;
        $type = $column->Type ?? '';
        if (! str_starts_with(strtolower($type), 'enum(')) {
            return;
        }

        $current = str_getcsv(substr($type, 5, -1), ',', "'", '\\');
        $values = array_values(array_unique(array_merge($current, ['Day', 'Evening', 'ODEL/Blended'])));
        if ($values === $current) {
            return;
        }

        $enum = implode(',', array_map(
            fn (string $value) => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'",
            $values,
        ));
        $default = $column->Default ?? 'fulltime';
        $escapedDefault = str_replace(["\\", "'"], ["\\\\", "\\'"], $default);

        DB::statement("ALTER TABLE `programmes` MODIFY `mode` ENUM({$enum}) NOT NULL DEFAULT '{$escapedDefault}'");
    }

    public function down(): void
    {
        // Keep enum values on rollback so rows using them remain valid.
    }
};
