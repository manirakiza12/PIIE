#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Destructive-migration scanner.
 *
 * Reads migration files, finds the `up()` method and normalises its PHP
 * tokens (comments and whitespace removed, multi-line expressions joined)
 * before matching destructive operations. This is deliberately token based:
 * a line-oriented grep cannot see a query builder chain that calls
 * ->delete() several lines below the DB::table(...) that starts it.
 *
 * It also inspects string literals, so raw SQL passed to DB::statement(),
 * DB::unprepared() or DB::raw() is covered.
 *
 * Usage:
 *   php deploy/scan-migrations.php [--json] <migration.php> [more files...]
 *
 * Exit codes:
 *   0  no destructive operation found
 *   1  at least one destructive operation found
 *   2  usage or parse error (fail closed: the caller must not treat this as ok)
 *
 * This file is part of deploy/ and never ships to production (export-ignore).
 */

const SCAN_PATTERNS = [
    'query-builder delete' => '/->\s*delete\s*\(/i',
    'static destructive call' => '/::\s*(?:delete|destroy|forceDelete|truncate)\s*\(/i',
    'query-builder forceDelete' => '/->\s*forceDelete\s*\(/i',
    'query-builder truncate' => '/->\s*truncate\s*\(/i',
    'query-builder drop*' => '/->\s*drop[A-Za-z]*\s*\(/i',
    'query-builder rename*' => '/->\s*rename[A-Za-z]*\s*\(/i',
    'Schema::drop*' => '/Schema\s*::\s*drop[A-Za-z]*\s*\(/i',
    'Schema::rename' => '/Schema\s*::\s*rename\s*\(/i',
    'SQL DELETE FROM' => '/\bDELETE\s+FROM\b/i',
    'SQL TRUNCATE' => '/\bTRUNCATE\s+(?:TABLE\s+)?/i',
    'SQL DROP object' => '/\bDROP\s+(?:TABLE|COLUMN|DATABASE|INDEX|CONSTRAINT|VIEW|TRIGGER|PROCEDURE|FUNCTION|SCHEMA)\b/i',
    'SQL ALTER TABLE ... DROP' => '/\bALTER\s+TABLE\b[^;]{0,400}?\bDROP\b/i',
];

/**
 * Return the token index range [start, end) of a named method body.
 *
 * @param  array<int, array{0:int,1:string,2:int}|string>  $tokens
 * @return array{0:int,1:int}|null
 */
function findMethodBody(array $tokens, string $name): ?array
{
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }

        if (! is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || strtolower($tokens[$j][1]) !== $name) {
            continue;
        }

        // Skip the parameter list.
        while ($j < $count && $tokens[$j] !== '(') {
            $j++;
        }
        $depth = 0;
        for (; $j < $count; $j++) {
            if ($tokens[$j] === '(') {
                $depth++;
            } elseif ($tokens[$j] === ')') {
                $depth--;
                if ($depth === 0) {
                    $j++;
                    break;
                }
            }
        }

        // Skip a return type, if present, up to the body brace.
        while ($j < $count && $tokens[$j] !== '{') {
            $j++;
        }
        if ($j >= $count) {
            return null;
        }

        $start = $j + 1;
        $depth = 0;
        for ($k = $start; $k < $count; $k++) {
            if ($tokens[$k] === '{') {
                $depth++;
            } elseif ($tokens[$k] === '}') {
                if ($depth === 0) {
                    return [$start, $k];
                }
                $depth--;
            }
        }

        return null;
    }

    return null;
}

/**
 * Collapse a token range into one string and remember where each piece came
 * from, so a regex offset can be reported as a source line.
 *
 * @param  array<int, array{0:int,1:string,2:int}|string>  $tokens
 * @param  array{0:int,1:int}  $range
 * @return array{code:string,map:array<int,array{0:int,1:int}>}
 */
