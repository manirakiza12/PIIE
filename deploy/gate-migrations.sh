#!/usr/bin/env bash
# A reviewed destructive result may be overridden; scan failures never may.
set -euo pipefail
[ "$#" -eq 1 ] || { echo 'Expected migration base reference' >&2; exit 2; }
case "${ALLOW_DESTRUCTIVE_MIGRATIONS:-0}" in 0|1) ;; *) echo 'Invalid migration review flag' >&2; exit 2;; esac
SCANNER="$(cd "$(dirname "$0")" && pwd)/check-migrations.sh"
if bash "$SCANNER" "$1"; then exit 0; else status=$?; fi
case "$status" in
  1)
    [ "${ALLOW_DESTRUCTIVE_MIGRATIONS:-0}" = 1 ] || { echo 'Destructive operations require explicit review' >&2; exit 1; }
    echo 'Reviewed destructive finding acknowledged'
    ;;
  *) echo 'Migration scan could not establish safety; override refused' >&2; exit 2;;
esac
