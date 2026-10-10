<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        // Isolate before provider boot: SMTP/settings providers may query the
        // database during bootstrap, before individual tests arrange fixtures.
        $app->beforeBootstrapping(\Illuminate\Foundation\Bootstrap\BootProviders::class, function ($app) {
            if ($app->environment('testing')) {
                $app['config']->set([
                    'database.default'=>'sqlite',
                    'database.connections.sqlite.database'=>':memory:',
                    'database.connections.mysql.database'=>'__piie_phpunit_blocked__',
                ]);
                \Illuminate\Support\Facades\DB::purge('mysql');
                \Illuminate\Support\Facades\DB::purge('sqlite');
            }
        });

        $app->make(Kernel::class)->bootstrap();

        // PHPUnit must never inherit the developer's live MySQL connection.
        // Keep normal browser/Artisan development unchanged; this applies
        // only to the application instance created by the test runner.
        if ($app->environment('testing')) {
            config([
                'database.default' => 'sqlite',
                'database.connections.sqlite.database' => ':memory:',
                // Explicit mysql usage must fail against a harmless database
                // name instead of ever reaching piie_main.
                'database.connections.mysql.database' => '__piie_phpunit_blocked__',
            ]);

            \Illuminate\Support\Facades\DB::purge('sqlite');
            \Illuminate\Support\Facades\DB::reconnect('sqlite');
            \Illuminate\Support\Facades\DB::purge('mysql');
        }

        return $app;
    }
}
