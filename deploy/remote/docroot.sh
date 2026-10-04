#!/usr/bin/env bash
# One-time conversion of the LIVE document root into a "thin shell" that serves
# whichever release `current` points at. public_html itself is never renamed,
# deleted or replaced; nothing is removed, only MOVED into backups/docroot-<ts>/.
#
#   docroot.sh plan                 read-only: prints exactly what apply would do
#   docroot.sh apply                needs PIIE_CONFIRM_DOCROOT_CUTOVER=yes
#   docroot.sh revert <ts>          restores everything moved by that apply
#
# Why a shell and not a symlinked public_html: the vhost document root is fixed
# at public_html (the root .htaccess says so; /composer.json answers 403 from it),
# and public_html holds persistent uploads in assets/uploads.
set -euo pipefail
. "$(dirname "$0")/lib.sh"
CMD="${1:-plan}"; DOC="$DOCROOT_LINK"; SRC="$BASE/current/public"
SHELL_SRC="${PIIE_SHELL_SRC:-$BASE/bin/docroot-index.php}"
# Top-level public entries that become symlinks into the current release.
# assets/ stays a REAL directory (it holds uploads/); its children are linked.
TOP="css js frontend course favicon.ico robots.txt"
KEEP_REAL="uploads"

[ -d "$DOC" ] && [ ! -L "$DOC" ] || die "$DOC must be a real directory (it is not touched, only its contents)"
[ -d "$SRC" ] || die "no release at $SRC; stage a release first"
[ -f "$SHELL_SRC" ] || die "shell index.php missing at $SHELL_SRC"

entries() {
  for t in $TOP; do [ -e "$SRC/$t" ] && echo "$t"; done
  for c in $(ls -A "$SRC/assets"); do
    case " $KEEP_REAL install.sql " in *" $c "*) continue;; esac
    echo "assets/$c"
  done
}

plan() {
  echo "Document root : $DOC (real directory, stays)"
  echo "Release source: $SRC -> $(readlink -f "$SRC")"
  [ -d "$DOC/assets/uploads" ] && echo "KEEP real     : assets/uploads ($(find "$DOC/assets/uploads" -type f | wc -l) files, untouched)" \
                               || echo "WARN          : assets/uploads missing in docroot"
  echo "front controller: index.php -> shell (old copy kept as backups/docroot-<ts>/index.php)"
  while read -r e; do
    if   [ -L "$DOC/$e" ]; then s="already a link"
    elif [ -e "$DOC/$e" ]; then s="MOVE to backup, then link"
    else s="link (new)"; fi
    echo "  $e : $s"
  done < <(entries)
  echo "NOT touched   : .env, .htaccess, app/ vendor/ storage/ etc. in docroot (kept as the legacy fallback)"
}

health() {
  for u in / /assets/css/style.css; do
    c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$HEALTH_URL${u#/}" || echo 000)
    log "check $u -> $c"; [ "$c" = 200 ] || return 1
  done
}

revert() {
  local B="$BASE/backups/docroot-${1:?timestamp}"
  [ -d "$B" ] || die "no backup $B"
  log "Reverting from $B"
  [ -f "$B/index.php" ] && cp -p "$B/index.php" "$DOC/index.php"
  while read -r e; do
    [ -L "$DOC/$e" ] && rm -f "$DOC/$e"
    [ -e "$B/$e" ] && mv "$B/$e" "$DOC/$e"
  done < "$B/moved.txt"
  log "Reverted."
}

case "$CMD" in
  plan) plan ;;
  apply)
    [ "${PIIE_CONFIRM_DOCROOT_CUTOVER:-}" = yes ] || die "set PIIE_CONFIRM_DOCROOT_CUTOVER=yes to apply"
    [ -L "$BASE/current" ] || die "no current release"
    exec 9>"$BASE/.deploy.lock"; flock -n 9 || die "a deployment is running"
    TS=$(date +%Y%m%d-%H%M%S); B="$BASE/backups/docroot-$TS"; mkdir -p "$B"; : > "$B/moved.txt"
    cp -p "$DOC/index.php" "$B/index.php"
    log "Backup dir $B"
    while read -r e; do
      [ -L "$DOC/$e" ] && continue
      mkdir -p "$B/$(dirname "$e")"
      if [ -e "$DOC/$e" ]; then mv "$DOC/$e" "$B/$e"; echo "$e" >> "$B/moved.txt"; else echo "$e" >> "$B/moved.txt"; fi
      ln -s "$BASE/current/public/$e" "$DOC/$e"
    done < <(entries)
    install -m 644 "$SHELL_SRC" "$DOC/index.php.new" && mv -f "$DOC/index.php.new" "$DOC/index.php"
    if ! health; then log "HEALTH FAILED - reverting"; revert "$TS"; exit 1; fi
    log "Cutover complete. Revert with: docroot.sh revert $TS"
    ;;
  revert) revert "${2:-}" ;;
  *) die "usage: docroot.sh plan|apply|revert <ts>" ;;
esac
