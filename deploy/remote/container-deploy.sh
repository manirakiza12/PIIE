#!/usr/bin/env bash
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
bash "$(dirname "$0")/container-preflight.sh"
exec 9>"$BASE/.deploy.lock"; flock -n 9 || fail 'Another deployment is active'
PREV=$(readlink "$BASE/current")
if [ "$MODE" = activate ]; then
  [[ "${EXPECTED_DEPLOYED_SHA:-}" =~ ^[0-9a-f]{40}$ ]] || fail 'Verified baseline SHA required'
  [ "$(cat "$BASE/current/RELEASE_SHA")" = "$EXPECTED_DEPLOYED_SHA" ] || fail 'Live baseline changed'
fi
printf '%s  %s\n' "$SUM" "$ART" | sha256sum -c -
# Refuse traversal, links, devices and secret files before extracting as root.
tar -tzf "$ART" | awk '/^\// || /(^|\/)\.\.($|\/)/ || /(^|\/)\.env$/ {bad=1} END {exit bad}' || fail 'Unsafe archive paths'
tar -tvzf "$ART" | awk 'substr($0,1,1)!="-" && substr($0,1,1)!="d" {bad=1} END {exit bad}' || fail 'Archive links or special files refused'
REL="$BASE/releases/$RID"
[ ! -e "$REL" ] || fail 'Release already exists'
mkdir "$REL"
tar -xzf "$ART" -C "$REL" --no-same-owner
# CI's mktemp staging directory is 0700 and tar preserves its '.' entry.
# Apache's www-data user must be able to traverse the extracted release root.
chmod 755 "$REL"
SHA=$(cat "$REL/RELEASE_SHA")
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] && [ "${SHA:0:12}" = "${RID##*-}" ] || fail 'Release SHA does not match ID'
[ -f "$REL/vendor/autoload.php" ] || fail 'Production dependencies missing'
ENV_SUM=$(sha256sum "$BASE/shared/.env" | cut -d' ' -f1)
ln -s ../../shared/.env "$REL/.env"
# The archive excludes runtime storage; preserve any baseline in the candidate.
if [ -d "$REL/storage" ]; then mv "$REL/storage" "$REL/storage-baseline"; fi
ln -s ../../shared/storage "$REL/storage"
mkdir -p "$REL/bootstrap/cache"
chown root:www-data "$REL/bootstrap/cache"; chmod 2775 "$REL/bootstrap/cache"
if [ -e "$REL/public/storage" ]; then fail 'Unexpected packaged public/storage'; fi
ln -s ../../../shared/storage/app/public "$REL/public/storage"
artisan() { app_run "/srv/piie/releases/$RID" php artisan "$@"; }
artisan package:discover --no-interaction >/dev/null
artisan --version
if [ "${PIIE_CODE_ONLY:-0}" = 1 ]; then
  artisan migrate:status > "$REL/migration-status.txt"
  ! grep -q Pending "$REL/migration-status.txt" || fail 'Code-only release refuses pending migrations'
  echo 'Code-only release: no migrations will run'
else
  artisan migrate --pretend --force --no-interaction > "$REL/migration-preview.txt"
  if grep -qiE 'drop +(table|column|database)|truncate|delete +from' "$REL/migration-preview.txt"; then fail 'Destructive migration SQL requires review'; fi
