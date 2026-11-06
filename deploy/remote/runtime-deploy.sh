#!/usr/bin/env bash
set -euo pipefail
# This verified production is Docker-based. Permission/daemon errors are not
# evidence of a legacy runtime and must never redirect execution to host PHP.
command -v docker >/dev/null || { echo 'Expected Docker runtime unavailable' >&2; exit 1; }
docker inspect piie-app >/dev/null 2>&1 || { echo 'Cannot inspect expected PIIE runtime; legacy fallback refused' >&2; exit 1; }
exec bash "$(dirname "$0")/container-deploy.sh" "$@"
