#!/usr/bin/env bash
# Runner-only validation before user-controlled inputs can reach a remote shell.
set -euo pipefail
[ "$#" -eq 0 ] || { echo 'Deployment validation arguments refused' >&2; exit 2; }
fail() { echo "::error::$1" >&2; exit 2; }
case "${MODE:-}" in stage|activate) ;; *) fail 'Invalid deployment mode';; esac
case "${ALLOW_DESTRUCTIVE:-0}" in 0|1) ;; *) fail 'Invalid migration review flag';; esac
case "${CODE_ONLY:-0}" in 0|1) ;; *) fail 'Invalid code-only flag';; esac
if [ -n "${DEPLOYED_REF:-}" ] && [ "$DEPLOYED_REF" != auto ]; then
  [[ "$DEPLOYED_REF" =~ ^[0-9a-f]{40}$ ]] || fail 'Deployed reference must be a full lowercase commit SHA or auto'
elif [ "$MODE" = activate ] && [ -z "${DEPLOYED_REF:-}" ]; then
  DEPLOYED_REF=auto
fi
if [ -n "${ROLLBACK_RELEASE:-}" ] && [ "$ROLLBACK_RELEASE" != auto ]; then
  [[ "$ROLLBACK_RELEASE" =~ ^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$ ]] || fail 'Invalid rollback release ID'
elif [ "$MODE" = activate ] && [ -z "${ROLLBACK_RELEASE:-}" ]; then
  ROLLBACK_RELEASE=auto
fi
export DEPLOYED_REF ROLLBACK_RELEASE
echo 'Deployment inputs validated'
