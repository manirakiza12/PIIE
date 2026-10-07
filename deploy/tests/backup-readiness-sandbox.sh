#!/usr/bin/env bash
# Backup readiness: prove the deploy can produce a database dump that is real,
# verifiable and retained, WITHOUT ever touching a production database.
#
# mysqldump is stubbed. So these tests prove the SHELL logic -- argument safety,
# gzip validity, the size floor, the completion marker, atomicity, checksums and
# retention ordering -- not that MariaDB itself is healthy. They cannot prove that
# last point; only a real dump on the host can, and that is deliberately not run.
#
# WHAT IS GENUINELY UNDER TEST HERE
#   * credentials come from a 600 my.cnf, never from argv (argv is world-visible
#     in `ps`, so a password on the command line leaks to every account on the host)
#   * a truncated or empty dump is REJECTED rather than stored as a valid backup
#   * a partial file is never left under the final name (atomic mv)
#   * retention keeps the newest N and never deletes the newest valid backup
#   * a failed dump cannot destroy an existing good backup
#   * one storage archive covers BOTH shared/storage/app and shared/public-uploads
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
D="$ROOT/deploy/remote"
S="$(mktemp -d)"; trap 'rm -rf "$S"' EXIT
fails=0
t() { if eval "$2"; then echo "  PASS  $1"; else echo "  FAIL  $1"; fails=$((fails+1)); fi; }

# ── stub mysqldump ───────────────────────────────────────────────────────────
# Emits a real gzip stream so `gzip -t` is genuinely exercised, and refuses
# credentials on argv the way production must.
BIN="$S/bin"; mkdir -p "$BIN"
cat > "$BIN/mysqldump" <<'STUB'
#!/bin/sh
for a in "$@"; do
  case "$a" in
    --password=*|-p?*) echo "FATAL: password on argv" >&2; exit 2 ;;
  esac
done
# NOTE: this stub writes PLAIN TEXT on stdout, exactly as mysqldump does. It must
# NOT gzip: backup.sh does the compressing (`mysqldump | gzip > out.part`), so a
# stub that pre-compresses produces a DOUBLE-gzipped archive. That still passes
# `gzip -t` and the size floor, but the completion marker is then invisible to
# zcat and the script correctly rejects it -- a confusing failure that looks like
# a script bug and is not one.
case "${STUB_MODE:-good}" in
  good)
    echo "-- MySQL dump 10.19  Distrib 10.11.6-MariaDB"
    i=0; while [ $i -lt 900 ]; do
      echo "INSERT INTO \`t$i\` VALUES ($i,'row data $i','aaaaaaaaaaaaaaaaaaaa');"
      i=$((i+1)); done
    echo "-- Dump completed on 2026-10-06 12:00:00"
    ;;
  empty)    : ;;
  nomarker) i=0; while [ $i -lt 900 ]; do
               echo "INSERT INTO \`t$i\` VALUES ($i,'row data $i','aaaaaaaaaaaaaaaaaaaa');"
               i=$((i+1)); done ;;
  corrupt)  # Valid gzip, but the payload is not a SQL dump at all.
            i=0; while [ $i -lt 900 ]; do
              echo "this is not a database dump line $i padding padding padding"
              i=$((i+1)); done ;;
  fail)     echo "mysqldump: Got error: 1045 Access denied for user" >&2; exit 1 ;;
esac
STUB
chmod +x "$BIN/mysqldump"
export PATH="$BIN:$PATH"

# backup.sh reads BASE from lib.sh and DB_* from $BASE/shared/.env via env_get,
# so the fixture must supply a real .env rather than relying on the environment.
# A dedicated, EMPTY TMPDIR. backup.sh's mktemp credentials file must land here
# and be gone afterwards, and asserting on a private directory removes any chance
# of the check passing or failing because of an unrelated file in a shared /tmp.
export TMPDIR="$S/tmp"; mkdir -p "$TMPDIR"

export PIIE_BASE="$S/dep"
mkdir -p "$PIIE_BASE/shared/storage/app/public" "$PIIE_BASE/shared/public-uploads" "$PIIE_BASE/backups"
cat > "$PIIE_BASE/shared/.env" <<'ENVF'
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:stub
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=piie_stub
DB_USERNAME=piie
DB_PASSWORD=sup3rs3cret
ENVF
chmod 600 "$PIIE_BASE/shared/.env"
B="$PIIE_BASE/backups"

