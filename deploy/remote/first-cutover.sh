#!/usr/bin/env bash
# ONE-TIME first cutover: legacy flat public_html -> thin shell serving a release.
# Deliberately separate from deploy-release.sh, whose activate guard is unchanged.
#
#   first-cutover.sh check   <release-id>   read-only: every prerequisite, changes nothing
#   first-cutover.sh run     <release-id>   performs the cutover; needs the typed confirmation:
#       PIIE_CONFIRM_FIRST_CUTOVER="CUTOVER <release-id> <db-name>"
#
# The release must already be STAGED with `deploy-release.sh ... stage`.
# Refuses to run if the shell is already installed (it is a one-time operation).
#
# Order (each step fails closed, nothing after a failed step runs):
#   1 prerequisites  2 confirmation  3 verified DB+storage backup  4 re-validate the staged release
#   5 migration review (pretend; destructive SQL aborts)  6 forward-only migrate
#   7 point `current` at the release  8 docroot.sh apply (moves, never deletes; health-checked)
# On failure after step 7: `current` is removed again and docroot.sh has already reverted the
# docroot, so the legacy site keeps serving. Migrations (step 6) are NOT undone: they are
# forward-only, so the backup from step 3 is the recovery path (docs/DEPLOYMENT.md).
set -euo pipefail
. "$(dirname "$0")/lib.sh"
HERE="$(dirname "$0")"
CMD="${1:?usage: first-cutover.sh check|run <release-id>}"; RID="${2:?release id}"
[[ "$RID" =~ ^[0-9]{8}-[0-9]{6}-[0-9a-f]{7,12}$ ]] || die "bad release id"
REL="$BASE/releases/$RID"
bad=0; ok(){ echo "  ok    $*"; }; no(){ echo "  FAIL  $*"; bad=1; }

prereqs() {
  echo "Prerequisites"
  [ -x "$PHP" ] && "$PHP" -r 'exit(PHP_VERSION_ID>=80300?0:1);' && ok "PHP >= 8.3" || no "PHP 8.3 not available"
  [ -d "$REL" ] && ok "release $RID is staged" || no "release $RID is not staged"
  [ -f "$REL/RELEASE_SHA" ] && ok "RELEASE_SHA present" || no "RELEASE_SHA missing"
  [ -L "$REL/.env" ] && [ "$(readlink "$REL/.env")" = "$BASE/shared/.env" ] && ok ".env -> shared/.env" || no "release .env is not linked to shared/.env"
  [ -L "$REL/storage/app" ] && ok "storage linked to shared" || no "storage not linked to shared"
  [ -s "$BASE/shared/.env" ] && ok "shared/.env non-empty" || no "shared/.env missing or empty"
  for k in APP_KEY DB_DATABASE DB_USERNAME; do [ -n "$(env_get $k)" ] && ok "env $k set" || no "env $k empty"; done
  [ "$(env_get APP_DEBUG)" = false ] && ok "APP_DEBUG=false" || no "APP_DEBUG must be false"
  [ -d "$DOCROOT_LINK" ] && [ ! -L "$DOCROOT_LINK" ] && ok "public_html is a real directory" || no "public_html must be a real directory"
  [ -d "$DOCROOT_LINK/assets/uploads" ] && ok "assets/uploads present" || no "assets/uploads missing"
  grep -q PIIE-RELEASE-SHELL "$DOCROOT_LINK/index.php" 2>/dev/null && no "shell already installed: first cutover is one-time; use deploy-release.sh" || ok "legacy index.php still in place"
  [ ! -e "$BASE/current" ] && ok "no current release yet" || no "current already exists"
  [ -f "$HERE/../docroot/index.php" ] || [ -f "$BASE/bin/docroot-index.php" ] && ok "shell template available" || no "shell template missing"
  for t in mysqldump flock gzip tar curl sha256sum; do command -v $t >/dev/null && ok "$t" || no "$t missing"; done
  FREE=$(df -Pk "$BASE" | awk 'NR==2{print int($4/1024)}'); [ "${FREE:-0}" -ge 2048 ] && ok "free space ${FREE}MB" || no "less than 2GB free"
  if [ -f "$REL/artisan" ]; then ( cd "$REL" && "$PHP" artisan --version >/dev/null 2>&1 ) && ok "release boots" || no "release does not boot"; fi
}

if [ "$CMD" = check ]; then prereqs; [ $bad = 0 ] && echo "READY (nothing was changed)" || echo "NOT READY"; exit $bad; fi
[ "$CMD" = run ] || die "usage: first-cutover.sh check|run <release-id>"

exec 9>"$BASE/.deploy.lock"; flock -n 9 || die "another deployment is running"
prereqs; [ $bad = 0 ] || die "prerequisites failed; nothing was changed"

WANT="CUTOVER $RID $(env_get DB_DATABASE)"
[ "${PIIE_CONFIRM_FIRST_CUTOVER:-}" = "$WANT" ] || die "operator confirmation missing. Set PIIE_CONFIRM_FIRST_CUTOVER=\"$WANT\""

log "Step 3: verified backups"
BK="$(bash "$HERE/backup.sh" "cutover-$RID")"; echo "$BK" | tail -3
DBF="$BASE/backups/db-cutover-$RID.sql.gz"
[ -s "$DBF" ] && gzip -t "$DBF" && [ -s "$BASE/backups/storage-cutover-$RID.tar.gz" ] || die "backup verification failed"

log "Step 4/5: migration review (pretend)"
cd "$REL"
PRETEND="$("$PHP" artisan migrate --pretend --force 2>&1)" || die "migrate --pretend failed"
echo "$PRETEND" | head -40
if echo "$PRETEND" | grep -qiE 'drop +(table|column|database)|truncate|delete +from'; then
  [ "${ALLOW_DESTRUCTIVE_MIGRATIONS:-}" = 1 ] || die "destructive SQL in pending migrations; nothing changed"
fi

log "Step 6: migrate (forward-only; legacy site keeps serving)"
"$PHP" artisan migrate --force || die "migration failed; current NOT set, docroot untouched. Restore from $DBF only if the schema is inconsistent."
"$PHP" artisan config:cache; "$PHP" artisan view:clear

log "Step 7: set current -> $RID"
ln -sfn "$REL" "$BASE/current.new"; mv -Tf "$BASE/current.new" "$BASE/current"

log "Step 8: convert document root (auto-reverts on failed health check)"
[ -f "$BASE/bin/docroot-index.php" ] || cp "$HERE/../docroot/index.php" "$BASE/bin/docroot-index.php"
if ! PIIE_CONFIRM_DOCROOT_CUTOVER=yes bash "$HERE/docroot.sh" apply; then
  rm -f "$BASE/current"
  log "CUTOVER FAILED. docroot reverted, current removed: the legacy site is serving."
  log "Migrations were applied (forward-only). Backup for recovery: $DBF"
  exit 1
fi
log "FIRST CUTOVER COMPLETE. Release $RID is live. Docroot backup: $(ls -dt "$BASE"/backups/docroot-* | head -1)"
log "Rollback of the docroot: docroot.sh revert <ts>. Later releases: deploy-release.sh / the workflow."
