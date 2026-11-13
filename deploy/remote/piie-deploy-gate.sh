#!/usr/bin/env bash
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
set -euo pipefail

# piie-deploy-gate — narrowly scoped privileged entry point for deployment operations.
# Installed at /usr/local/sbin/piie-deploy-gate (root:root, 0755).
# Sudoers: piie ALL=(root) NOPASSWD: /usr/local/sbin/piie-deploy-gate <subcommand> <strict-args...>
# All arguments are positionally validated; no environment variables from caller are trusted.
# The protected helpers snapshot/check archives, enforce the recorded mount identity,
# and verify the approved rollback baseline before activation.

BASE=/home/piie/deployments/piie
LIB=/usr/local/lib/piie/bin
LOG=/var/log/piie-deploy-gate.log

log() {
  local ts
  ts=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
  printf '%s %s\n' "$ts" "$*" | tee -a "$LOG"
}

fail() {
  log "REFUSED: $*"
  exit 1
}

require_root() {
  [ "$(id -u)" = 0 ] || fail 'root execution required'
}

# Validate the complete installed helper set before sourcing or executing it.
# No caller-writable script or caller-controlled environment crosses this gate.
verify_installation() {
  local path mode
  for path in /usr /usr/local /usr/local/lib /usr/local/lib/piie "$LIB"; do
    [ -d "$path" ] && [ ! -L "$path" ] || fail "unsafe helper directory: $path"
    [ "$(stat -c %u "$path")" = 0 ] || fail "helper directory not root-owned: $path"
    mode=$(stat -c %a "$path")
    (( (8#$mode & 0022) == 0 )) || fail "writable helper directory: $path"
  done
  for path in "$LIB"/*.sh "$LIB"/*.py; do
    [ -f "$path" ] && [ ! -L "$path" ] || fail "unsafe helper: $path"
    [ "$(stat -c '%u:%h' "$path")" = 0:1 ] || fail "unsafe helper ownership or links: $path"
    mode=$(stat -c %a "$path")
    (( (8#$mode & 0022) == 0 )) || fail "writable helper: $path"
  done
}

run_helper() {
  env -i PATH="$PATH" /bin/bash "$LIB/$1" "${@:2}"
}

# Validate absolute path is under BASE and has no symlinks
validate_path_under_base() {
  local path=$1 label=$2
  [ -n "$path" ] || fail "$label: empty"
  [[ "$path" =~ ^$BASE/incoming/piie-release-[0-9a-f]{12}\.tar\.gz$ ]] || fail "$label: invalid incoming archive path"
  [ ! -L "$path" ] || fail "$label: symlink refused"
}

# Validate release ID format
validate_rid() {
  local rid=$1
  [[ "$rid" =~ ^[0-9]{8}-[0-9]{6}-[0-9a-f]{12}$ ]] || fail "invalid release id: $rid"
}

# Validate SHA256 checksum
validate_sha256() {
  local sum=$1
  [[ "$sum" =~ ^[0-9a-f]{64}$ ]] || fail "invalid sha256: $sum"
}

# Validate full SHA
validate_full_sha() {
  local sha=$1
  [[ "$sha" =~ ^[0-9a-f]{40}$ ]] || fail "invalid full sha: $sha"
}

# Integrity checks on a caller-supplied archive are not independent authorization.
# Only a root-controlled receipt, obtained through the administrator review path,
# may authorize the exact candidate, digest, baseline, rollback target and flags.

# OPERATION: preflight
op_preflight() {
  [ "$#" -eq 0 ] || fail 'preflight: no arguments allowed'
  log 'preflight: start'
  require_root
  run_helper container-preflight.sh
  log 'preflight: ok'
}

# OPERATION: deploy-stage
op_deploy_stage() {
  [ "$#" -eq 4 ] || fail 'deploy-stage: expected RID ART SUM EXPECTED_RELEASE_SHA'
  local RID=$1 ART=$2 SUM=$3 SHA=$4
  validate_rid "$RID"
  validate_path_under_base "$ART" "archive"
  validate_sha256 "$SUM"
  validate_full_sha "$SHA"
  [ "${RID##*-}" = "${SHA:0:12}" ] || fail 'release ID differs from SHA'
  log "deploy-stage: RID=$RID ART=$ART SUM=${SUM:0:12}..."
  require_root
  env -i PATH="$PATH" EXPECTED_RELEASE_SHA="$SHA" /bin/bash "$LIB/container-deploy.sh" "$RID" "$ART" "$SUM" stage
  log 'deploy-stage: ok'
}

# OPERATION: deploy-activate
op_deploy_activate() {
  [ "$#" -eq 8 ] || fail 'deploy-activate: expected RID ART SUM EXPECTED_RELEASE_SHA EXPECTED_DEPLOYED_SHA EXPECTED_ROLLBACK_RELEASE PIIE_CODE_ONLY ALLOW_DESTRUCTIVE'
  local RID=$1 ART=$2 SUM=$3 EXPECTED_RELEASE_SHA=$4 EXPECTED_DEPLOYED_SHA=$5 EXPECTED_ROLLBACK_RELEASE=$6 PIIE_CODE_ONLY=$7 ALLOW_DESTRUCTIVE=$8
  validate_rid "$RID"
  validate_path_under_base "$ART" "archive"
  validate_sha256 "$SUM"
  validate_full_sha "$EXPECTED_RELEASE_SHA"
  if [ -n "$EXPECTED_DEPLOYED_SHA" ] && [ "$EXPECTED_DEPLOYED_SHA" != auto ]; then
    validate_full_sha "$EXPECTED_DEPLOYED_SHA"
  fi
  [ "${RID##*-}" = "${EXPECTED_RELEASE_SHA:0:12}" ] || fail 'release ID differs from SHA'
  if [ -n "$EXPECTED_ROLLBACK_RELEASE" ] && [ "$EXPECTED_ROLLBACK_RELEASE" != auto ]; then
    [[ "$EXPECTED_ROLLBACK_RELEASE" =~ ^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$ ]] || fail "invalid rollback release id: $EXPECTED_ROLLBACK_RELEASE"
  fi
  [[ "$ALLOW_DESTRUCTIVE" =~ ^(0|1)$ ]] || fail 'ALLOW_DESTRUCTIVE must be 0 or 1'
  [[ "$PIIE_CODE_ONLY" =~ ^(0|1)$ ]] || fail 'PIIE_CODE_ONLY must be 0 or 1'
  local ALLOW_DESTRUCTIVE_MIGRATIONS=$ALLOW_DESTRUCTIVE
  source "$LIB/secure-handoff.sh"
  authorize_activation
  # Resolved identities are passed explicitly. The helper reauthorizes under its
  # exclusive lock, refusing any baseline/receipt change after this precheck.
  log "deploy-activate: RID=$RID ART=$ART SHA=$EXPECTED_RELEASE_SHA DEPLOYED=${EXPECTED_DEPLOYED_SHA:-auto} ROLLBACK=${EXPECTED_ROLLBACK_RELEASE:-auto} DESTRUCTIVE=$ALLOW_DESTRUCTIVE CODE_ONLY=$PIIE_CODE_ONLY"
  require_root
  env -i PATH="$PATH" PIIE_CODE_ONLY=$PIIE_CODE_ONLY \
  EXPECTED_RELEASE_SHA=$EXPECTED_RELEASE_SHA \
  EXPECTED_DEPLOYED_SHA=$EXPECTED_DEPLOYED_SHA \
  EXPECTED_ROLLBACK_RELEASE=$EXPECTED_ROLLBACK_RELEASE \
  ALLOW_DESTRUCTIVE_MIGRATIONS=$ALLOW_DESTRUCTIVE \
  /bin/bash "$LIB/container-deploy.sh" "$RID" "$ART" "$SUM" activate
  log 'deploy-activate: ok'
}

# OPERATION: rollback
op_rollback() {
  [ "$#" -eq 3 ] || fail 'rollback: expected EXPECTED_SHA TARGET_RID TARGET_SHA'
  local EXPECTED_SHA=$1 TARGET_RID=$2 TARGET_SHA=$3
  validate_full_sha "$EXPECTED_SHA"
  [[ "$TARGET_RID" =~ ^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$ ]] || fail "invalid target release id: $TARGET_RID"
  validate_full_sha "$TARGET_SHA"
  log "rollback: EXPECTED=$EXPECTED_SHA TARGET=$TARGET_RID TARGET_SHA=$TARGET_SHA"
  require_root
  run_helper container-rollback.sh "$EXPECTED_SHA" "$TARGET_RID" "$TARGET_SHA"
  log 'rollback: ok'
}

# OPERATION: app-run
op_app_run() {
  [ "$#" -eq 4 ] || fail 'app-run: only the fixed course-content audit is allowed'
  [ "$1" = /srv/piie/current ] && [ "$2" = php ] && [ "$3" = artisan ] &&
    [ "$4" = course-content:audit ] || fail 'app-run: command not in allowlist'
  log 'app-run: course-content audit'
  require_root
  run_helper container-app.sh "$@"
  log 'app-run: ok'
}

# Main dispatcher
main() {
  require_root
  verify_installation
  [ "$#" -ge 1 ] || fail 'usage: piie-deploy-gate <subcommand> [args...]'
  local SUBCMD=$1
  shift
  case "$SUBCMD" in
    preflight)           op_preflight "$@" ;;
    deploy-stage)        op_deploy_stage "$@" ;;
    deploy-activate)     op_deploy_activate "$@" ;;
    rollback)            op_rollback "$@" ;;
    app-run)             op_app_run "$@" ;;
    *)                   fail "unknown subcommand: $SUBCMD" ;;
  esac
}

main "$@"
