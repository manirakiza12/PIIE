# Shared settings for the on-server scripts. Sourced, never executed.
BASE="${PIIE_BASE:-/home/piie/deployments/piie}"
PHP="${PIIE_PHP:-/usr/bin/php8.3}"           # Ubuntu 24.04 / Contabo production PHP CLI
DOCROOT_LINK="${PIIE_DOCROOT:-$BASE/current/public}"
# Legacy public health URL retained for the old shared-host cutover scripts.
HEALTH_URL="${PIIE_HEALTH_URL:-https://piie.ac.ug/}"

# Production release health check. Always targets this server directly so a
# deployment cannot accidentally pass by receiving HTTP 200 from an old host
# still serving the public DNS name.
APP_HEALTH_URL="${PIIE_APP_HEALTH_URL:-http://127.0.0.1/health}"
APP_HEALTH_HOST="${PIIE_APP_HEALTH_HOST:-piie.ac.ug}"
APP_HEALTH_BODY="${PIIE_APP_HEALTH_BODY:-PIIE-APP-OK}"

KEEP_RELEASES="${PIIE_KEEP_RELEASES:-5}"
KEEP_DB_BACKUPS="${PIIE_KEEP_DB_BACKUPS:-14}"
KEEP_STORAGE_BACKUPS="${PIIE_KEEP_STORAGE_BACKUPS:-3}"

log()  { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# Restore routing after an activated release fails its application health check.
restore_after_failed_activation() {
  local previous="${1:-}"

  if [ -n "$previous" ]; then
    log "HEALTH CHECK FAILED - restoring previous release"
    ln -sfn "$previous" "$BASE/current.new" \
      && mv -Tf "$BASE/current.new" "$BASE/current"
  else
    log "HEALTH CHECK FAILED - no previous release exists; removing failed current symlink"

    if [ -L "$BASE/current" ]; then
      rm -f -- "$BASE/current"
    elif [ -e "$BASE/current" ]; then
      die "refusing to remove unexpected non-symlink at $BASE/current"
    fi
  fi
}

# Probe the active Laravel release through the local Nginx virtual host.
# Success requires both HTTP 200 and the expected application response body.
app_health_check() {
  local response code body

  response=$(curl -sS \
    --max-time 15 \
    -H "Host: $APP_HEALTH_HOST" \
    -w '\n%{http_code}' \
    "$APP_HEALTH_URL" 2>/dev/null) || {
      APP_HEALTH_LAST_CODE="000"
      return 1
    }

  code="${response##*$'\n'}"
  body="${response%$'\n'*}"
  APP_HEALTH_LAST_CODE="$code"

  [ "$code" = "200" ] && [ "$body" = "$APP_HEALTH_BODY" ]
}

# Read one KEY from shared/.env without sourcing it (values may contain shell
# metacharacters) and without ever printing it.
env_get() {
  local v
  v=$(grep -E "^$1=" "$BASE/shared/.env" | tail -1 | cut -d= -f2- || true)
  v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
  printf '%s' "$v"
}
