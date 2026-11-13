#!/usr/bin/env bash
# CI-REQUIRES-ROOT: real ownership and mode changes on synthetic data.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
[ "$(id -u)" = 0 ] || { echo "Run in an isolated Linux root environment" >&2; exit 1; }
if [ "${1:-}" != --private-namespace ]; then exec unshare --mount --propagation private -- bash "$0" --private-namespace; fi
source "$ROOT/deploy/tests/support/secure-fixture.sh"
fixture_init
trap fixture_cleanup EXIT
RID=20261014-120000-f23cff8aaaaa
bash "$SCRIPTS/container-deploy.sh" "$RID" "$S/incoming/piie-release-fixture.tar.gz" "$SUM" stage >/dev/null
[ "$(readlink "$S/current")" = releases/f23cff8 ]
[ "$(stat -c %a "$S/.secure/releases/$RID")" = 755 ]
[ ! -d "$S/.secure/private/backup-unused" ]
[ ! -d "$S/shared/public-uploads" ]
! grep -q 'migrate --force' "$S/docker.log"
! grep -q '^kill ' "$S/docker.log"
echo 'PASS: staging preserves current, database and uploads'
set +e
EXPECTED_RELEASE_SHA=f23cff8aaaaa1111111111111111111111111111 bash "$SCRIPTS/container-deploy.sh" 20261014-120009-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" stage > "$S/full-sha.log" 2>&1
code=$?
set -e
[ "$code" -ne 0 ] && grep -q 'approved full SHA' "$S/full-sha.log"
[ "$(readlink "$S/current")" = releases/f23cff8 ]
echo 'PASS: candidate with matching short prefix but different full approved SHA is refused'
set +e
EXPECTED_DEPLOYED_SHA="$SHA" EXPECTED_ROLLBACK_RELEASE=unrelated bash "$SCRIPTS/container-deploy.sh" 20261014-120011-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/wrong-fallback.log" 2>&1
code=$?
set -e
[ "$code" -ne 0 ] && grep -q 'rollback release is not the active baseline' "$S/wrong-fallback.log"
[ "$(readlink "$S/current")" = releases/f23cff8 ] && [ ! -d "$S/.secure/private/backup-unused" ]
echo 'PASS: unrelated rollback input is refused before backup, migration or current changes'
# Reproduce the production state that broke uploads: a durable upload tree left
# root:root 0700 by an older deployment. It must be backed up and repaired
# before the first activation writes into it.
mkdir -p "$S/shared/public-uploads/legacy/nested"
printf 'preexisting-shared-upload\n' > "$S/shared/public-uploads/legacy/nested/existing.pdf"
chown -R root:root "$S/shared/public-uploads"
find "$S/shared/public-uploads" -type d -exec chmod 700 {} +
find "$S/shared/public-uploads" -type f -exec chmod 600 {} +
[ "$(stat -c '%U:%G:%a' "$S/shared/public-uploads/legacy/nested")" = 'root:root:700' ]
set +e
EXPECTED_DEPLOYED_SHA=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb bash "$SCRIPTS/container-deploy.sh" 20261014-120001-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/rejected.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && grep -q 'Live baseline changed' "$S/rejected.log"
echo 'PASS: activation rejects changed baseline before backup or migration'
# No privileged caller (including direct helper invocation) may omit approval.
set +e
EXPECTED_DEPLOYED_SHA=auto EXPECTED_ROLLBACK_RELEASE=auto bash "$SCRIPTS/container-deploy.sh" 20261014-120013-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/no-receipt.log" 2>&1
code=$?
set -e
[ "$code" -ne 0 ] && [ "$(readlink "$S/current")" = releases/f23cff8 ]
grep -q 'Unsafe directory: .*authorized' "$S/no-receipt.log"
[ ! -e "$S/.secure/releases/20261014-120013-f23cff8aaaaa" ]
[ ! -e "$S/.secure/private/backup-20261014-120013-f23cff8aaaaa" ]
! grep -qE 'artisan down|mariadb-dump|migrate --force' "$S/docker.log"
echo 'PASS: direct helper auto activation without receipt fails before mutation'
# Synthetic fixture authorization only; never creates a production receipt.
fixture_authorize() {
  local active=${1:-f23cff8} code_only=${2:-0}
  mkdir -p "$S/.secure/private/authorized"
  printf '%s\n' piie-deployment-approval-v1 "$SHA" "$SUM" "$SHA" "$active" "$code_only" 0 > "$S/.secure/private/authorized/$SHA.approved"
  chmod 600 "$S/.secure/private/authorized/$SHA.approved"
}
fixture_authorize
set +e
FAIL_PREVIEW=1 bash "$SCRIPTS/container-deploy.sh" 20261014-120002-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" stage > "$S/preview.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && [ "$(readlink "$S/current")" = releases/f23cff8 ]
echo 'PASS: failed migration preview cannot activate'
set +e
EXPECTED_DEPLOYED_SHA="$SHA" FAIL_HEALTH=1 bash "$SCRIPTS/container-deploy.sh" 20261014-120003-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/rollback.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && grep -q 'Code rolled back' "$S/rollback.log"
[ "$(readlink "$S/current")" = .secure/releases/f23cff8 ]
[ "$(cat "$S/shared/public-uploads/keep.txt")" = live-upload ]
[ "$(stat -c '%U:%G:%a' "$S/shared/public-uploads")" = 'root:www-data:2775' ]
[ "$(stat -c '%U:%G:%a' "$S/shared/public-uploads/legacy/nested")" = 'root:www-data:2775' ]
[ "$(stat -c '%U:%G:%a' "$S/shared/public-uploads/legacy/nested/existing.pdf")" = 'root:www-data:664' ]
[ "$(cat "$S/shared/public-uploads/legacy/nested/existing.pdf")" = preexisting-shared-upload ]
[ "$(cat "$S/shared/.env")" = preserved ]
[ -s "$S/.secure/private/backup-20261014-120003-f23cff8aaaaa/database.sql.gz" ]
[ -s "$S/.secure/private/backup-20261014-120003-f23cff8aaaaa/storage.tar.gz" ]
tar -tzf "$S/.secure/private/backup-20261014-120003-f23cff8aaaaa/public-uploads.tar.gz" > "$S/uploads-backup.list"
grep -qx './keep.txt' "$S/uploads-backup.list"
grep -qx './legacy/nested/existing.pdf' "$S/uploads-backup.list"
[ "$(tar -xOzf "$S/.secure/private/backup-20261014-120003-f23cff8aaaaa/public-uploads.tar.gz" ./keep.txt)" = live-upload ]
[ "$(tar -xOzf "$S/.secure/private/backup-20261014-120003-f23cff8aaaaa/public-uploads.tar.gz" ./legacy/nested/existing.pdf)" = preexisting-shared-upload ]
tar -tzf "$S/.secure/private/backup-20261014-120003-f23cff8aaaaa/storage.tar.gz" > "$S/storage-backup.list"
grep -qx 'storage/app/public/student-upload.pdf' "$S/storage-backup.list"
! grep -q '^storage/logs/.*\.log$' "$S/storage-backup.list"
grep -q 'run --rm --network piie-deploy_private --env-file' "$S/docker.log"
! grep -q 'exec piie-db' "$S/docker.log"
echo 'PASS: failed HTTP health rolls code back, repairing a root:root 0700 upload tree and backing up both upload locations'
EXPECTED_DEPLOYED_SHA="$SHA" bash "$SCRIPTS/container-deploy.sh" 20261014-120004-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/success.log" 2>&1
[ "$(readlink "$S/current")" = .secure/releases/20261014-120004-f23cff8aaaaa ]
[ "$(readlink "$S/current/.env")" = ../../../shared/.env ]
[ "$(readlink "$S/current/public/assets/uploads")" = ../../../../../shared/public-uploads ]
bash "$SCRIPTS/run-scheduler.sh"
grep -q 'artisan schedule:run' "$S/docker.log"
! grep -q '^exec ' "$S/docker.log"
grep -q '^kill --signal=USR1 piie-app' "$S/docker.log"
echo 'PASS: successful activation uses container-compatible relative links and scheduler'

