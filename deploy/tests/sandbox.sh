#!/usr/bin/env bash
# Exercises deploy/remote/*.sh against a throw-away directory tree with stubbed
# php / curl / flock. Touches no real host, database or network.
# Usage: bash deploy/tests/sandbox.sh   (on Windows Git Bash: export MSYS=winsymlinks:nativestrict)
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
S="$(mktemp -d)"; trap 'rm -rf "$S"' EXIT
B="$S/dep"; DOC="$S/public_html"; fails=0
t() { if eval "$2"; then echo "  PASS  $1"; else echo "  FAIL  $1"; fails=$((fails+1)); fi; }

# Does an archive contain a member matching PATTERN?
#
# Deliberately NOT `tar -tzf "$1" | grep -q "$2"`. `grep -q` exits on the first
# match and closes the pipe, tar dies of SIGPIPE, and under `set -o pipefail` the
# pipeline returns 141 — a failure even when the match succeeded. Measured on a
# 404-entry archive: 0/20 successes piped, 20/20 via a listing file. These
# assertions were silently flaky because of it.
archive_has() {
  local archive="$1" pattern="$2" listing
  [ -f "$archive" ] || return 1
  listing="$(mktemp)"
  tar -tzf "$archive" > "$listing" 2>/dev/null || { rm -f "$listing"; return 1; }
  grep -qE "$pattern" "$listing"
  local rc=$?
  rm -f "$listing"
  return $rc
}

mkdir -p "$B"/{bin,backups,releases,shared/storage/app} "$S/art/public/assets/"{css,vendors,uploads} "$S/art/public/"{css,js} "$S/bin" \
         "$DOC/assets/"{uploads,css,vendors} "$DOC/css"
echo APP_KEY=x > "$B/shared/.env"
touch "$S/art/artisan" "$S/art/RELEASE_SHA" "$S/art/public/favicon.ico" "$S/art/public/assets/install.sql" \
      "$DOC/assets/uploads/student1.pdf" "$DOC/assets/uploads/student2.pdf" "$DOC/css/old.css" "$DOC/.env" "$DOC/.htaccess"
echo '<?php // legacy' > "$DOC/index.php"

# ── Baseline upload artefacts that ship INSIDE the release artefact ──────────
# `deploy/build-release.sh` uses `git archive`, so the artefact carries the
# committed upload assets (logos, brochure, syllabus assets). deploy-release.sh
# must merge these into shared/public-uploads without overwriting anything.
mkdir -p "$S/art/public/assets/uploads/logo" \
         "$S/art/public/assets/uploads/documents/nested/deep" \
         "$S/art/public/assets/uploads/syllabus"
printf 'BASELINE-LOGO\n'    > "$S/art/public/assets/uploads/logo/baseline-logo.png"
printf 'BASELINE-BROCHURE\n' > "$S/art/public/assets/uploads/documents/brochure copy.pdf"
printf 'BASELINE-NESTED\n'   > "$S/art/public/assets/uploads/documents/nested/deep/baseline.pdf"
printf 'BASELINE-SYLLABUS\n' > "$S/art/public/assets/uploads/syllabus/syllabus-logo.png"
# A baseline file at a path a persistent upload already occupies. The persistent
# copy MUST win; this is the "never overwrite" rule under test.
printf 'BASELINE-SHARED-NAME\n' > "$S/art/public/assets/uploads/logo/shared-name.png"

# A pre-existing RUNTIME upload, written before any release exists, at the same
# path as a baseline artefact. Contents differ so the assertion is meaningful.
mkdir -p "$B/shared/public-uploads/logo"
printf 'PERSISTENT-RUNTIME-COPY\n' > "$B/shared/public-uploads/logo/shared-name.png"
# A pre-existing persistent upload in a NESTED runtime directory, which must
# survive untouched and must not be flattened or relocated.
mkdir -p "$B/shared/public-uploads/admissions/2026"
printf 'PERSISTENT-NESTED\n' > "$B/shared/public-uploads/admissions/2026/id-scan.pdf"

cp "$ROOT/deploy/docroot/index.php" "$B/bin/docroot-index.php"
tar -czf "$S/a.tar.gz" -C "$S/art" .; SUM="$(sha256sum "$S/a.tar.gz" | cut -d' ' -f1)"

printf '#!/bin/sh\necho "stub php $*"\n'       > "$S/bin/php"
cat > "$S/bin/curl" <<'STUB'
#!/bin/sh

# Legacy docroot health checks use -o /dev/null and expect only HTTP status.
case " $* " in
  *" -o /dev/null "*)
    printf '%s\n' "${CURL_CODE:-200}"
    ;;
  *)
    # Contabo application health expects response body followed by HTTP status.
    printf '%s\n%s\n' "${CURL_BODY:-PIIE-APP-OK}" "${CURL_CODE:-200}"
    ;;
