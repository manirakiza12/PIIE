#!/usr/bin/env bash
# READ-ONLY checks of the production host. Changes nothing.
set -uo pipefail
. "$(dirname "$0")/lib.sh"
bad=0; ok(){ echo "  ok    $*"; }; no(){ echo "  FAIL  $*"; bad=1; }; warn(){ echo "  warn  $*"; }

echo "PHP"
[ -x "$PHP" ] && ok "$($PHP -v | head -1)" || no "$PHP not executable"
"$PHP" -r 'exit(PHP_VERSION_ID>=80300?0:1);' && ok "PHP >= 8.3" || no "PHP < 8.3"
for e in bcmath ctype curl dom fileinfo gd intl json mbstring openssl pdo_mysql tokenizer xml zip; do
  "$PHP" -m | grep -qix "$e" && ok "ext $e" || no "ext $e missing"
done

echo "Layout"
for d in releases shared shared/storage backups; do [ -d "$BASE/$d" ] && ok "$d" || no "$BASE/$d missing"; done
if [ -f "$BASE/shared/.env" ]; then
  ok "shared/.env present (contents not printed)"
  [ "$(stat -c %a "$BASE/shared/.env")" -le 640 ] && ok ".env perms" || warn ".env perms should be 600/640"
  for k in APP_KEY DB_HOST DB_DATABASE DB_USERNAME; do [ -n "$(env_get $k)" ] && ok "env $k set" || no "env $k empty"; done
  [ "$(env_get APP_ENV)" = production ] && ok "APP_ENV=production" || warn "APP_ENV is not production"
  [ "$(env_get APP_DEBUG)" = false ] && ok "APP_DEBUG=false" || no "APP_DEBUG must be false"
else
  no "shared/.env missing - create once by hand"
fi

echo "Tools"
for t in mysqldump gzip tar curl flock sha256sum; do command -v $t >/dev/null && ok "$t" || no "$t missing"; done
FREE=$(df -Pk "$BASE" | awk 'NR==2{print int($4/1024)}')
[ "${FREE:-0}" -ge 2048 ] && ok "free space ${FREE}MB" || no "less than 2GB free (${FREE}MB)"

echo "Backups"
LAST=$(ls -t "$BASE"/backups/db-*.sql.gz 2>/dev/null | head -1 || true)
if [ -n "$LAST" ] && gzip -t "$LAST"; then ok "latest DB backup readable: $(basename "$LAST")"; else warn "no verified DB backup yet (a deploy takes one)"; fi

echo "Document root"
if grep -q PIIE-RELEASE-SHELL "$DOCROOT_LINK/index.php" 2>/dev/null; then
  ok "public_html serves the release shell"
elif [ -d "$DOCROOT_LINK" ] && [ ! -L "$DOCROOT_LINK" ]; then
  warn "public_html is the legacy flat app root (expected before cutover): deploys can be STAGED only; run docroot.sh plan/apply (docs/DEPLOYMENT.md)"
else
  no "public_html must be a real directory"
fi
if [ -L "$BASE/current" ]; then ok "current -> $(readlink "$BASE/current")"; else warn "no current release yet"; fi
exit $bad
