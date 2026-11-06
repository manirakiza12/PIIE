<?php

namespace Tests\Feature\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * Regression matrix for deploy/scan-migrations.php.
 *
 * The deploy gate (deploy/check-migrations.sh) refuses a release when a new
 * migration deletes/truncates/drops/renames in up(). A line-oriented grep
 * missed a query builder chain that called ->delete() several lines after the
 * DB::table(...) that started it, so this test locks the token-based scanner
 * in place.
 *
 * It deliberately extends the framework TestCase without booting Laravel: the
 * scanner is a standalone script and must be testable without a database.
 */
class MigrationSafetyScannerTest extends TestCase
{
    public function test_scanner_detects_multiline_and_raw_destructive_operations(): void
    {
        $root = dirname(__DIR__, 3);
        $runner = $root.'/deploy/tests/scan-migrations-regression.php';

        $this->assertFileExists($runner, 'Scanner regression runner is missing.');

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open([PHP_BINARY, $runner], $descriptors, $pipes, $root);

        $this->assertIsResource($process, 'Could not start the scanner regression runner.');

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $output = trim($stdout."\n".$stderr);

        $this->assertSame(0, $exit, "Scanner regression failures:\n".$output);
        $this->assertStringContainsString('0 failed', $stdout);
        $this->assertStringNotContainsString('FAIL', $stdout);
    }

    public function test_current_payroll_migration_is_additive_only(): void
    {
        $root = dirname(__DIR__, 3);
        $migration = $root.'/database/migrations/2026_11_05_000001_enhance_payroll_module_tables.php';

        $this->assertFileExists($migration);
        $this->assertStringNotContainsString('->delete(', file_get_contents($migration));
        $this->assertStringNotContainsString('DELETE FROM', strtoupper(file_get_contents($migration)));
    }
}
