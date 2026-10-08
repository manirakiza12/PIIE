<?php

namespace Piie\Testing;

use DOMDocument;
use DOMElement;
use RuntimeException;

/** Strict, version-independent discovery / JUnit reconciliation. */
final class PhpUnitInventory
{
    private static function xml(string $text): DOMDocument
    {
        if (stripos($text, '<!DOCTYPE') !== false) {
            throw new RuntimeException('DOCTYPE is not allowed in test evidence.');
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new DOMDocument();
        $loaded = $document->loadXML($text, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $errors || ! $document->documentElement) {
            throw new RuntimeException('Malformed test evidence XML.');
        }
        return $document;
    }

    private static function children(DOMElement $element): array
    {
        return array_values(array_filter(iterator_to_array($element->childNodes), static fn ($node) => $node instanceof DOMElement));
    }

    private static function required(DOMElement $node, string $attribute): string
    {
        $value = $node->getAttribute($attribute);
        if ($value === '') {
            throw new RuntimeException('Missing '.$attribute.' on '.$node->tagName.'.');
        }
        return $value;
    }

    private static function dataset(string $value): string
    {
        if (preg_match('/^#(-?\d+)$/D', $value, $matches)) {
            return $matches[1];
        }
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            return substr($value, 1, -1);
        }
        throw new RuntimeException('Unrecognized dataset identifier: '.$value);
    }

    public static function discovery(string $text): array
    {
        $root = self::xml($text)->documentElement;
        $namespace = 'https://xml.phpunit.de/testSuite';
        $modern = $root->localName === 'testSuite' && $root->namespaceURI === $namespace;
        if ($modern) {
            $containers = [];
            foreach (self::children($root) as $child) {
                if ($child->namespaceURI !== $namespace || ! in_array($child->localName, ['tests', 'groups'], true)) {
                    throw new RuntimeException('Unrecognized PHPUnit 11 discovery structure.');
                }
                if ($child->localName === 'tests') { $containers[] = $child; }
            }
            if (count($containers) !== 1) { throw new RuntimeException('Expected one discovery tests element.'); }
            $classes = self::children($containers[0]);
        } elseif ($root->tagName === 'tests' && ! $root->namespaceURI) {
            $classes = self::children($root);
        } else {
            throw new RuntimeException('Unrecognized PHPUnit discovery XML.');
        }
        $expected = []; $sizes = [];
        foreach ($classes as $class) {
            if ($class->localName !== ($modern ? 'testClass' : 'testCaseClass')
                || (string) $class->namespaceURI !== ($modern ? $namespace : '')) {
                throw new RuntimeException('Unrecognized discovery class.');
            }
            $name = self::required($class, 'name');
            foreach (self::children($class) as $method) {
                if ($method->localName !== ($modern ? 'testMethod' : 'testCaseMethod')
                    || (string) $method->namespaceURI !== ($modern ? $namespace : '')) {
                    throw new RuntimeException('Unrecognized discovery method.');
                }
                $methodName = self::required($method, 'name');
                $base = $name.'::'.$methodName;
                $key = $base;
                if ($modern) {
                    $key = self::required($method, 'id');
                    if ($key !== $base && ! str_starts_with($key, $base.'#')) {
                        throw new RuntimeException('Discovery method ID does not match its class/name.');
                    }
                } elseif ($method->hasAttribute('dataSet')) {
                    $key .= '#'.self::dataset($method->getAttribute('dataSet'));
                }
                if (isset($expected[$key])) { throw new RuntimeException('Duplicate discovered test: '.$key); }
                $expected[$key] = true;
                $sizes[$name] = ($sizes[$name] ?? 0) + 1;
            }
        }
        if (! $expected) { throw new RuntimeException('Zero discovered tests.'); }
        return ['expected' => $expected, 'classes' => $sizes];
    }

    public static function reconcile(array $discovery, array $reports, array $codes = []): array
    {
        if (empty($discovery['expected'])) { throw new RuntimeException('Zero discovered tests.'); }
        $actual = []; $duplicates = []; $failures = []; $errors = []; $warnings = []; $skips = [];
        $assertions = 0;
        foreach ($reports as $text) {
            $document = self::xml($text);
            $root = $document->documentElement;
            if (! in_array($root->tagName, ['testsuites', 'testsuite'], true) || $root->namespaceURI) {
                throw new RuntimeException('Unrecognized JUnit execution XML.');
            }
            foreach ($document->getElementsByTagName('*') as $element) {
                if (! in_array($element->tagName, ['testsuites', 'testsuite', 'testcase', 'failure', 'error', 'warning', 'skipped', 'system-out', 'system-err', 'properties', 'property'], true)) {
                    throw new RuntimeException('Unrecognized JUnit element: '.$element->tagName);
                }
            }
            foreach ($document->getElementsByTagName('testcase') as $test) {
                if (! $test->parentNode instanceof DOMElement || $test->parentNode->tagName !== 'testsuite') {
                    throw new RuntimeException('Testcase must belong to a testsuite.');
                }
                $class = self::required($test, 'class');
                $name = self::required($test, 'name');
                $display = $class.'::'.$name;
                if (! preg_match('/^([^\s]+?)(?: with data set (.*))?$/sD', $name, $parts)) {
                    throw new RuntimeException('Unrecognized executed test name.');
                }
                $key = $class.'::'.$parts[1].(isset($parts[2]) ? '#'.self::dataset($parts[2]) : '');
                $count = $test->getAttribute('assertions');
                if (! preg_match('/^\d+$/D', $count)) { throw new RuntimeException('Invalid assertion count.'); }
                $outcomes = array_values(array_filter(self::children($test), static fn ($node) => in_array($node->tagName, ['failure', 'error', 'warning', 'skipped'], true)));
                if (count($outcomes) > 1) { throw new RuntimeException('Conflicting execution outcomes.'); }
                if (isset($actual[$key])) { $duplicates[] = $display; continue; }
                $actual[$key] = true;
                $assertions += (int) $count;
                if ($outcomes) {
                    $outcome = $outcomes[0];
                    $message = $outcome->getAttribute('message').$outcome->textContent;
                    switch ($outcome->tagName) {
                        case 'failure': $failures[$display] = $message; break;
                        case 'error': $errors[$display] = $message; break;
                        case 'warning': $warnings[$display] = $message; break;
                        case 'skipped': $skips[$display] = $message; break;
                    }
                }
            }
        }
        if (! $actual) { throw new RuntimeException('Zero executed tests.'); }
        $missing = array_keys(array_diff_key($discovery['expected'], $actual));
        $unexpected = array_keys(array_diff_key($actual, $discovery['expected']));
        $summary = ['discovered' => count($discovery['expected']), 'executed' => count($actual), 'assertions' => $assertions,
            'passed' => count($actual) - count($failures) - count($errors) - count($warnings) - count($skips),
            'failed' => count($failures), 'errors' => count($errors), 'warnings' => count($warnings), 'skipped' => count($skips),
            'duplicates' => $duplicates, 'missing' => $missing, 'unexpected' => $unexpected,
            'shard_exit_codes' => $codes, 'failures' => $failures, 'error_details' => $errors, 'warning_details' => $warnings, 'skips' => $skips];
        $summary['successful'] = ! ($failures || $errors || $warnings || $missing || $unexpected || $duplicates || array_filter($codes));
        return $summary;
    }
}
