<?php
// PIIE-RELEASE-SHELL v1
//
// Front controller for the live document root (public_html). It contains no
// application code: it loads whichever release `current` points at, so a
// release switch (or rollback) is one atomic symlink change that never touches
// public_html itself. Installed by deploy/remote/docroot.sh, not by git archive.

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$app_root = '/home/piie/deployments/piie/current';

// `artisan down` writes here; storage/ is the shared, persistent directory.
if (file_exists($maintenance = $app_root.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $app_root.'/vendor/autoload.php';

$app = require_once $app_root.'/bootstrap/app.php';

// The web root is THIS directory, not <release>/public. Uploads written through
// public_path('assets/uploads/...') must land in the persistent docroot, and the
// files already uploaded there stay where they are.
$app->instance('path.public', __DIR__);

$kernel = $app->make(Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);
