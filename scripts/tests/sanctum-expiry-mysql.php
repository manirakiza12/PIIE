<?php

// Run only in the disposable, internal-network MySQL container environment.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

if (getenv('L2_DISPOSABLE_MYSQL') !== '1' || getenv('DB_HOST') !== 'mysql-l2'
    || getenv('DB_DATABASE') !== 'piie_l2_disposable') {
    throw new RuntimeException('Refusing any database outside the disposable L2 fixture.');
}
$capsule = new Manager();
$capsule->addConnection([
    'driver' => 'mysql', 'host' => 'mysql-l2', 'port' => 3306,
    'database' => 'piie_l2_disposable', 'username' => 'l2_test',
    'password' => getenv('DB_PASSWORD'), 'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00',
]);
$capsule->setAsGlobal();
$container = $capsule->getContainer();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($container);
$check = static function (bool $condition, string $label): void {
    if (! $condition) { throw new RuntimeException('FAIL: '.$label); }
    echo 'PASS: '.$label."\n";
};
$version = DB::selectOne('SELECT VERSION() AS version')->version;
$check(str_starts_with($version, '8.0.'), 'MySQL version '.$version);
$check(! Schema::hasTable('personal_access_tokens') && ! Schema::hasTable('migrations'), 'empty disposable database');
$repository = new DatabaseMigrationRepository($capsule->getDatabaseManager(), 'migrations');
$repository->createRepository();
$migrator = new Migrator($repository, $capsule->getDatabaseManager(), new Filesystem());
$root = dirname(__DIR__, 2);
$original = $root.'/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php';
$additive = $root.'/database/migrations/2026_10_08_000001_add_expires_at_to_personal_access_tokens.php';
$migrator->run([$original]);
$check(! Schema::hasColumn('personal_access_tokens', 'expires_at'), 'old Sanctum schema');
$id = DB::table('personal_access_tokens')->insertGetId([
    'tokenable_type' => 'App\\Models\\User', 'tokenable_id' => 42,
    'name' => 'historical', 'token' => hash('sha256', 'disposable-fixture'),
    'abilities' => '["profile:read"]', 'last_used_at' => '2020-01-02 03:04:05',
    'created_at' => '2019-01-01 00:00:00', 'updated_at' => '2020-01-02 03:04:05',
]);
$before = (array) DB::table('personal_access_tokens')->find($id);
$verify = static function () use ($before, $id, $check): void {
    $row = (array) DB::table('personal_access_tokens')->find($id);
    $check(array_key_exists('expires_at', $row) && $row['expires_at'] === null, 'historical expires_at NULL');
    unset($row['expires_at']);
    $check($row === $before, 'every historical field unchanged');
    $columns = DB::select('SELECT DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', ['piie_l2_disposable', 'personal_access_tokens', 'expires_at']);
    $check(count($columns) === 1 && $columns[0]->DATA_TYPE === 'timestamp' && $columns[0]->IS_NULLABLE === 'YES' && $columns[0]->COLUMN_DEFAULT === null, 'nullable timestamp default NULL');
    $index = DB::select('SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?', ['piie_l2_disposable', 'personal_access_tokens', 'personal_access_tokens_expires_at_index']);
    $check(count($index) === 1 && $index[0]->COLUMN_NAME === 'expires_at' && (int) $index[0]->NON_UNIQUE === 1, 'named nonunique expires_at index');
};
$migrator->run([$additive]);
$verify();
$check(count($repository->getRan()) === 2, 'original and additive migrations recorded');
(require $additive)->up();
$verify();
$check(count($repository->getRan()) === 2, 'idempotent UP does not change migration history');
$migrator->rollback([$original, $additive], ['step' => 1]);
$check(! Schema::hasColumn('personal_access_tokens', 'expires_at'), 'disposable NULL-only DOWN');
$check((array) DB::table('personal_access_tokens')->find($id) === $before, 'DOWN preserves token row');
$check(count($repository->getRan()) === 1, 'DOWN removes only additive history entry');
$migrator->run([$additive]);
$verify();
$check(count($repository->getRan()) === 2, 're-UP recorded');
DB::table('personal_access_tokens')->where('id', $id)->update(['expires_at' => '2030-01-01 00:00:00']);
try { (require $additive)->down(); throw new LogicException('Populated expiry was discarded.'); }
catch (RuntimeException $exception) {
    $check(str_contains($exception->getMessage(), 'expiry metadata'), 'populated expiry blocks DOWN');
}
$check(DB::table('personal_access_tokens')->find($id)->expires_at === '2030-01-01 00:00:00', 'populated expiry retained');
echo "Disposable MySQL Sanctum migration verification complete.\n";
