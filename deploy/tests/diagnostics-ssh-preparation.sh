#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
S=$(mktemp -d "${TMPDIR:-/tmp}/piie-ssh-fixture.XXXXXX")
cleanup() { rm -rf -- "$S"; }
trap cleanup EXIT
SOURCE="$ROOT/deploy/diagnostics/prepare-ssh.sh"
bash -n "$SOURCE"
# Generate fixture-only identities; production key material is never accessed.
ssh-keygen -q -t ed25519 -N '' -C fixture-only -f "$S/key"
ssh-keygen -q -t ed25519 -N '' -C fixture-host-only -f "$S/host"
FP=$(ssh-keygen -lf "$S/key" | awk '{print $2}')
# Substitute only the authorized identity in a temporary test copy.
sed -E "s|SHA256:[A-Za-z0-9+/]+|$FP|" "$SOURCE" > "$S/prepare.sh"
export PIIE_SSH_HOST=fixture.invalid PIIE_SSH_PORT=2222 PIIE_SSH_USER=root
export PIIE_SSH_KEY="$(cat "$S/key")"
export PIIE_SSH_KNOWN_HOSTS="[$PIIE_SSH_HOST]:$PIIE_SSH_PORT $(cat "$S/host.pub")"
fresh() { RUNNER_TEMP="$S/run-$RANDOM-$RANDOM"; mkdir "$RUNNER_TEMP"; export RUNNER_TEMP; }
fresh
bash "$S/prepare.sh" > "$S/pass.log" 2>&1
grep -q 'SSH preparation PASS' "$S/pass.log"
ssh -G -F "$RUNNER_TEMP/piie-diagnostics/config" piie-prod > "$S/effective" 2>/dev/null
grep -Eq '^stricthostkeychecking (true|yes)$' "$S/effective"
grep -Eq '^identitiesonly (true|yes)$' "$S/effective"
grep -Eq '^passwordauthentication (false|no)$' "$S/effective"
# The generated config must actually select the protected known-host file.
grep -Fq "UserKnownHostsFile $RUNNER_TEMP/piie-diagnostics/known_hosts" "$RUNNER_TEMP/piie-diagnostics/config"
for file in "$S/pass.log"; do
  ! grep -Eq 'PRIVATE KEY|fixture.invalid|SHA256:' "$file"
done
echo 'PASS: fixture identity and protected host pin configure strict SSH without sensitive output'
reject() {
  local expected=$1; shift
  fresh
  if "$@" > "$S/rejected.log" 2>&1; then echo 'FAIL: invalid SSH fixture accepted'; exit 1; fi
  grep -Fq "SSH preparation: $expected" "$S/rejected.log"
  ! grep -Eq 'PRIVATE KEY|fixture.invalid|SHA256:' "$S/rejected.log"
}
reject 'required variable PIIE_SSH_KEY is missing or empty' env -u PIIE_SSH_KEY bash "$S/prepare.sh"
reject 'host format validation failed' env PIIE_SSH_HOST=$'fixture.invalid\nProxyCommand unsafe' bash "$S/prepare.sh"
reject 'port format validation failed' env PIIE_SSH_PORT=invalid bash "$S/prepare.sh"
reject 'port range validation failed' env PIIE_SSH_PORT=65536 bash "$S/prepare.sh"
reject 'user format validation failed' env PIIE_SSH_USER='invalid user' bash "$S/prepare.sh"
reject 'deploy key parsing failed' env PIIE_SSH_KEY=invalid bash "$S/prepare.sh"
reject 'authorized deploy key identity validation failed' env PIIE_SSH_KEY="$(cat "$S/host")" bash "$S/prepare.sh"
reject 'known-host file parsing failed' env PIIE_SSH_KNOWN_HOSTS=invalid bash "$S/prepare.sh"
reject 'known-host entry for configured host and port is missing' env PIIE_SSH_PORT=2223 bash "$S/prepare.sh"
echo 'PASS: missing secrets, malformed metadata, wrong identities and missing host pins fail with sanitized named errors'
# Hashed host pins must work without disclosing or replacing them.
printf '%s\n' "$PIIE_SSH_KNOWN_HOSTS" > "$S/hashed"
ssh-keygen -H -f "$S/hashed" >/dev/null 2>&1
fresh
PIIE_SSH_KNOWN_HOSTS="$(cat "$S/hashed")" bash "$S/prepare.sh" > "$S/hashed.log" 2>&1
grep -q 'SSH preparation PASS' "$S/hashed.log"
echo 'PASS: hashed known-host fixture is accepted with strict verification retained'
