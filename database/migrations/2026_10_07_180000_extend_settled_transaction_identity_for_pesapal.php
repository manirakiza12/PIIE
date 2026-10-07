<?php

use App\Support\Payments\SettledPaymentIdentity;
use App\Support\Payments\SettledPaymentIdentityV2;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceIdentity(SettledPaymentIdentityV2::class);
    }

    public function down(): void
    {
        $this->replaceIdentity(SettledPaymentIdentity::class);
    }

    private function replaceIdentity(string $contract): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Payment identity protection requires MySQL/MariaDB or SQLite.');
        }
        $provider = $contract::PROVIDER_SQL;
        $transaction = $contract::TRANSACTION_SQL;
        $index = SettledPaymentIdentity::INDEX;
        $replace = function () use ($connection, $driver, $contract, $provider, $transaction, $index) {
            $duplicates = $connection->select('SELECT COUNT(*) AS duplicates FROM application_payments WHERE '
                . $contract::ELIGIBLE_SQL
                . ' GROUP BY LOWER(TRIM(method)), HEX(gateway_txn_id) HAVING COUNT(*) > 1 LIMIT 1');
            if ($duplicates) {
                throw new RuntimeException('Duplicate settled payment identities: run payments:identity-preflight and reconcile explicitly. No rows or identities were changed.');
            }
            if ($driver === 'mysql') {
                // One ALTER: index rebuild and expressions succeed together. A
                // duplicate arriving after preflight still fails the index build.
                $connection->statement("ALTER TABLE application_payments
                    DROP INDEX $index,
                    MODIFY COLUMN settled_provider VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS ($provider) STORED,
                    MODIFY COLUMN settled_provider_txn_id VARBINARY(764) GENERATED ALWAYS AS ($transaction) STORED,
                    ADD UNIQUE INDEX $index (settled_provider, settled_provider_txn_id)");
            } else {
                // SQLite cannot modify generated expressions. Transactional DDL
                // restores the old columns/index on any replacement failure.
                $connection->statement("DROP INDEX $index");
                $connection->statement('ALTER TABLE application_payments DROP COLUMN settled_provider_txn_id');
                $connection->statement('ALTER TABLE application_payments DROP COLUMN settled_provider');
                $connection->statement("ALTER TABLE application_payments ADD COLUMN settled_provider TEXT COLLATE BINARY GENERATED ALWAYS AS ($provider) VIRTUAL");
                $connection->statement("ALTER TABLE application_payments ADD COLUMN settled_provider_txn_id TEXT COLLATE BINARY GENERATED ALWAYS AS ($transaction) VIRTUAL");
                $connection->statement("CREATE UNIQUE INDEX $index ON application_payments (settled_provider, settled_provider_txn_id)");
            }
        };
        if ($driver === 'sqlite') {
            $connection->transaction($replace);
        } else {
            $replace();
        }
    }
};
