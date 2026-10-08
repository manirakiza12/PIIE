<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Piie\Testing\PhpUnitInventory;
use RuntimeException;

require_once dirname(__DIR__, 2).'/scripts/support/PhpUnitInventory.php';

class PhpUnitInventoryVerifierTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(dirname(__DIR__).'/Fixtures/phpunit-inventory/'.$name.'.xml');
    }

    private function inventoryFixtureResult(?string $report = null, array $codes = []): array
    {
        return PhpUnitInventory::reconcile(PhpUnitInventory::discovery($this->fixture('phpunit9')), [$report ?? $this->fixture('junit')], $codes);
    }

    public function test_phpunit_9_and_namespaced_phpunit_11_parse_equivalent_datasets(): void
    {
        $legacy = PhpUnitInventory::discovery($this->fixture('phpunit9'));
        $modern = PhpUnitInventory::discovery($this->fixture('phpunit11'));
        $this->assertSame($legacy, $modern);
        $this->assertCount(4, $modern['expected']);
        $this->assertArrayHasKey('Fixture\\ExampleTest::testData#named # & case', $modern['expected']);
        $this->assertArrayHasKey('Fixture\\ExampleTest::testData#00', $modern['expected']);
        $result = PhpUnitInventory::reconcile($modern, [$this->fixture('junit')]);
        $this->assertTrue($result['successful']);
        $this->assertSame(4, $result['passed']);
        $this->assertSame(10, $result['assertions']);
    }

    public static function invalidDiscoveries(): array
    {
        return [
            'zero legacy' => ['<tests/>'],
            'zero modern' => ['<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests/></testSuite>'],
            'malformed' => ['<tests>'],
            'unknown root' => ['<inventory/>'],
            'unknown child' => ['<tests><wrong/></tests>'],
            'duplicate' => ['<tests><testCaseClass name="C"><testCaseMethod name="testA"/><testCaseMethod name="testA"/></testCaseClass></tests>'],
            'missing attribute' => ['<tests><testCaseClass name="C"><testCaseMethod/></testCaseClass></tests>'],
            'wrong modern ID' => ['<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests><testClass name="C"><testMethod name="testA" id="Other::testA"/></testClass></tests></testSuite>'],
            'invalid dataset' => ['<tests><testCaseClass name="C"><testCaseMethod name="testA" dataSet="unknown"/></testCaseClass></tests>'],
            'doctype' => ['<!DOCTYPE tests [<!ENTITY x SYSTEM "file:///must-not-read">]><tests/>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDiscoveries')]
    public function test_invalid_discovery_fails_closed(string $xml): void
    {
        $this->expectException(RuntimeException::class);
        PhpUnitInventory::discovery($xml);
    }

    public static function invalidExecutions(): array
    {
        return [
            'zero' => ['<testsuites/>'],
            'malformed' => ['<testsuites>'],
            'unknown' => ['<report/>'],
            'unknown outcome' => ['<testsuite><testcase class="C" name="testA" assertions="1"><success/></testcase></testsuite>'],
            'missing class' => ['<testsuite><testcase name="testA" assertions="1"/></testsuite>'],
            'invalid assertions' => ['<testsuite><testcase class="C" name="testA" assertions="oops"/></testsuite>'],
            'conflicting outcomes' => ['<testsuite><testcase class="C" name="testA" assertions="1"><failure/><skipped/></testcase></testsuite>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidExecutions')]
    public function test_invalid_execution_fails_closed(string $xml): void
    {
        $this->expectException(RuntimeException::class);
        $this->inventoryFixtureResult($xml);
    }

    public function test_duplicate_execution_fails(): void
    {
        $result = PhpUnitInventory::reconcile(PhpUnitInventory::discovery($this->fixture('phpunit9')), [$this->fixture('junit'), $this->fixture('junit')]);
        $this->assertFalse($result['successful']);
        $this->assertCount(4, $result['duplicates']);
    }

    public function test_missing_execution_fails(): void
    {
        $result = $this->inventoryFixtureResult(str_replace('<testcase class="Fixture\\ExampleTest" name="testPlain" assertions="1"/>', '', $this->fixture('junit')));
        $this->assertFalse($result['successful']);
        $this->assertSame(['Fixture\\ExampleTest::testPlain'], $result['missing']);
    }

    public function test_unexpected_execution_fails(): void
    {
        $result = $this->inventoryFixtureResult(str_replace('name="testPlain"', 'name="testUnexpected"', $this->fixture('junit')));
        $this->assertFalse($result['successful']);
        $this->assertSame(['Fixture\\ExampleTest::testUnexpected'], $result['unexpected']);
    }

    public static function outcomes(): array
    {
        return ['failure' => ['failure', 'failed', false], 'error' => ['error', 'errors', false], 'skip' => ['skipped', 'skipped', true], 'warning' => ['warning', 'warnings', false]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outcomes')]
    public function test_outcomes_are_preserved(string $tag, string $field, bool $success): void
    {
        $xml = str_replace('name="testPlain" assertions="1"/>', 'name="testPlain" assertions="1"><'.$tag.' message="reason">detail</'.$tag.'></testcase>', $this->fixture('junit'));
        $result = $this->inventoryFixtureResult($xml);
        $this->assertSame(1, $result[$field]);
        $this->assertSame(3, $result['passed']);
        $this->assertSame($success, $result['successful']);
        if ($tag === 'skipped') { $this->assertSame(['Fixture\\ExampleTest::testPlain' => 'reasondetail'], $result['skips']); }
    }

    public function test_nonzero_process_exit_cannot_be_masked_by_passing_xml(): void
    {
        $this->assertFalse($this->inventoryFixtureResult(null, [1])['successful']);
    }

    public function test_empty_named_dataset_is_a_distinct_valid_case(): void
    {
        $legacy = PhpUnitInventory::discovery('<tests><testCaseClass name="C"><testCaseMethod name="testA" dataSet="&quot;&quot;"/></testCaseClass></tests>');
        $modern = PhpUnitInventory::discovery('<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests><testClass name="C"><testMethod name="testA" id="C::testA#"/></testClass></tests></testSuite>');
        $this->assertSame($legacy, $modern);
        $result = PhpUnitInventory::reconcile($modern, ['<testsuite><testcase class="C" name="testA with data set &quot;&quot;" assertions="1"/></testsuite>']);
        $this->assertTrue($result['successful']);
    }

    public function test_empty_inventory_cannot_bypass_discovery_guard(): void
    {
        $this->expectException(RuntimeException::class);
        PhpUnitInventory::reconcile(['expected' => []], [$this->fixture('junit')]);
    }

    public function test_valid_complete_inventory_passes(): void
    {
        $result = $this->inventoryFixtureResult();
        $this->assertTrue($result['successful']);
        $this->assertSame($result['discovered'], $result['executed']);
        foreach (['failed', 'errors', 'warnings', 'skipped'] as $field) { $this->assertSame(0, $result[$field]); }
        foreach (['duplicates', 'missing', 'unexpected'] as $field) { $this->assertSame([], $result[$field]); }
    }
}