esac
STUB
printf '#!/bin/sh\nexit 0\n'                    > "$S/bin/flock"; chmod +x "$S/bin/"*

cat > "$S/bin/mysqldump" <<'STUB'
#!/bin/sh
for a in "$@"; do
  case "$a" in *password*|*--password=*) echo "LEAK" > /tmp/piie-cred-leak ;; esac
done
echo "-- MySQL dump"
echo "CREATE TABLE t (id int);"
# backup.sh refuses a dump under 2048 bytes (a pre-existing sanity check), so this
# stub emits realistically sized, poorly-compressible output. Repeated filler lines
# would gzip far below the threshold and the check would fire for the wrong reason.
head -c 20000 /dev/urandom | base64
echo "-- Dump completed on 2026-10-06  0:00:00"
STUB
chmod +x "$S/bin/mysqldump"
export PATH="$S/bin:$PATH" PIIE_BASE="$B" PIIE_PHP="$S/bin/php" PIIE_DOCROOT="$DOC" PIIE_HEALTH_URL="http://sandbox/"
D="$ROOT/deploy/remote"

echo "application health"
. "$D/lib.sh"
t "application health accepts expected body + HTTP 200" \
  "CURL_BODY=PIIE-APP-OK CURL_CODE=200 app_health_check"
t "application health rejects wrong body even with HTTP 200" \
  "! CURL_BODY=WRONG-APPLICATION CURL_CODE=200 app_health_check"
t "application health rejects non-200 even with expected body" \
  "! CURL_BODY=PIIE-APP-OK CURL_CODE=500 app_health_check"

echo "failed activation recovery"

RECOVERY_OLD="$B/releases/20261004-115900-aaaaaaa"
RECOVERY_BAD="$B/releases/20261004-115901-bbbbbbb"
mkdir -p "$RECOVERY_OLD" "$RECOVERY_BAD"

ln -sfn "$RECOVERY_BAD" "$B/current"
restore_after_failed_activation "$RECOVERY_OLD"
t "failed activation restores previous release" \
  "[ \"\$(readlink -f $B/current)\" = \"\$(readlink -f $RECOVERY_OLD)\" ]"

ln -sfn "$RECOVERY_BAD" "$B/current"
restore_after_failed_activation ""
t "failed first activation removes current symlink" \
  "[ ! -e $B/current ] && [ ! -L $B/current ]"

echo "deploy-release.sh"
RID=20261004-120000-abcdef1
bash "$D/deploy-release.sh" $RID "$S/a.tar.gz" "$SUM" stage >/dev/null 2>&1
t "stage unpacks the release"                     "[ -d $B/releases/$RID ]"
t "stage links shared .env"                       "[ -L $B/releases/$RID/.env ]"
t "stage links shared storage"                    "[ -L $B/releases/$RID/storage/app ]"
t "stage does NOT create current"                 "[ ! -e $B/current ]"
t "stage takes no backup"                         "[ -z \"\$(ls $B/backups)\" ]"

# ── Contabo persistent public uploads ────────────────────────────────────────
# Regression coverage for: release switches must not lose runtime uploads.
echo "persistent public uploads (Contabo)"
PU="$B/shared/public-uploads"
RU="$B/releases/$RID/public/assets/uploads"

t "stage prepares shared/public-uploads"          "[ -d $PU ] && [ ! -L $PU ]"
t "release public/assets/uploads IS a symlink"     "[ -L $RU ]"
t "release uploads symlink -> shared/public-uploads" \
  "[ \"\$(readlink -f $RU)\" = \"\$(readlink -f $PU)\" ]"
t "release uploads resolves to a usable directory" "[ -d $RU ]"

# Baseline artefacts from the release must be preserved, nested structure intact.
t "baseline artefact file preserved"              "[ -f $PU/logo/baseline-logo.png ] && grep -q BASELINE-LOGO $PU/logo/baseline-logo.png"
t "baseline artefact with a SPACE in its name preserved" \
  "[ -f '$PU/documents/brochure copy.pdf' ] && grep -q BASELINE-BROCHURE '$PU/documents/brochure copy.pdf'"
t "nested baseline artefact preserved at depth"   "[ -f $PU/documents/nested/deep/baseline.pdf ] && grep -q BASELINE-NESTED $PU/documents/nested/deep/baseline.pdf"
t "sibling baseline directory preserved"          "[ -f $PU/syllabus/syllabus-logo.png ]"

