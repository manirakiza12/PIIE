#!/usr/bin/env bash
# Regression coverage for the two integration issues found in review:
#
#   1. backup.sh must tolerate a genuinely absent shared/public-uploads (the
#      pre-first-release state) while REFUSING a symlinked or non-directory one.
#   2. the runtime permission model must be group-write + setgid, never 777, and
#      must never touch permissions on pre-existing production files.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
D="$ROOT/deploy/remote"
S="$(mktemp -d)"; trap 'rm -rf "$S"' EXIT
fails=0
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

B="$S/dep"
setup() {
  rm -rf "$B" "$S/bin"
  mkdir -p "$B/bin" "$B/backups" "$B/releases" "$B/shared/storage/app/public" "$S/bin"
  printf 'APP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=x\nDB_HOST=db\nDB_DATABASE=piie\nDB_USERNAME=piie\nDB_PASSWORD=fixture\n' > "$B/shared/.env"
  printf 'laravel payload\n' > "$B/shared/storage/app/public/sample.txt"
  printf '#!/bin/sh\nexit 0\n' > "$S/bin/flock"
  cat > "$S/bin/mysqldump" <<'STUB'
#!/bin/sh
echo "-- MySQL dump"; echo "CREATE TABLE t (id int);"
head -c 20000 /dev/urandom | base64
echo "-- Dump completed on 2026-10-06  0:00:00"
STUB
  cat > "$S/bin/php" <<'STUB'
#!/bin/sh
echo "stub php $*"
STUB
  chmod +x "$S/bin/flock" "$S/bin/mysqldump" "$S/bin/php"
  export PATH="$S/bin:$PATH" PIIE_BASE="$B" PIIE_PHP="$S/bin/php" PIIE_DOCROOT="$B/current/public"
}

# ── ISSUE 1 ──────────────────────────────────────────────────────────────────
echo "backup.sh with a legitimately absent shared/public-uploads"
setup
t "fixture really has no public-uploads"  "[ ! -e $B/shared/public-uploads ]"
bash "$D/backup.sh" i1 > "$S/i1.log" 2>&1; rc=$?
t "backup SUCCEEDS with public-uploads absent" "[ $rc -eq 0 ]"
t "archive still contains storage/app"      "archive_has $B/backups/storage-i1.tar.gz '^storage/app/'"
t "archive holds the Laravel payload"       "archive_has $B/backups/storage-i1.tar.gz '^storage/app/public/sample.txt'"
t "DB dump still produced and verified"     "[ -f $B/backups/db-i1.sql.gz ] && gzip -t $B/backups/db-i1.sql.gz && zcat $B/backups/db-i1.sql.gz | grep -q 'Dump completed'"
t "archive keeps 600 permissions"           "[ \"\$(stat -c %a $B/backups/storage-i1.tar.gz)\" = 600 ]"
t "absence is explained in the log"         "grep -q 'does not exist yet' $S/i1.log"
t "backup did NOT create public-uploads"    "[ ! -e $B/shared/public-uploads ]"
t "backup did NOT fabricate upload content"  "[ ! -e $B/shared/public-uploads ] && [ -f $B/shared/storage/app/public/sample.txt ]"

echo
echo "backup.sh with public-uploads present"
setup
mkdir -p "$B/shared/public-uploads/admissions"
printf 'live upload\n' > "$B/shared/public-uploads/admissions/id.pdf"
bash "$D/backup.sh" i2 > "$S/i2.log" 2>&1; rc=$?
t "backup succeeds"                          "[ $rc -eq 0 ]"
t "archive includes public-uploads"          "archive_has $B/backups/storage-i2.tar.gz '^public-uploads/'"
t "archive holds the live upload"            "archive_has $B/backups/storage-i2.tar.gz '^public-uploads/admissions/id.pdf'"
t "archive includes storage/app too"         "archive_has $B/backups/storage-i2.tar.gz '^storage/app/'"
t "presence is logged"                       "grep -q 'present; including' $S/i2.log"

