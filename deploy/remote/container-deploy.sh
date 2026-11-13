#!/usr/bin/env bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
# Docker runtime counterpart to deploy-release.sh. No container/volume recreation.
set -euo pipefail
BASE=/home/piie/deployments/piie
source "$(dirname "$0")/container-app.sh"
RID=${1:?release id}; ART=${2:?archive}; SUM=${3:?checksum}; MODE=${4:-stage}
fail() { echo "ERROR: $*" >&2; exit 1; }
[[ "$RID" =~ ^[0-9]{8}-[0-9]{6}-[0-9a-f]{12}$ ]] || fail 'Invalid release id'
[[ "$SUM" =~ ^[0-9a-f]{64}$ ]] || fail 'Invalid checksum'
[ "$MODE" = stage ] || [ "$MODE" = activate ] || fail 'Invalid mode'
case "$ART" in "$BASE"/incoming/piie-release-*.tar.gz) ;; *) fail 'Archive outside incoming';; esac
[[ "${EXPECTED_RELEASE_SHA:-}" =~ ^[0-9a-f]{40}$ ]] || fail 'Full approved candidate SHA required'

source "$(dirname "$0")/secure-handoff.sh"
verify_runtime_mount
deployment_lock
if [ "$MODE" = activate ]; then authorize_activation; fi
bash "$(dirname "$0")/container-preflight.sh"
PREV=$(readlink "$BASE/current")
RECOVERY=$PREV
ENV_SUM=$(sha256sum "$BASE/shared/.env" | cut -d' ' -f1)
ENV_PERM=$(stat -c '%u:%g:%a' "$BASE/shared/.env")
if [ "$MODE" = activate ]; then
  # Already resolved and independently authorized under the lock above.
  ACTIVE_ID=$EXPECTED_ROLLBACK_RELEASE
  if [ "$PREV" = releases/f23cff8 ]; then
    compare_legacy_baseline "$BASE/current" "$BASE/.secure/releases/f23cff8" "$(sed -n '2p' "$BASE/.secure/private/f23cff8.approved")" || fail 'Legacy baseline code changed'
  fi
  RECOVERY=".secure/releases/$ACTIVE_ID"
fi
[ "$PREV" != ".secure/releases/$RID" ] || fail 'Candidate is already current'
prepare_release "$RID" "$ART" "$SUM" "$EXPECTED_RELEASE_SHA"
SHA=$EXPECTED_RELEASE_SHA
artisan() { app_run "/srv/piie/.secure/releases/$RID" php artisan "$@"; }
artisan package:discover --no-interaction > "$REL/package-discover.txt" 2>&1 || { echo 'PACKAGE_DISCOVER_FAILED:'; cat "$REL/package-discover.txt"; exit 1; }
cat "$REL/package-discover.txt"
artisan --version
echo "=== read-only required-tables preflight ==="
bash "$(dirname "$0")/check-required-tables.sh" "/srv/piie/.secure/releases/$RID" || fail 'Required tables missing in target database'
if [ "${PIIE_CODE_ONLY:-0}" = 1 ]; then
  artisan migrate:status > "$REL/migration-status.txt"
  ! grep -q Pending "$REL/migration-status.txt" || fail 'Code-only release refuses pending migrations'
  echo 'Code-only release: no migrations will run'
else
artisan migrate --pretend --force --no-interaction > "$REL/migration-preview.txt" 2>&1 || { echo 'MIGRATION_PRETEND_FAILED:'; cat "$REL/migration-preview.txt"; exit 1; }
  if grep -qiE 'drop +(table|column|database)|truncate|delete +from' "$REL/migration-preview.txt"; then fail 'Destructive migration SQL requires review'; fi
