#!/usr/bin/env bash
# Exercise the real scanner against disposable Git histories, never a database.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCANNER="$ROOT/deploy/check-migrations.sh"
TEMP_ROOT="$(cd "${TMPDIR:-/tmp}" && pwd)"
S="$(mktemp -d "$TEMP_ROOT/piie-migration-scan.XXXXXX")"
cleanup() {
  case "$S" in "$TEMP_ROOT"/piie-migration-scan.*) rm -rf -- "$S" ;; esac
}
trap cleanup EXIT
REAL_GIT="$(command -v git)"
export REAL_GIT
git init -q "$S/repo"
cd "$S/repo"
git config user.name 'Migration scanner fixture'
git config user.email 'migration-scanner@example.test'
git config core.autocrlf false
git -c core.hooksPath=/dev/null commit -q --allow-empty -m baseline
git branch fixture-base
mkdir -p database/migrations
passed=0
failed=0
check() {
  local label="$1" expected="$2" pattern="$3" code
  shift 3
  if "$@" > "$S/output" 2>&1; then code=0; else code=$?; fi
  if [ "$code" -eq "$expected" ] && grep -qF "$pattern" "$S/output"; then
    echo "PASS  $label"
    passed=$((passed + 1))
  else
    echo "FAIL  $label (exit $code, expected $expected)"
    cat "$S/output"
    failed=$((failed + 1))
  fi
  if [ "$expected" -ne 0 ] && grep -qF 'No new migrations' "$S/output"; then
    echo "FAIL  failed comparison claimed no new migrations"
    failed=$((failed + 1))
  fi
}
check 'valid base, no migrations' 0 'No new migrations' bash "$SCANNER" fixture-base
cat > database/migrations/safe.php <<'PHP'
<?php
class SafeFixture {
    public function up() {
        Schema::create('fixture', function ($table) { $table->id(); });
    }
    public function down() {
        Schema::dropIfExists('fixture');
    }
}
PHP
git add -- database/migrations/safe.php
git -c core.hooksPath=/dev/null commit -qm safe
check 'safe up; destructive down is excluded' 0 'ok          database/migrations/safe.php' bash "$SCANNER" fixture-base
cat > database/migrations/destructive.php <<'PHP'
<?php
class DestructiveFixture {
    public function up() {
        Schema::dropIfExists('existing');
    }
    public function down() {
    }
}
PHP
git add -- database/migrations/destructive.php
git -c core.hooksPath=/dev/null commit -qm destructive
check 'destructive migration is rejected' 1 'DESTRUCTIVE? database/migrations/destructive.php' bash "$SCANNER" fixture-base
check 'missing base reference fails closed' 2 'base ref missing-fixture not found' bash "$SCANNER" missing-fixture
# Use an unrelated commit as a base without checking out or deleting anything.
tree=$(git rev-parse 'fixture-base^{tree}')
orphan=$(printf 'unrelated fixture\n' | git -c user.name=Fixture -c user.email=fixture@example.test commit-tree "$tree")
git update-ref refs/heads/unrelated-fixture "$orphan"
check 'existing references without merge base fail closed' 2 'no valid merge base' bash "$SCANNER" unrelated-fixture
# Keep rev-parse and merge-base real; force only the comparison to fail.
mkdir "$S/bin"
cat > "$S/bin/git" <<'SH'
#!/usr/bin/env bash
if [ "${1:-}" = diff ]; then
  echo 'fixture git comparison failure' >&2
  exit 42
fi
exec "$REAL_GIT" "$@"
SH
chmod +x "$S/bin/git"
check 'git diff failure fails closed' 2 'migration comparison failed' env PATH="$S/bin:$PATH" bash "$SCANNER" fixture-base
echo "Migration scanner: $passed passed, $failed failed."
[ "$failed" -eq 0 ]
