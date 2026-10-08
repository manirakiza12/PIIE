<?php

// Deterministic fresh-process inventory verification; no database boot here.
require_once __DIR__.'/support/PhpUnitInventory.php';
use Piie\Testing\PhpUnitInventory;
$root = dirname(__DIR__);
chdir($root);
$out = $root . '/storage/logs/phase11-suite-' . date('Ymd-His');
if (file_exists($out)) {
    fwrite(STDERR, "Refusing to overwrite an existing inventory directory.\n");
    exit(2);
}
mkdir($out, 0777, true);
$php = [PHP_BINARY];
if (PHP_OS_FAMILY === 'Windows') {
    $php = array_merge($php, ['-d', 'extension=pdo_sqlite']);
}
$php = array_merge($php, ['-d', 'memory_limit=768M', 'vendor/phpunit/phpunit/phpunit']);
$run = function (array $arguments, string $log) use ($php): int {
    $process = proc_open(array_merge($php, $arguments), [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log . '.stderr', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot launch PHPUnit.'); }
    fclose($pipes[0]);
    return proc_close($process);
};
if ($run(['--list-tests-xml', $out . '/discovered.xml'], $out . '/discovery.log') !== 0) {
    throw new RuntimeException('Discovery failed.');
}
$inventory = PhpUnitInventory::discovery(file_get_contents($out . '/discovered.xml'));
$expected = $inventory['expected'];
$shards = []; $classes = []; $count = 0;
foreach ($inventory['classes'] as $name => $size) {
    if ($count && $count + $size > 450) {
        $shards[] = $classes; $classes = []; $count = 0;
    }
    $classes[] = $name; $count += $size;
}
if ($classes) { $shards[] = $classes; }
file_put_contents($out . '/manifest.json', json_encode(['discovered' => count($expected), 'shards' => $shards], JSON_PRETTY_PRINT));
$reports = []; $codes = [];
foreach ($shards as $index => $names) {
    $number = $index + 1;
    $filter = '/^(?:' . implode('|', array_map(fn ($name) => preg_quote($name, '/'), $names)) . ')::/';
    echo 'Running shard ' . $number . '/' . count($shards) . "\n";
    $codes[$number] = $run(['--fail-on-risky', '--order-by=default', '--filter', $filter, '--log-junit', $out . '/shard-' . $number . '.xml'], $out . '/shard-' . $number . '.log');
    if (!is_file($out . '/shard-' . $number . '.xml')) { throw new RuntimeException('Shard did not complete: ' . $number); }
    $reports[] = file_get_contents($out . '/shard-' . $number . '.xml');
    echo 'Completed shard ' . $number . ', exit ' . $codes[$number] . "\n";
}
$summary = PhpUnitInventory::reconcile($inventory, $reports, $codes);
$summary['risky'] = 0;
foreach (array_keys($codes) as $number) {
    $log = file_get_contents($out . '/shard-' . $number . '.log');
    if (preg_match('/Tests: [^\r\n]*Risky: (\d+)/', $log, $match)) {
        $summary['risky'] += (int) $match[1];
    }
}
$summary['successful'] = $summary['successful'] && $summary['risky'] === 0;
file_put_contents($out . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode(array_diff_key($summary, array_flip(['failures', 'error_details', 'warning_details', 'skips'])), JSON_PRETTY_PRINT) . "\n";
exit($summary['successful'] ? 0 : 1);
