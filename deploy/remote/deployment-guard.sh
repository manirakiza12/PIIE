#!/usr/bin/env bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
# Fixed paths: no environment-controlled security bypass or trust anchor.
BASE=/home/piie/deployments/piie
PIN=/home/piie/deployments
TRUST=/etc/piie/deployments.identity
security_fail() { echo "DEPLOYMENT REFUSED: $*" >&2; exit 1; }
root_directory() {
  local path=$1 mode
  [ -d "$path" ] && [ ! -L "$path" ] || security_fail "Unsafe directory: $path"
  [ "$(stat -c %u "$path")" = 0 ] || security_fail "Not root-owned: $path"
  mode=$(stat -c %a "$path")
  (( (8#$mode & 0022) == 0 )) || security_fail "Writable by non-root: $path"
}
root_file() {
  local path=$1 mode
  [ -f "$path" ] && [ ! -L "$path" ] || security_fail "Unsafe file: $path"
  [ "$(stat -c '%u:%h' "$path")" = 0:1 ] || security_fail "Unsafe owner/link count: $path"
  mode=$(stat -c %a "$path")
  (( (8#$mode & 0022) == 0 )) || security_fail "Writable by non-root: $path"
}
pin_structure() {
  [ "$(id -u)" = 0 ] || security_fail 'Root execution required'
  root_directory /; root_directory /home
  [ -d /home/piie ] && [ ! -L /home/piie ] || security_fail 'Unsafe home'
  root_directory "$PIN"; root_directory "$BASE"
}
pin_identity() {
  stat -c '%d:%i' /home/piie "$PIN" "$BASE"
}
check_identity() {
  root_directory /etc; root_directory /etc/piie
  root_file "$TRUST"
  [ "$(stat -c %a "$TRUST")" = 600 ] || security_fail 'Trust record must be private'
  [ "$(cat "$TRUST")" = "$(pin_identity)" ] || security_fail 'Deployment inode identity changed'
}
deployment_guard() {
  pin_structure
  # Check the mount before trusting any contents below the mutable home.
  mountpoint -q "$PIN" || security_fail 'Required self-bind mount is absent'
  check_identity
}
verify_runtime_mount() {
  deployment_guard
  local pid
  pid=$(docker inspect --format '{{.State.Pid}}' piie-app) || security_fail 'Cannot inspect app PID'
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || security_fail 'App is not running'
  [ "$(stat -Lc '%d:%i' "$BASE")" = "$(stat -Lc '%d:%i' "/proc/$pid/root/srv/piie")" ] || security_fail 'Container mount inode differs'
}
secure_layout() {
  deployment_guard
  root_directory "$BASE/shared"
  root_directory "$BASE/.secure"
  root_directory "$BASE/.secure/releases"
  root_directory "$BASE/.secure/private"
  [ "$(stat -c %a "$BASE/.secure/private")" = 700 ] || security_fail 'Private directory mode must be 700'
}
deployment_lock() {
  secure_layout
  local lock="$BASE/.secure/deploy.lock"
  if [ ! -e "$lock" ] && [ ! -L "$lock" ]; then
    (umask 077; set -C; : > "$lock") || security_fail 'Cannot create lock'
  fi
  root_file "$lock"
  [ "$(stat -c %a "$lock")" = 600 ] || security_fail 'Unsafe lock mode'
  exec 9<>"$lock"
  flock -n 9 || security_fail 'Another deployment is active'
}
switch_pointer() {
  local target=$1 temporary
  [[ "$target" =~ ^\.secure/releases/[A-Za-z0-9][A-Za-z0-9_-]{0,127}$ ]] || security_fail 'Invalid pointer target'
  secure_layout
  [ -L "$BASE/current" ] || security_fail 'Current must be a symlink'
  temporary=$(mktemp -d "$BASE/.secure/private/pointer.XXXXXXXX")
  ln -s "$target" "$temporary/current"
  mv -Tf -- "$temporary/current" "$BASE/current"
  rmdir "$temporary"
}
