<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Executes the existing helper body with an in-memory database boundary; no network connection exists. */
class LocalAdminAccessRecoveryTest extends TestCase
{
    private Application $app;
    private $connection;
    private $manager;
    private string $directory;
    private string $source;
    private string $output = '';
    private array $baseline;
    private const NEW_PASSWORD = 'synthetic-new-password-only';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->app = new Application($root);
        $this->app->instance('config', new Repository([
            'app' => ['key' => 'base64:'.base64_encode(random_bytes(32)), 'cipher' => 'AES-256-CBC'],
            'auth' => require $root.'/config/auth.php',
            'hashing' => require $root.'/config/hashing.php',
            'database' => ['default' => 'mysql', 'connections' => ['mysql' => [
                'driver' => 'mysql', 'host' => 'localhost', 'port' => 3307, 'database' => 'piie_main',
            ]]],
        ]));
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->app);
        foreach ([\Illuminate\Events\EventServiceProvider::class, \Illuminate\Hashing\HashServiceProvider::class,
            \Illuminate\Encryption\EncryptionServiceProvider::class, \Illuminate\Auth\AuthServiceProvider::class] as $provider) {
            $this->app->register($provider);
        }
        $this->connection = new class(new \PDO('sqlite::memory:'), ':memory:') extends SQLiteConnection {
            public array $identity = ['port' => 3307, 'db' => 'piie_main', 'datadir' => 'C:/piie-dev-db/data/', 'general_log' => 0, 'slow_query_log' => 0];
            public bool $failCommit = false;
            public function selectOne($query, $bindings = [], $useReadPdo = true) {
                return str_starts_with($query, 'SELECT @@port') ? (object) $this->identity : parent::selectOne($query, $bindings, $useReadPdo);
            }
            public function statement($query, $bindings = []) {
                return str_starts_with($query, 'SET SESSION TRANSACTION') ? true : parent::statement($query, $bindings);
            }
            public function commit() {
                if ($this->failCommit) { throw new \RuntimeException('synthetic commit failure'); }
                parent::commit();
            }
        };
        $this->manager = new class($this->connection) implements ConnectionResolverInterface {
            public int $calls = 0;
            public function __construct(private $db) {}
            public function connection($name = null) { $this->calls++; return $this->db; }
            public function getDefaultConnection() { return 'mysql'; }
            public function setDefaultConnection($name) {}
        };
        $this->app->instance('db', $this->manager);
        Model::setConnectionResolver($this->manager); Model::setEventDispatcher($this->app['events']);
        $this->connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password TEXT, role_id INTEGER, school_id INTEGER, account_status TEXT, staff_status TEXT, menu_permission TEXT, remember_token TEXT, created_at TEXT, updated_at TEXT)');
        foreach ([1, 2, 3] as $id) {
            $this->connection->table('users')->insert(['id' => $id, 'email' => 'fixture-'.$id.'@example.invalid',
                'password' => $this->app['hash']->make('synthetic-old-password-'.$id), 'role_id' => $id,
                'school_id' => 1, 'account_status' => 'active', 'staff_status' => 'active',
                'menu_permission' => 'fixture-permissions', 'remember_token' => 'synthetic-remember-value',
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        }
        $this->baseline = $this->rows();
        $this->directory = sys_get_temp_dir().'/recovery-fixture-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->source = file_get_contents($root.'/scripts/local-admin-access-recovery.php');
    }

    protected function tearDown(): void
    {
        $root = realpath(sys_get_temp_dir());
        $path = realpath($this->directory);
        if (! $root || ! $path || dirname($path) !== $root || ! preg_match('/^recovery-fixture-[a-f0-9]{16}$/', basename($path))) {
            throw new \RuntimeException('Fixture cleanup boundary refused');
        }
        foreach (new \DirectoryIterator($path) as $file) {
            if (! $file->isDot()) { if (! $file->isFile() || $file->isLink()) { throw new \RuntimeException('Unexpected fixture entry'); } unlink($file->getPathname()); }
        }
        rmdir($path);
        Model::unsetEventDispatcher(); Facade::clearResolvedInstances(); Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    private function rows(): array { return $this->connection->table('users')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(); }

    private function runHelper(array $input, bool $approved = true): array
    {
        // Only bootstrap/transport/path/exit are substituted. All guards, transactions,
        // encryption, account selection and reset/rollback statements are the actual script.
        $fixtureApp = new class($this->app) implements \ArrayAccess {
            public function __construct(private $app) {}
            public function bootstrapWith($bootstrappers) {}
            public function register($provider) { return new class { public function boot() {} }; }
            public function offsetExists(mixed $offset): bool { return isset($this->app[$offset]); }
            public function offsetGet(mixed $offset): mixed { return $this->app[$offset]; }
            public function offsetSet(mixed $offset, mixed $value): void { $this->app[$offset] = $value; }
            public function offsetUnset(mixed $offset): void { unset($this->app[$offset]); }
        };
        $fixtureJson = json_encode($input, JSON_THROW_ON_ERROR); $fixtureDirectory = $this->directory;
        $forbiddenValues = [self::NEW_PASSWORD, 'synthetic-old-password', 'synthetic-remember-value', $input['email'] ?? 'never-a-real-identifier'];
        $argv = ['helper', $approved ? '--approved-write' : '--not-approved'];
        $body = substr($this->source, strpos($this->source, '$connection = null;'));
        foreach (["\$app = require 'bootstrap/app.php';" => '$app = $fixtureApp;',
            'stream_get_contents(STDIN)' => '$fixtureJson',
            "base_path('local-reports/admin-access-recovery')" => '$fixtureDirectory',
            "exit(\$reply['ok'] ? 0 : 1);" => 'return $reply;'] as $old => $new) {
            $this->assertSame(1, substr_count($body, $old)); $body = str_replace($old, $new, $body);
        }
        ob_start();
        try { $reply = eval($body); } finally { $this->output = ob_get_clean(); }
        foreach ($forbiddenValues as $secret) {
            $this->assertTrue(! str_contains($this->output, $secret), 'Output contains forbidden fixture data');
        }
        foreach (array_merge($this->baseline, $this->rows()) as $row) { $this->assertTrue(! str_contains($this->output, $row['password']), 'Output contains a hash'); }
        $this->assertSame([], $this->connection->getQueryLog());
        return $reply;
    }

    public static function roles(): array { return [[1], [2]]; }

    #[DataProvider('roles')]
    public function test_reset_and_encrypted_rollback_change_only_selected_password(int $role): void
    {
        $inspect = $this->runHelper(['mode' => 'inspect', 'email' => 'fixture-'.$role.'@example.invalid'], false);
        $this->assertTrue($inspect['ok']); $this->assertSame($role, $inspect['role']); $this->assertTrue($this->rows() === $this->baseline);
        $result = $this->runHelper(['mode' => 'reset', 'email' => 'fixture-'.$role.'@example.invalid', 'confirmed_role' => $role, 'password' => self::NEW_PASSWORD]);
        $this->assertTrue($result['ok']); $after = $this->rows();
        foreach ($after as $index => $row) {
            $before = $this->baseline[$index];
            if ($row['id'] === $role) { $this->assertTrue($this->app['hash']->check(self::NEW_PASSWORD, $row['password'])); $row['password'] = $before['password']; }
            $this->assertTrue($row === $before, 'An unrelated account field changed');
        }
        $ciphertext = file_get_contents($result['rollback_file']);
        $this->assertTrue(! str_contains($this->output, $ciphertext), 'Output contains rollback ciphertext');
        $this->assertTrue(! str_contains($ciphertext, $this->baseline[$role - 1]['password']));
        $this->assertTrue(! str_contains($ciphertext, $after[$role - 1]['password']));
        $envelope = json_decode($this->app['encrypter']->decryptString($ciphertext), true);
        $this->assertTrue($envelope['previous_hash'] === $this->baseline[$role - 1]['password']);
        $rollback = $this->runHelper(['mode' => 'rollback', 'email' => 'fixture-'.$role.'@example.invalid', 'confirmed_role' => $role, 'rollback_file' => $result['rollback_file']]);
        $this->assertTrue($rollback['ok']); $this->assertTrue($this->rows() === $this->baseline);
    }

    public function test_rollback_refuses_later_password_change(): void
    {
        $reset = $this->runHelper(['mode' => 'reset', 'email' => 'fixture-1@example.invalid', 'confirmed_role' => 1, 'password' => self::NEW_PASSWORD]);
        $this->assertTrue($reset['ok']);
        $this->connection->table('users')->where('id', 1)->update(['password' => $this->app['hash']->make('synthetic-subsequent-password')]);
        $before = $this->rows();
        $reply = $this->runHelper(['mode' => 'rollback', 'email' => 'fixture-1@example.invalid', 'confirmed_role' => 1, 'rollback_file' => $reset['rollback_file']]);
        $this->assertFalse($reply['ok']); $this->assertTrue($before === $this->rows());
    }

    public static function unsafeTargets(): array { return [['host', 'production.example.invalid'], ['host', '127.0.0.1'], ['port', 3306], ['database', 'production'], ['database', 'wrong_fixture'], ['driver', 'pgsql']]; }
    #[DataProvider('unsafeTargets')]
    public function test_configuration_guard_refuses_before_database_access(string $field, mixed $value): void
    {
        config(['database.connections.mysql.'.$field => $value]); $this->manager->calls = 0;
        $reply = $this->runHelper(['mode' => 'reset', 'email' => 'fixture-1@example.invalid', 'confirmed_role' => 1, 'password' => self::NEW_PASSWORD]);
        $this->assertFalse($reply['ok']); $this->assertSame(0, $this->manager->calls); $this->assertTrue($this->rows() === $this->baseline);
    }

    public static function unsafeServers(): array { return [['port', 3306], ['db', 'wrong'], ['datadir', '/production/data/'], ['general_log', 1], ['slow_query_log', 1]]; }
    #[DataProvider('unsafeServers')]
    public function test_server_identity_and_logging_guards(string $field, mixed $value): void
    {
        $this->connection->identity[$field] = $value;
        $reply = $this->runHelper(['mode' => 'reset', 'email' => 'fixture-1@example.invalid', 'confirmed_role' => 1, 'password' => self::NEW_PASSWORD]);
        $this->assertFalse($reply['ok']); $this->assertTrue($this->rows() === $this->baseline);
    }

    public function test_role_confirmation_and_explicit_approval_are_required(): void
    {
        foreach ([[1, 2, true], [3, 3, true], [1, 1, false]] as [$account, $role, $approved]) {
            $reply = $this->runHelper(['mode' => 'reset', 'email' => 'fixture-'.$account.'@example.invalid', 'confirmed_role' => $role, 'password' => self::NEW_PASSWORD], $approved);
            $this->assertFalse($reply['ok']); $this->assertTrue($this->rows() === $this->baseline);
        }
    }

    public function test_commit_failure_rolls_back_and_reports_failure(): void
    {
        $this->connection->failCommit = true;
        $reply = $this->runHelper(['mode' => 'reset', 'email' => 'fixture-1@example.invalid', 'confirmed_role' => 1, 'password' => self::NEW_PASSWORD]);
        $this->assertFalse($reply['ok']); $this->assertTrue($this->rows() === $this->baseline);
    }

    public function test_disabled_accounts_and_missing_rollback_directory_refuse_writes(): void
    {
        $this->connection->table('users')->where('id', 1)->update(['account_status' => 'disable']);
        $before = $this->rows();
        $reply = $this->runHelper(['mode' => 'reset', 'email' => 'fixture-1@example.invalid', 'confirmed_role' => 1, 'password' => self::NEW_PASSWORD]);
        $this->assertFalse($reply['ok']); $this->assertTrue($this->rows() === $before);
        $this->connection->table('users')->where('id', 1)->update(['account_status' => 'active']);
        rmdir($this->directory);
        try {
            $reply = $this->runHelper(['mode' => 'reset', 'email' => 'fixture-1@example.invalid', 'confirmed_role' => 1, 'password' => self::NEW_PASSWORD]);
            $this->assertFalse($reply['ok']); $this->assertTrue($this->rows() === $this->baseline);
        } finally { mkdir($this->directory, 0700); }
    }

    public function test_wrong_account_and_tampered_rollback_are_rejected(): void
    {
        $reset = $this->runHelper(['mode' => 'reset', 'email' => 'fixture-1@example.invalid', 'confirmed_role' => 1, 'password' => self::NEW_PASSWORD]);
        $this->assertTrue($reset['ok']); $before = $this->rows();
        $reply = $this->runHelper(['mode' => 'rollback', 'email' => 'fixture-2@example.invalid', 'confirmed_role' => 2, 'rollback_file' => $reset['rollback_file']]);
        $this->assertFalse($reply['ok']); $this->assertTrue($before === $this->rows());
        file_put_contents($reset['rollback_file'], 'invalid-encrypted-fixture');
        $reply = $this->runHelper(['mode' => 'rollback', 'email' => 'fixture-1@example.invalid', 'confirmed_role' => 1, 'rollback_file' => $reset['rollback_file']]);
        $this->assertFalse($reply['ok']); $this->assertTrue($before === $this->rows());
    }
}
