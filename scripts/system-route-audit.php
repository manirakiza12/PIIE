<?php

// Read-only route/template/source inventory. No controller actions are invoked.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$routes = app('router')->getRoutes();
$badActions = $missingNames = $activeMissingNames = $missingParameters = $syntaxErrors = [];
$templateCount = 0;
$named = [];
foreach ($routes as $route) {
    if ($route->getName()) $named[$route->getName()][] = ['uri' => $route->uri(), 'methods' => $route->methods()];
    $action = $route->getActionName();
    if ($route->getAction('uses') instanceof Closure || $action === 'Closure') continue;
    [$class, $method] = array_pad(explode('@', $action, 2), 2, '__invoke');
    try {
        if (!class_exists($class) || !method_exists($class, $method) || !(new ReflectionMethod($class, $method))->isPublic()) {
            $badActions[] = ['name' => $route->getName(), 'uri' => $route->uri(), 'action' => $action];
        }
    } catch (Throwable $exception) {
        $badActions[] = ['name' => $route->getName(), 'uri' => $route->uri(), 'exception' => get_class($exception)];
    }
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) continue;
    $source = file_get_contents($file->getPathname());
    ++$templateCount;
    try { token_get_all(app('blade.compiler')->compileString($source), TOKEN_PARSE); }
    catch (Throwable $exception) { $syntaxErrors[] = ['file' => $file->getPathname(), 'error' => $exception->getMessage()]; }
    $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));
    preg_match_all('/\broute\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);
    foreach (array_unique($matches[1]) as $name) {
        if (!isset($named[$name])) {
            $guarded = preg_match('/Route::has\([\'"]'.preg_quote($name, '/').'[\'"]\)/', $source);
            // These templates belong to removed gateway/addon workflows and have no registered entry route.
            $retired = preg_match('#resources/views/(?:(?:admin/subscription|parent/payment|student/payment)/(?:flutterwave|paypal|paytm|razorpay|stripe)\.blade\.php|admin/disable_reason/|student/assignments/|superadmin/offline_payment/|auth/verify\.blade\.php)#', $relative);
            $entry = ['file' => $relative, 'route' => $name, 'kind' => $guarded ? 'guarded_extension' : ($retired ? 'retired_template' : 'active_missing')];
            $missingNames[] = $entry;
            if (!$guarded && !$retired) $activeMissingNames[] = $entry;
        }
    }
    preg_match_all('/\broute\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $source, $simpleCalls);
    foreach (array_unique($simpleCalls[1]) as $name) {
        $definition = $routes->getByName($name);
        if (!$definition) continue;
        preg_match_all('/\{([^}?]+)\}/', $definition->uri(), $required);
        $parameters = array_diff($required[1], array_keys($definition->defaults));
        if ($parameters) $missingParameters[] = ['file' => $relative, 'route' => $name, 'parameters' => array_values($parameters)];
    }
}
foreach (['app', 'routes', 'database/migrations'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory))) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        try { token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE); }
        catch (Throwable $exception) { $syntaxErrors[] = ['file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()), 'error' => $exception->getMessage()]; }
    }
}
$duplicates = array_filter($named, fn ($definitions) => count($definitions) > 1);
$report = ['route_count' => count($routes), 'template_count' => $templateCount, 'invalid_actions' => $badActions, 'unguarded_active_template_routes' => $activeMissingNames, 'missing_required_parameters' => $missingParameters, 'inactive_template_references' => $missingNames, 'duplicate_route_names' => $duplicates, 'syntax_errors' => $syntaxErrors];
$reportDirectory = base_path('output/system-check');
if (!is_dir($reportDirectory) && !mkdir($reportDirectory, 0775, true) && !is_dir($reportDirectory)) throw new RuntimeException('Could not create the route audit report directory.');
file_put_contents($reportDirectory.'/routes.json', json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($badActions || $activeMissingNames || $missingParameters || $duplicates || $syntaxErrors ? 1 : 0);
