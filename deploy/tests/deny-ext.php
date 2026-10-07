#!/usr/bin/env php
<?php
/**
 * Extract the file extensions that a web-server config denies.
 *
 *   php deny-ext.php <config-file>
 *
 * Prints one extension per line, e.g.
 *   sql
 *   sql.gz
 *   dump
 *
 * WHY THIS IS A PHP SCRIPT AND NOT A GREP
 *
 * The deny rules are written as a single anchored alternation:
 *
 *     <FilesMatch "(?i)\.(sql|sql\.gz|dump|key|pem)$">
 *     location ~* \.(sql|sql\.gz|dump|key|pem)$ {
 *
 * So `log` and `key` are BARE alternatives -- there is no literal dot in front
 * of them. That breaks the obvious check in both directions:
 *
 *   grep -q '\.key'   FALSE NEGATIVE: `\.(...|key|...)` contains no `.key`
 *                      substring, so a genuinely correct config fails the test.
 *   grep -q 'key'     FALSE POSITIVE: passes on any unrelated mention of the
 *                      word (a comment, another rule, the word "monkey").
 *
 * Neither is acceptable for a security assertion, so this reads the group the
 * way the server does and splits it on the real delimiter. Both the Apache and
 * the Nginx rules share the shape `\.(a|b|c)$`, so one parser serves both.
 *
 * A member that itself contains an escaped dot (`sql\.gz`) is a genuine
 * two-part extension and is returned as `sql.gz`, not `sql`.
 */
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "usage: php deny-ext.php <config-file>\n");
    exit(2);
}

$path = $argv[1];
if (!is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "cannot read {$path}\n");
    exit(2);
}

$src = file_get_contents($path);
if ($src === false) {
    fwrite(STDERR, "cannot read {$path}\n");
    exit(2);
}

// Match `\.(a|b|c)$` -- literal backslash, literal dot, group, anchored end.
$re = '/\\\\\.\(([^)]*)\)\$/';

if (!preg_match_all($re, $src, $m)) {
    // Nothing matched. Exit 3 rather than 0: an empty deny-list must never be
    // mistaken for "no failures". A caller that loops over the result would
    // otherwise silently run zero assertions and report success.
    fwrite(STDERR, "no anchored extension deny-list found in {$path}\n");
    exit(3);
}

$out = [];
foreach ($m[1] as $group) {
    foreach (explode('|', $group) as $ext) {
        // `sql\.gz` -> `sql.gz`. Unescape only what the pattern escapes: dots
        // that carry meaning inside the regex.
        $ext = str_replace('\.', '.', trim($ext));
        if ($ext !== '') {
            $out[$ext] = true;
        }
    }
}

$list = array_keys($out);
sort($list);
echo implode("\n", $list), "\n";
