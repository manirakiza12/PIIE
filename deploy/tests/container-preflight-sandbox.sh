#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
S=$(mktemp -d)
trap 'rm -rf "$S"' EXIT
mkdir -p "$S/bin" "$S/shared/storage" "$S/releases/old" "$S/scripts"
printf '%040d\n' 0 > "$S/releases/old/RELEASE_SHA"
ln -s releases/old "$S/current"
printf 'private' > "$S/shared/.env"; chmod 600 "$S/shared/.env"
printf 'DocumentRoot /srv/piie/current/public\n' > "$S/apache.conf"
sed "s|BASE=/home/piie/deployments/piie|BASE=$S|" "${PREFLIGHT_UNDER_TEST:-$ROOT/deploy/remote/container-preflight.sh}" > "$S/scripts/container-preflight.sh"
cp "$ROOT/deploy/remote/container-app.sh" "$S/scripts/"
cp "$ROOT/deploy/tests/support/container-docker.sh" "$S/bin/docker"
printf '#!/usr/bin/env bash\necho 0\n' > "$S/bin/id"
chmod +x "$S/bin/"*
export PATH="$S/bin:$PATH" FIXTURE_BASE="$S" FIXTURE_LOG="$S/docker.log"

# Exercise the actual preflight, never a stubbed-out replacement.
bash "$S/scripts/container-preflight.sh" > "$S/pass.log" 2>&1
grep -q 'Verified live Docker runtime' "$S/pass.log"
! grep -q '^exec ' "$S/docker.log"
! grep -q '^kill ' "$S/docker.log"
grep -q -- '--entrypoint test .* -r /srv/piie/shared/.env' "$S/docker.log"
grep -q -- '--entrypoint test .* -w /srv/piie/shared/storage' "$S/docker.log"
grep -q -- '--entrypoint php .*pdo_mysql' "$S/docker.log"
grep -q '^cp -L piie-app:' "$S/docker.log"
grep -q 'run --rm --network piie-stack_private' "$S/docker.log"
! grep -q 'private-fixture-value\|test-password' "$S/pass.log" "$S/docker.log"
while IFS= read -r file; do [ ! -e "$file" ]; done < "$S/env-paths.log"
echo 'PASS: real preflight succeeds with exec unavailable; live config checked; no signal or secret leak'

for failure in FAIL_ENV FAIL_STORAGE FAIL_PHP; do
  if env "$failure=1" bash "$S/scripts/container-preflight.sh" > "$S/failure.log" 2>&1; then
    echo "FAIL: $failure was ignored"; exit 1
  fi
done
printf 'DocumentRoot /wrong/public\n' > "$S/apache.conf"
if bash "$S/scripts/container-preflight.sh" > "$S/failure.log" 2>&1; then echo 'Wrong document root accepted'; exit 1; fi
printf 'DocumentRoot /srv/piie/current/public\n' > "$S/apache.conf"
if FIXTURE_INIT=tini bash "$S/scripts/container-preflight.sh" > "$S/failure.log" 2>&1; then echo 'Non-Apache init accepted'; exit 1; fi
if FIXTURE_INIT=tini bash -c 'source "$1"; app_apache_reload' _ "$S/scripts/container-app.sh" > "$S/failure.log" 2>&1; then echo 'Unsafe signal accepted'; exit 1; fi
! grep -q '^kill ' "$S/docker.log"
while IFS= read -r file; do [ ! -e "$file" ]; done < "$S/env-paths.log"
echo 'PASS: failed app permissions, PHP, document root and parent checks fail closed and clean up'
