#!/usr/bin/env bash
# Install a release on the server. Usage:
#   deploy-release.sh <release-id> <artefact.tar.gz> <sha256> [stage|activate]
# `stage` (default) unpacks and prepares the release only: no backup, no
# migration, no symlink change. `activate` additionally backs up, migrates and
# swaps `current`, and refuses unless public_html is wired to current/public.
set -euo pipefail
. "$(dirname "$0")/lib.sh"
RID="${1:?release id}"; ART="${2:?artefact}"; SUM="${3:?sha256}"; MODE="${4:-stage}"
[[ "$RID" =~ ^[0-9]{8}-[0-9]{6}-[0-9a-f]{7,12}$ ]] || die "bad release id"
[ "$MODE" = stage ] || [ "$MODE" = activate ] || die "mode must be stage|activate"

exec 9>"$BASE/.deploy.lock"; flock -n 9 || die "another deployment is running"

[ -f "$BASE/shared/.env" ] || die "shared/.env missing; refusing to generate one (would rotate APP_KEY)"
[ -d "$BASE/shared/storage" ] || die "shared/storage missing"
echo "$SUM  $ART" | sha256sum -c - >/dev/null || die "artefact checksum mismatch"

REL="$BASE/releases/$RID"
[ ! -e "$REL" ] || die "release $RID already exists (releases are append-only)"

if [ "$MODE" = activate ]; then
  WIRED=none
  [ -L "$DOCROOT_LINK" ] && WIRED="$(readlink -f "$DOCROOT_LINK")"
  [ "$WIRED" = "$(readlink -f "$BASE/current/public" 2>/dev/null || echo x)" ] \
    || die "public_html is not wired to current/public; run in stage mode (docs/DEPLOYMENT.md)"
  bash "$(dirname "$0")/backup.sh" "$RID"
fi

log "Unpacking into $RID"
mkdir -p "$REL.tmp"; tar -xzf "$ART" -C "$REL.tmp"
mv "$REL.tmp" "$REL"

ln -sfn "$BASE/shared/.env" "$REL/.env"
mkdir -p "$BASE/shared/storage/app/public" "$BASE/shared/storage/logs" \
         "$BASE/shared/storage/framework/cache/data" "$BASE/shared/storage/framework/sessions" \
         "$BASE/shared/storage/framework/views"
rm -rf "$REL/storage"; mkdir -p "$REL/storage"
for d in app logs framework; do ln -sfn "$BASE/shared/storage/$d" "$REL/storage/$d"; done
ln -sfn "$BASE/shared/storage/app/public" "$REL/public/storage"
chmod -R u+rwX,go-w "$REL"; chmod -R 775 "$REL/bootstrap/cache" 2>/dev/null || true

cd "$REL"
"$PHP" artisan --version >/dev/null || die "artisan does not boot on $PHP"
"$PHP" artisan package:discover --ansi >/dev/null

log "Pending migrations (pretend; nothing executed):"
PRETEND="$("$PHP" artisan migrate --pretend --force 2>&1 || true)"
echo "$PRETEND" | head -60
if echo "$PRETEND" | grep -qiE 'drop +(table|column|database)|truncate|delete +from'; then
  [ "${ALLOW_DESTRUCTIVE_MIGRATIONS:-}" = 1 ] || die "destructive SQL in pending migrations; review, then set ALLOW_DESTRUCTIVE_MIGRATIONS=1"
fi

if [ "$MODE" = stage ]; then
  log "STAGED $RID. No migration run, no symlink changed, site untouched."
  exit 0
fi

PREV="$(readlink "$BASE/current" 2>/dev/null || true)"
log "Migrating (forward-only; never fresh/wipe)"
"$PHP" artisan migrate --force || { log "migration failed; current NOT switched"; exit 1; }
"$PHP" artisan config:cache; "$PHP" artisan view:clear

ln -sfn "$REL" "$BASE/current.new"; mv -Tf "$BASE/current.new" "$BASE/current"
log "current -> $RID (previous: ${PREV:-none})"
"$PHP" artisan queue:restart >/dev/null 2>&1 || true

ok=0
for i in 1 2 3 4 5 6; do
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 000)
  log "health attempt $i: HTTP $code"
  if [ "$code" = 200 ]; then ok=1; break; fi
  sleep 5
done
if [ "$ok" != 1 ]; then
  log "HEALTH CHECK FAILED - restoring previous release"
  if [ -n "$PREV" ]; then ln -sfn "$PREV" "$BASE/current.new" && mv -Tf "$BASE/current.new" "$BASE/current"; fi
  log "Code rolled back. Database NOT restored automatically (see docs/DEPLOYMENT.md)."
  exit 1
fi

ls -1dt "$BASE"/releases/*/ | tail -n +$((KEEP_RELEASES+1)) | while read -r d; do
  [ "$(readlink -f "$d")" = "$(readlink -f "$BASE/current")" ] || rm -rf -- "$d"
done
log "DEPLOYED $RID"
