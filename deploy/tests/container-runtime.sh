#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
S=$(mktemp -d)
trap 'rm -rf "$S"' EXIT
mkdir -p "$S/bin" "$S/shared/storage" "$S/releases/old/public/assets/uploads" "$S/incoming" "$S/fixture/vendor" "$S/fixture/public/assets/uploads" "$S/scripts"
SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
printf '%s\n' "$SHA" > "$S/releases/old/RELEASE_SHA"
printf 'preserved' > "$S/shared/.env"
ln -s releases/old "$S/current"
printf 'live-upload' > "$S/releases/old/public/assets/uploads/keep.txt"
printf 'baseline' > "$S/fixture/public/assets/uploads/keep.txt"
printf '%s\n' "$SHA" > "$S/fixture/RELEASE_SHA"
touch "$S/fixture/vendor/autoload.php"
tar -czf "$S/incoming/piie-release-fixture.tar.gz" -C "$S/fixture" .
SUM=$(sha256sum "$S/incoming/piie-release-fixture.tar.gz" | cut -d' ' -f1)
# Replace only deployment location and privileged infrastructure commands.
sed "s|BASE=/home/piie/deployments/piie|BASE=$S|; s|chgrp -R www-data|true|; s|chown root:www-data|true|; s|/etc/cron.d/piie-scheduler|$S/scheduler|g" "$ROOT/deploy/remote/container-deploy.sh" > "$S/scripts/container-deploy.sh"
printf '#!/usr/bin/env bash\nexit 0\n' > "$S/scripts/container-preflight.sh"
cat > "$S/bin/docker" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FIXTURE_LOG"
case "$*" in
  *'inspect piie-db --format'*) printf 'MARIADB_DATABASE=forge\nMARIADB_ROOT_PASSWORD=test-password\n';;
  *mariadb-dump*) head -c 2000 /dev/urandom;;
  *'migrate --pretend'*) [ "${FAIL_PREVIEW:-0}" = 0 ];;
  *'curl --fail'*) [ "${FAIL_HEALTH:-0}" = 0 ];;
  *) exit 0;;
esac
STUB
chmod +x "$S/bin/docker"
printf '#!/usr/bin/env bash\nexit 0\n' > "$S/bin/flock"
chmod +x "$S/bin/flock"
export PATH="$S/bin:$PATH" FIXTURE_LOG="$S/docker.log"
RID=20261014-120000-aaaaaaaaaaaa
bash "$S/scripts/container-deploy.sh" "$RID" "$S/incoming/piie-release-fixture.tar.gz" "$SUM" stage >/dev/null
[ "$(readlink "$S/current")" = releases/old ]
[ "$(stat -c %a "$S/releases/$RID")" = 755 ]
[ ! -d "$S/backups" ]
[ ! -d "$S/shared/public-uploads" ]
! grep -q 'migrate --force' "$S/docker.log"
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
echo 'PASS: successful activation uses container-compatible relative links and scheduler'
