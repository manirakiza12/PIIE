#!/usr/bin/env bash
# Install a release on the server. Usage:
#   deploy-release.sh <release-id> <artefact.tar.gz> <sha256> [stage|activate]
# `stage` (default) unpacks and prepares the release only: no backup, no
# migration, no symlink change. `activate` additionally backs up, migrates and
# swaps `current`; Nginx serves the application from current/public.
set -euo pipefail
. "$(dirname "$0")/lib.sh"
RID="${1:?release id}"; ART="${2:?artefact}"; SUM="${3:?sha256}"; MODE="${4:-stage}"
if [ "$MODE" = activate ] && [ -n "${EXPECTED_DEPLOYED_SHA:-}" ]; then
  [[ "$EXPECTED_DEPLOYED_SHA" =~ ^[0-9a-f]{40}$ ]] || die "invalid deployed baseline"
  [ -f "$BASE/current/RELEASE_SHA" ] || die "current release identity is missing"
  [ "$(cat "$BASE/current/RELEASE_SHA")" = "$EXPECTED_DEPLOYED_SHA" ] || die "deployed release changed since migration review"
fi
[[ "$RID" =~ ^[0-9]{8}-[0-9]{6}-[0-9a-f]{7,12}$ ]] || die "bad release id"
[ "$MODE" = stage ] || [ "$MODE" = activate ] || die "mode must be stage|activate"

exec 9>"$BASE/.deploy.lock"; flock -n 9 || die "another deployment is running"

[ -f "$BASE/shared/.env" ] || die "shared/.env missing; refusing to generate one (would rotate APP_KEY)"
[ -d "$BASE/shared/storage" ] || die "shared/storage missing"
echo "$SUM  $ART" | sha256sum -c - >/dev/null || die "artefact checksum mismatch"

# ── PERSISTENT PUBLIC UPLOADS ────────────────────────────────────────────────
#
# Laravel storage is already persistent (shared/storage is symlinked in below).
# `public/assets/uploads` is NOT, and it is not safe to leave it that way: the
# release directory is unpacked fresh from an artefact every time, so any runtime
# upload written there disappears on the next release switch. A student's uploaded
# ID scan must not evaporate because a deploy happened.
#
# The fix is the same pattern already used for storage, and the same pattern the
# legacy shared-host cutover uses for public_html/assets/uploads: ONE shared
# directory, with every release symlinking to it.
#
#     $BASE/shared/public-uploads          <- the only real copy
#     $REL/public/assets/uploads           -> symlink to the above
#
# The release artefact DOES contain baseline upload files (the tracked logos,
# brochure, syllabus assets and so on — built by `git archive`, so exactly the
# committed set). Those are merged into the shared directory first.
#
# MERGE RULES, all of which matter:
#
#   * A file already in shared/public-uploads ALWAYS WINS. That directory holds the
#     live runtime uploads. A baseline file is only ever ADDED when the path is
#     absent, never used to overwrite. There is no `cp` over an existing file and
#     no `rsync --delete` anywhere in this function.
#   * Nested directories are created as needed.
#   * Symlinks are REFUSED, at three levels: the shared directory itself, the
#     release's uploads directory, and any entry found inside it. Without these, an
#     attacker able to plant a symlink could get the merge to read through it and
#     pull arbitrary server files into a web-served directory, or redirect writes.
#   * The entry list goes through a TEMPORARY FILE, not `<(...)`. Process
#     substitution needs /dev/fd, which is exactly what broke the first cutover
#     (see docroot.sh). A pipe would also be wrong here: it runs the loop body in a
#     subshell, so `die` inside would exit only that subshell and the deploy would
#     carry on as if the merge had succeeded.
#
# Fail-closed: any unexpected filesystem shape aborts before anything is removed.
SHARED_UPLOADS="$BASE/shared/public-uploads"