# ── 1. structural requirements ───────────────────────────────────────────────
echo "backup.sh structure"
t "uses mysqldump"                      "grep -q 'mysqldump' $D/backup.sh"
t "credentials via --defaults-extra-file, not argv" "grep -q 'defaults-extra-file' $D/backup.sh"
t "no --password= / bare -p on a mysqldump line" \
  "! grep -nE 'mysqldump.*(-p[a-zA-Z]|--password=)' $D/backup.sh"
t "the credentials file is chmod 600"    "grep -qE 'chmod 600 .*CNF|CNF.*chmod 600' $D/backup.sh"
t "the credentials file is a mktemp"     "grep -qE 'CNF=.*mktemp' $D/backup.sh"
# The cleanup is a named function invoked by the trap, not an inline `rm`, so
# assert the trap calls it rather than looking for a literal `rm` on one line.
t "a cleanup handler is registered on exit"     "grep -qE 'trap cleanup EXIT' $D/backup.sh"
t "the cleanup handler removes the credentials file" \
  "grep -qE '^cleanup\(\).*rm -f -- .*CNF' $D/backup.sh"
t "the cleanup handler also removes partial archives" \
  "grep -qE 'cleanup\(\).*PARTIAL' $D/backup.sh"
t "compresses with gzip"                 "grep -q 'gzip' $D/backup.sh"
t "verifies with gzip -t"               "grep -q 'gzip -t' $D/backup.sh"
t "enforces a non-zero size floor"      "grep -qE 'stat -c %s' $D/backup.sh"
t "checks the dump completion marker"   "grep -q 'Dump completed' $D/backup.sh"
t "writes a sha256 sidecar"             "grep -q 'sha256sum' $D/backup.sh"
t "timestamps the filename"             "grep -qE 'date \+%Y%m%d-%H%M%S' $D/backup.sh"
t "finalises atomically (.part -> mv)"  "grep -q '\.part' $D/backup.sh"
t "backups are mode 600"                "grep -qE 'chmod 600' $D/backup.sh"
t "the backup dir is mode 700"          "grep -qE 'chmod 700' $D/backup.sh"
t "retention is bounded"                "grep -q 'tail -n +' $D/backup.sh"

# `tar ... | grep -q` returns 141 under `pipefail` when grep exits first. That was
# a real deploy-blocking defect, so its absence is asserted rather than assumed.
t "no tar-piped-into-grep anywhere in deploy/remote" \
  "! grep -rnE 'tar -[tz]?[a-z]*f?[^|]*\| *grep -q' $D/ 2>/dev/null"

# ── 2. a real dump, end to end ───────────────────────────────────────────────
echo "producing a dump"
# backup.sh takes a single optional argument used as the timestamp. That is how
# deploy-release.sh calls it (`backup.sh "$RID"`), so it is how it is called here.
#
# The timestamp is fixed rather than taken from `date`, because two runs inside
# the same wall-clock second would collide on the output filename and silently
# overwrite one another -- making a later "two archives exist" assertion fail for
# a reason that has nothing to do with the code under test.
TS1="20260101-000001"
bash "$D/backup.sh" "$TS1" > "$S/b1.log" 2>&1
t "backup.sh exits 0"                       "[ $? -eq 0 ]"
t "it defaults the timestamp when given none" \
  "grep -qE 'TS=\"\\\$\{1:-\\\$\(date \+%Y%m%d-%H%M%S\)\}\"' $D/backup.sh"

OUT="$(ls "$B"/db-*.sql.gz 2>/dev/null | head -1)"
t "a db-*.sql.gz archive exists"            "[ -n '$OUT' ]"
t "the archive is a valid gzip"              "[ -n '$OUT' ] && gzip -t '$OUT'"
t "the archive is larger than 2 KB"         "[ -n '$OUT' ] && [ \$(stat -c %s '$OUT') -gt 2048 ]"
t "the decompressed dump has the completion marker" \
  "[ -n '$OUT' ] && zcat '$OUT' | tail -5 | grep -q 'Dump completed'"
t "the archive name is timestamped" \
  "[ -n '$OUT' ] && basename '$OUT' | grep -qE '^db-[0-9]{8}-[0-9]{6}\.sql\.gz$'"
t "the archive is mode 600"                 "[ -n '$OUT' ] && [ \"\$(stat -c %a '$OUT')\" = 600 ]"
t "the backups dir is mode 700"             "[ \"\$(stat -c %a '$B')\" = 700 ]"
t "no .part file was left behind"           "[ -z \"\$(ls '$B'/*.part 2>/dev/null)\" ]"
t "a .sha256 sidecar was written"           "[ -f '$OUT.sha256' ]"
t "the checksum VERIFIES" \
  "[ -f '$OUT.sha256' ] && (cd '$B' && sha256sum -c \"\$(basename '$OUT').sha256\" >/dev/null 2>&1)"
