#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/deployment-guard.sh"
# Fixed protected parents, independent of unprivileged incoming/releases staging.
protected_directory() { root_directory "$1"; }
secure_handoff() {
  secure_layout
  [[ "$ART" = "$BASE/incoming/"* ]] || fail 'Archive outside incoming'
  local name=${ART#"$BASE/incoming/"}
  [[ "$name" =~ ^piie-release-[A-Za-z0-9._-]+\.tar\.gz$ ]] || fail 'Invalid archive filename'
  [ ! -L "$BASE/incoming" ] && [ -d "$BASE/incoming" ] || fail 'Invalid incoming directory'
  [ -f "$ART" ] && [ ! -L "$ART" ] || fail 'Archive must be a regular file'
  [ -f "$BASE/shared/.env" ] && [ ! -L "$BASE/shared/.env" ] || fail 'Invalid environment file'
  SNAPSHOT=$(mktemp -d "$BASE/.secure/private/artifact.XXXXXXXX")
  python3 -I "$(dirname "${BASH_SOURCE[0]}")/release-archive.py" snapshot "$ART" "$SNAPSHOT/archive.tar.gz" || fail 'Cannot copy archive safely'
  ART="$SNAPSHOT/archive.tar.gz"
  printf '%s  %s\n' "$SUM" "$ART" | sha256sum -c - || fail 'Snapshot checksum mismatch'
  REL="$BASE/.secure/releases/$RID"
}
prepare_release() {
  local id=$1 archive=$2 checksum=$3 sha=$4
  [[ "$sha" =~ ^[0-9a-f]{40}$ && "$checksum" =~ ^[0-9a-f]{64}$ ]] || fail 'Approved full SHA and checksum required'
  if [ "$id" = f23cff8 ]; then
    [ "${sha:0:7}" = "$id" ] || fail 'Baseline SHA does not match ID'
  else
    [[ "$id" =~ ^[0-9]{8}-[0-9]{6}-[0-9a-f]{12}$ ]] && [ "${sha:0:12}" = "${id##*-}" ] || fail 'Release SHA does not match ID'
  fi
  RID=$id ART=$archive SUM=$checksum
  secure_handoff
  if [ -e "$REL" ] || [ -L "$REL" ]; then
    [ "$(readlink "$BASE/current")" != ".secure/releases/$id" ] || fail 'Cannot replace current code'
    verify_release "$id" "$sha"
    [ "$(sed -n '2p' "$BASE/.secure/private/$id.approved")" = "$checksum" ] || fail 'Existing protected artifact differs'
    # Root stage -> activate of the same RID rebuilds from the new verified copy.
    # Retained cache writer FDs can affect only this private, quarantined old tree.
    mv -T -- "$REL" "$SNAPSHOT/previous-release"
    mv -T -- "$BASE/.secure/private/$id.approved" "$SNAPSHOT/previous.approved"
  fi
  python3 -I "$(dirname "${BASH_SOURCE[0]}")/release-archive.py" extract "$ART" "$REL" "$sha" || fail 'Archive extraction refused'
  [ -f "$REL/vendor/autoload.php" ] || fail 'Production dependencies missing'
  ln -s ../../../shared/.env "$REL/.env"
  if [ -d "$REL/storage" ]; then mv "$REL/storage" "$REL/storage-baseline"; fi
  ln -s ../../../shared/storage "$REL/storage"
  mkdir -p "$REL/bootstrap/cache" "$REL/public/assets/uploads"
  find "$REL/bootstrap/cache" -print0 | xargs -0 -r chown root:www-data -- || fail 'Cannot set cache ownership'
  find "$REL/bootstrap/cache" -type d -print0 | xargs -0 -r chmod 2775 -- || fail 'Cannot set cache mode'
  find "$REL/bootstrap/cache" -type f -print0 | xargs -0 -r chmod 664 -- || fail 'Cannot set cache mode'
  ln -s ../../../../shared/storage/app/public "$REL/public/storage"
  local approval="$BASE/.secure/private/$id.approved"
  if [ "$id" = f23cff8 ]; then approval="$SNAPSHOT/baseline.approved"; fi
  (umask 077; set -C; printf '%s\n%s\n' "$sha" "$checksum" > "$approval")
}
verify_release() {
  local id=$1 sha=$2 marker="$BASE/.secure/private/$1.approved"
  [[ "$id" =~ ^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$ ]] || fail 'Invalid protected release ID'
  secure_layout
  root_file "$marker"
  root_directory "$BASE/.secure/releases/$id"
  [ "$(head -n 1 "$marker")" = "$sha" ] || fail 'Release is not independently approved'
  [ "$(cat "$BASE/.secure/releases/$id/RELEASE_SHA")" = "$sha" ] || fail 'Protected release identity changed'
}
link_uploads() {
  local release=$1
  if [ -L "$release/public/assets/uploads" ]; then
    [ "$(readlink "$release/public/assets/uploads")" = ../../../../../shared/public-uploads ] || fail 'Invalid protected upload link'
  else
    mv "$release/public/assets/uploads" "$release/public/assets/uploads-baseline"
    ln -s ../../../../../shared/public-uploads "$release/public/assets/uploads"
  fi
}
validate_uploads() {
  local path=$1 entries
  [ -d "$path" ] && [ ! -L "$path" ] || fail 'Invalid upload directory'
  entries=$(find "$path" \( -type l -o -type f -links +1 -o ! -type f ! -type d \) -print -quit) || fail 'Cannot inspect uploads'
  [ -z "$entries" ] || fail 'Upload links or special files refused'
}
# No-follow descriptors prevent a concurrent upload symlink swap from targeting .env.
set_upload_permissions() {
  local path=$1
  [ "$path" = "$BASE/shared/public-uploads" ] || fail 'Invalid permission target'
  validate_uploads "$path"
  python3 -I "$(dirname "${BASH_SOURCE[0]}")/repair-uploads.py" "$path" || fail 'Cannot set upload permissions'
}
