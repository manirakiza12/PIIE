#!/usr/bin/env bash
# Read-only preflight: verify required application tables exist in the target DB.
# Runs OUTSIDE migrate --pretend, so Schema::hasTable() works correctly.
# Usage: check-required-tables.sh [release-workdir]  (defaults to current)
set -euo pipefail
WORKDIR=${1:-/srv/piie/current}
case "$WORKDIR" in /srv/piie/current|/srv/piie/releases/*) ;; *) echo 'Invalid application working directory' >&2; exit 1;; esac
BASE=/home/piie/deployments/piie
source "$(dirname "$0")/container-app.sh"
app_run "$WORKDIR" php artisan db:check-required-tables