# THE critical rule: a persistent runtime file must never be overwritten by a
# baseline artefact that happens to share its path.
t "persistent file NOT overwritten by same-path baseline" \
  "grep -q PERSISTENT-RUNTIME-COPY $PU/logo/shared-name.png && ! grep -q BASELINE-SHARED-NAME $PU/logo/shared-name.png"
t "pre-existing persistent nested upload survives untouched" \
  "[ -f $PU/admissions/2026/id-scan.pdf ] && grep -q PERSISTENT-NESTED $PU/admissions/2026/id-scan.pdf"

# The release holds no independent uploads copy: writing THROUGH the release path
# must land in the shared directory. That is the whole persistence guarantee — a
# runtime upload made after staging is still there when the next release is staged.
t "write through the release path lands in shared" \
  "printf 'RUNTIME-AFTER-STAGE\n' > $RU/admissions/runtime-after-stage.pdf && [ -f $PU/admissions/runtime-after-stage.pdf ]"
t "no leftover merge temp files"                   "! ls /tmp/piie-public-uploads.* >/dev/null 2>&1"

# ── Fail-closed shapes, in an isolated sandbox so the main one is untouched ───
echo "persistent public uploads — fail-closed shapes"
EV="$S/evil"; EB="$EV/dep"; EPU="$EB/shared/public-uploads"
mkdir -p "$EB"/{bin,backups,releases,shared/storage/app} "$EV/art/public/assets/uploads" "$EV/bin"
echo APP_KEY=x > "$EB/shared/.env"
touch "$EV/art/artisan" "$EV/art/RELEASE_SHA"
printf 'CONTENT\n' > "$EV/art/public/assets/uploads/keep.txt"

# 1. A symlink where the release's uploads directory should be.
rm -rf "$EV/art/public/assets/uploads"; ln -sfn /etc "$EV/art/public/assets/uploads"
tar -czf "$EV/evil.tar.gz" -C "$EV/art" .
EVSUM="$(sha256sum "$EV/evil.tar.gz" | cut -d' ' -f1)"
t "release uploads that is a SYMLINK is refused" \
  "! PIIE_BASE=$EB bash $D/deploy-release.sh 20261006-121000-abcdef9 $EV/evil.tar.gz $EVSUM stage >/dev/null 2>&1"
t "symlinked uploads did NOT leak /etc into shared" \
  "! [ -e $EPU/passwd ] && ! [ -e $EPU/hostname ] && ! [ -e $EPU/hosts ]"

# 2. A symlink where shared/public-uploads should be.
rm -f "$EV/art/public/assets/uploads"; mkdir -p "$EV/art/public/assets/uploads"
printf 'CONTENT\n' > "$EV/art/public/assets/uploads/keep.txt"
# -rf, not -f and not -sfn: the previous case leaves a real directory here. `rm -f` on a
# directory fails, so the symlink would never be created and this case would pass for
# the wrong reason.
rm -rf "$EPU"; ln -s "$EV/elsewhere" "$EPU"
tar -czf "$EV/evil2.tar.gz" -C "$EV/art" .
EV2SUM="$(sha256sum "$EV/evil2.tar.gz" | cut -d' ' -f1)"
t "shared/public-uploads as a SYMLINK is refused" \
  "! PIIE_BASE=$EB bash $D/deploy-release.sh 20261006-121100-abcdeff $EV/evil2.tar.gz $EV2SUM stage >/dev/null 2>&1"
t "symlinked shared dir was not written through"  "[ ! -e $EV/elsewhere/keep.txt ]"
rm -f "$EPU"

# 3. A symlink INSIDE the release uploads tree.
printf 'CONTENT\n' > "$EV/art/public/assets/uploads/real.txt"
ln -sfn /etc/passwd "$EV/art/public/assets/uploads/sneaky.txt"
tar -czf "$EV/evil3.tar.gz" -C "$EV/art" .
EV3SUM="$(sha256sum "$EV/evil3.tar.gz" | cut -d' ' -f1)"
t "a symlink INSIDE release uploads is refused" \
  "! PIIE_BASE=$EB bash $D/deploy-release.sh 20261006-121200-abcdefg $EV/evil3.tar.gz $EV3SUM stage >/dev/null 2>&1"
t "symlink inside uploads was not copied into shared" "! [ -e $EPU/sneaky.txt ]"
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
PIIE_DOCROOT="$B/current/public" bash "$D/deploy-release.sh" 20261004-120400-abcdef5 "$S/a.tar.gz" "$SUM" activate > "$S/act.log" 2>&1
t "Contabo activate passes current/public guard, then FAILS CLOSED at backup (no DB creds) without unpacking" "grep -q \"DB_DATABASE empty\" $S/act.log && [ ! -e $B/releases/20261004-120400-abcdef5 ]"
TS="$(ls "$B/backups" | grep '^docroot-' | tail -1)"; TS="${TS#docroot-}"
bash "$D/docroot.sh" revert "$TS" >/dev/null 2>&1
t "revert restores index.php and real dirs"       "grep -q legacy $DOC/index.php && [ ! -L $DOC/css ] && [ -f $DOC/css/old.css ] && [ ! -L $DOC/assets/vendors ]"
t "revert keeps uploads"                          "[ -f $DOC/assets/uploads/student2.pdf ]"

