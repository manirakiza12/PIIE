#!/usr/bin/env bash
# Behavioural tests for deploy/remote/first-cutover.sh in throw-away trees with
# stubbed php / curl / flock / mysqldump. No real host, database or network.
# Windows Git Bash: export MSYS=winsymlinks:nativestrict
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
D="$ROOT/deploy/remote"
fails=0
t() { if eval "$2"; then echo "  PASS  $1"; else echo "  FAIL  $1"; fails=$((fails+1)); fi; }

RID=20261004-120000-abcdef1
S=""; B=""; DOC=""; CONF=""

setup() {   # fresh sandbox with a legacy docroot and a STAGED release
  [ -n "$S" ] && rm -rf "$S"
  S="$(mktemp -d)"; B="$S/dep"; DOC="$S/public_html"
  mkdir -p "$B"/{bin,backups,releases,shared/storage/app} "$S/art/public/assets/"{css,vendors,uploads} "$S/art/public/"{css,js} "$S/bin" \
           "$DOC/assets/"{uploads,css,vendors} "$DOC/css" "$DOC/app"
  printf 'APP_KEY=base64:x\nAPP_DEBUG=false\nDB_HOST=localhost\nDB_PORT=3306\nDB_DATABASE=piie_db\nDB_USERNAME=u\nDB_PASSWORD=p\n' > "$B/shared/.env"
  touch "$S/art/artisan" "$S/art/RELEASE_SHA" "$S/art/public/favicon.ico" "$DOC/assets/uploads/student1.pdf" "$DOC/assets/uploads/student2.pdf" "$DOC/css/old.css" "$DOC/.env" "$DOC/.htaccess"
  echo '<?php // legacy' > "$DOC/index.php"
  cp "$ROOT/deploy/docroot/index.php" "$B/bin/docroot-index.php"
  tar -czf "$S/a.tar.gz" -C "$S/art" .; SUM="$(sha256sum "$S/a.tar.gz" | cut -d' ' -f1)"
  cat > "$S/bin/php" <<'STUB'
#!/bin/sh
case "$*" in
  *"migrate --pretend"*) echo "${PRETEND_OUT:-create table x}";;
  *"migrate --force"*) [ -n "${FAIL_MIGRATE:-}" ] && { echo migrate-failed; exit 1; }; echo migrated; touch "${MIGRATED_FLAG:-/dev/null}";;
  *) echo "stub php $*";;
esac
exit 0
STUB
  printf '#!/bin/sh\necho ${CURL_CODE:-200}\n' > "$S/bin/curl"
  printf '#!/bin/sh\nexit 0\n' > "$S/bin/flock"
  cat > "$S/bin/mysqldump" <<'STUB'
#!/bin/sh
[ -n "$MYSQLDUMP_FAIL" ] && exit 2
i=0; while [ $i -lt 600 ]; do echo "INSERT INTO t VALUES ($i,$((i*7919%10007)),$((i*104729%99991)));"; i=$((i+1)); done
echo "-- Dump completed on 2026"
STUB
  chmod +x "$S/bin/"*
  export PATH="$S/bin:$PATH" PIIE_BASE="$B" PIIE_PHP="$S/bin/php" PIIE_DOCROOT="$DOC" PIIE_HEALTH_URL="http://sandbox/"
  unset CURL_CODE FAIL_MIGRATE PRETEND_OUT MYSQLDUMP_FAIL PIIE_CONFIRM_FIRST_CUTOVER ALLOW_DESTRUCTIVE_MIGRATIONS
  bash "$D/deploy-release.sh" $RID "$S/a.tar.gz" "$SUM" stage >/dev/null 2>&1
  CONF="CUTOVER $RID piie_db"
}
legacy_intact() { grep -q legacy "$DOC/index.php" && [ ! -L "$DOC/css" ] && [ -f "$DOC/css/old.css" ] && [ -f "$DOC/assets/uploads/student2.pdf" ] && [ ! -e "$B/current" ]; }
run() { PIIE_CONFIRM_FIRST_CUTOVER="${1-$CONF}" bash "$D/first-cutover.sh" run $RID >"$S/out.log" 2>&1; }

echo "A. preconditions"
setup
t "normal activate still refused on a legacy docroot (guard unchanged)" "! bash $D/deploy-release.sh 20261004-130000-abcdef2 $S/a.tar.gz $SUM activate >/dev/null 2>&1"
t "check reports READY on a staged release"        "bash $D/first-cutover.sh check $RID 2>&1 | grep -q '^READY'"
t "check changes nothing"                           "legacy_intact && [ -z \"\$(ls $B/backups)\" ]"
t "run refused without confirmation"                "! run '' && legacy_intact && [ -z \"\$(ls $B/backups)\" ]"
t "run refused with the wrong confirmation"         "! run 'CUTOVER $RID other_db' && legacy_intact && [ -z \"\$(ls $B/backups)\" ]"
t "check fails for an unstaged release"             "! bash $D/first-cutover.sh check 20261004-999999-abcdef9 >/dev/null 2>&1"

