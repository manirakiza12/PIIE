#!/usr/bin/env bash
set -euo pipefail
if command -v docker >/dev/null && docker inspect piie-app >/dev/null 2>&1; then
  exec bash "$(dirname "$0")/container-deploy.sh" "$@"
fi
[ "${PIIE_CODE_ONLY:-0}" != 1 ] || { echo 'Code-only mode requires the Docker runtime'; exit 1; }
exec bash "$(dirname "$0")/deploy-release.sh" "$@"
