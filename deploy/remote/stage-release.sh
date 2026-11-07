#!/usr/bin/env bash
# Unprivileged release staging. Prepares a release directory and links shared
# environment/storage without touching Docker, the live symlink, or the database.
# Usage: stage-release.sh <RID> <ART> <SUM>
set -euo pipefail
BASE=/home/piie/deployments/piie
RID=${1:?release id}; ART=${2:?archive}; SUM=${3:?checksum}
fail() { echo "ERROR: $*" >&2; exit 1; }
[[ "$RID" =~ ^[0-9]{8}-[0-9]{6}-[0-9a-f]{12}$ ]] || fail 'Invalid release id'
[[ "$SUM" =~ ^[0-9a-f]{64}$ ]] || fail 'Invalid checksum'
case "$ART" in "$BASE"/incoming/piie-release-*.tar.gz) ;; *) fail 'Archive outside incoming';; esac
[[ "${EXPECTED_RELEASE_SHA:-}" =~ ^[0-9a-f]{40}$ ]] || fail 'Full approved candidate SHA required'
printf '%s  %s\n' "$SUM" "$ART" | sha256sum -c -
# Refuse traversal, links, devices and secret files before extracting.
tar -tzf "$ART" | awk '/^\// || /(^|\/)\.\.($|\/)/ || /(^|\/)\.env$/ {bad=1} END {exit bad}' || fail 'Unsafe archive paths'
tar -tvzf "$ART" | awk 'substr($0,1,1)!="-" && substr($0,1,1)!="d" {bad=1} END {exit bad}' || fail 'Archive links or special files refused'
REL="$BASE/releases/$RID"
[ ! -e "$REL" ] || fail 'Release already exists'
mkdir "$REL"
tar -xzf "$ART" -C "$REL" --no-same-owner
chmod 755 "$REL"
SHA=$(cat "$REL/RELEASE_SHA")
[[ "$SHA" =~ ^[0-9a-f]{40}$ ]] && [ "${SHA:0:12}" = "${RID##*-}" ] || fail 'Release SHA does not match ID'
[ "$SHA" = "$EXPECTED_RELEASE_SHA" ] || fail 'Release differs from approved full SHA'
[ -f "$REL/vendor/autoload.php" ] || fail 'Production dependencies missing'
ln -s ../../shared/.env "$REL/.env"
if [ -d "$REL/storage" ]; then mv "$REL/storage" "$REL/storage-baseline"; fi
ln -s ../../shared/storage "$REL/storage"
mkdir -p "$REL/bootstrap/cache"
chown "$(id -u):$(id -g)" "$REL/bootstrap/cache"; chmod 2775 "$REL/bootstrap/cache"
if [ -e "$REL/public/storage" ]; then fail 'Unexpected packaged public/storage'; fi
ln -s ../../../shared/storage/app/public "$REL/public/storage"
echo "STAGED $RID: live release, uploads and database unchanged (unprivileged)"
echo "Staged SHA: $SHA"
