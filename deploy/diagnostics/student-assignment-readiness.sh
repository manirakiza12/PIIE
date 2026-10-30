#!/usr/bin/env bash
# Fixed, read-only inspection of the reported assignment. No student impersonation.
set -euo pipefail
[ "$#" = 0 ] || exit 1
BASE=/home/piie/deployments/piie
ACTIVE=$(cat "$BASE/current/RELEASE_SHA")
[[ "$ACTIVE" =~ ^[0-9a-f]{40}$ ]] || exit 1
IMAGE=$(docker inspect -f '{{.Image}}' piie-app)
[[ "$IMAGE" =~ ^sha256:[0-9a-f]{64}$ ]] || exit 1
docker run --rm -i --pull=never --read-only --cap-drop=ALL --security-opt no-new-privileges --pids-limit=64 \
  --mount "type=bind,source=$BASE,target=/srv/piie,readonly" --network container:piie-app \
  --user www-data --workdir /srv/piie/current --entrypoint php "$IMAGE" <<'PHP'
<?php
require '/srv/piie/current/vendor/autoload.php';
$pdo = null;
try {
    $root = '/srv/piie/current';
    $env = Dotenv\Dotenv::createArrayBacked($root)->safeLoad();
    $pdo = new PDO('mysql:host='.($env['DB_HOST'] ?? 'db').';port='.($env['DB_PORT'] ?? 3306).';dbname='.$env['DB_DATABASE'].';charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('START TRANSACTION READ ONLY');
    $columns = $pdo->query('SHOW COLUMNS FROM assignments')->fetchAll(PDO::FETCH_COLUMN);
    $newSchema = in_array('submission_requirement_mode', $columns, true) && in_array('optional_submission_kinds', $columns, true);
    $extra = $newSchema ? ',submission_requirement_mode,optional_submission_kinds' : ',NULL AS submission_requirement_mode,NULL AS optional_submission_kinds';
    $row = $pdo->query('SELECT id,school_id,course_offering_id,submission_type,submission_kinds,status,allowed_attempts,due_date,closes_at,late_policy,instructions'.$extra.' FROM assignments WHERE id=2 AND course_offering_id=30')->fetch(PDO::FETCH_ASSOC);
    if (!$row) { throw new LogicException('Reported assignment not found in Course Allocation 30'); }
    $mentionsDocument = (bool)preg_match('/\b(pdf|word|docx?|document)\b/i', strip_tags($row['instructions'] ?? ''));
    unset($row['instructions']);
    $questions = $pdo->query('SELECT id,sequence,marks,is_required,response_kinds,require_all FROM assignment_questions WHERE assignment_id=2 AND school_id='.(int)$row['school_id'].' ORDER BY sequence,id')->fetchAll(PDO::FETCH_ASSOC);
    $model = new App\Models\Assignment($row);
    $featurePresent = is_file($root.'/public/js/student-assignment-submission.js');
    if ($featurePresent && !$newSchema) { throw new LogicException('Response UI and assignment schema disagree'); }
    $ran = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = array_map(fn ($file) => basename($file, '.php'), glob($root.'/database/migrations/*.php'));
    if (array_diff($files, $ran)) { throw new LogicException('Active release has pending migrations'); }
    echo json_encode(['read_only'=>true,'active_sha'=>trim(file_get_contents($root.'/RELEASE_SHA')),
        'assignment'=>$row,'structured_questions'=>$questions,'instructions_mention_document'=>$mentionsDocument,
        'response_schema_installed'=>$newSchema,'new_response_ui_present'=>$featurePresent,
        'effective_response_rule'=>$featurePresent ? ($model->requiresAllResponseKinds() ? 'all' : 'any') : 'legacy_all',
        'effective_response_kinds'=>$featurePresent ? $model->responseKinds() : $model->acceptedEvidenceKinds(),
        'student_accounts_used'=>false,'student_submissions_created'=>false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: assignment read-only inspection ('.get_class($exception).')'.PHP_EOL);
    exit(1);
} finally {
    if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
}
PHP
code=$(curl --silent --show-error --max-time 20 --output /dev/null --write-out '%{http_code}' https://piie.ac.ug/student/courses/30/assignments/2)
[[ "$code" = 302 || "$code" = 303 ]] || { echo 'FAIL: student assignment must require authentication'; exit 1; }
[ "$(curl --fail --silent --show-error --max-time 20 https://piie.ac.ug/health)" = PIIE-APP-OK ]
if [ -f "$BASE/current/public/js/student-assignment-submission.js" ]; then
  live=$(mktemp)
  trap 'rm -f -- "$live"' EXIT
  curl --fail --silent --show-error --max-time 20 "https://piie.ac.ug/js/student-assignment-submission.js?release=$ACTIVE" -o "$live"
  cmp "$BASE/current/public/js/student-assignment-submission.js" "$live"
  echo 'PASS: served student submission JavaScript matches active release'
fi
[ "$(cat "$BASE/current/RELEASE_SHA")" = "$ACTIVE" ]
echo "ASSIGNMENT READINESS PASS: active=$ACTIVE; no application writes or student-account submissions"
