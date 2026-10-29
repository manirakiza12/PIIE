#!/usr/bin/env bash
# Fixed Module 1 pre/post release inspection. No parameters or caller commands.
set -euo pipefail
[ "$#" = 0 ] || { echo 'Diagnostic arguments refused'; exit 1; }
BASE=/home/piie/deployments/piie
TARGET=ad5f0190e68d912a06923fc6ceba623883fb0732
BASELINE=0b1924ca54ed89c72400d08a3e80476888b1e719
fail() { echo "FAIL: $*" >&2; exit 1; }
# Explain a failed database probe without printing Docker command/env/log values.
# This collects evidence only; it never overrides the Docker health gate below.
database_health_evidence() (
  summary=$(cat <<'HEALTH_PY'
# HEALTH_SUMMARY_BEGIN
import json, sys
try:
    data = json.load(sys.stdin)
    checks = []
    for entry in (data.get("health", {}).get("Log") or [])[-3:]:
        text = str(entry.get("Output", "")).lower()
        category = "unclassified"
        if "seccomp" in text or "setssb" in text:
            category = "runtime_seccomp_failure"
        elif "setns" in text or "oci runtime exec failed" in text:
            category = "runtime_exec_failure"
        elif "executable file not found" in text or "command not found" in text:
            category = "probe_executable_missing"
        elif "access denied" in text or "authentication" in text:
            category = "database_authentication_failure"
        elif "connection refused" in text or "can\u0027t connect" in text or "timed out" in text:
            category = "database_connection_failure"
        elif "permission denied" in text:
            category = "probe_permission_failure"
        checks.append({"exit_code": int(entry.get("ExitCode", -1)), "category": category})
    command = " ".join(data.get("test") or []).lower()
    probe = "mariadb_builtin" if "healthcheck.sh" in command else "admin_ping" if "mysqladmin" in command or "mariadb-admin" in command else "custom"
    print(json.dumps({"database_health_evidence": True, "probe_type": probe,
        "oom_killed": bool(data.get("oom_killed")), "restart_count": int(data.get("restart_count", 0)),
        "recent_checks": checks}))
except Exception:
    print("FAIL: database health metadata could not be summarized", file=sys.stderr)
    sys.exit(1)
# HEALTH_SUMMARY_END
HEALTH_PY
)
  docker inspect --format '{"health":{{json .State.Health}},"test":{{json .Config.Healthcheck.Test}},"oom_killed":{{json .State.OOMKilled}},"restart_count":{{json .RestartCount}}}' piie-db 2>/dev/null |
    python3 -c "$summary" || echo 'Database health metadata summary unavailable'
  # Existing deployment preflight uses this same independent client/network.
  # Its only SQL is SELECT 1; credentials remain in a private remote temp file.
  db_env=$(mktemp)
  chmod 600 "$db_env"
  trap 'rm -f -- "$db_env"' EXIT
  if ! docker inspect piie-db --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null |
    grep -E '^MARIADB_ROOT_PASSWORD=' > "$db_env"; then
    echo 'Independent database read-only probe: unavailable (credential metadata missing)'
    exit 0
  fi
  if docker run --rm --pull=never --read-only --cap-drop=ALL --security-opt no-new-privileges \
    --network piie-stack_private --env-file "$db_env" --entrypoint sh mariadb:11.4 -ec \
    'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb --connect-timeout=5 --host=piie-db --user=root --batch --skip-column-names -e "SELECT 1" | grep -qx 1' >/dev/null 2>&1; then
    echo 'Independent database read-only SELECT 1: PASS'
  else
    echo 'Independent database read-only SELECT 1: FAIL (details withheld)'
  fi
)
[ "$(id -u)" = 0 ] || fail 'Verified VPS administrator required'
case "$(readlink "$BASE/current")" in releases/*) ;; *) fail 'Unexpected current link';; esac
ACTIVE=$(cat "$BASE/current/RELEASE_SHA")
[[ "$ACTIVE" =~ ^[0-9a-f]{40}$ ]] || fail 'Invalid live release SHA'
[ "$ACTIVE" = "$BASELINE" ] || [ "$ACTIVE" = "$TARGET" ] || fail 'Live release is outside the approved Module 1 transition'
echo "Active release SHA: $ACTIVE"
echo "Approved target SHA: $TARGET"
[ -s "$BASE/current/vendor/autoload.php" ] || fail 'Active release cannot be loaded'
for container in piie-app piie-db; do
  [ "$(docker inspect -f '{{.State.Running}}' "$container")" = true ] || fail "$container is not running"
  health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}not_configured{{end}}' "$container")
  if [ "$health" != healthy ] && [ "$health" != not_configured ]; then
    if [ "$container" = piie-db ]; then database_health_evidence || echo 'Database failure evidence collection incomplete'; fi
    fail "$container health is $health"
  fi
  echo "Container $container: running; health=$health"
done
docker inspect -f '{{range .Mounts}}{{.Source}}:{{.Destination}}{{println}}{{end}}' piie-app | grep -Fx "$BASE:/srv/piie" >/dev/null || fail 'Application mount mismatch'
FREE=$(df -PB1 "$BASE" | awk 'NR==2 {print $4}')
INODES=$(df -Pi "$BASE" | awk 'NR==2 {print $4}')
NEEDED=$(du -sb "$BASE/current/." "$BASE/shared" | awk '{sum += $1} END {printf "%.0f", sum * 2}')
echo "Disk free bytes: $FREE; free inodes: $INODES; conservative release/backup estimate: $NEEDED"
[[ "$FREE" =~ ^[0-9]+$ && "$INODES" =~ ^[0-9]+$ && "$NEEDED" =~ ^[0-9]+$ ]] || fail 'Cannot establish disk capacity'
[ "$FREE" -ge 2147483648 ] && [ "$FREE" -ge "$NEEDED" ] && [ "$INODES" -ge 25000 ] || fail 'Insufficient disk/inode headroom'

# Read existing archives in place; never create a backup or restore anything here.
BACK=$(find "$BASE/backups" -mindepth 2 -maxdepth 2 -type f -name SHA256SUMS -printf '%T@ %h\n' | sort -nr | sed -n '1s/^[^ ]* //p')
[[ "$BACK" =~ ^$BASE/backups/[0-9]{8}-[0-9]{6}-[0-9a-f]{12}$ ]] || fail 'No recognized backup manifest'
AGE=$(( $(date +%s) - $(stat -c %Y "$BACK/SHA256SUMS") ))
echo "Latest backup: ${BACK##*/}; age_seconds=$AGE"
[ "$AGE" -ge 0 ] && [ "$AGE" -le 86400 ] || fail 'Latest verified backup is older than 24 hours or has a future timestamp'
for file in database.sql.gz storage.tar.gz public-uploads.tar.gz environment.backup SHA256SUMS; do
  [ -f "$BACK/$file" ] && [ ! -L "$BACK/$file" ] && [ -s "$BACK/$file" ] || fail 'Backup member missing or linked'
