<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'personal_access_tokens';
    private const INDEX = 'personal_access_tokens_expires_at_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('The original personal_access_tokens migration must run first.');
        }
        $indexed = $this->hasExpiryIndex();
        if (! Schema::hasColumn(self::TABLE, 'expires_at')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->timestamp('expires_at')->nullable()->default(null);
            });
        }
        if (! $indexed) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('expires_at', self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        $indexed = $this->hasExpiryIndex();
        if (! Schema::hasColumn(self::TABLE, 'expires_at')) {
            return;
        }
        // Application rollback normally retains this additive column. Never
        // discard populated expiry metadata when explicitly rolling back DDL.
        if (DB::table(self::TABLE)->whereNotNull('expires_at')->exists()) {
            throw new RuntimeException('Retain expires_at: tokens contain expiry metadata.');
        }
        if ($indexed) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });
    }

    private function hasExpiryIndex(): bool
    {
        $connection = DB::connection();
        switch ($connection->getDriverName()) {
            case 'mysql':
                $rows = $connection->select(
                    'SELECT COLUMN_NAME AS column_name, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX',
                    [$connection->getDatabaseName(), $connection->getTablePrefix().self::TABLE, self::INDEX]
                );
                break;
            case 'sqlite':
                $rows = [];
                foreach ($connection->select("PRAGMA index_list('personal_access_tokens')") as $index) {
                    if ($index->name === self::INDEX) {
                        foreach ($connection->select("PRAGMA index_info('personal_access_tokens_expires_at_index')") as $column) {
                            $rows[] = (object) ['column_name' => $column->name, 'non_unique' => 1 - $index->unique];
                        }
                    }
                }
                break;
            default:
                throw new RuntimeException('Expiry index verification supports MySQL and SQLite only.');
        }
        if (! $rows) {
            return false;
        }
        if (count($rows) !== 1 || $rows[0]->column_name !== 'expires_at' || (int) $rows[0]->non_unique !== 1) {
            throw new RuntimeException('Unexpected personal_access_tokens expiry index definition.');
        }
        return true;
    }
};