# ── RUNTIME WRITABILITY MODEL ────────────────────────────────────────────────
#
# Two different identities write to this tree and the permissions have to serve
# both without either being able to read what it should not:
#
#   deployment user  piie        (this script: merges baseline artefacts)
#   PHP-FPM runtime  www-data    (the web app: writes real user uploads)
#
# `chmod 775` alone is NOT sufficient and, worse, proves nothing about the second
# identity. A bare 775 gives group write to whichever group happens to own the
# directory; if that is not www-data, PHP-FPM cannot write a single upload and every
# upload fails at runtime. Verifying it as `[ -w "$PU" ]` only proves the DEPLOY user
# can write, which is a different question.
#
# The model, in order of preference:
#
#   1. GROUP WRITE + SETGID. The directory is owned by piie with group
#      `www-data` and mode 2775. The setgid bit is the important part: it makes every
#      file and subdirectory created LATER inherit group www-data, so nested
#      directories created by a merge, and files created by PHP at runtime, stay
#      group-writable without anyone having to remember to fix them.
#   2. A NARROWLY SCOPED DEFAULT ACL for u:www-data, applied opportunistically when
#      setfacl is present. This covers the case where www-data is not the owning
#      group, without granting anything to "other".
#
# Deliberately NOT done:
#   * chmod 777 — never.
#   * recursive chown/chmod over existing production uploads. Only directories THIS
#     script creates are touched; pre-existing content keeps whatever permissions
#     and ownership it already has, because those files are live data.
#   * sudo. This script runs unprivileged from CI and must never assume sudo exists.
#     `chgrp` is attempted ONLY when the deploy user is already a member of the
#     runtime group; otherwise the script says so and leaves ownership alone.
RUNTIME_GROUP="${PIIE_RUNTIME_GROUP:-www-data}"

