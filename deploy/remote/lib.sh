# Shared settings for the on-server scripts. Sourced, never executed.
BASE="${PIIE_BASE:-/home/piie/deployments/piie}"
PHP="${PIIE_PHP:-/usr/local/php83/bin/php}"   # bare `php` is 8.2.27 on this host
DOCROOT_LINK="${PIIE_DOCROOT:-/home/piie/domains/piie.ac.ug/public_html}"
HEALTH_URL="${PIIE_HEALTH_URL:-https://piie.ac.ug/}"
KEEP_RELEASES="${PIIE_KEEP_RELEASES:-5}"
KEEP_DB_BACKUPS="${PIIE_KEEP_DB_BACKUPS:-14}"
KEEP_STORAGE_BACKUPS="${PIIE_KEEP_STORAGE_BACKUPS:-3}"

log()  { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

# Read one KEY from shared/.env without sourcing it (values may contain shell
# metacharacters) and without ever printing it.
env_get() {
  local v
  v=$(grep -E "^$1=" "$BASE/shared/.env" | tail -1 | cut -d= -f2- || true)
  v="${v%\"}"; v="${v#\"}"; v="${v%\'}"; v="${v#\'}"
  printf '%s' "$v"
}
