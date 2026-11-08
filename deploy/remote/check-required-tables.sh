#!/usr/bin/env bash
# Read-only preflight: verify required application tables exist in the target DB.
# Runs OUTSIDE migrate --pretend, so Schema::hasTable() works correctly.
# Usage: check-required-tables.sh [release-workdir]  (defaults to current)
set -euo pipefail
WORKDIR=${1:-/srv/piie/current}
[[ "$WORKDIR" = /srv/piie/current || "$WORKDIR" =~ ^/srv/piie/\.secure/releases/[A-Za-z0-9][A-Za-z0-9_-]{0,127}$ ]] || { echo 'Invalid application working directory' >&2; exit 1; }
BASE=/home/piie/deployments/piie
source "$(dirname "$0")/container-app.sh"
app_run "$WORKDIR" php artisan db:check-required-tables
