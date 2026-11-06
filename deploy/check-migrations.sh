#!/usr/bin/env bash
# Scan migrations added since a base ref for destructive operations. Exit 1 if
# any are found, so a human must review them before production. Exit 2 means
# the scan could not be performed at all (missing ref, parse error) and must
# never be treated as "clean".
#
# The actual matching lives in deploy/scan-migrations.php. It tokenises the
# migration, so both of these are detected:
#   * DB::table('payroll')->where(...)->delete();      (single line)
#   * DB::table('payroll')                             (delete on a later line)
#         ->where(...)
#         ->delete();
#   * DB::statement('DELETE FROM payroll WHERE ...');  (raw SQL string)
#
# Usage: deploy/check-migrations.sh [base-ref]   (default: origin/main)
set -euo pipefail

BASE="${1:-origin/main}"
git rev-parse --verify -q "$BASE" >/dev/null || { echo "base ref $BASE not found"; exit 2; }
git merge-base "$BASE" HEAD >/dev/null || { echo "no valid merge base between $BASE and HEAD"; exit 2; }
FILES=$(git diff --name-only --diff-filter=AM "$BASE"...HEAD -- database/migrations) || {
  echo "migration comparison failed for $BASE...HEAD"
  exit 2
}
[ -z "$FILES" ] && { echo "No new migrations since $BASE."; exit 0; }

PHP_BIN="${PHP_BIN:-php}"
command -v "$PHP_BIN" >/dev/null 2>&1 || { echo "php not found; set PHP_BIN to a PHP 8.1+ binary"; exit 2; }

SCANNER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/scan-migrations.php"
[ -f "$SCANNER" ] || { echo "scanner not found: $SCANNER"; exit 2; }

mapfile -t MIGRATIONS <<< "$FILES"

set +e
"$PHP_BIN" "$SCANNER" "${MIGRATIONS[@]}"
STATUS=$?
set -e

case "$STATUS" in
  0) ;;
  1) echo "Review the flagged migrations; deployment requires ALLOW_DESTRUCTIVE_MIGRATIONS=1 after review." ;;
  2) echo "Migration scan failed; do not deploy until the error above is resolved." ;;
  *) echo "Migration scan returned unexpected status $STATUS."; STATUS=2 ;;
esac

exit "$STATUS"
