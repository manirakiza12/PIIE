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
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 000); log "health: HTTP $code"
