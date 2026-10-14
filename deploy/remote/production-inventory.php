<?php
// Read-only metadata and aggregates. No framework/provider bootstrap or personal records.
$root = getenv('PIIE_CONTAINER_APP') ?: '/srv/piie/current';
require $root.'/vendor/autoload.php';
$env = Dotenv\Dotenv::createArrayBacked($root)->safeLoad();
$pdo = new PDO('mysql:host='.($env['DB_HOST'] ?? 'db').';port='.($env['DB_PORT'] ?? 3306).';dbname='.$env['DB_DATABASE'].';charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('START TRANSACTION READ ONLY');
try {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach (['users','schools','subscriptions','payment_history','admissions','application_payments','student_fee_managers','student_profiles','message_thrades','chats'] as $table) {
        if (in_array($table, $tables, true)) $counts[$table] = $pdo->query('SELECT COUNT(*) AS count, MAX(id) AS highest_id FROM `'.$table.'`')->fetch(PDO::FETCH_ASSOC);
    }
    $primary = (int) $pdo->query("SELECT value FROM global_settings WHERE `key`='primary_school_id'")->fetchColumn();
    $careers = $pdo->query("SELECT page_key,slug,status FROM website_pages WHERE page_key='careers' OR slug='careers'")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['release' => trim(file_get_contents($root.'/RELEASE_SHA')), 'php' => PHP_VERSION,
        'database_version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'primary_school_id' => $primary,
        'protected_counts' => $counts, 'migrations' => $pdo->query('SELECT migration FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        'careers' => $careers, 'app_key_digest' => hash('sha256', $env['APP_KEY'] ?? ''),
        'app_env' => $env['APP_ENV'] ?? null, 'app_debug' => $env['APP_DEBUG'] ?? null,
        'public_uploads_path' => realpath($root.'/public/assets/uploads')], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally { $pdo->rollBack(); }
