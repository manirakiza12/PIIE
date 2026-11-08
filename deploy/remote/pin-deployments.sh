#!/usr/bin/env bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
set -euo pipefail
source "$(dirname "$0")/deployment-guard.sh"
case "${1:-}" in
  enroll)
    # Explicit installation step, never called automatically at boot.
    pin_structure
    root_directory /etc; root_directory /etc/piie
    [ ! -e "$TRUST" ] && [ ! -L "$TRUST" ] || security_fail 'Trust record already exists'
    before=$(pin_identity)
    pid=$(docker inspect --format '{{.State.Pid}}' piie-app)
    [[ "$pid" =~ ^[1-9][0-9]*$ ]] || security_fail 'Invalid app PID'
    [ "$(stat -Lc '%d:%i' "$BASE")" = "$(stat -Lc '%d:%i' "/proc/$pid/root/srv/piie")" ] || security_fail 'Not the live mounted base'
    [ "$before" = "$(pin_identity)" ] || security_fail 'Enrollment path changed'
    (umask 077; set -C; printf '%s\n' "$before" > "$TRUST")
    ;;
  start)
    pin_structure; check_identity
    if ! mountpoint -q "$PIN"; then
      mount --no-canonicalize --bind "$PIN" "$PIN"
    fi
    # No auto-unmount on failure: never expose a live path to another rename.
    deployment_guard
    ;;
  check) deployment_guard ;;
  *) security_fail 'Usage: pin-deployments.sh enroll|start|check' ;;
esac