t "the checksum file is also 600"           "[ -f '$OUT.sha256' ] && [ \"\$(stat -c %a '$OUT.sha256')\" = 600 ]"
t "the dump does not contain the DB password" \
  "[ -n '$OUT' ] && ! zcat '$OUT' | grep -q 'sup3rs3cret'"
# `zcat | grep -q` has the same SIGPIPE/141 hazard under `set -o pipefail` that
# `tar | grep -q` does, so this suite's own checks list to a FILE first. The
# pipeline above runs under `eval` without pipefail, but being consistent here
# keeps the suite from modelling the bug it is meant to catch.
if [ -n "$OUT" ]; then zcat "$OUT" > "$S/plain.sql" 2>/dev/null; fi
t "the archive is compressed EXACTLY ONCE (not double-gzipped)" \
  "[ -n '$OUT' ] && head -1 '$S/plain.sql' | grep -q '^-- MySQL dump'"
t "the decompressed dump is plain SQL, not binary" \
  "[ -n '$OUT' ] && grep -c 'INSERT INTO' '$S/plain.sql' | grep -q '^900$'"

# Asserted against the suite's own empty TMPDIR, so this is exact rather than a
# best-effort scan of a shared /tmp that other processes are writing to.
t "no leftover credentials file in the private TMPDIR" \
  "[ -z \"\$(grep -rl 'sup3rs3cret' \"$TMPDIR\" 2>/dev/null | head -1)\" ]"

# Prove the check above can actually FAIL, so it is not a vacuous assertion: drop
# a file containing the password into TMPDIR and confirm it is detected.
printf 'password="sup3rs3cret"\n' > "$TMPDIR/tmp.LEFTOVERPROBE"
t "the leftover-credentials check DOES detect a planted file" \
  "[ -n \"\$(grep -rl 'sup3rs3cret' \"$TMPDIR\" 2>/dev/null | head -1)\" ]"
rm -f "$TMPDIR/tmp.LEFTOVERPROBE"

# ── 3. bad dumps are REJECTED, and a good backup survives ────────────────────
echo "rejecting invalid dumps"
GOOD="$OUT"; GOODSUM="$(cat "$OUT.sha256")"
# Each failing run gets its own timestamp, so a rejected dump cannot be confused
# with an overwrite of a good one.
_bt=2
run_bad() { _bt=$((_bt+1)); STUB_MODE="$1" bash "$D/backup.sh" "20260101-00000$_bt" > "$S/bad.log" 2>&1; echo $?; }

rc=$(run_bad empty)
t "an EMPTY dump is rejected"                 "[ $rc -ne 0 ]"
rc=$(run_bad nomarker)
t "a TRUNCATED dump (no marker) is rejected"  "[ $rc -ne 0 ]"
rc=$(run_bad corrupt)
# `mysqldump | gzip` always yields a valid gzip stream, so a non-dump payload
# cannot be caught by `gzip -t`. It is the completion marker that catches it --
# which is precisely why backup.sh checks for one.
t "a valid-gzip NON-DUMP payload is rejected"  "[ $rc -ne 0 ]"
t "the marker check is what rejects it"       "grep -qi 'completion marker' $S/bad.log"
rc=$(run_bad fail)
t "a mysqldump FAILURE is rejected"           "[ $rc -ne 0 ]"

t "the earlier good backup still exists"      "[ -f '$GOOD' ]"
t "the earlier good backup is still valid"    "gzip -t '$GOOD'"
t "the earlier good checksum is unchanged"    "[ \"\$(cat '$GOOD.sha256')\" = '$GOODSUM' ]"
t "no .part file left after failures"         "[ -z \"\$(ls '$B'/*.part 2>/dev/null)\" ]"
t "only ONE good archive was kept, not four"  "[ \$(ls $B/db-*.sql.gz | wc -l) -eq 1 ]"

# ── 4. retention keeps the newest and does not over-delete ───────────────────
echo "retention"
RB="$S/retain"; mkdir -p "$RB"
for i in $(seq 1 12); do
  f="$RB/db-202601$(printf '%02d' $i)-000000.sql.gz"
  echo "archive $i" | gzip > "$f"; chmod 600 "$f"
  touch -d "2026-01-$(printf '%02d' $i) 00:00:00" "$f"
