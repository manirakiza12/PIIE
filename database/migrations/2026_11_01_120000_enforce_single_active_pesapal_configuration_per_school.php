<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A school must never have two active PesaPal rows.
 *
 * Application code already refuses to choose between them, but only the
 * database can stop two concurrent first-time saves from both committing an
 * active row. Inactive rows are intentionally unconstrained: replacing
 * credentials keeps the old row for historical reconciliation.
 *
 * The migration fails closed if duplicates already exist. It never merges,
 * deletes, or rewrites a configuration; an operator must resolve them.
 */
return new class extends Migration
{
    private const INDEX = 'payment_methods_single_active_pesapal_unique';
    private const COLUMN = 'active_pesapal_school_id';

    public function up(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Single active PesaPal configuration requires MySQL/MariaDB or SQLite.');
        }
        if (! Schema::hasTable('payment_methods')) {
            throw new RuntimeException('payment_methods is missing; PesaPal configuration cannot be protected.');
        }
        $duplicates = $connection->select(
            "SELECT school_id FROM payment_methods WHERE name = 'pesapal' AND status = 1 AND school_id IS NOT NULL "
            . 'GROUP BY school_id HAVING COUNT(*) > 1 LIMIT 1'
        );
        if ($duplicates) {
            throw new RuntimeException('Duplicate active PesaPal configurations exist. Resolve them explicitly; no rows were changed.');
        }
        if ($driver === 'mysql') {
            // MySQL has no partial unique index. The generated column is NULL
            // for every row that is not an active PesaPal configuration, and a
            // unique index treats NULLs as distinct.
            $connection->statement(
                'ALTER TABLE payment_methods ADD COLUMN ' . self::COLUMN . " BIGINT GENERATED ALWAYS AS (CASE WHEN name = 'pesapal' AND status = 1 THEN school_id ELSE NULL END) STORED, "
                . 'ADD UNIQUE INDEX ' . self::INDEX . ' (' . self::COLUMN . ')'
            );
        } else {
            // SQLite supports a partial unique index directly. NULL school_id
            // rows remain unconstrained, matching the application query.
            $connection->statement('CREATE UNIQUE INDEX ' . self::INDEX
                . " ON payment_methods (school_id) WHERE name = 'pesapal' AND status = 1");
        }
    }

    public function down(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if ($driver === 'mysql') {
            $connection->statement('ALTER TABLE payment_methods DROP INDEX ' . self::INDEX . ', DROP COLUMN ' . self::COLUMN);
        } elseif ($driver === 'sqlite') {
            $connection->statement('DROP INDEX ' . self::INDEX);
        } else {
            throw new RuntimeException('Unsupported PesaPal configuration protection driver.');
        }
    }
};