fi
if [ "$MODE" = stage ]; then echo "STAGED $RID: live release, uploads and database unchanged"; exit 0; fi
BACK="$BASE/.secure/private/backup-$RID"
mkdir -m 700 "$BACK"  # never overwrite a previous activation backup
(
  db_env=$(mktemp)
  chmod 600 "$db_env"
  trap 'rm -f "$db_env"' EXIT
  docker inspect piie-db --format '{{range .Config.Env}}{{println .}}{{end}}' |
    grep -E '^MARIADB_(DATABASE|ROOT_PASSWORD)=' > "$db_env"
  docker run --rm --network piie-deploy_private --env-file "$db_env" mariadb:11.4 sh -ec \
    'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump --host=piie-db --user=root --single-transaction --routines --triggers --events --hex-blob "$MARIADB_DATABASE"' |
    gzip > "$BACK/database.sql.gz"
)
gzip -t "$BACK/database.sql.gz"; [ "$(stat -c %s "$BACK/database.sql.gz")" -gt 100 ] || fail 'Empty database backup'
# Laravel log files are append-only and may change while tar reads them (the
# scheduler writes storage/logs/scheduler.log every minute). They are operational
# output, not persistent user data, so omit only .log files under storage/logs.
# Keep the rest of storage, especially storage/app uploads, and let any other tar
# error remain fatal under set -e.
tar -czf "$BACK/storage.tar.gz" --exclude='storage/logs/*.log' -C "$BASE/shared" storage
tar -tzf "$BACK/storage.tar.gz" >/dev/null
SHARED="$BASE/shared/public-uploads"
LIVE_UPLOADS=$(readlink -f "$BASE/current/public/assets/uploads")
case "$LIVE_UPLOADS" in "$BASE"/releases/f23cff8/public/assets/uploads|"$BASE"/.secure/releases/*/public/assets/uploads|"$SHARED") ;; *) fail 'Unexpected live uploads location';; esac
# The live tree and the durable shared tree can each hold files the other lacks
# (first activation after the durable-upload change, or an interrupted earlier
# attempt). Archive their union so a restore never loses either side. The
# pre-existing shared file wins a path collision, matching the merge order below.
UPLOAD_BACKUP=$(mktemp -d "$BASE/.upload-backup.XXXXXXXX")
cleanup_upload_backup() { rm -rf -- "$UPLOAD_BACKUP"; }
trap cleanup_upload_backup EXIT
if [ -d "$SHARED" ] && [ ! -L "$SHARED" ]; then
  validate_uploads "$SHARED"
  python3 -I "$(dirname "$0")/merge-uploads.py" "$SHARED" "$UPLOAD_BACKUP"
fi
if [ "$LIVE_UPLOADS" != "$SHARED" ] && [ -d "$LIVE_UPLOADS" ] && [ ! -L "$LIVE_UPLOADS" ]; then
  validate_uploads "$LIVE_UPLOADS"
  python3 -I "$(dirname "$0")/merge-uploads.py" "$LIVE_UPLOADS" "$UPLOAD_BACKUP"
fi
tar -czf "$BACK/public-uploads.tar.gz" -C "$UPLOAD_BACKUP" .
tar -tzf "$BACK/public-uploads.tar.gz" >/dev/null
rm -rf -- "$UPLOAD_BACKUP"
trap - EXIT
cp -p "$BASE/shared/.env" "$BACK/environment.backup"; chmod 600 "$BACK/environment.backup"
(cd "$BACK" && sha256sum database.sql.gz storage.tar.gz public-uploads.tar.gz environment.backup > SHA256SUMS && sha256sum -c SHA256SUMS)
[ ! -L "$SHARED" ] || fail 'Shared uploads cannot be a link'
mkdir -p "$SHARED"
# Repair the existing tree BEFORE copying anything into it. Older deployments
# left this tree root:root 0700, which www-data cannot traverse. Validating and
# repairing first also makes an unsafe pre-existing tree fail before any write.
set_upload_permissions "$SHARED"
merge_uploads() {
  python3 -I "$(dirname "$0")/merge-uploads.py" "$1" "$SHARED" || fail 'Cannot safely merge uploads'
}

SWITCHED=0; MAINTENANCE=0
cleanup() {
  local status=$?
  if [ "$status" -ne 0 ] && [ "$SWITCHED" = 1 ]; then
    if ! switch_pointer "$RECOVERY"; then
      echo 'CRITICAL: code pointer restoration failed; administrator intervention required' >&2
      return 1
    fi
    if ! app_apache_reload; then
      echo 'CRITICAL: prior pointer restored but application reload failed; runtime recovery unverified' >&2
      return 1
    fi
    echo 'Code rolled back; forward database migrations retained.' >&2
  fi
  if [ "$MAINTENANCE" = 1 ]; then
    app_run /srv/piie/current php artisan up || { echo 'CRITICAL: maintenance recovery failed' >&2; return 1; }
  fi
  return "$status"
}
trap cleanup EXIT
app_run /srv/piie/current php artisan down --retry=30
MAINTENANCE=1
# Copy runtime files under maintenance, then candidate baseline. Existing files win.
if [ "$LIVE_UPLOADS" != "$SHARED" ]; then merge_uploads "$LIVE_UPLOADS"; fi
merge_uploads "$REL/public/assets/uploads"
set_upload_permissions "$SHARED"
link_uploads "$REL"
# The rollback copy must see the same durable user uploads as the candidate.
link_uploads "$BASE/$RECOVERY"
mkdir -p "$BASE/shared/storage/app/public"
if [ "${PIIE_CODE_ONLY:-0}" != 1 ]; then artisan migrate --force --no-interaction > "$REL/migration-run.txt" 2>&1 || { echo 'MIGRATION_RUN_FAILED:'; cat "$REL/migration-run.txt"; exit 1; }; cat "$REL/migration-run.txt"; fi
artisan migrate:status | tee "$REL/migration-status.txt"
! grep -q Pending "$REL/migration-status.txt" || fail 'Migrations remain pending'
artisan config:clear
artisan view:clear
artisan route:clear
[ "$(sha256sum "$BASE/shared/.env" | cut -d' ' -f1)" = "$ENV_SUM" ] || fail 'Environment changed'
[ "$(stat -c '%u:%g:%a' "$BASE/shared/.env")" = "$ENV_PERM" ] || fail '.env permissions changed'
switch_pointer ".secure/releases/$RID"
    SWITCHED=1
    app_apache_reload
    artisan up; MAINTENANCE=0
    artisan config:cache
    artisan route:cache
    artisan view:cache
    for url in /health / /login /careers /applicant/login /assets/css/typography.css /assets/css/workspace-ui.css; do
  app_run /srv/piie/current curl --fail --silent --show-error --retry 3 --retry-delay 2 -H 'Host: piie.ac.ug' "http://127.0.0.1$url" -o /dev/null || fail "HTTP smoke failed: $url"
done
# Scheduler is a separately installed, mount-gated systemd timer. Never rewrite
# cron during activation or open a root host log through a mutable path.
echo "DEPLOYED $SHA; verified backup $BACK; previous $PREV retained"