echo "rollback.sh"
ln -s "$B/releases/$RID" "$B/c2" 2>/dev/null; mkdir -p "$B/releases/20261003-100000-0000000"
t "rollback moves current to previous release"    "bash $D/rollback.sh 20261003-100000-0000000 >/dev/null 2>&1 && [ \"\$(basename \$(readlink $B/current))\" = 20261003-100000-0000000 ]"

# A rollback target that fails application health must not remain active.
# Start from the older release, attempt to switch back to RID with a wrong
# application response, and verify rollback.sh restores the original current.
CURL_BODY=WRONG-APPLICATION bash "$D/rollback.sh" "$RID" > "$S/rollback-health.log" 2>&1
rc=$?
t "unhealthy rollback target exits non-zero" \
  "[ $rc -ne 0 ]"
t "unhealthy rollback target restores original current" \
  "[ \"\$(basename \$(readlink $B/current))\" = 20261003-100000-0000000 ]"
t "unhealthy rollback logs transactional restoration" \
  "grep -q 'Rollback attempt reverted' $S/rollback-health.log"

# ── backup.sh protects BOTH persistent trees in one verified archive ─────────
echo "backup.sh covers both persistent trees"
# Realistic .env so backup.sh gets past its DB_DATABASE guard. The password value
# is a fixture, not a credential, and one assertion below proves it never reaches
# the log.
printf 'APP_ENV=production\nDB_HOST=db.internal\nDB_PORT=3306\nDB_USERNAME=piie\nDB_PASSWORD=fixture-not-a-secret\nDB_DATABASE=piie_prod\n' > "$B/shared/.env"
printf 'storage payload\n' > "$B/shared/storage/app/public/sample.txt"
printf 'persistent upload payload\n' > "$PU/admissions/backup-probe.pdf"

bash "$D/backup.sh" bk-1 > "$S/backup.log" 2>&1
t "backup.sh succeeds"                            "[ -f $B/backups/db-bk-1.sql.gz ] && [ -f $B/backups/storage-bk-1.tar.gz ]"
t "DB dump still gzip-verified"                   "gzip -t $B/backups/db-bk-1.sql.gz"
t "DB dump keeps its completion marker"           "zcat $B/backups/db-bk-1.sql.gz | grep -q 'Dump completed'"
t "DB dump checksum sidecar written"              "[ -f $B/backups/db-bk-1.sql.gz.sha256 ]"

SA="$B/backups/storage-bk-1.tar.gz"
t "storage archive is valid gzip"                 "gzip -t $SA"
t "storage archive contains storage/app"          "archive_has $SA '^storage/app/'"
t "storage archive contains public-uploads"       "archive_has $SA '^public-uploads/'"
t "archive holds the Laravel storage payload"     "archive_has $SA '^storage/app/public/sample.txt'"
t "archive holds the persistent upload payload"   "archive_has $SA '^public-uploads/admissions/backup-probe.pdf'"
t "archive permissions are 600"                   "[ \"\$(stat -c %a $SA)\" = 600 ]"
t "backup.sh never logged the password"           "! grep -q 'fixture-not-a-secret' $S/backup.log"

# One restore command must put both trees back where they belong.
t "single-command restore returns both trees" \
  "mkdir -p $S/restored && tar -xzf $SA -C $S/restored && [ -f $S/restored/storage/app/public/sample.txt ] && [ -f $S/restored/public-uploads/admissions/backup-probe.pdf ]"

# Retention must still prune, and must not orphan a dump's sidecar.
for i in 2 3 4 5; do bash "$D/backup.sh" "bk-$i" >/dev/null 2>&1; done
t "storage retention prunes to KEEP_STORAGE_BACKUPS" \
  "[ \"\$(ls -1 $B/backups/storage-*.tar.gz | wc -l)\" -le 3 ]"
t "db retention keeps each sidecar with its dump" \
  "[ \"\$(ls -1 $B/backups/db-*.sql.gz | wc -l)\" = \"\$(ls -1 $B/backups/db-*.sql.gz.sha256 | wc -l)\" ]"

echo; [ $fails -eq 0 ] && echo "ALL SANDBOX CHECKS PASSED" || echo "$fails SANDBOX CHECK(S) FAILED"
exit $((fails>0))
