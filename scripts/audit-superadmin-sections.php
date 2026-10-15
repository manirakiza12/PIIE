<?php
// Production diagnostic: read-only SQL; no framework boot or personal data.
$root = '/srv/piie/current';
require $root.'/vendor/autoload.php';
$env = Dotenv\Dotenv::createArrayBacked($root)->safeLoad();
$pdo = new PDO('mysql:host='.($env['DB_HOST'] ?? 'db').';port='.($env['DB_PORT'] ?? 3306).';dbname='.$env['DB_DATABASE'].';charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('START TRANSACTION READ ONLY');
try {
    $result = ['release' => trim(file_get_contents($root.'/RELEASE_SHA'))];
    $cached = is_file($root.'/bootstrap/cache/config.php') ? require $root.'/bootstrap/cache/config.php' : [];
    $result['subscription_enforcement'] = (bool) ($cached['app']['enforce_school_subscriptions'] ?? filter_var($env['ENFORCE_SCHOOL_SUBSCRIPTIONS'] ?? false, FILTER_VALIDATE_BOOLEAN));
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['schools', 'packages', 'subscriptions', 'payment_history', 'addons', 'faq', 'frontend_features', 'website_pages', 'website_sections', 'website_items'] as $table) {
        if (in_array($table, $tables, true)) {
            $result['counts'][$table] = (int) $pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
        }
    }
    $result['sections'] = $pdo->query('SELECT id,school_id,page_key,section_key,title,status FROM website_sections ORDER BY school_id,page_key,id')->fetchAll(PDO::FETCH_ASSOC);
    $result['items_by_section'] = $pdo->query('SELECT school_id,section_key,COUNT(*) AS total,SUM(status=1) AS published FROM website_items GROUP BY school_id,section_key')->fetchAll(PDO::FETCH_ASSOC);
    $result['addons'] = $pdo->query('SELECT id,unique_identifier,status FROM addons')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    $pdo->rollBack();
}
