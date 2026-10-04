#!/usr/bin/env bash
# Scan migrations added since a base ref for destructive operations. Exit 1 if
# any are found, so a human must review them before production.
# Usage: deploy/check-migrations.sh [base-ref]   (default: origin/main)
set -euo pipefail
BASE="${1:-origin/main}"
git rev-parse --verify -q "$BASE" >/dev/null || { echo "base ref $BASE not found"; exit 2; }
FILES=$(git diff --name-only --diff-filter=AM "$BASE"...HEAD -- database/migrations || true)
[ -z "$FILES" ] && { echo "No new migrations since $BASE."; exit 0; }
PATTERN='dropColumn|dropIfExists|Schema::drop|->drop\(|dropForeign|dropIndex|dropUnique|truncate\(|DROP +(TABLE|COLUMN|DATABASE)|TRUNCATE|DELETE +FROM|DB::table\([^)]*\)->(delete|truncate)|->renameColumn|rename\('
status=0
for f in $FILES; do
  # down() legitimately drops what up() created; only inspect up().
  UP=$(awk '/function up\(/{p=1} /function down\(/{p=0} p' "$f")
  if echo "$UP" | grep -nE "$PATTERN" >/dev/null; then
    echo "DESTRUCTIVE? $f"; echo "$UP" | grep -nE "$PATTERN" | head -5; status=1
  else
    echo "ok          $f"
  fi
done
[ $status = 0 ] || echo "Review the flagged migrations; deployment requires ALLOW_DESTRUCTIVE_MIGRATIONS=1 after review."
exit $status
