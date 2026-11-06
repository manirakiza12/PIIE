#!/usr/bin/env bash
# Read an archive marker without extracting files to disk or booting the app.
set -euo pipefail
[ "$#" -eq 2 ] || { echo 'Expected archive and full approved SHA' >&2; exit 2; }
ARCHIVE=$1 EXPECTED=$2
[[ "$EXPECTED" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid approved SHA' >&2; exit 2; }
MEMBERS=$(tar -tzf "$ARCHIVE" | awk '$0 == "RELEASE_SHA" || $0 == "./RELEASE_SHA" {print}')
[ "$(printf '%s\n' "$MEMBERS" | grep -c .)" -eq 1 ] || { echo 'Release must contain exactly one identity marker' >&2; exit 1; }
TYPE=$(tar -tvzf "$ARCHIVE" "$MEMBERS" | cut -c1)
[ "$TYPE" = '-' ] || { echo 'Identity marker must be a regular file' >&2; exit 1; }
ACTUAL=$(tar -xOf "$ARCHIVE" "$MEMBERS")
[ "$ACTUAL" = "$EXPECTED" ] || { echo 'Artifact identity differs from approved commit' >&2; exit 1; }
echo 'Release identity matches full approved commit'
