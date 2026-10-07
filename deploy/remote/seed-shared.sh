#!/usr/bin/env bash
# Seed the shared/ directory for the Contabo deployment.
#
#   bash deploy/remote/seed-shared.sh [check|apply]
#
#   check   read-only: report exactly what is present, missing or wrongly permissioned
#   apply   create what is missing and fix the modes of the paths it owns
#
# READ-ONLY BY DEFAULT. `apply` is the only mode that writes, and it will not run
# without being asked for explicitly. It never deletes anything and never touches
# existing content — it creates missing directories and sets modes, nothing else.
#
# WHY A SCRIPT AND NOT A DOCUMENTED LIST
# Every path below is referenced by the deployment scripts, so a hand-typed list
# drifts from the code the moment anything changes. This reads the same names the
# deploy scripts use, so a path that stops being needed stops being listed.
set -uo pipefail
. "$(dirname "$0")/lib.sh"

MODE="${1:-check}"
RUNTIME_GROUP="${PIIE_RUNTIME_GROUP:-www-data}"
bad=0
ok(){ echo "  ok    $*"; }
no(){ echo "  FAIL  $*"; bad=1; }
warn(){ echo "  warn  $*"; }
# act <description> <shell command>
#
# In `apply`, runs the command. In `check`, prints the command it WOULD run and
# changes nothing. $2 must be the command itself -- passing a mode name here
# silently evals a non-existent command.
act(){
  if [ "$MODE" = apply ]; then
    eval "$2"
  else
    echo "  ----  $*"
  fi
}

echo "=== shared/ seed ($MODE) ==="
echo "target: $BASE/shared"
echo

# ── 1. The directories, in the order they matter ────────────────────────────
#
# storage/app          user uploads served through /storage; the single most
#                      irreplaceable thing in shared/. Losing it loses documents.
# storage/app/public   the public half of the above, linked to public/storage.
# storage/framework    sessions, cache and compiled views. Sessions live here, so
#                      this must exist and be writable or nobody can log in.
# storage/logs         Laravel writes here on every request; if it is not writable
#                      the application 500s rather than merely losing logs.
# public-uploads       everything served from public/assets/uploads. Covered in
#                      depth by prepare_public_uploads(); created here so the
#                      first activation has a correctly permissioned tree to merge
#                      into rather than creating one with the wrong mode.
echo "Directories"
for d in \
  "$BASE/shared/storage" \
  "$BASE/shared/storage/app" \
  "$BASE/shared/storage/app/public" \
  "$BASE/shared/storage/framework" \
  "$BASE/shared/storage/framework/cache" \
  "$BASE/shared/storage/framework/cache/data" \
  "$BASE/shared/storage/framework/sessions" \
  "$BASE/shared/storage/framework/views" \
  "$BASE/shared/storage/logs" \
  "$BASE/shared/public-uploads"
do
  rel="${d#$BASE/shared/}"
  if [ -L "$d" ]; then
    no "$rel is a symlink; it must be a real directory"
  elif [ -d "$d" ]; then
    if [ -w "$d" ]; then ok "$rel exists and is writable"; else no "$rel exists but is NOT writable by this user"; fi
  else
    # Show what apply would do; act() performs it only under `apply`.
    act "$rel missing - mkdir -p '$d'" "mkdir -p '$d'"
    if [ "$MODE" = apply ]; then
      if [ -d "$d" ]; then ok "$rel created"; else no "could not create $rel"; fi
    else
      warn "$rel missing (run with apply)"
    fi
  fi
done