done
t "fixture has 12 archives"                   "[ \$(ls $RB/db-*.sql.gz | wc -l) -eq 12 ]"
t "the newest fixture archive is day 12" \
  "[ \"\$(basename \$(ls -t $RB/db-*.sql.gz | head -1))\" = 'db-20260112-000000.sql.gz' ]"

# Apply backup.sh's own retention expression to the fixture.
KEEP="${PIIE_KEEP_DB_BACKUPS:-5}"
ls -t "$RB"/db-*.sql.gz | tail -n +$((KEEP+1)) | while read -r f; do rm -f -- "$f" "$f.sha256"; done
n="$(ls "$RB"/db-*.sql.gz | wc -l)"
t "retention keeps exactly KEEP archives"      "[ $n -eq $KEEP ]"
t "retention KEEPS the newest archive"         "[ -f '$RB/db-20260112-000000.sql.gz' ]"
t "retention keeps the newest KEEP by mtime"   "[ -f '$RB/db-20260108-000000.sql.gz' ]"
t "retention deleted the OLDEST archive"       "[ ! -f '$RB/db-20260101-000000.sql.gz' ]"
t "retention left the newest valid backup intact and readable" \
  "gzip -t '$RB/db-20260112-000000.sql.gz'"

# Fewer archives than KEEP must delete nothing -- the case that would otherwise
# wipe every backup after a quiet period.
RB2="$S/retain2"; mkdir -p "$RB2"
for i in 1 2 3; do echo "a$i" | gzip > "$RB2/db-2026020$i-000000.sql.gz"; chmod 600 "$RB2/db-2026020$i-000000.sql.gz"; done
ls -t "$RB2"/db-*.sql.gz | tail -n +$((KEEP+1)) | while read -r f; do rm -f -- "$f" "$f.sha256"; done
t "retention deletes NOTHING when under KEEP"  "[ \$(ls $RB2/db-*.sql.gz | wc -l) -eq 3 ]"

# The real script's retention, run against the real backup dir, must also keep
# the newest archive it just wrote.
t "the live script's retention keeps the newest real archive" \
  "[ -n '$GOOD' ] && [ -f '$GOOD' ]"

# ── 5. the storage half, and the shared/-rooted layout ───────────────────────
echo "storage backups and layout"
# One invocation takes BOTH halves; the db archive is already present from above.
echo "irreplaceable user document" > "$PIIE_BASE/shared/storage/app/doc.pdf"
echo "an upload" > "$PIIE_BASE/shared/public-uploads/logo.png"
TS2="20260101-000002"
bash "$D/backup.sh" "$TS2" > "$S/b2.log" 2>&1
t "the second run exits 0"                   "[ $? -eq 0 ]"
SOUT="$(ls "$B"/storage-*.tar.gz 2>/dev/null | head -1)"
t "a storage-*.tar.gz archive exists"        "[ -n '$SOUT' ]"
t "it is a valid gzip"                       "[ -n '$SOUT' ] && gzip -t '$SOUT'"

# Listed from the archive belonging to THIS run. `ls ... | head -1` would pick
# the oldest of several archives, and the doc.pdf / logo.png fixtures only exist
# in the second run's tree -- so matching against the first archive's listing
# fails for a reason unrelated to the code.
SOUT2="$B/storage-$TS2.tar.gz"
if [ -f "$SOUT2" ]; then tar -tzf "$SOUT2" > "$S/stor.list" 2>/dev/null; fi
# Listed to a FILE, then grepped from the file. This suite sets `pipefail`, so
# `tar -tzf ... | grep -q` would fail here with 141 for exactly the reason
# backup.sh avoids that pattern -- the test would model the bug it documents.
t "it contains storage/app/doc.pdf"          "[ -f '$SOUT2' ] && grep -q 'storage/app/doc.pdf' '$S/stor.list'"
t "it contains public-uploads/logo.png"      "[ -f '$SOUT2' ] && grep -q 'public-uploads/logo.png' '$S/stor.list'"
# Rooted at shared/, not shared/storage, so ONE archive restores BOTH trees with
# a single `tar -xzf ... -C shared`.
# Rooted at shared/, so ONE archive restores BOTH trees with a single
# `tar -xzf ... -C shared`. An archive rooted at shared/storage would instead
# carry a bare `app/doc.pdf`, which restores into the wrong place.
t "it is rooted at shared/, not shared/storage" \
  "[ -f '$SOUT2' ] && grep -qx 'storage/app/doc.pdf' '$S/stor.list'"
t "it has NO bare app/ entry (that would mean the wrong -C)" \
  "[ -f '$SOUT2' ] && ! grep -q '^app/' '$S/stor.list'"
