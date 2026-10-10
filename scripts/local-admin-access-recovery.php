<?php

// CLI-only helper for the private PowerShell operator workflow. No web route.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');
chdir(dirname(__DIR__));
require 'vendor/autoload.php';
$connection = null;
$reply = ['ok' => false, 'message' => 'Recovery stopped; no successful change confirmed.'];
try {
    $app = require 'bootstrap/app.php';
    $app->bootstrapWith([
        Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        Illuminate\Foundation\Bootstrap\RegisterFacades::class,
    ]);
    $app->register(Illuminate\Events\EventServiceProvider::class);
    $app->register(Illuminate\Database\DatabaseServiceProvider::class)->boot();
    $app->register(Illuminate\Hashing\HashServiceProvider::class);
    $app->register(Illuminate\Encryption\EncryptionServiceProvider::class);
    $app->register(Illuminate\Auth\AuthServiceProvider::class);
    $settings = config('database.connections.'.config('database.default'));
    if (($settings['driver'] ?? null) !== 'mysql' || $settings['host'] !== 'localhost'
        || (int) $settings['port'] !== 3307 || $settings['database'] !== 'piie_main'
        || config('auth.guards.web.provider') !== 'users'
        || config('auth.providers.users.model') !== App\Models\User::class) {
        throw new RuntimeException();
    }
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $mode = $input['mode'] ?? 'inspect';
    if (! in_array($mode, ['inspect', 'reset', 'rollback'], true)
        || ($mode !== 'inspect' && ($argv[1] ?? '') !== '--approved-write')) { throw new RuntimeException(); }
    $connection = $app['db']->connection();
    $server = $connection->selectOne('SELECT @@port AS port, @@datadir AS datadir, DATABASE() AS db, @@general_log AS general_log, @@slow_query_log AS slow_query_log');
    if ((int) $server->port !== 3307 || $server->db !== 'piie_main'
        || ! str_contains(strtolower(str_replace('\\', '/', $server->datadir)), '/piie-dev-db/')) { throw new RuntimeException(); }
    if ($mode !== 'inspect' && ($server->general_log || $server->slow_query_log)) { throw new RuntimeException(); }
    $connection->statement($mode === 'inspect' ? 'SET SESSION TRANSACTION READ ONLY' : 'SET SESSION TRANSACTION READ WRITE');
    $connection->beginTransaction();
    $provider = $app['auth']->createUserProvider('users');
    $user = $provider->retrieveByCredentials(['email' => trim((string) ($input['email'] ?? ''))]);
    if (! $user || ! in_array((int) $user->role_id, [1, 2], true)
        || $user->account_status === 'disable' || $user->isStaffPortalBlocked()) { throw new RuntimeException(); }
    $role = (int) $user->role_id;
    if ($mode === 'inspect') {
        $reply = ['ok' => true, 'role' => $role, 'message' => $role === 1 ? 'Existing enabled Superadmin selected.' : 'Existing enabled School Administrator selected.'];
    } else {
        if ((int) ($input['confirmed_role'] ?? 0) !== $role) { throw new RuntimeException(); }
        $locked = $connection->table('users')->where('id', $user->id)->lockForUpdate()->first();
        if (! $locked || (int) $locked->role_id !== $role || $locked->email !== $user->email
            || $locked->account_status === 'disable' || App\Support\Staff\StaffStatus::blocksPortal($locked->staff_status)) { throw new RuntimeException(); }
        $directory = base_path('local-reports/admin-access-recovery');
        if ($mode === 'reset') {
            $password = $input['password'] ?? '';
            if (! is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72) { throw new RuntimeException(); }
            $replacement = $app['hash']->make($password);
            // Write encrypted rollback material BEFORE changing the database.
            // The operator wrapper creates this directory with a private ACL.
            if (! is_dir($directory)) { throw new RuntimeException(); }
            $path = $directory.'/'.bin2hex(random_bytes(16)).'.encrypted';
            $envelope = $app['encrypter']->encryptString(json_encode([
                'database' => 'piie_main', 'port' => 3307, 'user_id' => (int) $locked->id,
                'role_id' => $role, 'previous_hash' => $locked->password,
                'replacement_hash' => $replacement, 'created_at' => date(DATE_ATOM),
            ], JSON_THROW_ON_ERROR));
            $handle = fopen($path, 'x');
            if (! $handle) { throw new RuntimeException(); }
            try { if (fwrite($handle, $envelope) !== strlen($envelope) || ! fflush($handle)) { throw new RuntimeException(); } }
            finally { fclose($handle); }
        } else {
            $path = realpath((string) ($input['rollback_file'] ?? ''));
            $root = realpath($directory);
            if (! $path || ! $root || ! str_starts_with(strtolower($path), strtolower($root).DIRECTORY_SEPARATOR)
                || pathinfo($path, PATHINFO_EXTENSION) !== 'encrypted') { throw new RuntimeException(); }
            $saved = json_decode($app['encrypter']->decryptString(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);
            if ($saved['database'] !== 'piie_main' || $saved['port'] !== 3307 || $saved['user_id'] !== (int) $locked->id
                || $saved['role_id'] !== $role || ! hash_equals($saved['replacement_hash'], $locked->password)) { throw new RuntimeException(); }
            $replacement = $saved['previous_hash'];
        }
        $affected = $connection->table('users')->where('id', $locked->id)->where('role_id', $role)
            ->where('password', $locked->password)->update(['password' => $replacement]);
        if ($affected !== 1) { throw new RuntimeException(); }
        if ($mode === 'reset' && ! $provider->validateCredentials($provider->retrieveById($locked->id), ['password' => $password])) {
            throw new RuntimeException();
        }
        $reply = ['ok' => true, 'role' => $role, 'message' => $mode === 'reset' ? 'Selected password updated and provider verification passed.' : 'Selected prior password hash restored.', 'rollback_file' => $path];
    }
    $connection->commit();
} catch (Throwable $exception) {
    $reply = ['ok' => false, 'message' => 'Recovery stopped; no successful change confirmed.'];
    try { if ($connection && $connection->transactionLevel()) { $connection->rollBack(); } }
    catch (Throwable $rollbackException) { /* Outcome is ambiguous; retain the generic failure response. */ }
    // Never echo exceptions, SQL bindings, account identifiers or credential data.
} finally {
    unset($input, $password, $replacement, $locked, $user, $saved, $envelope);
}
echo json_encode($reply, JSON_THROW_ON_ERROR).PHP_EOL;
exit($reply['ok'] ? 0 : 1);