# ── 2. Runtime write permissions ────────────────────────────────────────────
#
# PHP-FPM runs as $RUNTIME_GROUP and has to write into storage/ and into
# public-uploads. setgid on the directories means anything created later inherits
# the group and stays group-writable by construction. This is the same model
# prepare_public_uploads applies, and it is repeated here because these paths must
# be correct BEFORE the first release exists.
echo
echo "Runtime permissions (group $RUNTIME_GROUP)"
for d in "$BASE/shared/storage" "$BASE/shared/storage/app" "$BASE/shared/storage/app/public" \
         "$BASE/shared/storage/framework" "$BASE/shared/storage/framework/cache" \
         "$BASE/shared/storage/framework/cache/data" "$BASE/shared/storage/framework/sessions" \
         "$BASE/shared/storage/framework/views" "$BASE/shared/storage/logs" \
         "$BASE/shared/public-uploads"; do
  [ -d "$d" ] || continue
  rel="${d#$BASE/shared/}"
  mode="$(stat -c %a "$d" 2>/dev/null || echo '?')"
  grp="$(stat -c %G "$d" 2>/dev/null || echo '?')"
  gwrite="${mode:$(( ${#mode} - 2 )):1}"

  # Group digit must carry the WRITE bit: 7=rwx 6=rw- 3=-wx 2=-w-.
  #
  # Digit 5 (r-x) and 4 (r--) do NOT grant group write and must NOT be accepted.
  # An earlier version of this check allowed 5, which made `mkdir -p` output
  # (umask 022 -> 2755, setgid inherited but group not writable) report as
  # "group-writable" and skip its own chmod. PHP-FPM then could not write
  # sessions, logs or compiled views. preflight.sh tests the same digit
  # correctly; keep the two in step.
  if [ "${mode:0:1}" = 2 ] && { [ "$gwrite" = 7 ] || [ "$gwrite" = 6 ] || [ "$gwrite" = 3 ] || [ "$gwrite" = 2 ]; }; then
    ok "$rel mode $mode (setgid, group-writable)"
  else
    act "$rel mode $mode group $grp - wants 2775" "chmod 2775 '$d'"
    if [ "$MODE" = apply ]; then
      chmod 2775 "$d" 2>/dev/null && ok "$rel now $(stat -c %a "$d")" || no "could not chmod $rel"
    else
      no "$rel mode $mode group $grp - wants 2775 so PHP-FPM can write"
    fi
  fi

  if [ "$grp" != "$RUNTIME_GROUP" ]; then
    warn "$rel group is '$grp', expected '$RUNTIME_GROUP'"
    warn "  ONE-TIME FIX (needs root): use chown with option -R to set owner/group $(id -un):$RUNTIME_GROUP on '$d'"
  fi
done

# ── 3. The one file that must never be world-readable ───────────────────────
echo
echo "Environment file"
ENVF="$BASE/shared/.env"
if [ -f "$ENVF" ]; then
  mode="$(stat -c %a "$ENVF" 2>/dev/null || echo '?')"
  if [ "$mode" = 600 ]; then
    ok "shared/.env mode 600 (contents not printed)"
  else
    act "shared/.env mode $mode - wants 600" "chmod 600 '$ENVF'"
    if [ "$MODE" = apply ]; then
      chmod 600 "$ENVF" 2>/dev/null && ok "shared/.env now $(stat -c %a "$ENVF")" || no "could not chmod shared/.env"
    else
      no "shared/.env mode $mode - world/group-readable credential file; run with apply"
    fi
  fi
  for k in APP_KEY DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD; do
    grep -qE "^$k=.+" "$ENVF" && ok "env $k present" || no "env $k MISSING from shared/.env"
  done
  [ "$(env_get APP_DEBUG)" = false ] && ok "APP_DEBUG=false" || no "APP_DEBUG must be false"
  [ "$(env_get APP_ENV)" = production ] && ok "APP_ENV=production" || no "APP_ENV must be production"
else
  no "shared/.env missing - create it ONCE BY HAND from a local .env; never generate it (that would rotate APP_KEY)"
fi

echo
if [ "$MODE" = apply ]; then
  [ $bad -eq 0 ] && echo "shared/ seeded." || echo "shared/ seeded WITH FAILURES (see FAIL above)"
else
  [ $bad -eq 0 ] && echo "shared/ is ready." || echo "shared/ is NOT ready (see FAIL above). Run: seed-shared.sh apply"
fi
exit $bad
