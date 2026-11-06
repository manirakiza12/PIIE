#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Standalone regression matrix for deploy/scan-migrations.php.
 *
 * Runs on any PHP 8.1+ binary (the local development PHP can be older than
 * the application runtime), so the scanner can be verified without booting
 * Laravel or PHPUnit:
 *
 *   php deploy/tests/scan-migrations-regression.php
 *
 * tests/Feature/Deploy/MigrationSafetyScannerTest.php calls this same runner
 * inside the normal CI PHPUnit suite, so the two cannot drift apart.
 *
 * Exit 0 = every case matched, 1 = at least one mismatch, 2 = environment error.
 */

$root = dirname(__DIR__, 2);
$scanner = $root.'/deploy/scan-migrations.php';
$fixtures = $root.'/tests/Fixtures/migrations';

if (! is_file($scanner)) {
    fwrite(STDERR, "scanner not found: $scanner\n");
    exit(2);
}

if (! is_dir($fixtures)) {
    fwrite(STDERR, "fixtures not found: $fixtures\n");
    exit(2);
}

/**
 * Each case: fixture file, expected exit code, substring that must appear.
 */
$cases = [
    ['destructive_helper.php', 1, 'query-builder delete'],
    ['destructive_static.php', 1, 'static destructive call'],
    ['destructive_chained_multiline_delete.php', 1, 'DESTRUCTIVE?'],
    ['destructive_raw_sql_delete.php', 1, 'SQL DELETE FROM'],
    ['destructive_multiline_raw_sql.php', 1, 'ALTER TABLE'],
    ['destructive_drop_column.php', 1, 'drop*'],
    ['destructive_schema_drop.php', 1, 'Schema::drop*'],
    ['destructive_rename.php', 1, 'rename*'],
    ['safe_additive_columns.php', 0, 'ok'],
    ['safe_down_only.php', 0, 'ok'],
    ['safe_comment_mentions.php', 0, 'ok'],
    ['safe_update_rows.php', 0, 'ok'],
    ['invalid_missing_up.php', 2, 'up() method not found'],
];

$failures = 0;
$passes = 0;

foreach ($cases as [$fixture, $expectedExit, $expectedText]) {
    $path = $fixtures.'/'.$fixture;

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open([PHP_BINARY, $scanner, $path], $descriptors, $pipes, $root);

    if (! is_resource($process)) {
        fwrite(STDERR, "FAIL  $fixture: could not start the scanner\n");
        $failures++;
        continue;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    $output = $stdout.$stderr;

    if ($exit !== $expectedExit) {
        fwrite(STDERR, "FAIL  $fixture: expected exit $expectedExit, got $exit\n");
        fwrite(STDERR, "      output: ".trim(preg_replace('/\s+/', ' ', $output))."\n");
        $failures++;
        continue;
    }

    if (! str_contains($output, $expectedText)) {
        fwrite(STDERR, "FAIL  $fixture: output does not contain \"$expectedText\"\n");
        fwrite(STDERR, "      output: ".trim(preg_replace('/\s+/', ' ', $output))."\n");
        $failures++;
        continue;
    }

    echo "PASS  $fixture (exit $exit)\n";
    $passes++;
}

printf("\n%d passed, %d failed\n", $passes, $failures);

exit($failures === 0 ? 0 : 1);
