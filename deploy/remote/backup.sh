#!/usr/bin/env bash
# Take and VERIFY a database dump and a storage archive. Fails closed.
set -euo pipefail
. "$(dirname "$0")/lib.sh"
TS="${1:-$(date +%Y%m%d-%H%M%S)}"
B="$BASE/backups"; mkdir -p "$B"; chmod 700 "$B"

# The trap removes the credentials file AND any half-written archive.
#
# Without the `.part` cleanup, every rejected dump (truncated, empty, no marker)
# left a stale `db-<ts>.sql.gz.part` behind in backups/. Those files are never
# pruned by retention -- the globs are `db-*.sql.gz`, which does not match
# `db-*.sql.gz.part` -- so a host with flaky credentials accumulates them
# indefinitely. They are worthless, and a stale one sitting next to a good
# archive is actively confusing during an incident.
PARTIAL=""
cleanup() { rm -f -- "$CNF" ${PARTIAL:+"$PARTIAL"}; }
trap cleanup EXIT
CNF="$(mktemp)"; chmod 600 "$CNF"
{ echo "[client]"; echo "host=$(env_get DB_HOST)"; echo "port=$(env_get DB_PORT)"
  echo "user=$(env_get DB_USERNAME)"; echo "password=\"$(env_get DB_PASSWORD)\""; } > "$CNF"
DB="$(env_get DB_DATABASE)"; [ -n "$DB" ] || die "DB_DATABASE empty"

OUT="$B/db-$TS.sql.gz"
log "Dumping database (credentials stay on the server)"
# Registered with the trap BEFORE the dump runs, so a mid-dump failure (a killed
# mysqldump, a full disk) is cleaned up on the way out.
PARTIAL="$OUT.part"
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers --no-tablespaces "$DB" | gzip > "$OUT.part"
gzip -t "$OUT.part" || die "dump is not a valid gzip"
[ "$(stat -c %s "$OUT.part")" -gt 2048 ] || die "dump suspiciously small"
zcat "$OUT.part" | tail -5 | grep -q "Dump completed" || die "dump has no completion marker (truncated?)"
mv "$OUT.part" "$OUT"; PARTIAL=""; chmod 600 "$OUT"
# The sidecar is created by the shell redirect, which honours umask rather than
# an explicit mode, so it lands at 644 while the archive it describes is 600.
# Set it explicitly: the filename it records, and the fact that a dump exists at
# all, are not things an unrelated account on the host needs to learn.
sha256sum "$OUT" > "$OUT.sha256"; chmod 600 "$OUT.sha256"
log "DB backup verified: $(basename "$OUT") ($(stat -c %s "$OUT") bytes)"

# ── ONE verified archive covering BOTH persistent trees ──────────────────────
#
# Two directories hold state that outlives any release and cannot be regenerated
# from the repository:
#
#   $BASE/shared/storage/app        Laravel uploads (framework-managed)
#   $BASE/shared/public-uploads    files served from public/assets/uploads
#
# Both go into a single archive rooted at $BASE/shared, so a restore is one
# unambiguous command that puts both back exactly where they belong:
#
#   tar -xzf backups/storage-<id>.tar.gz -C shared
#
# The archive layout CHANGED. It used to contain `app/...` and was restored with
# `-C shared/storage`. Both members are now rooted at `shared/`, which is what
# makes a single-archive restore correct. docs/DEPLOYMENT.md is updated to match.
# Archives taken before this change still restore correctly with the OLD command;
# check the member list before choosing one.
#
# Nothing about the database dump, its gzip check, its completion-marker check,
# retention or permissions is changed.
SOUT="$B/storage-$TS.tar.gz"
MEMBERS=(storage/app)

# ── shared/public-uploads: four distinct states, only ONE is benign ──────────
#
# deploy-release.sh calls this script for MODE=activate BEFORE it unpacks the
# release and BEFORE prepare_public_uploads() creates shared/public-uploads. On a
# genuine first activation that directory does not exist yet, and that is a normal,
# expected state — preflight deliberately allows it. So absence must NOT fail here,
# or the first Contabo deployment could never run.
#
# What must NOT be tolerated is a directory that is present but is not a real
# directory. The previous test was `[ -d ... ]`, which FOLLOWS SYMLINKS: a
# symlinked public-uploads would have been archived straight through, quietly
# copying whatever the link pointed at into a web-adjacent backup. Symlink and
# non-directory are therefore refused explicitly.
#
# The absent case is handled by archiving the members that DO exist and saying so.
# Nothing is fabricated: this script never creates public-uploads, never writes
# into it, and never stands in application upload content that was not there.
PU="$BASE/shared/public-uploads"
if [ -L "$PU" ]; then
  die "shared/public-uploads is a symlink; refusing to archive through it. Fix the deployment layout first."
elif [ -e "$PU" ] && [ ! -d "$PU" ]; then
  die "shared/public-uploads exists but is not a directory; refusing to archive it"
elif [ -d "$PU" ]; then
  MEMBERS+=(public-uploads)
  log "shared/public-uploads present; including it in this backup"
else
  # Legitimately absent: the pre-first-release state. The archive is still valid and
  # still covers everything that exists.
  log "shared/public-uploads does not exist yet (normal before the first release); this archive covers storage/app only"
fi

log "Archiving shared persistent data: ${MEMBERS[*]}"
PARTIAL="$SOUT.part"
tar -czf "$SOUT.part" -C "$BASE/shared" "${MEMBERS[@]}"
tar -tzf "$SOUT.part" >/dev/null || die "storage archive unreadable"

# Verify by CONTENT, not merely that tar exited 0. An archive that silently omitted
# the uploads tree would look perfectly healthy right up until an incident.
#
# The listing is written to a FILE and grepped from that file, never `tar | grep -q`.
# `grep -q` exits on the first match and closes the pipe, so tar is killed by SIGPIPE
# and the pipeline returns 141; under this script'"'"'s `set -o pipefail` that counts as a
# failure even though the match succeeded. Measured on a 404-entry archive: 0/20
# successes piped, 20/20 via a file. Verified writes to a temp file for the same
# reason: it also removes the pipe entirely.
LIST="$(mktemp)"; chmod 600 "$LIST"
tar -tzf "$SOUT.part" > "$LIST" || { rm -f "$LIST"; die "storage archive unreadable"; }
for m in "${MEMBERS[@]}"; do
  grep -q "^$m/" "$LIST" \
    || { rm -f "$LIST"; die "storage archive does not contain $m - refusing to call it verified"; }
done
ENTRY_COUNT="$(wc -l < "$LIST" | tr -d ' ')"
rm -f "$LIST"

mv "$SOUT.part" "$SOUT"; PARTIAL=""; chmod 600 "$SOUT"
log "Storage backup verified: $(basename "$SOUT") ($ENTRY_COUNT entries, covering ${MEMBERS[*]})"

# Retention: prune only our own, well-named, OLD files; never the newest.
ls -t "$B"/db-*.sql.gz      2>/dev/null | tail -n +$((KEEP_DB_BACKUPS+1))      | while read -r f; do rm -f -- "$f" "$f.sha256"; done
ls -t "$B"/storage-*.tar.gz 2>/dev/null | tail -n +$((KEEP_STORAGE_BACKUPS+1)) | while read -r f; do rm -f -- "$f"; done
echo "BACKUP_DB=$OUT"