done
[ "$(wc -l < "$BACK/SHA256SUMS")" = 4 ] || fail 'Unexpected backup manifest size'
awk 'NF!=2 || $1 !~ /^[0-9a-f]+$/ || length($1)!=64 || ($2!="database.sql.gz" && $2!="storage.tar.gz" && $2!="public-uploads.tar.gz" && $2!="environment.backup") {exit 1}' "$BACK/SHA256SUMS" || fail 'Unsafe backup manifest'
[ "$(awk '{print $2}' "$BACK/SHA256SUMS" | sort -u | wc -l)" = 4 ] || fail 'Duplicate backup manifest members'
(cd "$BACK" && sha256sum --check --strict SHA256SUMS)
gzip -t "$BACK/database.sql.gz"
gzip -cd "$BACK/database.sql.gz" | tail -n 8 | grep -q 'Dump completed' || fail 'Database dump completion marker missing'
tar -tzf "$BACK/storage.tar.gz" >/dev/null
tar -tzf "$BACK/public-uploads.tar.gz" >/dev/null
echo 'Backup checksums, database gzip/completion marker and storage/upload archive integrity: PASS'
DB_BYTES=$(gzip -cd "$BACK/database.sql.gz" | wc -c)
TOTAL_NEEDED=$((NEEDED + DB_BYTES * 2))
echo "Capacity including twice the uncompressed database backup: required_bytes=$TOTAL_NEEDED"
[ "$FREE" -ge "$TOTAL_NEEDED" ] || fail 'Insufficient capacity for release and database/storage backups'
RECOVERABLE=0
while IFS= read -r file; do
  release=${file%/RELEASE_SHA}
  sha=$(cat "$file")
  if [[ "$sha" =~ ^[0-9a-f]{40}$ ]] && [ -s "$release/vendor/autoload.php" ] && [ "$sha" != "$ACTIVE" ]; then
    echo "Retained recovery release: ${release##*/}; SHA=$sha"
    RECOVERABLE=$((RECOVERABLE + 1))
  fi
done < <(find "$BASE/releases" -mindepth 2 -maxdepth 2 -type f -name RELEASE_SHA | sort)
[ "$RECOVERABLE" -ge 1 ] || fail 'No retained alternative code release'
docker image inspect mariadb:11.4 --format '{{.Id}}' >/dev/null || fail 'Existing backup client image missing'
docker run --rm --pull=never --read-only --network none --cap-drop=ALL --security-opt no-new-privileges --entrypoint sh mariadb:11.4 -ec 'command -v mariadb-dump >/dev/null'