function normalise(array $tokens, array $range): array
{
    [$start, $end] = $range;

    $code = '';
    $map = [];
    $line = 0;

    for ($i = $start; $i < $end; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $line = $token[2];
            $map[] = [strlen($code), $line];
            $code .= $token[1];
        } else {
            $map[] = [strlen($code), $line];
            $code .= $token;
        }
    }

    return ['code' => $code, 'map' => $map];
}

/**
 * @param  array<int, array{0:int,1:int}>  $map
 */
function lineAtOffset(array $map, int $offset): int
{
    $line = 0;
    foreach ($map as [$position, $sourceLine]) {
        if ($position > $offset) {
            break;
        }
        $line = $sourceLine;
    }

    return $line;
}

/**
 * @return array{status:string,matches:array<int,array{label:string,line:int,context:string}>,error?:string}
 */
function scanFile(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        return ['status' => 'error', 'matches' => [], 'error' => 'file is missing or unreadable'];
    }

    $source = file_get_contents($path);
    if ($source === false) {
        return ['status' => 'error', 'matches' => [], 'error' => 'file could not be read'];
    }

    try {
        $tokens = token_get_all($source, TOKEN_PARSE);
    } catch (ParseError $e) {
        return ['status' => 'error', 'matches' => [], 'error' => 'PHP parse error: '.$e->getMessage()];
    }

    $range = findMethodBody($tokens, 'up');
    if ($range === null) {
        return ['status' => 'error', 'matches' => [], 'error' => 'up() method not found'];
    }

    // Helpers called by up() must not hide destructive operations. Scan all
    // code except down(); conservative findings require review, not bypass.
    $down = findMethodBody($tokens, 'down');
    if ($down !== null) {
        for ($i = $down[0]; $i < $down[1]; $i++) {
            $tokens[$i] = '';
        }
    }
    ['code' => $code, 'map' => $map] = normalise($tokens, [0, count($tokens)]);

    $matches = [];
    foreach (SCAN_PATTERNS as $label => $pattern) {
        if (! preg_match($pattern, $code, $match, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $offset = $match[0][1];
        $start = max(0, $offset - 60);
        $context = substr($code, $start, 160);
        $context = preg_replace('/\s+/', ' ', $context) ?? $context;

        $matches[] = [
            'label' => $label,
            'line' => lineAtOffset($map, $offset),
            'context' => trim($context),
        ];
    }

    return [
        'status' => $matches === [] ? 'ok' : 'destructive',
        'matches' => $matches,
    ];
}

$arguments = array_slice($argv, 1);
$json = false;

if ($arguments !== [] && $arguments[0] === '--json') {
    $json = true;
    array_shift($arguments);
}

if ($arguments === []) {
    fwrite(STDERR, "usage: scan-migrations.php [--json] <migration.php> [more files...]\n");
    exit(2);
}

$results = [];
$exitCode = 0;

foreach ($arguments as $path) {
    $result = scanFile($path);
    $result['path'] = $path;
    $results[] = $result;

    if ($result['status'] === 'error') {
        $exitCode = 2;
    } elseif ($result['status'] === 'destructive' && $exitCode === 0) {
        $exitCode = 1;
    }
}

if ($json) {
    echo json_encode(['destructive' => $exitCode === 1, 'exit' => $exitCode, 'files' => $results], JSON_PRETTY_PRINT), PHP_EOL;
    exit($exitCode);
}

foreach ($results as $result) {
    if ($result['status'] === 'error') {
        echo 'ERROR       ', $result['path'], ': ', $result['error'], PHP_EOL;
        continue;
    }

    if ($result['status'] === 'ok') {
        echo 'ok          ', $result['path'], PHP_EOL;
        continue;
    }

    echo 'DESTRUCTIVE? ', $result['path'], PHP_EOL;
    foreach ($result['matches'] as $match) {
        echo '  ', $match['label'], ' (line ', $match['line'], ')', PHP_EOL;
        echo '    ', $match['context'], PHP_EOL;
    }
}

exit($exitCode);
