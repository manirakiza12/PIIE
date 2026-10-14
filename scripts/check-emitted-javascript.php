<?php

/**
 * Parse the JavaScript this Blade view EMITS, and fail loudly if it does not.
 *
 * WHY THIS EXISTS AT ALL
 *
 * A view can be structurally perfect - balanced tags, compiling Blade, passing
 * every feature test that asserts on markup - and still emit a `<script>` the
 * browser refuses to run. That is exactly what happened to the lesson form: the
 * Summernote bootstrap was removed from it, taking the
 * `document.addEventListener(...)` and `jQuery(function () {...})` openers with
 * it and leaving three orphaned closing braces behind. Blade does not care,
 * because Blade only resolves its own directives; the feature tests did not
 * catch it, because they assert on markup and never execute JavaScript.
 *
 * The symptom users saw was "Continue to the content does nothing" - and, worse,
 * the unsaved-work guard and the preview hand-off silently dead as well.
 *
 * So the only check that catches this class of fault is parsing the output. Node
 * is used when it is present; without it the check reports that it was SKIPPED
 * rather than quietly passing, because a check that silently does nothing is the
 * same failure a second time.
 *
 * Usage:  php scripts/check-emitted-javascript.php
 *         php scripts/check-emitted-javascript.php --verbose
 */

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$views = [
    'resources/views/teacher/course_offerings/content/lesson_form.blade.php',
    'resources/views/teacher/course_offerings/assignments/form.blade.php',
    'resources/views/teacher/course_offerings/assignments/submission.blade.php',
    'resources/views/teacher/course_offerings/assignments/questions.blade.php',
    'resources/views/student/course_assignments/show.blade.php',
];

$verbose = in_array('--verbose', $argv, true);

$node = null;
foreach (['node', 'nodejs'] as $candidate) {
    $path = trim((string) shell_exec('where '.$candidate.' 2>nul'));
    if ($path !== '') {
        $node = $path;

        break;
    }
}

$extracted = 0;
$failed = 0;

foreach ($views as $view) {
    $full = $root.'/'.$view;

    if (! is_file($full)) {
        echo '  SKIP  '.$view." (not found)\n";

        continue;
    }

    $source = file_get_contents($full);

    // Blade first: @json, @if and friends become real JavaScript only after
    // compilation, and compiling the raw file would choke on the directives.
    try {
        $compiled = Illuminate\Support\Facades\Blade::compileString($source);
    } catch (Throwable $e) {
        echo '  BLADE '.$view.': '.$e->getMessage()."\n";
        $failed++;

        continue;
    }

    // Pull out every inline <script> body that has no src. An external script is
    // already on disk and is not this view's responsibility.
    if (! preg_match_all('/<script(?![^>]*\ssrc=)[^>]*>(.*?)<\/script>/is', $compiled, $matches)) {
        echo '  NONE  '.$view."\n";

        continue;
    }

    foreach ($matches[1] as $index => $body) {
        // Blade compiles @json into a PHP echo. That is correct - it is resolved
        // when the page is rendered - but it is not JavaScript, so it has to be
        // stood in for before the output can be parsed. Substituting an empty
        // array is enough to answer the only question being asked here, which is
        // whether the surrounding braces and statements balance.
        $body = preg_replace(
            '/<\?php echo json_encode\(.*?\) \?>/s',
            '[]',
            $body
        );

        $extracted++;

        if ($node === null) {
            echo '  SKIP  '.$view.' script #'.($index + 1)." (no node)\n";

            continue;
        }

        // Strip a line-offset banner so node's error points at the view line.
        $file = tempnam(sys_get_temp_dir(), 'piie-js').'.js';
        file_put_contents($file, $body);

        $result = trim((string) shell_exec(
            escapeshellarg($node).' --check '.escapeshellarg($file).' 2>&1'
        ));

        unlink($file);

        if ($result === '') {
            echo '  OK    '.$view.' script #'.($index + 1)."\n";

            continue;
        }

        $failed++;
        echo '  FAIL  '.$view.' script #'.($index + 1)."\n";

        foreach (array_slice(explode("\n", $result), 0, 6) as $line) {
            echo '        '.trim($line)."\n";
        }
    }
}

echo "\n";
echo 'scripts checked: '.$extracted."\n";
echo 'failures       : '.$failed."\n";

if ($node === null) {
    echo "\nNODE NOT FOUND - every check was SKIPPED, not passed.\n";
    echo "Install Node, or this script proves nothing.\n";

    exit(2);
}

exit($failed > 0 ? 1 : 0);