prepare_public_uploads() {
  local rel_uploads="$1"
  local copied=0 kept=0 dirs=0 p rel dest list created_top=0

  # ── validate the shared directory before writing anything to it ──
  if [ -L "$SHARED_UPLOADS" ]; then
    die "shared/public-uploads is a symlink; refusing to write uploads through it"
  fi
  if [ -e "$SHARED_UPLOADS" ] && [ ! -d "$SHARED_UPLOADS" ]; then
    die "shared/public-uploads exists but is not a directory"
  fi
  if [ ! -d "$SHARED_UPLOADS" ]; then
    mkdir -p "$SHARED_UPLOADS" || die "cannot create $SHARED_UPLOADS"
    created_top=1
  fi

  # Apply the runtime model to the top-level directory.
  #
  # 2775 = setgid + rwxrwxr-x. setgid is the load-bearing bit: it is what makes
  # everything created inside inherit the group, so PHP-FPM's uploads and any
  # directory this script adds are group-writable by construction.
  chmod 2775 "$SHARED_UPLOADS" 2>/dev/null \
    || warn "could not chmod 2775 $SHARED_UPLOADS; runtime uploads may not be writable"

  # chgrp only when it can actually succeed without sudo. If the deploy user is not
  # a member of the runtime group, `chgrp` would fail anyway; attempting it and
  # ignoring the error would be theatre, so the group is left alone and reported.
  if [ "$created_top" = 1 ] && [ "$(id -gn)" != "$RUNTIME_GROUP" ]; then
    if id -nG 2>/dev/null | tr ' ' '\n' | grep -qx "$RUNTIME_GROUP"; then
      chgrp "$RUNTIME_GROUP" "$SHARED_UPLOADS" 2>/dev/null \
        || warn "chgrp $RUNTIME_GROUP failed on $SHARED_UPLOADS"
    else
      warn "deploy user is not a member of '$RUNTIME_GROUP'; leaving group ownership alone."
      warn "  ONE-TIME VPS PREREQUISITE: use chown with option -R to set owner/group piie:$RUNTIME_GROUP on '$SHARED_UPLOADS'; then chmod 2775 '$SHARED_UPLOADS'"
    fi
  fi

  # Opportunistic, narrowly scoped default ACL: grants the runtime USER write access
  # regardless of which group owns the directory, and — being a default ACL — applies
  # only to entries created afterwards. Nothing is granted to "other". Skipped
  # silently where setfacl or filesystem ACL support is absent.
  if command -v setfacl >/dev/null 2>&1; then
    setfacl -m "u:$RUNTIME_GROUP:rx" "$SHARED_UPLOADS" 2>/dev/null || true
    setfacl -d -m "u:$RUNTIME_GROUP:rwx" "$SHARED_UPLOADS" 2>/dev/null || true
  fi

  # ── validate the release directory before reading anything from it ──
  if [ -L "$rel_uploads" ]; then
    die "release public/assets/uploads is a symlink; refusing to merge through it"
  fi
  if [ -e "$rel_uploads" ] && [ ! -d "$rel_uploads" ]; then
    die "release public/assets/uploads exists but is not a directory"
  fi
  [ -d "$rel_uploads" ] || mkdir -p "$rel_uploads" \
    || die "cannot create $rel_uploads"

  # ── merge baseline files, never overwriting ──
  list="$(mktemp "${TMPDIR:-/tmp}/piie-public-uploads.XXXXXX")" \
    || die "mktemp failed; cannot enumerate release uploads"
  # ABSOLUTE paths, deliberately. An earlier version emitted `find .` output and then
  # tested those relative paths from the caller's working directory, where they
  # resolve to nothing — every directory entry failed "neither a regular file nor a
  # directory" and the merge silently copied nothing. Absolute paths are correct
  # regardless of where the loop runs.
  #
  # -print0 so names containing spaces or newlines survive; no -L, so a symlink is
  # reported as an entry instead of being followed.
  find "$rel_uploads" -mindepth 1 -print0 > "$list" \
    || { rm -f "$list"; die "cannot enumerate release uploads"; }

  while IFS= read -r -d '' p; do
    rel="${p#"$rel_uploads"/}"
    dest="$SHARED_UPLOADS/$rel"

    if [ -L "$p" ]; then
      rm -f "$list"
      die "release upload '$rel' is a symlink; refusing to merge (bad artefact or tampering)"
    fi

    if [ -d "$p" ]; then
      if [ -L "$dest" ]; then
        rm -f "$list"
        die "shared/public-uploads/$rel is a symlink; refusing to descend into it"
      fi
      if [ -e "$dest" ] && [ ! -d "$dest" ]; then
        rm -f "$list"
        die "shared/public-uploads/$rel exists and is not a directory"
      fi
      if [ ! -d "$dest" ]; then
        mkdir -p "$dest" || { rm -f "$list"; die "cannot create $dest"; }
        # Only directories THIS RUN created. A pre-existing directory keeps whatever
        # permissions it already has — it may hold live production uploads, and
        # silently changing the mode of live data is not this script's decision.
        chmod 2775 "$dest" 2>/dev/null || true
        dirs=$((dirs + 1))
      fi
      continue
    fi

    if [ ! -f "$p" ]; then
      rm -f "$list"
      die "release upload '$rel' is neither a regular file nor a directory"
    fi

    # THE PERSISTENT FILE ALWAYS WINS.
    if [ -e "$dest" ] || [ -L "$dest" ]; then
      kept=$((kept + 1))
      continue
    fi

    cp -p -- "$p" "$dest" || { rm -f "$list"; die "cannot copy baseline upload '$rel'"; }
    # 664 = group-writable, nothing for "other". Only files this run created.
    chmod 664 -- "$dest" 2>/dev/null || true
    copied=$((copied + 1))
  done < "$list"
  rm -f "$list"

  log "public uploads merged into shared: ${copied} baseline file(s) added, ${kept} kept, ${dirs} dir(s) ensured"

  # ── only now replace the release-local copy with the symlink ──
  rm -rf -- "$rel_uploads" || die "cannot remove release-local uploads directory"
  ln -s "$SHARED_UPLOADS" "$rel_uploads" \
    || die "cannot symlink release uploads to shared/public-uploads"

  # Prove the result rather than assuming it.
  [ -d "$rel_uploads" ] && [ -L "$rel_uploads" ] \
    || die "release uploads is not a usable symlink after preparation"
}