t "one archive carries both persistent trees" \
  "[ -f '$SOUT2' ] && [ \$(grep -c -e '^storage/' -e '^public-uploads/' '$S/stor.list') -ge 2 ]"
t "both trees restore into place with ONE tar -C shared" \
  "RD=\$(mktemp -d) && tar -xzf '$SOUT2' -C \"\$RD\" && [ -f \"\$RD/storage/app/doc.pdf\" ] && [ -f \"\$RD/public-uploads/logo.png\" ] && rm -rf \"\$RD\""
t "the storage archive is mode 600"          "[ -f '$SOUT2' ] && [ \"\$(stat -c %a '$SOUT2')\" = 600 ]"

# ── 6. one invocation produces both halves, as the deploy calls it ──────────
echo "one invocation, both halves"
# Each successful run writes BOTH a db-<ts>.sql.gz and a storage-<ts>.tar.gz, so
# the counts must stay equal. An imbalance would mean one half silently skipped.
ndb="$(ls "$B"/db-*.sql.gz 2>/dev/null | wc -l)"
nsto="$(ls "$B"/storage-*.tar.gz 2>/dev/null | wc -l)"
t "every successful run produced BOTH halves"    "[ $ndb -eq $nsto ]"
t "two successful runs are recorded, and did NOT collide" "[ $ndb -ge 2 ]"
t "distinct timestamps produce distinct archives" \
  "[ -f '$B/db-$TS1.sql.gz' ] && [ -f '$B/db-$TS2.sql.gz' ]"
t "no leftover .part or checksum-less archive" \
  "[ -z \"\$(ls '$B'/*.part 2>/dev/null)\" ]"
t "deploy-release.sh calls backup.sh with a single timestamp arg" \
  "grep -qE 'backup\.sh\" \"\\\$RID' $ROOT/deploy/remote/deploy-release.sh"

# ── 7. the four-state public-uploads branch ─────────────────────────────────
# deploy-release.sh calls backup.sh for MODE=activate BEFORE unpacking and BEFORE
# prepare_public_uploads() creates shared/public-uploads. Three of the four states
# must be tolerated; only the hostile ones may fail.
echo "absent public-uploads (normal on first activation)"
PB="$S/pre"; rm -rf "$PB"; mkdir -p "$PB/shared/storage/app/public" "$PB/backups"
cp "$PIIE_BASE/shared/.env" "$PB/shared/.env"
PIIE_BASE="$PB" bash "$D/backup.sh" "20260101-000010" > "$S/p_absent.log" 2>&1
t "an ABSENT public-uploads is tolerated"     "[ $? -eq 0 ]"
t "it did not fabricate the directory"        "[ ! -d '$PB/shared/public-uploads' ]"
t "it still archived storage/app" \
  "tar -tzf \$(ls $PB/backups/storage-*.tar.gz | head -1) | grep -q 'storage/app/'"

echo "public-uploads is a SYMLINK (must refuse)"
PB2="$S/pre2"; rm -rf "$PB2"; mkdir -p "$PB2/shared/storage/app/public" "$PB2/backups" "$S/elsewhere"
cp "$PIIE_BASE/shared/.env" "$PB2/shared/.env"
echo x > "$S/elsewhere/target"; ln -s "$S/elsewhere/target" "$PB2/shared/public-uploads"
PIIE_BASE="$PB2" bash "$D/backup.sh" "20260101-000011" > "$S/p_link.log" 2>&1
t "a SYMLINKED public-uploads is refused"     "[ $? -ne 0 ]"
t "the refusal names the reason"              "grep -qi 'symlink' $S/p_link.log"

echo "public-uploads is a FILE (must refuse)"
PB3="$S/pre3"; rm -rf "$PB3"; mkdir -p "$PB3/shared/storage/app/public" "$PB3/backups"
cp "$PIIE_BASE/shared/.env" "$PB3/shared/.env"
echo not-a-dir > "$PB3/shared/public-uploads"
PIIE_BASE="$PB3" bash "$D/backup.sh" "20260101-000012" > "$S/p_file.log" 2>&1
t "a public-uploads FILE is refused"          "[ $? -ne 0 ]"

echo "public-uploads present as a real directory (normal)"
t "the populated run earlier succeeded" \
  "[ -f \"\$(ls $B/storage-*.tar.gz | head -1)\" ]"

echo
[ $fails -eq 0 ] && echo "ALL BACKUP READINESS CHECKS PASSED" || echo "$fails BACKUP READINESS CHECK(S) FAILED"
exit $((fails>0))