: > "$S/docker.log"
fixture_authorize 20261014-120004-f23cff8aaaaa 1
EXPECTED_DEPLOYED_SHA="$SHA" PIIE_CODE_ONLY=1 bash "$SCRIPTS/container-deploy.sh" 20261014-120005-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/code-only.log" 2>&1
! grep -q 'migrate --' "$S/docker.log"
[ "$(readlink "$S/current")" = .secure/releases/20261014-120005-f23cff8aaaaa ]
echo 'PASS: code-only activation runs no migrations'
fixture_authorize 20261014-120005-f23cff8aaaaa 1
set +e
EXPECTED_DEPLOYED_SHA="$SHA" PIIE_CODE_ONLY=1 PENDING_MIGRATIONS=1 bash "$SCRIPTS/container-deploy.sh" 20261014-120006-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/pending.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && grep -q 'refuses pending migrations' "$S/pending.log"
[ "$(readlink "$S/current")" = .secure/releases/20261014-120005-f23cff8aaaaa ]
echo 'PASS: code-only activation rejects pending migrations before backup or switching'

fixture_authorize 20261014-120005-f23cff8aaaaa 0
set +e
EXPECTED_DEPLOYED_SHA="$SHA" FAIL_RELOAD_ONCE=1 bash "$SCRIPTS/container-deploy.sh" 20261014-120007-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/reload.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && grep -q 'Code rolled back' "$S/reload.log"
[ "$(readlink "$S/current")" = .secure/releases/20261014-120005-f23cff8aaaaa ]
! grep -q '^exec ' "$S/docker.log"
while IFS= read -r file; do [ ! -e "$file" ]; done < "$S/env-paths.log"
echo 'PASS: graceful reload failure rolls back and lifts maintenance without exec'

