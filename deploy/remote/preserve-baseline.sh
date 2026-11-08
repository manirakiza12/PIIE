#!/usr/bin/env bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
# Approved original CI archive is required; a RELEASE_SHA marker is not attestation.
set -euo pipefail
fail() { echo "BASELINE REFUSED: $*" >&2; exit 1; }
source "$(dirname "$0")/secure-handoff.sh"
[ "$#" = 3 ] || fail 'Expected archive, approved checksum, approved full baseline SHA'
verify_runtime_mount
deployment_lock
[ "$(readlink "$BASE/current")" = releases/f23cff8 ] || fail 'Legacy baseline is not current'
[ "$(cat "$BASE/current/RELEASE_SHA")" = "$3" ] || fail 'Live baseline identity differs'
ENV_BEFORE=$(stat -c '%u:%g:%a' "$BASE/shared/.env")
ENV_HASH=$(sha256sum "$BASE/shared/.env")
prepare_release f23cff8 "$1" "$2" "$3"
python3 -I "$(dirname "$0")/release-archive.py" compare "$BASE/releases/f23cff8" "$REL" || {
  fail 'Baseline comparison failed; protected copy is not approved'
}
[ "$(stat -c '%u:%g:%a' "$BASE/shared/.env")" = "$ENV_BEFORE" ] && [ "$(sha256sum "$BASE/shared/.env")" = "$ENV_HASH" ] || fail 'Environment changed'
mv -T -- "$SNAPSHOT/baseline.approved" "$BASE/.secure/private/f23cff8.approved"
echo 'Trusted rollback baseline preserved; current, database, storage and uploads unchanged'
