#!/usr/bin/env bash
# CI-REQUIRES-ROOT: real mount, inode, ownership, permission and locking controls.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
[ "$(id -u)" = 0 ] || exit 1
if [ "${1:-}" != --private-namespace ]; then exec unshare --mount --propagation private -- bash "$0" --private-namespace; fi
source "$ROOT/deploy/tests/support/secure-fixture.sh"
fixture_init
trap fixture_cleanup EXIT
reject() { if "$@" > "$S/rejected.log" 2>&1; then echo "FAIL accepted: $*" >&2; exit 1; fi; }
BEFORE=$(stat -c '%u:%g:%a' "$S/shared/.env")
ENV_HASH=$(sha256sum "$S/shared/.env")
PIN_PATH=$TESTROOT/home/piie/deployments
RID=20261107-162151-f23cff8aaaaa
ART=$S/incoming/piie-release-fixture.tar.gz
SOURCE_ART=$ART
[ "$(readlink "$S/current")" = releases/f23cff8 ]
[ "$(head -n1 "$S/.secure/private/f23cff8.approved")" = "$SHA" ]
echo 'PASS: independently approved baseline archive compared and preserved without switching live code'
# Baseline marker alone cannot approve modified live code.
printf altered > "$S/releases/f23cff8/vendor/autoload.php"
reject python3 "$SCRIPTS/release-archive.py" compare "$S/releases/f23cff8" "$S/.secure/releases/f23cff8"
printf 'autoload\n' > "$S/releases/f23cff8/vendor/autoload.php"
echo 'PASS: altered baseline code is rejected despite matching RELEASE_SHA'
umount "$PIN_PATH"
reject bash "$SCRIPTS/pin-deployments.sh" check
reject bash "$SCRIPTS/container-deploy.sh" "$RID" "$ART" "$SUM" stage
reject bash "$SCRIPTS/run-scheduler.sh"
reject bash "$SCRIPTS/container-rollback.sh" "$SHA" f23cff8 "$SHA"
[ ! -d "$S/.secure/releases/$RID" ]
[ "$(readlink "$S/current")" = releases/f23cff8 ]
echo 'PASS: absent mount blocks deployment, scheduler and rollback before filesystem changes'
# Root-owned decoy can pass ownership checks; recorded inode identity must reject it.
mkdir -p "$TESTROOT/home/piie/decoy/piie"
runuser -u nobody -- mv "$PIN_PATH" "$PIN_PATH.saved"
runuser -u nobody -- mv "$TESTROOT/home/piie/decoy" "$PIN_PATH"
reject bash "$SCRIPTS/pin-deployments.sh" start
! mountpoint -q "$PIN_PATH"
runuser -u nobody -- mv "$PIN_PATH" "$TESTROOT/home/piie/decoy"
runuser -u nobody -- mv "$PIN_PATH.saved" "$PIN_PATH"
bash "$SCRIPTS/pin-deployments.sh" start
bash "$SCRIPTS/pin-deployments.sh" check
reject runuser -u nobody -- mv "$PIN_PATH" "$PIN_PATH.attack"
chmod 644 "$TESTROOT/etc/piie/deployments.identity"
reject bash "$SCRIPTS/pin-deployments.sh" check
chmod 600 "$TESTROOT/etc/piie/deployments.identity"
chmod 775 "$S/.secure/releases"
reject bash "$SCRIPTS/container-deploy.sh" "$RID" "$ART" "$SUM" stage
chmod 755 "$S/.secure/releases"
echo 'PASS: simulated reboot restores pin; replacement/rename and unsafe trust/code-parent permissions rejected'
# Use actual unprivileged staging script with unchanged incoming/releases ownership.
runuser -u nobody -- env EXPECTED_RELEASE_SHA="$SHA" bash "$SCRIPTS/stage-release.sh" "$RID" "$ART" "$SUM" > "$S/ci-stage.log"
runuser -u nobody -- sh -c 'printf malicious > "$1/vendor/autoload.php"' sh "$S/releases/$RID"
[ "$(stat -c %U "$S/releases")" = nobody ]
echo 'PASS: unprivileged CI staging continues and remains explicitly untrusted'
fail() { echo "ERROR: $*" >&2; exit 1; }
source "$SCRIPTS/secure-handoff.sh"
# Prove source replacement cannot mutate the already verified private copy.
secure_handoff
runuser -u nobody -- sh -c 'rm "$1"; printf replaced > "$1"' sh "$SOURCE_ART"
printf '%s  %s\n' "$SUM" "$ART" | sha256sum -c -
reject runuser -u nobody -- cat "$ART"
reject bash "$SCRIPTS/container-deploy.sh" "$RID" "$SOURCE_ART" "$SUM" stage
# Restore a valid archive; the approved checksum must still match.
tar -czf "$SOURCE_ART" -C "$S/fixture" .
SUM=$(sha256sum "$SOURCE_ART" | cut -d' ' -f1)
prepare_release "$RID" "$SOURCE_ART" "$SUM" "$SHA"
[ "$(cat "$REL/vendor/autoload.php")" = autoload ]
[ "$(stat -c %a "$REL/vendor/bin/tool")" = 755 ]
reject runuser -u nobody -- mv "$REL" "$REL.attack"
reject runuser -u nobody -- sh -c 'echo bad > "$1/vendor/autoload.php"' sh "$REL"
echo 'PASS: archive replacement rejected; verified copy rebuilt independently of staged code; executables preserved'
VIEW=$TESTROOT/container/srv/piie
runuser -u www-data -- test -r "$VIEW/.secure/releases/$RID/.env"
runuser -u www-data -- test -r "$VIEW/.secure/releases/$RID/vendor/autoload.php"
runuser -u www-data -- touch "$VIEW/.secure/releases/$RID/bootstrap/cache/runtime"
reject runuser -u www-data -- sh -c 'echo bad > "$1/vendor/autoload.php"' sh "$VIEW/.secure/releases/$RID"
mkdir -p "$S/shared/public-uploads/nested"
printf durable > "$S/shared/public-uploads/nested/file"
chmod 700 "$S/shared/public-uploads" "$S/shared/public-uploads/nested"
set_upload_permissions "$S/shared/public-uploads"
runuser -u www-data -- sh -c 'echo runtime >> "$1/nested/file"' sh "$VIEW/shared/public-uploads"
[ "$(stat -c '%u:%g:%a' "$S/shared/.env")" = "$BEFORE" ]
[ "$(sha256sum "$S/shared/.env")" = "$ENV_HASH" ]
echo 'PASS: actual www-data code/env reads, cache/upload writes and code-write denial through unchanged bind view'
ln "$S/shared/.env" "$S/shared/public-uploads/env-link"
reject python3 "$SCRIPTS/repair-uploads.py" "$S/shared/public-uploads"
rm "$S/shared/public-uploads/env-link"
ln -s ../.env "$S/shared/public-uploads/env-link"
reject python3 "$SCRIPTS/repair-uploads.py" "$S/shared/public-uploads"
rm "$S/shared/public-uploads/env-link"
reject runuser -u nobody -- python3 "$SCRIPTS/repair-uploads.py" "$S/shared/public-uploads"
[ "$(stat -c '%u:%g:%a' "$S/shared/.env")" = "$BEFORE" ]
[ "$(sha256sum "$S/shared/.env")" = "$ENV_HASH" ]
# A pre-existing destination symlink must never let a merge overwrite .env.
mkdir "$TESTROOT/merge-source"
printf attack > "$TESTROOT/merge-source/env-link"
ln -s ../.env "$S/shared/public-uploads/env-link"
reject python3 "$SCRIPTS/merge-uploads.py" "$TESTROOT/merge-source" "$S/shared/public-uploads"
rm "$S/shared/public-uploads/env-link"
rm "$TESTROOT/merge-source/env-link"
ln -s "$S/shared/.env" "$TESTROOT/merge-source/env-link"
reject python3 "$SCRIPTS/merge-uploads.py" "$TESTROOT/merge-source" "$S/shared/public-uploads"
[ "$(sha256sum "$S/shared/.env")" = "$ENV_HASH" ]
echo 'PASS: hardlinks, symlinks and real permission errors fail closed without changing .env'
deployment_lock
reject flock -n "$S/.secure/deploy.lock" true
reject runuser -u nobody -- rm "$S/.secure/deploy.lock"
reject runuser -u nobody -- rm "$S/current"
link_uploads "$REL"
link_uploads "$S/.secure/releases/f23cff8"
switch_pointer ".secure/releases/$RID"
[ "$(readlink "$S/current")" = ".secure/releases/$RID" ]
[ "$(cat "$VIEW/current/vendor/autoload.php")" = autoload ]
# Scheduler skips safely while deployment has the exclusive lock.
bash "$SCRIPTS/run-scheduler.sh"
flock -u 9; exec 9>&-
bash "$SCRIPTS/run-scheduler.sh"
grep -q 'artisan schedule:run' "$S/docker.log"
echo 'PASS: exclusive deployment lock, atomic protected pointer and scheduler exclusion/access'
reject bash "$SCRIPTS/container-rollback.sh" "$SHA" ../mailcow "$SHA"
reject bash "$SCRIPTS/container-rollback.sh" "$SHA" f23cff8 bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
reject env FAIL_HEALTH=1 bash "$SCRIPTS/container-rollback.sh" "$SHA" f23cff8 "$SHA"
[ "$(readlink "$S/current")" = ".secure/releases/$RID" ]
reject env FAIL_RELOAD_ONCE=1 bash "$SCRIPTS/container-rollback.sh" "$SHA" f23cff8 "$SHA"
[ "$(readlink "$S/current")" = ".secure/releases/$RID" ]
bash "$SCRIPTS/container-rollback.sh" "$SHA" f23cff8 "$SHA"
[ "$(readlink "$S/current")" = .secure/releases/f23cff8 ]
[ -f "$S/releases/f23cff8/vendor/autoload.php" ]
[ -f "$S/shared/public-uploads/nested/file" ]
echo 'PASS: rollback identity/path checks, health/reload recovery and trusted baseline restoration preserve legacy release/data'
# The per-container restart policy must not bypass the mount-gated unit.
bash "$SCRIPTS/runtime-supervisor.sh" check
reject env FIXTURE_RESTART_POLICY=unless-stopped bash "$SCRIPTS/runtime-supervisor.sh" check
umount "$PIN_PATH"
: > "$S/docker.log"
reject env FIXTURE_RUNNING=false bash "$SCRIPTS/runtime-supervisor.sh" run
! grep -q '^start ' "$S/docker.log"
bash "$SCRIPTS/pin-deployments.sh" start
reject env FIXTURE_RUNNING=false bash "$SCRIPTS/runtime-supervisor.sh" run
grep -qx 'start piie-app' "$S/docker.log"
grep -qx 'wait piie-app' "$S/docker.log"
! grep -qE '^(start|stop|restart) (piie-db|docker|mailcow)' "$S/docker.log"
echo 'PASS: guarded restart refuses missing pin/automatic Docker policy; starts/waits only the existing PIIE app'
# Reject a container/host split even when Docker textual metadata would match.
umount "$TESTROOT/container/srv/piie"
reject bash "$SCRIPTS/container-app.sh" /srv/piie/current php artisan --version
mount --bind "$S" "$TESTROOT/container/srv/piie"
echo 'PASS: actual container-mount inode mismatch fails closed'
[ "$(stat -c '%u:%g:%a' "$S/shared/.env")" = "$BEFORE" ]
[ "$(sha256sum "$S/shared/.env")" = "$ENV_HASH" ]
! grep -Eq '^kill .* (piie-db|mailcow)|compose|prune|network (connect|disconnect)' "$S/docker.log"
echo 'PASS: only PIIE app clients/signals used; shared environment and unrelated service paths untouched'