[ -f /etc/cron.d/piie-scheduler ] || fail 'Scheduler cron entry missing'
[ "$(stat -c %a /etc/cron.d/piie-scheduler)" = 644 ] || fail 'Unexpected scheduler cron mode'
grep -Fx '* * * * * root /bin/bash /home/piie/deployments/piie/bin/container-app.sh /srv/piie/current php artisan schedule:run >> /home/piie/deployments/piie/shared/storage/logs/scheduler.log 2>&1' /etc/cron.d/piie-scheduler >/dev/null || fail 'Scheduler entry mismatch'
if systemctl is-active --quiet cron; then :; elif systemctl is-active --quiet crond; then :; else fail 'Cron service inactive'; fi
LOG="$BASE/shared/storage/logs/scheduler.log"
[ -f "$LOG" ] || fail 'Scheduler log missing'
LOG_AGE=$(( $(date +%s) - $(stat -c %Y "$LOG") ))
ERRORS=$(tail -n 100 "$LOG" | grep -Eic 'FAIL|ERROR|Exception' || true)
echo "Scheduler log age_seconds=$LOG_AGE; recent_tail_error_count=$ERRORS"
[ "$LOG_AGE" -ge 0 ] && [ "$LOG_AGE" -le 180 ] || fail 'Scheduler evidence is stale'
[ "$ERRORS" = 0 ] || fail 'Scheduler log contains errors; review required'

IMAGE=$(docker inspect -f '{{.Image}}' piie-app)
[[ "$IMAGE" =~ ^sha256:[0-9a-f]{64}$ ]] || fail 'Invalid application image identity'
# Ephemeral client: same runtime/network, read-only host mount and root filesystem,
# no framework/provider bootstrap, no credential argv/env output, no server file writes.
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
    $sha = trim(file_get_contents($root.'/RELEASE_SHA'));
    $module = '2026_10_29_090000_create_curriculum_assignment_proposals';
    $ran = $pdo->query('SELECT migration FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $files = array_map(fn ($f) => basename($f, '.php'), glob($root.'/database/migrations/*.php'));
    $pending = array_values(array_diff($files, $ran));
    if ($pending) throw new LogicException('Active release has pending migrations');
    $installed = in_array($module, $ran, true);
    if (($sha === 'ad5f0190e68d912a06923fc6ceba623883fb0732') !== $installed) throw new LogicException('Module migration and active release disagree');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['curriculum_applicability_mappings','curriculum_assignment_proposals'] as $table) {
        if (in_array($table, $tables, true) !== $installed) throw new LogicException('Module table/migration collision');
    }
    foreach (['schools','users','programmes','intake_sessions','curricula','academic_years','curriculum_stages','admissions','student_curriculum_assignments'] as $table) {
        $q = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='id'");
        $q->execute([$table]);
        if (!preg_match('/^bigint(?:\(\d+\))? unsigned$/i', (string)$q->fetchColumn())) throw new LogicException('Foreign-key prerequisite type mismatch');
    }
    $pdo->query('SELECT id, school_id, status, automation_student_id FROM admissions WHERE 1=0');
    $pdo->query('SELECT id, school_id, student_id, session_id, total_amount, paid_amount, status FROM student_fee_managers WHERE 1=0');
    $pdo->query('SELECT school_id, user_id, programme_id, intake_session_id, status FROM student_profiles WHERE 1=0');
    if ($installed) {
        $routes = file_get_contents($root.'/routes/web.php');
        foreach (['index','mapping.store','mapping.approve','mapping.revoke','propose','review','apply'] as $name) {
            if (!str_contains($routes, "name('admin.curriculum_assignment.$name')")) throw new LogicException('Module route missing');
        }
        if (!str_contains($routes, "Route::middleware(['auth', 'admin', 'rbac'])->controller(\\App\\Http\\Controllers\\CurriculumAssignmentProposalController::class)")) throw new LogicException('Module route guard missing');
        $service = file_get_contents($root.'/app/Support/Curriculum/CurriculumAssignmentProposals.php');
        if (!str_contains($service, 'academic.programme_cohort.membership') || !str_contains($service, "authorize(\$actor, 'approve')")) throw new LogicException('Apply authority guard missing');
    }
    echo json_encode(['database_read_only'=>true,'current_pending_migrations'=>$pending,'target_pending_migrations'=>$installed ? [] : [$module],'module_tables_present'=>$installed,'admissions_finance_schema_checks'=>'PASS','module_route_and_authority_source_checks'=>$installed ? 'PASS' : 'not active yet']).PHP_EOL;
} catch (LogicException $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage().PHP_EOL); exit(1);
} catch (Throwable $e) {
    // Never emit connection/parsed environment values or exception traces.
    fwrite(STDERR, 'FAIL: provider-free database diagnostic ('.get_class($e).')'.PHP_EOL); exit(1);
} finally {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
}
PHP
for path in /health / /login /applicant/login /careers; do
  code=$(curl --silent --show-error --max-time 20 --output /dev/null --write-out '%{http_code}' "https://piie.ac.ug$path")
  [ "$code" = 200 ] || fail "Public read-only smoke $path returned $code"
  echo "Public read-only smoke $path: HTTP $code"
done
[ "$(curl --fail --silent --show-error --max-time 20 https://piie.ac.ug/health)" = PIIE-APP-OK ] || fail 'Application health body mismatch'
[ "$(cat "$BASE/current/RELEASE_SHA")" = "$ACTIVE" ] || fail 'Release changed during inspection'
echo "READINESS PASS: active=$ACTIVE; fixed Module 1 transition; no deployment or application writes performed"
