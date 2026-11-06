#!/usr/bin/env bash
# Synthetic archives only. Never connects to production or restores a database.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
S=$(mktemp -d "${TMPDIR:-/tmp}/piie-inventory.XXXXXX")
cleanup() { case "$S" in "${TMPDIR:-/tmp}"/piie-inventory.*) rm -rf -- "$S";; esac; }
trap cleanup EXIT
BASE="$S/host"
B="$BASE/backups/20261106-120000-aaaaaaaaaaaa"
mkdir -p "$B" "$BASE/current" "$BASE/shared/storage" "$S/bin"
printf '%040d\n' 1 > "$BASE/current/RELEASE_SHA"
printf 'SECRET_SENTINEL=never-print-this-value\n' > "$BASE/shared/.env"
printf '%s\n' '-- Dump completed on synthetic fixture' | gzip > "$B/database.sql.gz"
tar -czf "$B/storage.tar.gz" -C "$BASE/shared" storage
tar -czf "$B/public-uploads.tar.gz" --files-from /dev/null
printf 'never-print-this-value\n' > "$B/environment.backup"
(cd "$B" && sha256sum --text database.sql.gz storage.tar.gz public-uploads.tar.gz environment.backup > SHA256SUMS)
cp "$B/SHA256SUMS" "$S/manifest"
sed "s|BASE=/home/piie/deployments/piie|BASE=$BASE|" "$ROOT/deploy/diagnostics/readonly-inventory.sh" > "$S/inventory.sh"
# Avoid depending on a developer's Docker daemon or scheduler.
printf '#!/usr/bin/env bash\nexit 1\n' > "$S/bin/docker"
printf '#!/usr/bin/env bash\necho "* * * * * TOKEN=never-print-this-value command"\n' > "$S/bin/crontab"
chmod +x "$S/bin/docker" "$S/bin/crontab"
export PATH="$S/bin:$PATH"
bash "$S/inventory.sh" > "$S/good.log"
grep -q 'verification_exit=0' "$S/good.log"
grep -q 'environment.backup: OK' "$S/good.log"
! grep -q 'never-print-this-value' "$S/good.log"
echo 'PASS inventory validates four backup members without emitting secret values or cron commands'
reject() {
  if bash "$S/inventory.sh" > "$S/rejected.log"; then echo 'FAIL inventory accepted invalid evidence'; exit 1; fi
  ! grep -q 'never-print-this-value' "$S/rejected.log"
}
printf 'corruption' >> "$B/storage.tar.gz"
reject
echo 'PASS corrupt backup fails closed'
tar -czf "$B/storage.tar.gz" -C "$BASE/shared" storage
sed 's|environment.backup|../../shared/.env|' "$S/manifest" > "$B/SHA256SUMS"
reject
echo 'PASS manifest path traversal is refused'
cp "$S/manifest" "$B/SHA256SUMS"
touch -d '2 days ago' "$B/SHA256SUMS"
reject
echo 'PASS stale backup fails closed'
touch "$B/SHA256SUMS"
mv "$BASE/current" "$BASE/saved-current"
reject
echo 'PASS missing release pointer fails closed'
