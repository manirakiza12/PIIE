#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
S=$(mktemp -d)
cleanup_fixture() {
  local status=$?
  if [ "$status" != 0 ]; then
    # Only synthetic fixture data; expose failures otherwise hidden by redirects.
    for log in "$S"/*.log; do echo "--- $log"; tail -n 30 "$log"; done
  fi
  rm -rf "$S"
}
trap cleanup_fixture EXIT
mkdir -p "$S/bin" "$S/shared/storage" "$S/releases/old/public/assets/uploads" "$S/incoming" "$S/fixture/vendor" "$S/fixture/public/assets/uploads" "$S/scripts"
SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
printf '%s\n' "$SHA" > "$S/releases/old/RELEASE_SHA"
printf 'preserved' > "$S/shared/.env"
chmod 600 "$S/shared/.env"
printf 'DocumentRoot /srv/piie/current/public\n' > "$S/apache.conf"
ln -s releases/old "$S/current"
printf 'live-upload' > "$S/releases/old/public/assets/uploads/keep.txt"
printf 'baseline' > "$S/fixture/public/assets/uploads/keep.txt"
printf '%s\n' "$SHA" > "$S/fixture/RELEASE_SHA"
touch "$S/fixture/vendor/autoload.php"
tar -czf "$S/incoming/piie-release-fixture.tar.gz" -C "$S/fixture" .
SUM=$(sha256sum "$S/incoming/piie-release-fixture.tar.gz" | cut -d' ' -f1)
# Replace only deployment location and privileged infrastructure commands.
sed "s|BASE=/home/piie/deployments/piie|BASE=$S|; s|chgrp -R www-data|true|; s|chown root:www-data|true|; s|/etc/cron.d/piie-scheduler|$S/scheduler|g; s|/home/piie/deployments/piie/bin/container-app.sh|$S/scripts/container-app.sh|g; s|/home/piie/deployments/piie/shared/storage/logs/scheduler.log|$S/scheduler.log|g" "$ROOT/deploy/remote/container-deploy.sh" > "$S/scripts/container-deploy.sh"
sed "s|BASE=/home/piie/deployments/piie|BASE=$S|" "$ROOT/deploy/remote/container-preflight.sh" > "$S/scripts/container-preflight.sh"
cp "$ROOT/deploy/remote/container-app.sh" "$S/scripts/"
cp "$ROOT/deploy/tests/support/container-docker.sh" "$S/bin/docker"
printf '#!/usr/bin/env bash\necho 0\n' > "$S/bin/id"; chmod +x "$S/bin/id"
chmod +x "$S/bin/docker"
printf '#!/usr/bin/env bash\nexit 0\n' > "$S/bin/flock"
chmod +x "$S/bin/flock"
export PATH="$S/bin:$PATH" FIXTURE_LOG="$S/docker.log" FIXTURE_BASE="$S"
RID=20261014-120000-aaaaaaaaaaaa
bash "$S/scripts/container-deploy.sh" "$RID" "$S/incoming/piie-release-fixture.tar.gz" "$SUM" stage >/dev/null
[ "$(readlink "$S/current")" = releases/old ]
[ "$(stat -c %a "$S/releases/$RID")" = 755 ]
[ ! -d "$S/backups" ]
[ ! -d "$S/shared/public-uploads" ]
! grep -q 'migrate --force' "$S/docker.log"
! grep -q '^kill ' "$S/docker.log"
echo 'PASS: staging preserves current, database and uploads'
set +e
EXPECTED_DEPLOYED_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb bash "$S/scripts/container-deploy.sh" 20261014-120001-aaaaaaaaaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/rejected.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && grep -q 'Live baseline changed' "$S/rejected.log"
echo 'PASS: activation rejects changed baseline before backup or migration'
set +e
FAIL_PREVIEW=1 bash "$S/scripts/container-deploy.sh" 20261014-120002-aaaaaaaaaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" stage > "$S/preview.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && [ "$(readlink "$S/current")" = releases/old ]
echo 'PASS: failed migration preview cannot activate'
set +e
EXPECTED_DEPLOYED_SHA="$SHA" FAIL_HEALTH=1 bash "$S/scripts/container-deploy.sh" 20261014-120003-aaaaaaaaaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/rollback.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && grep -q 'Code rolled back' "$S/rollback.log"
[ "$(readlink "$S/current")" = releases/old ]
[ "$(cat "$S/shared/public-uploads/keep.txt")" = live-upload ]
[ "$(cat "$S/shared/.env")" = preserved ]
[ -s "$S/backups/20261014-120003-aaaaaaaaaaaa/database.sql.gz" ]
grep -q 'run --rm --network piie-stack_private --env-file' "$S/docker.log"
! grep -q 'exec piie-db' "$S/docker.log"
echo 'PASS: failed HTTP health rolls code back, preserving live uploads and environment'
EXPECTED_DEPLOYED_SHA="$SHA" bash "$S/scripts/container-deploy.sh" 20261014-120004-aaaaaaaaaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/success.log" 2>&1
[ "$(readlink "$S/current")" = releases/20261014-120004-aaaaaaaaaaaa ]
[ "$(readlink "$S/current/.env")" = ../../shared/.env ]
[ "$(readlink "$S/current/public/assets/uploads")" = ../../../../shared/public-uploads ]
[ -s "$S/scheduler" ]
cut -d' ' -f7- "$S/scheduler" > "$S/run-scheduler.sh"
bash "$S/run-scheduler.sh"
grep -q 'artisan schedule:run' "$S/docker.log"
! grep -q '^exec ' "$S/docker.log"
grep -q '^kill --signal=USR1 piie-app' "$S/docker.log"
echo 'PASS: successful activation uses container-compatible relative links and scheduler'

: > "$S/docker.log"
EXPECTED_DEPLOYED_SHA="$SHA" PIIE_CODE_ONLY=1 bash "$S/scripts/container-deploy.sh" 20261014-120005-aaaaaaaaaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/code-only.log" 2>&1
! grep -q 'migrate --' "$S/docker.log"
[ "$(readlink "$S/current")" = releases/20261014-120005-aaaaaaaaaaaa ]
echo 'PASS: code-only activation runs no migrations'
set +e
EXPECTED_DEPLOYED_SHA="$SHA" PIIE_CODE_ONLY=1 PENDING_MIGRATIONS=1 bash "$S/scripts/container-deploy.sh" 20261014-120006-aaaaaaaaaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/pending.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && grep -q 'refuses pending migrations' "$S/pending.log"
[ "$(readlink "$S/current")" = releases/20261014-120005-aaaaaaaaaaaa ]
echo 'PASS: code-only activation rejects pending migrations before backup or switching'

set +e
EXPECTED_DEPLOYED_SHA="$SHA" FAIL_RELOAD_ONCE=1 bash "$S/scripts/container-deploy.sh" 20261014-120007-aaaaaaaaaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/reload.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && grep -q 'Code rolled back' "$S/reload.log"
[ "$(readlink "$S/current")" = releases/20261014-120005-aaaaaaaaaaaa ]
! grep -q '^exec ' "$S/docker.log"
while IFS= read -r file; do [ ! -e "$file" ]; done < "$S/env-paths.log"
echo 'PASS: graceful reload failure rolls back and lifts maintenance without exec'
