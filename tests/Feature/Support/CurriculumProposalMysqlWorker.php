<?php

// Two real PHP processes exercise the same service against CI's disposable MySQL.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = config('database.connections.mysql');
if (getenv('GITHUB_ACTIONS') !== 'true' || ! $app->environment('testing')
    || ($connection['database'] ?? '') !== 'ci_test' || ($connection['host'] ?? '') !== '127.0.0.1') {
    throw new RuntimeException('Concurrency worker refuses any non-CI database.');
}
config(['database.default' => 'mysql']);
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
