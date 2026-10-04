#!/usr/bin/env bash
# Take and VERIFY a database dump and a storage archive. Fails closed.
set -euo pipefail
. "$(dirname "$0")/lib.sh"
TS="${1:-$(date +%Y%m%d-%H%M%S)}"
B="$BASE/backups"; mkdir -p "$B"; chmod 700 "$B"

CNF="$(mktemp)"; trap 'rm -f "$CNF"' EXIT; chmod 600 "$CNF"
{ echo "[client]"; echo "host=$(env_get DB_HOST)"; echo "port=$(env_get DB_PORT)"
  echo "user=$(env_get DB_USERNAME)"; echo "password=\"$(env_get DB_PASSWORD)\""; } > "$CNF"
DB="$(env_get DB_DATABASE)"; [ -n "$DB" ] || die "DB_DATABASE empty"

OUT="$B/db-$TS.sql.gz"
log "Dumping database (credentials stay on the server)"
mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --routines --triggers --no-tablespaces "$DB" | gzip > "$OUT.part"
gzip -t "$OUT.part" || die "dump is not a valid gzip"
[ "$(stat -c %s "$OUT.part")" -gt 2048 ] || die "dump suspiciously small"
zcat "$OUT.part" | tail -5 | grep -q "Dump completed" || die "dump has no completion marker (truncated?)"
mv "$OUT.part" "$OUT"; chmod 600 "$OUT"
sha256sum "$OUT" > "$OUT.sha256"
log "DB backup verified: $(basename "$OUT") ($(stat -c %s "$OUT") bytes)"

SOUT="$B/storage-$TS.tar.gz"
log "Archiving shared storage/app (uploads)"
tar -czf "$SOUT.part" -C "$BASE/shared/storage" app
tar -tzf "$SOUT.part" >/dev/null || die "storage archive unreadable"
mv "$SOUT.part" "$SOUT"; chmod 600 "$SOUT"
log "Storage backup verified: $(basename "$SOUT")"

# Retention: prune only our own, well-named, OLD files; never the newest.
ls -t "$B"/db-*.sql.gz      2>/dev/null | tail -n +$((KEEP_DB_BACKUPS+1))      | while read -r f; do rm -f -- "$f" "$f.sha256"; done
ls -t "$B"/storage-*.tar.gz 2>/dev/null | tail -n +$((KEEP_STORAGE_BACKUPS+1)) | while read -r f; do rm -f -- "$f"; done
echo "BACKUP_DB=$OUT"
