#!/usr/bin/env bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
# Explicit code-only Docker rollback. Never auto-selects a release or undoes SQL.
# Must run through the separately approved administrator boundary, never broad sudo.
set -euo pipefail
BASE=/home/piie/deployments/piie
fail() { echo "ROLLBACK REFUSED: $*" >&2; exit 1; }
[ "$#" -eq 3 ] || fail 'expected current SHA, target release ID and target SHA'
EXPECTED=$1 TARGET=$2 TARGET_SHA=$3
[[ "$EXPECTED" =~ ^[0-9a-f]{40}$ && "$TARGET_SHA" =~ ^[0-9a-f]{40}$ ]] || fail 'full verified SHAs required'
[[ "$TARGET" =~ ^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$ ]] || fail 'invalid target release ID'
[ "$(id -u)" = 0 ] || fail 'approved administrator execution required'
source "$(dirname "$0")/container-app.sh"
source "$(dirname "$0")/secure-handoff.sh"
verify_runtime_mount
deployment_lock
[ -L "$BASE/current" ] || fail 'current is not a release link'
PREV=$(readlink "$BASE/current")
[[ "$PREV" =~ ^\.secure/releases/[A-Za-z0-9][A-Za-z0-9_-]{0,127}$ ]] || fail 'Active pointer is not protected'
[ "$(cat "$BASE/current/RELEASE_SHA")" = "$EXPECTED" ] || fail 'active release changed'
verify_release "${PREV##*/}" "$EXPECTED"
verify_release "$TARGET" "$TARGET_SHA"
[ -s "$BASE/.secure/releases/$TARGET/vendor/autoload.php" ] || fail 'target dependencies missing'
[ "$PREV" != ".secure/releases/$TARGET" ] || fail 'target already active'
[ "$(readlink "$BASE/.secure/releases/$TARGET/public/assets/uploads")" = ../../../../../shared/public-uploads ] || fail 'Target uploads are not durable'
app_apache_master || fail 'application init is not verified Apache'
SWITCHED=0
recover() {
  local status=$?
  if [ "$status" -ne 0 ] && [ "$SWITCHED" = 1 ]; then
    if ! switch_pointer "$PREV" || ! app_apache_reload; then
      echo 'CRITICAL: failed rollback could not restore its starting runtime' >&2
      return 1
    fi
    echo 'Failed rollback target reverted to starting release; database untouched' >&2
  fi
  return "$status"
}
trap recover EXIT
switch_pointer ".secure/releases/$TARGET"
SWITCHED=1
app_apache_reload
BODY=$(app_run /srv/piie/current curl --fail --silent --show-error --max-time 15 -H 'Host: piie.ac.ug' http://127.0.0.1/health)
[ "$BODY" = PIIE-APP-OK ] || fail 'restored release health body mismatch'
echo 'Code rollback verified; forward migrations and shared data retained'
