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
# Safe diagnostics: report only formatting metadata, never key material.
CR_COUNT=$(tr -cd '\r' < "$D/key" | wc -c)
TRAILING_WS=$(grep -cE '[[:space:]]+$' "$D/key" || true)
LINE_COUNT=$(wc -l < "$D/key")
echo "Deploy key diagnostics: lines=$LINE_COUNT cr=$CR_COUNT trailing_ws_lines=$TRAILING_WS"
FP=$(ssh-keygen -lf "$D/key" 2>/dev/null | awk '{print $2}') || fail 'deploy key parsing failed'
echo "Calculated deploy key fingerprint: $FP"
echo "Expected deploy key fingerprint: SHA256:HnARD+o96mEq/ZObScCf5YgfXvV7RW3qrN/aF3LI4LA"
[ "$FP" = 'SHA256:HnARD+o96mEq/ZObScCf5YgfXvV7RW3qrN/aF3LI4LA' ] || fail 'authorized deploy key identity validation failed'
# Independently verified through the Contabo console by the operator.
# This validates a runner copy; it never updates the protected secret or server.
if grep -Eq '^[[:space:]]*@' "$D/known_hosts"; then fail 'marked host entries require review'; fi
ssh-keygen -lf "$D/known_hosts" >/dev/null 2>&1 || fail 'known-host file parsing failed'
LOOKUP="$PIIE_SSH_HOST"
[ "$PORT" = 22 ] || LOOKUP="[$PIIE_SSH_HOST]:$PORT"
ssh-keygen -F "$LOOKUP" -f "$D/known_hosts" >/dev/null 2>&1 || fail 'known-host entry for configured host and port is missing'
ssh-keygen -F "$LOOKUP" -f "$D/known_hosts" > "$D/matched_hosts"
awk '!/^#/ { if ($1 ~ /^@/) exit 1; if ($2 == "ssh-ed25519") print }' "$D/matched_hosts" > "$D/verified_hosts" || fail 'marked host entries require review'
[ -s "$D/verified_hosts" ] || fail 'verified ED25519 host entry is missing'
HOST_FP=$(ssh-keygen -lf "$D/verified_hosts" 2>/dev/null | awk '{print $2}' | sort -u) || fail 'host fingerprint parsing failed'
[ "$HOST_FP" = 'SHA256:fnVx92azTndsKgv+ZLYmnmWr/4iLDmtnvBU5Y4MFkbw' ] || fail 'independently verified host identity mismatch'
mv "$D/verified_hosts" "$D/known_hosts"
rm -f "$D/matched_hosts"
operation='SSH configuration creation'
cat > "$D/config" <<EOF
Host piie-prod
  HostName $PIIE_SSH_HOST
  Port $PORT
  User $PIIE_SSH_USER
  IdentityFile $D/key
  UserKnownHostsFile $D/known_hosts
  StrictHostKeyChecking yes
  HostKeyAlgorithms ssh-ed25519
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
