#!/usr/bin/env bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
set -euo pipefail
fail() { security_fail "$*"; }
source "$(dirname "$0")/secure-handoff.sh"
secure_layout
root_file "$BASE/.secure/deploy.lock"
exec 9<>"$BASE/.secure/deploy.lock"
flock -sn 9 || security_fail 'Deployment is active'
[[ "$(readlink "$BASE/current")" =~ ^\.secure/releases/[A-Za-z0-9][A-Za-z0-9_-]{0,127}$ ]] || security_fail 'Runtime startup requires protected current'
CURRENT_ID=$(basename "$(readlink "$BASE/current")")
CURRENT_SHA=$(cat "$BASE/current/RELEASE_SHA")
verify_release "$CURRENT_ID" "$CURRENT_SHA"
# Docker auto-restart would bypass the pin prerequisite. This policy update must
# be separately approved at installation; it preserves the existing container.
[ "$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' piie-app)" = no ] || security_fail 'PIIE auto-restart must be delegated to its guarded unit'
docker inspect -f '{{range .Mounts}}{{.Source}}:{{.Destination}}{{println}}{{end}}' piie-app | grep -Fx "$BASE:/srv/piie" >/dev/null || security_fail 'Configured bind source differs'
case "${1:-check}" in
  check) verify_runtime_mount ;;
  run)
    if [ "$(docker inspect --format '{{.State.Running}}' piie-app)" != true ]; then
      docker start piie-app >/dev/null
    fi
    verify_runtime_mount
    # Restart only this supervisor/container after a container exit. Never start,
    # stop or restart the shared Docker daemon or any unrelated container.
    flock -u 9; exec 9>&-
    docker wait piie-app
    exit 1
    ;;
  *) security_fail 'Usage: runtime-supervisor.sh check|run' ;;
esac
