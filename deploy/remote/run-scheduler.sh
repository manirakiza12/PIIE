#!/usr/bin/env bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
set -euo pipefail
source "$(dirname "$0")/deployment-guard.sh"
verify_runtime_mount
secure_layout
root_file "$BASE/.secure/deploy.lock"
exec 9<>"$BASE/.secure/deploy.lock"
# Skip this minute during deployment; never overlap migrations/pointer changes.
flock -sn 9 || exit 0
[[ "$(readlink "$BASE/current")" =~ ^\.secure/releases/[A-Za-z0-9][A-Za-z0-9_-]{0,127}$ ]] || security_fail 'Scheduler requires protected current'
source "$(dirname "$0")/container-app.sh"
app_run /srv/piie/current php artisan schedule:run