REL="$BASE/releases/$RID"
[ ! -e "$REL" ] || die "release $RID already exists (releases are append-only)"

if [ "$MODE" = activate ]; then
  [ "$DOCROOT_LINK" = "$BASE/current/public" ] \
    || die "production document root must be $BASE/current/public"
  bash "$(dirname "$0")/backup.sh" "$RID"
fi

log "Unpacking into $RID"
mkdir -p "$REL.tmp"; tar -xzf "$ART" -C "$REL.tmp"
mv "$REL.tmp" "$REL"

ln -sfn "$BASE/shared/.env" "$REL/.env"
mkdir -p "$BASE/shared/storage/app/public" "$BASE/shared/storage/logs" \
         "$BASE/shared/storage/framework/cache/data" "$BASE/shared/storage/framework/sessions" \
         "$BASE/shared/storage/framework/views"
rm -rf "$REL/storage"; mkdir -p "$REL/storage"
for d in app logs framework; do ln -sfn "$BASE/shared/storage/$d" "$REL/storage/$d"; done
ln -sfn "$BASE/shared/storage/app/public" "$REL/public/storage"
chmod -R u+rwX,go-w "$REL"; chmod -R 775 "$REL/bootstrap/cache" 2>/dev/null || true

# Persistent public uploads. Deliberately AFTER the recursive chmod above: the
# symlink created here must never be traversed by it, and merging afterwards keeps
# the two operations from interacting at all.
prepare_public_uploads "$REL/public/assets/uploads"

cd "$REL"
"$PHP" artisan --version >/dev/null || die "artisan does not boot on $PHP"
"$PHP" artisan package:discover --ansi >/dev/null

log "Pending migrations (pretend; nothing executed):"
PRETEND="$("$PHP" artisan migrate --pretend --force 2>&1)" || die "migration preflight failed; release will not activate"
echo "$PRETEND" | head -60
if echo "$PRETEND" | grep -qiE 'drop +(table|column|database)|truncate|delete +from'; then
  [ "${ALLOW_DESTRUCTIVE_MIGRATIONS:-}" = 1 ] || die "destructive SQL in pending migrations; review, then set ALLOW_DESTRUCTIVE_MIGRATIONS=1"
fi

if [ "$MODE" = stage ]; then
  log "STAGED $RID. No migration run, no symlink changed, site untouched."
  exit 0
fi

PREV="$(readlink "$BASE/current" 2>/dev/null || true)"
log "Migrating (forward-only; never fresh/wipe)"
"$PHP" artisan migrate --force || { log "migration failed; current NOT switched"; exit 1; }
"$PHP" artisan config:cache; "$PHP" artisan view:clear

ln -sfn "$REL" "$BASE/current.new"; mv -Tf "$BASE/current.new" "$BASE/current"
log "current -> $RID (previous: ${PREV:-none})"
"$PHP" artisan queue:restart >/dev/null 2>&1 || true

ok=0
for i in 1 2 3 4 5 6; do
  if app_health_check; then
    log "application health attempt $i: HTTP $APP_HEALTH_LAST_CODE, expected body confirmed"
    ok=1
    break
  fi

  log "application health attempt $i: HTTP ${APP_HEALTH_LAST_CODE:-000}, health response rejected"
  sleep 5
done
if [ "$ok" != 1 ]; then
  restore_after_failed_activation "$PREV"
  log "Failed activation routing reverted. Database NOT restored automatically (see docs/DEPLOYMENT.md)."
  exit 1
fi

ls -1dt "$BASE"/releases/*/ | tail -n +$((KEEP_RELEASES+1)) | while read -r d; do
  [ "$(readlink -f "$d")" = "$(readlink -f "$BASE/current")" ] || rm -rf -- "$d"
done
log "DEPLOYED $RID"
