#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
# CI-REQUIRES-ROOT: real pin and inode guard; only Docker infrastructure is mocked.
[ "$(id -u)" = 0 ] || exit 1
if [ "${1:-}" != --private-namespace ]; then exec unshare --mount --propagation private -- bash "$0" --private-namespace; fi
source "$ROOT/deploy/tests/support/secure-fixture.sh"
fixture_init
trap fixture_cleanup EXIT
# Exercise the actual preflight, never a stubbed-out replacement.
bash "$SCRIPTS/container-preflight.sh" > "$S/pass.log" 2>&1
grep -q 'Verified live Docker runtime' "$S/pass.log"
! grep -q '^exec ' "$S/docker.log"
! grep -q '^kill ' "$S/docker.log"
grep -q -- '--entrypoint test .* -r /srv/piie/shared/.env' "$S/docker.log"
grep -q -- '--entrypoint test .* -w /srv/piie/shared/storage' "$S/docker.log"
grep -q -- '--entrypoint php .*pdo_mysql' "$S/docker.log"
grep -q '^cp -L piie-app:' "$S/docker.log"
grep -q 'run --rm --network piie-deploy_private' "$S/docker.log"
! grep -q 'private-fixture-value\|test-password' "$S/pass.log" "$S/docker.log"
while IFS= read -r file; do [ ! -e "$file" ]; done < "$S/env-paths.log"
echo 'PASS: real preflight succeeds with exec unavailable; live config checked; no signal or secret leak'

for failure in FAIL_ENV FAIL_STORAGE FAIL_PHP; do
  if env "$failure=1" bash "$SCRIPTS/container-preflight.sh" > "$S/failure.log" 2>&1; then
    echo "FAIL: $failure was ignored"; exit 1
  fi
done
printf 'DocumentRoot /wrong/public\n' > "$S/apache.conf"
if bash "$SCRIPTS/container-preflight.sh" > "$S/failure.log" 2>&1; then echo 'Wrong document root accepted'; exit 1; fi
printf 'DocumentRoot /srv/piie/current/public\n' > "$S/apache.conf"
if FIXTURE_INIT=tini bash "$SCRIPTS/container-preflight.sh" > "$S/failure.log" 2>&1; then echo 'Non-Apache init accepted'; exit 1; fi
if FIXTURE_INIT=tini bash -c 'source "$1"; app_apache_reload' _ "$SCRIPTS/container-app.sh" > "$S/failure.log" 2>&1; then echo 'Unsafe signal accepted'; exit 1; fi
! grep -q '^kill ' "$S/docker.log"
while IFS= read -r file; do [ ! -e "$file" ]; done < "$S/env-paths.log"
echo 'PASS: failed app permissions, PHP, document root and parent checks fail closed and clean up'
