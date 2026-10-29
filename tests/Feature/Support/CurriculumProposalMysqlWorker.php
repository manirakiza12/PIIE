<?php

// Refuse before loading providers, which can themselves query a database.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Concurrency worker refuses any non-CI environment.');
}
// Two real PHP processes exercise the same service against CI's disposable MySQL.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\BootProviders::class, function ($app): void {
    $connection = $app['config']->get('database.connections.mysql');
    if (! $app->environment('testing') || ($connection['database'] ?? '') !== 'ci_test'
        || ($connection['host'] ?? '') !== '127.0.0.1' || ($connection['username'] ?? '') !== 'ci_user'
        || (int) ($connection['port'] ?? 0) !== 3306) {
        throw new RuntimeException('Concurrency worker refuses any non-CI database.');
    }
    $app['config']->set(['database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.unix_socket' => '']);
});
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$actor = App\Models\User::findOrFail((int) $argv[2]);
$service = $app->make(App\Support\Curriculum\CurriculumAssignmentProposals::class);
echo "READY\n";
flush();
try {
    $result = match ($argv[1]) {
        'apply' => $service->apply($actor, (int) $argv[3], true),
        'propose' => $service->propose($actor, (int) $argv[3], 'Registrar reviewed ordinary entry and absence of transfer/credit claims.'),
        'approve-mapping' => $service->approveMapping($actor, (int) $argv[3]),
        default => throw new RuntimeException('Unknown test operation'),
    };
    echo json_encode(['result' => $result], JSON_THROW_ON_ERROR)."\n";
} catch (App\Support\Curriculum\CurriculumProposalBlocked $e) {
    echo json_encode(['blocked' => $e->reasonCode], JSON_THROW_ON_ERROR)."\n";
}
