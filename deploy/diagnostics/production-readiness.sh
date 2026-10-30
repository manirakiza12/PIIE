#!/usr/bin/env bash
# Fixed Live Classes pre/post release inspection. No parameters or caller commands.
set -euo pipefail
[ "$#" = 0 ] || { echo 'Diagnostic arguments refused'; exit 1; }
BASE=/home/piie/deployments/piie
# Baseline: protected production deployment of PR #132 (student assignment submission).
# Production SHA 5195eccaf8857c1e05523a13787a851034840fb2 confirmed by
# production diagnostics run 41085927437 (active release read from
# /home/piie/deployments/piie/current/RELEASE_SHA). Merged through protected
# GitHub rules (PR #132).
# Exact release identity; neither caller input nor arbitrary main is admitted.
BASELINE=5195eccaf8857c1e05523a13787a851034840fb2
# Target: protected PR #133 merge (student assignment upload limits and lesson
# transition). Verified by all 5 CI checks in run 41073596727 (success) and
# merged through protected GitHub rules. No arbitrary release SHA is admitted.
TARGET=5a49e9a715fd9fb9861395fb7a3dbf72d28af557
fail() { echo "FAIL: $*" >&2; exit 1; }
# Explain a failed database probe without printing Docker command/env/log values.
# Only the documented exec/seccomp incompatibility can use the existing separate
# client health check. Unknown failures or failed database queries remain fatal.
database_health_evidence() (
  summary=$(cat <<'HEALTH_PY'
# HEALTH_SUMMARY_BEGIN
import datetime, json, re, sys, time
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
        try:
            stamp = re.sub(r"(\.\d{6})\d+", r"\1", str(entry.get("End", "")))
            end = datetime.datetime.fromisoformat(stamp.replace("Z", "+00:00"))
            if end.tzinfo is None:
                raise ValueError("missing timezone")
            age = int(time.time() - end.timestamp())
        except (ValueError, TypeError):
            age = -1
        signature = "setssb requires libseccomp" in text and ("seccomp filter" in text or "oci runtime exec failed" in text)
        checks.append({"exit_code": int(entry.get("ExitCode", -1)), "category": category,
            "age_seconds": age, "documented_exec_failure": signature})
    command = " ".join(data.get("test") or []).lower()
    probe = "mariadb_builtin" if "healthcheck.sh" in command else "admin_ping" if "mysqladmin" in command or "mariadb-admin" in command else "custom"
    compatible = (data.get("health", {}).get("Status") == "unhealthy" and probe == "mariadb_builtin"
        and not data.get("oom_killed") and not data.get("restarting") and data.get("restart_count") == 0
        and len(checks) == 3 and all(c["exit_code"] == -1 and c["documented_exec_failure"]
            and c["category"] == "runtime_seccomp_failure" and 0 <= c["age_seconds"] <= 300 for c in checks))
    print(json.dumps({"database_health_evidence": True, "probe_type": probe,
        "oom_killed": bool(data.get("oom_killed")), "restart_count": int(data.get("restart_count", 0)),
        "recent_checks": checks, "compatible_client_required": compatible}))
except Exception:
    print("FAIL: database health metadata could not be summarized", file=sys.stderr)
    sys.exit(1)
# HEALTH_SUMMARY_END
HEALTH_PY
)
  compatible=0
  if evidence=$(docker inspect --format '{"health":{{json .State.Health}},"test":{{json .Config.Healthcheck.Test}},"oom_killed":{{json .State.OOMKilled}},"restarting":{{json .State.Restarting}},"restart_count":{{json .RestartCount}}}' piie-db 2>/dev/null |
    python3 -c "$summary"); then
    printf '%s\n' "$evidence"
    if printf '%s\n' "$evidence" | python3 -c 'import json,sys; sys.exit(0 if json.load(sys.stdin).get("compatible_client_required") is True else 1)'; then compatible=1; fi
  else
    echo 'Database health metadata summary unavailable'
  fi
  # Existing deployment preflight uses this same independent client/network.
  # Its only SQL is SELECT 1; credentials remain in a private remote temp file.
  db_env=$(mktemp)
  chmod 600 "$db_env"
  trap 'rm -f -- "$db_env"' EXIT
  if ! docker inspect piie-db --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null |
    grep -E '^MARIADB_ROOT_PASSWORD=' > "$db_env"; then
    echo 'Independent database read-only probe: unavailable (credential metadata missing)'
    exit 1
  fi
  if docker run --rm --pull=never --read-only --cap-drop=ALL --security-opt no-new-privileges \
    --network piie-stack_private --env-file "$db_env" --entrypoint sh mariadb:11.4 -ec \
    'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb --connect-timeout=5 --host=piie-db --user=root --batch --skip-column-names -e "SELECT 1" | grep -qx 1' >/dev/null 2>&1; then
    echo 'Independent database read-only SELECT 1: PASS'
    [ "$compatible" = 1 ] || exit 1
    echo 'Database service health verified by existing compatible client; documented Docker exec/seccomp probe failure retained in evidence'
  else
    echo 'Independent database read-only SELECT 1: FAIL (details withheld)'
    exit 1
  fi
)
[ "$(id -u)" = 0 ] || fail 'Verified VPS administrator required'
case "$(readlink "$BASE/current")" in releases/*) ;; *) fail 'Unexpected current link';; esac
ACTIVE=$(cat "$BASE/current/RELEASE_SHA")
[[ "$ACTIVE" =~ ^[0-9a-f]{40}$ ]] || fail 'Invalid live release SHA'
echo "Active release SHA: $ACTIVE"
[ "$ACTIVE" = "$BASELINE" ] || [ "$ACTIVE" = "$TARGET" ] || [ "$ACTIVE" = "$LEARNING_RELEASE" ] || [ "$ACTIVE" = "$COMMUNICATION_RELEASE" ] || [ "$ACTIVE" = "$LESSON_RELEASE" ] || fail 'Live release is outside the approved release history'
echo "Approved target SHA: $TARGET"
[ -s "$BASE/current/vendor/autoload.php" ] || fail 'Active release cannot be loaded'
for container in piie-app piie-db; do
  [ "$(docker inspect -f '{{.State.Running}}' "$container")" = true ] || fail "$container is not running"
  health=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}not_configured{{end}}' "$container")
  if [ "$health" != healthy ] && [ "$health" != not_configured ]; then
    if [ "$container" = piie-db ] && database_health_evidence; then
      echo 'Container piie-db: running; Docker health=unhealthy (documented probe execution failure); independent service health=PASS'
      continue
    fi
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
    // Both approved releases include this already-deployed module and schema.
    if (!$installed || !is_file($root.'/database/migrations/'.$module.'.php')) throw new LogicException('Required existing module migration missing');
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
    echo json_encode(['database_read_only'=>true,'current_pending_migrations'=>$pending,'module_tables_present'=>$installed,'admissions_finance_schema_checks'=>'PASS','module_route_and_authority_source_checks'=>'PASS']).PHP_EOL;
    $routes = file_get_contents($root.'/routes/web.php');
    foreach (['student.live_classes.show','student.live_classes.join','live_classes.recording.access'] as $route) {
        if (!str_contains($routes, $route)) throw new LogicException('Live Class route missing');
    }
    $kernel = file_get_contents($root.'/app/Console/Kernel.php');
    $liveClassRetryInstalled = in_array('2026_10_29_150000_add_live_class_notification_revisions', $ran, true);
    if (in_array($sha, ['452e21ac5d59ea582357914f2045d558816a1776', '7838f3c534d3a08fc38d3ef838bba925e2ddca43', '9a5f3b05b3fbafcb10ccda36a6b48d567cf78529', 'd2fa0a15c61618f996d1f0bfc1c786745eba52bd', 'e46dafa4128bbb75b8fa29318dd09b03a87d5257'], true) !== $liveClassRetryInstalled) throw new LogicException('Live Classes release and retry migration disagree');
    $commands = ['live-classes:send-reminders','live-classes:refresh-google-links'];
    if ($liveClassRetryInstalled) $commands[] = 'live-classes:retry-emails';
    foreach ($commands as $command) {
        if (!str_contains($kernel, $command)) throw new LogicException('Live Class scheduled command missing');
    }
    $outbox = null;
    if ($liveClassRetryInstalled) {
        $pdo->query('SELECT schedule_revision, notification_revision FROM live_classes WHERE 1=0');
        $outbox = $pdo->query('SELECT COUNT(*) AS total, SUM(sent_at IS NOT NULL) AS transport_accepted, SUM(sent_at IS NULL AND discarded_at IS NULL AND attempts >= 5) AS exhausted, SUM(sent_at IS NULL AND discarded_at IS NULL AND attempts < 5) AS pending FROM live_class_email_deliveries')->fetch(PDO::FETCH_ASSOC);
    }
    $smtp = $pdo->query("SELECT COUNT(*) FROM global_settings WHERE `key` IN ('smtp_user','smtp_pass','smtp_host','smtp_port') AND value IS NOT NULL AND value <> ''")->fetchColumn();
    $google = $pdo->query("SELECT enabled, (client_id IS NOT NULL AND client_id <> '' AND client_secret IS NOT NULL AND client_secret <> '') AS credentials_present, (verified_at IS NOT NULL) AS previously_verified FROM google_meet_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $grants = $pdo->query("SELECT COUNT(*) FROM google_account_connections WHERE status='ok' AND refresh_token_ciphertext IS NOT NULL")->fetchColumn();
    $zones = $pdo->query('SELECT DISTINCT timezone FROM schools WHERE timezone IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['live_classes_read_only'=>true,'routes_and_scheduled_commands'=>'PASS','email_retry_migration_installed'=>$liveClassRetryInstalled,'smtp_fields_present'=>(int)$smtp===4,'email_outbox'=>$outbox,'google_setup'=>$google ?: null,'google_grants_present'=>(int)$grants>0,'institution_timezones'=>$zones,'queue_connection'=>$env['QUEUE_CONNECTION'] ?? 'sync','jitsi_app_id_present'=>!empty($env['JITSI_APP_ID']),'provider_connectivity_tested'=>false,'real_student_delivery_tested'=>false]).PHP_EOL;
    // Communication access incident: metadata only, never message content or credentials.
    $communicationAccess = $pdo->query("SELECT role_id, account_status, staff_status, force_password_change, COUNT(*) AS accounts FROM users WHERE role_id=3 GROUP BY role_id,account_status,staff_status,force_password_change")->fetchAll(PDO::FETCH_ASSOC);
    $reportedThread = $pdo->query('SELECT t.id AS thread_id, u.id AS user_id, u.role_id, u.account_status, u.staff_status, u.force_password_change, (u.school_id=t.school_id) AS same_school FROM message_thrades t JOIN users u ON u.id=t.sender_id OR u.id=t.reciver_id WHERE t.id=6')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['communication_access_read_only'=>true,'lecturer_account_states'=>$communicationAccess,'reported_thread_participant_states'=>$reportedThread]).PHP_EOL;
    $associationInstalled = (bool)$pdo->query("SHOW COLUMNS FROM course_offering_lessons LIKE 'assignment_id'")->fetch(PDO::FETCH_ASSOC);
    $associationColumn = $associationInstalled ? 'assignment_id' : 'NULL AS assignment_id';
    $publishedLessons = $pdo->query('SELECT id,course_offering_id,'.$associationColumn.' FROM course_offering_lessons WHERE status=\'published\' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $unlinkedLessons = array_values(array_filter($publishedLessons, fn($lesson) => empty($lesson['assignment_id'])));
    echo json_encode(['lesson_publication_read_only'=>true,'association_schema_installed'=>$associationInstalled,'published_lesson_count'=>count($publishedLessons),'unlinked_published_lesson_count'=>count($unlinkedLessons),'unlinked_published_lessons'=>$unlinkedLessons,'historical_records_modified'=>false]).PHP_EOL;
    // Fixed reference-course metadata, inside the existing read-only transaction.
    $course = $pdo->query('SELECT c.id,c.school_id,c.reference,s.timezone FROM course_offerings c JOIN schools s ON s.id=c.school_id WHERE c.id=30')->fetch(PDO::FETCH_ASSOC);
    if ($course) {
        $lessons = $pdo->query('SELECT l.id,l.title,l.status,l.released_at,l.content_type,m.id AS module_id,m.status AS module_status,m.released_at AS module_released_at FROM course_offering_lessons l JOIN course_offering_modules m ON m.id=l.course_offering_module_id AND m.course_offering_id=l.course_offering_id AND m.school_id=l.school_id WHERE l.course_offering_id=30 ORDER BY m.sequence,m.id,l.sequence,l.id')->fetchAll(PDO::FETCH_ASSOC);
        $registered = (int)$pdo->query("SELECT COUNT(*) FROM course_registrations r JOIN course_offerings c ON c.id=r.course_offering_id AND c.school_id=r.school_id WHERE c.id=30 AND r.status='confirmed'")->fetchColumn();
        $resources = $pdo->query('SELECT r.id,r.stored_name,r.type,l.id AS lesson_id FROM course_offering_lesson_resources r LEFT JOIN course_offering_lessons l ON l.id=r.course_offering_lesson_id AND l.course_offering_id=r.course_offering_id AND l.school_id=r.school_id WHERE r.course_offering_id=30')->fetchAll(PDO::FETCH_ASSOC);
        $missing = [];
        foreach ($resources as $resource) {
            if (!$resource['lesson_id'] || ($resource['type'] === 'file' && (!$resource['stored_name'] || !is_file($root.'/storage/app/'.$resource['stored_name'])))) $missing[] = (int)$resource['id'];
        }
        echo json_encode(['course_learning_read_only'=>true,'offering'=>$course,'database_clock'=>$env['APP_TIMEZONE'] ?? 'UTC','utc_now'=>gmdate('c'),'confirmed_registrations'=>$registered,'lessons'=>$lessons,'missing_lesson_resource_ids'=>$missing]).PHP_EOL;
    }
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
echo "READINESS PASS: active=$ACTIVE; approved Live Classes transition; no deployment or application writes performed"
