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

# Bash is required, not preferred: `set -o pipefail` and `local` are bash features.
# If this is ever run by a POSIX shell the failure is cryptic, so say so plainly
# instead of letting it fail somewhere confusing later.
if [ -z "${BASH_VERSION:-}" ]; then
  echo "ERROR: docroot.sh must be run with bash, e.g.  bash docroot.sh ${1:-plan}" >&2
  exit 1
fi

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

# ── WHY THE ENTRY LIST IS A TEMPORARY FILE AND NOT `<(entries)` ───────────────
#
# The first cutover of release a512b7b failed with
#     /dev/fd/62: No such file or directory
# and rolled its own docroot back, leaving the legacy site serving.
#
# `while read -r e; do ...; done < <(entries)` is process substitution. Bash
# implements it by opening a FIFO through /dev/fd, so it needs that path to
# resolve. /dev/fd is normally a symlink to /proc/self/fd; on a host where /proc is
# not mounted, or /dev/fd is missing or restricted, it does not resolve and the
# redirect fails.
#
# What the failure actually did, reproduced under this script's own
# `set -euo pipefail` with a tmpfs mounted over /proc so /dev/fd cannot resolve:
# the redirect fails, `set -e` aborts, and the loop body runs ZERO times. So
# apply() exited non-zero having moved nothing, linked nothing and written an empty
# moved.txt. public_html was NOT modified — index.php, .env and .htaccess were all
# still the legacy ones. first-cutover.sh then removed `current` and reported
# failure, so the failure itself was contained.
#
# Worth stating plainly, because it is easy to assume the opposite: the HTTP 500
# seen during that cutover did NOT come from a half-linked document root. It came
# from the application side — first-cutover.sh runs `migrate --force` (step 6)
# BEFORE the docroot conversion (step 8), and those migrations are forward-only and
# are deliberately not undone on failure. The legacy site was therefore left running
# against an already-migrated schema. Confirm against the server's own logs before
# assuming either explanation; what is reproduced here is the docroot.sh mechanism,
# not the 500.
#
# A temporary file behaves identically for a read loop: the loop still runs in the
# CURRENT shell rather than in a subshell, so `continue` and any state the body sets
# behave exactly as before. It needs only mktemp/rm from coreutils, and the EXIT
# trap removes it on every exit path.
#
# The entry count is also checked before anything is linked. An empty list is a
# refusal, not a no-op: half-applying a cutover is how a release takes a site down.
#
# Do not "simplify" this into a pipe either. `entries | while read ...` puts the
# loop body in a SUBSHELL, which silently discards every variable it assigns.
ENTRIES_FILE=""
entries_into_file() {
  ENTRIES_FILE="$(mktemp "${TMPDIR:-/tmp}/piie-docroot-entries.XXXXXX")" \
    || die "mktemp failed; cannot list document-root entries"
  entries > "$ENTRIES_FILE" || die "could not enumerate document-root entries"
}
discard_entries_file() {
  if [ -n "$ENTRIES_FILE" ]; then rm -f "$ENTRIES_FILE"; ENTRIES_FILE=""; fi
  return 0
}
trap discard_entries_file EXIT

plan() {
  echo "Document root : $DOC (real directory, stays)"
  echo "Release source: $SRC -> $(readlink -f "$SRC")"
  [ -d "$DOC/assets/uploads" ] && echo "KEEP real     : assets/uploads ($(find "$DOC/assets/uploads" -type f | wc -l) files, untouched)" \
                               || echo "WARN          : assets/uploads missing in docroot"
  echo "front controller: index.php -> shell (old copy kept as backups/docroot-<ts>/index.php)"
  entries_into_file
  while read -r e; do
    if   [ -L "$DOC/$e" ]; then s="already a link"
    elif [ -e "$DOC/$e" ]; then s="MOVE to backup, then link"
    else s="link (new)"; fi
    echo "  $e : $s"
  done < "$ENTRIES_FILE"
  discard_entries_file
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
    entries_into_file
    ENTRY_COUNT="$(wc -l < "$ENTRIES_FILE" | tr -d ' ')"
    log "Document-root entries to link: $ENTRY_COUNT"
    # An empty list means the release exposes no public assets to link. Refuse here
    # rather than half-apply: at this point nothing has been moved and moved.txt is
    # still empty, so exiting here is clean.
    [ "$ENTRY_COUNT" -gt 0 ] || { log "REFUSING: release lists 0 linkable entries"; exit 1; }
    while read -r e; do
      [ -L "$DOC/$e" ] && continue
      mkdir -p "$B/$(dirname "$e")"
      if [ -e "$DOC/$e" ]; then mv "$DOC/$e" "$B/$e"; echo "$e" >> "$B/moved.txt"; else echo "$e" >> "$B/moved.txt"; fi
      ln -s "$BASE/current/public/$e" "$DOC/$e"
    done < "$ENTRIES_FILE"
    discard_entries_file
    install -m 644 "$SHELL_SRC" "$DOC/index.php.new" && mv -f "$DOC/index.php.new" "$DOC/index.php"
    if ! health; then log "HEALTH FAILED - reverting"; revert "$TS"; exit 1; fi
    log "Cutover complete. Revert with: docroot.sh revert $TS"
    ;;
  revert) revert "${2:-}" ;;
  *) die "usage: docroot.sh plan|apply|revert <ts>" ;;
esac
