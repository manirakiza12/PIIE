<?php
/**
 * Compiles and lints EVERY Blade template the public site can render.
 *
 * `php artisan view:cache` compiles each file in isolation and reports success, but
 * a template with unbalanced `@if`/`@foreach` compiles to PHP that is only a syntax
 * error once PHP parses it. That is exactly how `/website/contact-us` reached a 500
 * with "unexpected token endforeach" while `view:cache` reported success, so this
 * script does the missing second step: `php -l` on the compiled output.
 *
 * Run: php scripts/lint-blade.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$compiler = app('blade.compiler');
$root = dirname(__DIR__).'/resources/views';

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

$checked = 0;
$failed = [];

foreach ($iterator as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
        continue;
    }

    $path = $file->getPathname();
    $source = (string) file_get_contents($path);

    try {
        $compiled = $compiler->compileString($source);
    } catch (Throwable $e) {
        $failed[] = [$path, 'COMPILE: '.$e->getMessage()];

        continue;
    }

    $tmp = sys_get_temp_dir().'/blade-'.md5($path).'.php';
    file_put_contents($tmp, $compiled);

    $output = [];
    $exit = 0;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $output, $exit);
    @unlink($tmp);

    $checked++;

    if ($exit !== 0) {
        $failed[] = [$path, 'LINT: '.trim(implode(' ', array_slice($output, 0, 2)))];
    }
}

printf("compiled and linted %d Blade templates\n", $checked);

if ($failed === []) {
    echo "all templates produce syntactically valid PHP\n";

    return;
}

echo "\nFAILURES (".count($failed).")\n";

foreach ($failed as [$path, $why]) {
    echo '  - '.str_replace(dirname(__DIR__).'/', '', $path)."\n      {$why}\n";
}
exit(1);
