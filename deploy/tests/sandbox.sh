#!/usr/bin/env bash
# Exercises deploy/remote/*.sh against a throw-away directory tree with stubbed
# php / curl / flock. Touches no real host, database or network.
# Usage: bash deploy/tests/sandbox.sh   (on Windows Git Bash: export MSYS=winsymlinks:nativestrict)
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
S="$(mktemp -d)"; trap 'rm -rf "$S"' EXIT
B="$S/dep"; DOC="$S/public_html"; fails=0
t() { if eval "$2"; then echo "  PASS  $1"; else echo "  FAIL  $1"; fails=$((fails+1)); fi; }

mkdir -p "$B"/{bin,backups,releases,shared/storage/app} "$S/art/public/assets/"{css,vendors,uploads} "$S/art/public/"{css,js} "$S/bin" \
         "$DOC/assets/"{uploads,css,vendors} "$DOC/css"
echo APP_KEY=x > "$B/shared/.env"
touch "$S/art/artisan" "$S/art/RELEASE_SHA" "$S/art/public/favicon.ico" "$S/art/public/assets/install.sql" \
      "$DOC/assets/uploads/student1.pdf" "$DOC/assets/uploads/student2.pdf" "$DOC/css/old.css" "$DOC/.env" "$DOC/.htaccess"
echo '<?php // legacy' > "$DOC/index.php"
cp "$ROOT/deploy/docroot/index.php" "$B/bin/docroot-index.php"
tar -czf "$S/a.tar.gz" -C "$S/art" .; SUM="$(sha256sum "$S/a.tar.gz" | cut -d' ' -f1)"

printf '#!/bin/sh\necho "stub php $*"\n'       > "$S/bin/php";   printf '#!/bin/sh\necho ${CURL_CODE:-200}\n' > "$S/bin/curl"
printf '#!/bin/sh\nexit 0\n'                    > "$S/bin/flock"; chmod +x "$S/bin/"*
export PATH="$S/bin:$PATH" PIIE_BASE="$B" PIIE_PHP="$S/bin/php" PIIE_DOCROOT="$DOC" PIIE_HEALTH_URL="http://sandbox/"
D="$ROOT/deploy/remote"

echo "deploy-release.sh"
RID=20261004-120000-abcdef1
bash "$D/deploy-release.sh" $RID "$S/a.tar.gz" "$SUM" stage >/dev/null 2>&1
t "stage unpacks the release"                     "[ -d $B/releases/$RID ]"
t "stage links shared .env"                       "[ -L $B/releases/$RID/.env ]"
t "stage links shared storage"                    "[ -L $B/releases/$RID/storage/app ]"
t "stage does NOT create current"                 "[ ! -e $B/current ]"
t "stage takes no backup"                         "[ -z \"\$(ls $B/backups)\" ]"
t "activate refused before docroot conversion"    "! bash $D/deploy-release.sh 20261004-120100-abcdef2 $S/a.tar.gz $SUM activate >/dev/null 2>&1"
t "bad checksum refused"                          "! bash $D/deploy-release.sh 20261004-120200-abcdef3 $S/a.tar.gz 00 stage >/dev/null 2>&1"
mv "$B/shared/.env" "$B/shared/e"
t "missing shared/.env refused (never generated)" "! bash $D/deploy-release.sh 20261004-120300-abcdef4 $S/a.tar.gz $SUM stage >/dev/null 2>&1 && [ ! -e $B/shared/.env ]"
mv "$B/shared/e" "$B/shared/.env"
t "existing release id refused (append-only)"     "! bash $D/deploy-release.sh $RID $S/a.tar.gz $SUM stage >/dev/null 2>&1"
t "rollback refused with no current release"      "! bash $D/rollback.sh >/dev/null 2>&1"

