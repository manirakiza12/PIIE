#!/usr/bin/env bash
# Real archives/symlinks in a disposable POSIX tree; no network, SQL or Docker.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
S=$(mktemp -d "${TMPDIR:-/tmp}/piie-release-engineering.XXXXXX")
cleanup() { case "$S" in "${TMPDIR:-/tmp}"/piie-release-engineering.*) rm -rf -- "$S";; esac; }
trap cleanup EXIT
reject() { if "$@" > "$S/rejected.log" 2>&1; then echo 'FAIL unsafe operation accepted'; exit 1; fi; }
A=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
B=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
mkdir -p "$S/archive" "$S/bin" "$S/scripts" "$S/host/releases/start/vendor" "$S/host/releases/target/vendor" "$S/host/shared"
printf '%s\n' "$A" > "$S/archive/RELEASE_SHA"
tar -czf "$S/good.tar.gz" -C "$S/archive" RELEASE_SHA
bash "$ROOT/deploy/verify-release-identity.sh" "$S/good.tar.gz" "$A"
reject bash "$ROOT/deploy/verify-release-identity.sh" "$S/good.tar.gz" "$B"
tar -czf "$S/duplicate.tar.gz" -C "$S/archive" RELEASE_SHA RELEASE_SHA
reject bash "$ROOT/deploy/verify-release-identity.sh" "$S/duplicate.tar.gz" "$A"
ln -s RELEASE_SHA "$S/archive/link"
tar -czf "$S/no-marker.tar.gz" -C "$S/archive" link
reject bash "$ROOT/deploy/verify-release-identity.sh" "$S/no-marker.tar.gz" "$A"
printf '%s\n' "$A" > "$S/archive/value"
mv "$S/archive/RELEASE_SHA" "$S/archive/saved-marker"
ln -s value "$S/archive/RELEASE_SHA"
tar -czf "$S/linked-marker.tar.gz" -C "$S/archive" RELEASE_SHA
reject bash "$ROOT/deploy/verify-release-identity.sh" "$S/linked-marker.tar.gz" "$A"
echo 'PASS full-SHA archive identity; mismatched, duplicate, missing and linked markers refused'

sed "s|PATH=/usr/sbin:/usr/bin:/sbin:/bin|PATH=$S/bin:/usr/sbin:/usr/bin:/sbin:/bin|" "$ROOT/deploy/remote/runtime-deploy.sh" > "$S/scripts/runtime-deploy.sh"
printf '#!/usr/bin/env bash\nexit "${FIXTURE_DOCKER_STATUS:-1}"\n' > "$S/bin/docker"
printf '#!/usr/bin/env bash\nprintf container > "$FIXTURE_ROOT/launched"\n' > "$S/scripts/container-deploy.sh"
printf '#!/usr/bin/env bash\nprintf legacy > "$FIXTURE_ROOT/legacy"\n' > "$S/scripts/deploy-release.sh"
chmod +x "$S/bin/docker"
export PATH="$S/bin:$PATH" FIXTURE_ROOT="$S"
reject bash "$S/scripts/runtime-deploy.sh"
[ ! -e "$S/launched" ] && [ ! -e "$S/legacy" ]
FIXTURE_DOCKER_STATUS=0 bash "$S/scripts/runtime-deploy.sh"
[ "$(cat "$S/launched")" = container ] && [ ! -e "$S/legacy" ]
echo 'PASS missing Docker visibility never selects host/legacy execution'

# Protected rollback is exercised with real root ownership/mounts in root-handoff.sh.

WORKFLOW="$ROOT/.github/workflows/piie-deploy.yml"
grep -q 'steps.install.outcome.*success' "$WORKFLOW"
grep -q 'inputs.reconcile_enrolment' "$WORKFLOW"
grep -q 'verify-release-identity.sh' "$WORKFLOW"
grep -q 'container-rollback.sh' "$WORKFLOW"
echo 'PASS post-activation failure handler, explicit enrolment approval and identity verifier are wired'
