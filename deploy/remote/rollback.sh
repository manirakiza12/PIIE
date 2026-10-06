#!/usr/bin/env bash
# Point `current` back at a previous release (code only). Usage: rollback.sh [release-id]
set -euo pipefail
. "$(dirname "$0")/lib.sh"
exec 9>"$BASE/.deploy.lock"; flock -n 9 || die "a deployment is running"
[ -L "$BASE/current" ] || die "no current release; nothing to roll back from"
CUR="$(basename "$(readlink -f "$BASE/current")")"
TARGET="${1:-$(ls -1t "$BASE/releases" | grep -vx "$CUR" | head -1)}"
[ -n "$TARGET" ] && [ -d "$BASE/releases/$TARGET" ] || die "no such release"
ln -sfn "$BASE/releases/$TARGET" "$BASE/current.new" && mv -Tf "$BASE/current.new" "$BASE/current"
"$PHP" "$BASE/current/artisan" config:cache >/dev/null
log "current -> $TARGET (was $CUR). Database untouched."
if app_health_check; then
  log "application health: HTTP $APP_HEALTH_LAST_CODE, expected body confirmed"
else
  log "ROLLBACK HEALTH CHECK FAILED: HTTP ${APP_HEALTH_LAST_CODE:-000}, restoring $CUR"

  ln -sfn "$BASE/releases/$CUR" "$BASE/current.new" \
    && mv -Tf "$BASE/current.new" "$BASE/current"

  "$PHP" "$BASE/current/artisan" config:cache >/dev/null

  log "Rollback attempt reverted; current -> $CUR. Database untouched."
  exit 1
fi