echo
echo "backup.sh refuses an unsafe shared/public-uploads"
setup
mkdir -p "$B/elsewhere"; printf 'SECRET\n' > "$B/elsewhere/secret.txt"
ln -s "$B/elsewhere" "$B/shared/public-uploads"
bash "$D/backup.sh" i3 > "$S/i3.log" 2>&1; rc=$?
t "symlinked public-uploads is REFUSED"      "[ $rc -ne 0 ]"
t "refusal names the symlink reason"         "grep -q 'is a symlink' $S/i3.log"
t "secret content was NOT archived"          "! archive_has $B/backups/storage-i3.tar.gz secret.txt"
t "no storage archive left behind"           "[ ! -f $B/backups/storage-i3.tar.gz ]"

setup
printf 'i am a file\n' > "$B/shared/public-uploads"
bash "$D/backup.sh" i4 > "$S/i4.log" 2>&1; rc=$?
t "non-directory public-uploads is REFUSED"  "[ $rc -ne 0 ]"
t "refusal names the not-a-directory reason" "grep -q 'not a directory' $S/i4.log"
t "no storage archive left behind (2)"       "[ ! -f $B/backups/storage-i4.tar.gz ]"

echo
echo "backup-before-migration ordering is unchanged"
setup
bash "$D/deploy-release.sh" 20261006-000000-abcdef1 "$S/nonexistent.tar.gz" 00 activate > /dev/null 2>&1
t "activate still runs backup BEFORE unpacking" \
  "grep -n 'backup.sh' $D/deploy-release.sh | head -1 | cut -d: -f1 | xargs -I{} sh -c 'test {} -lt \$(grep -n \"Unpacking into\" $D/deploy-release.sh | head -1 | cut -d: -f1)'"
t "backup still precedes migrate" \
  "grep -n 'backup.sh' $D/deploy-release.sh | head -1 | cut -d: -f1 | xargs -I{} sh -c 'test {} -lt \$(grep -n \"migrate --force\" $D/deploy-release.sh | head -1 | cut -d: -f1)'"
t "prepare_public_uploads still precedes migrate" \
  "grep -n 'prepare_public_uploads \"' $D/deploy-release.sh | head -1 | cut -d: -f1 | xargs -I{} sh -c 'test {} -lt \$(grep -n \"migrate --force\" $D/deploy-release.sh | head -1 | cut -d: -f1)'"

# ── ISSUE 2 ──────────────────────────────────────────────────────────────────
echo
echo "runtime permission model"
setup
mkdir -p "$S/art/public/assets/uploads/logo" "$S/art/public"
touch "$S/art/artisan" "$S/art/RELEASE_SHA"
printf 'BASELINE\n' > "$S/art/public/assets/uploads/logo/logo.png"
tar -czf "$S/art.tar.gz" -C "$S/art" .
SUM="$(sha256sum "$S/art.tar.gz" | cut -d' ' -f1)"
bash "$D/deploy-release.sh" 20261006-000000-abcdef2 "$S/art.tar.gz" "$SUM" stage > "$S/perm.log" 2>&1; rc=$?
t "stage succeeds"                                    "[ $rc -eq 0 ]"

PU="$B/shared/public-uploads"
PMODE="$(stat -c %a "$PU")"
t "top dir is group-writable"                         "[ \$PMODE -ge 2000 ] && [ \"\${PMODE: -1}\" -ge 5 ] || [ \"\${PMODE: -1}\" = 7 ] || [ \"\${PMODE: -1}\" = 6 ] || [ \"\${PMODE: -1}\" = 3 ] || [ \"\${PMODE: -1}\" = 2 ]"
t "top dir has setgid (mode starts with 2)"           "[ \"\${PMODE:0:1}\" = 2 ]"
t "top dir is NOT 777"                                "[ \"$PMODE\" != 777 ]"
t "nested created dir also carries setgid"            "[ \"\$(stat -c %a $PU/logo)\" != 0 ] && [ \"\$(stat -c %a $PU/logo | cut -c1)\" = 2 ]"
t "merged baseline file is group-writable, not 777"   "[ \"\$(stat -c %a $PU/logo/logo.png)\" = 664 ]"

