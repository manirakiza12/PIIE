#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
S=$(mktemp -d "${TMPDIR:-/tmp}/piie-workflow-gates.XXXXXX")
cleanup() { case "$S" in "${TMPDIR:-/tmp}"/piie-workflow-gates.*) rm -rf -- "$S";; esac; }
trap cleanup EXIT
check() {
  local expected=$1 actual=0; shift
  "$@" > "$S/result" 2>&1 || actual=$?
  if [ "$actual" != "$expected" ]; then cat "$S/result"; echo "FAIL expected $expected got $actual"; exit 1; fi
}
VALIDATOR="$ROOT/deploy/validate-deploy-inputs.sh"
check 0 env MODE=stage DEPLOYED_REF= ALLOW_DESTRUCTIVE=0 CODE_ONLY=0 bash "$VALIDATOR"
check 0 env MODE=activate DEPLOYED_REF=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ROLLBACK_RELEASE=f23cff8 bash "$VALIDATOR"
check 0 env MODE=activate DEPLOYED_REF=auto ROLLBACK_RELEASE=auto bash "$VALIDATOR"
check 0 env MODE=activate DEPLOYED_REF= ROLLBACK_RELEASE= bash "$VALIDATOR"
check 2 env MODE=stage DEPLOYED_REF='; touch should-never-exist' bash "$VALIDATOR"
check 2 env MODE=stage DEPLOYED_REF='$(id)' bash "$VALIDATOR"
check 2 env MODE=invalid bash "$VALIDATOR"
check 2 env MODE=stage ALLOW_DESTRUCTIVE='1; id' bash "$VALIDATOR"
check 2 env MODE=stage CODE_ONLY=invalid bash "$VALIDATOR"
check 2 env MODE=activate DEPLOYED_REF=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ROLLBACK_RELEASE=invalid/ref bash "$VALIDATOR"
check 2 env MODE=stage ROLLBACK_RELEASE='../mailcow' bash "$VALIDATOR"
check 2 env MODE=activate DEPLOYED_REF='auto; id' ROLLBACK_RELEASE=auto bash "$VALIDATOR"
check 2 env MODE=activate DEPLOYED_REF=auto ROLLBACK_RELEASE='auto; id' bash "$VALIDATOR"
check 2 env MODE=activate DEPLOYED_REF=auto ROLLBACK_RELEASE=auto CODE_ONLY='$(id)' bash "$VALIDATOR"
echo 'PASS valid stage/activation accepted, auto baseline accepted, and invalid remote-shell inputs rejected'
# Stub only the scanner process, retaining the real override decision logic.
cp "$ROOT/deploy/gate-migrations.sh" "$S/gate-migrations.sh"
printf '#!/usr/bin/env bash\nexit "${FIXTURE_SCAN_STATUS:?}"\n' > "$S/check-migrations.sh"
check 0 env FIXTURE_SCAN_STATUS=0 ALLOW_DESTRUCTIVE_MIGRATIONS=0 bash "$S/gate-migrations.sh" base
check 1 env FIXTURE_SCAN_STATUS=1 ALLOW_DESTRUCTIVE_MIGRATIONS=0 bash "$S/gate-migrations.sh" base
check 0 env FIXTURE_SCAN_STATUS=1 ALLOW_DESTRUCTIVE_MIGRATIONS=1 bash "$S/gate-migrations.sh" base
check 2 env FIXTURE_SCAN_STATUS=2 ALLOW_DESTRUCTIVE_MIGRATIONS=1 bash "$S/gate-migrations.sh" base
check 2 env FIXTURE_SCAN_STATUS=127 ALLOW_DESTRUCTIVE_MIGRATIONS=1 bash "$S/gate-migrations.sh" base
check 2 env FIXTURE_SCAN_STATUS=0 ALLOW_DESTRUCTIVE_MIGRATIONS=invalid bash "$S/gate-migrations.sh" base
echo 'PASS scanner errors and unexpected exits fail closed even with destructive review override'
WORKFLOW="$ROOT/.github/workflows/piie-deploy.yml"
[ "$(grep -c 'github.ref.*refs/heads/main.*PIIE_DEPLOY_ENABLED' "$WORKFLOW")" -eq 2 ]
[ "$(grep -c 'bash deploy/validate-deploy-inputs.sh' "$WORKFLOW")" -eq 3 ]
grep -q 'bash deploy/gate-migrations.sh' "$WORKFLOW"
echo 'PASS gate and protected job require main; validated inputs and fail-closed migration gate are wired'
