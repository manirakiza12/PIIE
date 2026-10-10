<?php

namespace PiieSandbox;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
require_once __DIR__.'/ConnectivitySettings.php';
require_once __DIR__.'/OutboundPolicy.php';

final class IsolatedConfiguration extends LoadConfiguration
{
    public static function overrides(array $connection, string $storage, string $origin): array
    {
        $url = parse_url($origin);
        if (($connection['driver'] ?? null) !== 'mysql' || ($connection['host'] ?? null) !== '127.0.0.1'
            || (int) ($connection['port'] ?? 0) !== 3307 || !preg_match('/\Apiie_sandbox_[a-f0-9]{16}\z/', $connection['database'] ?? '')
            || !preg_match('/\Apiie_sb_[a-f0-9]{16}\z/', $connection['username'] ?? '') || empty($connection['password'])
            || !empty($connection['url']) || !empty($connection['unix_socket']) || !is_array($url)
            || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['path'] ?? '', ['', '/'], true)
            || (($url['scheme'] ?? '') === 'http' && $origin !== 'http://127.0.0.1:8002')) {
            throw new \RuntimeException('Isolated sandbox configuration refused.');
        }
        return [
            'app.env' => 'local', 'app.debug' => false, 'app.url' => rtrim($origin, '/'),
            'app.bypass_subscription' => false,
            'database.default' => 'mysql', 'database.connections' => ['mysql' => $connection],
            'cache.default' => 'file', 'cache.prefix' => 'piie_sandbox',
            'cache.stores.file' => ['driver' => 'file', 'path' => $storage.'/framework/cache/data', 'lock_path' => $storage.'/framework/cache/data'],
            'session.driver' => 'file', 'session.files' => $storage.'/framework/sessions',
            'session.cookie' => 'piie_sandbox_session', 'session.domain' => null,
            'session.secure' => $url['scheme'] === 'https', 'session.same_site' => 'lax',
            'view.compiled' => $storage.'/framework/views', 'filesystems.default' => 'local',
            'filesystems.disks' => ['local' => ['driver' => 'local', 'root' => $storage.'/app', 'throw' => true]],
            'mail.default' => 'array', 'mail.mailers' => ['array' => ['transport' => 'array']],
            'queue.default' => 'sync', 'broadcasting.default' => 'null',
            'logging.default' => 'null', 'logging.deprecations.channel' => 'null',
            'logging.channels.emergency.path' => $storage.'/logs/emergency.log',
        ];
    }

    public function bootstrap(\Illuminate\Contracts\Foundation\Application $app)
    {
        parent::bootstrap($app);
        if (!$app->environment('local')) throw new \RuntimeException('Sandbox must use APP_ENV=local.');
        $settings=ConnectivitySettings::read($app->environmentPath(),$app['config']->get('app.url'));
        $app['config']->set(['app.url'=>$settings['origin'],'sandbox.connectivity'=>$settings]);
        $app['config']->set(self::overrides($app['config']->get('database.connections.mysql'), $app->storagePath(), $app['config']->get('app.url')));
        // Run before application providers can read SMTP/addon settings or connect.
        $app->booting(function () use ($app) {
            self::protectOutbound($app);
            \sandboxCapacity();
            $db = $app->make('db')->connection('mysql');
            $identity = $db->selectOne('SELECT @@port AS port, DATABASE() AS name, @@datadir AS dir');
            if ((int) $identity->port !== 3307 || $identity->name !== $app['config']->get('database.connections.mysql.database')
                || strtolower(rtrim(str_replace('\\', '/', $identity->dir), '/')) !== 'c:/piie-dev-db/data') {
                throw new \RuntimeException('Isolated database identity refused.');
            }
            self::verifyGrants($db->getPdo(), $identity->name, $app['config']->get('database.connections.mysql.username'));
        });
        $app->booted(function () use ($app) {
            if(is_file($app->environmentPath().'/checkout-grant.json')) {
                $app['config']->set(['sandbox.checkout.start_guard'=>[ControlledCheckout::class,'startGuard'],
                    'sandbox.checkout.reconcile_guard'=>[ControlledCheckout::class,'reconcileGuard']]);
            }
            $app['config']->set(['mail.default'=>'array','mail.mailers'=>['array'=>['transport'=>'array']]]);
        });
    }

    public static function verifyGrants(\PDO $pdo, string $database, string $username): void
    {
        $account = $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        if ($account !== $username.'@127.0.0.1') throw new \RuntimeException('Sandbox account identity refused.');
        $grants = $pdo->query('SHOW GRANTS')->fetchAll(\PDO::FETCH_COLUMN);
        $dml = false;
        foreach ($grants as $grant) {
            if (preg_match('/\AGRANT USAGE ON \*\.\* TO /', $grant) && !str_contains($grant, 'WITH GRANT OPTION')) continue;
            if (preg_match('/\AGRANT (.+) ON `'.preg_quote(str_replace('_', '\\_', $database), '/').'`\.\* TO /', $grant, $match)
                && !str_contains($grant, 'WITH GRANT OPTION')) {
                $permissions = explode(', ', $match[1]); sort($permissions);
                if ($permissions === ['DELETE', 'INSERT', 'SELECT', 'UPDATE']) { $dml = true; continue; }
            }
            throw new \RuntimeException('Sandbox account has unexpected privileges.');
        }
        if (!$dml) throw new \RuntimeException('Sandbox DML grant missing.');
    }

    public static function protectOutbound(Application $app): void
    {
        // A database SMTP override cannot defeat this event-level veto.
        $app['config']->set(['mail.default' => 'array', 'mail.mailers' => ['array' => ['transport' => 'array']]]);
        $app->make('events')->listen(\Illuminate\Mail\Events\MessageSending::class, fn () => false);
        // Default-off Laravel HTTP policy; explicit phases permit only pinned sandbox API calls.
        $settings=$app['config']->get('sandbox.connectivity',['registration'=>false,'transactions'=>false]);
        Http::globalMiddleware(OutboundPolicy::middleware($settings));
        if(is_file($app->environmentPath().'/checkout-grant.json')) Http::globalMiddleware(ControlledCheckout::transportMiddleware());
        URL::forceRootUrl($app['config']->get('app.url'));
        URL::forceScheme(parse_url($app['config']->get('app.url'), PHP_URL_SCHEME));
    }
}