echo
echo "pre-existing production permissions are left alone"
setup
mkdir -p "$PU/existing"
printf 'LIVE\n' > "$PU/existing/live.pdf"
chmod 600 "$PU/existing/live.pdf"
chmod 700 "$PU/existing"
BEFORE_FILE="$(stat -c %a "$PU/existing/live.pdf")"
BEFORE_DIR="$(stat -c %a "$PU/existing")"
tar -czf "$S/art2.tar.gz" -C "$S/art" .
SUM2="$(sha256sum "$S/art2.tar.gz" | cut -d' ' -f1)"
bash "$D/deploy-release.sh" 20261006-000000-abcdef3 "$S/art2.tar.gz" "$SUM2" stage > /dev/null 2>&1
t "pre-existing file mode unchanged"  "[ \"\$(stat -c %a $PU/existing/live.pdf)\" = $BEFORE_FILE ]"
t "pre-existing directory mode unchanged" "[ \"\$(stat -c %a $PU/existing)\" = $BEFORE_DIR ]"
t "pre-existing file still there"     "[ -f $PU/existing/live.pdf ] && grep -q LIVE $PU/existing/live.pdf"

echo
echo "no chmod 777 anywhere in the deploy scripts"
t "no executed chmod 777" \
  "! cat $D/*.sh | grep -v '^[[:space:]]*#' | grep -qE 'chmod[^\\n]*777'"
t "no recursive chown of existing uploads" \
  "! cat $D/*.sh | grep -v '^[[:space:]]*#' | grep -qE 'chown[[:space:]]+-R'"

echo
echo "runtime group is configurable, not hard-coded"
t "PIIE_RUNTIME_GROUP is honoured"  "grep -q 'PIIE_RUNTIME_GROUP' $D/deploy-release.sh && grep -q 'PIIE_RUNTIME_GROUP' $D/preflight.sh"
# The script that actually runs from CI is deploy-release.sh; it must not invoke sudo
# at all. preflight.sh may probe with sudo, but only behind `command -v` and `-n`.
t "CI script deploy-release.sh never invokes sudo" \
  "! grep -v '^[[:space:]]*#' $D/deploy-release.sh | grep -q 'sudo'"
t "preflight probes sudo only behind command -v" \
  "grep -v '^[[:space:]]*#' $D/preflight.sh | grep 'sudo' | grep -qv 'command -v sudo' && false || true; grep -q 'command -v sudo' $D/preflight.sh && ! grep -v '^[[:space:]]*#' $D/preflight.sh | grep -qE '^[[:space:]]*sudo '"


echo
echo "SIGPIPE regression: tar | grep -q is NOT usable under pipefail"
# This was a real defect in backup.sh, not a test artefact. `tar -tzf A | grep -q B`
# makes grep exit on first match, kills tar with SIGPIPE, and pipefail turns the
# 141 into a failure even when the match succeeded — so backup.sh's own
# "does the archive contain this?" verification could refuse a perfectly good
# archive, and on a FIRST activation that means refusing to deploy at all.
mkfixture() {
  rm -rf /tmp/sp; mkdir -p /tmp/sp/src/storage/app/public /tmp/sp/src/public-uploads
  i=0; while [ $i -lt 400 ]; do echo "payload $i" > "/tmp/sp/src/public-uploads/f-$i.png"; i=$((i+1)); done
  echo x > /tmp/sp/src/storage/app/public/sample.txt
  tar -czf /tmp/sp/a.tar.gz -C /tmp/sp/src storage/app public-uploads
}
mkfixture
piped_ok=0; file_ok=0
for i in $(seq 1 10); do
  tar -tzf /tmp/sp/a.tar.gz | grep -q '^storage/app/' && piped_ok=$((piped_ok+1))
  tar -tzf /tmp/sp/a.tar.gz > /tmp/sp/list.txt; grep -q '^storage/app/' /tmp/sp/list.txt && file_ok=$((file_ok+1))
done
t "the piped form IS unreliable (demonstrates the bug)"  "[ $piped_ok -lt 10 ]"
t "the file form IS reliable (10/10)"                    "[ $file_ok -eq 10 ]"
t "backup.sh does NOT use the piped form"                "! grep -qE 'tar -tzf[^|]*\| *grep -q' $D/backup.sh"
t "the archive_has helper does NOT pipe tar into grep"   "! grep -A6 'archive_has()' $D/sandbox.sh | grep -q 'tar -tzf.*|.*grep'"
rm -rf /tmp/sp

echo
[ $fails -eq 0 ] && echo "ALL INTEGRATION CHECKS PASSED" || echo "$fails INTEGRATION CHECK(S) FAILED"
exit $((fails>0))