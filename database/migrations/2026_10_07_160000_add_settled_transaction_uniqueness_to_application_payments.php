<?php

use App\Support\Payments\SettledPaymentIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Payment identity protection requires MySQL/MariaDB or SQLite.');
        }
        // HEX groups full opaque bytes, including case and trailing whitespace,
        // independently of the source table's case-insensitive/PAD SPACE collation.
        $duplicates = $connection->select('SELECT COUNT(*) AS duplicates FROM application_payments WHERE '
            . SettledPaymentIdentity::ELIGIBLE_SQL
            . ' GROUP BY LOWER(TRIM(method)), HEX(gateway_txn_id) HAVING COUNT(*) > 1 LIMIT 1');
        if ($duplicates) {
            throw new RuntimeException('Duplicate settled payment identities: run payments:identity-preflight and reconcile explicitly. No rows or identities were changed.');
        }
        $provider = SettledPaymentIdentity::PROVIDER_SQL;
        $transaction = SettledPaymentIdentity::TRANSACTION_SQL;
        $index = SettledPaymentIdentity::INDEX;
        if ($driver === 'mysql') {
            // One DDL statement: duplicates inserted after preflight still cause
            // index creation to fail. VARBINARY avoids PAD SPACE equality and
            // preserves up to 191 utf8mb4 characters (764 bytes), without hashes.
            $connection->statement("ALTER TABLE application_payments
                ADD COLUMN settled_provider VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS ($provider) STORED,
                ADD COLUMN settled_provider_txn_id VARBINARY(764) GENERATED ALWAYS AS ($transaction) STORED,
                ADD UNIQUE INDEX $index (settled_provider, settled_provider_txn_id)");
        } else {
            // SQLite cannot ALTER ADD a STORED column on populated tables.
            // VIRTUAL provides the same tested nullable unique semantics.
            $connection->transaction(function () use ($connection, $provider, $transaction, $index) {
                $connection->statement("ALTER TABLE application_payments ADD COLUMN settled_provider TEXT COLLATE BINARY GENERATED ALWAYS AS ($provider) VIRTUAL");
                $connection->statement("ALTER TABLE application_payments ADD COLUMN settled_provider_txn_id TEXT COLLATE BINARY GENERATED ALWAYS AS ($transaction) VIRTUAL");
                $connection->statement("CREATE UNIQUE INDEX $index ON application_payments (settled_provider, settled_provider_txn_id)");
            });
        }
    }

    public function down(): void
    {
        $connection = DB::connection();
        $index = SettledPaymentIdentity::INDEX;
        if ($connection->getDriverName() === 'mysql') {
            $connection->statement("ALTER TABLE application_payments DROP INDEX $index, DROP COLUMN settled_provider_txn_id, DROP COLUMN settled_provider");
        } elseif ($connection->getDriverName() === 'sqlite') {
            $connection->transaction(function () use ($connection, $index) {
                $connection->statement("DROP INDEX $index");
                $connection->statement('ALTER TABLE application_payments DROP COLUMN settled_provider_txn_id');
                $connection->statement('ALTER TABLE application_payments DROP COLUMN settled_provider');
            });
        } else {
            throw new RuntimeException('Unsupported payment identity protection driver.');
        }
    }
};