# A genuine archive write/read error must still abort activation before the live
# symlink is switched. This wrapper fails only the storage backup archive and
# delegates every other tar operation to the host's real tar binary.
REAL_TAR=$(command -v tar)
cat > "$TESTROOT/bin/tar" <<'STUB'
#!/usr/bin/env bash
if [ "${FAIL_STORAGE_ARCHIVE:-0}" = 1 ] && [[ "$*" == *"/storage.tar.gz"* ]]; then
  echo 'tar: synthetic storage archive write error' >&2
  exit 2
fi
exec "$REAL_TAR" "$@"
STUB
chmod +x "$TESTROOT/bin/tar"
export REAL_TAR
set +e
EXPECTED_DEPLOYED_SHA="$SHA" FAIL_STORAGE_ARCHIVE=1 bash "$SCRIPTS/container-deploy.sh" 20261014-120008-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/archive-error.log" 2>&1
code=$?
set -e
[ "$code" -ne 0 ] && grep -q 'synthetic storage archive write error' "$S/archive-error.log"
[ "$(readlink "$S/current")" = .secure/releases/20261014-120005-f23cff8aaaaa ]
echo 'PASS: meaningful storage archive errors remain fatal before activation'
set +e
EXPECTED_DEPLOYED_SHA="$SHA" FAIL_RELOAD_ALWAYS=1 bash "$SCRIPTS/container-deploy.sh" 20261014-120010-f23cff8aaaaa "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate > "$S/recovery-failed.log" 2>&1
code=$?
set -e
[ "$code" -ne 0 ] && grep -q 'CRITICAL: prior pointer restored but application reload failed' "$S/recovery-failed.log"
! grep -q 'Code rolled back;' "$S/recovery-failed.log"
[ "$(readlink "$S/current")" = .secure/releases/20261014-120005-f23cff8aaaaa ]
echo 'PASS: failed rollback reload is reported critical without a false recovery success'

RID=20261014-120012-f23cff8aaaaa
bash "$SCRIPTS/container-deploy.sh" "$RID" "$S/incoming/piie-release-fixture.tar.gz" "$SUM" stage > "$S/restage.log" 2>&1
fixture_authorize 20261014-120005-f23cff8aaaaa 1
EXPECTED_DEPLOYED_SHA="$SHA" PIIE_CODE_ONLY=1 bash "$SCRIPTS/container-deploy.sh" "$RID" "$S/incoming/piie-release-fixture.tar.gz" "$SUM" activate >> "$S/restage.log" 2>&1
[ "$(readlink "$S/current")" = ".secure/releases/$RID" ]
[ -n "$(find "$S/.secure/private" -path '*/previous-release/RELEASE_SHA' -print -quit)" ]
echo 'PASS: root stage then activate of the same approved RID rebuilds verified code and preserves prior protected snapshot'