echo "docroot.sh"
ln -s "$B/releases/$RID" "$B/current"
# Regression guard for the first-cutover failure:
#   /dev/fd/62: No such file or directory
# Process substitution needs /dev/fd, which does not resolve on every host. This
# sandbox runs where /dev/fd DOES work, so the failure cannot be reproduced here —
# which is exactly why it needs a static assertion rather than a behavioural test.
# Strip comments first so the explanatory prose about the removed pattern does not
# trip the check.
t "docroot.sh contains NO process substitution" \
  "! grep -v '^[[:space:]]*#' \"$D/docroot.sh\" | grep -qE '[<>]\('"
t "docroot.sh requires mktemp, and first-cutover checks for it" \
  "grep -q 'mktemp' $D/docroot.sh && grep -q 'mktemp' $D/first-cutover.sh"
bash "$D/docroot.sh" plan >/dev/null 2>&1;                          t "plan changes nothing"                "head -c 5 $DOC/index.php | grep -q '<?php' && [ ! -L $DOC/css ]"
t "plan leaves no temp file behind"        "! ls /tmp/piie-docroot-entries.* >/dev/null 2>&1"
t "apply refused without confirmation"            "! bash $D/docroot.sh apply >/dev/null 2>&1"
CURL_CODE=404 PIIE_CONFIRM_DOCROOT_CUTOVER=yes bash "$D/docroot.sh" apply >/dev/null 2>&1; rc=$?
t "failed health check -> apply exits non-zero"   "[ $rc -ne 0 ]"
t "failed health check -> auto-reverted"          "grep -q legacy $DOC/index.php && [ ! -L $DOC/css ] && [ -f $DOC/css/old.css ]"
PIIE_CONFIRM_DOCROOT_CUTOVER=yes bash "$D/docroot.sh" apply >/dev/null 2>&1
t "apply installs the shell front controller"     "grep -q PIIE-RELEASE-SHELL $DOC/index.php"
t "apply links static dirs into current"          "[ -L $DOC/css ] && [ -L $DOC/assets/vendors ]"
t "uploads stay a real directory with all files"  "[ ! -L $DOC/assets/uploads ] && [ -f $DOC/assets/uploads/student1.pdf ] && [ -f $DOC/assets/uploads/student2.pdf ]"
t ".env and .htaccess untouched"                  "[ -f $DOC/.env ] && [ -f $DOC/.htaccess ]"
t "old files kept in backup, not deleted"         "ls $B/backups/docroot-*/css/old.css >/dev/null 2>&1 && ls $B/backups/docroot-*/index.php >/dev/null 2>&1"
bash "$D/deploy-release.sh" 20261004-120400-abcdef5 "$S/a.tar.gz" "$SUM" activate > "$S/act.log" 2>&1
t "activate passes guard, then FAILS CLOSED at backup (no DB creds) without unpacking" "grep -q \"DB_DATABASE empty\" $S/act.log && [ ! -e $B/releases/20261004-120400-abcdef5 ]"
TS="$(ls "$B/backups" | grep '^docroot-' | tail -1)"; TS="${TS#docroot-}"
bash "$D/docroot.sh" revert "$TS" >/dev/null 2>&1
t "revert restores index.php and real dirs"       "grep -q legacy $DOC/index.php && [ ! -L $DOC/css ] && [ -f $DOC/css/old.css ] && [ ! -L $DOC/assets/vendors ]"
t "revert keeps uploads"                          "[ -f $DOC/assets/uploads/student2.pdf ]"

echo "rollback.sh"
ln -s "$B/releases/$RID" "$B/c2" 2>/dev/null; mkdir -p "$B/releases/20261003-100000-0000000"
t "rollback moves current to previous release"    "bash $D/rollback.sh 20261003-100000-0000000 >/dev/null 2>&1 && [ \"\$(basename \$(readlink $B/current))\" = 20261003-100000-0000000 ]"

echo; [ $fails -eq 0 ] && echo "ALL SANDBOX CHECKS PASSED" || echo "$fails SANDBOX CHECK(S) FAILED"
exit $((fails>0))
