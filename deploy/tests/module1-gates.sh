#!/usr/bin/env bash
# Behavioural regression checks. No network, application boot or real database.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TEMP_ROOT="$(realpath "${TMPDIR:-/tmp}")"
S="$(mktemp -d "$TEMP_ROOT/piie-module1-gates.XXXXXXXX")"
S="$(realpath "$S")"
case "$S" in "$TEMP_ROOT"/piie-module1-gates.*) ;; *) echo 'Unsafe test directory'; exit 2 ;; esac
cleanup() {
  local resolved
  resolved="$(realpath "$S")"
  case "$resolved" in "$TEMP_ROOT"/piie-module1-gates.*) rm -rf -- "$resolved" ;; *) return 2 ;; esac
}
trap cleanup EXIT
mkdir -p "$S/bin" "$S/deployment/current"
cat > "$S/bin/php" <<'STUB'
#!/usr/bin/env bash
exit "${FIXTURE_PHP_EXIT:-0}"
STUB
chmod +x "$S/bin/php"

# Run the exact CI block with a process that fails without a PHPUnit summary.
awk '
  /- name: Run PHPUnit Feature tests/ { found=1; next }
  found && /run: \|/ { body=1; next }
  body && /^          / { print substr($0,11); next }
  body && /^      - name:/ { exit }
' "$ROOT/.github/workflows/piie-ci.yml" > "$S/phpunit-step.sh"
set +e
(cd "$S" && PATH="$S/bin:$PATH" FIXTURE_PHP_EXIT=3 bash "$S/phpunit-step.sh") > "$S/ci.log" 2>&1
code=$?
set -e
[ "$code" = 3 ] || { echo 'FAIL: failed PHPUnit process accepted'; exit 1; }
echo 'PASS: CI rejects failed PHPUnit process without a summary'

# Baseline checks run before backup, unpacking or database operations.
SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
set +e
PIIE_BASE="$S/deployment" EXPECTED_DEPLOYED_SHA="$SHA" bash "$ROOT/deploy/remote/deploy-release.sh" fixture unused unused activate > "$S/missing.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && grep -q 'current release identity is missing' "$S/missing.log"
echo 'PASS: activation refuses missing deployed identity'
printf '%s\n' bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb > "$S/deployment/current/RELEASE_SHA"
set +e
PIIE_BASE="$S/deployment" EXPECTED_DEPLOYED_SHA="$SHA" bash "$ROOT/deploy/remote/deploy-release.sh" fixture unused unused activate > "$S/changed.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && grep -q 'deployed release changed' "$S/changed.log"
echo 'PASS: activation refuses changed deployed baseline'

# Execute the actual preflight statement with a failing stub, no migrations run.
awk '/^PRETEND=.*artisan migrate --pretend/ { print; exit }' "$ROOT/deploy/remote/deploy-release.sh" > "$S/preflight.sh"
set +e
( . "$ROOT/deploy/remote/lib.sh"; PHP="$S/bin/php"; export FIXTURE_PHP_EXIT=9; . "$S/preflight.sh" ) > "$S/preflight.log" 2>&1
code=$?
set -e
[ "$code" != 0 ] && grep -q 'migration preflight failed' "$S/preflight.log"
echo 'PASS: failed migration preflight aborts release preparation'
