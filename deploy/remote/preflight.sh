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
# mktemp is required by docroot.sh, which lists document-root entries through a
# temporary file rather than process substitution.
for t in mysqldump gzip tar curl flock sha256sum mktemp; do command -v $t >/dev/null && ok "$t" || no "$t missing"; done
FREE=$(df -Pk "$BASE" | awk 'NR==2{print int($4/1024)}')
[ "${FREE:-0}" -ge 2048 ] && ok "free space ${FREE}MB" || no "less than 2GB free (${FREE}MB)"

echo "Backups"
LAST=$(ls -t "$BASE"/backups/db-*.sql.gz 2>/dev/null | head -1 || true)
if [ -n "$LAST" ] && gzip -t "$LAST"; then ok "latest DB backup readable: $(basename "$LAST")"; else warn "no verified DB backup yet (a deploy takes one)"; fi

echo "Release routing"
if [ -L "$BASE/current" ]; then
  ok "current -> $(readlink "$BASE/current")"
  [ -d "$BASE/current/public" ] && ok "current/public exists" || no "current/public missing"
else
  warn "no current release yet (expected before first activation)"
fi

# Database dumps and other secrets inside the document root are served verbatim.
#
# This is a live problem, not a hypothetical one: `public/assets/install.sql` is a
# tracked 136 KB schema dump that sits inside the web root, and `public/.htaccess`
# contains no rule denying .sql. Because the file EXISTS, the front-controller
# rewrite (`RewriteCond %{REQUEST_FILENAME} !-f`) does not match it and Apache
# serves it as a plain download at /assets/install.sql.
#
# docroot.sh deliberately leaves install.sql where it is — it is excluded from the
# entries list so it is neither moved nor symlinked — which means the cutover does
# not remove it either. Read-only check; it reports, it does not delete.
echo "Exposed data files in the document root"
EXPOSED="$(find "$DOCROOT_LINK" -maxdepth 3 \( -name '*.sql' -o -name '*.sql.gz' -o -name '*.dump' -o -name '.env*' \) -type f 2>/dev/null | head -10)"
if [ -n "$EXPOSED" ]; then
  echo "$EXPOSED" | while read -r f; do
    printf '  %s (%s bytes)\n' "${f#$DOCROOT_LINK/}" "$(stat -c %s "$f" 2>/dev/null || echo '?')"
  done
  no "data/secret files are present inside the active public document root — remove them from the release before deployment"
else
  ok "no .sql/.dump/.env files served from the document root"
fi
exit $bad
