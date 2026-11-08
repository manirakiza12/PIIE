#!/usr/bin/env bash
# CI-REQUIRES-ROOT: real bind mounts, namespaces and ownership operations.
# Layout proof only: real ownership, bind mounts and attacker operations.
# No Docker, production paths, database, network or application commands.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo 'Root required' >&2; exit 1; }
if [ "${1:-}" != --private-namespace ]; then
  exec unshare --mount --propagation private -- bash "$0" --private-namespace
fi
S=$(mktemp -d /var/lib/piie-mount-proof.XXXXXXXX)
cleanup() {
  local status=$?
  exec 9>&-
  mountpoint -q "$S/home/piie/deployments.original" && umount "$S/home/piie/deployments.original"
  mountpoint -q "$S/pinned" && umount "$S/pinned"
  mountpoint -q "$S/container/srv/piie" && umount "$S/container/srv/piie"
  case "$S" in /var/lib/piie-mount-proof.*) rm -rf -- "$S";; esac
  return "$status"
}
trap cleanup EXIT
chmod 755 "$S"
HOME_PATH=$S/home/piie
LEGACY=$HOME_PATH/deployments/piie
PIN=$S/pinned
VIEW=$S/container/srv/piie
RID=20261107-162151-4684b28255b9
mkdir -p "$LEGACY"/{incoming,releases/f23cff8/public,shared/public-uploads,shared/storage/logs} "$PIN" "$VIEW"
chown nobody:nogroup "$HOME_PATH"
chmod 750 "$HOME_PATH"
chmod 755 "$HOME_PATH/deployments" "$LEGACY"
chown nobody:root "$LEGACY/releases"
chown nobody:nogroup "$LEGACY/incoming"; chmod 750 "$LEGACY/incoming"
chown root:www-data "$LEGACY/shared"; chmod 750 "$LEGACY/shared"
printf 'unchanged-secret\n' > "$LEGACY/shared/.env"
chown root:www-data "$LEGACY/shared/.env"; chmod 640 "$LEGACY/shared/.env"
printf old > "$LEGACY/releases/f23cff8/public/index.php"
ln -s releases/f23cff8 "$LEGACY/current"
# Existing Docker-like view is established before the additional host alias.
mount --bind "$LEGACY" "$VIEW"
mount --bind "$LEGACY" "$PIN"
[ "$(stat -c '%d:%i' "$PIN")" = "$(stat -c '%d:%i' "$VIEW")" ]
BEFORE=$(stat -c '%u:%g:%a' "$PIN/shared/.env")
ENV_SUM=$(sha256sum "$PIN/shared/.env" | cut -d' ' -f1)
install -d -m 755 "$PIN/.secure" "$PIN/.secure/releases"
install -d -m 700 "$PIN/.secure/private"
# Real unprivileged CI staging is retained at the old staging paths.
runuser -u nobody -- mkdir "$LEGACY/releases/ci-before"
runuser -u nobody -- sh -c 'printf approved > "$1/incoming/piie-release-fixture.tar.gz"' sh "$LEGACY"
SUM=$(sha256sum "$PIN/incoming/piie-release-fixture.tar.gz" | cut -d' ' -f1)
SNAP=$(mktemp -d "$PIN/.secure/private/artifact.XXXXXXXX")
cp "$PIN/incoming/piie-release-fixture.tar.gz" "$SNAP/artifact"
printf '%s  %s\n' "$SUM" "$SNAP/artifact" | sha256sum -c -
# Root ownership below a piie-owned home does NOT prevent ancestor replacement.
runuser -u nobody -- mv "$HOME_PATH/deployments" "$HOME_PATH/deployments.original"
runuser -u nobody -- mkdir -p "$LEGACY/.secure" "$LEGACY/shared"
runuser -u nobody -- sh -c 'printf decoy > "$1/.secure/marker"' sh "$LEGACY"
[ "$(cat "$LEGACY/.secure/marker")" = decoy ]
[ ! -e "$PIN/.secure/marker" ] && [ ! -e "$VIEW/.secure/marker" ]
echo 'PASS: ancestor rename succeeds; ordinary host path is redirected despite root-owned descendants'
[ "$(stat -c '%d:%i' "$PIN")" = "$(stat -c '%d:%i' "$VIEW")" ]
[ "$(stat -c '%d:%i' "$LEGACY")" != "$(stat -c '%d:%i' "$VIEW")" ]
echo 'PASS: root bind alias stays on the existing container-mounted inode after ancestor replacement'
# No privileged operations below this point use the attacker-controlled legacy path.
runuser -u nobody -- sh -c 'rm "$1/incoming/piie-release-fixture.tar.gz"; printf replaced > "$1/incoming/piie-release-fixture.tar.gz"' sh "$PIN"
printf '%s  %s\n' "$SUM" "$SNAP/artifact" | sha256sum -c -
if runuser -u nobody -- cat "$SNAP/artifact" >/dev/null 2>&1; then exit 1; fi
echo 'PASS: verified private artifact survives incoming replacement and is inaccessible to staging user'
mkdir -p "$PIN/.secure/releases/$RID/public" "$PIN/.secure/releases/$RID/bootstrap/cache"
printf verified-code > "$PIN/.secure/releases/$RID/public/index.php"
chmod 755 "$PIN/.secure/releases/$RID" "$PIN/.secure/releases/$RID/public"
chmod 644 "$PIN/.secure/releases/$RID/public/index.php"
chown root:www-data "$PIN/.secure/releases/$RID/bootstrap/cache"
chmod 2775 "$PIN/.secure/releases/$RID/bootstrap/cache"
if runuser -u nobody -- mv "$PIN/.secure/releases/$RID" "$PIN/.secure/releases/replaced" 2>/dev/null; then exit 1; fi
if runuser -u nobody -- sh -c 'printf bad > "$1/public/index.php"' sh "$PIN/.secure/releases/$RID" 2>/dev/null; then exit 1; fi
if runuser -u www-data -- sh -c 'printf bad > "$1/public/index.php"' sh "$PIN/.secure/releases/$RID" 2>/dev/null; then exit 1; fi
runuser -u www-data -- touch "$PIN/.secure/releases/$RID/bootstrap/cache/runtime"
echo 'PASS: protected runtime code cannot be replaced or edited; Laravel cache remains writable'
exec 9>"$PIN/.deploy.lock"; chmod 600 "$PIN/.deploy.lock"; flock -n 9
if flock -n "$PIN/.deploy.lock" true; then exit 1; fi
if runuser -u nobody -- sh -c 'rm "$1/.deploy.lock"' sh "$PIN" 2>/dev/null; then exit 1; fi
if runuser -u nobody -- sh -c 'rm "$1/current"' sh "$PIN" 2>/dev/null; then exit 1; fi
echo 'PASS: pinned deployment lock excludes concurrent writers; staging user cannot replace lock or active pointer'
ln -s ".secure/releases/$RID" "$PIN/current.new"
mv -Tf "$PIN/current.new" "$PIN/current"
[ "$(cat "$VIEW/current/public/index.php")" = verified-code ]
runuser -u www-data -- test -r "$VIEW/current/public/index.php"
echo 'PASS: unchanged container /srv/piie/current/public resolves protected candidate code'
# Simulate scheduler data writes through the stable host alias, never through HOME.
chown root:www-data "$PIN/shared/storage/logs" "$PIN/shared/public-uploads"
chmod 2775 "$PIN/shared/storage/logs" "$PIN/shared/public-uploads"
runuser -u www-data -- sh -c 'printf scheduler > "$1/shared/storage/logs/scheduler.log"; printf upload > "$1/shared/public-uploads/preserved"' sh "$PIN"
[ "$(cat "$VIEW/shared/public-uploads/preserved")" = upload ]
[ "$(stat -c '%u:%g:%a' "$PIN/shared/.env")" = "$BEFORE" ]
[ "$(sha256sum "$PIN/shared/.env" | cut -d' ' -f1)" = "$ENV_SUM" ]
echo 'PASS: scheduler and uploads remain visible; .env owner, group, mode and content stay unchanged'
ln -s releases/f23cff8 "$PIN/current.rollback"
mv -Tf "$PIN/current.rollback" "$PIN/current"
[ "$(cat "$VIEW/current/public/index.php")" = old ]
[ "$(cat "$VIEW/.secure/releases/$RID/public/index.php")" = verified-code ]
echo 'PASS: original release retained and pointer rollback resolves through original Docker-like mount'
# Staging continues on the original tree even after attempted path redirection.
runuser -u nobody -- mkdir "$HOME_PATH/deployments.original/piie/releases/ci-after"
runuser -u nobody -- touch "$HOME_PATH/deployments.original/piie/incoming/ci-after"
[ -d "$PIN/releases/ci-before" ] && [ -d "$PIN/releases/ci-after" ]
echo 'PASS: incoming/releases ownership is unchanged and unprivileged staging still succeeds'

# Minimal alternative: pin the one root-owned child of the user-owned home.
# The test leaves its fixture name unchanged; no ownership changes are needed.
mount --bind "$HOME_PATH/deployments.original" "$HOME_PATH/deployments.original"
if runuser -u nobody -- mv "$HOME_PATH/deployments.original" "$HOME_PATH/renamed-again" 2>/dev/null; then exit 1; fi
[ "$(stat -c '%d:%i' "$HOME_PATH/deployments.original/piie")" = "$(stat -c '%d:%i' "$VIEW")" ]
runuser -u nobody -- mkdir "$HOME_PATH/deployments.original/piie/releases/ci-pinned"
[ -d "$VIEW/releases/ci-pinned" ]
echo 'PASS: self-bind on root-owned deployments blocks its rename without changing home ownership or CI staging'
