<?php

// Operator-run, read-only inventory. No Laravel/provider bootstrap, student identities or answers.
$root = $argv[1] ?? getenv('PIIE_CONTAINER_APP') ?: '/srv/piie/current';
require $root.'/vendor/autoload.php';
$env = Dotenv\Dotenv::createArrayBacked($root)->safeLoad();
$pdo = new PDO(
    'mysql:host='.($env['DB_HOST'] ?? 'db').';port='.($env['DB_PORT'] ?? 3306).';dbname='.$env['DB_DATABASE'].';charset=utf8mb4',
    $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec('START TRANSACTION READ ONLY');
try {
    $columns = $pdo->query('SHOW COLUMNS FROM online_exams')->fetchAll(PDO::FETCH_COLUMN);
    $schoolColumns = $pdo->query('SHOW COLUMNS FROM schools')->fetchAll(PDO::FETCH_COLUMN);
    $offering = in_array('course_offering_id', $columns, true) ? 'e.course_offering_id' : 'NULL';
    $education = in_array('education_level', $schoolColumns, true) ? 's.education_level' : 'NULL';
    $schoolType = in_array('school_type', $schoolColumns, true) ? 's.school_type' : 'NULL';
    $exams = $pdo->query("SELECT e.id AS exam_id, e.school_id, $education AS education_level,
        $schoolType AS school_type, e.class_id, $offering AS course_offering_id,
        e.workflow_state, e.start_datetime, e.end_datetime,
        COUNT(a.id) AS attempts,
        SUM(CASE WHEN a.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN a.status = 'in_progress' AND a.expires_at > CURRENT_TIMESTAMP THEN 1 ELSE 0 END) AS stored_deadline_in_future,
        SUM(CASE WHEN a.status = 'in_progress' AND a.expires_at <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END) AS stored_deadline_expired
        FROM online_exams e LEFT JOIN schools s ON s.id = e.school_id
        LEFT JOIN online_exam_submissions a ON a.online_exam_id = e.id AND a.school_id = e.school_id
        GROUP BY e.id, e.school_id, $education, $schoolType, e.class_id, $offering,
        e.workflow_state, e.start_datetime, e.end_datetime ORDER BY e.school_id, e.id")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'verified_at_utc' => gmdate('c'),
        'release' => is_file($root.'/RELEASE_SHA') ? trim(file_get_contents($root.'/RELEASE_SHA')) : null,
        'note' => 'Stored deadlines are inventory only; application effective deadlines can be earlier. No records were changed.',
        'exams' => $exams,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
    $pdo->rollBack();
}
