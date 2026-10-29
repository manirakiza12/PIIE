#!/usr/bin/env bash
# Runner-only setup. Never print connection metadata, keys or fingerprints.
set +x
set -euo pipefail
fail() { echo "::error::SSH preparation: $1" >&2; exit 1; }
operation='initialization'
trap 'fail "$operation failed"' ERR
umask 077
[ "$#" = 0 ] || fail 'arguments refused'
for name in RUNNER_TEMP PIIE_SSH_HOST PIIE_SSH_PORT PIIE_SSH_USER PIIE_SSH_KEY PIIE_SSH_KNOWN_HOSTS; do
  [ -n "${!name:-}" ] || fail "required variable $name is missing or empty"
done
[[ "$PIIE_SSH_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || fail 'host format validation failed'
[[ "$PIIE_SSH_PORT" =~ ^[0-9]{1,5}$ ]] || fail 'port format validation failed'
PORT=$((10#$PIIE_SSH_PORT))
[ "$PORT" -ge 1 ] && [ "$PORT" -le 65535 ] || fail 'port range validation failed'
[[ "$PIIE_SSH_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || fail 'user format validation failed'
operation='private client directory creation'
D="$RUNNER_TEMP/piie-diagnostics"
mkdir "$D"
operation='private key and known-host file creation'
printf '%s\n' "$PIIE_SSH_KEY" > "$D/key"
printf '%s\n' "$PIIE_SSH_KNOWN_HOSTS" > "$D/known_hosts"
FP=$(ssh-keygen -lf "$D/key" 2>/dev/null | awk '{print $2}') || fail 'deploy key parsing failed'
[ "$FP" = 'SHA256:xE5JjsJX2o4xwKnBZuiZQoOdTv+dFonLKwFq8bhCrTM' ] || fail 'authorized deploy key identity validation failed'
# The protected known_hosts secret is the production host pin, as in piie-deploy.
# Do not additionally compare it to the legacy host fingerprint in old comments.
ssh-keygen -lf "$D/known_hosts" >/dev/null 2>&1 || fail 'known-host file parsing failed'
LOOKUP="$PIIE_SSH_HOST"
[ "$PORT" = 22 ] || LOOKUP="[$PIIE_SSH_HOST]:$PORT"
ssh-keygen -F "$LOOKUP" -f "$D/known_hosts" >/dev/null 2>&1 || fail 'known-host entry for configured host and port is missing'
operation='SSH configuration creation'
cat > "$D/config" <<EOF
Host piie-prod
  HostName $PIIE_SSH_HOST
  Port $PORT
  User $PIIE_SSH_USER
  IdentityFile $D/key
  UserKnownHostsFile $D/known_hosts
  StrictHostKeyChecking yes
  IdentitiesOnly yes
  BatchMode yes
  PasswordAuthentication no
  ClearAllForwardings yes
  PermitLocalCommand no
  ControlMaster no
  ConnectTimeout 15
EOF
ssh -G -F "$D/config" piie-prod >/dev/null 2>&1 || fail 'SSH configuration parsing failed'
echo 'SSH preparation PASS: authorized deploy identity and matching protected host pin; strict verification enabled'
