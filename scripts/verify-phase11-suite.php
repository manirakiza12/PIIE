<?php

// Deterministic fresh-process inventory verification; no database boot here.
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
$inventory = simplexml_load_file($out . '/discovered.xml');
$expected = [];
$shards = []; $classes = []; $count = 0;
foreach ($inventory->testCaseClass as $class) {
    $name = (string) $class['name'];
    $size = count($class->testCaseMethod);
    if ($count && $count + $size > 450) {
        $shards[] = $classes; $classes = []; $count = 0;
    }
    $classes[] = $name; $count += $size;
    foreach ($class->testCaseMethod as $method) {
        $key = $name . '::' . (string) $method['name']
            . (isset($method['dataSet']) ? ' with data set ' . (string) $method['dataSet'] : '');
        if (isset($expected[$key])) { throw new RuntimeException('Duplicate discovered test: ' . $key); }
        $expected[$key] = true;
    }
}
if ($classes) { $shards[] = $classes; }
file_put_contents($out . '/manifest.json', json_encode(['discovered' => count($expected), 'shards' => $shards], JSON_PRETTY_PRINT));
$actual = []; $duplicates = []; $failures = []; $skips = [];
$assertions = 0; $codes = [];
foreach ($shards as $index => $names) {
    $number = $index + 1;
    $filter = '/^(?:' . implode('|', array_map(fn ($name) => preg_quote($name, '/'), $names)) . ')::/';
    echo 'Running shard ' . $number . '/' . count($shards) . "\n";
    $codes[$number] = $run(['--order-by=default', '--filter', $filter, '--log-junit', $out . '/shard-' . $number . '.xml'], $out . '/shard-' . $number . '.log');
    if (!is_file($out . '/shard-' . $number . '.xml')) { throw new RuntimeException('Shard did not complete: ' . $number); }
    $junit = simplexml_load_file($out . '/shard-' . $number . '.xml');
    foreach ($junit->xpath('//testcase') as $test) {
        $key = (string) $test['class'] . '::' . (string) $test['name'];
        if (isset($actual[$key])) { $duplicates[] = $key; }
        $actual[$key] = true;
        $assertions += (int) $test['assertions'];
        if (isset($test->failure) || isset($test->error) || isset($test->warning)) {
            $failures[$key] = (string) ($test->failure ?? $test->error ?? $test->warning);
        }
        if (isset($test->skipped)) { $skips[$key] = (string) $test->skipped; }
    }
    echo 'Completed shard ' . $number . ', exit ' . $codes[$number] . "\n";
}
$missing = array_keys(array_diff_key($expected, $actual));
$unexpected = array_keys(array_diff_key($actual, $expected));
$summary = ['discovered' => count($expected), 'executed' => count($actual), 'assertions' => $assertions,
    'passed' => count($actual) - count($failures) - count($skips), 'failed' => count($failures), 'skipped' => count($skips),
    'duplicates' => $duplicates, 'missing' => $missing, 'unexpected' => $unexpected,
    'shard_exit_codes' => $codes, 'failures' => $failures, 'skips' => $skips];
file_put_contents($out . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode(array_diff_key($summary, array_flip(['failures', 'skips'])), JSON_PRETTY_PRINT) . "\n";
exit($failures || $missing || $unexpected || $duplicates || array_filter($codes) ? 1 : 0);
