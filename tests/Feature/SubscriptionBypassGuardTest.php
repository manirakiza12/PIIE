<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The development subscription bypass must fail closed: it is allowed only when
 * the flag is on, APP_ENV is "local", the default connection is MySQL on a
 * loopback host at port 3307, and the live server reports port 3307.
 */
class SubscriptionBypassGuardTest extends TestCase
{
    private function arrange(bool $flag, string $env, string $host = '127.0.0.1', int $port = 3307, string $driver = 'mysql'): void
    {
        config([
            'app.bypass_subscription' => $flag,
            'database.default' => 'mysql',
            'database.connections.mysql.driver' => $driver,
            'database.connections.mysql.host' => $host,
            'database.connections.mysql.port' => $port,
        ]);
        $this->app['env'] = $env;
    }

    private function livePort(int $port, string $datadir = 'C:\\piie-dev-db\\data\\'): void
    {
        DB::shouldReceive('selectOne')->andReturn((object) ['p' => $port, 'd' => $datadir]);
    }

    public function test_bypass_is_off_by_default(): void
    {
        // Assert the code default, independent of whatever the local .env sets.
        $this->assertStringContainsString(
            "env('BYPASS_SUBSCRIPTION', false)",
            file_get_contents(config_path('app.php'))
        );
    }

    public function test_bypass_allowed_only_when_every_condition_holds(): void
    {
        $this->arrange(true, 'local');
        $this->livePort(3307);

        $this->assertTrue((new AdminController())->subscriptionBypassPermitted());
    }

    public function test_local_environment_alone_no_longer_bypasses(): void
    {
        $this->arrange(false, 'local');
        $this->livePort(3307);

        $this->assertFalse((new AdminController())->subscriptionBypassPermitted());
    }

    public function test_flag_is_ignored_outside_the_local_environment(): void
    {
        foreach (['production', 'Development', 'staging', 'testing'] as $env) {
            $this->arrange(true, $env);
            $this->livePort(3307);
            $this->assertFalse((new AdminController())->subscriptionBypassPermitted(), $env);
        }
    }

    public function test_flag_is_ignored_on_port_3306_or_a_remote_host(): void
    {
        $this->arrange(true, 'local', '127.0.0.1', 3306);
        $this->livePort(3306);
        $this->assertFalse((new AdminController())->subscriptionBypassPermitted());

        $this->arrange(true, 'local', 'db.example.com', 3307);
        $this->livePort(3307);
        $this->assertFalse((new AdminController())->subscriptionBypassPermitted());
    }

    public function test_configured_port_cannot_override_the_live_server_port(): void
    {
        $this->arrange(true, 'local');
        $this->livePort(3306);

        $this->assertFalse((new AdminController())->subscriptionBypassPermitted());
    }

    public function test_bypass_fails_closed_when_the_database_cannot_be_queried(): void
    {
        $this->arrange(true, 'local');
        DB::shouldReceive('selectOne')->andThrow(new \RuntimeException('no database'));

        $this->assertFalse((new AdminController())->subscriptionBypassPermitted());
    }

    public function test_non_mysql_driver_never_bypasses(): void
    {
        $this->arrange(true, 'local', '127.0.0.1', 3307, 'sqlite');
        $this->livePort(3307);

        $this->assertFalse((new AdminController())->subscriptionBypassPermitted());
    }

    public function test_a_port_3307_server_outside_the_isolated_datadir_never_bypasses(): void
    {
        foreach (['C:\\ProgramData\\MariaDB\\data\\', '/var/lib/mysql/', '', 'C:\\piie-dev-db-backup\\data\\'] as $datadir) {
            $this->arrange(true, 'local');
            $this->livePort(3307, $datadir);
            $this->assertFalse((new AdminController())->subscriptionBypassPermitted(), $datadir);
        }
    }

    public function test_the_isolated_datadir_is_matched_regardless_of_slash_style_and_case(): void
    {
        foreach (['C:\\piie-dev-db\\data\\', 'c:/PIIE-DEV-DB/data/'] as $datadir) {
            $this->arrange(true, 'local');
            $this->livePort(3307, $datadir);
            $this->assertTrue((new AdminController())->subscriptionBypassPermitted(), $datadir);
        }
    }
}
