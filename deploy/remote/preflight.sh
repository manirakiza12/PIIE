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

  # Mode 600 exactly, and FAIL rather than warn.
  #
  # This file holds DB_PASSWORD, APP_KEY and every API credential. It was 644 —
  # world-readable — which means every other account on the host could read the
  # application's entire credential set. There is no safe middle ground here, so
  # "close enough" is not accepted: 640 still grants group read, and 604 would
  # as well.
  #
  # Checking the numeric mode rather than testing access from this user is
  # deliberate: a preflight running as root would see a readable file even at 600.
  # `stat -c %a` reports the stored mode, which is what other accounts get.
  ENV_MODE="$(stat -c %a "$BASE/shared/.env" 2>/dev/null || echo '?')"
  if [ "$ENV_MODE" = "600" ]; then
    ok ".env mode is 600"
  else
    no ".env mode is $ENV_MODE, must be 600 (world/group-readable credential file)"
    no "  FIX (as the deploy user): chmod 600 '$BASE/shared/.env'"
  fi

  # A .env inside the document root is a credential file being served over HTTP.
  # Checked independently of the .sql scan below because it is the single worst
  # thing that can sit in a web root.
  for d in "$DOCROOT_LINK" "$BASE/shared/public-uploads"; do
    [ -d "$d" ] || continue
    ENVLEAK="$(find "$d" -maxdepth 3 -name '.env*' -type f 2>/dev/null | head -5)"
    if [ -n "$ENVLEAK" ]; then
      echo "$ENVLEAK" | while read -r f; do echo "  found: ${f#$d/}"; done
      no "a .env file is present under ${d} and would be web-served"
    fi
  done

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

echo "Persistent public uploads"
# shared/public-uploads is the single real copy of everything served from
# public/assets/uploads. It is created by deploy-release.sh during release
# preparation, so its absence before the first release is NORMAL and is only a
# warning here — preflight must not require a release to exist in order to run.
PU="$BASE/shared/public-uploads"
RUNTIME_GROUP="${PIIE_RUNTIME_GROUP:-www-data}"
if [ -L "$PU" ]; then
  no "shared/public-uploads is a symlink; it must be a real directory"
elif [ -d "$PU" ]; then
  ok "shared/public-uploads exists ($(find "$PU" -type f 2>/dev/null | wc -l) file(s))"

  # (a) Can the DEPLOY user manage it? A different question from (b).
  if [ -w "$PU" ]; then ok "deploy user can write shared/public-uploads"; else no "deploy user CANNOT write shared/public-uploads"; fi

  # (b) Can the PHP-FPM RUNTIME write it? This is the one that silently breaks
  # uploads in production, and `[ -w "$PU" ]` says nothing about it: it only proves
  # the identity running THIS script can write.
  PGROUP="$(stat -c %G "$PU" 2>/dev/null || echo '?')"
  PMODE="$(stat -c %a "$PU" 2>/dev/null || echo '?')"
  PGRP_OTHER="$(id -gn)"

  if [ "$PGROUP" = "$RUNTIME_GROUP" ]; then
    ok "owned by group $RUNTIME_GROUP (expected PHP-FPM group)"
  else
    no "group is '$PGROUP', expected '$RUNTIME_GROUP'. PHP-FPM will not be able to write."
    no "  ONE-TIME FIX (needs root): chown -R piie:$RUNTIME_GROUP '$PU' && chmod 2775 '$PU'"
  fi

  # Group-write bit, and setgid so entries created later inherit that group.
  #
  # `stat -c %a` prints the MINIMUM number of digits: 755, 2775, 664 - not a fixed
  # width. An earlier version matched fixed 5- and 6-character patterns, so every
  # real mode fell through to "?" and a correctly configured 2775 directory was
  # reported as not group-writable. The group digit is therefore read positionally
  # from the right, which works for any width.
  gwrite="${PMODE:$(( ${#PMODE} - 2 )):1}"
  if [ "$gwrite" = 7 ] || [ "$gwrite" = 6 ] || [ "$gwrite" = 3 ] || [ "$gwrite" = 2 ]; then
    ok "group-writable (mode $PMODE)"
  else
    no "not group-writable (mode $PMODE, group-write digit '$gwrite'); PHP-FPM cannot write uploads"
    no "  ONE-TIME FIX (needs root): chmod 2775 '$PU'"
  fi

  case "${PMODE:0:1}" in
    2) ok "setgid set (mode $PMODE): new files inherit group $RUNTIME_GROUP" ;;
    *) no "setgid bit is NOT set (mode $PMODE); files created later will not inherit group $RUNTIME_GROUP"
       no "  ONE-TIME FIX (needs root): chmod g+s '$PU'" ;;
  esac

  # Direct proof, but only when it can be obtained without assuming sudo. A CI
  # runner is unprivileged, so `sudo -n` may simply not work; that is reported as
  # unverified rather than silently passed.
  if command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
    if sudo -n -u "$RUNTIME_GROUP" test -w "$PU" 2>/dev/null; then
      ok "verified: runtime user $RUNTIME_GROUP can write"
    else
      no "verified: runtime user $RUNTIME_GROUP CANNOT write"
    fi
  else
    warn "cannot verify runtime writability without root; the group/mode/setgid checks above are the evidence available"
    warn "  verify once by hand: sudo -u $RUNTIME_GROUP touch '$PU/.probe' && rm -f '$PU/.probe'"
  fi

  # A default ACL is the belt-and-braces path when www-data is not the owning group.
  if command -v getfacl >/dev/null 2>&1; then
    if getfacl -p "$PU" 2>/dev/null | grep -q "user:$RUNTIME_GROUP"; then
      ok "ACL grants $RUNTIME_GROUP access"
    fi
  fi
else
  warn "shared/public-uploads missing (created by the first release; nothing to persist yet)"
fi

# When a release IS active, its public/assets/uploads must resolve to that shared
# directory. A release holding a real directory there instead would silently lose
# every runtime upload on the next release switch — the exact bug this prevents.
if [ -L "$BASE/current" ]; then
  RL="$BASE/current/public/assets/uploads"
  if [ ! -e "$RL" ]; then
    no "current release has no public/assets/uploads"
  elif [ ! -L "$RL" ]; then
    no "current/public/assets/uploads is a real directory; runtime uploads would be lost on the next release"
  elif [ "$(readlink -f "$RL")" != "$(readlink -f "$PU")" ]; then
    no "current uploads symlink points at $(readlink -f "$RL"), expected $(readlink -f "$PU")"
  else
    ok "current uploads -> shared/public-uploads"
  fi
fi

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
