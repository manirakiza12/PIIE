#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
bash -n "$ROOT/deploy/diagnostics/production-readiness.sh"
S=$(mktemp -d "${TMPDIR:-/tmp}/piie-diagnostics.XXXXXX")
cleanup() {
  local status=$?
  if [ "$status" != 0 ]; then
    for log in "$S"/*.log; do [ ! -f "$log" ] || tail -n 25 "$log"; done
  fi
  case "$S" in "${TMPDIR:-/tmp}"/piie-diagnostics.*) rm -rf -- "$S";; esac
}
trap cleanup EXIT
BASE="$S/host"
mkdir -p "$S/bin" "$BASE/releases/old/vendor" "$BASE/releases/previous/vendor" "$BASE/shared/storage/logs" "$BASE/backups/20261029-000000-0b1924ca54ed"
printf '%s\n' 67be85e70349287f58ffeb4bfaafabccb9e953ff > "$BASE/releases/old/RELEASE_SHA"
printf '%040d\n' 1 > "$BASE/releases/previous/RELEASE_SHA"
printf 'synthetic autoloader\n' | tee "$BASE/releases/old/vendor/autoload.php" > "$BASE/releases/previous/vendor/autoload.php"
ln -s releases/old "$BASE/current"
printf 'Successful scheduled command\n' > "$BASE/shared/storage/logs/scheduler.log"
printf '%s\n' '* * * * * root /bin/bash /home/piie/deployments/piie/bin/container-app.sh /srv/piie/current php artisan schedule:run >> /home/piie/deployments/piie/shared/storage/logs/scheduler.log 2>&1' > "$S/cron"
chmod 644 "$S/cron"
B="$BASE/backups/20261029-000000-0b1924ca54ed"
printf '%s\n' '-- Dump completed on synthetic fixture' | gzip > "$B/database.sql.gz"
tar -czf "$B/storage.tar.gz" -C "$BASE/shared" storage
tar -czf "$B/public-uploads.tar.gz" --files-from /dev/null
printf 'synthetic secret not printed\n' > "$B/environment.backup"
(cd "$B" && sha256sum database.sql.gz storage.tar.gz public-uploads.tar.gz environment.backup > SHA256SUMS)
sed "s|BASE=/home/piie/deployments/piie|BASE=$BASE|; s|/etc/cron.d/piie-scheduler|$S/cron|g" "$ROOT/deploy/diagnostics/production-readiness.sh" > "$S/inspect.sh"
cat > "$S/bin/docker" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$DOCKER_LOG"
case "$1" in
  inspect)
    if [ "${2:-}" = piie-db ] && [ "${3:-}" = --format ]; then
      echo 'MARIADB_ROOT_PASSWORD=synthetic-fixture'
      exit 0
    fi
    case "$3" in
      *Config.Healthcheck.Test*) python3 - <<'PY'
import datetime, json, os
text = "OCI runtime exec failed: error adding SSB flag to seccomp filter: SetSSB requires libseccomp" if os.environ.get("DB_PROBE_RUNTIME") == "1" else "unknown probe error"
entry = {"ExitCode": -1, "Output": text, "End": datetime.datetime.now(datetime.timezone.utc).isoformat()}
print(json.dumps({"health":{"Status":"unhealthy","Log":[entry,entry,entry]},"test":["CMD","healthcheck.sh"],"oom_killed":False,"restarting":False,"restart_count":0}))
PY
        ;;
      *State.Running*) [ "${BAD_CONTAINER:-0}" = 0 ] && echo true || echo false;;
      *State.Health*) if { [ "${BAD_DB_HEALTH:-0}" = 1 ] || [ "${DB_PROBE_RUNTIME:-0}" = 1 ]; } && [ "$4" = piie-db ]; then echo unhealthy; else echo healthy; fi;;
      *Mounts*) echo "$FIXTURE_BASE:/srv/piie";;
      *Image*) printf 'sha256:%064d\n' 1;;
      *) exit 91;;
    esac;;
  image) [ "$2" = inspect ];;
  run)
    [[ " $* " = *' --read-only '* && " $* " = *' --pull=never '* && " $* " = *' --cap-drop=ALL '* ]] || exit 92
    if [[ " $* " = *' --entrypoint php '* ]]; then
      [[ " $* " = *',readonly '* && " $* " = *' --user www-data '* ]] || exit 93
      cat >/dev/null
      echo '{"database_read_only":true,"current_pending_migrations":[],"target_pending_migrations":["2026_10_29_090000_create_curriculum_assignment_proposals"]}'
    else
      if [[ " $* " = *' --network piie-stack_private '* && " $* " = *'SELECT 1'* ]]; then exit "${DB_CLIENT_STATUS:-0}"; fi
      [[ " $* " = *' --network none '* ]] || exit 94
    fi;;
  *) exit 95;;
esac
SH
printf '#!/usr/bin/env bash\necho 0\n' > "$S/bin/id"
cat > "$S/bin/df" <<'SH'
#!/usr/bin/env bash
echo 'Filesystem blocks used available capacity mount'
if [ "$1" = -Pi ]; then echo '/dev/fixture 1000000 100 999900 1% /'; else echo "/dev/fixture 10000000000 1 ${FREE_BYTES:-9000000000} 1% /"; fi
SH
printf '#!/usr/bin/env bash\necho "1000000 fixture"\n' > "$S/bin/du"
printf '#!/usr/bin/env bash\nexit "${BAD_CRON:-0}"\n' > "$S/bin/systemctl"
cat > "$S/bin/curl" <<'SH'
#!/usr/bin/env bash
if [[ " $* " = *' --write-out '* ]]; then echo 200; else echo PIIE-APP-OK; fi
SH
chmod +x "$S/bin/"*
export PATH="$S/bin:$PATH" FIXTURE_BASE="$BASE" DOCKER_LOG="$S/docker.log"
snapshot() { find "$BASE" -type f -exec sha256sum {} + | sort; }
snapshot > "$S/before"
bash "$S/inspect.sh" > "$S/healthy.log" 2>&1
grep -q 'READINESS PASS' "$S/healthy.log"
! grep -q 'synthetic secret' "$S/healthy.log"
snapshot > "$S/after"
cmp "$S/before" "$S/after"
echo 'PASS: healthy diagnostics preserve all application, backup and release files and never print environment data'
reject() {
  local name=$1; shift
  if "$@" > "$S/rejected.log" 2>&1; then echo "FAIL: accepted $name"; exit 1; fi
  echo "PASS: rejects $name"
}
reject 'caller arguments' bash "$S/inspect.sh" arbitrary-command
printf '%s\n' 452e21ac5d59ea582357914f2045d558816a1776 > "$BASE/releases/old/RELEASE_SHA"
bash "$S/inspect.sh" > "$S/target.log" 2>&1
grep -q 'READINESS PASS: active=452e21ac5d59ea582357914f2045d558816a1776' "$S/target.log"
# Rejections must precede Docker, database, backup or application probes.
for sha in ad5f0190e68d912a06923fc6ceba623883fb0732 0b1924ca54ed89c72400d08a3e80476888b1e719 452e21ac5d59ea582357914f2045d558816a1777 '452e21ac' '452e21ac5d59ea582357914f2045d558816a1776;id'; do
  printf '%s\n' "$sha" > "$BASE/releases/old/RELEASE_SHA"
  : > "$DOCKER_LOG"
  reject 'unexpected, obsolete, abbreviated or tampered release' env BASELINE="$sha" TARGET="$sha" bash "$S/inspect.sh"
  [ ! -s "$DOCKER_LOG" ] || { echo 'FAIL: rejected SHA reached production probes'; exit 1; }
done
printf '%s\n' 67be85e70349287f58ffeb4bfaafabccb9e953ff > "$BASE/releases/old/RELEASE_SHA"
echo 'PASS: only exact approved baseline and target accepted; environment overrides cannot broaden the gate'
reject 'unhealthy runtime' env BAD_CONTAINER=1 bash "$S/inspect.sh"
reject 'unhealthy database despite evidence collection' env BAD_DB_HEALTH=1 bash "$S/inspect.sh"
grep -q 'FAIL: piie-db health is unhealthy' "$S/rejected.log"
DB_PROBE_RUNTIME=1 bash "$S/inspect.sh" > "$S/compatible-client.log" 2>&1
grep -q 'READINESS PASS' "$S/compatible-client.log"
grep -q 'independent service health=PASS' "$S/compatible-client.log"
reject 'documented runtime probe failure with failed service query' env DB_PROBE_RUNTIME=1 DB_CLIENT_STATUS=1 bash "$S/inspect.sh"
reject 'insufficient disk' env FREE_BYTES=1 bash "$S/inspect.sh"
reject 'inactive scheduler' env BAD_CRON=1 bash "$S/inspect.sh"
touch -d '10 minutes ago' "$BASE/shared/storage/logs/scheduler.log"
reject 'stale scheduler evidence' bash "$S/inspect.sh"
touch "$BASE/shared/storage/logs/scheduler.log"
cp "$B/database.sql.gz" "$S/saved.gz"
printf 'corrupt' >> "$B/database.sql.gz"
reject 'corrupt backup' bash "$S/inspect.sh"
cp "$S/saved.gz" "$B/database.sql.gz"
printf '%040d\n' 2 > "$BASE/releases/old/RELEASE_SHA"
reject 'unapproved live release' bash "$S/inspect.sh"
printf '%s\n' 67be85e70349287f58ffeb4bfaafabccb9e953ff > "$BASE/releases/old/RELEASE_SHA"
cp "$B/SHA256SUMS" "$S/saved-manifest"
sed 's|environment.backup|../../shared/.env|' "$S/saved-manifest" > "$B/SHA256SUMS"
reject 'unsafe backup manifest path' bash "$S/inspect.sh"
cp "$S/saved-manifest" "$B/SHA256SUMS"
touch -d '2 days ago' "$B/SHA256SUMS"
reject 'stale backup evidence' bash "$S/inspect.sh"

# Check the provider-free probe's syntax and read-only transaction boundary.
sed -n "/^<?php$/,/^PHP$/p" "$ROOT/deploy/diagnostics/production-readiness.sh" | sed '$d' > "$S/probe.php"
php -l "$S/probe.php" >/dev/null
grep -Fq 'START TRANSACTION READ ONLY' "$S/probe.php"
! grep -Eq '\$pdo->(exec|query|prepare)\(.?(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE)' "$S/probe.php"
echo 'PASS: provider-free PHP probe parses and uses a read-only database transaction'