fi
if [ "$MODE" = stage ]; then echo "STAGED $RID: live release, uploads and database unchanged"; exit 0; fi
BACK="$BASE/backups/$RID"
install -d -m 700 "$BACK"
(
  db_env=$(mktemp)
  chmod 600 "$db_env"
  trap 'rm -f "$db_env"' EXIT
  docker inspect piie-db --format '{{range .Config.Env}}{{println .}}{{end}}' |
    grep -E '^MARIADB_(DATABASE|ROOT_PASSWORD)=' > "$db_env"
  docker run --rm --network piie-stack_private --env-file "$db_env" mariadb:11.4 sh -ec \
    'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump --host=piie-db --user=root --single-transaction --routines --triggers --events --hex-blob "$MARIADB_DATABASE"' |
    gzip > "$BACK/database.sql.gz"
)
gzip -t "$BACK/database.sql.gz"; [ "$(stat -c %s "$BACK/database.sql.gz")" -gt 100 ] || fail 'Empty database backup'
tar -czf "$BACK/storage.tar.gz" -C "$BASE/shared" storage
tar -tzf "$BACK/storage.tar.gz" >/dev/null
LIVE_UPLOADS=$(readlink -f "$BASE/current/public/assets/uploads")
case "$LIVE_UPLOADS" in "$BASE"/releases/*/public/assets/uploads|"$BASE"/shared/public-uploads) ;; *) fail 'Unexpected live uploads location';; esac
tar -czf "$BACK/public-uploads.tar.gz" -C "$LIVE_UPLOADS" .
tar -tzf "$BACK/public-uploads.tar.gz" >/dev/null
cp -p "$BASE/shared/.env" "$BACK/environment.backup"; chmod 600 "$BACK/environment.backup"
(cd "$BACK" && sha256sum database.sql.gz storage.tar.gz public-uploads.tar.gz environment.backup > SHA256SUMS && sha256sum -c SHA256SUMS)
SHARED="$BASE/shared/public-uploads"
[ ! -L "$SHARED" ] || fail 'Shared uploads cannot be a link'
mkdir -p "$SHARED"
merge_uploads() {
  local source=$1 path relative destination
  [ -d "$source" ] && [ ! -L "$source" ] || fail 'Invalid upload source'
  [ -z "$(find "$source" -type l -print -quit)" ] || fail 'Upload symlinks refused'
  [ -z "$(find "$SHARED" -type l -print -quit)" ] || fail 'Shared upload symlinks refused'
  while IFS= read -r -d '' path; do
    relative=${path#"$source"/}; destination="$SHARED/$relative"
    if [ -d "$path" ]; then mkdir -p "$destination";
    elif [ -f "$path" ]; then [ -e "$destination" ] || cp -p "$path" "$destination";
    else fail 'Unexpected upload entry'; fi
  done < <(find "$source" -mindepth 1 -print0)
}
SWITCHED=0; MAINTENANCE=0
cleanup() {
  local status=$?
  if [ "$status" -ne 0 ] && [ "$SWITCHED" = 1 ]; then
    ln -s "$PREV" "$BASE/current.rollback"; mv -Tf "$BASE/current.rollback" "$BASE/current"
    app_apache_reload || true
    echo 'Code rolled back; forward database migrations retained.' >&2
  fi
  if [ "$MAINTENANCE" = 1 ]; then app_run /srv/piie/current php artisan up || true; fi
}
trap cleanup EXIT
app_run /srv/piie/current php artisan down --retry=30
MAINTENANCE=1
# Copy runtime files under maintenance, then candidate baseline. Existing files win.
if [ "$LIVE_UPLOADS" != "$SHARED" ]; then merge_uploads "$LIVE_UPLOADS"; fi
merge_uploads "$REL/public/assets/uploads"
chgrp -R www-data "$SHARED"
find "$SHARED" -type d -exec chmod 2775 {} +
find "$SHARED" -type f -exec chmod 664 {} +
mv "$REL/public/assets/uploads" "$REL/public/assets/uploads-baseline"
ln -s ../../../../shared/public-uploads "$REL/public/assets/uploads"
mkdir -p "$BASE/shared/storage/app/public"
if [ "${PIIE_CODE_ONLY:-0}" != 1 ]; then artisan migrate --force --no-interaction; fi
artisan migrate:status | tee "$REL/migration-status.txt"
! grep -q Pending "$REL/migration-status.txt" || fail 'Migrations remain pending'
artisan config:clear
artisan view:clear
artisan route:clear
[ "$(sha256sum "$BASE/shared/.env" | cut -d' ' -f1)" = "$ENV_SUM" ] || fail 'Environment changed'
ln -s "releases/$RID" "$BASE/current.new"; mv -Tf "$BASE/current.new" "$BASE/current"
SWITCHED=1
app_apache_reload
artisan up; MAINTENANCE=0
for url in /health / /login /careers /applicant/login /assets/css/typography.css /assets/css/workspace-ui.css; do
  app_run /srv/piie/current curl --fail --silent --show-error --retry 3 --retry-delay 2 -H 'Host: piie.ac.ug' "http://127.0.0.1$url" -o /dev/null || fail "HTTP smoke failed: $url"
done
# Host cron executes the scheduler in the same runtime as HTTP, without host PHP.
printf '%s\n' '* * * * * root /bin/bash /home/piie/deployments/piie/bin/container-app.sh /srv/piie/current php artisan schedule:run >> /home/piie/deployments/piie/shared/storage/logs/scheduler.log 2>&1' > /etc/cron.d/piie-scheduler
chmod 644 /etc/cron.d/piie-scheduler
echo "DEPLOYED $SHA; verified backup $BACK; previous $PREV retained"
