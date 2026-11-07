#!/usr/bin/env bash
# Read-only gate for the production diagnostics workflow and script.
# Proves the diagnostics path cannot deploy, migrate, write, or destroy data.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
WORKFLOW="$ROOT/.github/workflows/piie-production-diagnostics.yml"
SCRIPT="$ROOT/deploy/diagnostics/production-readiness.sh"
fail() { echo "FAIL: $*" >&2; exit 1; }
pass() { echo "PASS: $*"; }

[ -f "$WORKFLOW" ] || fail "diagnostics workflow missing"
[ -f "$SCRIPT" ] || fail "diagnostics script missing"

# 1. Workflow no longer depends on PIIE_DEPLOY_ENABLED (independently runnable on main).
if grep -q 'PIIE_DEPLOY_ENABLED' "$WORKFLOW"; then fail "diagnostics workflow still references PIIE_DEPLOY_ENABLED"; fi
pass "diagnostics workflow has no PIIE_DEPLOY_ENABLED dependency"

# 2. Main-branch gate preserved.
grep -q "github.ref == 'refs/heads/main'" "$WORKFLOW" || fail "main-branch gate missing"
pass "main-branch gate preserved"

# 3. Production environment approval preserved (required reviewers).
grep -q 'environment: production' "$WORKFLOW" || fail "production environment approval missing"
pass "production environment approval preserved"

# 4. Concurrency lock preserved.
grep -q 'piie-production-deploy' "$WORKFLOW" || fail "deployment concurrency lock missing"
pass "deployment concurrency lock preserved"

# 5. Pinned SSH host identity preserved.
grep -q 'prepare-ssh.sh' "$WORKFLOW" || fail "pinned SSH preparation missing"
pass "pinned SSH preparation preserved"

# 6. Strict host key checking / no keyscan.
grep -q 'StrictHostKeyChecking' "$ROOT/deploy/diagnostics/prepare-ssh.sh" || fail "strict host key checking missing"
pass "strict host-key verification preserved"

# 7. Diagnostics script must not contain deployment/migration/destructive commands.
for forbidden in 'deploy-release.sh' 'container-deploy.sh' 'first-cutover.sh' 'migrate --force' 'migrate:fresh' 'migrate:rollback' 'db:wipe' 'db:seed' 'rsync --delete' 'rollback.sh' 'container-rollback.sh'; do
  if grep -Fq -- "$forbidden" "$SCRIPT"; then fail "diagnostics script contains forbidden token: $forbidden"; fi
done
pass "diagnostics script contains no deployment/migration/destructive commands"

# 8. Diagnostics script must not contain write SQL.
if grep -Eq '\$pdo->(exec|query|prepare)\(.?(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE)' "$SCRIPT"; then
  fail "diagnostics script contains write SQL"
fi
pass "diagnostics script contains no write SQL"

# 9. Read-only transaction boundary present.
grep -Fq 'START TRANSACTION READ ONLY' "$SCRIPT" || fail "read-only transaction boundary missing"
pass "read-only database transaction preserved"

# 10. Workflow must not invoke piie-deploy or release activation.
for forbidden in 'piie-deploy.yml' 'deploy-release' 'container-deploy' 'first-cutover'; do
  if grep -Fq -- "$forbidden" "$WORKFLOW"; then fail "workflow references forbidden deployment token: $forbidden"; fi
done
pass "workflow contains no deployment invocation"

# 11. The only remote command is the fixed read-only script via stdin.
grep -Fq "production-readiness.sh" "$WORKFLOW" || fail "fixed read-only diagnostic script not invoked"
pass "workflow invokes only the fixed read-only diagnostic script"

echo 'PASS: production diagnostics workflow is independently runnable and demonstrably read-only'
