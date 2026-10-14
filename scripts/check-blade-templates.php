<?php

// This file lives in scripts/, so the application root is one level up.
$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';
$app = require_once $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/**
 * Compile a Blade view and lint the result.
 *
 * A view with an unbalanced @if/@endif renders a ParseError on the FIRST request
 * that uses it, which in a feature suite reads as a 500 with no pointer to the
 * template at all. Counting `@if` by hand is unreliable - the directives appear
 * inside strings, inside comments, and several to a line - so this asks Blade to
 * compile and then asks PHP to parse what came out.
 */

$views = [
    'resources/views/teacher/course_offerings/assignments/form.blade.php',
    'resources/views/teacher/course_offerings/content/lesson_form.blade.php',
];

$failed = 0;

foreach ($views as $view) {
    $full = $root.'/'.$view;

    if (! is_file($full)) {
        echo "  SKIP  {$view}\n";

        continue;
    }

    try {
        $compiled = Illuminate\Support\Facades\Blade::compileString(file_get_contents($full));
    } catch (Throwable $e) {
        echo "  BLADE {$view}\n        ".$e->getMessage()."\n";
        $failed++;

        continue;
    }

    // Blade's own leftover directives would parse as PHP and confuse the lint,
    // so they are stripped - they are a separate failure from a broken @if.
    $compiled = preg_replace('/@end(if|foreach|forelse|php|unless|isset|empty|switch|for|while)\b/', '', $compiled);

    $tmp = tempnam(sys_get_temp_dir(), 'bladechk').'.php';
    file_put_contents($tmp, $compiled);

    $result = trim((string) shell_exec('php -l '.escapeshellarg($tmp).' 2>&1'));
    unlink($tmp);

    if ($result === '' || str_contains($result, 'No syntax errors')) {
        echo "  OK    {$view}\n";

        continue;
    }

    $failed++;
    echo "  PARSE {$view}\n";

    foreach (array_slice(explode("\n", $result), 0, 4) as $line) {
        echo '        '.trim($line)."\n";
    }
}

exit($failed > 0 ? 1 : 0);