echo "B. refusals"
setup; ln -s "$B/releases/$RID" "$B/current"
t "refused when current already exists"             "! run && [ -z \"\$(ls $B/backups)\" ]"
setup; cp "$ROOT/deploy/docroot/index.php" "$DOC/index.php"
t "refused when the shell is already installed (one-time)" "! run && [ -z \"\$(ls $B/backups)\" ]"
setup; mv "$B/shared/.env" "$B/shared/e"
t "refused when shared/.env is missing"             "! run && legacy_intact"
setup; rm -rf "$DOC/assets/uploads"
t "refused when assets/uploads is missing"          "! run && [ -z \"\$(ls $B/backups)\" ]"

echo "C. failures after the point of no return are contained"
setup; MYSQLDUMP_FAIL=1 run; rc=$?
t "backup failure aborts before any change"         "[ $rc -ne 0 ] && legacy_intact && ! ls $B/backups/db-cutover-*.sql.gz >/dev/null 2>&1"
setup; PRETEND_OUT='drop table students' run; rc=$?
t "destructive migration aborts, docroot and current untouched" "[ $rc -ne 0 ] && legacy_intact && grep -q destructive $S/out.log"
setup; FAIL_MIGRATE=1 run; rc=$?
t "migration failure leaves legacy site serving"    "[ $rc -ne 0 ] && legacy_intact"
setup; CURL_CODE=404 run; rc=$?
t "failed health check -> non-zero exit"            "[ $rc -ne 0 ]"
t "failed health check -> docroot reverted, current removed" "legacy_intact"
t "failed health check -> backups retained"         "[ -s $B/backups/db-cutover-$RID.sql.gz ] && [ -s $B/backups/storage-cutover-$RID.tar.gz ]"
t "failed health check -> nothing deleted"          "[ -f $DOC/css/old.css ] && [ -f $DOC/assets/uploads/student1.pdf ] && [ -f $DOC/.env ]"

echo "D. successful cutover"
setup; MIGRATED_FLAG="$S/migrated" run; rc=$?; [ -n "${KEEP:-}" ] && cp "$S/out.log" /tmp/lastout.log
t "exit 0"                                          "[ $rc -eq 0 ]"
t "verified DB backup exists and is valid gzip"     "gzip -t $B/backups/db-cutover-$RID.sql.gz && [ -s $B/backups/db-cutover-$RID.sql.gz.sha256 ]"
t "storage backup exists"                           "tar -tzf $B/backups/storage-cutover-$RID.tar.gz >/dev/null"
t "migrations ran after the backup"                 "[ -f $S/migrated ]"
t "current points at the staged release"            "[ \"\$(readlink $B/current)\" = $B/releases/$RID ]"
t "shell installed"                                 "grep -q PIIE-RELEASE-SHELL $DOC/index.php"
t "static dirs linked into current"                 "[ -L $DOC/css ] && [ -L $DOC/assets/vendors ]"
t "uploads untouched"                               "[ ! -L $DOC/assets/uploads ] && [ -f $DOC/assets/uploads/student1.pdf ] && [ -f $DOC/assets/uploads/student2.pdf ]"
t "legacy app dir, .env and .htaccess untouched"    "[ -d $DOC/app ] && [ -f $DOC/.env ] && [ -f $DOC/.htaccess ]"
t "docroot backup holds the old index.php and css"  "grep -q legacy $B/backups/docroot-*/index.php && [ -f $B/backups/docroot-*/css/old.css ]"
t "shared/.env was not modified"                    "grep -q 'APP_KEY=base64:x' $B/shared/.env"
t "second run refused (one-time)"                   "! run"
TS="$(ls "$B/backups" | grep '^docroot-' | tail -1)"; bash "$D/docroot.sh" revert "${TS#docroot-}" >/dev/null 2>&1
t "docroot.sh revert restores the legacy docroot"   "grep -q legacy $DOC/index.php && [ ! -L $DOC/css ] && [ -f $DOC/css/old.css ] && [ -f $DOC/assets/uploads/student1.pdf ]"

[ -n "${KEEP:-}" ] && cp "$S/out.log" /tmp/lastout.log; rm -rf "$S"
echo; [ $fails -eq 0 ] && echo "ALL FIRST-CUTOVER CHECKS PASSED" || echo "$fails FIRST-CUTOVER CHECK(S) FAILED"
exit $((fails>0))
